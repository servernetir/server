<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use App\Models\AiUsage;
use Illuminate\Support\Facades\DB;

/** Short-lived encrypted evidence for a native 2xx response an older parser cannot read. */
final class AiNativeResponseRecovery
{
    public const MAX_BYTES = 4194304;
    public const RAW_FIELD = '_servernet_unparsed_native';

    public function __construct(private readonly AiSettlement $settlement) {}

    public static function capture(AiUsage $usage, string $key, string $raw, int $status): void
    {
        if ($usage->operation !== 'native' || ! is_array($usage->native_snapshot)
            || $status < 200 || $status >= 300 || $raw === '' || strlen($raw) > self::MAX_BYTES) return;

        try {
            // Keep the original string: decoding/re-encoding a numeric cost would lose precision.
            AiCall::create([
                'customer_id' => $usage->customer_id, 'ai_reservation_id' => $usage->ai_reservation_id,
                'model_slug' => $usage->model_slug, 'idempotency_key' => $key, 'ok' => false,
                'request_body' => null, 'response_body' => null,
                'response_encrypted' => [self::RAW_FIELD => $raw], 'upstream_status' => $status,
            ]);
        } catch (\Throwable) {
            // Evidence is optional; failure keeps the existing unknown-cost path closed.
        }
    }

    public function recover(AiUsage $usage): bool
    {
        if ($usage->operation !== 'native' || ! is_array($usage->native_snapshot['contract'] ?? null)) return false;
        $call = AiCall::where('customer_id', $usage->customer_id)
            ->where('ai_reservation_id', $usage->ai_reservation_id)->where('ok', false)
            ->where('created_at', '>=', self::expiresBefore())->first();
        if (! $call) return false;
        try {
            $raw = $call->response_encrypted[self::RAW_FIELD] ?? null;
            if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES) return false;
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        if (! is_array($body)) return false;
        $parsed = AiUsageParser::parse($body, $raw, 'native', $usage->native_snapshot);
        if ($parsed === null) return false;

        // All pricing comes from the reservation snapshot, never today's catalogue.
        $settled = $usage->status === AiUsage::STATUS_SETTLE_PENDING
            ? $this->settlement->finalize($usage->id)
            : $this->settlement->settleFromUsage($usage, $parsed, $usage->upstream_request_id);
        if (! in_array($settled->status, AiUsage::CHARGED, true) || $settled->usage_source === 'cap') return false;

        return DB::transaction(function () use ($call, $body, $settled) {
            $row = AiCall::whereKey($call->id)->where('ok', false)
                ->where('created_at', '>=', self::expiresBefore())->lockForUpdate()->first();
            if (! $row) return false;
            $row->forceFill(['ok' => true, 'response_encrypted' => AiCaller::publicBody($body, $settled),
                'request_body' => null, 'response_body' => null])->save();
            return true;
        });
    }

    public static function expiresBefore(): \Illuminate\Support\Carbon
    {
        return now()->subHours((int) config('ai.replay_ttl_h', 24));
    }
}
