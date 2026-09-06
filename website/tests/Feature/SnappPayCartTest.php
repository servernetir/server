<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\Payment\SnappPay\SnappPayCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سبدِ خریدِ اسنپ‌پی باید **دقیقاً** همان فاکتور را توصیف کند.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * دو خطای این‌جا بی‌صدا پول می‌برند:
 *
 * ۱) **واحد.** تمامِ APIهای اسنپ‌پی ریال می‌گیرند و پولِ ما تومان است. یک
 *    فراموشیِ ضربدر ۱۰ یعنی مشتری یک‌دهم می‌پردازد.
 *
 * ۲) **جمع.** فرمولِ اسنپ‌پی صریح است و اگر جمعِ سبد با فاکتور نخوانَد،
 *    یا درخواست رد می‌شود یا — بدتر — پذیرفته می‌شود و مبلغی غیر از فاکتور
 *    از مشتری می‌گیرد.
 */
class SnappPayCartTest extends TestCase
{
    use RefreshDatabase;

    private function cart(): SnappPayCart
    {
        return app(SnappPayCart::class);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'sp'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
            'phone'    => '0912'.random_int(1000000, 9999999),
        ]);
    }

    /** @param array<int,array{qty:int,unit:int,discount?:int}> $lines */
    private function invoice(array $lines, int $taxRateBp = 0, int $invoiceDiscount = 0): Invoice
    {
        $inv = Invoice::create([
            'customer_id'   => $this->customer()->id,
            'currency_code' => 'IRT',
            'subtotal'      => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status'        => 'unpaid', 'issued_at' => now(),
        ]);

        foreach ($lines as $i => $l) {
            $line = $l['qty'] * $l['unit'];
            $disc = $l['discount'] ?? 0;

            InvoiceItem::create([
                'invoice_id'  => $inv->id,
                'title'       => 'ردیف '.($i + 1),
                'quantity'    => $l['qty'],
                'unit_price'  => $l['unit'],
                'line_total'  => $line,
                'discount'    => $disc,
                'tax_rate_bp' => $taxRateBp,
                'tax_amount'  => intdiv(($line - $disc) * $taxRateBp, 10000),
            ]);
        }

        if ($invoiceDiscount > 0) {
            $inv->discount = $invoiceDiscount;
        }

        $inv->recalculateTotals();
        $inv->save();

        return $inv->fresh(['items']);
    }

    private function payment(Invoice $inv): Payment
    {
        return Payment::create([
            'invoice_id'    => $inv->id,
            'customer_id'   => $inv->customer_id,
            'gateway'       => 'snapppay',
            'currency_code' => 'IRT',
            'amount'        => $inv->total,
            'status'        => 'pending',
        ]);
    }

    private function body(Invoice $inv): array
    {
        return $this->cart()->forToken($inv, $this->payment($inv), '09121110000', 'https://servernet.cloud/x');
    }

    // ───────────────────────────── واحد ─────────────────────────────

    public function test_every_amount_is_sent_in_rial_not_toman(): void
    {
        $inv = $this->invoice([['qty' => 1, 'unit' => 1_000_000]], taxRateBp: 1000);
        $body = $this->body($inv);

        // ۱٬۰۰۰٬۰۰۰ تومان = ۱۰٬۰۰۰٬۰۰۰ ریال
        $this->assertSame(10_000_000, $body['cartList'][0]['cartItems'][0]['amount']);
        $this->assertSame(1_000_000, $body['cartList'][0]['taxAmount'], '۱۰۰٬۰۰۰ تومان مالیات');
        $this->assertSame(11_000_000, $body['cartList'][0]['totalAmount']);
        $this->assertSame(11_000_000, $body['amount']);
        $this->assertSame(1_100_000, $inv->total, 'و فاکتور تومانی دست‌نخورده می‌مانَد');
    }

    // ─────────────────────────── فرمولِ اسنپ‌پی ───────────────────────────

    public function test_the_cart_total_is_items_plus_tax(): void
    {
        $inv = $this->invoice([
            ['qty' => 2, 'unit' => 300_000],
            ['qty' => 1, 'unit' => 400_000],
        ], taxRateBp: 1000);

        $body = $this->body($inv);
        $cart = $body['cartList'][0];

        $items = 0;
        foreach ($cart['cartItems'] as $it) {
            $items += $it['count'] * $it['amount'];
        }

        $this->assertSame($items + $cart['taxAmount'], $cart['totalAmount']);
        $this->assertFalse($cart['isTaxIncluded'], 'مالیات بیرونِ قیمتِ ردیف‌هاست');
        $this->assertTrue($cart['isShipmentIncluded'], 'حمل‌ونقل نداریم');
        $this->assertSame(0, $cart['shippingAmount']);
    }

    public function test_the_order_amount_subtracts_the_discount(): void
    {
        $inv = $this->invoice([['qty' => 1, 'unit' => 1_000_000, 'discount' => 200_000]], taxRateBp: 1000);
        $body = $this->body($inv);

        $this->assertSame(2_000_000, $body['discountAmount'], 'تخفیف جدا می‌رود، نه داخلِ قیمت');
        $this->assertSame(
            $body['cartList'][0]['totalAmount'] - $body['discountAmount'],
            $body['amount'],
            'amount = totalAmount − discount − externalSource',
        );
        $this->assertSame(0, $body['externalSourceAmount']);
    }

    /** 🔴 مبلغِ نهایی باید مو‌به‌مو با فاکتور یکی باشد. */
    public function test_the_order_amount_equals_the_invoice_total(): void
    {
        foreach ([
            [[['qty' => 1, 'unit' => 500_000]], 0, 0],
            [[['qty' => 3, 'unit' => 250_000]], 1000, 0],
            [[['qty' => 2, 'unit' => 400_000, 'discount' => 100_000]], 1000, 0],
            [[['qty' => 1, 'unit' => 900_000]], 900, 150_000],
        ] as [$lines, $rate, $invDiscount]) {
            $inv = $this->invoice($lines, taxRateBp: $rate, invoiceDiscount: $invDiscount);
            $body = $this->body($inv);

            $this->assertSame(
                $inv->total * 10,
                $body['amount'],
                'فاکتور '.$inv->number.' — مبلغِ سبد باید ریالِ همان total باشد',
            );
        }
    }

    // ───────────────────────────── گاردها ─────────────────────────────

    /**
     * ⚠️ اگر صادرکننده‌ای `line_total` را چیزی جز `qty × unit` بگذارد، سبد
     * فاکتور را غلط توصیف می‌کند حتی اگر جمعِ کل تصادفاً درست دربیاید.
     */
    public function test_it_refuses_a_cart_whose_lines_do_not_add_up(): void
    {
        $inv = $this->invoice([['qty' => 1, 'unit' => 100_000]]);

        // line_total دستکاری می‌شود بدونِ آنکه unit_price عوض شود
        $inv->items()->first()->forceFill(['line_total' => 250_000])->save();
        $inv->refresh();
        $inv->recalculateTotals();
        $inv->save();

        $this->expectException(\RuntimeException::class);
        $this->body($inv->fresh(['items']));
    }

    public function test_it_refuses_a_cart_that_does_not_match_the_invoice_total(): void
    {
        $inv = $this->invoice([['qty' => 1, 'unit' => 100_000]]);

        // total دستی خراب می‌شود — چیزی که هیچ مسیرِ سالمی نمی‌سازد
        $inv->forceFill(['total' => 999_999])->save();

        $this->expectException(\RuntimeException::class);
        $this->body($inv->fresh(['items']));
    }

    public function test_it_refuses_an_invoice_with_no_lines(): void
    {
        $inv = Invoice::create([
            'customer_id'   => $this->customer()->id,
            'currency_code' => 'IRT',
            'subtotal'      => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status'        => 'unpaid', 'issued_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->body($inv);
    }

    // ─────────────────────────── شناسهٔ تراکنش ───────────────────────────

    /** مستندات: بین ۵ تا ۱۰ کاراکتر، و برای بیش از ۱۰ رقم حتماً یک حرف. */
    public function test_the_transaction_id_fits_snapppays_format(): void
    {
        $inv = $this->invoice([['qty' => 1, 'unit' => 100_000]]);

        foreach ([1, 42, 999_999, 2_000_000_000] as $id) {
            $p = $this->payment($inv);
            $p->forceFill(['id' => $id])->save();

            $tid = $this->cart()->transactionId($p);

            $this->assertGreaterThanOrEqual(5, strlen($tid), "شناسهٔ «{$tid}» کوتاه است");
            $this->assertLessThanOrEqual(10, strlen($tid), "شناسهٔ «{$tid}» بلند است");
            $this->assertMatchesRegularExpression('/[A-Z]/', $tid, 'باید حرف داشته باشد');
        }
    }

    public function test_two_payments_never_share_a_transaction_id(): void
    {
        $inv = $this->invoice([['qty' => 1, 'unit' => 100_000]]);

        $a = $this->cart()->transactionId($this->payment($inv));
        $b = $this->cart()->transactionId($this->payment($inv));

        $this->assertNotSame($a, $b, 'هر تلاشِ پرداخت شناسهٔ خودش را می‌گیرد');
    }

    // ───────────────────────────── موبایل ─────────────────────────────

    public function test_every_mobile_shape_becomes_one(): void
    {
        foreach (['09121110000', '9121110000', '989121110000', '+98 912 111 0000', '۰۹۱۲۱۱۱۰۰۰۰'] as $raw) {
            $this->assertSame('09121110000', $this->cart()->normalizeMobile($raw), "ورودی: {$raw}");
        }
    }
}
