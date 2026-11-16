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
use Illuminate\Support\Str;

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
                $state = $this->plan($key, $source, (int) Setting::get('daily_blog_actor_id'));
            }
            if (($state['stage'] ?? '') !== 'queued') {
                return $state;
            }
            $jobs = [];
            foreach (($state['locale_jobs'] ?? ['fa' => $state['job_id']]) as $locale => $id) {
                $job = GrowthJob::findOrFail($id);
                if ($job->status === 'queued') {
                    app(GrowthJobs::class)->run($job);
                    $job->refresh();
                }
                if (in_array($job->status, ['queued', 'processing'], true)) {
                    if ($job->status === 'processing' && $job->started_at?->lt(now()->subMinutes(20))) {
                        $state['stage'] = 'blocked';
                        $state['code'] = 'uncertain_generation_requires_receipt_review';
                        break;
                    }
                    return $state;
                }
                if ($job->status !== 'completed') {
                    $state['stage'] = 'blocked';
                    $state['code'] = 'failed_generation_requires_receipt_review';
                    break;
                }
                $jobs[$locale] = $job;
            }
            if ($state['stage'] === 'blocked') {
                GrowthJob::whereIn('id', array_values($state['locale_jobs'] ?? []))->where('status', 'queued')
                    ->update(['status' => 'cancelled', 'actual_cost_irt' => 0, 'error_code' => 'bundle_stopped', 'finished_at' => now()]);
            } else {
                try {
                    if (isset($state['locale_jobs'])) {
                        $bundle = [];
                        foreach ($jobs as $locale => $job) {
                            $decoded = app(DailyBlogArticle::class)->decode((string) GrowthContent::findOrFail($job->content_id)->body);
                            $bundle[$locale] = $decoded['translations'][$locale] ?? null;
                        }
                        $raw = json_encode(['translations' => $bundle], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    } else {
                        $raw = (string) GrowthContent::findOrFail($state['content_id'])->body;
                    }
                    $translations = app(DailyBlogArticle::class)->parse($raw, $state['source']);
                    $state = $this->publish($key, $state, $jobs['fa'], $translations);
                } catch (\Throwable $e) {
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

    /** Explicit operator action only; normal cron never retries an uncertain/blocked generation. */
    public function retryReviewed(int $actor): array
    {
        if (! $this->enabled() || $actor !== (int) Setting::get('daily_blog_actor_id')) {
            throw new \RuntimeException('delegation_missing');
        }
        $lock = Cache::lock('growth-center-run', 900);
        if (! $lock->get()) {
            throw new \RuntimeException('worker_busy');
        }
        try {
            return DB::transaction(function () use ($actor) {
                $key = 'daily-blog:'.now('Asia/Tehran')->toDateString();
                $run = DB::table('growth_automation_runs')->where('run_key', $key)->lockForUpdate()->first();
                if (! $run) {
                    throw new \RuntimeException('reviewed_run_missing');
                }
                $old = json_decode($run->summary, true, 64, JSON_THROW_ON_ERROR);
                if (($old['stage'] ?? '') !== 'blocked') {
                    return $old;
                }
                $ids = array_values($old['locale_jobs'] ?? [$old['job_id'] ?? 0]);
                if (GrowthJob::whereIn('id', $ids)->whereIn('status', ['queued', 'processing'])->exists()) {
                    throw new \RuntimeException('prior_generation_active');
                }
                $attempt = (int) ($old['reviewed_retries'] ?? 0) + 1;
                if ($attempt > 2) {
                    throw new \RuntimeException('reviewed_retry_limit');
                }
                DB::table('growth_automation_runs')->insertOrIgnore(['run_key' => $key.':reviewed:'.$attempt,
                    'summary' => $run->summary, 'created_at' => now()]);
                $state = $this->plan($key, $old['source'], $actor, $attempt);
                $state['reviewed_retries'] = $attempt;
                $state['prior_job_ids'] = array_values(array_unique(array_merge($old['prior_job_ids'] ?? [], $ids)));
                $this->save($key, $state);
                GrowthAudit::record('daily_blog.reviewed_retry', $key, $actor, ['prior_job_ids' => $ids, 'new_job_ids' => $state['locale_jobs'] ?? []]);
                return $state;
            });
        } finally {
            $lock->release();
        }
    }

    private function plan(string $key, array $source, int $actor, int $attempt = 0): array
    {
        return DB::transaction(function () use ($key, $source, $actor, $attempt) {
            $state = ['stage' => 'blocked', 'policy' => 'native-trilingual-v2', 'source' => $source, 'code' => 'no_supported_model_or_budget'];
            $control = DB::table('growth_controls')->where('id', 1)->lockForUpdate()->first();
            if (! $control?->enabled) {
                $this->save($key, $state);
                return $state;
            }
            $jobs = app(GrowthJobs::class);
            $remaining = min($control->daily_irt - $jobs->spend(now()->startOfDay()), $control->monthly_irt - $jobs->spend(now()->startOfMonth()));
            $recent = PostTranslation::where('locale', 'en')->latest('id')->limit(60)->pluck('title')->all();
            $models = array_unique(array_merge(config('daily_blog.preferred_models', []), config('ai_growth.autopilot_text_models', [])));
            $best = null;
            foreach (array_slice($models, 0, 8) as $model) {
                try {
                    $requests = [];
                    $total = 0;
                    foreach (['fa', 'en', 'tr'] as $locale) {
                        $payload = app(DailyBlogArticle::class)->payload($model, $source, $recent, $locale);
                        $quote = app(GrowthGateway::class)->estimate($payload, 'text');
                        $total += $quote['max_cost_irt'];
                        $requests[$locale] = compact('payload', 'quote');
                    }
                    if ($total > 0 && $total <= $remaining) {
                        $best = $requests;
                        break;
                    }
                } catch (\Throwable) {
                }
            }
            if (! $best) {
                $this->save($key, $state);
                return $state;
            }
            // Reserve all three calls atomically before any inference; no partial budget approval.
            foreach ($best as $locale => $request) {
                $item = app(ContentWorkflow::class)->create(['title' => 'Daily insight '.now('Asia/Tehran')->toDateString().' '.$locale.' #'.$attempt.': '.mb_substr($source['title'], 0, 120),
                    'locale' => $locale, 'channel' => 'blog', 'format' => 'article', 'objective' => 'education',
                    'target_url' => $source['url'], 'brief' => 'Owner-authorized trilingual publication. Official snapshot: '.$source['url']], $actor);
                $q = $request['quote'];
                $job = $jobs->enqueue($item->id, 'text', $request['payload'], $q['max_cost_irt'], $item->version,
                    hash('sha256', 'daily-blog:trilingual-v1:'.$item->id), $actor, $q['gateway_token_id']);
                $state['locale_jobs'][$locale] = $job->id;
                $state['locale_contents'][$locale] = $item->id;
            }
            $state['stage'] = 'queued';
            $state['code'] = null;
            $state['content_id'] = $state['locale_contents']['fa'];
            $state['job_id'] = $state['locale_jobs']['fa'];
            $state['max_cost_irt'] = array_sum(array_column(array_column($best, 'quote'), 'max_cost_irt'));
            $this->save($key, $state);
            GrowthAudit::record('daily_blog.planned', $key, $actor, ['locale_jobs' => $state['locale_jobs'], 'max_cost_irt' => $state['max_cost_irt']]);
            return $state;
        });
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
            foreach (($state['locale_jobs'] ?? ['fa' => $job->id]) as $locale => $jobId) {
                $partJob = GrowthJob::findOrFail($jobId);
                $part = GrowthContent::lockForUpdate()->findOrFail($partJob->content_id);
                if ($partJob->status !== 'completed' || $part->status !== 'draft' || $part->version !== $partJob->content_version + 1) {
                    throw new \RuntimeException('operator_changed_content');
                }
            }
            $slug = 'daily-insight-'.now('Asia/Tehran')->toDateString().'-'.(Str::slug(mb_substr($state['source']['title'], 0, 65)) ?: 'technology');
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
            foreach (($state['locale_contents'] ?? ['fa' => $item->id]) as $locale => $id) {
                GrowthContent::findOrFail($id)->update(['status' => 'published', 'published_at' => now(), 'post_id' => $post->id,
                    'publication_url' => 'https://servernet.cloud'.($locale === 'fa' ? '' : '/'.$locale).'/blog/'.$slug]);
            }
            // Deliberately do not set approved_by or claim a human review.
            $state['stage'] = 'published';
            $state['post_id'] = $post->id;
            $state['published_at'] = now()->toIso8601String();
            $state['urls'] = array_map(fn ($l) => 'https://servernet.cloud'.($l === 'fa' ? '' : '/'.$l).'/blog/'.$slug, ['fa', 'en', 'tr']);
            unset($state['source']['text']);
            $this->save($key, $state);
            GrowthAudit::record('daily_blog.published', $key, null, ['post_id' => $post->id, 'policy' => 'native-trilingual-v1']);
            DB::afterCommit(function () use ($post) {
                BlogRepository::flush();
                try {
                    app(GoogleDiscovery::class)->enqueue($post->fresh('translations'));
                } catch (\Throwable) {
                    // The independent scanner will recover this enqueue; publication is already committed.
                    GrowthAudit::record('google_discovery.enqueue_deferred', 'post:'.$post->id);
                }
            });

            return $state;
        });
    }

    private function save(string $key, array $state): void
    {
        DB::table('growth_automation_runs')->updateOrInsert(['run_key' => $key],
            ['summary' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
