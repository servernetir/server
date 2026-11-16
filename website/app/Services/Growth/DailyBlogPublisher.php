<?php

namespace App\Services\Growth;

use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\Setting;
use App\Models\User;
use App\Services\BlogRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DailyBlogPublisher
{
    public function __construct()
    {
        // A scoped config file also works on installations with a pre-existing config cache.
        config(['daily_blog' => require config_path('daily_blog.php')]);
    }

    public function status(): array
    {
        $key = 'daily-blog:'.now('Asia/Tehran')->toDateString();
        $run = DB::table('growth_automation_runs')->where('run_key', $key)->first();

        return ['enabled' => Setting::get('daily_blog_enabled') === '1', 'timezone' => 'Asia/Tehran',
            'today' => $run ? json_decode($run->summary, true) : null];
    }

    public function enabled(): bool
    {
        return Setting::get('daily_blog_enabled') === '1'
            && (bool) DB::table('growth_controls')->where('id', 1)->value('enabled')
            && User::whereKey((int) Setting::get('daily_blog_actor_id', '0'))->where('role', 'admin')->exists();
    }

    public function tick(): array
    {
        if (! $this->enabled() || now('Asia/Tehran')->hour < 8) {
            return ['stage' => 'paused_or_before_window'];
        }
        // Same lock as growth:run: neither worker can race a generation/publication.
        $lock = Cache::lock('growth-center-run', 900);
        if (! $lock->get()) {
            return ['stage' => 'worker_busy'];
        }
        $key = 'daily-blog:'.now('Asia/Tehran')->toDateString();
        try {
            $run = DB::table('growth_automation_runs')->where('run_key', $key)->first();
            $state = $run ? json_decode($run->summary, true, 64, JSON_THROW_ON_ERROR) : [];
            if (in_array($state['stage'] ?? '', ['published', 'blocked'], true)) {
                return $state;
            }
            if (! $run || ($state['stage'] ?? '') === 'waiting_source') {
                if (! empty($state['next_attempt_at']) && now()->lt($state['next_attempt_at'])) {
                    return $state;
                }
                $state['attempts'] = ($state['attempts'] ?? 0) + 1;
                try {
                    $source = app(DailyBlogSources::class)->discover();
                } catch (\Throwable) {
                    $state += ['policy' => 'native-trilingual-v1'];
                    $state['stage'] = $state['attempts'] < 6 ? 'waiting_source' : 'blocked';
                    $state['code'] = 'no_fresh_official_source';
                    $state['next_attempt_at'] = now()->addHour()->toIso8601String();
                    $this->save($key, $state);

                    return $state;
                }
                $actor = (int) Setting::get('daily_blog_actor_id');
                $state = DB::transaction(function () use ($key, $source, $actor) {
                    $state = ['stage' => 'planning', 'policy' => 'native-trilingual-v1', 'source' => $source];
                    // Source snapshot + job + run are committed together, never an orphan paid call.
                    $item = app(ContentWorkflow::class)->create(['title' => 'Daily insight '.now('Asia/Tehran')->toDateString().': '.mb_substr($source['title'], 0, 140),
                        'locale' => 'fa', 'channel' => 'blog', 'format' => 'article', 'objective' => 'education',
                        'target_url' => $source['url'], 'brief' => 'Owner-authorized automatic trilingual publication. Public source: '.$source['url']], $actor);
                    $state['content_id'] = $item->id;
                    try {
                        $recent = PostTranslation::where('locale', 'en')->latest('id')->limit(60)->pluck('title')->all();
                        $best = null;
                        foreach (array_slice(config('ai_growth.autopilot_text_models', []), 0, 8) as $model) {
                            try {
                                $payload = app(DailyBlogArticle::class)->payload($model, $source, $recent);
                                $quote = app(GrowthGateway::class)->estimate($payload, 'text');
                                if ($quote['max_cost_irt'] > 0 && (! $best || $quote['max_cost_irt'] < $best['quote']['max_cost_irt'])) {
                                    $best = compact('payload', 'quote');
                                }
                            } catch (\Throwable) {
                            }
                        }
                        if (! $best) {
                            throw new \RuntimeException('no_supported_model_or_key');
                        }
                        $job = app(GrowthJobs::class)->enqueue($item->id, 'text', $best['payload'], $best['quote']['max_cost_irt'], $item->version,
                            hash('sha256', 'daily-blog:trilingual-v1:'.$item->id), $actor, $best['quote']['gateway_token_id']);
                        $state += ['job_id' => $job->id, 'max_cost_irt' => $job->max_cost_irt];
                        $state['stage'] = 'queued';
                    } catch (\Throwable $e) {
                        $state['stage'] = 'blocked';
                        $state['code'] = $e instanceof \RuntimeException ? $e->getMessage() : 'budget_or_generation_unavailable';
                    }
                    $this->save($key, $state);
                    GrowthAudit::record('daily_blog.planned', $key, $actor, ['content_id' => $item->id, 'stage' => $state['stage']]);

                    return $state;
                });
            }
            if (($state['stage'] ?? '') !== 'queued') {
                return $state;
            }
            $job = GrowthJob::findOrFail($state['job_id']);
            if ($job->status === 'queued') {
                app(GrowthJobs::class)->run($job);
                $job->refresh();
            }
            if ($job->status === 'processing' && $job->started_at?->lt(now()->subMinutes(20))) {
                $state['stage'] = 'blocked';
                $state['code'] = 'uncertain_generation_requires_receipt_review';
            } elseif (in_array($job->status, ['queued', 'processing'], true)) {
                return $state;
            } elseif ($job->status !== 'completed') {
                $state['stage'] = 'blocked';
                $state['code'] = 'failed_generation_requires_receipt_review';
            } else {
                try {
                    $item = GrowthContent::findOrFail($state['content_id']);
                    $translations = app(DailyBlogArticle::class)->parse((string) $item->body, $state['source']);
                    $state = $this->publish($key, $state, $job, $translations);
                } catch (\Throwable $e) {
                    // A post-commit cache error must not overwrite the durable publication receipt.
                    $saved = json_decode(DB::table('growth_automation_runs')->where('run_key', $key)->value('summary'), true);
                    if (($saved['stage'] ?? '') === 'published') {
                        $state = $saved;
                    } else {
                        $state['stage'] = 'blocked';
                        $state['code'] = $e instanceof \RuntimeException && preg_match('/^[a-z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'article_validation_failed';
                    }
                }
            }
            $this->save($key, $state);
            if ($state['stage'] === 'blocked') {
                GrowthAudit::record('daily_blog.blocked', $key, null, ['code' => $state['code']]);
                app(GrowthNotifications::class)->send('انتشار سه‌زبانه متوقف شد؛ رسید را بررسی کنید', $state['content_id']);
            }

            return $state;
        } finally {
            $lock->release();
        }
    }

    private function publish(string $key, array $state, GrowthJob $job, array $translations): array
    {
        return DB::transaction(function () use ($key, $state, $job, $translations) {
            $control = DB::table('growth_controls')->where('id', 1)->lockForUpdate()->first();
            if (! $control?->enabled || ! $this->enabled()) {
                throw new \RuntimeException('publication_paused');
            }
            $run = DB::table('growth_automation_runs')->where('run_key', $key)->lockForUpdate()->first();
            $saved = json_decode($run->summary, true);
            if (($saved['stage'] ?? '') === 'published') {
                return $saved;
            }
            $item = GrowthContent::lockForUpdate()->findOrFail($state['content_id']);
            if ($item->status !== 'draft' || $item->version !== $job->content_version + 1) {
                throw new \RuntimeException('operator_changed_content');
            }
            $slug = 'daily-insight-'.now('Asia/Tehran')->toDateString();
            if (Post::where('slug', $slug)->exists()) {
                throw new \RuntimeException('publication_slug_already_exists');
            }
            // Atomic: no public post can have only one or two languages.
            $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'news', 'status' => 'draft',
                'source' => $state['source']['url'], 'author_id' => null]);
            foreach ($translations as $locale => $tr) {
                PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'auto' => true, 'tags' => []] + $tr);
            }
            $post->update(['status' => 'published', 'published_at' => now()]);
            $item->update(['status' => 'published', 'published_at' => now(), 'post_id' => $post->id,
                'publication_url' => 'https://servernet.cloud/blog/'.$slug]);
            // Deliberately do not set approved_by or claim a human review.
            $state['stage'] = 'published';
            $state['post_id'] = $post->id;
            $state['published_at'] = now()->toIso8601String();
            $state['urls'] = array_map(fn ($l) => 'https://servernet.cloud'.($l === 'fa' ? '' : '/'.$l).'/blog/'.$slug, ['fa', 'en', 'tr']);
            unset($state['source']['text']);
            $this->save($key, $state);
            GrowthAudit::record('daily_blog.published', $key, null, ['post_id' => $post->id, 'policy' => 'native-trilingual-v1']);
            DB::afterCommit(fn () => BlogRepository::flush());

            return $state;
        });
    }

    private function save(string $key, array $state): void
    {
        DB::table('growth_automation_runs')->updateOrInsert(['run_key' => $key],
            ['summary' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
