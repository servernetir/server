<?php

namespace App\Services\Growth;

use App\Models\AiModel;
use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\PostTranslation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class GrowthAutopilot
{
    public function status(): array
    {
        $control = DB::table('growth_controls')->find(1);
        $configured = Setting::get('growth_autopilot_enabled') === '1';
        $enabled = $configured && (bool) $control?->enabled;
        $actor = (int) Setting::get('growth_autopilot_actor_id', '0');
        $backlog = GrowthContent::whereIn('status', ['draft', 'review'])->count();
        $remaining = count($this->availableTopics());
        $latest = DB::table('growth_automation_runs')->where('run_key', 'like', 'autopilot:%')->latest('id')->limit(10)->get()
            ->map(fn ($r) => ['key' => $r->run_key, 'summary' => json_decode($r->summary, true), 'created_at' => $r->created_at])->all();
        $today = collect($latest)->firstWhere('key', 'autopilot:'.now('Asia/Tehran')->toDateString());
        $heartbeat = DB::table('growth_automation_runs')->where('run_key', 'engine:heartbeat')->first();
        $health = $heartbeat ? json_decode($heartbeat->summary, true) : null;
        $engine = ! $health ? 'unknown' : ((now()->diffInSeconds(Carbon::parse($heartbeat->created_at), true) > (($health['status'] ?? '') === 'running' ? 1200 : 300)) ? 'stale' : ($health['status'] ?? 'unknown'));
        $dayLeft = max(0, (int) $control?->daily_irt - app(GrowthJobs::class)->spend(now()->startOfDay()));
        $monthLeft = max(0, (int) $control?->monthly_irt - app(GrowthJobs::class)->spend(now()->startOfMonth()));
        $stage = $today['summary']['stage'] ?? null;
        $state = match (true) {
            ! $enabled => 'paused',
            ! User::whereKey($actor)->where('role', 'admin')->exists() => 'delegation_missing',
            in_array($stage, ['text_queued', 'image_queued'], true) => 'working',
            $stage === 'waiting_source' => 'waiting_source',
            ($today['summary']['code'] ?? null) === 'topic_pool_empty' => 'topic_pool_empty',
            $stage === 'blocked' => 'blocked',
            $backlog >= 7 => 'backlog_full',
            $dayLeft < 1 || $monthLeft < 1 => 'budget_exhausted',
            $stage === 'review_ready' => 'awaiting_review',
            $remaining === 0 => 'topic_pool_empty',
            now('Asia/Tehran')->hour < 8 => 'before_window',
            default => 'ready',
        };
        $next = now('Asia/Tehran')->startOfDay()->setHour(8);
        if ($today && $stage !== 'waiting_source') {
            $next->addDay();
        }
        if ($stage === 'waiting_source' && ! empty($today['summary']['next_attempt_at'])) {
            $next = Carbon::parse($today['summary']['next_attempt_at'])->setTimezone('Asia/Tehran');
        }
        if ($next->isPast() && ! $today) {
            $next = now('Asia/Tehran')->addMinute()->startOfMinute();
        }
        if (in_array($state, ['paused', 'delegation_missing', 'backlog_full', 'budget_exhausted', 'topic_pool_empty', 'blocked'], true)) {
            $next = null;
        }

        return ['enabled' => $configured, 'active' => $enabled, 'actor_id' => $actor, 'policy' => 'drafts-v1', 'daily_topics' => 1, 'review_backlog_limit' => 7,
            'publication' => 'human_review', 'timezone' => 'Asia/Tehran', 'operating_state' => $state, 'backlog' => $backlog,
            'topics_remaining' => $remaining, 'next_at' => $next?->toIso8601String(), 'engine_status' => $engine,
            'last_tick_at' => $heartbeat?->created_at, 'latest' => $latest];
    }

    public function prepare(): void
    {
        if (Setting::get('growth_autopilot_enabled') !== '1' || ! DB::table('growth_controls')->where('id', 1)->value('enabled')) {
            return;
        }
        $actor = User::whereKey((int) Setting::get('growth_autopilot_actor_id', '0'))->where('role', 'admin')->first();
        if (! $actor) {
            return;
        }
        foreach (DB::table('growth_automation_runs')->where('run_key', 'like', 'autopilot:%')->latest('id')->limit(30)->get() as $run) {
            $state = json_decode($run->summary, true);
            if (in_array($state['stage'] ?? '', ['text_queued', 'image_queued'], true)) {
                $this->advance($run, $state, $actor->id);
            }
        }
        if (now('Asia/Tehran')->hour < 8) {
            return;
        }
        $key = 'autopilot:'.now('Asia/Tehran')->toDateString();
        DB::transaction(function () use ($key, $actor) {
            $control = DB::table('growth_controls')->where('id', 1)->lockForUpdate()->first();
            if (! $control?->enabled) {
                return;
            }
            $run = DB::table('growth_automation_runs')->where('run_key', $key)->first();
            $prior = $run ? json_decode($run->summary, true) : [];
            if ($run && (($prior['stage'] ?? '') !== 'waiting_source' || ! empty($prior['content_id'])
                || ($prior['source_attempts'] ?? 0) >= 3 || empty($prior['next_attempt_at'])
                || Carbon::parse($prior['next_attempt_at'])->isFuture())) {
                return;
            }
            if (GrowthContent::whereIn('status', ['draft', 'review'])->count() >= 7) {
                return;
            }
            $topics = $this->availableTopics();
            $topic = $run ? collect($topics)->first(fn ($t) => $t === ($prior['topic'] ?? null)) : ($topics[0] ?? null);
            $state = ['stage' => 'blocked', 'code' => 'topic_pool_empty', 'policy' => 'drafts-v1'];
            if ($topic) {
                $state = ['stage' => 'blocked', 'code' => 'source_unavailable', 'policy' => 'drafts-v1', 'topic' => $topic,
                    'source_attempts' => (int) ($prior['source_attempts'] ?? 0) + 1, 'last_attempt_at' => now()->toIso8601String()];
                try {
                    $response = Http::timeout(8)->connectTimeout(3)->withoutRedirecting()->accept('text/html')->get($topic['url']);
                    if (! $response->ok() || strlen($response->body()) > 2_000_000) {
                        throw new \RuntimeException('source_unavailable');
                    }
                    $html = preg_replace('~<(script|style|nav|header|footer)\b[^>]*>.*?</\1>~is', '', $response->body());
                    $excerpt = app(Redactor::class)->clean(mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)))), 0, 8000));
                    if (mb_strlen($excerpt) < 100) {
                        throw new \RuntimeException('source_unavailable');
                    }
                    $state['code'] = 'generation_unavailable';
                    $item = app(ContentWorkflow::class)->create(['title' => $topic['title'], 'locale' => $topic['locale'], 'channel' => 'blog',
                        'format' => 'article', 'objective' => 'education', 'target_url' => $topic['url'],
                        'brief' => 'Educational decision guide; practical tradeoffs and limitations. Source snapshot: '.$topic['url'].' at '.now()->toIso8601String()."\n".$excerpt], $actor->id);
                    $state += ['content_id' => $item->id, 'source' => $topic['url'], 'source_sha256' => hash('sha256', $excerpt), 'source_at' => now()->toIso8601String()];
                    $job = $this->queue($item, 'text', $actor->id);
                    $state['text_job_id'] = $job->id;
                    $state['stage'] = 'text_queued';
                    $state['code'] = null;
                } catch (\Throwable $e) {
                    if ($state['code'] === 'source_unavailable' && empty($state['content_id']) && $state['source_attempts'] < 3) {
                        $state['stage'] = 'waiting_source';
                        $state['next_attempt_at'] = now()->addHour()->toIso8601String();
                    } elseif ($state['code'] !== 'source_unavailable') {
                        $state['code'] = $e instanceof \RuntimeException && preg_match('/^[a-z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'generation_unavailable';
                    }
                }
            }
            DB::table('growth_automation_runs')->updateOrInsert(['run_key' => $key], ['summary' => json_encode($state, JSON_THROW_ON_ERROR), 'created_at' => $run?->created_at ?? now()]);
            GrowthAudit::record($run ? 'autopilot.source_retry' : 'autopilot.planned', $key, $actor->id, $state);
        });
    }

    private function availableTopics(): array
    {
        $topics = [];
        foreach (config('ai_growth.autopilot_topics', []) as $topic) {
            if (! preg_match('~^/[a-z0-9/-]+$~D', $topic['path'])) {
                continue;
            }
            foreach (['fa', 'en', 'tr'] as $locale) {
                $title = $topic[$locale];
                $topics[] = ['title' => $title, 'locale' => $locale, 'url' => 'https://servernet.cloud'.($locale === 'fa' ? '' : '/'.$locale).$topic['path']];
            }
        }
        $fingerprint = fn ($title, $locale) => hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($title))).'|'.$locale.'|blog');
        $used = GrowthContent::whereIn('topic_hash', array_map(fn ($t) => $fingerprint($t['title'], $t['locale']), $topics))->pluck('topic_hash')->all();
        foreach (PostTranslation::whereIn('title', array_column($topics, 'title'))->get(['title', 'locale']) as $post) {
            $used[] = $fingerprint($post->title, $post->locale);
        }

        return array_values(array_filter($topics, fn ($t) => ! in_array($fingerprint($t['title'], $t['locale']), $used, true)));
    }

    private function queue(GrowthContent $item, string $kind, int $actor): GrowthJob
    {
        $gateway = app(GrowthGateway::class);
        $jobs = app(GrowthJobs::class);
        $control = DB::table('growth_controls')->find(1);
        $remaining = min($control->daily_irt - $jobs->spend(now()->startOfDay()), $control->monthly_irt - $jobs->spend(now()->startOfMonth()));
        $best = null;
        foreach (array_slice(config('ai_growth.autopilot_'.$kind.'_models', []), 0, 8) as $slug) {
            $model = AiModel::where('slug', $slug)->where('status', 'active')->whereNull('suspended_at')->first();
            if (! $model || $model->category !== ($kind === 'text' ? 'chat' : 'image')) {
                continue;
            }
            try {
                $input = clone $item;
                if ($kind === 'image') {
                    $input->brief = 'Conceptual editorial illustration: '.$item->title.'. No text, charts, prices or claims about actual infrastructure.';
                }
                $payload = $gateway->payload($input, $kind, $slug);
                if ($kind === 'text') {
                    $payload['messages'][0]['content'] .= ' Write only the public article, without an internal verification checklist. Do not quote product prices, traffic allowances, country counts, SLA/uptime guarantees or delivery-time promises, even if the source page mentions them. Link to the current official catalog instead. Sizing ranges are illustrative examples, never advertised plans. Do not claim that powering off stops billing. Do not invent ROI or break-even figures. Keep the requested language.';
                }
                $quote = $gateway->estimate($payload, $kind);
                if ($quote['max_cost_irt'] > 0 && $quote['max_cost_irt'] <= $remaining
                    && (! $best || $quote['max_cost_irt'] < $best['quote']['max_cost_irt'])) {
                    $best = compact('payload', 'quote');
                }
            } catch (\Throwable) { /* An unavailable quote never starts inference. */
            }
        }
        if (! $best) {
            throw new \RuntimeException($remaining < 1 ? 'budget_exhausted' : 'no_supported_model_or_key');
        }
        $job = $jobs->enqueue($item->id, $kind, $best['payload'], $best['quote']['max_cost_irt'], $item->version,
            hash('sha256', 'autopilot:drafts-v1:'.$item->id.':'.$kind), $actor, $best['quote']['gateway_token_id']);
        GrowthAudit::record('autopilot.cost_authorized', 'job:'.$job->id, $actor,
            ['policy' => 'drafts-v1', 'max_cost_irt' => $job->max_cost_irt, 'daily_irt' => $control->daily_irt, 'monthly_irt' => $control->monthly_irt]);

        return $job;
    }

    private function advance(object $run, array $state, int $actor): void
    {
        DB::transaction(function () use ($run, $state, $actor) {
            $control = DB::table('growth_controls')->where('id', 1)->lockForUpdate()->first();
            if (! $control?->enabled) {
                return;
            }
            $job = GrowthJob::find($state[$state['stage'] === 'text_queued' ? 'text_job_id' : 'image_job_id'] ?? 0);
            $item = GrowthContent::find($state['content_id'] ?? 0);
            if (! $job || ! $item || in_array($job->status, ['queued', 'processing'], true)) {
                return;
            }
            if ($job->status !== 'completed') {
                $state['stage'] = 'blocked';
                $state['code'] = 'failed_job_requires_review';
            } elseif ($item->status !== 'draft' || $item->version !== $job->content_version + 1) {
                $state['stage'] = 'blocked';
                $state['code'] = 'operator_changed_content';
            } elseif ($state['stage'] === 'text_queued' && ! $this->acceptable($item)) {
                $state['stage'] = 'blocked';
                $state['code'] = 'quality_review_required';
                GrowthAudit::record('autopilot.quality_rejected', 'content:'.$item->id, $actor);
            } elseif ($state['stage'] === 'text_queued' && app(GrowthClaims::class)->findings((string) $item->body)) {
                $state['stage'] = 'blocked';
                $state['code'] = 'claims_review_required';
                GrowthAudit::record('autopilot.claims_flagged', 'content:'.$item->id, $actor, ['checks' => app(GrowthClaims::class)->findings((string) $item->body)]);
            } else {
                if ($state['stage'] === 'text_queued' && Setting::get('growth_autopilot_images') === '1') {
                    try {
                        $image = $this->queue($item, 'image', $actor);
                        $state['image_job_id'] = $image->id;
                        $state['stage'] = 'image_queued';
                        DB::table('growth_automation_runs')->where('id', $run->id)->update(['summary' => json_encode($state, JSON_THROW_ON_ERROR)]);

                        return;
                    } catch (\Throwable) {
                        $state['image_note'] = 'image_not_available_within_budget';
                    }
                }
                app(ContentWorkflow::class)->transition($item->id, 'review', $item->version, $actor);
                $state['stage'] = 'review_ready';
                $state['code'] = null;
                GrowthAudit::record('autopilot.review_ready', 'content:'.$item->id, $actor);
            }
            DB::table('growth_automation_runs')->where('id', $run->id)->update(['summary' => json_encode($state, JSON_THROW_ON_ERROR)]);
        });
    }

    private function acceptable(GrowthContent $item): bool
    {
        $body = (string) $item->body;
        if (mb_strlen($body) < 600 || preg_match('/<\|[^>\r\n]{1,100}\|>|[\p{Han}\p{Hiragana}\p{Katakana}\p{Cyrillic}\p{Hangul}]/u', $body)) {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $body);
        $matching = preg_match_all($item->locale === 'fa' ? '/\p{Arabic}/u' : '/\p{Latin}/u', $body);

        return $letters > 0 && $matching / $letters >= ($item->locale === 'fa' ? 0.7 : 0.9);
    }
}
