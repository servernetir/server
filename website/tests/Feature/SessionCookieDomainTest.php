<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionCookieDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_overrides_parent_domain_configuration(): void
    {
        config(['session.domain' => '.servernet.cloud', 'session.secure' => false]);
        $response = $this->get('https://console.servernet.cloud/login')->assertOk();
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === '__Host-servernet_session');
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_local_cookie_settings_remain_usable(): void
    {
        config(['session.cookie' => 'local_session', 'session.domain' => null, 'session.secure' => false]);
        $response = $this->get('/login')->assertOk();
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'local_session');
        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
    }

    public function test_remembered_login_uses_a_host_bound_cookie(): void
    {
        $customer = Customer::create(['email' => 'cookie@example.test', 'phone' => '09121230001',
            'password' => bcrypt('test-password'), 'status' => 'active']);
        Route::middleware('web')->get('/account/cookie-check', function () use ($customer) {
            Auth::guard('customer')->login($customer, true);

            return response('ok');
        });
        $response = $this->get('https://console.servernet.cloud/account/cookie-check')->assertOk();
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), '__Host-remember_customer_'));
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_parent_domain_session_is_retired(): void
    {
        $response = $this->withCookie('snet_session', 'obsolete-test-cookie')
            ->get('https://console.servernet.cloud/login')->assertOk();
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'snet_session');
        $this->assertNotNull($cookie);
        $this->assertSame('.servernet.cloud', $cookie->getDomain());
        $this->assertLessThan(time(), $cookie->getExpiresTime());
        $this->assertGuest('customer');
    }
}
