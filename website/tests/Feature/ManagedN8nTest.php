<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\ManagedN8n;
use App\Services\Cloud\ManagedN8nCatalog;
use App\Services\Cloud\ManagedN8nSsh;
use App\Services\Cloud\VpsTcSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManagedN8nTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Setting::put('n8n_sales_enabled', '1');
        Setting::put('n8n_license_confirmed', '1');
        Setting::put('pricing_rate_override', '100000');
        $this->mock(CloudPricingSnapshot::class, function ($m) {
            $m->shouldReceive('current')->andReturn(true);
            $m->shouldReceive('excludedProviders')->andReturn([]);
        });
        $this->mock(VpsTcSales::class)->shouldReceive('enabled')->andReturn(true);
    }

    private function plan(string $provider = 'hetzner', array $overrides = []): CloudPlan
    {
        $location = ['hetzner' => 'de-falkenstein', 'proxmox' => 'ir-tehran', 'vpstc' => 'tr-istanbul'][$provider];
        CloudLocation::firstOrCreate(['code' => $location], ['country' => strtoupper(substr($location, 0, 2)),
            'city' => 'Test', 'is_active' => true]);
        $plan = CloudPlan::create(array_replace([
            'provider' => $provider, 'provider_ref' => 'plan-'.uniqid(), 'provider_location' => $location,
            'location_code' => $location, 'slug' => 'cv-'.uniqid(), 'public_name' => 'CV-2-4',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'arch' => 'x86',
            'cpu_kind' => 'shared', 'cost_eur_cents' => 300, 'price_eur_cents' => 1000,
            'price_irt' => 1000000, 'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $overrides));
        CloudImage::create(['provider' => $provider,
            'provider_ref' => $provider === 'vpstc' ? $plan->provider_ref.'|ubuntu' : 'ubuntu-'.uniqid(),
            'key' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'min_ram_mb' => 1024, 'min_disk_gb' => 10, 'is_active' => true]);
        return $plan;
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => uniqid('n8n').'@example.test', 'password' => 'secret123',
            'status' => 'active', 'locale' => 'fa']);
    }

    private function order(CloudPlan $p, Customer $c, array $overrides = [])
    {
        return $this->actingAs($c, 'customer')->post(route('account.cloud.store.place', [], false), array_replace([
            'application' => 'n8n', 'plan' => $p->slug, 'location' => $p->location_code,
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'label' => 'my-n8n',
        ], $overrides));
    }

    private function allocated(array $n8n = []): array
    {
        $p = $this->plan(); $c = $this->customer();
        $s = Service::create(['customer_id' => $c->id, 'name' => 'n8n', 'price' => 1200000,
            'tax_percent' => 0, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'cloud_plan_id' => $p->id, 'cloud_image_key' => 'ubuntu-24.04',
            'provision_meta' => ['application' => 'n8n']]);
        $i = CloudInstance::create(['service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '99',
            'location_code' => $p->location_code, 'image_key' => 'ubuntu-24.04', 'status' => 'running',
            'ipv4' => '203.0.113.10', 'meta' => $n8n ? ['n8n' => $n8n] : null]);
        return [$s, $i, $c];
    }

    public function test_all_terms_preserve_twenty_percent_without_general_term_discounts(): void
    {
        foreach (['hetzner', 'vpstc'] as $provider) {
            $p = $this->plan($provider);
            foreach (['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12] as $cycle => $months) {
                $this->assertSame(1200000 * $months, CloudStoreController::priceForCycle($p, $cycle, [], true));
            }
        }
    }

    public function test_iran_uses_a_turkish_tier_meeting_cpu_ram_and_disk_and_tracks_price_updates(): void
    {
        $ir = $this->plan('proxmox', ['price_irt' => 100000]);
        $this->plan('vpstc', ['ram_mb' => 2048, 'price_irt' => 200000]);
        $source = $this->plan('vpstc', ['disk_gb' => 50, 'price_irt' => 1000000]);
        $catalog = app(ManagedN8nCatalog::class);
        $this->assertSame($source->id, $catalog->source($ir)->id);
        $this->assertSame(1200000, $catalog->quote($ir));
        $source->update(['price_irt' => 2000000]);
        $this->assertSame(2400000, $catalog->quote($ir));
        $this->assertGreaterThan(0, $catalog->hourly($ir));
    }

    public function test_owned_iran_n8n_sales_do_not_reopen_ordinary_vps_or_quarantined_plans(): void
    {
        $iran = $this->plan('proxmox'); $this->plan('vpstc');
        Setting::put('cloud_disabled_providers', 'proxmox,hetzner');
        Setting::put('n8n_iran_sales_enabled', '1');
        $this->assertFalse(CloudPlan::sellable()->whereKey($iran->id)->exists());
        $this->assertTrue(app(ManagedN8nCatalog::class)->plans()->contains('id', $iran->id));
        $customer = $this->customer(); $customer->update(['phone' => '09121234567']);
        \App\Models\CustomerProfile::create(['customer_id' => $customer->id, 'is_default' => true,
            'type' => 'individual', 'status' => 'verified', 'email' => $customer->email, 'mobile' => $customer->phone]);
        $customer->identityVerification()->create(['national_id_enc' => 'synthetic',
            'national_id_hash' => hash('sha256', $customer->email), 'birth_date' => '1990-01-01',
            'mobile' => $customer->phone, 'status' => 'verified', 'shahkar_matched' => true]);
        $this->order($iran, $customer)->assertSessionHasNoErrors();
        $this->assertSame(1200000, Service::firstOrFail()->price);
        $this->assertFalse(CloudPlan::sellable()->whereKey($iran->id)->exists());
        $iran->update(['admin_disabled' => true]);
        $this->assertFalse(app(ManagedN8nCatalog::class)->plans()->contains('id', $iran->id));
        $iran->update(['admin_disabled' => false]);
        Setting::put('n8n_iran_sales_enabled', '0');
        $this->assertFalse(app(ManagedN8nCatalog::class)->plans()->contains('id', $iran->id));
    }

    public function test_no_iran_price_without_a_comparable_turkish_plan(): void
    {
        $ir = $this->plan('proxmox');
        $this->assertSame(0, app(ManagedN8nCatalog::class)->quote($ir));
        $this->assertEmpty(app(ManagedN8nCatalog::class)->plans());
    }

    public function test_turkey_hourly_is_rejected_before_service_or_invoice_creation(): void
    {
        $p = $this->plan('vpstc', ['cost_hour_eur_micro' => 1000]);
        $this->order($p, $this->customer(), ['billing_mode' => 'hourly'])->assertSessionHasErrors();
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_paid_period_quote_is_server_computed_and_unpaid_orders_cannot_provision(): void
    {
        $p = $this->plan();
        $this->order($p, $this->customer(), ['price' => 1, 'cycle' => 'semiannual'])->assertSessionHasNoErrors();
        $s = Service::firstOrFail();
        $this->assertSame(7200000, $s->price);
        $this->assertSame('n8n', data_get($s->provision_meta, 'application'));
        $this->assertSame('pending', $s->status);
        $this->assertNull($s->provision_status);
        $this->assertSame('unpaid', Invoice::firstOrFail()->status);
        $this->assertFalse(app(ManagedN8n::class)->advance($s));
        $this->assertSame(0, CloudInstance::count());
    }

    public function test_turkey_yearly_is_available_for_managed_n8n_and_not_ordinary_vps(): void
    {
        $p = $this->plan('vpstc');
        $this->assertSame(0, CloudStoreController::priceForCycle($p, 'yearly'));
        $this->order($p, $this->customer(), ['cycle' => 'yearly'])->assertSessionHasNoErrors();
        $this->assertSame(14400000, Service::firstOrFail()->price);
    }

    public function test_modified_image_or_ssh_key_is_rejected(): void
    {
        $p = $this->plan(); $c = $this->customer();
        $this->order($p, $c, ['image' => 'windows-2022'])->assertSessionHasErrors('application');
        $this->order($p, $c, ['ssh_key_id' => 1])->assertSessionHasErrors('application');
        $this->assertSame(0, Service::count());
    }

    public function test_closed_sales_prevent_direct_form_posts(): void
    {
        $p = $this->plan(); Setting::put('n8n_sales_enabled', '0');
        $this->order($p, $this->customer())->assertSessionHasErrors();
        $this->assertSame(0, Service::count());
    }

    public function test_server_running_does_not_mean_n8n_is_delivered_and_off_preserves_a_ready_delivery(): void
    {
        [$s, $i] = $this->allocated();
        $this->assertFalse($i->readyForNotice());
        $this->assertFalse($i->isDelivered());
        $i->update(['meta' => ['n8n' => ['state' => 'ready']]]);
        $this->assertTrue($i->readyForNotice());
        $i->update(['status' => 'off']);
        $this->assertTrue($i->isDelivered());
    }

    public function test_worker_reuses_one_allocation_and_waits_for_https_health(): void
    {
        [$s, $i] = $this->allocated();
        $dns = $this->mock(\App\Services\Dns\CloudflareDns::class);
        $dns->shouldReceive('pointSubdomain')->twice()->andReturn(['ok' => true]);
        $ssh = $this->mock(ManagedN8nSsh::class);
        $ssh->shouldReceive('step')->twice()->andReturn(['state' => 'installing']);
        $worker = app(ManagedN8n::class);
        $this->assertFalse($worker->advance($s));
        $first = data_get($i->fresh()->meta, 'n8n');
        $this->assertNotEmpty($first['password_enc']);
        $this->assertFalse($worker->advance($s));
        $this->assertSame($first['hostname'], data_get($i->fresh()->meta, 'n8n.hostname'));
        $this->assertSame($first['password_enc'], data_get($i->fresh()->meta, 'n8n.password_enc'));
        $this->assertSame(1, CloudInstance::count());
        $this->assertFalse($i->fresh()->readyForNotice());
    }

    public function test_tls_and_an_allocation_bound_marker_are_required_for_delivery(): void
    {
        $state = ['state' => 'installing', 'hostname' => 'n8n-1-fixture.servernet.cloud', 'nonce' => 'nonce',
            'version' => '2.48.7', 'started_at' => now()->toIso8601String(),
            'password_enc' => Crypt::encryptString('fixture-password')];
        [$s, $i] = $this->allocated($state);
        $this->mock(\App\Services\Dns\CloudflareDns::class)->shouldReceive('pointSubdomain')->twice()->andReturn(['ok' => true]);
        $this->mock(ManagedN8nSsh::class)->shouldReceive('step')->twice()->andReturn(['state' => 'installed']);
        Http::fake(['*/.well-known/servernet-n8n' => Http::sequence()
            ->push(['service_id' => $s->id + 1, 'nonce' => 'nonce'])
            ->push(['service_id' => $s->id, 'nonce' => 'nonce']),
            '*/healthz/readiness' => Http::response(['status' => 'ok'])]);
        $this->assertFalse(app(ManagedN8n::class)->advance($s));
        $this->assertFalse($i->fresh()->readyForNotice());
        $this->assertTrue(app(ManagedN8n::class)->advance($s));
        $this->assertTrue($i->fresh()->readyForNotice());
        $this->assertSame($state['hostname'], $s->fresh()->domain);
    }

    public function test_failed_or_cancelled_installs_are_not_restarted_by_worker(): void
    {
        [$s, $i] = $this->allocated(['state' => 'failed']);
        $this->mock(ManagedN8nSsh::class)->shouldNotReceive('step');
        $this->assertFalse(app(ManagedN8n::class)->advance($s));
        $this->assertSame(['state' => 'failed'], data_get($i->fresh()->meta, 'n8n'));
        $s->update(['status' => 'cancelled']);
        $this->assertFalse(app(ManagedN8n::class)->advance($s));
        Http::assertNothingSent();
    }

    public function test_owed_notices_wait_for_application_delivery_and_use_its_https_link(): void
    {
        [$service, $instance] = $this->allocated(['state' => 'installing']);
        $provisioner = app(\App\Services\Cloud\CloudProvisioner::class);
        $this->assertSame(0, $provisioner->deliverOwedNotices());
        $this->assertNull($instance->fresh()->ready_notified_at);
        Mail::assertNothingSent();
        $instance->update(['meta' => ['n8n' => ['state' => 'ready', 'owner_email' => 'owner@example.test'],
            'public_domain' => 'n8n-fixture.servernet.cloud']]);
        $this->assertSame(1, $provisioner->deliverOwedNotices());
        Mail::assertSent(\App\Mail\ServiceReadyMail::class, fn ($mail) =>
            $mail->domain === 'https://n8n-fixture.servernet.cloud' && $mail->password === null
            && ! $mail->withSshGuide && $mail->passwordInPanel);
        $this->assertSame(0, $provisioner->deliverOwedNotices());
    }

    public function test_cleanup_waits_for_the_installer_before_removing_routes(): void
    {
        [$s, $i] = $this->allocated(['hostname' => 'n8n-fixture.servernet.cloud']);
        $this->mock(\App\Services\Dns\CloudflareDns::class)->shouldNotReceive('removeSubdomain');
        $lock = \Illuminate\Support\Facades\Cache::lock('n8n-install-'.$s->id, 240);
        $this->assertTrue($lock->get());
        try { $this->assertFalse(app(ManagedN8n::class)->removeGateway($i)); }
        finally { $lock->release(); }
    }

    public function test_bootstrap_secret_never_serializes_or_appears_on_normal_get(): void
    {
        [$s, $i, $c] = $this->allocated(['state' => 'ready', 'hostname' => 'n8n-fixture.servernet.cloud',
            'owner_email' => 'owner@example.test', 'password_enc' => Crypt::encryptString('SECRET_n8n_ACCESS')]);
        $this->assertStringNotContainsString('password_enc', $i->toJson());
        $response = $this->actingAs($c, 'customer')->get(route('account.cloud.show', $s, false));
        $response->assertOk()->assertDontSee('SECRET_n8n_ACCESS')->assertSee('n8n-fixture.servernet.cloud');
        $this->post(route('account.cloud.n8n-access', $s, false))->assertRedirect();
        $this->get(route('account.cloud.show', $s, false))->assertOk()->assertSee('SECRET_n8n_ACCESS');
        $this->get(route('account.cloud.show', $s, false))->assertDontSee('SECRET_n8n_ACCESS');
    }

    public function test_other_customer_cannot_reveal_n8n_credentials(): void
    {
        [$s] = $this->allocated(['state' => 'ready', 'password_enc' => Crypt::encryptString('PRIVATE')]);
        $this->actingAs($this->customer(), 'customer')->post(route('account.cloud.n8n-access', $s, false))->assertNotFound();
    }

    public function test_public_pages_render_all_languages_from_live_prices(): void
    {
        $this->plan();
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $language) {
            $this->get($prefix.'/cloud/n8n')->assertOk()->assertSee(trans('n8n.title', [], $language))
                ->assertSee('application=n8n', false)->assertDontSee('N8N-START')->assertDontSee('n8n.title');
        }
    }
    public function test_managed_application_appears_in_services_with_flag_and_access_while_vps_stays_in_servers(): void
    {
        [$s, $i, $c] = $this->allocated(['state' => 'ready', 'hostname' => 'n8n-workspace.example.test',
            'owner_email' => 'login@example.test', 'password_enc' => Crypt::encryptString('CARD_PRIVATE_SECRET')]);
        $s->update(['name' => 'My automation workspace']);
        $vps = Service::create(['customer_id' => $c->id, 'name' => 'Independent ordinary VPS', 'price' => 1000000,
            'tax_percent' => 0, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'active',
            'cloud_plan_id' => $s->cloud_plan_id, 'provision_status' => 'none']);
        $other = $this->actingAs($c, 'customer')->get('/account/other')->assertOk()
            ->assertSee('My automation workspace')->assertSee('n8n-workspace.example.test')
            ->assertSee('login@example.test')->assertSee('assets/flags/de.svg')
            ->assertDontSee('Independent ordinary VPS')->assertDontSee('CARD_PRIVATE_SECRET');
        $this->assertStringContainsString('n8n.css', $other->getContent());
        $this->get('/account/servers')->assertOk()->assertSee('Independent ordinary VPS')->assertDontSee('My automation workspace');
        $this->get('/account/services')->assertOk()->assertSee('My automation workspace')->assertSee('Independent ordinary VPS');
        $this->assertTrue($s->fresh()->isCloud(), 'This is a presentation category, not a provider or billing conversion.');
    }

    public function test_card_password_reveal_is_owner_only_post_json_and_never_enters_a_later_get(): void
    {
        [$s, $i, $c] = $this->allocated(['state' => 'ready', 'hostname' => 'n8n-secret.example.test',
            'password_enc' => Crypt::encryptString('JSON_OWNER_ONLY_SECRET')]);
        $r = $this->actingAs($c, 'customer')->postJson(route('account.cloud.n8n-access', $s, false));
        $r->assertOk()->assertJsonPath('password', 'JSON_OWNER_ONLY_SECRET');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->get('/account/other')->assertOk()->assertDontSee('JSON_OWNER_ONLY_SECRET');
        $this->actingAs($this->customer(), 'customer')->postJson(route('account.cloud.n8n-access', $s, false))->assertNotFound();
        $s->update(['status' => 'cancelled']);
        $this->actingAs($c, 'customer')->postJson(route('account.cloud.n8n-access', $s, false))->assertNotFound();
    }

    public function test_country_plan_term_and_name_are_retained_before_an_explicit_order_submit(): void
    {
        $p = $this->plan();
        $query = ['country' => 'DE', 'plan' => $p->slug, 'location' => $p->location_code, 'cycle' => 'yearly', 'label' => 'My workflow'];
        $r = $this->get('/cloud/n8n?'.http_build_query($query))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$r->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@data-n8n-order][@method="get"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="cycle"][@value="yearly"][@checked]')->length);
        $this->assertSame('My workflow', $xpath->query('//input[@name="label"]')->item(0)->getAttribute('value'));
        $this->assertSame($p->slug, $xpath->query('//input[@name="plan"]')->item(0)->getAttribute('value'));
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->get('/account/cloud-store?'.http_build_query(['application'=>'n8n']+$query))->assertRedirect(route('login'));
        $this->assertStringContainsString('cycle=yearly', session('url.intended'));
        $this->assertStringContainsString('label=My%20workflow', session('url.intended'));
    }

    public function test_turkish_package_never_presents_an_enabled_hourly_choice_and_keeps_the_paid_period(): void
    {
        $p = $this->plan('vpstc'); $c = $this->customer();
        $r = $this->actingAs($c, 'customer')->get('/account/cloud-store?'.http_build_query([
            'application'=>'n8n','location'=>$p->location_code,'plan'=>$p->slug,'cycle'=>'semiannual','billing_mode'=>'hourly',
        ]))->assertOk();
        $dom = new \DOMDocument; @$dom->loadHTML($r->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//label[@data-n8n-hourly-choice][@hidden]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="billing_mode"][@disabled]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="billing_mode"][@checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="cycle"][@value="semiannual"][@checked]')->length);
    }

    public function test_unselected_and_closed_catalogues_cannot_submit_and_malformed_selections_render_safely(): void
    {
        $p = $this->plan();
        $r = $this->get('/cloud/n8n?country[]=DE&cycle[]=yearly&label[]=unsafe')->assertOk();
        $dom = new \DOMDocument; @$dom->loadHTML($r->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@data-n8n-details][@hidden]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-n8n-order]//button[@type="submit"][@disabled]')->length);
        Setting::put('n8n_sales_enabled', '0');
        $r = $this->get('/cloud/n8n?'.http_build_query(['location'=>$p->location_code,'plan'=>$p->slug]))->assertOk();
        @$dom->loadHTML($r->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@data-n8n-order]//button[@type="submit"][@disabled]')->length);
    }

    public function test_ssh_installer_pins_key_and_uploads_private_configuration_before_launching_one_unit(): void
    {
        $state = ['state' => 'waiting_ssh', 'hostname' => 'n8n-test.servernet.cloud', 'nonce' => 'marker',
            'owner_email' => 'owner@example.test', 'password_enc' => Crypt::encryptString('fixture-initial'), 'version' => '2.48.7'];
        [$s, $i] = $this->allocated($state); $i->setPassword('fixture-root'); $i->save();
        $remote = \Mockery::mock(\phpseclib4\Net\SFTP::class);
        $remote->shouldReceive('setTimeout')->once()->with(15);
        $remote->shouldReceive('getServerPublicHostKey')->once()->andReturn('fixture-key');
        $remote->shouldReceive('login')->once()->with('root', 'fixture-root')->andReturn(true);
        $remote->shouldReceive('exec')->once()->with(\Mockery::on(fn ($command) => str_contains($command, 'ready.json')))->andReturn('pending');
        $remote->shouldReceive('exec')->once()->with('install -d -m 0700 /opt/servernet-n8n')->andReturn('');
        $remote->shouldReceive('getExitStatus')->twice()->andReturn(0);
        $remote->shouldReceive('put')->once()->with('/opt/servernet-n8n/request.json', \Mockery::on(function ($json) {
            $data = json_decode($json, true);
            return $data['email'] === 'owner@example.test' && $data['password'] === 'fixture-initial';
        }));
        $remote->shouldReceive('chmod')->once()->with('/opt/servernet-n8n/request.json', 0600);
        $remote->shouldReceive('put')->once()->with('/opt/servernet-n8n/install.py', \Mockery::type('string'));
        $remote->shouldReceive('chmod')->once()->with('/opt/servernet-n8n/install.py', 0700);
        $remote->shouldReceive('exec')->once()->with(\Mockery::on(fn ($command) => str_starts_with($command, 'systemd-run ')))->andReturn('');
        $transport = \Mockery::mock(ManagedN8nSsh::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $transport->shouldReceive('connect')->once()->andReturn($remote);
        $this->assertSame('installing', $transport->step($s, $i, $state)['state']);
        $this->assertSame(hash('sha256', 'fixture-key'), data_get($i->fresh()->meta, 'n8n.ssh_key_sha256'));
    }

    public function test_changed_ssh_key_is_rejected_before_sending_credentials(): void
    {
        $state = ['ssh_key_sha256' => hash('sha256', 'original-key')];
        [$s, $i] = $this->allocated($state); $i->setPassword('fixture-root'); $i->save();
        $remote = \Mockery::mock(\phpseclib4\Net\SFTP::class);
        $remote->shouldReceive('setTimeout');
        $remote->shouldReceive('getServerPublicHostKey')->andReturn('different-key');
        $remote->shouldNotReceive('login');
        $transport = \Mockery::mock(ManagedN8nSsh::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $transport->shouldReceive('connect')->andReturn($remote);
        $this->expectExceptionMessage('ssh_host_key_changed');
        $transport->step($s, $i, $state);
    }

    public function test_managed_rebuild_cannot_remove_n8n_and_keep_a_stale_delivery(): void
    {
        [$s] = $this->allocated(['state' => 'ready']);
        $this->assertNull(app(\App\Services\Cloud\CloudOperations::class)->rebuildImageRef($s, 'ubuntu-24.04'));
        Http::assertNothingSent();
    }

}
