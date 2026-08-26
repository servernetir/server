<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Domain\DomainRegistrar;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * بازیابیِ دامنهٔ منقضی (redemption) — مسیرِ نجاتی که وجود نداشت.
 *
 * ═══ بن‌بستِ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 دامنهٔ `expired` از پنل غیب می‌شد (`alive()`)، صفِ تمدید نمی‌گرفتش
 * (`status='active'` می‌خواهد)، و «مسیرِ نجات» فقط یک جملهٔ «با پشتیبانی
 * تماس بگیرید» بود — دقیقاً در پنجره‌ای که رجیستری هنوز بازیابی را
 * می‌پذیرد و هر روز تأخیر شانس را کم می‌کند.
 *
 * قاعده‌های قفل‌شده: صفِ بازیابی روی `expired` سوار است (بی‌اشتراک با ثبت
 * و تمدید)؛ بدونِ کارمزدِ پیکربندی‌شده مسیر بسته می‌مانَد (نجاتِ زیرِ قیمت
 * ممنوع)؛ شکستِ قطعی = رفاندِ خودکارِ فاکتورِ نشان‌شده.
 */
class DomainRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const FEE = 500_000;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 500)]);
        Setting::put('domain_restore_fee_toman', (string) self::FEE);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'rs'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function expired(array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id'      => $this->customer()->id,
            'domain'           => 'rs'.random_int(1000, 99999).'.com',
            'sld'              => 'x', 'tld' => 'com',
            'status'           => 'expired',
            'provision_status' => 'done',
            'period_years'     => 1,
            'renew_toman'      => 2_000_000,
            'op_id'            => 777,
            'expires_at'       => now()->subDays(40),
        ], $over));
    }

    private function pay(Invoice $inv): void
    {
        $payment = Payment::create([
            'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => (int) $inv->total,
            'status' => 'redirected', 'external_ref' => 'X'.random_int(1000, 99999),
        ]);

        app(PaymentService::class)->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));
    }

    // ═══════════════ فاکتورِ بازیابی ═══════════════

    public function test_the_restore_button_issues_a_priced_invoice(): void
    {
        $d = $this->expired();

        $this->actingAs($d->customer, 'customer')
            ->post('/account/domains/'.$d->id.'/restore')
            ->assertRedirect();

        $inv = Invoice::where('domain_id', $d->id)->firstOrFail();

        $this->assertSame(2_000_000 + self::FEE, (int) $inv->subtotal,
            'قیمتِ نجات = تمدیدِ مؤثر + کارمزدِ بازیابی');
        $this->assertSame($inv->id, (int) ($d->fresh()->meta['restore_invoice_id'] ?? 0),
            'فاکتور نشان نشد — رفاندِ هدفمند کور می‌شود');
    }

    /** بدونِ کارمزدِ پیکربندی‌شده، مسیر عمداً بسته است — نجاتِ زیرِ قیمت ممنوع */
    public function test_without_a_configured_fee_the_path_stays_closed(): void
    {
        Setting::put('domain_restore_fee_toman', null);
        $d = $this->expired();

        $this->actingAs($d->customer, 'customer')
            ->post('/account/domains/'.$d->id.'/restore')
            ->assertSessionHasErrors();

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    public function test_an_active_domain_cannot_use_the_restore_path(): void
    {
        $d = $this->expired(['status' => 'active']);

        $this->actingAs($d->customer, 'customer')
            ->post('/account/domains/'.$d->id.'/restore')
            ->assertSessionHasErrors();
    }

    // ═══════════════ صف‌ها ═══════════════

    public function test_paying_the_restore_invoice_queues_only_the_restore_queue(): void
    {
        $d = $this->expired();

        $this->actingAs($d->customer, 'customer')
            ->post('/account/domains/'.$d->id.'/restore');

        $this->pay(Invoice::where('domain_id', $d->id)->firstOrFail());

        $this->assertSame('pending', $d->fresh()->provision_status);

        $this->assertTrue(Domain::awaitingRestore()->whereKey($d->id)->exists());

        // 🔴 جداییِ صف‌ها — درسِ ثبت‌شدهٔ CLAUDE.md: هم‌پوشانی یعنی خریدِ دوباره
        $this->assertFalse(Domain::awaitingRenewal()->whereKey($d->id)->exists(),
            'بازیابی در صفِ تمدید افتاد');
        $this->assertFalse(Domain::awaitingRegistration()->whereKey($d->id)->exists(),
            'بازیابی در صفِ ثبت افتاد — دامنه دوباره خریده می‌شود');
    }

    // ═══════════════ اجرای بازیابی ═══════════════

    private function fakeRegistrar(bool $restoreOk): void
    {
        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'   => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/restore*'      => $restoreOk
                ? Http::response(['code' => 0, 'data' => []])
                : Http::response(['code' => 399, 'desc' => 'restore not possible'], 500),
            '*/domains/777*'  => Http::response(['code' => 0, 'data' => [
                'id' => 777, 'expiration_date' => now()->addYear()->format('Y-m-d H:i:s'),
            ]]),
            '*'               => Http::response([], 500),
        ]);
    }

    public function test_a_successful_restore_brings_the_domain_back_to_life(): void
    {
        $this->fakeRegistrar(restoreOk: true);

        $d = $this->expired(['provision_status' => 'pending']);
        $d->putMeta(['restore_invoice_id' => 123]);

        $res = app(DomainRegistrar::class)->restorePaid($d);

        $this->assertTrue($res['ok'], $res['message']);
        $d->refresh();
        $this->assertSame('active', $d->status, 'دامنهٔ نجات‌یافته باید به پنل برگردد');
        $this->assertSame('done', $d->provision_status);
        $this->assertTrue($d->expires_at->isFuture());
        $this->assertNull($d->meta['restore_invoice_id'] ?? null);
    }

    public function test_a_refused_restore_escalates_to_a_human_and_stays_expired(): void
    {
        $this->fakeRegistrar(restoreOk: false);

        $d = $this->expired(['provision_status' => 'pending', 'provision_tries' => 2]);

        $res = app(DomainRegistrar::class)->restorePaid($d);

        $this->assertFalse($res['ok']);
        $d->refresh();
        $this->assertSame('manual', $d->provision_status, 'بعد از ۳ تلاش، تصمیم با آدم');
        $this->assertSame('expired', $d->status,
            'شکستِ بازیابی نباید دامنه را «فعال» جا بزند');
    }

    // ═══════════════ رفاندِ شکستِ قطعی ═══════════════

    public function test_a_dead_restore_is_refunded_after_the_grace_period(): void
    {
        $d = $this->expired(['provision_status' => 'manual', 'provision_error' => 'بازیابی: رد شد']);

        $inv = Invoice::create([
            'customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 2_500_000, 'tax' => 250_000,
            'total' => 2_750_000, 'paid' => 2_750_000, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(),
        ]);
        $d->putMeta(['restore_invoice_id' => $inv->id]);
        $d->forceFill(['updated_at' => now()->subHours(30)])->saveQuietly();

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(2_750_000,
            (int) CreditEntry::where('customer_id', $d->customer_id)->sum('amount'),
            'پولِ نجاتِ ناموفق باید برگردد — قاعدهٔ «یا انجام، یا بازگشتِ پول»');
        $this->assertSame('refunded', $inv->fresh()->status);

        $d->refresh();
        $this->assertSame('expired', $d->status);
        $this->assertSame('done', $d->provision_status);
    }
}
