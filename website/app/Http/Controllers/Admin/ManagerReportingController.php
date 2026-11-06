<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Bale\Management\ManagerGoogleReporting;
use App\Services\Bale\Management\ManagerTrafficSnapshot;
use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ManagerReportingController extends Controller
{
    public const STATE = 'bale_manager_google_state';
    private const HANDOFF = 'bale_manager_google_handoff';

    public function index(Request $request, ManagerGoogleReporting $google, ManagerTrafficSnapshot $traffic): View
    {
        $this->guard($request);

        return view('admin.bale.reporting', ['connection' => $google->status(), 'configuration' => $google->configuration(),
            'snapshot' => $traffic->cachedSnapshot()]);
    }

    public function configure(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'property_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! ManagerGoogleReporting::validProperty($value)) { $fail(__('bale-reporting.invalid_property')); }
            }],
            'site' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! ManagerGoogleReporting::validSite($value)) { $fail(__('bale-reporting.invalid_site')); }
            }],
        ]);
        Setting::put(ManagerGoogleReporting::PROPERTY_KEY, $data['property_id']);
        Setting::put(ManagerGoogleReporting::SITE_KEY, $data['site']);

        return redirect('/admin/bale/reporting')->with('ok', __('bale-reporting.saved'));
    }

    public function connect(Request $request, ManagerGoogleReporting $google): RedirectResponse
    {
        $this->guard($request);
        if (! $google->status()['configured']) {
            return redirect('/admin/bale/reporting')->with('err', __('bale-reporting.credentials_missing'));
        }
        $state = Str::random(48);
        $verifier = Str::random(64);
        $request->session()->put(self::STATE, $state);
        $request->session()->put(self::HANDOFF, ['user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp, 'verifier' => $verifier]);

        return redirect()->away($google->authUrl($state, $this->redirectUri(), $verifier));
    }

    /** Reached exclusively through the already registered Calendar OAuth callback. */
    public function callback(Request $request, ManagerGoogleReporting $google): RedirectResponse
    {
        $this->guard($request);
        $state = $request->session()->pull(self::STATE);
        $handoff = $request->session()->pull(self::HANDOFF);
        if (! is_string($state) || $state === '' || ! hash_equals($state, (string) $request->query('state'))
            || ! is_array($handoff) || ($handoff['user_id'] ?? null) !== $request->user()->id
            || ($handoff['expires_at'] ?? 0) < now()->timestamp || ! is_string($handoff['verifier'] ?? null)) {
            return redirect('/admin/bale/reporting')->with('err', __('bale-reporting.invalid_state'));
        }
        $code = $request->query('code');
        if ($request->filled('error') || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return redirect('/admin/bale/reporting')->with('err', __('bale-reporting.connection_cancelled'));
        }
        $result = $google->exchangeCode($code, $this->redirectUri(), $handoff['verifier']);

        return redirect('/admin/bale/reporting')->with($result['ok'] ? 'ok' : 'err',
            __($result['ok'] ? 'bale-reporting.connected_success' : 'bale-reporting.connection_failed'));
    }

    public function disconnect(Request $request, ManagerGoogleReporting $google): RedirectResponse
    {
        $this->guard($request);
        $google->disconnect();
        $request->session()->forget([self::STATE, self::HANDOFF]);

        return redirect('/admin/bale/reporting')->with('ok', __('bale-reporting.disconnected_success'));
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isAdmin() && BaleManagerPolicy::enabled(), 403);
    }

    private function redirectUri(): string
    {
        return url('/admin/calendar/google/callback');
    }
}
