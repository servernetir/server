<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ManagerReportingController;
use App\Models\GoogleCalendarToken;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Management\ManagerGoogleReporting;
use App\Services\Bale\Management\ManagerTrafficSnapshot;
use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerGoogleReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-31 09:00:00', 'Asia/Tehran'));
        Setting::put(BaleManagerPolicy::SETTING, '1');
        Setting::put('google_client_id', 'fixture-google-client');
        Setting::putSecret('google_client_secret', 'fixture-google-secret');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $role = 'admin'): User
    {
        return User::create(['name' => 'Reporting fixture', 'email' => bin2hex(random_bytes(5)).'@example.test',
            'password' => bcrypt('fixture-password'), 'role' => $role]);
    }

    private function tokenReply(array $replace = []): array
    {
        return array_replace(['access_token' => 'fixture-reporting-access', 'refresh_token' => 'fixture-reporting-refresh',
            'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => ManagerGoogleReporting::SCOPES], $replace);
    }

    private function grant(int $expiry = 3600): ManagerGoogleReporting
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response($this->tokenReply(['expires_in' => $expiry])),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'manager@example.test', 'email_verified' => true]),
        ]);
        $google = app(ManagerGoogleReporting::class);
        $this->assertTrue($google->exchangeCode('fixture-code', 'https://console.example.test/admin/calendar/google/callback', 'fixture-verifier')['ok']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();

        return $google;
    }

    private function reports(bool $gscYesterdayPending = false): void
    {
        Http::fake(function (Request $request) use ($gscYesterdayPending) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response($this->tokenReply(['access_token' => 'fixture-refreshed-access', 'refresh_token' => 'fixture-rotated-refresh']));
            }
            if (str_starts_with($request->url(), 'https://analyticsdata.googleapis.com/')) {
                $month = $request['dateRanges'][0]['startDate'] !== 'yesterday';

                return Http::response(['metricHeaders' => [['name' => 'totalUsers', 'type' => 'TYPE_INTEGER'], ['name' => 'sessions', 'type' => 'TYPE_INTEGER']],
                    'rows' => [['metricValues' => [['value' => $month ? '77' : '12'], ['value' => $month ? '99' : '15']]]],
                    'rowCount' => 1, 'metadata' => ['timeZone' => 'Asia/Tehran']]);
            }
            if (str_starts_with($request->url(), 'https://www.googleapis.com/webmasters/')) {
                $fresh = $request['dataState'] === 'all';
                if ($fresh && $gscYesterdayPending) { return Http::response(['rows' => []]); }
                $row = ['clicks' => $fresh ? 5.0 : 101.0, 'impressions' => $fresh ? 30.0 : 800.0];
                if ($fresh) { $row['keys'] = [$request['startDate']]; }

                return Http::response(['rows' => [$row], 'responseAggregationType' => 'byProperty']);
            }
            $this->fail('Unexpected external request');
        });
    }

    public function test_connection_requests_only_read_scopes_with_pkce_and_registered_redirect(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/bale/reporting/connect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(ManagerGoogleReporting::SCOPES, $query['scope']);
        $this->assertSame('false', $query['include_granted_scopes']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringEndsWith('/admin/calendar/google/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringNotContainsString('calendar', $query['scope']);
        Http::assertNothingSent();
    }

    public function test_matching_reporting_state_uses_separate_encrypted_token_and_keeps_calendar_state(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/bale/reporting/connect')->assertRedirect();
        $state = session(ManagerReportingController::STATE);
        session()->put('google_calendar_state', 'unrelated-calendar-state');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response($this->tokenReply()),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'manager@example.test', 'email_verified' => true]),
        ]);
        $this->get('/admin/calendar/google/callback?state='.urlencode($state).'&code=fixture-code')
            ->assertRedirect('/admin/bale/reporting')->assertSessionHas('ok');
        $raw = Setting::query()->whereKey(ManagerGoogleReporting::TOKEN_KEY)->value('value');
        $this->assertStringNotContainsString('fixture-reporting-refresh', $raw);
        $this->assertStringContainsString('fixture-reporting-refresh', Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        $this->assertSame(0, GoogleCalendarToken::count());
        $this->assertSame('unrelated-calendar-state', session('google_calendar_state'));
        $this->assertNull(session(ManagerReportingController::STATE));
        Http::assertSentCount(2);
    }

    public function test_forged_or_expired_reporting_state_never_exchanges_a_code(): void
    {
        $this->actingAs($this->admin())->get('/admin/bale/reporting/connect');
        $state = session(ManagerReportingController::STATE);
        $this->get('/admin/calendar/google/callback?state=forged&code=fixture')->assertRedirect('/admin/calendar');
        $this->travel(11)->minutes();
        $this->get('/admin/calendar/google/callback?state='.urlencode($state).'&code=fixture')
            ->assertRedirect('/admin/bale/reporting')->assertSessionHas('err');
        $this->assertNull(Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        Http::assertNothingSent();
    }

    public function test_wider_write_scope_is_not_stored(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($this->tokenReply([
            'scope' => ManagerGoogleReporting::SCOPES.' https://www.googleapis.com/auth/calendar.events']))]);
        $result = app(ManagerGoogleReporting::class)->exchangeCode('fixture', 'https://example.test/callback', 'verifier');
        $this->assertFalse($result['ok']);
        $this->assertSame('scopes_unverified', $result['reason']);
        $this->assertNull(Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        Http::assertSentCount(1);
    }

    public function test_missing_persistent_grant_is_not_reported_as_a_connected_account(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($this->tokenReply(['refresh_token' => '']))]);
        $result = app(ManagerGoogleReporting::class)->exchangeCode('fixture', 'https://example.test/callback', 'verifier');
        $this->assertFalse($result['ok']);
        $this->assertSame('persistent_grant_missing', $result['reason']);
        $this->assertNull(Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        Http::assertSentCount(1);
    }

    public function test_traffic_reports_period_unique_users_and_google_clicks_separately(): void
    {
        $google = $this->grant();
        $this->reports();
        $snapshot = (new ManagerTrafficSnapshot($google))->refresh();
        $this->assertSame(12, $snapshot['ga4']['periods']['yesterday']['total_users']);
        $this->assertSame(77, $snapshot['ga4']['periods']['previous_month']['total_users']);
        $this->assertSame(99, $snapshot['ga4']['periods']['previous_month']['sessions']);
        $this->assertSame('2026-09-01', $snapshot['ga4']['periods']['previous_month']['start_date']);
        $this->assertSame('2026-09-30', $snapshot['ga4']['periods']['previous_month']['end_date']);
        $this->assertSame('Asia/Tehran', $snapshot['ga4']['periods']['yesterday']['timezone']);
        $this->assertSame(5, $snapshot['gsc']['periods']['yesterday']['clicks']);
        $this->assertSame('America/Los_Angeles', $snapshot['gsc']['periods']['yesterday']['timezone']);
        $this->assertTrue($snapshot['gsc']['periods']['yesterday']['preliminary']);
        $this->assertSame('known', $snapshot['status']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'POST');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), ':runReport')
            && $request['dateRanges'][0]['startDate'] === '2026-09-01' && $request['dimensions'] === []);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sc-domain%3Aservernet.cloud/')
            && $request['dataState'] === 'final' && $request['dimensions'] === []
            && isset($request['startDate'], $request['endDate']) && ! isset($request['start_date']));
        $this->assertStringNotContainsString('fixture-reporting-access', json_encode($snapshot));
    }

    public function test_search_console_lag_is_unknown_instead_of_a_zero_visitor_claim(): void
    {
        $google = $this->grant();
        $this->reports(gscYesterdayPending: true);
        $snapshot = (new ManagerTrafficSnapshot($google))->refresh();
        $this->assertSame('partial', $snapshot['status']);
        $this->assertSame('known', $snapshot['ga4']['status']);
        $this->assertSame('unknown', $snapshot['gsc']['periods']['yesterday']['status']);
        $this->assertNull($snapshot['gsc']['periods']['yesterday']['clicks']);
        $this->assertNull($snapshot['gsc']['periods']['yesterday']['impressions']);
        $this->assertSame(101, $snapshot['gsc']['periods']['previous_month']['clicks']);
    }

    public function test_preview_reads_do_not_refresh_expired_tokens_or_make_google_requests(): void
    {
        $google = $this->grant(60);
        $this->travel(2)->minutes();
        $before = Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY);
        $snapshot = (new ManagerTrafficSnapshot($google))->cachedSnapshot();
        $this->assertSame('no_observation', $snapshot['reason']);
        $this->assertSame($before, Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        Http::assertNothingSent();
    }

    public function test_explicit_delivery_refresh_rotates_encrypted_reporting_tokens_only(): void
    {
        $google = $this->grant(60);
        $this->travel(2)->minutes();
        $this->reports();
        $snapshot = (new ManagerTrafficSnapshot($google))->refresh();
        $this->assertSame('known', $snapshot['status']);
        $this->assertStringContainsString('fixture-rotated-refresh', Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        $this->assertSame('known', (new ManagerTrafficSnapshot($google))->cachedSnapshot()['status']);
        $this->assertSame(0, GoogleCalendarToken::count());
        Http::assertSentCount(5);
    }

    public function test_expired_observations_and_reconfigured_sources_do_not_reuse_old_totals(): void
    {
        $google = $this->grant();
        $this->reports();
        $reader = new ManagerTrafficSnapshot($google);
        $reader->refresh();
        $this->travel(7)->hours();
        $old = $reader->cachedSnapshot();
        $this->assertSame('unknown', $old['status']);
        $this->assertNull($old['ga4']['periods']['yesterday']['total_users']);
        Setting::put(ManagerGoogleReporting::PROPERTY_KEY, '999999');
        $this->assertSame('no_observation', $reader->cachedSnapshot()['reason']);
        Http::assertSentCount(4);
    }

    public function test_policy_and_admin_role_gate_the_ui_and_configuration_rejects_arbitrary_origins(): void
    {
        $this->actingAs($this->admin('support'))->get('/admin/bale/reporting')->assertForbidden();
        $this->actingAs($this->admin())->post('/admin/bale/reporting/configure', ['property_id' => 'https://evil.example',
            'site' => 'https://manager@evil.example:443/?token=secret'])->assertSessionHasErrors(['property_id', 'site']);
        Setting::put(BaleManagerPolicy::SETTING, '0');
        $this->get('/admin/bale/reporting')->assertForbidden();
        $this->assertSame('disabled', app(ManagerTrafficSnapshot::class)->refresh()['reason']);
        Http::assertNothingSent();
    }

    public function test_disconnecting_reporting_keeps_other_google_connection_settings(): void
    {
        $this->grant();
        $this->actingAs($this->admin())->post('/admin/bale/reporting/disconnect')->assertRedirect('/admin/bale/reporting');
        $this->assertNull(Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        $this->assertSame('fixture-google-client', Setting::get('google_client_id'));
        $this->assertSame('fixture-google-secret', Setting::getSecret('google_client_secret'));
        Http::assertNothingSent();
    }

    public function test_a_pending_token_refresh_cannot_restore_a_disconnected_grant(): void
    {
        $google = $this->grant(60);
        $this->travel(2)->minutes();
        Http::fake(['https://oauth2.googleapis.com/token' => function () {
            Setting::putSecret(ManagerGoogleReporting::TOKEN_KEY, null);

            return Http::response($this->tokenReply(['access_token' => 'late-refresh-result']));
        }]);
        $result = $google->accessToken();
        $this->assertFalse($result['ok']);
        $this->assertSame('connection_changed', $result['reason']);
        $this->assertNull(Setting::getSecret(ManagerGoogleReporting::TOKEN_KEY));
        Http::assertSentCount(1);
    }

    public function test_sources_are_pinned_during_collection_and_changed_source_discards_old_totals(): void
    {
        $google = $this->grant();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), ':runReport')) {
                Setting::put(ManagerGoogleReporting::PROPERTY_KEY, '999999');

                return Http::response(['metricHeaders' => [['name' => 'totalUsers', 'type' => 'TYPE_INTEGER'], ['name' => 'sessions', 'type' => 'TYPE_INTEGER']],
                    'rows' => [['metricValues' => [['value' => '50'], ['value' => '60']]]], 'metadata' => ['timeZone' => 'Asia/Tehran']]);
            }

            return Http::response(['rows' => []]);
        });
        $reader = new ManagerTrafficSnapshot($google);
        $result = $reader->refresh();
        $this->assertSame('connection_changed', $result['reason']);
        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['ga4']['periods']['previous_month']['total_users']);
        $this->assertSame('no_observation', $reader->cachedSnapshot()['reason']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'properties/999999'));
        Http::assertSentCount(4);
    }

    public function test_reporting_view_renders_localized_labels_in_three_languages_and_escapes_values_without_google_calls(): void
    {
        $this->actingAs($this->admin());
        session()->start();
        session()->put('_token', 'fixture-reporting-csrf-token');
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
        $google = app(ManagerGoogleReporting::class);
        $snapshot = app(ManagerTrafficSnapshot::class)->cachedSnapshot();
        $configuration = $google->configuration();
        $configuration['site'] = '\"><script>reporting-source-marker</script>';
        $connection = ['configured' => true, 'connected' => true,
            'email' => '<script>reporting-account-marker</script>'];
        $original = app()->getLocale();
        try {
            foreach (['fa', 'en', 'tr'] as $locale) {
                app()->setLocale($locale);
                $html = view('admin.bale.reporting', compact('connection', 'configuration', 'snapshot'))->render();
                foreach (['title', 'sources', 'unique_note', 'lag_note', 'save', 'disconnect'] as $key) {
                    $this->assertStringContainsString(e(trans('bale-reporting.'.$key, [], $locale)), $html);
                }
                $this->assertStringContainsString(e($connection['email']), $html);
                $this->assertStringContainsString(e($configuration['site']), $html);
                $this->assertStringNotContainsString('<script>reporting-account-marker</script>', $html);
                $this->assertStringNotContainsString('<script>reporting-source-marker</script>', $html);
                $this->assertStringNotContainsString('bale-reporting.', $html);
            }
        } finally { app()->setLocale($original); }
        Http::assertNothingSent();
    }
}
