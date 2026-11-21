<?php

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Services\Cloud\ProxmoxClient;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_bootstrap_cannot_create_a_privileged_account(): void
    {
        config(['auth.admin_bootstrap_token' => null]);
        $this->get('/admin/setup')->assertNotFound();
        $this->post('/admin/setup', ['name' => 'Test', 'email' => 'first@example.test',
            'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_valid_bootstrap_requires_normal_login_and_cannot_run_twice(): void
    {
        config(['auth.admin_bootstrap_token' => str_repeat('a', 40)]);
        $data = ['name' => 'Test', 'email' => 'first@example.test', 'bootstrap_token' => str_repeat('a', 40),
            'password' => 'test-password', 'password_confirmation' => 'test-password'];
        $this->post('/admin/setup', $data)->assertRedirect('/admin/login');
        $this->assertGuest('web');
        $this->post('/admin/setup', $data)->assertRedirect('/admin/login');
        $this->assertDatabaseCount('users', 1);
    }

    private function challenge(int $attempts = 0): OtpChallenge
    {
        return OtpChallenge::create([
            'channel' => 'sms', 'destination' => '09121230002', 'purpose' => 'login',
            'code_hash' => hash_hmac('sha256', '235901', (string) config('app.key')),
            'attempts' => $attempts, 'resends' => 0, 'expires_at' => now()->addMinute(),
        ]);
    }

    public function test_final_attempt_can_be_consumed_only_once(): void
    {
        $row = $this->challenge(9);
        $otp = app(OtpService::class);
        $this->assertTrue($otp->verify('sms', $row->destination, 'login', '235901')->ok);
        $this->assertFalse($otp->verify('sms', $row->destination, 'login', '235901')->ok);
        $this->assertSame(10, (int) $row->fresh()->attempts);
    }

    public function test_a_stale_read_cannot_accept_a_concurrently_consumed_code(): void
    {
        $row = $this->challenge();
        $changed = false;
        Event::listen('eloquent.retrieved: '.OtpChallenge::class, function ($challenge) use (&$changed, $row) {
            if (! $changed && $challenge->id === $row->id) {
                $changed = true;
                DB::table('otp_challenges')->where('id', $row->id)->update(['verified_at' => now()]);
            }
        });
        $this->assertFalse(app(OtpService::class)->verify('sms', $row->destination, 'login', '235901')->ok);
    }

    public function test_proxmox_rejects_insecure_origins_without_contacting_them(): void
    {
        Setting::putSecret('proxmox_token_secret', 'test-only');
        Setting::put('proxmox_api_url', 'http://pve.example.test:8006/api2/json');
        Http::fake();
        $result = app(ProxmoxClient::class)->testConnection();
        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_proxmox_keeps_certificate_validation_and_disables_redirects(): void
    {
        Setting::putSecret('proxmox_token_secret', 'test-only');
        Setting::put('proxmox_api_url', 'https://pve.example.test:8006/api2/json');
        $observed = [];
        Http::fake(function ($request, $options) use (&$observed) {
            $observed[] = $options;

            return Http::response(['data' => []], 200);
        });
        $this->assertTrue(app(ProxmoxClient::class)->testConnection()['ok']);
        $this->assertNotEmpty($observed);
        $this->assertTrue($observed[0]['verify']);
        $this->assertFalse($observed[0]['allow_redirects']);
    }

    public static function connectionAddresses(): array
    {
        return ['ipv4' => ['192.0.2.42', '192.0.2.42'], 'ipv6' => ['2001:db8::42', '[2001:db8::42]']];
    }

    #[DataProvider('connectionAddresses')]
    public function test_proxmox_numeric_node_origin_uses_the_verified_certificate_hostname(string $ip, string $resolved): void
    {
        Setting::putSecret('proxmox_token_secret', 'test-only');
        Setting::put('proxmox_api_url', 'https://'.$resolved.':8006/api2/json');
        Setting::put('proxmox_tls_server_name', 'pve.example.test');
        $observed = [];
        Http::fake(function ($request, $options) use (&$observed) {
            $observed[] = ['host' => parse_url($request->url(), PHP_URL_HOST), 'options' => $options];

            return Http::response(['data' => []], 200);
        });
        $this->assertTrue(app(ProxmoxClient::class)->testConnection()['ok']);
        $this->assertSame('pve.example.test', $observed[0]['host']);
        $this->assertTrue($observed[0]['options']['verify']);
        $this->assertFalse($observed[0]['options']['allow_redirects']);
        $this->assertSame('', $observed[0]['options']['proxy']);
        $this->assertSame(['pve.example.test:8006:'.$resolved], $observed[0]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_proxmox_rejects_an_invalid_certificate_hostname_without_contacting_any_node(): void
    {
        Setting::putSecret('proxmox_token_secret', 'test-only');
        Setting::put('proxmox_api_url', 'https://192.0.2.42:8006/api2/json');
        Setting::put('proxmox_tls_server_name', 'pve.example.test:8006');
        Http::fake();
        $this->assertFalse(app(ProxmoxClient::class)->testConnection()['ok']);
        Http::assertNothingSent();
    }

    public function test_proxmox_named_origin_is_not_overridden_by_an_old_certificate_alias(): void
    {
        Setting::putSecret('proxmox_token_secret', 'test-only');
        Setting::put('proxmox_api_url', 'https://new.example.test:8006/api2/json');
        Setting::put('proxmox_tls_server_name', 'old.example.test');
        $observed = [];
        Http::fake(function ($request, $options) use (&$observed) {
            $observed[] = ['host' => parse_url($request->url(), PHP_URL_HOST), 'options' => $options];

            return Http::response(['data' => []], 200);
        });
        $this->assertTrue(app(ProxmoxClient::class)->testConnection()['ok']);
        $this->assertSame('new.example.test', $observed[0]['host']);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $observed[0]['options']['curl'] ?? []);
    }
}
