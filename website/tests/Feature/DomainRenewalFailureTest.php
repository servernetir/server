<?php

namespace Tests\Feature;

use App\Console\Commands\ResolveStuckDomains;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تمدیدِ پرداخت‌شده‌ای که شکست می‌خورد — «دو بار پول، صفر تمدید» ممنوع.
 *
 * ═══ باگِ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 تمدیدِ شکست‌خورده (`failRenewal` پس از ۵ تلاش) دامنهٔ **فعال** را در
 * `manual` پارک می‌کرد و بعد:
 *
 *   ۱) `domains:resolve-stuck` فقط `status='pending'` را می‌دید → نه بازگشتِ
 *      وجه، نه هیچ تعیین تکلیفی — پولِ مشتری در برزخ.
 *   ۲) `domains:lifecycle` چون فاکتورِ «باز»ی نمی‌دید، فردا یک فاکتورِ
 *      تمدیدِ **دوم** صادر می‌کرد. مشتری می‌پرداخت و پرداختِ دوم هم دامنهٔ
 *      `manual` را از صف بیرون نمی‌آورد: دو بار پول، صفر تمدید، هیچ هشداری.
 *
 * این فایل هر دو در را می‌بندد.
 */
class DomainRenewalFailureTest extends TestCase
{
    use RefreshDatabase;

    private const PAID = 2_750_000;

    protected function setUp(): void
    {
        parent::setUp();

        // هیچ تماسِ واقعی‌ای در این مسیر نباید برود
        Http::fake(['*' => Http::response([], 500)]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'rf'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** دامنهٔ فعال با تمدیدِ شکست‌خورده + فاکتورِ تمدیدِ پرداخت‌شده و نشان‌شده */
    private function failedRenewal(int $hoursAgo = 25, bool $stamped = true): Domain
    {
        $c = $this->customer();

        $d = Domain::create([
            'customer_id'      => $c->id,
            'domain'           => 'rf'.random_int(1000, 99999).'.com',
            'sld'              => 'x', 'tld' => 'com',
            'status'           => 'active',
            'provision_status' => 'manual',
            'provision_tries'  => 5,
            'provision_error'  => 'تمدید: خطای رجیسترار',
            'period_years'     => 1,
            'price_toman'      => 2_000_000,
            'renew_toman'      => 2_500_000,
            'op_id'            => 777,
            'expires_at'       => now()->addDays(9),
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 2_500_000, 'tax' => 250_000,
            'total' => self::PAID, 'paid' => self::PAID, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(),
        ]);

        if ($stamped) {
            $d->putMeta(['renew_invoice_id' => $inv->id, 'renew_years' => 1]);
        }

        // ⚠️ آخر از همه، وگرنه ذخیره‌های بالا عقربه را به «الان» برمی‌گردانند
        $d->forceFill(['updated_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $d->refresh();
    }

    // ═══════════════ درِ اول: فاکتورِ دوم ═══════════════

    public function test_lifecycle_never_invoices_while_a_renewal_is_in_flight_or_failed(): void
    {
        foreach (['pending', 'running', 'manual'] as $status) {
            $c = $this->customer();
            $d = Domain::create([
                'customer_id' => $c->id,
                'domain' => 'lf'.random_int(1000, 99999).'.com',
                'sld' => 'x', 'tld' => 'com',
                'status' => 'active', 'provision_status' => $status,
                'period_years' => 1, 'renew_toman' => 2_500_000,
                'expires_at' => now()->addDays(10),
            ]);

            $this->artisan('domains:lifecycle')->assertExitCode(0);

            $this->assertSame(0, Invoice::where('domain_id', $d->id)->count(),
                "در provision_status={$status} نباید فاکتورِ تمدیدِ تازه صادر شود");
        }
    }

    // ═══════════════ درِ دوم: پولِ در برزخ ═══════════════

    public function test_a_failed_renewal_is_refunded_after_the_grace_period(): void
    {
        $d = $this->failedRenewal(25);

        $this->artisan('domains:resolve-stuck')->assertExitCode(0);

        $entry = CreditEntry::where('customer_id', $d->customer_id)
            ->where('reason', ResolveStuckDomains::RENEW_REFUND_REASON)
            ->first();

        $this->assertNotNull($entry, 'وجهِ تمدیدِ شکست‌خورده به اعتبار برنگشت');
        $this->assertSame(self::PAID, (int) $entry->amount,
            'مبلغِ بازگشتی باید کلِ پرداختیِ فاکتورِ تمدید باشد (با مالیات)');

        $d->refresh();
        $this->assertSame('refunded', Invoice::where('domain_id', $d->id)->first()->status);

        // 🔴 دامنه زنده می‌مانَد — تمدیدِ شکست‌خورده لغوِ دامنه نیست
        $this->assertSame('active', $d->status);
        $this->assertSame('done', $d->provision_status,
            'دامنه باید به حالتِ عادی برگردد تا چرخهٔ عمر دوباره یادآوری بفرستد');
        $this->assertNull($d->meta['renew_invoice_id'] ?? null);
    }

    public function test_running_twice_does_not_refund_twice(): void
    {
        $d = $this->failedRenewal(25);

        $this->artisan('domains:resolve-stuck')->assertExitCode(0);
        $this->artisan('domains:resolve-stuck')->assertExitCode(0);

        $this->assertSame(1, CreditEntry::where('customer_id', $d->customer_id)
            ->where('reason', ResolveStuckDomains::RENEW_REFUND_REASON)
            ->count(), 'اجرای ساعتیِ فرمان نباید هر بار پول برگرداند');
    }

    public function test_a_failed_renewal_inside_the_grace_period_waits(): void
    {
        $d = $this->failedRenewal(2);

        $this->artisan('domains:resolve-stuck')->assertExitCode(0);

        $d->refresh();
        $this->assertSame('manual', $d->provision_status, 'در مهلت نباید دست بخورد');
        $this->assertSame(0, CreditEntry::where('customer_id', $d->customer_id)->count());
    }

    /**
     * 🔴 مهم‌ترین محافظِ این فایل: بدونِ نشانِ `renew_invoice_id`، هیچ فاکتوری
     * برنمی‌گردد. «آخرین فاکتورِ پرداخت‌شده» ممکن است فاکتورِ **ثبتِ** سالِ
     * پیش باشد — برگرداندنش یعنی پولِ دامنه‌ای که مشتری دارد و استفاده می‌کند.
     */
    public function test_an_unstamped_failed_renewal_is_left_for_a_human(): void
    {
        $d = $this->failedRenewal(25, stamped: false);

        $this->artisan('domains:resolve-stuck')->assertExitCode(0);

        $d->refresh();
        $this->assertSame('manual', $d->provision_status);
        $this->assertSame('paid', Invoice::where('domain_id', $d->id)->first()->status,
            'فاکتورِ بی‌نشان نباید برگردد — شاید فاکتورِ ثبت باشد');
        $this->assertSame(0, CreditEntry::where('customer_id', $d->customer_id)->count());
    }

    /** رفتارِ صفِ ثبت (status=pending) نباید با افزودنِ صفِ تمدید عوض شده باشد */
    public function test_the_registration_queue_is_untouched_by_the_renewal_branch(): void
    {
        $c = $this->customer();

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'rg'.random_int(1000, 99999).'.com',
            'sld' => 'x', 'tld' => 'com',
            'status' => 'pending', 'provision_status' => 'manual',
            'provision_tries' => 3, 'period_years' => 1, 'price_toman' => 190000,
            'provision_error' => 'قرارداد امضا نشده',
        ]);
        $d->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('domains:resolve-stuck')->assertExitCode(0);

        // در مهلت است؛ نه لغو شده نه وجهی برگشته
        $this->assertSame('pending', $d->refresh()->status);
        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)
            ->where('reason', ResolveStuckDomains::RENEW_REFUND_REASON)->count());
    }
}
