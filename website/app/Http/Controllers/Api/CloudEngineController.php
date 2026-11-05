<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CloudEnginePrivacy;
use App\Models\Customer;
use App\Models\EngineOperation;
use App\Models\EngineVm;
use App\Services\Cloud\Engine\EngineApiError;
use App\Services\Cloud\Engine\EngineApiService;
use App\Services\Cloud\Engine\EngineConsoleService;
use App\Services\Cloud\Engine\EngineProvider;
use App\Services\Cloud\Engine\EngineResize;
use App\Services\Cloud\Engine\EngineTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CloudEngineController extends Controller
{
    public function __construct(private readonly EngineApiService $api) {}

    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (\Throwable $e) {
            // Laravel renders route exceptions inside its pipeline before an
            // outer middleware can catch them. Catch infrastructure errors at
            // the controller boundary so APP_DEBUG cannot expose credentials.
            return CloudEnginePrivacy::failure($e);
        }
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->attributes->get('api_customer');
        abort_unless($customer instanceof Customer, 401);

        return $customer;
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $servers = EngineVm::with(['service.cloudInstance', 'address', 'image'])
            ->whereHas('service', fn ($q) => $q->where('customer_id', $customer->id))
            ->orderBy('id')->paginate(50, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['ok' => true, 'data' => $servers->getCollection()->map(fn ($vm) => $this->api->serverDto($vm)),
            'pagination' => ['page' => $servers->currentPage(), 'last_page' => $servers->lastPage(), 'total' => $servers->total()]]);
    }

    public function show(Request $request, string $server): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->api->serverDto($this->api->owned($this->customer($request), $server))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['service_id' => ['required', 'integer', 'min:1']]);

        return $this->accepted($this->api->create($this->customer($request), $data['service_id'], (string) $request->header('Idempotency-Key')));
    }

    public function destroy(Request $request, string $server): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->mutation($request, $server, 'delete', []);
    }

    public function power(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['on', 'off', 'reboot', 'reset', 'shutdown'])]]);

        return $this->mutation($request, $server, 'power', $data);
    }

    public function rebuild(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/D'], 'confirm' => ['required', 'accepted']]);

        return $this->mutation($request, $server, 'rebuild', ['image' => $data['image'], 'confirmed' => true]);
    }

    public function resetPassword(Request $request, string $server): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->mutation($request, $server, 'reset_password', []);
    }

    public function hostname(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/D']]);

        return $this->mutation($request, $server, 'hostname', ['hostname' => strtolower($data['hostname'])]);
    }

    public function resize(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:100'], 'upgrade_disk' => ['sometimes', 'boolean']]);
        return $this->mutation($request, $server, 'resize', ['plan' => $data['plan'], 'upgrade_disk' => (bool) ($data['upgrade_disk'] ?? true)]);
    }

    public function metadata(Request $request, string $server): JsonResponse
    {
        return $this->mutation($request, $server, 'metadata', $request->json()->all());
    }

    public function resizeOptions(Request $request, string $server): JsonResponse
    {
        $vm = $this->api->owned($this->customer($request), $server);
        return response()->json(['ok' => true, 'data' => ['plans' => app(EngineResize::class)->options($vm),
            'currency' => $vm->service->currency_code ?: 'IRT', 'cycle' => $vm->service->cycle,
            'price_effective' => 'next_renewal', 'disk_shrink_supported' => false]]);
    }

    public function history(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $vm = $this->api->owned($this->customer($request), $server);
        $operations = EngineOperation::where('vm_id', $vm->id)->latest('id')->paginate(50, ['*'], 'page', $data['page'] ?? 1);
        return response()->json(['ok' => true, 'data' => $operations->getCollection()->map(fn ($op) => $this->api->operationDto($op)),
            'pagination' => ['page' => $operations->currentPage(), 'last_page' => $operations->lastPage(), 'total' => $operations->total()]]);
    }

    private function mutation(Request $request, string $server, string $action, array $payload): JsonResponse
    {
        return $this->accepted($this->api->mutate($this->customer($request), $server, $action, $payload, (string) $request->header('Idempotency-Key')));
    }

    private function accepted(EngineOperation $operation): JsonResponse
    {
        return response()->json(['ok' => true, 'operation' => $this->api->operationDto($operation)], 202)
            ->header('Location', '/api/v1/operations/'.$operation->id);
    }

    public function operation(Request $request, string $operation): JsonResponse
    {
        $customer = $this->customer($request);
        $row = EngineOperation::whereKey($operation)
            ->whereHas('vm.service', fn ($q) => $q->where('customer_id', $customer->id))->firstOrFail();

        return response()->json(['ok' => true, 'data' => $this->api->operationDto($row)]);
    }

    public function console(Request $request, string $server): JsonResponse
    {
        $customer = $this->customer($request);
        $vm = $this->api->owned($customer, $server);
        try {
            $ticket = app(EngineConsoleService::class)->issue($vm, $customer->id);
        } catch (\InvalidArgumentException) {
            throw new EngineApiError('console_unavailable', 409);
        }

        return response()->json(['ok' => true, 'data' => $ticket], 201);
    }

    public function traffic(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $vm = $this->api->owned($this->customer($request), $server);

        return response()->json(['ok' => true, 'data' => $this->api->trafficDto(app(EngineTrafficService::class)->report($vm, $data['month'] ?? null))]);
    }

    public function metrics(Request $request, string $server): JsonResponse
    {
        $data = $request->validate(['window' => ['sometimes', Rule::in(['1h', '24h', '7d', '30d'])]]);
        $vm = $this->api->owned($this->customer($request), $server);
        $result = app(EngineProvider::class)->metrics($vm->uuid, $data['window'] ?? '24h');
        if (! ($result['ok'] ?? false)) {
            throw new EngineApiError('metrics_unavailable', 503);
        }

        return response()->json(['ok' => true, 'data' => $this->api->metricsDto($result)]);
    }
}
