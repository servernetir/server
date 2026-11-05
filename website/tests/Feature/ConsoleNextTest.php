<?php

namespace Tests\Feature;

use App\Http\Middleware\ConsoleNext as CandidateMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\CloudInstance;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\IdentityVerification;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Notify\Notifier;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Support\ConsoleNext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Support\NavasanRates;

/** The opt-in interface must use the same live customer contracts as /account. */
class ConsoleNextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['console-next.enabled' => true, 'edge.dns.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
    }

    public static function locales(): array
    {
        return [['fa', '', 'rtl'], ['en', '/en', 'ltr'], ['tr', '/tr', 'ltr']];
    }

    public function test_every_current_customer_route_keeps_its_controller_guards_throttle_and_binding_contract(): void
    {
        $original = $candidate = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (preg_match('~^(?:(?:en|tr)/)?account/next(?:/|$)~', $uri)) {
                $key = implode('|', $route->methods()).' '.preg_replace('~(^|/)account/next(?=/|$)~', '$1account', $uri);
                $candidate[$key] = $route;
            } elseif (preg_match('~^(?:(?:en|tr)/)?account(?:/|$)~', $uri)) {
                $original[implode('|', $route->methods()).' '.$uri] = $route;
            }
        }
        $this->assertNotEmpty($original);
        $this->assertCount(count($original), $candidate);
        foreach ($original as $key => $route) {
            $this->assertArrayHasKey($key, $candidate, 'Missing live candidate action: '.$key);
            $next = $candidate[$key];
            $this->assertSame($route->getActionName(), $next->getActionName(), $key);
            $this->assertSame($route->wheres, $next->wheres, $key.' parameter constraints');
            $this->assertSame($route->bindingFields(), $next->bindingFields(), $key.' binding fields');
            $this->assertSame($route->getName(), str_replace('account.next.', 'account.', (string) $next->getName()), $key.' name');
            $middleware = array_values(array_filter($next->gatherMiddleware(), fn ($value) => $value !== CandidateMiddleware::class));
            $this->assertSame($route->gatherMiddleware(), $middleware, $key.' guard/throttle order');
            $this->assertContains(CandidateMiddleware::class, $next->gatherMiddleware());
        }
        foreach (['account.next.identity', 'account.next.dns', 'account.next.ai', 'account.next.madar', 'account.next.wallet-refunds'] as $name) {
            $this->assertTrue(Route::has($name), $name.' is a current live module');
        }
    }

    public function test_disabled_candidate_does_not_replace_the_original_panel(): void
    {
        config(['console-next.enabled' => false]);
        $customer = $this->customer();
        $this->actingAs($customer, 'customer')->get('/account/next')->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/account')->assertOk()->assertDontSee('class="console-next', false)
            ->assertSee('class="pnl-wrap"', false);
    }

    #[DataProvider('locales')]
    public function test_guest_intended_destination_stays_on_the_separate_interface(string $locale, string $prefix, string $direction): void
    {
        $this->get($prefix.'/account/next/profile')->assertRedirect($prefix.'/login');
        $this->assertSame(url($prefix.'/account/next/profile'), session('url.intended'));
        $this->get($prefix.'/login')->assertOk();
        $this->actingAs($this->customer(), 'customer')->get(session('url.intended'))->assertOk()
            ->assertSee('console-next.css', false);
        $this->get($prefix.'/account/profile')->assertOk()->assertDontSee('console-next.css', false);
    }

    #[DataProvider('locales')]
    public function test_current_customer_modules_render_real_fixtures_and_keep_forms_on_the_candidate(string $locale, string $prefix, string $direction): void
    {
        $fixture = $this->fixtures();
        $this->actingAs($fixture['customer'], 'customer');
        $pages = [
            'home' => '', 'services' => '/services', 'hosting' => '/hosting', 'servers' => '/servers',
            'other' => '/other', 'domains' => '/domains', 'dns' => '/dns', 'invoices' => '/invoices',
            'bank' => '/bank', 'profile' => '/profile', 'security' => '/security', 'tickets' => '/tickets',
            'topup' => '/topup', 'ai' => '/ai', 'identity' => '/identity-api',
            'invoice' => '/invoices/'.$fixture['invoice']->id, 'ticket' => '/tickets/'.$fixture['ticket']->id,
            'server' => '/cloud/'.$fixture['cloud']->id, 'dns-zone' => '/dns/'.$fixture['zone']->id,
            'ticket-new' => '/tickets/new', 'cloud-store' => '/cloud-store',
        ];
        $manifest = [];
        foreach ($pages as $page => $suffix) {
            $path = $prefix.'/account/next'.$suffix;
            $response = $this->get($path);
            $this->assertSame(200, $response->status(), $path.' redirects to '.(string) $response->headers->get('Location'));
            $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $path);
            $html = $response->getContent();
            $xpath = $this->document($html);
            $this->assertSame($locale, $xpath->query('/html')->item(0)->getAttribute('lang'), $path);
            $this->assertSame($direction, $xpath->query('/html')->item(0)->getAttribute('dir'), $path);
            $this->assertSame(1, $xpath->query('//main')->length, $path.' one main landmark');
            $this->assertGreaterThan(0, $xpath->query('//main//h1')->length, $path.' real page heading');
            $this->assertStringContainsString('console-next.css', $html);
            $this->assertStringNotContainsString('sn-preview-data', $html, $path.' must not use fictional review boot data');
            $this->assertStringNotContainsString('preview-account.js', $html);
            foreach ($xpath->query('//form[@action]') as $form) {
                $action = (string) parse_url(html_entity_decode($form->getAttribute('action')), PHP_URL_PATH);
                if (preg_match('~^/(?:en/|tr/)?account(?:/|$)~', $action)) {
                    $this->assertStringStartsWith($prefix.'/account/next', $action, $path.' form action');
                }
                if (strtolower($form->getAttribute('method')) === 'post') {
                    $this->assertGreaterThan(0, $xpath->query('.//input[@name="_token"]', $form)->length, $path.' retains CSRF fields');
                }
            }
            if ($page === 'server') {
                $this->assertContains($prefix.'/account/next/invoices', $this->mainInvoiceListPaths($html), $path.' uses the localized candidate invoice link');
                $legacy = $this->get($prefix.'/account/cloud/'.$fixture['cloud']->id)->assertOk();
                $this->assertContains('/account/invoices', $this->mainInvoiceListPaths($legacy->getContent()), 'The existing server page keeps its original invoice anchor');
            }
            $this->capture($locale.'-'.$page.'.html', $html);
            $manifest[] = ['locale' => $locale, 'page' => $page, 'path' => $path, 'file' => $locale.'-'.$page.'.html'];
        }
        $this->capture($locale.'-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->get($prefix.'/account/next/store')->assertRedirect($prefix.'/account/next/cloud-store');
        $this->assertStringContainsString('atlas.example', $this->get($prefix.'/account/next/hosting')->getContent());
        $this->assertStringContainsString('Fixture monthly maintenance', $this->get($prefix.'/account/next/other')->getContent());
        Http::assertNothingSent();
    }

    public function test_safe_ticket_submission_uses_existing_validation_storage_and_candidate_redirect(): void
    {
        $customer = $this->customer();
        $this->mock(Notifier::class)->shouldReceive('fire')->once()->withArgs(fn ($event, $owner) => $event === 'ticket_new' && $owner->is($customer));
        $this->actingAs($customer, 'customer')->from('/account/next/tickets/new')
            ->post('/account/next/tickets', ['subject' => '', 'department' => 'technical', 'priority' => 'normal', 'body' => ''])
            ->assertSessionHasErrors(['subject', 'body']);
        $this->assertSame(0, Ticket::count());
        $response = $this->post('/account/next/tickets', [
            'subject' => 'Isolated console review ticket', 'department' => 'technical',
            'priority' => 'normal', 'body' => 'Fictional test message, not a live customer request.',
        ]);
        $ticket = Ticket::sole();
        $response->assertRedirect('/account/next/tickets/'.$ticket->id);
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('Fictional test message, not a live customer request.', $ticket->messages()->sole()->body);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Isolated console review ticket');
        Http::assertNothingSent();
    }

    public function test_candidate_posts_keep_real_csrf_protection(): void
    {
        $this->actingAs($this->customer(), 'customer');
        $this->app->instance('env', 'local');
        try {
            $this->post('/account/next/tickets', ['subject' => 'Forged request'])->assertStatus(419);
            $this->assertSame(0, Ticket::count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    #[DataProvider('locales')]
    public function test_unpaid_cloud_order_keeps_its_notification_link_canonical_while_the_browser_stays_on_the_candidate(string $locale, string $prefix, string $direction): void
    {
        Setting::put('pricing_rate_override', '100000');
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]);
        app(CloudPricingSnapshot::class)->publish([100000, 100000, 100000]);
        $fixture = $this->fixtures();
        $plan = $fixture['cloud']->cloudPlan;
        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'min_ram_mb' => 0, 'is_active' => true]);
        $notifications = [];
        $this->mock(Notifier::class)->shouldReceive('fire')->twice()->andReturnUsing(
            function (string $event, Customer $customer, array $variables) use (&$notifications): void {
                $notifications[$event] = ['customer_id' => $customer->id, 'variables' => $variables];
            }
        );
        $instanceCount = CloudInstance::count();
        $response = $this->actingAs($fixture['customer'], 'customer')->post($prefix.'/account/next/cloud-store', [
            'location' => $plan->location_code, 'plan' => $plan->slug, 'image' => 'ubuntu-24.04',
            'cycle' => 'monthly', 'label' => 'Isolated console review order',
        ])->assertSessionHasNoErrors();
        $ordered = Service::where('customer_id', $fixture['customer']->id)->where('status', 'pending')->sole();
        $invoice = Invoice::where('service_id', $ordered->id)->sole();
        $response->assertRedirect($prefix.'/account/next/invoices/'.$invoice->id);
        $this->assertSame('pending', $ordered->status);
        $this->assertNull($ordered->provision_status, 'An unpaid UI test must not enqueue provisioning');
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(0, (int) $invoice->paid);
        $this->assertGreaterThan(0, (int) $invoice->total);
        $this->assertArrayHasKey('service_ordered', $notifications);
        $this->assertArrayHasKey('invoice', $notifications);
        $this->assertSame($fixture['customer']->id, $notifications['invoice']['customer_id']);
        $this->assertSame('https://console.servernet.cloud'.$prefix.'/account/invoices/'.$invoice->id, $notifications['invoice']['variables']['link']);
        $this->assertStringNotContainsString('/account/next', $notifications['invoice']['variables']['link']);
        $this->assertSame($instanceCount, CloudInstance::count(), 'No provider instance is purchased for an unpaid test order');
        config(['console-next.enabled' => false]);
        $this->get($prefix.'/account/invoices/'.$invoice->id)->assertOk()->assertDontSee('console-next.css', false);
        Http::assertNothingSent();
    }

    public function test_another_customers_invoice_service_ticket_and_dns_zone_remain_private(): void
    {
        $fixture = $this->fixtures();
        $this->actingAs($this->customer(), 'customer');
        foreach (['/invoices/'.$fixture['invoice']->id, '/cloud/'.$fixture['cloud']->id,
            '/tickets/'.$fixture['ticket']->id, '/dns/'.$fixture['zone']->id] as $path) {
            $this->get('/account/next'.$path)->assertNotFound();
        }
        $this->post('/account/next/tickets/'.$fixture['ticket']->id.'/close')->assertNotFound();
        $this->assertSame('answered', $fixture['ticket']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_old_controller_redirects_are_contained_without_a_persistent_interface_override(): void
    {
        $this->actingAs($this->customer(), 'customer')->get('/en/account/next/verify')
            ->assertRedirect('/en/account/next/profile');
        $this->get('/en/account/verify')->assertRedirect('/en/account/profile');
        $this->get('/en/account/profile')->assertOk()->assertDontSee('console-next.css', false);
    }

    public function test_redirect_mapping_preserves_queries_and_ignores_gateways_auth_admin_and_other_origins(): void
    {
        $request = Request::create('https://console.servernet.cloud/en/account/next/profile');
        $request->attributes->set(ConsoleNext::ATTRIBUTE, true);
        foreach ([
            '/account/invoices/7?paid=1#receipt' => '/account/next/invoices/7?paid=1#receipt',
            'https://console.servernet.cloud/tr/account/profile?a=b#company' => 'https://console.servernet.cloud/tr/account/next/profile?a=b#company',
            '/en/account/next/profile' => '/en/account/next/profile',
            'https://gateway.example/account/pay' => 'https://gateway.example/account/pay',
            '//console.servernet.cloud/account/profile' => '//console.servernet.cloud/account/profile',
            'https://console.servernet.cloud:444/account/profile' => 'https://console.servernet.cloud:444/account/profile',
            'http://console.servernet.cloud/account/profile' => 'http://console.servernet.cloud/account/profile',
            'https://user:pass@console.servernet.cloud/account/profile' => 'https://user:pass@console.servernet.cloud/account/profile',
            '/payment/callback?authority=sample' => '/payment/callback?authority=sample',
            '/admin/customers/7' => '/admin/customers/7', '/login' => '/login', '/account-next' => '/account-next',
        ] as $url => $expected) {
            $this->assertSame($expected, ConsoleNext::candidateUrl($url, $request));
        }
        $this->assertSame('en.account.next.profile', ConsoleNext::routeName('en.account.profile', $request));
        $this->assertSame('en.login', ConsoleNext::routeName('en.login', $request));
        $this->assertSame('account.does-not-exist', ConsoleNext::routeName('account.does-not-exist', $request));
        $this->assertSame('https://console.servernet.cloud/en/account/profile', ConsoleNext::legacyUrl($request));
        $request->attributes->remove(ConsoleNext::ATTRIBUTE);
        $this->assertSame('/account/profile', ConsoleNext::candidateUrl('/account/profile', $request));
    }

    public function test_warmed_page_cache_never_serves_a_candidate_page_to_a_guest(): void
    {
        config(['pagecache.enabled' => true]);
        $path = 'account/next/profile';
        $key = 'page:g0:'.sha1('localhost||'.$path);
        Cache::put('page:gen', 0);
        Cache::put($key, ['html' => '<h1>Private cached customer content</h1>', 'token' => 'cached',
            'fresh_until' => time() + 60, 'expires_at' => time() + 600], 600);
        $response = $this->get('/'.$path)->assertRedirect('/login');
        $this->assertStringNotContainsString('Private cached customer content', $response->getContent());
        $this->assertNotSame('HIT', $response->headers->get('X-Cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_real_console_ticket_remains_owned_one_use_and_private_in_the_candidate(): void
    {
        $fixture = $this->fixtures();
        $service = $fixture['cloud'];
        Cache::put('cloud-console:'.$service->id.':fixture-ticket', ['url' => 'wss://fixture.example/vnc', 'password' => 'fixture-only'], 90);
        $this->actingAs($fixture['customer'], 'customer');
        $view = $this->get('/account/next/cloud/'.$service->id.'/console/view?t=fixture-ticket')->assertOk();
        $this->assertStringContainsString("connect-src 'self' wss:", $view->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('wss://fixture.example/vnc', $view->getContent());
        $this->assertStringContainsString('/account/next/cloud/'.$service->id.'/console/ticket', str_replace('\\/', '/', $view->getContent()));
        $this->getJson('/account/next/cloud/'.$service->id.'/console/ticket?t=fixture-ticket')->assertOk()
            ->assertJson(['ok' => true, 'url' => 'wss://fixture.example/vnc']);
        $this->getJson('/account/next/cloud/'.$service->id.'/console/ticket?t=fixture-ticket')->assertStatus(410);
        $normal = $this->get('/account/next/profile')->assertOk();
        $this->assertStringNotContainsString("connect-src 'self' wss:", $normal->headers->get('Content-Security-Policy'));
        $this->assertTrue($view->headers->hasCacheControlDirective('no-store'));
        Http::assertNothingSent();
    }

    public function test_private_activation_cli_can_enable_disable_and_fail_closed_without_changing_environment_or_data(): void
    {
        $storage = $this->app->storagePath();
        $temporary = sys_get_temp_dir().'/servernet-console-next-'.bin2hex(random_bytes(8));
        File::makeDirectory($temporary.'/app', 0700, true);
        $this->app->useStoragePath($temporary);
        try {
            config(['console-next.enabled' => false]);
            $this->assertFalse(ConsoleNext::enabled());
            $this->artisan('console-next:enable')->assertExitCode(0);
            $this->assertTrue(ConsoleNext::enabled());
            $this->assertSame('enabled', trim(file_get_contents(ConsoleNext::activationPath())));
            $this->artisan('console-next:disable')->assertExitCode(0);
            config(['console-next.enabled' => true]);
            $this->assertFalse(ConsoleNext::enabled(), 'Private disabled marker overrides an enabled environment default');
            file_put_contents(ConsoleNext::activationPath(), 'invalid activation state');
            $this->assertFalse(ConsoleNext::enabled());
            $this->assertSame(0, Customer::count());
        } finally {
            $this->app->useStoragePath($storage);
            File::deleteDirectory($temporary);
        }
    }

    public function test_owned_provider_console_form_redirect_policy_matches_the_current_panel_without_broadening_other_pages(): void
    {
        $fixture = $this->fixtures();
        $service = $fixture['cloud'];
        $service->cloudInstance->update(['provider' => 'arvan']);
        $service->unsetRelation('cloudInstance');
        $this->actingAs($fixture['customer'], 'customer');
        foreach (['', '/en', '/tr'] as $prefix) {
            $legacy = $this->cloudDetailPolicy($prefix.'/account/cloud/'.$service->id, $prefix, false, $service);
            $candidate = $this->cloudDetailPolicy($prefix.'/account/next/cloud/'.$service->id, $prefix, true, $service);
            $this->assertStringContainsString('https://console.arvaniaas.ir', $legacy);
            $this->assertSame($legacy, $candidate, 'The same owned service keeps its current browser POST-redirect policy');
        }
        $this->actingAs($this->customer(), 'customer');
        $this->assertStringNotContainsString('https://console.arvaniaas.ir', $this->cloudDetailPolicy('/account/next/cloud/'.$service->id, '', true, $service));
        Http::assertNothingSent();
    }

    public function test_activation_cannot_change_state_while_a_guarded_release_holds_the_canonical_lock(): void
    {
        $storage = $this->app->storagePath();
        $temporary = sys_get_temp_dir().'/servernet-console-next-lock-'.bin2hex(random_bytes(8));
        File::makeDirectory($temporary.'/app', 0700, true);
        $this->app->useStoragePath($temporary);
        $held = null;
        try {
            ConsoleNext::setEnabled(false);
            $lockIdentity = lstat(ConsoleNext::releaseLockPath());
            $held = fopen(ConsoleNext::releaseLockPath(), 'r+b');
            $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));
            $blocked = false;
            try {
                $this->artisan('console-next:enable')->run();
            } catch (\RuntimeException $exception) {
                $blocked = true;
                $this->assertStringContainsString('release is in progress', $exception->getMessage());
            }
            $this->assertTrue($blocked, 'The command must refuse activation while the release lock is held');
            $this->assertSame("disabled\n", file_get_contents(ConsoleNext::activationPath()));
            $this->assertFalse(ConsoleNext::enabled());
            flock($held, LOCK_UN);
            fclose($held);
            $held = null;
            $this->artisan('console-next:enable')->assertExitCode(0);
            $this->assertTrue(ConsoleNext::enabled());
            clearstatcache(true, ConsoleNext::releaseLockPath());
            $after = lstat(ConsoleNext::releaseLockPath());
            $this->assertSame($lockIdentity['ino'], $after['ino'], 'The canonical release lock is never replaced or unlinked');
            $this->assertSame(0600, $after['mode'] & 0777);
            $this->assertSame(0, Customer::count());
        } finally {
            if (is_resource($held)) {
                flock($held, LOCK_UN);
                fclose($held);
            }
            $this->app->useStoragePath($storage);
            File::deleteDirectory($temporary);
        }
    }

    private function cloudDetailPolicy(string $path, string $prefix, bool $candidate, Service $service): string
    {
        $request = Request::create('http://localhost'.$path);
        $name = ($prefix === '' ? '' : substr($prefix, 1).'.').'account.'.($candidate ? 'next.' : '').'cloud.show';
        $route = clone Route::getRoutes()->getByName($name);
        $route->bind($request);
        $route->setParameter('service', $service);
        $request->setRouteResolver(fn () => $route);
        $response = app(SecurityHeaders::class)->handle($request, fn () => response('<p>Owned service fixture</p>'));

        return (string) $response->headers->get('Content-Security-Policy');
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'review-'.bin2hex(random_bytes(5)).'@example.invalid',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => bcrypt('fixture-only'),
            'status' => 'active', 'locale' => 'fa']);
    }

    private function fixtures(): array
    {
        $customer = $this->customer();
        IdentityVerification::create(['customer_id' => $customer->id, 'status' => 'verified', 'first_name' => 'Alex',
            'last_name' => 'Example', 'father_name' => 'Fixture parent', 'mobile' => $customer->phone,
            'national_id_enc' => Crypt::encryptString('0000000000'), 'national_id_hash' => hash('sha256', 'fixture-'.$customer->id),
            'birth_date' => '1990-04-12', 'verified_at' => now()->subMonth()]);
        CustomerProfile::create(['customer_id' => $customer->id, 'type' => 'company', 'status' => 'draft', 'is_default' => true,
            'email' => $customer->email, 'company_name' => 'Atlas Example Ltd.', 'country' => 'DE']);
        $server = Server::create(['name' => 'Fixture hosting', 'type' => 'whm', 'hostname' => 'hosting.example.invalid',
            'username' => 'fixture', 'api_token' => 'fixture-not-a-credential', 'verify_tls' => true, 'status' => 'active']);
        $base = ['customer_id' => $customer->id, 'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => now()->subMonth(), 'next_due_at' => now()->addMonth()];
        $hosting = Service::create($base + ['name' => 'Atlas WordPress', 'server_id' => $server->id,
            'username' => 'atlas_demo', 'domain' => 'atlas.example']);
        CloudLocation::firstOrCreate(['code' => 'de-frankfurt'], ['country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        $plan = CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'Cloud CV-2', 'slug' => 'cv-2c-4g-40d-de-frankfurt', 'vcpu' => 2,
            'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 500, 'price_eur_cents' => 1450,
            'price_irt' => 1450000, 'is_active' => true, 'in_stock' => true]);
        $cloud = Service::create(array_merge($base, ['name' => 'atlas-edge-01', 'price' => 1450000, 'cloud_plan_id' => $plan->id]));
        CloudInstance::create(['service_id' => $cloud->id, 'provider' => 'hetzner', 'provider_ref' => 'fixture-server',
            'location_code' => 'de-frankfurt', 'image_key' => 'ubuntu-24.04', 'hostname' => 'atlas-edge-01',
            'ipv4' => '203.0.113.41', 'status' => 'running', 'password_seen' => true,
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme']]);
        Service::create($base + ['name' => 'Fixture monthly maintenance', 'description' => 'Fictional service used only by automated UI checks.']);
        Domain::create(['customer_id' => $customer->id, 'domain' => 'atlas.example', 'sld' => 'atlas', 'tld' => 'example',
            'status' => 'active', 'provision_status' => 'done', 'expires_at' => now()->addYear(),
            'auto_renew' => true, 'is_locked' => true, 'period_years' => 1]);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'service_id' => $hosting->id, 'kind' => 'service',
            'number' => 'INV-REVIEW-1001', 'currency_code' => 'IRT', 'subtotal' => 250000, 'tax' => 0, 'total' => 250000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(14)]);
        $invoice->items()->create(['title' => 'Atlas WordPress monthly renewal', 'quantity' => 1,
            'unit_price' => 250000, 'line_total' => 250000, 'tax_amount' => 0]);
        $ticket = Ticket::create(['customer_id' => $customer->id, 'subject' => 'Fixture DNS migration',
            'department' => 'technical', 'priority' => 'normal', 'status' => 'answered', 'last_reply_at' => now(), 'last_reply_role' => 'staff']);
        $ticket->addMessage('customer', $customer->id, 'Alex Example', 'Can you help with the fictional DNS migration?');
        $ticket->addMessage('staff', null, 'ServerNet support', 'This conversation exists only in the isolated browser fixture.');
        $ticket->update(['status' => 'answered']);
        $zone = CdnZone::create(['customer_id' => $customer->id, 'domain' => 'atlas.example', 'mode' => 'ns',
            'status' => 'active', 'ownership' => 'manual', 'default_ttl' => 300, 'publish_state' => 'published',
            'desired_version' => 1, 'applied_version' => 1, 'delegation_state' => 'delegated']);
        CdnRecord::create(['cdn_zone_id' => $zone->id, 'type' => 'A', 'name' => 'atlas.example',
            'content' => '203.0.113.41', 'ttl' => 300, 'proxied' => false, 'disabled' => false]);

        return compact('customer', 'hosting', 'cloud', 'invoice', 'ticket', 'zone');
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function mainInvoiceListPaths(string $html): array
    {
        $paths = [];
        foreach ($this->document($html)->query('//main//a[@href]') as $anchor) {
            $path = (string) parse_url(html_entity_decode($anchor->getAttribute('href')), PHP_URL_PATH);
            if (preg_match('~^/(?:en/|tr/)?account/(?:next/)?invoices$~', $path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function capture(string $name, string $contents): void
    {
        $directory = getenv('CONSOLE_NEXT_CAPTURE_DIR');
        if (! is_string($directory) || $directory === '') {
            return;
        }
        if (! str_starts_with($directory, '/workspace/artifacts/servernet-redesign/')) {
            throw new \InvalidArgumentException('Capture synthetic test fixtures only in the designated review-artifact directory.');
        }
        File::ensureDirectoryExists($directory, 0700);
        file_put_contents(rtrim($directory, '/').'/'.$name, $contents, LOCK_EX);
    }
}
