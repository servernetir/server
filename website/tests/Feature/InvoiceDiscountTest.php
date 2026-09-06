<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تخفیفِ فاکتور — یک ستون واقعی، نه عددی که در قیمتِ واحد حل شده باشد.
 *
 * ═══ دو فشاری که این ستون را لازم کرد ═══
 *
 * ۱) ممیزیِ مالیِ شهریور ۱۴۰۵: تخفیف هیچ ردی نداشت و «چرا این مشتری
 *    ارزان‌تر خرید؟» جوابی در سیستم نداشت.
 * ۲) قراردادِ سبدِ اسنپ‌پی `discountAmount` را جدا می‌خواهد:
 *      amount = Σ(count × unit) + tax − discount − externalSource
 *
 * ⚠️ این ستون تخفیفِ **سرویس** نیست. آن عمداً داخلِ `services.price` می‌مانَد
 * چون روی هر تمدید هم اعمال می‌شود.
 */
class InvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'd'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
        ]);
    }

    private function invoice(array $over = []): Invoice
    {
        return Invoice::create($over + [
            'customer_id'   => $this->customer()->id,
            'currency_code' => 'IRT',
            'subtotal'      => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status'        => 'unpaid', 'issued_at' => now(),
        ]);
    }

    private function item(Invoice $inv, int $qty, int $unit, int $discount = 0, int $rateBp = 1000): InvoiceItem
    {
        $line = $qty * $unit;
        $net  = $line - $discount;

        return InvoiceItem::create([
            'invoice_id'  => $inv->id,
            'title'       => 'سرور ابری',
            'quantity'    => $qty,
            'unit_price'  => $unit,
            'line_total'  => $line,
            'discount'    => $discount,
            'tax_rate_bp' => $rateBp,
            // مالیات روی مبلغِ خالص — همان قاعده‌ای که netTotal() توضیح می‌دهد
            'tax_amount'  => intdiv($net * $rateBp, 10000),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────

    /** بدونِ تخفیف، ریاضی دقیقاً همان چیزی است که همیشه بود. */
    public function test_an_invoice_without_a_discount_is_unchanged(): void
    {
        $inv = $this->invoice();
        $this->item($inv, qty: 1, unit: 1_000_000);

        $inv->recalculateTotals();
        $inv->save();

        $this->assertSame(1_000_000, $inv->subtotal);
        $this->assertSame(0, $inv->discount);
        $this->assertSame(100_000, $inv->tax);
        $this->assertSame(1_100_000, $inv->total, 'subtotal + tax، مثل قبل');
    }

    /** تخفیف پیش از مالیات کم می‌شود — مأخذِ ارزش افزوده مبلغِ خالص است. */
    public function test_tax_is_charged_on_the_net_amount(): void
    {
        $inv = $this->invoice();
        $this->item($inv, qty: 1, unit: 1_000_000, discount: 200_000);

        $inv->recalculateTotals();
        $inv->save();

        $this->assertSame(1_000_000, $inv->subtotal, 'جمعِ ناخالص دست‌نخورده می‌مانَد');
        $this->assertSame(200_000, $inv->discount);
        // ۱۰٪ روی ۸۰۰٬۰۰۰ = ۸۰٬۰۰۰ — نه ۱۰۰٬۰۰۰
        $this->assertSame(80_000, $inv->tax);
        $this->assertSame(880_000, $inv->total);
    }

    /** تخفیفِ چند ردیف جمع می‌شود و روی فاکتور می‌نشیند. */
    public function test_line_discounts_add_up_to_the_invoice_discount(): void
    {
        $inv = $this->invoice();
        $this->item($inv, qty: 2, unit: 500_000, discount: 100_000);
        $this->item($inv, qty: 1, unit: 300_000, discount: 50_000);

        $inv->recalculateTotals();
        $inv->save();

        $this->assertSame(1_300_000, $inv->subtotal);
        $this->assertSame(150_000, $inv->discount);
        $this->assertSame(1_150_000 + $inv->tax, $inv->total);
    }

    /**
     * 🔴 تخفیفِ بزرگ‌تر از مبلغ، فاکتورِ منفی می‌سازد — و `due()` آن را با
     * `max(0, …)` پنهان می‌کند، پس خطا هرگز دیده نمی‌شود.
     */
    public function test_a_discount_can_never_exceed_the_invoice(): void
    {
        $inv = $this->invoice();
        $this->item($inv, qty: 1, unit: 100_000, discount: 0);
        $inv->discount = 500_000;

        $inv->recalculateTotals();
        $inv->save();

        $this->assertSame(100_000, $inv->discount, 'به جمعِ ردیف‌ها محدود می‌شود');
        $this->assertSame(0, $inv->total - $inv->tax);
        $this->assertGreaterThanOrEqual(0, $inv->total);
    }

    /** تخفیفِ سطحِ فاکتور، وقتی ردیفی تخفیف ندارد. */
    public function test_an_invoice_level_discount_is_kept(): void
    {
        $inv = $this->invoice();
        $this->item($inv, qty: 1, unit: 1_000_000);
        $inv->discount = 150_000;

        $inv->recalculateTotals();
        $inv->save();

        $this->assertSame(150_000, $inv->discount);
        $this->assertSame(1_000_000 - 150_000 + $inv->tax, $inv->total);
    }

    /** مبلغِ خالصِ ردیف — مأخذی که هر صادرکننده باید مالیات را از آن بگیرد. */
    public function test_the_line_net_total_subtracts_its_own_discount(): void
    {
        $inv = $this->invoice();
        $item = $this->item($inv, qty: 3, unit: 200_000, discount: 60_000);

        $this->assertSame(600_000, $item->line_total);
        $this->assertSame(540_000, $item->netTotal());
    }

    /** «چرا تخفیف؟» بخشی از خودِ تخفیف است — ممیز همین را می‌پرسد. */
    public function test_the_reason_is_stored_and_shown(): void
    {
        $c = $this->customer();
        $inv = Invoice::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT',
            'subtotal' => 1_000_000, 'discount' => 100_000, 'discount_note' => 'جبرانِ قطعی سرویس',
            'tax' => 0, 'total' => 900_000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $html = $this->actingAs($c, 'customer')
            ->get('/account/invoices/'.$inv->id.'/print?noprint=1')
            ->assertOk()->getContent();

        $this->assertStringContainsString('جبرانِ قطعی سرویس', $html);
        $this->assertStringContainsString(__('ui.invp_discount'), $html);
    }

    /** فاکتورِ بی‌تخفیف نباید ردیفِ خالیِ «تخفیف: —» چاپ کند. */
    public function test_no_discount_row_when_there_is_none(): void
    {
        $c = $this->customer();
        $inv = Invoice::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT',
            'subtotal' => 1_000_000, 'tax' => 0, 'total' => 1_000_000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $html = $this->actingAs($c, 'customer')
            ->get('/account/invoices/'.$inv->id.'/print?noprint=1')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(__('ui.invp_discount'), $html);
    }
}
