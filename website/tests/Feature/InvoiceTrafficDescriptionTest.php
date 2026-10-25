<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\InvoiceText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvoiceTrafficDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): array
    {
        Http::preventStrayRequests();
        $customer = Customer::create(['email' => 'invoice-detail@example.test', 'status' => 'active', 'locale' => 'fa']);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 1000, 'tax' => 100, 'total' => 1100, 'paid' => 1100, 'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
            'note' => 'PRIVATE OPERATOR NOTE — not customer facing']);
        $item = $invoice->items()->create(['title' => 'ترافیک اینترنت سرور #101',
            'description' => '1.250000 GB | 2026-10-24T20:45:00Z — 2026-10-24T21:15:00Z (UTC)',
            'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000, 'tax_rate_bp' => 1000, 'tax_amount' => 100]);
        Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'gateway' => 'credit',
            'currency_code' => 'IRT', 'amount' => 1100, 'status' => 'paid', 'paid_at' => now()]);

        return [$customer, $invoice, $item];
    }

    public function test_list_detail_and_print_explain_traffic_in_all_three_languages_without_financial_writes(): void
    {
        [$customer, $invoice, $item] = $this->invoice();
        $before = [$invoice->fresh()->getRawOriginal(), $item->fresh()->getRawOriginal(), Payment::first()->getRawOriginal(), CreditEntry::count(), BusinessEntry::count()];
        $this->actingAs($customer, 'customer');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $title = trans('invoices.traffic_title', ['service' => '101'], $locale);
            $this->get($prefix.'/account/invoices')->assertOk()->assertSee($title)->assertSee($invoice->number);
            foreach (['', '/print?noprint=1'] as $suffix) {
                $response = $this->get($prefix.'/account/invoices/'.$invoice->id.$suffix)->assertOk()->assertSee($title)
                    ->assertDontSee('PRIVATE OPERATOR NOTE');
                if ($locale === 'fa') {
                    $response->assertSee('بابت مصرف ۱.۲۵ گیگابایت ترافیک اینترنت')
                        ->assertSee('۱۴۰۵/۰۸/۰۳ ۰۰:۱۵')->assertSee('۱۴۰۵/۰۸/۰۳ ۰۰:۴۵')->assertSee('به وقت تهران');
                } else {
                    $response->assertSee('1.25 GB')->assertSee('2026/10/25 00:15')->assertSee('2026/10/25 00:45')->assertSee('Asia/Tehran');
                }
            }
        }
        $after = [$invoice->fresh()->getRawOriginal(), $item->fresh()->getRawOriginal(), Payment::first()->getRawOriginal(), CreditEntry::count(), BusinessEntry::count()];
        $this->assertSame($before, $after);
    }

    public function test_admin_customer_finance_shows_the_same_title_and_consumption_period(): void
    {
        [$customer] = $this->invoice();
        $staff = User::create(['name' => 'Fixture admin', 'email' => 'invoice-admin@example.test', 'password' => bcrypt('fixture'), 'role' => 'admin']);
        $this->actingAs($staff, 'web')->get('/admin/customers/'.$customer->id)->assertOk()
            ->assertSee('هزینه مصرف ترافیک اینترنت سرور #101')->assertSee('بابت مصرف ۱.۲۵ گیگابایت')
            ->assertSee('۱۴۰۵/۰۸/۰۳ ۰۰:۱۵')->assertSee('به وقت تهران');
    }

    public function test_a_normal_service_or_topup_is_not_reclassified_as_traffic(): void
    {
        [$customer, $invoice, $item] = $this->invoice();
        $item->update(['title' => 'پشتیبانی ویژه', 'description' => 'شرح واقعی خدمت دیگر']);
        $this->actingAs($customer, 'customer')->get('/account/invoices/'.$invoice->id)->assertOk()
            ->assertSee('پشتیبانی ویژه')->assertSee('شرح واقعی خدمت دیگر')->assertDontSee('هزینه مصرف ترافیک');
        $invoice->update(['kind' => 'topup']);
        $this->get('/account/invoices')->assertOk()->assertSee('پشتیبانی ویژه')->assertDontSee('هزینه مصرف ترافیک');
    }

    public function test_bad_or_unknown_description_is_preserved_and_html_is_escaped(): void
    {
        [$customer, $invoice, $item] = $this->invoice();
        $item->description = '1 GB | 2026-02-30T20:45:00Z — 2026-03-01T21:15:00Z (UTC)';
        $this->assertSame($item->description, InvoiceText::description($item));
        $item->update(['description' => '<script>invoiceFixture()</script>']);
        $this->actingAs($customer, 'customer')->get('/account/invoices/'.$invoice->id)->assertOk()
            ->assertSee('&lt;script&gt;invoiceFixture()&lt;/script&gt;', false)->assertDontSee('<script>invoiceFixture()</script>', false);
    }

    public function test_invoice_ownership_still_prevents_other_customers_from_reading_details(): void
    {
        [, $invoice] = $this->invoice();
        $stranger = Customer::create(['email' => 'invoice-stranger@example.test', 'status' => 'active']);
        $this->actingAs($stranger, 'customer')->get('/account/invoices/'.$invoice->id)->assertNotFound();
        $this->get('/account/invoices/'.$invoice->id.'/print')->assertNotFound();
        $this->get('/account/invoices')->assertOk()->assertDontSee($invoice->number)->assertDontSee('هزینه مصرف ترافیک اینترنت سرور #101');
    }
}
