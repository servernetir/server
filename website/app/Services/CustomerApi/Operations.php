<?php

namespace App\Services\CustomerApi;

use App\Models\CustomerApiToken;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable claims survive lost HTTP responses and cache eviction. */
class Operations
{
    public function reconcile(Service $service, string $status, string $observedAt): void
    {
        $at = \Illuminate\Support\Carbon::parse($observedAt);
        $stopAt = data_get($service->fresh()->provision_meta, 'hourly_stop_requested_at');
        if ($status === 'off' && filled($stopAt) && $at->gte(\Illuminate\Support\Carbon::parse($stopAt))) {
            DB::table('customer_api_operations')->where('customer_id', $service->customer_id)->where('service_id', $service->id)
                ->whereIn('state', ['uncertain', 'processing'])->where('created_at', '<=', \Illuminate\Support\Carbon::parse($stopAt))
                ->update(['state' => 'superseded', 'updated_at' => now()]);
        }
        DB::table('customer_api_operations')->where('customer_id', $service->customer_id)->where('service_id', $service->id)
            ->where(function ($q) {
                $q->where('state', 'uncertain')->orWhere(fn ($q) => $q->where('state', 'processing')->where('created_at', '<', now()->subMinutes(5)));
            })->where('updated_at', '<', $at)
            ->where('action', match ($status) { 'running' => 'on', 'off' => 'off', default => '__none__' })
            ->update(['state' => 'reconciled', 'updated_at' => now()]);
    }

    public function execute(CustomerApiToken $token, Service $service, string $key, string $action, callable $run): JsonResponse
    {
        if (! Schema::hasTable('customer_api_operations')) {
            return response()->json(['ok' => false, 'error' => 'api_not_ready'], 503);
        }
        if (! preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/D', $key)) {
            return response()->json(['ok' => false, 'error' => 'idempotency_key_required'], 422);
        }
        $hash = hash('sha256', $key);
        $fingerprint = hash('sha256', $service->id.':'.$action);
        $publicId = (string) Str::uuid();
        $inserted = DB::table('customer_api_operations')->insertOrIgnore([
            'public_id' => $publicId, 'customer_id' => $token->customer_id, 'token_id' => $token->id,
            'service_id' => $service->id, 'key_hash' => $hash, 'fingerprint' => $fingerprint,
            'action' => $action, 'state' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('customer_api_operations')->where('customer_id', $token->customer_id)->where('key_hash', $hash)->first();
        abort_unless($row, 503);
        if (! hash_equals($row->fingerprint, $fingerprint)) {
            return response()->json(['ok' => false, 'error' => 'idempotency_conflict'], 409);
        }
        if (! $inserted) {
            if ($row->response !== null) {
                return response()->json(json_decode($row->response, true, flags: JSON_THROW_ON_ERROR), $row->http_status)
                    ->header('Idempotency-Replayed', 'true');
            }
            return response()->json(['ok' => false, 'error' => 'operation_in_progress', 'operation_id' => $row->public_id], 409);
        }
        try {
            $result = $run($row->id);
        } catch (\Throwable $e) {
            report($e);
            $result = ['ok' => false, 'error' => 'provider_result_unknown', 'http_status' => 503, 'uncertain' => true];
        }
        $status = (int) ($result['http_status'] ?? 500);
        $state = ($result['uncertain'] ?? false) ? 'uncertain' : 'completed';
        unset($result['http_status'], $result['uncertain']);
        $result['operation_id'] = $publicId;
        DB::table('customer_api_operations')->where('id', $row->id)->update([
            'state' => $state, 'response' => json_encode($result, JSON_THROW_ON_ERROR),
            'http_status' => $status, 'updated_at' => now(),
        ]);
        return response()->json($result, $status);
    }
}
