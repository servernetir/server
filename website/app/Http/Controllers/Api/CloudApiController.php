<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Account\CloudServerController;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Cloud\CloudPower;
use App\Services\Cloud\HourlyGpuStart;
use App\Services\CustomerApi\Operations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudApiController extends Controller
{
    private function query(Request $request)
    {
        $ids = $request->attributes->get('api_token')->cloud_service_ids;
        return Service::where('customer_id', $request->attributes->get('api_customer')->id)
            ->whereHas('cloudInstance')->when(is_array($ids), fn ($q) => $q->whereIn('id', $ids));
    }

    private function owned(Request $request, string $id): Service
    {
        return $this->query($request)->with(['cloudInstance', 'cloudPlan'])->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->query($request)->with(['cloudInstance', 'cloudPlan'])->orderByDesc('id')->paginate(50);
        return response()->json(['ok' => true, 'data' => $page->getCollection()->map(fn ($s) => $this->resource($s)),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]]);
    }

    public function show(Request $request, string $service): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->resource($this->owned($request, $service))]);
    }

    public function status(Request $request, string $service): JsonResponse
    {
        $owned = $this->owned($request, $service);
        try {
            $result = app(CloudServerController::class)->status($owned)->getData(true);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => 'status_unavailable', 'data' => $this->resource($owned->fresh())], 503);
        }
        // Raw driver build metadata and private identifiers never leave this API.
        $safe = array_intersect_key($result, array_flip(['status', 'ready', 'closed', 'stage', 'stage_index', 'waiting_capacity', 'capacity_state', 'checked_at', 'delivery_issue']));
        $ok = (bool) ($result['ok'] ?? false);
        if ($ok && isset($result['observed_at'], $result['status'])) {
            app(Operations::class)->reconcile($owned, $result['status'], $result['observed_at']);
        }
        return response()->json(['ok' => $ok, 'data' => $safe + ['service_id' => $owned->id,
            'observed_at' => $result['observed_at'] ?? null, 'cache_seconds' => 20]]
            + ($ok ? [] : ['error' => 'status_unavailable']), $ok ? 200 : 503);
    }

    public function metrics(Request $request, string $service): JsonResponse
    {
        return app(CloudServerController::class)->metrics($request, $this->owned($request, $service));
    }

    public function power(Request $request, string $service, string $action): JsonResponse
    {
        $owned = $this->owned($request, $service);
        $mapped = ['start' => 'on', 'stop' => 'off', 'reboot' => 'reboot'][$action] ?? null;
        abort_unless($mapped, 404);
        return app(Operations::class)->execute($request->attributes->get('api_token'), $owned,
            (string) $request->header('Idempotency-Key'), $mapped,
            fn ($id) => app(CloudPower::class)->execute($owned, $mapped, $id));
    }

    public function operation(Request $request, string $operation): JsonResponse
    {
        $row = DB::table('customer_api_operations')->where('customer_id', $request->attributes->get('api_customer')->id)
            ->where('public_id', $operation)->first();
        abort_unless($row, 404);
        $this->owned($request, (string) $row->service_id);
        return response()->json(['ok' => true, 'data' => ['id' => $row->public_id, 'service_id' => $row->service_id,
            'action' => $row->action, 'state' => $row->state, 'created_at' => $row->created_at,
            'result' => $row->response ? json_decode($row->response, true, flags: JSON_THROW_ON_ERROR) : null]]);
    }

    private function resource(Service $service): array
    {
        $i = $service->cloudInstance;
        return ['id' => $service->id, 'name' => $service->name,
            'product' => $i?->isGpuApp() ? 'gpu' : 'vps', 'service_status' => $service->status,
            'status' => $i?->status ?? 'unknown', 'observed_at' => $i?->synced_at?->toIso8601String(),
            'actions' => array_map(fn ($a) => ['on' => 'start', 'off' => 'stop', 'reboot' => 'reboot'][$a], app(CloudPower::class)->actions($i)),
            'billing' => ['mode' => $service->billing_mode, 'currency' => $service->currency_code,
                'cycle' => $service->hasUnverifiedBillingTerms() ? null : $service->cycle,
                'price' => $service->hasUnverifiedBillingTerms() ? null : (int) $service->price,
                'hourly_rate_irt' => $service->isHourly() ? (int) $service->hourly_rate_irt : null,
                'restart_reservation_irt' => HourlyGpuStart::pending($service),
                'off_billing' => $service->isHourly() && (bool) $service->cloudPlan?->is_interruptible ? 'paused' : 'continues',
                'next_due_at' => $service->next_due_at?->toDateString(), 'held' => $service->hourlyBillingHeld()]];
    }
}
