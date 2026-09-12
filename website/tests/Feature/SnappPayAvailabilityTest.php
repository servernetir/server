<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Payment\SnappPay\SnappPayAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * چه کسی درگاهِ اقساطی را می‌بیند — و چه کسی نه.
 *
 * ═══ قاعده‌ای که اسنپ‌پی در بازبینی می‌سنجد ═══
 *
 * 🔴 «از هر گونه پیاده‌سازی دستی سمتِ خود خودداری فرمایید و حتماً سرویس
 * eligible را درست پیاده‌سازی کنید. در صورت true بودن، تایتل و دیسکریپشن که
 * در جواب بازگردانده می‌شود **بدونِ هیچ‌گونه تغییری** نمایش داده شود و اگر
 * جواب برگشتی false بود روش پرداخت اقساطی نمایش داده نشود.»
 *
 * پس دو چیز تست می‌شود: متن از آن‌ها می‌آید، و «نه» واقعاً یعنی ناپیدا.
 */
class SnappPayAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snapppay.enabled'       => true,
            'snapppay.base_url'      => 'https://snapppay.test',
            'snapppay.client_id'     => 'cid',
            'snapppay.client_secret' => 'secret',
            'snapppay.username'      => 'user',
            'snapppay.password'      => 'pass',
            'snapppay.test_emails'   => [],
        ]);

        app()->setLocale('fa');
    }

    private function fakeEligible(bool $eligible = true): void
    {
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'jwt', 'expires_in' => 3600]),
            '*offer/v1/eligible*' => Http::response([
                'successful' => true,
                'response'   => [
                    'eligible'      => $eligible,
                    'title_message' => 'خرید اقساطی اسنپ‌پی',
                    'description'   => 'تا ۴ قسط بدون بهره',
                ],
            ]),
        ]);
    }

    private function customer(string $email = 'c@example.com'): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999), 'email' => $email,
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);
    }

    private function invoice(?Customer $c = null, string $kind = 'service', string $cur = 'IRT'): Invoice
    {
        $c ??= $this->customer();

        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => $kind, 'currency_code' => $cur,
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id, 'title' => 'سرور', 'quantity' => 1,
            'unit_price' => 500_000, 'line_total' => 500_000,
            'discount' => 0, 'tax_rate_bp' => 0, 'tax_amount' => 0,
        ]);

        $inv->recalculateTotals();
        $inv->save();

        return $inv->fresh(['items']);
    }

    private function check(Invoice $inv, ?Customer $c = null): array
    {
        return app(SnappPayAvailability::class)->forInvoice($inv, $c ?? $inv->customer);
    }

    // ───────────────────────────────────────────────────────────────

    /** 🔴 متنِ دکمه ساختهٔ ما نیست — عیناً همان چیزی که اسنپ‌پی داد. */
    public function test_the_title_and_description_come_from_snapppay(): void
    {
        $this->fakeEligible();

        $out = $this->check($this->invoice());

        $this->assertTrue($out['show']);
        $this->assertSame('خرید اقساطی اسنپ‌پی', $out['title']);
        $this->assertSame('تا ۴ قسط بدون بهره', $out['description']);
    }

    /** 🔴 «نه» یعنی ناپیدا — نه غیرفعال، نه با پیامِ «در دسترس نیست». */
    public function test_an_ineligible_amount_hides_the_method(): void
    {
        $this->fakeEligible(false);

        $out = $this->check($this->invoice());

        $this->assertFalse($out['show']);
        $this->assertSame('not-eligible', $out['reason']);
    }

    /**
     * ⚠️ تماسِ ناموفق «نمی‌دانیم» است و باید مثلِ «نه» رفتار کند: دکمه‌ای که
     * بعداً سرِ گرفتنِ توکن رد شود، از نبودنش بدتر است.
     */
    public function test_an_unreachable_snapppay_hides_the_method(): void
    {
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'jwt', 'expires_in' => 3600]),
            '*offer/v1/eligible*' => Http::response([], 500),
        ]);

        $this->assertFalse($this->check($this->invoice())['show']);
    }

    /**
     * و پاسخِ منفی کش نمی‌شود، وگرنه یک قطعیِ گذرا درگاه را پنج دقیقه می‌خواباند.
     *
     * ⚠️ `Http::sequence` و نه دو بار `Http::fake`: فراخوانیِ دومِ fake
     * استاب‌های قبلی را جایگزین نمی‌کند، به آن‌ها **اضافه** می‌شود و اولی
     * برنده می‌مانَد — پس تست همیشه همان پاسخِ اول را می‌گرفت.
     */
    public function test_a_negative_answer_is_not_cached(): void
    {
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'jwt', 'expires_in' => 3600]),
            '*offer/v1/eligible*' => Http::sequence()
                ->push(['successful' => true, 'response' => ['eligible' => false]])
                ->push(['successful' => true, 'response' => [
                    'eligible' => true, 'title_message' => 'اقساطی', 'description' => 'چهار قسط',
                ]]),
        ]);

        $inv = $this->invoice();

        $this->assertFalse($this->check($inv)['show']);
        $this->assertTrue($this->check($inv)['show'], 'پاسخِ تازه باید دیده شود، نه کشِ منفی');
    }

    /** ولی پاسخِ مثبت کش می‌شود — وگرنه هر بازِ صفحه یک تماسِ شبکه‌ای است. */
    public function test_a_positive_answer_is_cached_per_amount(): void
    {
        $this->fakeEligible();
        $inv = $this->invoice();

        $this->check($inv);
        $this->check($inv);

        $calls = 0;
        Http::assertSent(function ($r) use (&$calls) {
            if (str_contains($r->url(), 'offer/v1/eligible')) {
                $calls++;
            }

            return true;
        });

        $this->assertSame(1, $calls, 'مبلغ عوض نشده، پس یک تماس بس است');
    }

    // ─────────────────────── گیت‌های محلی ───────────────────────

    /** تصمیمِ کارفرما: «تمام خریدهایی که با زبان فارسی انجام می‌شود». */
    public function test_a_non_persian_visitor_never_sees_it(): void
    {
        $this->fakeEligible();
        app()->setLocale('en');

        $out = $this->check($this->invoice());

        $this->assertFalse($out['show']);
        $this->assertSame('locale', $out['reason']);
        Http::assertNothingSent();   // حتی زنگ هم نمی‌زنیم
    }

    public function test_a_non_toman_invoice_is_out(): void
    {
        $this->fakeEligible();

        $out = $this->check($this->invoice(cur: 'EUR'));

        $this->assertFalse($out['show']);
        $this->assertSame('currency', $out['reason']);
    }

    /** ⚠️ خریدِ اعتبار با اقساط عملاً وام‌دادنِ نقد است. */
    public function test_a_wallet_topup_is_out_by_default(): void
    {
        $this->fakeEligible();

        $out = $this->check($this->invoice(kind: 'topup'));

        $this->assertFalse($out['show']);
        $this->assertSame('topup', $out['reason']);
    }

    public function test_a_topup_can_be_allowed_by_one_flag(): void
    {
        $this->fakeEligible();
        config(['snapppay.allow_topup' => true]);

        $this->assertTrue($this->check($this->invoice(kind: 'topup'))['show']);
    }

    public function test_a_partly_paid_invoice_is_out(): void
    {
        $this->fakeEligible();
        $inv = $this->invoice();
        $inv->forceFill(['paid' => 100_000])->save();

        $out = $this->check($inv->fresh(['items']));

        $this->assertFalse($out['show']);
        $this->assertSame('not-payable', $out['reason']);
    }

    /**
     * 🔴 چون استیجینگ روی همان سرورِ پروداکشن است، این فهرست تنها چیزی است
     * که بین تستِ ما و مشتریِ واقعی می‌ایستد.
     */
    public function test_only_the_listed_testers_see_it_while_testing(): void
    {
        $this->fakeEligible();
        config(['snapppay.test_emails' => ['tester@servernet.cloud']]);

        $stranger = $this->customer('stranger@example.com');
        $out = $this->check($this->invoice($stranger), $stranger);
        $this->assertFalse($out['show']);
        $this->assertSame('not-tester', $out['reason']);

        $tester = $this->customer('tester@servernet.cloud');
        $this->assertTrue($this->check($this->invoice($tester), $tester)['show']);
    }

    public function test_a_disabled_gateway_is_out(): void
    {
        $this->fakeEligible();
        config(['snapppay.enabled' => false]);

        $this->assertFalse($this->check($this->invoice())['show']);
        Http::assertNothingSent();
    }

    // ─────────────────── گاردِ سمتِ سرور ───────────────────

    /**
     * 🔴 پنهان‌کردنِ کارت کسی را متوقف نمی‌کند که فرم را دستی POST کند.
     * «در دسترس نبودن» یعنی سرور هم قبول نکند.
     */
    public function test_posting_the_form_directly_is_refused_when_ineligible(): void
    {
        $this->fakeEligible(false);
        $c = $this->customer();
        $inv = $this->invoice($c);

        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/pay', ['gateway' => 'snapppay'])
            ->assertSessionHasErrors('gateway');

        $this->assertSame(0, \App\Models\SnappPayOrder::count());
    }
}
