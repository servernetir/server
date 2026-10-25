<?php

namespace App\Services\Growth;

use App\Models\AiUsage;
use App\Models\GrowthContent;
use App\Models\GrowthJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GrowthJobs
{
    public function enqueue(int $contentId, string $kind, array $payload, int $max, int $version, string $key, int $actor, ?int $gatewayTokenId = null): GrowthJob
    {
        return DB::transaction(function () use ($contentId, $kind, $payload, $max, $version, $key, $actor, $gatewayTokenId) {
            $control = DB::table('growth_controls')->where('id', 1)->lockForUpdate()->first();
            if ($old = GrowthJob::where('request_key', $key)->first()) {
                if ((int) $old->content_id !== $contentId || $old->kind !== $kind || $old->payload !== $payload) {
                    abort(409);
                }

                return $old;
            }
            if (! $control?->enabled) {
                throw ValidationException::withMessages(['jobs' => 'تولید متوقف است؛ آن را در تنظیمات فعال کنید.']);
            }
            $item = GrowthContent::lockForUpdate()->findOrFail($contentId);
            app(ContentWorkflow::class)->editable($item, $version);
            if ($item->status !== 'draft') {
                abort(409, 'تولید فقط برای پیش‌نویس مجاز است.');
            }
            if (GrowthJob::where('content_id', $contentId)->where(function ($q) {
                $q->whereIn('status', ['queued', 'processing'])->orWhere(function ($q) {
                    $q->where('status', 'failed')->whereNull('actual_cost_irt');
                });
            })->exists()) {
                abort(409, 'برای این محتوا کار فعال وجود دارد.');
            }
            if ($max < 1 || $this->spend(now()->startOfDay()) + $max > $control->daily_irt
                || $this->spend(now()->startOfMonth()) + $max > $control->monthly_irt) {
                throw ValidationException::withMessages(['budget' => 'سقف هزینه روزانه یا ماهانه کافی نیست.']);
            }
            $job = GrowthJob::create(['request_key' => $key, 'content_id' => $contentId, 'kind' => $kind, 'payload' => $payload,
                'model_slug' => $payload['model'] ?? null, 'gateway_token_id' => $gatewayTokenId, 'content_version' => $version, 'max_cost_irt' => $max]);
            GrowthAudit::record('job.queued', 'job:'.$job->id, $actor, ['max_cost_irt' => $max]);

            return $job;
        });
    }

    public function spend(\DateTimeInterface $from): int
    {
        // Uncertain and in-flight calls keep their whole approved ceiling until reconciled.
        return (int) GrowthJob::where('created_at', '>=', $from)->sum(DB::raw('COALESCE(actual_cost_irt,max_cost_irt)'));
    }

    public function run(GrowthJob $job): void
    {
        if (! DB::table('growth_controls')->where('id', 1)->value('enabled')) {
            return;
        }
        if (! GrowthJob::whereKey($job->id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now(),
            'attempts' => DB::raw('attempts + 1')])) {
            return;
        }
        $start = microtime(true);
        $job->refresh();
        $sent = false;
        try {
            if ($job->created_at->utc()->toDateString() !== now('UTC')->toDateString()) {
                throw new \RuntimeException('quote_day_expired');
            }
            $item = GrowthContent::findOrFail($job->content_id);
            if ((int) $item->version !== $job->content_version || $item->status !== 'draft') {
                throw new \RuntimeException('content_changed');
            }
            $estimate = app(GrowthGateway::class)->estimate($job->payload, $job->kind);
            if ($job->gateway_token_id && (int) $job->gateway_token_id !== (int) ($estimate['gateway_token_id'] ?? 0)) {
                throw new \RuntimeException('gateway_key_changed');
            }
            if ($estimate['max_cost_irt'] > $job->max_cost_irt) {
                throw new \RuntimeException('quote_increased');
            }
            $sent = true;
            $outcome = app(GrowthGateway::class)->execute($job);
            $usage = $outcome->usage?->fresh();
            $job->update(['ai_usage_id' => $usage?->id, 'actual_cost_irt' => $usage && in_array($usage->status, AiUsage::CHARGED, true)
                ? max(0, (int) $usage->charged_irt - (int) $usage->refunded_irt) : ($usage === null || $usage->status === 'released' ? 0 : null)]);
            if (! $outcome->ok) {
                throw new \RuntimeException($outcome->code);
            }
            if ($job->kind === 'text') {
                $body = data_get($outcome->response, 'choices.0.message.content');
                if (! is_string($body) || trim($body) === '' || mb_strlen($body) > 80000) {
                    throw new \RuntimeException('invalid_ai_output');
                }
                DB::transaction(function () use ($job, $body) {
                    $item = GrowthContent::lockForUpdate()->findOrFail($job->content_id);
                    if ((int) $item->version !== $job->content_version) {
                        throw new \RuntimeException('content_changed');
                    }
                    $item->update(['body' => app(Redactor::class)->clean($body), 'version' => $item->version + 1, 'status' => 'draft',
                        'approved_at' => null, 'approved_by' => null]);
                    app(ContentWorkflow::class)->revision($item, null);
                });
            } else {
                DB::transaction(function () use ($item, $job, $outcome) {
                    $locked = GrowthContent::lockForUpdate()->findOrFail($item->id);
                    if ((int) $locked->version !== $job->content_version) {
                        throw new \RuntimeException('content_changed');
                    }
                    app(MediaArchive::class)->fromProvider($locked, $job, $outcome->response ?? []);
                    $locked->update(['version' => $locked->version + 1, 'status' => 'draft', 'approved_at' => null, 'approved_by' => null]);
                    app(ContentWorkflow::class)->revision($locked, null);
                });
            }
            $job->update(['status' => 'completed', 'finished_at' => now()]);
            GrowthAudit::record('job.completed', 'job:'.$job->id);
            app(GrowthNotifications::class)->send('محتوا آماده بازبینی است', $job->content_id);
        } catch (\Throwable $e) {
            // Never repeat a possibly charged operation automatically. Gateway owns reconciliation.
            $code = $e instanceof \RuntimeException && preg_match('/^[a-z_]{3,80}$/D', $e->getMessage()) ? $e->getMessage() : 'generation_failed';
            $job->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now()] + (! $sent ? ['actual_cost_irt' => 0] : []));
            GrowthAudit::record('job.failed', 'job:'.$job->id, null, ['code' => $code]);
            app(GrowthNotifications::class)->send('خطای پردازش؛ بررسی رسید لازم است', $job->content_id);
        } finally {
            $job->update(['duration_ms' => (int) round((microtime(true) - $start) * 1000)]);
        }
    }
}
