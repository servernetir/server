<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Growth\GoogleDiscovery;
use App\Services\Growth\GrowthAudit;
use App\Services\Growth\SearchConsoleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchConsoleController extends Controller
{
    public const STATE = 'growth_search_console_oauth';

    public function index(Request $request, GoogleDiscovery $delivery)
    {
        $this->guard($request);

        return view('admin.growth.google-discovery', ['status' => $delivery->status()]);
    }

    public function configure(Request $request)
    {
        $this->guard($request);
        $request->validate(['enabled' => 'required|boolean']);
        Setting::put('google_discovery_enabled', $request->boolean('enabled') ? '1' : '0');
        if (! Setting::get('google_discovery_started_at')) {
            Setting::put('google_discovery_started_at', now()->toIso8601String());
        }
        GrowthAudit::record('google_discovery.policy_updated', 'google-discovery', (int) $request->user()->id,
            ['enabled' => $request->boolean('enabled')]);

        return back()->with('ok', __('google-discovery.saved'));
    }

    public function connect(Request $request, SearchConsoleClient $google)
    {
        $this->guard($request);
        abort_unless($google->status()['configured'], 422, __('google-discovery.credentials_missing'));
        $state = Str::random(48);
        $verifier = Str::random(64);
        $request->session()->put(self::STATE, ['state' => $state, 'verifier' => $verifier,
            'user_id' => (int) $request->user()->id, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return redirect()->away($google->authUrl($state, $verifier, $this->redirectUri()));
    }

    /** Reached through the existing registered Calendar callback, only on this exact session-bound state. */
    public function callback(Request $request, SearchConsoleClient $google)
    {
        $this->guard($request);
        $pending = $request->session()->pull(self::STATE);
        abort_unless(is_array($pending) && ($pending['user_id'] ?? null) === (int) $request->user()->id
            && ($pending['expires_at'] ?? 0) > now()->timestamp && is_string($request->query('state'))
            && is_string($pending['state'] ?? null) && hash_equals($pending['state'], $request->query('state'))
            && is_string($pending['verifier'] ?? null), 403);
        $code = $request->query('code');
        if ($request->filled('error') || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return redirect('/admin/ai-growth/google-discovery')->with('err', __('google-discovery.connection_failed'));
        }
        try {
            $google->exchange($code, $pending['verifier'], $this->redirectUri());
        } catch (\Throwable) {
            return redirect('/admin/ai-growth/google-discovery')->with('err', __('google-discovery.connection_failed'));
        }
        GrowthAudit::record('google_discovery.connected', 'google-discovery', (int) $request->user()->id);

        return redirect('/admin/ai-growth/google-discovery')->with('ok', __('google-discovery.connected'));
    }

    public function disconnect(Request $request, SearchConsoleClient $google)
    {
        $this->guard($request);
        $google->disconnect();
        $request->session()->forget(self::STATE);
        GrowthAudit::record('google_discovery.disconnected', 'google-discovery', (int) $request->user()->id);

        return back()->with('ok', __('google-discovery.saved'));
    }

    private function redirectUri(): string
    {
        return url('/admin/calendar/google/callback');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
