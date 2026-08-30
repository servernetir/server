<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * چرخهٔ تمدیدِ دامنه — و شکافی که ساختنش را لازم کرد.
 *
 * 🔴 ممیزی نشان داد **هیچ کدی در پروژه یک دامنه را تمدید نمی‌کرد**:
 * `DomainRegistrar::renew()` نوشته و تست شده بود ولی هیچ فراخوانی نداشت، و دو
 * کرونِ تمدیدِ موجود فقط جدولِ `services` را می‌خوانند در حالی که خریدِ دامنه
 * اصلاً `Service` نمی‌سازد. یعنی هر دامنهٔ فروخته‌شده یک سال بعد بی‌صدا منقضی
 * می‌شد — و پنل هم‌زمان به مشتری قول می‌داد «پیش از سررسید فاکتور صادر می‌شود».
 */
class DomainRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'r'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function domain(array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id'      => $this->customer()->id,
            'domain'           => 'd'.random_int(1000, 99999).'.com',
            'sld'              => 'x', 'tld' => 'com',
            'status'           => 'active',
            'provision_status' => 'done',
            'period_years'     => 1,
            'price_toman'      => 2_000_000,
            'renew_toman'      => 2_500_000,
            'op_id'            => 777,
            'expires_at'       => now()->addDays(10),
        ], $over));
    }

    // ═══════════════ زمان‌بندی ═══════════════

    /**
     * 🔴 همان تلهٔ سه‌بار-تکرارشده: فرمان نوشته می‌شود، ثبت نمی‌شود، و هیچ
     * اتفاقی نمی‌افتد — بی‌خطا، با کدِ ۲۰۰.
     */
    public function test_both_domain_renewal_commands_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);

        foreach (['domains:lifecycle', 'domains:renew'] as $needle) {
            $this->assertTrue($commands->contains(fn ($c) => str_contains($c, $needle)),
                "{$needle} زمان‌بندی نشده — یعنی هرگز اجرا نمی‌شود");
        }
    }

    // ═══════════════ صدورِ فاکتورِ تمدید ═══════════════

    public function test_it_issues_a_renewal_invoice_inside_the_lead_window(): void
    {
        $d = $this->domain(['expires_at' => now()->addDays(10)]);

        $this->artisan('domains:lifecycle')->assertSuccessful();

        $inv = Invoice::where('domain_id', $d->id)->first();

        $this->assertNotNull($inv, 'فاکتورِ تمدید صادر نشد');
        $this->assertSame('domain', $inv->kind);
        $this->assertStringContainsString('تمدید', (string) $inv->note);
        $this->assertSame(2_500_000, (int) $inv->subtotal, 'مبلغ باید renew_toman باشد نه قیمتِ ثبت');
    }

    /** دورتر از پنجره → هنوز نه. فاکتورِ زودهنگام یعنی مشتری ماه‌ها زودتر پول بدهد */
    public function test_it_does_not_invoice_a_domain_far_from_expiry(): void
    {
        $this->domain(['expires_at' => now()->addDays(200)]);

        $this->artisan('domains:lifecycle')->assertSuccessful();

        $this->assertSame(0, Invoice::count());
    }

    /** ⚠️ idempotent — اجرای روزانه نباید هر روز یک فاکتور تازه بسازد */
    public function test_running_twice_does_not_create_two_invoices(): void
    {
        $this->domain(['expires_at' => now()->addDays(10)]);

        $this->artisan('domains:lifecycle');
        $this->artisan('domains:lifecycle');

        $this->assertSame(1, Invoice::count());
    }

    /**
     * ⚠️ حتی با تمدیدِ خودکارِ **خاموش** هم فاکتور صادر می‌شود.
     *
     * خاموش‌بودن یعنی «خودکار تمدید نکن»، نه «به من نگو دارد منقضی می‌شود».
     * سکوت تصمیم را از مشتری می‌گیرد.
     */
    public function test_it_still_invoices_when_auto_renew_is_off(): void
    {
        $this->domain(['expires_at' => now()->addDays(10), 'auto_renew' => false]);

        $this->artisan('domains:lifecycle');

        $this->assertSame(1, Invoice::count());
    }

    /** دامنهٔ بی‌تاریخِ انقضا (هنوز ثبت‌نشده) نباید فاکتور بگیرد */
    public function test_a_domain_without_an_expiry_date_is_skipped(): void
    {
        $this->domain(['expires_at' => null, 'status' => 'pending', 'provision_status' => 'pending']);

        $this->artisan('domains:lifecycle');

        $this->assertSame(0, Invoice::count());
    }

    // ═══════════════ انقضا ═══════════════

    /** پیش از پایانِ مهلت، دامنه هنوز زنده است — دورهٔ بازیابی هنوز باز است */
    public function test_a_just_expired_domain_stays_active_during_the_grace_period(): void
    {
        $d = $this->domain(['expires_at' => now()->subDays(3)]);

        $this->artisan('domains:lifecycle');

        $this->assertSame('active', $d->fresh()->status);
    }

    public function test_it_marks_a_domain_expired_after_the_grace_period(): void
    {
        $d = $this->domain(['expires_at' => now()->subDays(Domain::EXPIRY_GRACE_DAYS + 2)]);

        $this->artisan('domains:lifecycle');

        $this->assertSame('expired', $d->fresh()->status);
        $this->assertTrue($d->fresh()->isDead());
    }

    /** حالتِ آزمایشی هیچ‌چیز را عوض نمی‌کند */
    public function test_dry_run_changes_nothing(): void
    {
        $d = $this->domain(['expires_at' => now()->addDays(10)]);

        $this->artisan('domains:lifecycle --dry')->assertSuccessful();

        $this->assertSame(0, Invoice::count());
        $this->assertSame('done', $d->fresh()->provision_status);
    }

    // ═══════════════ صفِ تمدید ═══════════════

    /**
     * 🔴 مهم‌ترین تستِ ایمنیِ این فایل.
     *
     * صفِ تمدید و صفِ ثبت باید **بی‌اشتراک** بمانند. اگر روزی کسی شرطِ `status`
     * را از یکی از دو اسکوپ بردارد، `domains:provision` یک تمدید را به‌جای ثبتِ
     * تازه برمی‌دارد و دامنه **دوباره خریده** می‌شود.
     */
    public function test_the_renewal_queue_never_overlaps_the_registration_queue(): void
    {
        $renewing = $this->domain(['status' => 'active', 'provision_status' => 'pending']);
        $registering = $this->domain(['status' => 'pending', 'provision_status' => 'pending', 'expires_at' => null]);

        $renewIds = Domain::query()->awaitingRenewal()->pluck('id')->all();
        $regIds = Domain::query()->awaitingRegistration()->pluck('id')->all();

        $this->assertContains($renewing->id, $renewIds);
        $this->assertContains($registering->id, $regIds);
        $this->assertSame([], array_intersect($renewIds, $regIds),
            'یک دامنه هم‌زمان در صفِ ثبت و صفِ تمدید است — خطرِ خریدِ دوباره');
    }

    /** همان مسیری که بازگشتِ موفقِ درگاه می‌رود (`settleConfirmed` → `applyPaid`) */
    private function pay(Invoice $inv): void
    {
        $payment = \App\Models\Payment::create([
            'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => (int) $inv->total,
            'status' => 'redirected', 'external_ref' => 'X'.random_int(1000, 9999),
        ]);

        app(\App\Services\Payment\PaymentService::class)
            ->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));
    }

    /** پرداختِ فاکتورِ تمدید باید دامنه را به صفِ تمدید ببرد */
    public function test_paying_a_renewal_invoice_queues_the_domain(): void
    {
        $d = $this->domain(['expires_at' => now()->addDays(10)]);

        $this->artisan('domains:lifecycle');
        $this->pay(Invoice::where('domain_id', $d->id)->firstOrFail());

        $this->assertSame('pending', $d->fresh()->provision_status);
        $this->assertContains($d->id, Domain::query()->awaitingRenewal()->pluck('id')->all());
    }

    /**
     * ⚠️ پرداختِ تمدید نباید یک دامنهٔ **در حالِ ثبت** را از قفلش بیرون بکشد —
     * همان درسی که مسیرِ ثبت داد: قفلِ بازشده یعنی دو اجرا هم‌زمان تمدید
     * می‌کنند و دامنه **دو سال** تمدید می‌شود، که یک سالش از جیبِ ماست.
     */
    public function test_a_second_payment_does_not_break_an_in_flight_lock(): void
    {
        $d = $this->domain(['provision_status' => 'running']);

        $this->pay(Invoice::create([
            'customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 100, 'tax' => 0, 'total' => 100,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]));

        $this->assertSame('running', $d->fresh()->provision_status,
            'قفلِ در جریان باز شد — اجرای بعدی همان دامنه را دوباره تمدید می‌کند');
    }

    /** ⚠️ صفِ دستی هم دستِ آدم می‌مانَد؛ پرداختِ دوباره برش نمی‌گرداند به کرون */
    public function test_a_payment_does_not_pull_a_domain_out_of_the_manual_queue(): void
    {
        $d = $this->domain(['provision_status' => 'manual']);

        $this->pay(Invoice::create([
            'customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 100, 'tax' => 0, 'total' => 100,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]));

        $this->assertSame('manual', $d->fresh()->provision_status);
    }

    // ═══════════════ محافظِ «پول نگرفته، نخر» ═══════════════

    /**
     * 🔴 ردیفِ سفارشِ رهاشده نه «مرده» است نه «ثبت‌شده»، پس از هر دو نگهبانِ
     * قبلیِ `retry()` رد می‌شد — و فیلترِ پیش‌فرضِ /admin/domains دقیقاً همان
     * ردیف‌ها را نشان می‌دهد. یعنی مدیری که صفِ «نیازمندِ توجه» را با «تلاش
     * دوباره» خالی می‌کرد، ناخواسته دامنهٔ پرداخت‌نشده را **می‌خرید**.
     */
    public function test_admin_retry_refuses_a_domain_with_no_paid_invoice(): void
    {
        $d = $this->domain(['status' => 'pending', 'provision_status' => 'none', 'expires_at' => null]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/domains/'.$d->id.'/retry')->assertSessionHasErrors();

        $this->assertSame('none', $d->fresh()->provision_status,
            'دامنهٔ پرداخت‌نشده به صفِ خرید رفت');
    }

    /** ولی دامنهٔ پرداخت‌شده باید بتواند دوباره تلاش کند */
    public function test_admin_retry_works_once_the_invoice_is_paid(): void
    {
        $d = $this->domain(['status' => 'pending', 'provision_status' => 'failed', 'expires_at' => null]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        Invoice::create([
            'customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 100, 'tax' => 0, 'total' => 100,
            'paid' => 100, 'status' => 'paid', 'issued_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/domains/'.$d->id.'/retry')->assertSessionHasNoErrors();

        $this->assertSame('pending', $d->fresh()->provision_status);
    }

    // ═══════════════ آزادسازیِ نامِ دامنه ═══════════════

    /**
     * 🔴 لغوِ فاکتورِ پرداخت‌نشده باید نامِ دامنه را آزاد کند.
     *
     * ردیفِ دامنهٔ پرداخت‌نشده `pending`+`none` است — نه مرده، نه ثبت‌شده. بی‌این
     * شاخه، مشتری با یک لغوِ ساده همان نام را برای خودش **و هر مشتریِ دیگری**
     * برای همیشه می‌سوزاند، چون `order()` سفارشِ دوباره را رد می‌کند.
     */
    public function test_cancelling_an_unpaid_invoice_frees_the_domain_name(): void
    {
        $d = $this->domain(['status' => 'pending', 'provision_status' => 'none', 'expires_at' => null]);

        $inv = Invoice::create([
            'customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 100, 'tax' => 0, 'total' => 100,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->actingAs($d->customer, 'customer')
            ->post(route('account.invoice.cancel', $inv))
            ->assertRedirect();

        $fresh = $d->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertTrue($fresh->isDead(), 'دامنه باید مرده شمرده شود تا بشود دوباره سفارشش داد');
    }

    /**
     * ⚠️ ولی دامنهٔ **زنده** نباید کشته شود.
     *
     * لغوِ فاکتورِ تمدید یعنی «امسال تمدید نمی‌کنم»، نه «دامنه‌ام را دور بریز».
     */
    public function test_cancelling_a_renewal_invoice_does_not_kill_a_live_domain(): void
    {
        $d = $this->domain(['status' => 'active', 'provision_status' => 'done']);

        $inv = Invoice::create([
            'customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 100, 'tax' => 0, 'total' => 100,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->actingAs($d->customer, 'customer')
            ->post(route('account.invoice.cancel', $inv))
            ->assertRedirect();

        $this->assertSame('active', $d->fresh()->status);
    }

    // ═══════════════ سلامت ═══════════════

    /**
     * انقضای نزدیک باید در پنلِ سلامت دیده شود، نه فقط در یک تبِ خاموش.
     *
     * ⚠️ از ممیزیِ شهریور ۱۴۰۵ در چکِ **جدای** `domains_expiry` و با سطحِ
     * `warn`: وقتی با صفِ گیرکرده در یک چک بود، انقضای روزمره آن چک را
     * دائم `fail` نگه می‌داشت و امضای هشدار عوض نمی‌شد — یعنی گیرکردنِ
     * واقعیِ صف هرگز اعلان نمی‌گرفت.
     */
    public function test_health_reports_domains_expiring_soon(): void
    {
        \Illuminate\Support\Facades\File::put(
            storage_path('app/'.\App\Services\SystemHealth::HEARTBEAT), now()->toDateTimeString()
        );

        $this->domain(['expires_at' => now()->addDays(3)]);

        $checks = collect(app(\App\Services\SystemHealth::class)->checks())->keyBy('key');

        $this->assertSame('warn', $checks['domains_expiry']['level']);
        $this->assertStringContainsString('منقضی', $checks['domains_expiry']['detail']);
    }

    /**
     * 🔴 قلبِ باگِ «آژیرِ خفه»: انقضای روزمره نباید چکِ صفِ دامنه را اشغال
     * کند — وگرنه گیرکردنِ واقعیِ صف، امضای هشدار را عوض نمی‌کند و هیچ
     * اعلانی نمی‌رود.
     */
    public function test_routine_expiry_does_not_occupy_the_stuck_queue_check(): void
    {
        \Illuminate\Support\Facades\File::put(
            storage_path('app/'.\App\Services\SystemHealth::HEARTBEAT), now()->toDateTimeString()
        );

        $this->domain(['expires_at' => now()->addDays(3)]);

        $checks = collect(app(\App\Services\SystemHealth::class)->checks())->keyBy('key');

        $this->assertTrue((bool) $checks['domains']['ok'],
            'انقضای عادی چکِ صف را خراب کرد — خرابیِ واقعیِ بعدی دیگر اعلان نمی‌گیرد');
    }
}
