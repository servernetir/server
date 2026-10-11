<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiBillingDisplayTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
        Http::preventStrayRequests();
    }

    public function test_funding_step_discloses_account_consumption_tax_in_all_languages_without_taxing_the_topup(): void
    {
        TaxRate::create(['name' => 'AI test rate', 'country' => 'IR', 'product_kind' => 'ai',
            'rate_bp' => 1250, 'priority' => 100, 'is_active' => true]);
        Setting::put('pricing_rate_override', '100000');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            [$customer] = $this->greenKey(0, $locale);
            $response = $this->actingAs($customer, 'customer')->get($prefix.'/account/topup')->assertOk();
            $response->assertSee('data-ai-funding-tax', false)->assertSee('12.5')->assertDontSee('ai.funding_usage_tax_note');
            $this->actingAs($customer, 'customer')->post($prefix.'/account/topup', ['amount' => $locale === 'fa' ? 100000 : 5])
                ->assertRedirect()->assertSessionHasNoErrors();
            $invoice = Invoice::where('customer_id', $customer->id)->where('kind', 'topup')->firstOrFail();
            $this->assertSame(0, (int) $invoice->tax);
            $this->assertSame((int) $invoice->subtotal, (int) $invoice->total);
        }
        $other = Customer::create(['email' => 'no-ai@example.com', 'phone' => '09123456789',
            'status' => 'active', 'locale' => 'en']);
        $this->actingAs($other, 'customer')->get('/en/account/topup')->assertOk()->assertDontSee('data-ai-funding-tax', false);
    }

    public function test_usage_receipt_shows_recorded_usage_and_tax_and_keeps_tenant_privacy(): void
    {
        [$customer, $plain] = $this->greenKey(locale: 'en');
        $this->fakeUsage();
        $this->v1($plain, $this->bigBody())->assertOk();
        $usage = AiUsage::firstOrFail();
        $html = $this->actingAs($customer, 'customer')->get('/en/account/ai')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $headers = $xpath->query('//table[@data-ai-billing-receipts]/thead/tr/th');
        $this->assertSame('Usage, Toman', $headers->item(3)->textContent);
        $this->assertSame('Tax, Toman', $headers->item(4)->textContent);
        $row = $xpath->query('//table[@data-ai-billing-receipts]/tbody/tr[1]/td');
        $this->assertSame(number_format((int) $usage->sell_irt), $row->item(3)->textContent);
        $this->assertSame(number_format((int) $usage->tax_irt), $row->item(4)->textContent);
        $this->assertSame(number_format((int) $usage->charged_irt - (int) $usage->refunded_irt), $row->item(5)->textContent);
        [$other] = $this->greenKey(0, 'en');
        $this->actingAs($other, 'customer')->get('/en/account/ai')->assertOk()->assertDontSee($usage->public_id);
    }
}
