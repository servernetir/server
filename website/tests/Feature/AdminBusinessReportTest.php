<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Reports\BusinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/admin/reports` — گزارشِ کسب‌وکار.
 *
 * 🔴 ادعای مرکزی «صفحه ۲۰۰ می‌دهد» نیست. یک گزارشِ مالی که عددِ اشتباه نشان
 * دهد بدتر از نبودنش است، چون تصمیم روی آن گرفته می‌شود. پس هر تست یک **عدد**
 * را می‌سنجد، نه رندر شدن را.
 */
class AdminBusinessReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function service(array $over = []): Service
    {
        return Service::create($over + [
            'customer_id'   => $this->customer()->id,
            'name'          => 'هاست',
            'currency_code' => 'IRT',
            'price'         => 500_000,
            'tax_percent'   => 0,
            'cycle'         => 'monthly',
            'status'        => 'active',
            'activated_at'  => now()->subMonths(2),
            'next_due_at'   => now()->addDays(10),
        ]);
    }

    private function report(): BusinessReport
    {
        return app(BusinessReport::class);
    }

    // ═══════════════ پولِ در راه ═══════════════

    public function test_upcoming_renewals_are_counted_with_their_tax(): void
    {
        $this->service(['price' => 1_000_000, 'tax_percent' => 10, 'next_due_at' => now()->addDays(5)]);

        $f = $this->report()->forecast(30);

        $this->assertSame(1, $f['incoming']['renewals']['count']);
        $this->assertSame(1_100_000, $f['incoming']['renewals']['amount'], 'مالیات در مبلغِ تمدید نیامد');
    }

    /** سرویسی که سررسیدش بیرونِ پنجره است، در آن پنجره شمرده نمی‌شود */
    public function test_a_renewal_outside_the_window_is_not_counted(): void
    {
        $this->service(['next_due_at' => now()->addDays(60)]);

        $this->assertSame(0, $this->report()->forecast(30)['incoming']['renewals']['count']);
        $this->assertSame(1, $this->report()->forecast(90)['incoming']['renewals']['count']);
    }

    /**
     * 🔴 سرویسی که فاکتورِ بازِ پرداخت‌نشده دارد، دو بار شمرده نمی‌شود.
     *
     * تمدیدش قبلاً صادر شده و مبلغش در «طلبِ وصول‌نشده» است. اگر این‌جا هم
     * بیاید، کارفرما درآمدِ در راه را بیشتر از واقع می‌بیند و روی پولی حساب
     * می‌کند که دو بار شمرده شده.
     */
    public function test_a_service_with_an_open_invoice_is_not_counted_twice(): void
    {
        $s = $this->service(['next_due_at' => now()->addDays(3)]);

        Invoice::create([
            'customer_id' => $s->customer_id, 'service_id' => $s->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 500_000, 'tax' => 0, 'total' => 500_000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $f = $this->report()->forecast(30);

        $this->assertSame(0, $f['incoming']['renewals']['count'], 'مبلغ دو بار شمرده شد');
        $this->assertSame(500_000, $f['incoming']['overdue']['amount']);
    }

    /**
     * 🔴 شرطِ تمدید باید **همانی** باشد که کرون برمی‌دارد.
     *
     * سرویسِ معلق فاکتورِ تمدید نمی‌گیرد (`where status active`)، پس گزارش هم
     * نباید پولش را وعده دهد. اگر روزی شرطِ کرون عوض شود و این‌جا نه، صفحه
     * عددی نشان می‌دهد که هیچ کرونی پشتش نیست.
     */
    public function test_only_active_services_are_forecast(): void
    {
        $this->service(['status' => 'suspended', 'next_due_at' => now()->addDays(3)]);

        $this->assertSame(0, $this->report()->forecast(30)['incoming']['renewals']['count']);
    }

    /**
     * طلبِ نیمه‌پرداخت فقط به اندازهٔ ماندهٔ آن شمرده می‌شود.
     *
     * ⚠️ وضعیت عمداً `unpaid` است و نه `partial`. هیچ‌جای این پروژه `partial`
     * نوشته نمی‌شود — فاکتورِ نیمه‌پرداخت `unpaid` می‌مانَد و فقط `paid` بالا
     * می‌رود. تستی که `partial` بسازد، حالتی را می‌سنجد که در پروداکشن وجود
     * ندارد و سبز بودنش هیچ‌چیز را تضمین نمی‌کند.
     */
    public function test_a_partly_paid_invoice_counts_only_its_remainder(): void
    {
        $c = $this->customer();

        Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 1_000_000, 'tax' => 0, 'total' => 1_000_000,
            'paid' => 400_000, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->assertSame(600_000, $this->report()->forecast(30)['incoming']['overdue']['amount']);
    }

    /**
     * «راکد» از `issued_at` می‌آید، نه از `due_at`.
     *
     * ═══ به‌روزرسانی (شهریور ۱۴۰۵) ═══
     *
     * تا امروز `due_at` **هیچ نویسنده‌ای نداشت** و همین تست آن را قفل کرده بود،
     * با این یادداشت که «اگر روزی نوشته شد، این گزارش می‌تواند سررسیدِ واقعی
     * نشان دهد». حالا `Invoice::creating` مهلتِ پرداخت را می‌نویسد
     * (`billing.invoice_hold_hours`) و کرونِ `invoices:expire` رویش کار می‌کند.
     *
     * ⚠️ ولی تعریفِ «راکد» عمداً **دست‌نخورده** ماند و همچنان از `issued_at`
     * می‌آید. دو مفهومِ متفاوت‌اند: `due_at` مهلتِ ۴۸ ساعتهٔ لغوِ خودکار است و
     * «راکد» یعنی فاکتوری که هفته‌هاست بلاتکلیف مانده. یکی‌کردنشان یعنی از
     * فردا هر فاکتورِ دو روزه «راکد» شمرده شود و آن ستون بی‌معنا گردد.
     */
    public function test_stale_invoices_are_detected_without_the_unwritten_due_date(): void
    {
        $c = $this->customer();

        foreach ([2, 30] as $age) {
            Invoice::create([
                'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
                'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 0,
                'status' => 'unpaid', 'issued_at' => now()->subDays($age),
            ]);
        }

        $o = $this->report()->forecast(30)['incoming']['overdue'];

        $this->assertSame(2, $o['count']);
        $this->assertSame(1, $o['stale_count'], 'فاکتورِ راکد پیدا نشد');

        // و ادعای تازه: `due_at` حالا **نوشته می‌شود** و شمارشِ راکد همچنان
        // مستقل از آن است — وگرنه دو مفهوم در هم می‌روند.
        $this->assertNotNull(Invoice::first()->due_at,
            'مهلتِ پرداخت روی فاکتور نوشته نشد — کرونِ invoices:expire کور می‌شود');
    }

    /**
     * 🔴 درآمدِ سرورِ ساعتی در هیچ دفتری نیست و باید این‌جا دیده شود.
     *
     * `CloudMeterHourly` مستقیم از اعتبار کم می‌کند: نه فاکتور، نه ردیفِ
     * پرداخت. و دفترِ مالی فقط از روی پرداخت درآمد ثبت می‌کند. پس بی‌این
     * بخش، کلِ کتابِ ساعتی از هر گزارشِ سودی غایب است.
     */
    public function test_hourly_cloud_income_is_surfaced_because_the_ledger_misses_it(): void
    {
        $c = $this->customer();

        foreach ([['cloud_hourly', -120_000], ['cloud_hourly_convert', -300_000], ['topup', 900_000]] as [$reason, $amt]) {
            \Illuminate\Support\Facades\DB::table('credit_ledger')->insert([
                'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $amt,
                'balance_after' => 0, 'reason' => $reason,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $h = $this->report()->forecast(30)['incoming']['hourly'];

        $this->assertTrue($h['has_any']);
        $this->assertSame(420_000, $h['month'], 'افزایشِ اعتبار نباید درآمدِ ساعتی شمرده شود');
    }

    // ═══════════════ هزینهٔ دامنه ═══════════════

    public function test_domain_renewal_shows_both_what_we_bill_and_what_we_pay(): void
    {
        $c = $this->customer();

        Domain::create([
            'customer_id' => $c->id, 'domain' => 'example.com', 'sld' => 'example', 'tld' => 'com',
            'status' => 'active', 'auto_renew' => true,
            'expires_at' => now()->addDays(15),
            'renew_toman' => 900_000,
            'cost_amount' => 1000,            // ۱۰٫۰۰ یورو به سنت
            'cost_currency' => 'EUR',
        ]);

        $f = $this->report()->forecast(30);

        $this->assertSame(900_000, $f['incoming']['domains']['amount'], 'صورت‌حسابِ مشتری نیامد');
        $this->assertSame(1000, $f['outgoing']['domains']['eur'], 'بهایِ رجیسترار نیامد');
    }

    /** دامنهٔ بی‌تمدیدِ خودکار هزینه‌ای در راه ندارد */
    public function test_a_domain_without_auto_renew_is_not_forecast(): void
    {
        $c = $this->customer();

        Domain::create([
            'customer_id' => $c->id, 'domain' => 'x.com', 'sld' => 'x', 'tld' => 'com',
            'status' => 'active', 'auto_renew' => false,
            'expires_at' => now()->addDays(5), 'renew_toman' => 900_000,
            'cost_amount' => 1000, 'cost_currency' => 'EUR',
        ]);

        $this->assertSame(0, $this->report()->forecast(30)['outgoing']['domains']['count']);
    }

    // ═══════════════ مشتری ═══════════════

    /**
     * «مشتری» و «مشتریِ پولی» دو عددند و قاطی نمی‌شوند.
     *
     * ثبت‌نام رایگان است؛ شمارشِ کلِ ردیف‌ها به‌تنهایی خوش‌بینانه است و
     * تصمیمِ بازاریابی را خراب می‌کند.
     */
    /**
     * 🔴 ثبت‌نامِ نیمه‌کاره مشتری نیست — و پولِ واقعی سوزانده.
     *
     * ردیفِ `pending` درست پیش از استعلامِ هویت ساخته می‌شود، پس هر کدامشان
     * شاهکار + استعلام (≈ ۸۱ هزار تومان) خرج کرده‌اند. شمردنشان به‌عنوان
     * مشتری هم رشد را بزرگ‌تر از واقع نشان می‌دهد و هم این هزینه را پنهان.
     */
    public function test_abandoned_registrations_are_excluded_and_their_cost_shown(): void
    {
        $this->customer();
        Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'p'.random_int(1000, 99999).'@x.com',
            'phone' => '0913'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'pending', 'locale' => 'fa',
        ]);

        $c = $this->report()->customers(3);

        $this->assertSame(1, $c['total'], 'ثبت‌نامِ نیمه‌کاره به‌عنوان مشتری شمرده شد');
        $this->assertSame(1, $c['abandoned']);
        $this->assertGreaterThan(0, $c['abandoned_cost'], 'هزینهٔ استعلامِ سوخته نشان داده نشد');
    }

    public function test_paying_customers_are_counted_separately_from_signups(): void
    {
        $paid = $this->customer();
        $this->customer();
        $this->customer();

        Invoice::create([
            'customer_id' => $paid->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 100,
            'status' => 'paid', 'issued_at' => now(),
        ]);

        $c = $this->report()->customers(3);

        $this->assertSame(3, $c['total']);
        $this->assertSame(1, $c['paying'], 'مشتریِ پولی با کلِ ثبت‌نام‌ها یکی شد');
    }

    /** یک مشتری با دو فاکتورِ پرداخت‌شده، یک مشتری است */
    public function test_a_customer_with_two_paid_invoices_counts_once(): void
    {
        $c = $this->customer();

        foreach ([1, 2] as $i) {
            Invoice::create([
                'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
                'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 100,
                'status' => 'paid', 'issued_at' => now(),
            ]);
        }

        $this->assertSame(1, $this->report()->customers(3)['paying']);
    }

    public function test_the_growth_trend_has_one_row_per_month(): void
    {
        $t = $this->report()->customers(12)['trend'];

        $this->assertCount(12, $t);
        $this->assertSame(0, $t[0]['count'] + 0, 'ماهِ خالی باید صفر باشد نه نال');
    }

    // ═══════════════ زیرساخت ═══════════════

    public function test_server_capacity_is_reported_as_allocation(): void
    {
        Server::create([
            'name' => 'WHM-DE-01', 'type' => 'whm', 'status' => 'active',
            'max_accounts' => 200, 'active_accounts' => 50,
        ]);

        $rows = $this->report()->infrastructure()['servers'];

        $this->assertCount(1, $rows);
        $this->assertSame(25, $rows[0]['pct']);
        $this->assertFalse($rows[0]['full']);
    }

    /** ظرفیتِ نامحدود درصد ندارد — و نباید صفر یا صد جا بزند */
    public function test_an_unlimited_server_has_no_percentage(): void
    {
        Server::create([
            'name' => 'WHM-IR-01', 'type' => 'whm', 'status' => 'active',
            'max_accounts' => null, 'active_accounts' => 12,
        ]);

        $this->assertNull($this->report()->infrastructure()['servers'][0]['pct']);
    }

    // ═══════════════ صداقتِ صفحه ═══════════════

    /**
     * 🔴 صفحه باید صریح بگوید چه چیزی را نمی‌داند.
     *
     * گزارشی که فقط دانستنی‌ها را نشان دهد، ناخواسته ادعا می‌کند بقیه‌اش صفر
     * است — و کارفرما «هزینهٔ در راه» را می‌بیند بی‌آنکه بداند اجارهٔ سرورها
     * اصلاً در آن نیست.
     */
    public function test_the_page_states_what_it_cannot_know(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/reports')
            ->assertOk()
            ->assertSee('آنچه این اعداد نمی‌گویند', false)
            ->assertSee('ظرفیتِ حساب', false);

        $this->assertNotEmpty($this->report()->blindSpots());
    }

    /** بازهٔ نامعتبر به پیش‌فرض برمی‌گردد، نه اینکه صفحه بترکد */
    public function test_an_invalid_window_falls_back_to_the_default(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/reports?days=9999')
            ->assertOk()
            ->assertSee('۳۰ روزِ آینده', false);
    }

    /** گزارش فقط می‌خوانَد — هیچ روتِ نوشتنی ندارد */
    public function test_the_report_is_read_only(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/reports')->assertStatus(405);
    }

    public function test_a_guest_cannot_see_the_report(): void
    {
        $this->get('/admin/reports')->assertRedirect();
    }

    // ═══════════════ اجارهٔ سرور ═══════════════

    private function rentedServer(array $over = []): Server
    {
        static $n = 0;
        $n++;

        return Server::create($over + [
            'name' => 'SRV-'.$n, 'type' => 'whm', 'status' => 'active',
            'max_accounts' => 200, 'active_accounts' => 10,
            'monthly_cost' => 3990, 'cost_currency' => 'EUR', 'billing_day' => 5,
            'vendor' => 'تأمین‌کنندهٔ الف',
        ]);
    }

    public function test_server_rent_is_converted_and_counted_in_the_forecast(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');   // ۱ یورو = ۱۰۰٬۰۰۰ ت
        $this->rentedServer(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);

        $rent = $this->report()->forecast(30)['outgoing']['servers'];

        $this->assertTrue($rent['ready']);
        $this->assertSame(3_990_000, $rent['monthly'], 'سنتِ یورو درست به تومان تبدیل نشد');
        $this->assertSame(1, $rent['priced']);
        $this->assertSame(0, $rent['unpriced']);
    }

    /** مبلغِ تومانی تبدیل نمی‌شود — همان عدد است */
    public function test_a_toman_priced_server_is_not_converted(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 2_500_000, 'cost_currency' => 'IRT']);

        $this->assertSame(2_500_000, $this->report()->forecast(30)['outgoing']['servers']['monthly']);
    }

    /**
     * 🔴 سرورِ بی‌قیمت **صفر شمرده نمی‌شود** — شمرده نمی‌شود، و تعدادش گزارش
     * می‌شود.
     *
     * اگر null را صفر بگیریم، یک جمعِ کم‌تر از واقع به‌عنوان «هزینهٔ کل» خوانده
     * می‌شود و سود خوش‌بینانه می‌مانَد — همان چیزی که این ستون‌ها برای رفعش
     * ساخته شدند.
     */
    public function test_a_server_without_a_price_is_reported_not_treated_as_free(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'EUR']);
        $this->rentedServer(['monthly_cost' => null]);

        $rent = $this->report()->forecast(30)['outgoing']['servers'];

        $this->assertSame(1, $rent['unpriced'], 'سرورِ بی‌قیمت گزارش نشد');
        $this->assertSame(1_000_000, $rent['monthly'], 'سرورِ بی‌قیمت در جمع آمد');
    }

    /** صفر یعنی «واقعاً رایگان» و با «نمی‌دانم» یکی نیست */
    public function test_zero_means_free_and_is_not_confused_with_unknown(): void
    {
        $this->rentedServer(['monthly_cost' => 0, 'cost_currency' => 'IRT']);

        $rent = $this->report()->forecast(30)['outgoing']['servers'];

        $this->assertSame(0, $rent['unpriced'], 'سرورِ رایگان «بی‌قیمت» شمرده شد');
        $this->assertSame(1, $rent['free']);
        $this->assertSame(0, $rent['monthly']);
    }

    /**
     * 🔴 پنجرهٔ ۹۰ روزه یعنی چند بار صورت‌حساب، نه یک بار.
     *
     * یک ماهانهٔ ساده در پنجرهٔ سه‌ماهه، هزینه را یک‌سومِ واقع نشان می‌داد.
     */
    public function test_a_ninety_day_window_counts_more_than_one_billing_date(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'EUR', 'billing_day' => 5]);

        $m30 = $this->report()->forecast(30)['outgoing']['servers']['toman'];
        $m90 = $this->report()->forecast(90)['outgoing']['servers']['toman'];

        $this->assertGreaterThan($m30, $m90, 'پنجرهٔ بلندتر هزینهٔ بیشتری نداد');
    }

    /** بی‌روزِ صورت‌حساب هم تخمین می‌زند، نه اینکه صفر بدهد */
    public function test_a_server_without_a_billing_day_still_counts(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'EUR', 'billing_day' => null]);

        $this->assertGreaterThan(0, $this->report()->forecast(30)['outgoing']['servers']['toman']);
    }

    /**
     * 🔴 بهایِ تمام‌شده و تأمین‌کننده از JSON بیرون می‌مانند.
     *
     * سفیدبرچسبیِ کلِ پروژه به این بند است: یک `toJson()` در جای اشتباه هم
     * قیمتِ خریدِ ما را لو می‌دهد هم اینکه سرور را از کجا اجاره می‌کنیم.
     */
    public function test_cost_and_vendor_never_leak_through_serialization(): void
    {
        $s = $this->rentedServer(['vendor' => 'SecretVendor']);

        $json = $s->fresh()->toJson();

        $this->assertStringNotContainsString('SecretVendor', $json);
        $this->assertStringNotContainsString('monthly_cost', $json);
    }

    /** فرمِ مدیر مبلغِ خالی را null ذخیره می‌کند، نه صفر */
    public function test_an_empty_cost_field_saves_null_not_zero(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/servers', [
            'name' => 'SRV-NEW', 'type' => 'whm', 'status' => 'active',
            'monthly_cost' => '', 'cost_currency' => 'EUR', 'billing_day' => '',
        ])->assertRedirect();

        $s = Server::where('name', 'SRV-NEW')->first();

        $this->assertNotNull($s);
        $this->assertNull($s->monthly_cost, 'مبلغِ خالی صفر ذخیره شد — یعنی «رایگان»');
        $this->assertNull($s->billing_day);
    }

    /** فرم هر چهار فیلد را واقعاً ذخیره می‌کند */
    public function test_the_admin_form_saves_all_four_cost_fields(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/servers', [
            'name' => 'SRV-COST', 'type' => 'whm', 'status' => 'active',
            'monthly_cost' => '3990', 'cost_currency' => 'EUR',
            'billing_day' => '5', 'vendor' => 'تأمین‌کنندهٔ ب',
        ])->assertRedirect();

        $s = Server::where('name', 'SRV-COST')->first();

        $this->assertSame(3990, (int) $s->monthly_cost);
        $this->assertSame('EUR', $s->cost_currency);
        $this->assertSame(5, $s->billing_day);
        $this->assertSame('تأمین‌کنندهٔ ب', $s->vendor);
    }

    /** روزِ صورت‌حسابِ ۳۱ رد می‌شود — هر ماهی آن روز را ندارد */
    public function test_a_billing_day_past_28_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/servers', [
            'name' => 'SRV-BAD', 'type' => 'whm', 'status' => 'active',
            'billing_day' => '31',
        ])->assertSessionHasErrors('billing_day');
    }

    /**
     * 🔴 وقتی سروری بی‌قیمت است، صفحه باید **بگوید** جمع ناقص است.
     *
     * عددِ کم‌برآوردشده‌ای که «قطعی» به نظر برسد، از نبودِ عدد بدتر است.
     */
    public function test_the_page_admits_the_total_is_incomplete(): void
    {
        $this->rentedServer(['monthly_cost' => null]);

        $titles = array_column($this->report()->blindSpots(), 'title');

        $this->assertNotEmpty(array_filter($titles, fn ($t) => str_contains($t, 'در جمع نیست')),
            'صفحه دربارهٔ سرورِ بی‌قیمت ساکت ماند');
    }

    /** وقتی همهٔ سرورها قیمت دارند، آن هشدار می‌رود */
    public function test_the_warning_disappears_once_every_server_is_priced(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 1000]);

        $titles = array_column($this->report()->blindSpots(), 'title');

        $this->assertEmpty(array_filter($titles, fn ($t) => str_contains($t, 'در جمع نیست')));
    }

    /**
     * 🔴 هر ارز با نرخِ **خودش** تبدیل می‌شود.
     *
     * نسخهٔ اول برای هر ارزی نرخِ یورو را می‌گرفت، پس یک سرورِ دلاری با نرخِ
     * یورو حساب می‌شد — چند درصد خطا روی هر ماه، برای همیشه، بی‌هیچ نشانه‌ای.
     * و `pricing_rate_override` که مدیر برای یورو می‌گذارد نباید روی مبلغِ
     * دلاری بنشیند.
     */
    public function test_a_dollar_server_is_not_converted_at_the_euro_rate(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');   // فقط یورو

        $usd = $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'USD']);

        $toman = $usd->fresh()->monthlyCostToman();

        $this->assertNotSame(1_000_000, $toman,
            'مبلغِ دلاری با نرخِ دستیِ یورو تبدیل شد');
    }

    // ═══════════════ چیزهایی که بازبینیِ حریفانه پیدا کرد ═══════════════

    /**
     * 🔴 پنجرهٔ ۳۰ روزه یعنی ۳۰ روز، نه ۳۱.
     *
     * مرزِ بالای بازه شامل بود، پس وقتی روزِ صورت‌حساب دقیقاً روی لبه می‌افتاد
     * اجارهٔ ماهانه **دو بار** شمرده می‌شد — صد درصد بیش‌برآورد، فقط در یک
     * روزِ خاصِ ماه، پس شبیهِ یک هزینهٔ واقعی به نظر می‌رسید نه یک باگ.
     *
     * تستِ قبلی نمی‌گرفتش چون فقط `monthly` و نابرابریِ ۳۰/۹۰ را می‌سنجید،
     * هرگز خودِ `toman`ِ سی‌روزه را.
     */
    public function test_a_thirty_day_window_never_counts_the_same_rent_twice(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'EUR', 'billing_day' => 5]);

        // ۵ام: هم امروز صورت‌حساب است، هم دقیقاً ۳۰ روز بعد لبهٔ پنجره
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-05 10:00:00'));

        $rent = $this->report()->forecast(30)['outgoing']['servers'];

        $this->assertSame(1_000_000, $rent['toman'],
            'اجارهٔ یک ماه دو بار در پنجرهٔ سی‌روزه شمرده شد');
    }

    /** و در ماهِ ۳۱ روزه هم همان یک بار — رفتار نباید به طولِ ماه بند باشد */
    public function test_the_window_edge_behaves_the_same_in_a_31_day_month(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '100000');
        $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'EUR', 'billing_day' => 5]);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-05 10:00:00'));

        $this->assertSame(1_000_000, $this->report()->forecast(30)['outgoing']['servers']['toman']);
    }

    /**
     * 🔴 مبلغی که تبدیل نشد هم باید «جا مانده» اعلام شود، نه فقط بی‌قیمت.
     *
     * وگرنه با نرخِ ارزِ در دسترس‌نبود، صفحه هم‌زمان «۰ تومان» نشان می‌داد و
     * ادعا می‌کرد جمع کامل است — در حالی که کلِ اجارهٔ ارزی از قلم افتاده بود.
     */
    public function test_an_unconvertible_amount_also_marks_the_total_incomplete(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '0');
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $this->rentedServer(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);

        $titles = array_column($this->report()->blindSpots(), 'title');

        $this->assertNotEmpty(array_filter($titles, fn ($t) => str_contains($t, 'در جمع نیست')),
            'مبلغِ تبدیل‌نشده بی‌صدا از جمع افتاد و صفحه چیزی نگفت');
    }

    /**
     * 🔴 صفحه دربارهٔ یک سرور دو حرفِ متناقض نزند.
     *
     * سروری که مبلغش **ثبت شده** ولی نرخِ ارز نبود، نباید «اجاره وارد نشده»
     * بخورد — مدیر بیهوده صفحهٔ سرورها را باز می‌کند و عدد را همان‌جا می‌بیند.
     */
    public function test_a_priced_server_is_never_labelled_as_having_no_price(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '0');
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $this->rentedServer(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);

        $row = $this->report()->infrastructure()['servers'][0];

        $this->assertNull($row['cost']);
        $this->assertFalse($row['cost_unknown'], 'سرورِ قیمت‌خورده «بی‌قیمت» علامت خورد');
    }

    /** و سرورِ واقعاً بی‌قیمت همان علامت را می‌گیرد */
    public function test_a_server_with_no_price_is_marked_unknown(): void
    {
        $this->rentedServer(['monthly_cost' => null]);

        $row = $this->report()->infrastructure()['servers'][0];

        $this->assertTrue($row['cost_unknown']);
    }

    /**
     * 🔴 نرخِ ارز یک بار در هر درخواست گرفته می‌شود، نه یک بار به‌ازای هر سرور.
     *
     * دو دلیل، و دومی بدتر است: `ExchangeRate::refresh()` روی شکست هیچ‌چیز کش
     * نمی‌کند، پس با منبعِ خاموش هر فراخوان یک HTTPِ مسدودکننده است. و دو پاسِ
     * همان صفحه می‌توانستند دو نرخِ متفاوت بگیرند و جمع با هشدارِ پایینِ صفحه
     * نخوانَد.
     */
    public function test_the_exchange_rate_is_fetched_once_per_request(): void
    {
        \App\Models\Setting::put('pricing_rate_override', '0');
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        foreach (range(1, 4) as $i) {
            $this->rentedServer(['monthly_cost' => 1000, 'cost_currency' => 'EUR']);
        }

        $r = $this->report();
        $r->forecast(30);
        $r->infrastructure();
        $r->blindSpots();

        $calls = count(\Illuminate\Support\Facades\Http::recorded());

        $this->assertLessThanOrEqual(2, $calls,
            'نرخ به‌ازای هر سرور و هر پاس دوباره گرفته شد ('.$calls.' فراخوان)');
    }

    /**
     * 🔴 پیش از اجرای مهاجرت، مدیریتِ سرور نباید بترکد.
     *
     * مهاجرت‌های پروداکشن دستی اجرا می‌شوند، پس کد همیشه مدتی جلوتر از
     * دیتابیس است. در آن پنجره، «افزودن سرور» با خطای SQL می‌ترکید — یعنی یک
     * قابلیتِ گزارشیِ تازه، کارِ روزمرهٔ مدیر را می‌خواباند.
     */
    public function test_managing_servers_survives_a_database_without_the_cost_columns(): void
    {
        \Illuminate\Support\Facades\Schema::table('servers', function ($t) {
            $t->dropColumn(['monthly_cost', 'cost_currency', 'billing_day', 'vendor']);
        });

        $this->actingAs($this->admin(), 'web')->post('/admin/servers', [
            'name' => 'SRV-PREMIGRATION', 'type' => 'whm', 'status' => 'active',
            'api_token' => '', 'monthly_cost' => '3990',
        ])->assertRedirect();

        $this->assertNotNull(Server::where('name', 'SRV-PREMIGRATION')->first(),
            'افزودن سرور پیش از مهاجرت شکست خورد');
    }

    /** و گزارش هم روی همان دیتابیس بالا می‌آید */
    public function test_the_report_survives_a_database_without_the_cost_columns(): void
    {
        \Illuminate\Support\Facades\Schema::table('servers', function ($t) {
            $t->dropColumn(['monthly_cost', 'cost_currency', 'billing_day', 'vendor']);
        });

        $this->actingAs($this->admin(), 'web')->get('/admin/reports')->assertOk();

        $this->assertFalse($this->report()->forecast(30)['outgoing']['servers']['ready']);
    }

    /**
     * 🔴 فرم یا فیلد را دارد و کار می‌کند، یا اصلاً نشانش نمی‌دهد.
     *
     * پیش از مهاجرت، کنترلر مقدارِ ارسالی را بی‌صدا دور می‌ریزد (وگرنه SQL
     * می‌ترکد). اگر فیلد همچنان روی صفحه باشد، مدیر اجاره را وارد می‌کند،
     * «ذخیره شد» می‌گیرد و عدد غیب می‌شود — شکستِ خاموشی که بدتر از خطاست.
     */
    public function test_the_cost_fields_are_hidden_until_the_columns_exist(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/servers')
            ->assertOk()
            ->assertSee('اجارهٔ ماهانه', false);

        \Illuminate\Support\Facades\Schema::table('servers', function ($t) {
            $t->dropColumn(['monthly_cost', 'cost_currency', 'billing_day', 'vendor']);
        });

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/servers')
            ->assertOk()
            ->assertDontSee('اجارهٔ ماهانه', false);
    }
}
