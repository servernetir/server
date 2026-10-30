<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\Analytics\DataLayerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AnalyticsSessionEventConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.gtm.enabled', true);
        config()->set('services.gtm.id', 'GTM-TEST123');

        Route::middleware('web')->get('/__test/analytics/page', fn () => view('partials.gtm-head'));
        Route::middleware('web')->get('/__test/analytics/callback', function () {
            // The gateway callback flashes and renders its result in one request.
            // Use an already-settled in-memory fixture; do not exercise a payment.
            DataLayerService::flashPurchase($this->payment());

            return view('partials.gtm-head');
        });
        Route::middleware('web')->get('/__test/analytics/credit-redirect', function () {
            DataLayerService::flashPurchase($this->payment());

            return redirect('/__test/analytics/page');
        });
    }

    public function test_purchase_rendered_in_callback_is_not_emitted_again_on_the_next_page(): void
    {
        $callback = $this->get('/__test/analytics/callback')->assertOk();
        $this->assertSame(1, substr_count($callback->getContent(), '"event":"purchase"'));
        $callback->assertSee('"transaction_id":"payment:44"', false)
            ->assertSee('"currency":"IRR"', false)
            ->assertSee('"value":1090000', false)
            ->assertSee('"tax":90000', false)
            ->assertSee('"price":500000', false);

        $this->get('/__test/analytics/page')->assertOk()
            ->assertDontSee('"event":"purchase"', false)
            ->assertDontSee('"transaction_id":"payment:44"', false);
        Http::assertNothingSent();
    }

    public function test_redirect_without_rendering_preserves_purchase_until_its_first_render(): void
    {
        $this->get('/__test/analytics/credit-redirect')->assertRedirect('/__test/analytics/page');

        $landing = $this->get('/__test/analytics/page')->assertOk();
        $this->assertSame(1, substr_count($landing->getContent(), '"event":"purchase"'));
        $landing->assertSee('"transaction_id":"payment:44"', false);
        $this->get('/__test/analytics/page')->assertOk()->assertDontSee('"event":"purchase"', false);
    }

    public function test_disabled_analytics_does_not_consume_an_unrendered_event(): void
    {
        config()->set('services.gtm.enabled', false);
        $this->get('/__test/analytics/callback')->assertOk()->assertDontSee('"event":"purchase"', false);
        $this->assertSame('payment:44', session(DataLayerService::SESSION_PURCHASE_KEY)['ecommerce']['transaction_id']);

        config()->set('services.gtm.enabled', true);
        $this->get('/__test/analytics/page')->assertOk()->assertSee('"event":"purchase"', false);
        $this->get('/__test/analytics/page')->assertOk()->assertDontSee('"event":"purchase"', false);
    }

    public function test_auth_and_generic_events_are_consumed_after_their_first_render(): void
    {
        Route::middleware('web')->get('/__test/analytics/auth', function () {
            DataLayerService::flashSignUp((object) ['id' => 9999], 'mobile_otp');
            DataLayerService::flashEvent('service_provisioned', ['service_family' => 'vps']);

            return view('partials.gtm-head');
        });

        $this->get('/__test/analytics/auth')->assertOk()
            ->assertSee('"event":"sign_up","method":"mobile_otp"', false)
            ->assertSee('"event":"service_provisioned","service_family":"vps"', false);
        $this->get('/__test/analytics/page')->assertOk()
            ->assertDontSee('"event":"sign_up"', false)
            ->assertDontSee('"event":"service_provisioned"', false);
    }

    private function payment(): Payment
    {
        $item = new InvoiceItem(['title' => 'Cloud plan CV-2', 'quantity' => 2, 'unit_price' => 50_000, 'line_total' => 100_000]);
        $item->id = 91;
        $invoice = new Invoice(['kind' => 'service', 'currency_code' => 'IRT', 'tax' => 9_000]);
        $invoice->id = 12;
        $invoice->setRelation('items', new Collection([$item]));
        $payment = new Payment(['invoice_id' => 12, 'currency_code' => 'IRT', 'amount' => 109_000, 'status' => 'paid']);
        $payment->id = 44;
        $payment->setRelation('invoice', $invoice);

        return $payment;
    }
}
