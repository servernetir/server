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
     * 🔴 «راکد» از `issued_at` می‌آید، نه از `due_at`.
     *
     * ستونِ `due_at` در مهاجرت هست و ایندکس هم دارد، ولی **هیچ کدی در اپ
     * نمی‌نویسدش**. هر شمارشی رویش برای همیشه صفر است — و صفرِ همیشگی شبیهِ
     * «همه‌چیز مرتب است» به نظر می‌رسد، که بدترین نوع خرابی است.
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

        // و ادعا را قفل کن: هیچ‌کس `due_at` را نمی‌نویسد
        $this->assertNull(Invoice::first()->due_at,
            'اگر روزی due_at نوشته شد، این گزارش می‌تواند سررسیدِ واقعی نشان دهد');
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
}
