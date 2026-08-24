<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تمدیدِ دستی از پنلِ مشتری — دکمه‌ای که وجود نداشت.
 *
 * ═══ شکافی که این تست از آن آمد ═══
 *
 * 🔴 ممیزیِ شهریور ۱۴۰۵: تنها مسیرِ تمدید، فاکتورِ خودکارِ `domains:lifecycle`
 * در ۲۱ روزِ آخر بود. مشتری‌ای که می‌خواست **زودتر** یا **چندساله** تمدید کند
 * هیچ راهی نداشت — کامنتِ کرون می‌گفت «چندساله را مشتری دستی می‌خرد» ولی آن
 * مسیرِ دستی هرگز ساخته نشده بود. صفحهٔ فروش هم‌زمان قول می‌داد «دامنه هرگز
 * منقضی نشود».
 *
 * قاعده‌های قفل‌شده در این فایل:
 *   • فاکتورِ باز بازمصرف می‌شود — دو کلیک، دو فاکتور نمی‌سازد.
 *   • تمدیدِ در جریان (pending/running) یا شکست‌خورده (manual) فاکتورِ
 *     تازه نمی‌گیرد — جلوی «دو بار پول، صفر تمدید».
 *   • فقط دامنهٔ فعالِ خودِ مشتری.
 */
class DomainRenewNowTest extends TestCase
{
    use RefreshDatabase;

    private const RENEW = 2_500_000;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'rn'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function domain(array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id'      => $this->customer()->id,
            'domain'           => 'rn'.random_int(1000, 99999).'.com',
            'sld'              => 'x', 'tld' => 'com',
            'status'           => 'active',
            'provision_status' => 'done',
            'period_years'     => 1,
            'price_toman'      => 2_000_000,
            'renew_toman'      => self::RENEW,
            'op_id'            => 777,
            'expires_at'       => now()->addMonths(8),
        ], $over));
    }

    private function renew(Domain $d, int $years = 1): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($d->customer, 'customer')
            ->post('/account/domains/'.$d->id.'/renew', ['years' => $years]);
    }

    // ═══════════════ مسیرِ شاد ═══════════════

    public function test_an_active_domain_gets_a_renewal_invoice_for_the_chosen_years(): void
    {
        $d = $this->domain();

        $this->renew($d, 3)->assertRedirect();

        $inv = Invoice::latest('id')->firstOrFail();

        $this->assertSame('domain', $inv->kind);
        $this->assertSame($d->id, (int) $inv->domain_id);
        $this->assertSame(self::RENEW * 3, (int) $inv->subtotal,
            'مبلغِ تمدیدِ چندساله باید renew_toman × سال باشد');

        // 🔴 بعد از پرداخت، کرونِ تمدید از همین متا می‌فهمد چند سال بخرد
        $this->assertSame(3, $d->fresh()->renewYears());
    }

    public function test_the_redirect_lands_on_the_invoice_page(): void
    {
        $d = $this->domain();

        $res = $this->renew($d, 1);
        $inv = Invoice::latest('id')->firstOrFail();

        $res->assertRedirect(route('account.invoice', $inv));
    }

    // ═══════════════ ضدِ فاکتورِ تکراری ═══════════════

    public function test_a_second_click_reuses_the_open_invoice_instead_of_duplicating(): void
    {
        $d = $this->domain();

        $this->renew($d, 2)->assertRedirect();
        $this->renew($d, 3)->assertRedirect();

        $this->assertSame(1, Invoice::where('domain_id', $d->id)->count(),
            'دو کلیک روی «تمدید» دو فاکتور ساخت — مشتری دو بار پول می‌دهد');
    }

    /**
     * 🔴 قلبِ باگِ ممیزی: تمدیدِ پرداخت‌شده‌ای که شکست خورده (`manual`)،
     * نباید فاکتورِ دومی بگیرد — وگرنه «دو بار پول، صفر تمدید».
     */
    public function test_a_manual_renewal_blocks_a_new_invoice(): void
    {
        $d = $this->domain(['provision_status' => 'manual']);

        $this->renew($d)->assertSessionHasErrors();

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    public function test_an_in_flight_renewal_does_not_get_a_second_invoice(): void
    {
        foreach (['pending', 'running'] as $status) {
            $d = $this->domain(['provision_status' => $status]);

            $this->renew($d)->assertRedirect();

            $this->assertSame(0, Invoice::where('domain_id', $d->id)->count(),
                "در provision_status={$status} نباید فاکتورِ تازه صادر شود");
        }
    }

    // ═══════════════ گاردها ═══════════════

    public function test_a_pending_domain_cannot_issue_a_renewal_invoice(): void
    {
        $d = $this->domain(['status' => 'pending', 'provision_status' => 'none']);

        $this->renew($d)->assertSessionHasErrors();

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    public function test_someone_elses_domain_is_a_404(): void
    {
        $d = $this->domain();

        $this->actingAs($this->customer(), 'customer')
            ->post('/account/domains/'.$d->id.'/renew', ['years' => 1])
            ->assertNotFound();

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    public function test_more_than_five_years_is_rejected(): void
    {
        $d = $this->domain();

        $this->renew($d, 8)->assertSessionHasErrors('years');

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    /** دامنه‌ای که هیچ قیمتِ تمدیدی ندارد فاکتورِ صفرتومانی نمی‌گیرد */
    public function test_a_domain_with_no_stored_price_is_refused(): void
    {
        $d = $this->domain(['renew_toman' => null, 'price_toman' => 0]);

        $this->renew($d)->assertSessionHasErrors();

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    // ═══════════════ هم‌نواییِ با کرون ═══════════════

    /**
     * کرونِ چرخهٔ عمر فاکتورِ دستی را «فاکتورِ باز» می‌بیند و دومی نمی‌سازد —
     * وگرنه تمدیدِ زودهنگامِ مشتری، در ۲۱ روزِ آخر یک فاکتورِ موازی می‌گرفت.
     */
    public function test_the_lifecycle_cron_does_not_duplicate_a_manual_renewal_invoice(): void
    {
        $d = $this->domain(['expires_at' => now()->addDays(10)]);

        $this->renew($d, 2)->assertRedirect();
        $this->artisan('domains:lifecycle')->assertExitCode(0);

        $this->assertSame(1, Invoice::where('domain_id', $d->id)->count());
    }
}
