<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, Customer, Invoice, Service, Setting};
use App\Services\Cloud\{GpuCapacityOrders, SaladClient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GpuRequestPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private CloudPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('pricing_usd_rate_override', '90000');

        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'is_active' => true]);
        $this->plan = CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'request-presentation-test',
            'provider_location' => 'global', 'location_code' => 'global-gpu',
            'slug' => 'request-presentation-test', 'public_name' => 'Test GPU',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150, 'arch' => 'x86',
            'disk_type' => 'ssd', 'gpu_model' => 'Test GPU', 'gpu_vram_mb' => 4096,
            'gpu_count' => 1, 'is_interruptible' => true,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 2000,
            'price_irt' => 2000000, 'cost_hour_eur_micro' => 15000,
            'is_active' => true, 'in_stock' => true,
        ]);
        CloudImage::create([
            'provider' => 'salad', 'key' => 'gpu-jupyter',
            'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'],
            'kind' => 'app', 'family' => 'gpu-jupyter', 'label' => 'Jupyter PyTorch',
            'arch' => 'x86', 'is_active' => true,
        ]);
        $this->customer = Customer::create([
            'email' => 'request-presentation@example.com', 'phone' => '09121112233',
            'password' => 'test-only-password', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_credit_wait_shows_the_agreed_rate_and_required_balance_with_localized_actions(string $locale): void
    {
        $service = $this->request('insufficient_credit', ['minimum_credit_irt' => 984000]);
        $xpath = $this->render($service, $locale);

        $card = $xpath->query('//*[@data-gpu-capacity-wait]')->item(0);
        $this->assertNotNull($card);
        $this->assertNotSame('', $card->getAttribute('aria-labelledby'));
        $this->assertSame(1, $xpath->query('//*[@id="'.$card->getAttribute('aria-labelledby').'"]')->length);

        $summary = $xpath->query($this->withClass('cr-summary'), $card)->item(0);
        $this->assertNotNull($summary);
        $this->assertStringContainsString(cloud_price(41000), $summary->textContent);
        $this->assertStringContainsString(cloud_price(984000), $summary->textContent);
        $this->assertStringContainsString(__('ui.gpu_approved_price'), $summary->textContent);
        $this->assertStringContainsString(__('ui.gpu_request_required_credit'), $summary->textContent);

        $topup = $xpath->query('.//a[@href="'.lroute('account.topup').'"]', $card)->item(0);
        $this->assertNotNull($topup);
        $this->assertStringContainsString(__('ui.gpu_topup'), $topup->textContent);

        $cancel = $xpath->query($this->withClass('cr-cancel').'//form[@action="'.lroute('account.services.cancel', $service).'"]')->item(0);
        $this->assertNotNull($cancel);
        $this->assertSame('post', strtolower($cancel->getAttribute('method')));
        $this->assertTrue($cancel->hasAttribute('data-confirm'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $cancel)->length);

        $this->assertRequestWasOnlyRead($service, 'insufficient_credit');
    }

    #[DataProvider('locales')]
    public function test_price_change_keeps_explicit_post_confirmation_of_the_exact_quote(string $locale): void
    {
        $service = $this->request('price_changed', ['new_price_irt' => 52000]);
        $xpath = $this->render($service, $locale);

        $card = $xpath->query('//*[@data-gpu-capacity-wait]')->item(0);
        $this->assertNotNull($card);
        $form = $xpath->query('.//form[@action="'.lroute('account.cloud.gpu-price', $service).'"]', $card)->item(0);
        $this->assertNotNull($form);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $quote = $xpath->query('.//input[@name="quoted_price_irt"]', $form)->item(0);
        $this->assertNotNull($quote);
        $this->assertSame('hidden', $quote->getAttribute('type'));
        $this->assertSame('52000', $quote->getAttribute('value'));
        $this->assertStringContainsString(cloud_price(52000), $card->textContent);
        $this->assertStringContainsString(cloud_price(41000), $card->textContent);
        $this->assertStringContainsString(__('ui.gpu_confirm_price'), $form->textContent);
        $this->assertSame(0, $xpath->query('.//a[@href="'.lroute('account.topup').'"]', $card)->length);

        $this->assertRequestWasOnlyRead($service, 'price_changed');
    }

    public function test_closed_request_does_not_render_waiting_or_cancellation_controls(): void
    {
        $service = $this->request('insufficient_credit', ['minimum_credit_irt' => 984000]);
        $service->update(['status' => 'cancelled']);

        $xpath = $this->render($service, 'fa');

        $this->assertSame(0, $xpath->query('//*[@data-gpu-capacity-wait]')->length);
        $this->assertSame(0, $xpath->query($this->withClass('cr-cancel'))->length);
        $this->assertSame(1, $xpath->query('//*[@data-gpu-closed]')->length);
    }

    public function test_pending_invoice_keeps_its_payment_action_without_a_request_cancellation_card(): void
    {
        $service = $this->request('awaiting_payment', ['charge_pending' => false]);
        $service->update(['status' => 'pending', 'provision_status' => 'none', 'billing_mode' => 'cycle']);
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'subtotal' => 2000000, 'tax' => 0,
            'total' => 2000000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $xpath = $this->render($service, 'fa');

        $card = $xpath->query('//*[@data-gpu-payment-wait]')->item(0);
        $this->assertNotNull($card);
        $payment = $xpath->query('.//a[@href="'.lroute('account.invoice', $invoice).'"]', $card)->item(0);
        $this->assertNotNull($payment);
        $this->assertStringContainsString(__('ui.gpu_pay_invoice'), $payment->textContent);
        $this->assertSame(0, $xpath->query($this->withClass('cr-cancel'))->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.lroute('account.services.cancel', $service).'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-gpu-capacity-wait]')->length);
        $this->assertSame('pending', $service->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNothingSent();
    }

    public function test_delivered_server_does_not_render_request_wait_or_cancellation_card(): void
    {
        $service = $this->request('ready', ['charge_pending' => false]);
        $service->update(['status' => 'active', 'provision_status' => 'done']);
        $instance = CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'salad',
            'provider_ref' => 'request-presentation-instance', 'image_key' => 'gpu-jupyter',
            'hostname' => 'gpu-presentation.example.com', 'status' => 'running',
            'specs' => ['vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150],
        ]);
        $this->assertTrue($instance->isDelivered());

        $xpath = $this->render($service, 'fa');

        $this->assertSame(0, $xpath->query('//*[@data-gpu-capacity-wait]')->length);
        $this->assertSame(0, $xpath->query($this->withClass('cr-cancel'))->length);
        $this->assertSame(1, $xpath->query('//*[@id="service-lifecycle"]')->length);
    }

    private function request(string $state, array $metadata = []): Service
    {
        $service = app(GpuCapacityOrders::class)->register($this->customer, $this->plan,
            ['image' => 'gpu-jupyter', 'billing_mode' => 'hourly', 'cycle' => 'monthly',
                'gpu_request_key' => str_repeat('p', 40)], 'presentation', 'Test request',
            ['ok' => true, 'available' => false, 'checked_at' => now()->toIso8601String()]);
        $meta = $service->provision_meta;
        $meta['gpu_capacity_order'] = array_replace($meta['gpu_capacity_order'], $metadata, ['state' => $state]);
        $service->update(['hourly_rate_irt' => 41000, 'provision_meta' => $meta]);

        return $service;
    }

    private function render(Service $service, string $locale): \DOMXPath
    {
        $prefix = $locale === 'fa' ? '' : '/'.$locale;
        $html = $this->actingAs($this->customer, 'customer')
            ->get($prefix.'/account/cloud/'.$service->id)->assertOk()->getContent();

        $this->assertStringContainsString('assets/css/cloud-request.css', $html);
        $this->assertStringNotContainsString('ui.gpu_request_', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    private function assertRequestWasOnlyRead(Service $service, string $state): void
    {
        $this->assertSame($state, data_get($service->fresh()->provision_meta, 'gpu_capacity_order.state'));
        $this->assertSame(41000, (int) $service->fresh()->hourly_rate_irt);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    private function withClass(string $class): string
    {
        return './/*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]';
    }
}
