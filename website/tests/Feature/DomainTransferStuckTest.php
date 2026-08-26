<?php

namespace Tests\Feature;

use App\Console\Commands\ResolveStuckDomains;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Support\PanelSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * انتقالِ دامنه دیگر بن‌بست ندارد.
 *
 * ═══ باگِ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 بعد از پرداختِ فاکتورِ انتقال، پیام می‌گفت «کدِ انتقال (EPP) را در همان
 * صفحهٔ دامنه وارد کنید» — ولی آن فرم **هرگز ساخته نشده بود**. روتش بود،
 * ویویش نبود. صفحهٔ دامنه به ردیفِ انتقال می‌گفت «در صف ثبت است» (دروغ)،
 * فهرست «نامشخص» نشان می‌داد، هیچ کرونی ردیفِ pending را نمی‌دید، و
 * resolve-stuck هم با فیلترِ status='pending' هرگز نمی‌دیدش.
 *
 * یعنی: پول گرفته، هیچ راهِ پیش‌روی، هیچ timeout، هیچ رفاند — برای همیشه.
 *
 * این فایل چهار در را قفل می‌کند: فرمِ EPP در صفحه، برچسبِ درستِ وضعیت،
 * مهلت+رفاندِ انتقالِ رهاشده، و رفاندِ کاملِ با مالیات.
 */
class DomainTransferStuckTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 190_000;

    private const PAID = 209_000;      // با مالیات

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 500)]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'tr'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** سفارشِ انتقال + فاکتور (پرداخت‌شده یا نه) */
    private function transfer(bool $paid, array $over = []): Domain
    {
        $c = $this->customer();

        $d = Domain::create(array_merge([
            'customer_id'      => $c->id,
            'domain'           => 'tr'.random_int(1000, 99999).'.com',
            'sld'              => 'x', 'tld' => 'com',
            'registrar'        => 'openprovider',
            'order_type'       => 'transfer',
            'status'           => Domain::STATUS_TRANSFERRING,
            'transfer_status'  => 'pending',
            'provision_status' => $paid ? 'pending' : 'none',
            'period_years'     => 1,
            'price_toman'      => self::PRICE,
        ], $over));

        Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => self::PRICE, 'tax' => 19_000,
            'total' => self::PAID, 'paid' => $paid ? self::PAID : 0,
            'status' => $paid ? 'paid' : 'unpaid',
            'issued_at' => now(), 'paid_at' => $paid ? now() : null,
        ]);

        return $d->refresh();
    }

    private function age(Domain $d, int $hours): Domain
    {
        $d->forceFill(['updated_at' => now()->subHours($hours)])->saveQuietly();

        return $d->refresh();
    }

    // ═══════════════ صفحهٔ دامنه — فرمی که وجود نداشت ═══════════════

    public function test_a_paid_transfer_shows_the_epp_form_not_the_registration_message(): void
    {
        $d = $this->transfer(paid: true);

        $res = $this->actingAs($d->customer, 'customer')
            ->get('/account/domains/'.$d->id);

        $res->assertOk()
            ->assertSee('شروع انتقال')
            ->assertSee('auth_code', escape: false)
            ->assertDontSee('در صف ثبت است');
    }

    public function test_an_unpaid_transfer_links_to_its_invoice_instead_of_the_form(): void
    {
        $d = $this->transfer(paid: false);

        $this->actingAs($d->customer, 'customer')
            ->get('/account/domains/'.$d->id)
            ->assertOk()
            ->assertSee('پرداخت فاکتور انتقال')
            ->assertDontSee('شروع انتقال');
    }

    public function test_a_submitted_transfer_shows_the_waiting_message(): void
    {
        $d = $this->transfer(paid: true, over: ['transfer_status' => 'submitted']);

        $this->actingAs($d->customer, 'customer')
            ->get('/account/domains/'.$d->id)
            ->assertOk()
            ->assertSee('در انتظارِ تأییدِ رجیسترارِ فعلی');
    }

    // ═══════════════ برچسبِ وضعیت — «نامشخص» ممنوع ═══════════════

    public function test_the_panel_state_names_every_transfer_stage(): void
    {
        $cases = [
            'pending'   => 'dmn_state_transfer_epp',
            'submitted' => 'dmn_state_transfer_wait',
            'failed'    => 'dmn_state_transfer_failed',
        ];

        foreach ($cases as $status => $key) {
            $d = $this->transfer(paid: true, over: ['transfer_status' => $status]);

            $this->assertSame($key, PanelSections::domainState($d)[1],
                "انتقال با transfer_status={$status} باید برچسبِ خودش را بگیرد، نه «نامشخص»");
        }
    }

    // ═══════════════ مهلت و بازگشتِ پول ═══════════════

    /** 🔴 قلبِ باگ: پرداخت‌شده، کدِ EPP هرگز وارد نشده — نباید تا ابد بماند */
    public function test_a_paid_transfer_with_no_epp_for_a_week_is_cancelled_and_refunded(): void
    {
        $d = $this->age($this->transfer(paid: true), 8 * 24);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $d->refresh();
        $this->assertSame('cancelled', $d->status, 'انتقالِ رهاشده باید بعد از مهلت بسته شود');
        $this->assertSame('rejected', $d->transfer_status);

        $entry = CreditEntry::where('customer_id', $d->customer_id)
            ->where('reason', 'domain_transfer_refund')->first();

        $this->assertNotNull($entry, 'پولِ انتقالِ رهاشده برنگشت');
        $this->assertSame(self::PAID, (int) $entry->amount,
            'رفاند باید کلِ پرداختی (با مالیات) باشد، نه price_toman بی‌مالیات');

        $this->assertSame('refunded', Invoice::where('domain_id', $d->id)->first()->status);
    }

    public function test_a_recent_paid_transfer_is_given_time(): void
    {
        $d = $this->age($this->transfer(paid: true), 2 * 24);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(Domain::STATUS_TRANSFERRING, $d->refresh()->status,
            'مشتری هنوز در مهلتِ واردکردنِ کد است');
        $this->assertSame(0, CreditEntry::count());
    }

    /** پیش‌فاکتورِ پرداخت‌نشده کارِ invoices:expire-orders است، نه این فرمان */
    public function test_an_unpaid_transfer_is_not_touched_by_the_stuck_command(): void
    {
        $d = $this->age($this->transfer(paid: false), 20 * 24);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(Domain::STATUS_TRANSFERRING, $d->refresh()->status);
        $this->assertSame(0, CreditEntry::count(), 'برای فاکتورِ پرداخت‌نشده پول ساخته شد');
    }

    public function test_a_failed_submit_is_refunded_after_the_manual_grace(): void
    {
        $d = $this->age($this->transfer(paid: true, over: [
            'transfer_status' => 'failed', 'provision_status' => 'manual',
        ]), 25);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $d->refresh();
        $this->assertSame('cancelled', $d->status);
        $this->assertSame(1, CreditEntry::where('reason', 'domain_transfer_refund')->count());
    }

    public function test_running_twice_never_refunds_twice(): void
    {
        $d = $this->age($this->transfer(paid: true), 8 * 24);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();
        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(1, CreditEntry::where('customer_id', $d->customer_id)
            ->where('reason', 'domain_transfer_refund')->count());
    }

    // ═══════════════ پیش‌فاکتورِ رهاشده نامِ دامنه را نمی‌سوزاند ═══════════════

    public function test_an_expired_unpaid_transfer_invoice_frees_the_domain_name(): void
    {
        $d = $this->transfer(paid: false);
        Invoice::where('domain_id', $d->id)
            ->update(['created_at' => now()->subHours(100)]);

        $this->artisan('invoices:expire-orders')->assertSuccessful();

        $this->assertSame('cancelled', $d->refresh()->status,
            'پیش‌فاکتورِ انتقالِ رهاشده باید نامِ دامنه را آزاد کند — قیدِ یکتایی آن را برای همیشه قفل می‌کرد');
    }
}
