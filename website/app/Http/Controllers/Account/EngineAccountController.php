<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\EngineVm;
use App\Models\Service;
use App\Services\Cloud\Engine\EngineAccountPresenter;
use App\Services\Cloud\Engine\EngineApiError;
use App\Services\Cloud\Engine\EngineApiService;
use App\Services\Cloud\Engine\EngineConsoleService;
use App\Support\ErrorTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/** Session/CSRF browser controls reuse the API's ownership and operation admission. */
class EngineAccountController extends Controller
{
    private function owned(Service $service): EngineVm
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer && (int) $service->customer_id === (int) $customer->id, 404);

        return EngineVm::where('service_id', $service->id)
            ->whereHas('service.cloudInstance', fn ($q) => $q->where('provider', 'cloud-engine'))->firstOrFail();
    }

    private function limit(Service $service): bool
    {
        return RateLimiter::attempt('engine-account:'.$service->customer_id.':'.$service->id, 12, fn () => true, 60) !== false;
    }

    public function status(Request $request, Service $service)
    {
        $this->owned($service);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        try {
            $snapshot = app(EngineAccountPresenter::class)->snapshot($service, $data['month'] ?? null);
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('cloud_engine_browser', 'Customer server status requires review', 300, ['exception_class' => $e::class]);

            return response()->json(['ok' => false, 'error' => 'temporarily_unavailable'], 503)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['ok' => true, 'data' => $snapshot])
            ->header('Cache-Control', 'private, no-store');
    }

    public function power(Request $request, Service $service)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['on', 'off', 'reboot', 'reset', 'shutdown'])]]);

        return $this->mutate($request, $service, 'power', $data);
    }

    public function rebuild(Request $request, Service $service)
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:64'], 'confirm' => ['required', Rule::in(['DELETE'])]]);

        return $this->mutate($request, $service, 'rebuild', ['image' => $data['image'], 'confirmed' => true]);
    }

    public function resetPassword(Request $request, Service $service)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->mutate($request, $service, 'reset_password', []);
    }

    public function hostname(Request $request, Service $service)
    {
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/D']]);

        return $this->mutate($request, $service, 'hostname', ['hostname' => strtolower($data['hostname'])]);
    }

    public function resize(Request $request, Service $service)
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:100'], 'upgrade_disk' => ['sometimes', 'boolean']]);
        return $this->mutate($request, $service, 'resize', ['plan' => $data['plan'], 'upgrade_disk' => (bool) ($data['upgrade_disk'] ?? true)]);
    }

    public function metadata(Request $request, Service $service)
    {
        $data = $request->validate(['labels_text' => ['present', 'nullable', 'string', 'max:16384'],
            'protect_delete' => ['required', 'boolean'], 'protect_rebuild' => ['required', 'boolean']]);
        $labels = [];
        foreach (preg_split('/\R/u', (string) $data['labels_text']) as $line) {
            if (trim($line) === '') continue;
            $pair = explode('=', $line, 2);
            $key = trim($pair[0]);
            if (count($pair) !== 2 || array_key_exists($key, $labels)) return back()->withErrors(__('cloud_engine.customer_labels_invalid'));
            $labels[$key] = trim($pair[1]);
        }
        return $this->mutate($request, $service, 'metadata', ['labels' => $labels,
            'protection' => ['delete' => (bool) $data['protect_delete'], 'rebuild' => (bool) $data['protect_rebuild']]]);
    }

    private function mutate(Request $request, Service $service, string $action, array $payload)
    {
        $vm = $this->owned($service);
        $data = $request->validate(['request_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{8,128}\z/D']]);
        if (! $this->limit($service)) {
            return back()->withErrors(__('cloud_engine.customer_conflict'));
        }
        try {
            app(EngineApiService::class)->mutate(Auth::guard('customer')->user(), $vm->uuid, $action, $payload, $data['request_key']);
        } catch (EngineApiError $e) {
            return back()->withErrors(__(in_array($e->publicCode, ['operation_in_progress', 'idempotency_conflict'], true)
                ? 'cloud_engine.customer_conflict' : 'cloud_engine.customer_unavailable'));
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('cloud_engine_browser', 'Customer server operation requires review', 300, ['exception_class' => $e::class]);

            return back()->withErrors(__('cloud_engine.customer_unavailable'));
        }

        return redirect()->to(lroute('account.cloud.show', $service))->with('ok', __('cloud_engine.customer_queued'));
    }

    public function console(Request $request, Service $service)
    {
        $vm = $this->owned($service);
        if (! $this->limit($service)) {
            return back()->withErrors(__('cloud_engine.customer_conflict'));
        }
        try {
            $grant = app(EngineConsoleService::class)->issue($vm, (int) $service->customer_id);
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('cloud_engine_browser', 'Customer console requires review', 300, ['exception_class' => $e::class]);

            return back()->withErrors(__('cloud_engine.customer_unavailable'));
        }

        return redirect()->away($grant['url'])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
