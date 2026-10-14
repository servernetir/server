<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudLocation, CloudPlan, CreditEntry, Customer, Service, Setting};
use App\Services\Cloud\{GpuCapacityOrders, SaladClient};
use App\Services\Finance\Wallet;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GpuPublicOrderTest extends TestCase
{
    use RefreshDatabase;

    private CloudPlan $plan;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('salad_api_key', 'test-only');
        Setting::put('salad_org', 'test-org');
        Setting::put('salad_project', 'test-project');
        Setting::put('salad_priority', 'high');
        Setting::put('pricing_rate_override', '100000');
        Setting::put('pricing_usd_rate_override', '90000');
        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'is_active' => true]);
        $this->plan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => 'gpu-class-test', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'slug' => 'gpu-public-test', 'public_name' => 'GTX 1650 (4 GB)',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150, 'arch' => 'x86', 'disk_type' => 'ssd',
            'gpu_model' => 'GTX 1650', 'gpu_vram_mb' => 4096, 'gpu_count' => 1, 'is_interruptible' => true,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
            'cost_hour_eur_micro' => 15000, 'is_active' => true, 'in_stock' => true]);
        CloudImage::create(['provider' => 'salad', 'key' => 'gpu-jupyter', 'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'],
            'kind' => 'app', 'family' => 'gpu-jupyter', 'label' => 'Jupyter PyTorch', 'arch' => 'x86', 'is_active' => true]);
        $this->customer = Customer::create(['email' => 'public-gpu@example.com', 'phone' => '09121112233',
            'password' => 'test-only-password', 'status' => 'active', 'locale' => 'fa']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/availability/')) {
                return Http::response(['available_gpu_high' => 0], 200);
            }
            if (str_ends_with($request->url(), '/gpu-classes')) {
                return Http::response(['items' => [['id' => 'gpu-class-test', 'is_high_demand' => false,
                    'prices' => [['priority' => 'high', 'price' => '0.01']]]]], 200);
            }
            throw new \RuntimeException('Unexpected provider call: '.$request->method().' '.$request->url());
        });
    }

    private function choices(): array
    {
        return ['plan' => $this->plan->slug, 'image' => 'gpu-jupyter', 'label' => 'my-notebook',
            'quotes' => [$this->plan->slug => $this->plan->hourlyIrt()]];
    }

    private function start(): string
    {
        $this->post(route('gpu.order'), $this->choices())->assertRedirect(route('account.gpu.resume'));
        return session('gpu_public_order.data.gpu_request_key');
    }

    private function assertNoPurchase(): void
    {
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/containers'));
    }

    public function test_guest_choices_survive_login_and_submit_once_without_any_purchase(): void
    {
        $key = $this->start();
        $this->assertSame($key, $this->start(), 'Repeated public submission must reuse the intent.');
        $this->get(route('account.gpu.resume'))->assertRedirect();
        $this->assertSame(route('account.gpu.resume'), session('url.intended'));
        $this->actingAs($this->customer, 'customer')->get(route('account.gpu.resume'))
            ->assertOk()->assertSee('my-notebook')->assertSee('requestSubmit()', false);
        $this->assertDatabaseCount('services', 0);
        $this->assertNoPurchase();
        $this->post(route('account.gpu.complete'), ['intent_key' => $key, 'label' => 'tampered', 'plan' => 'tampered'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $service = Service::sole();
        $this->assertSame('my-notebook', data_get($service->provision_meta, 'gpu_capacity_order.label'));
        $this->assertSame('gpu-jupyter', $service->cloud_image_key);
        $this->assertSame(GpuCapacityOrders::WAIT, $service->provision_status);
        $this->assertSame($this->plan->hourlyIrt(), $service->hourly_rate_irt);
        $this->post(route('account.gpu.complete'), ['intent_key' => $key])->assertRedirect();
        $this->assertDatabaseCount('services', 1);
        $this->assertNoPurchase();
    }

    public static function checkoutLocales(): array
    {
        return ['Persian' => [''], 'English' => ['en.'], 'Turkish' => ['tr.']];
    }

    #[DataProvider('checkoutLocales')]
    public function test_real_otp_login_preserves_jupyter_choices_and_funded_wallet_through_queue(string $prefix): void
    {
        // Exercise the real login controller and session regeneration, never actingAs().
        Mail::fake();
        $sms = new class implements SmsSender {
            public ?string $code = null;
            public function enabled(): bool { return true; }
            public function name(): string { return 'test-only'; }
            public function send(string $mobile, string $text): bool { return true; }
            public function sendOtp(string $mobile, string $code): bool { $this->code = $code; return true; }
        };
        $this->app->instance(SmsSender::class, $sms);

        // Pick a non-default card, so a silent fallback to the cheapest plan fails this test.
        $selected = $this->plan->replicate();
        $selected->fill(['slug' => 'gpu-selected-notebook', 'provider_ref' => 'gpu-selected-class',
            'public_name' => 'RTX 3090 (24 GB)', 'gpu_model' => 'RTX 3090', 'gpu_vram_mb' => 24576,
            'price_irt' => 4000000])->save();
        $rate = $selected->hourlyIrt();
        $wallet = app(Wallet::class);
        $wallet->credit($this->customer->id, 'IRT', $rate * 40, 'topup', $this->customer);
        $balance = $wallet->balanceOf($this->customer->id);
        $assertUncharged = function () use ($wallet, $balance) {
            $this->assertSame($balance, $wallet->balanceOf($this->customer->id));
            $this->assertSame($balance, $wallet->availableOf($this->customer->id));
            $this->assertDatabaseCount('credit_ledger', 1); // Only the test's initial top-up.
            $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('cloud_instances', 0);
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/containers'));
        };

        $this->get(route($prefix.'gpu'))->assertOk()->assertSee('gpu-jupyter');
        $this->post(route($prefix.'gpu.order'), ['plan' => $selected->slug, 'image' => 'gpu-jupyter',
            'label' => 'my-selected-notebook', 'quotes' => [$selected->slug => $rate]])
            ->assertRedirect(route($prefix.'account.gpu.resume'));
        $intent = session('gpu_public_order');
        $key = $intent['data']['gpu_request_key'];
        $this->get(route($prefix.'account.gpu.resume'))->assertRedirect();
        $this->assertSame(route($prefix.'account.gpu.resume'), session('url.intended'));
        $this->get(route($prefix.'login'))->assertOk();
        $this->post(route($prefix.'login.start'), ['method' => 'mobile', 'identifier' => $this->customer->phone])
            ->assertRedirect(route($prefix.'login.code'));
        $this->get(route($prefix.'login.code'))->assertOk();
        $this->assertNotNull($sms->code);
        $this->post(route($prefix.'login.verify'), ['code' => $sms->code === '000000' ? '111111' : '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');
        $this->assertSame($intent, session('gpu_public_order'));
        $assertUncharged();

        $sessionBeforeLogin = session()->getId();
        $this->post(route($prefix.'login.verify'), ['code' => $sms->code])
            ->assertSessionHasNoErrors()->assertRedirect(route($prefix.'account.gpu.resume'));
        $this->assertAuthenticatedAs($this->customer, 'customer');
        $this->assertNotSame($sessionBeforeLogin, session()->getId());
        $this->assertSame($intent, session('gpu_public_order'));
        $this->get(route($prefix.'account.gpu.resume'))->assertOk()
            ->assertSee('my-selected-notebook')->assertSee('Jupyter')
            ->assertSee('value="'.$key.'"', false)->assertSee('requestSubmit()', false);
        $this->assertDatabaseCount('services', 0);
        $assertUncharged();

        // Submit the continuation form just as its browser script does.
        $response = $this->post(route($prefix.'account.gpu.complete'), ['intent_key' => $key])->assertSessionHasNoErrors();
        $service = Service::sole();
        $response->assertRedirect(route($prefix.'account.cloud.show', $service));
        $this->assertSame($selected->id, $service->cloud_plan_id);
        $this->assertSame('gpu-jupyter', $service->cloud_image_key);
        $this->assertSame('my-selected-notebook', data_get($service->provision_meta, 'gpu_capacity_order.label'));
        $this->assertSame($key, data_get($service->provision_meta, 'gpu_capacity_order.request_key'));
        $this->assertSame($rate, $service->hourly_rate_irt);
        $this->assertSame(GpuCapacityOrders::WAIT, $service->provision_status);
        $this->assertSame('hourly', $service->billing_mode);
        $this->assertTrue(GpuCapacityOrders::unpaid($service));
        $this->get(route($prefix.'account.cloud.show', $service))->assertOk()->assertSee('Jupyter');
        $this->get(route($prefix.'account.cloud.status', $service))->assertJson(['capacity_state' => 'awaiting_allocation']);
        $assertUncharged();

        // Run the actual capacity command only against this isolated database and fake HTTP.
        $this->travel(2)->minutes();
        $this->artisan('cloud:advance-gpu-capacity')->expectsOutput('{"waiting":1}')->assertSuccessful();
        $this->assertSame(GpuCapacityOrders::WAIT, $service->fresh()->provision_status);
        $this->post(route($prefix.'account.gpu.complete'), ['intent_key' => $key])
            ->assertRedirect(route($prefix.'account.cloud.show', $service));
        $this->assertDatabaseCount('services', 1);
        $assertUncharged();
    }

    public function test_price_change_while_logging_in_returns_to_editable_form(): void
    {
        $key = $this->start();
        $this->plan->update(['price_irt' => 4000000]);
        $this->actingAs($this->customer, 'customer')->post(route('account.gpu.complete'), ['intent_key' => $key])
            ->assertRedirect(route('gpu'))->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('services', 0);
        $this->assertNoPurchase();
    }

    public function test_paused_app_is_rejected_after_login(): void
    {
        $key = $this->start();
        Setting::put('salad_jupyter_sales_paused', '1');
        $this->actingAs($this->customer, 'customer')->post(route('account.gpu.complete'), ['intent_key' => $key])
            ->assertRedirect(route('gpu'))->assertSessionHasErrors('image');
        $this->assertDatabaseCount('services', 0);
        $this->assertNoPurchase();
    }

    public function test_expired_intent_and_wrong_token_cannot_order(): void
    {
        $key = $this->start();
        $this->actingAs($this->customer, 'customer')->post(route('account.gpu.complete'), ['intent_key' => 'wrong'])->assertStatus(419);
        $this->travel(31)->minutes();
        $this->post(route('account.gpu.complete'), ['intent_key' => $key])->assertRedirect(route('gpu'))->assertSessionHasErrors();
        $this->assertDatabaseCount('services', 0);
        $this->assertNoPurchase();
    }

    public function test_account_switch_cannot_reuse_authenticated_intent(): void
    {
        $this->actingAs($this->customer, 'customer');
        $key = $this->start();
        $other = Customer::create(['email' => 'other-gpu@example.com', 'password' => 'test-only-password', 'status' => 'active']);
        $this->actingAs($other, 'customer')->post(route('account.gpu.complete'), ['intent_key' => $key])
            ->assertRedirect(route('gpu'))->assertSessionHasErrors();
        $this->assertDatabaseCount('services', 0);
    }

    public function test_public_form_has_choices_name_and_complete_localized_faq(): void
    {
        foreach (['fa' => '/gpu', 'en' => '/en/gpu', 'tr' => '/tr/gpu'] as $locale => $url) {
            $html = $this->get($url)->assertOk()->assertSee('name="label"', false)
                ->assertSee('value="gpu-jupyter"', false)->getContent();
            $this->assertSame(12, substr_count($html, '<details>'));
            $this->assertStringContainsString(trans('gpu.faq7_q', [], $locale), $html);
            $this->assertDoesNotMatchRegularExpression('/\bgpu\.(?:faq|name|continue|app)[a-z0-9_]*\b/', $html);
        }
        Setting::put('salad_jupyter_sales_paused', '1');
        $this->get('/gpu')->assertOk()->assertDontSee('value="gpu-jupyter"', false);
        $this->post(route('gpu.order'), $this->choices())->assertSessionHasErrors('image');
        $this->assertNoPurchase();
    }

    public function test_wait_message_changes_after_an_hour_without_overwriting_real_state(): void
    {
        $key = $this->start();
        $this->actingAs($this->customer, 'customer')->post(route('account.gpu.complete'), ['intent_key' => $key]);
        $service = Service::sole();
        $this->travel(59)->minutes();
        $this->assertSame('awaiting_allocation', GpuCapacityOrders::displayState($service));
        $this->get(route('account.cloud.status', $service))->assertJson(['capacity_state' => 'awaiting_allocation']);
        $this->travel(2)->minutes();
        $this->assertSame('waiting_capacity', GpuCapacityOrders::displayState($service));
        $this->get(route('account.cloud.status', $service))->assertJson(['capacity_state' => 'waiting_capacity']);
        $this->get(route('account.cloud.show', $service))->assertOk()->assertSee(__('ui.gpu_wait_delayed'), false);
        foreach (['checking_capacity', 'price_changed', 'insufficient_credit'] as $state) {
            $meta = $service->provision_meta;
            $meta['gpu_capacity_order']['state'] = $state;
            $service->provision_meta = $meta;
            $this->assertSame($state, GpuCapacityOrders::displayState($service));
        }
        $this->assertNoPurchase();
    }
}
