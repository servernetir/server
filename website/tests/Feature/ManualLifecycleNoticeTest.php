<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Provisioning\ManualLifecycleNotice;
use App\Services\Provisioning\ProvisioningService;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 محصولِ دستی فقط در خریدِ اول آدم لازم ندارد.
 *
 * ═══ خرابی‌ای که این می‌بندد ═══
 *
 * لایسنس‌ها در خریدِ اول درست مدیریت می‌شدند: `provision_status='manual'`،
 * صفِ مدیر، و شمارش در `SystemHealth`. ولی سه رخدادِ دیگر بی‌صدا رد می‌شدند:
 *
 *   · **تمدید** — سررسید جلو می‌رفت و تنها ردش اعلانِ عمومیِ «پرداختِ موفق»
 *     بود که از تمدیدِ هاست فرقی ندارد. لایسنسِ بالادست تمدید نمی‌شد و پنلِ
 *     مشتریِ **پول‌داده** در دورهٔ تازه قفل می‌شد. و چون لایسنس ماهانه است،
 *     این پرتکرارترین حالت است.
 *   · **تعلیق** — `suspend()` چون `server` نبود `success` می‌داد.
 *   · **خاتمه** — `releaseServer()` هم همین.
 *
 * دو موردِ آخر پولِ در جریان‌اند و در جهتِ عکس: مشتری نمی‌پردازد و ما به
 * تأمین‌کننده می‌پردازیم. خطِ قرمزِ «هرگز زیرِ بها» دقیقاً همین است.
 */
class ManualLifecycleNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'ml'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /**
     * لایسنس: نه سرور دارد نه ابری است.
     *
     * ⚠️ `$attrs + [پیش‌فرض‌ها]` و نه برعکس. عملگرِ `+` روی آرایه کلیدهای
     * **چپ** را نگه می‌دارد، پس نسخهٔ اولِ همین متد هر override را بی‌صدا
     * دور می‌ریخت — و تستِ «خریدِ اول» با `activated_at` پرشده می‌دوید و
     * قرمزِ گمراه‌کننده می‌داد. فیکسچری که ورودی‌اش را نادیده بگیرد، سناریو
     * را نمی‌سازد.
     */
    private function licence(array $attrs = []): Service
    {
        return Service::create($attrs + [
            'customer_id' => $this->customer()->id,
            'name' => 'لایسنس cPanel/WHM — سرور مجازی',
            'price' => 390000, 'currency_code' => 'IRT', 'cycle' => 'monthly',
            'status' => 'active', 'domain' => '203.0.113.9',
            'provision_status' => 'done', 'activated_at' => now(),
        ]);
    }

    // ═══════════════ ۱) خودِ نشانه ═══════════════

    public function test_each_lifecycle_event_leaves_a_pending_action(): void
    {
        foreach (['renew', 'suspend', 'terminate'] as $kind) {
            $s = $this->licence();

            app(ManualLifecycleNotice::class)->flag($s, $kind);

            $a = $s->fresh()->pendingManualAction();

            $this->assertNotNull($a, "رخدادِ «{$kind}» هیچ ردی نگذاشت");
            $this->assertSame($kind, $a['kind']);
            $this->assertNotSame('', trim((string) $a['note']),
                'کارِ لازم بی‌متن است — مدیر نمی‌داند باید چه کند');
        }
    }

    /**
     * 🔴 مهم‌ترین ادعا: سرویسِ **خودکار** نباید نشانه بگیرد.
     *
     * درایور آن‌جا خودش کار را کرده. یک کارتِ اضافه فقط صف را شلوغ می‌کند — و
     * صفِ شلوغ دقیقاً همان چیزی است که ردیفِ واقعی را گم می‌کند. این همان
     * قاعدهٔ «آژیرِ همیشه‌قرمز» است، از سمتِ حجم به‌جای سمتِ زمان.
     */
    public function test_an_automatically_delivered_service_is_never_flagged(): void
    {
        $server = Server::create([
            'name' => 'n1', 'type' => 'whm', 'hostname' => 'whm.test',
            'username' => 'root', 'api_token' => 'x', 'is_active' => true,
        ]);

        foreach (['renew', 'suspend', 'terminate'] as $kind) {
            $s = $this->licence(['server_id' => $server->id, 'username' => 'u1']);

            app(ManualLifecycleNotice::class)->flag($s, $kind);

            $this->assertNull($s->fresh()->pendingManualAction(),
                "سرویسِ خودکار برای «{$kind}» نشانه گرفت");
        }
    }

    /** ⚠️ و `provision_status` دست‌نخورده می‌مانَد. */
    public function test_the_marker_never_touches_the_delivery_column(): void
    {
        $s = $this->licence();

        app(ManualLifecycleNotice::class)->flag($s, 'renew');

        $this->assertSame('done', $s->fresh()->provision_status,
            'نوشتن روی provision_status تمدیدِ بعدی را به awaiting_provision می‌بَرد '
            .'و مشتری سرویسِ سالمش را «در انتظارِ تحویل» می‌بیند');
    }

    // ═══════════════ ۲) ناظر ═══════════════

    /**
     * چکِ سلامت تا پاک‌نشدن قرمز می‌مانَد.
     *
     * ⚠️ ادعا روی **ماندگاری** است، نه روی یک اعلان: اعلان یک بار می‌آید و اگر
     * مدیر آن ساعت نگاه نکند رفته است.
     */
    public function test_the_health_check_stays_red_until_it_is_cleared(): void
    {
        $before = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'manual_lifecycle');
        $this->assertSame('ok', $before['level'], 'با صفِ خالی نباید هشدار بدهد');

        $s = $this->licence();
        app(ManualLifecycleNotice::class)->flag($s, 'terminate');

        $red = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'manual_lifecycle');
        $this->assertFalse($red['ok']);
        $this->assertStringContainsString('#'.$s->id, $red['detail'],
            'گزارش باید بگوید کدام سرویس — وگرنه مدیر باید خودش بگردد');

        $s->fresh()->clearManualAction();

        $green = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'manual_lifecycle');
        $this->assertSame('ok', $green['level']);
    }

    /**
     * 🔴 ابطالِ نکرده `fail` است و تمدیدِ نکرده `warn`.
     *
     * هر دو کارِ آدم می‌خواهند ولی جهتِ ضررشان فرق دارد: ابطال یعنی همین حالا
     * داریم پول می‌دهیم؛ تمدید یعنی مشتری در آیندهٔ نزدیک قطع می‌شود.
     * یک‌سطح‌کردنشان یعنی فوری‌ترین کار در انبوهِ بقیه گم شود.
     */
    public function test_an_unrevoked_licence_outranks_an_unrenewed_one(): void
    {
        app(ManualLifecycleNotice::class)->flag($this->licence(), 'renew');

        $warn = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'manual_lifecycle');
        $this->assertSame('warn', $warn['level']);

        app(ManualLifecycleNotice::class)->flag($this->licence(), 'terminate');

        $fail = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'manual_lifecycle');
        $this->assertSame('fail', $fail['level'], 'ابطالِ نکرده پولِ در جریان است و باید بالاتر بنشیند');
    }

    /**
     * ⚠️ کلیدِ چک باید از `services` جدا باشد.
     *
     * امضای اعلانِ `SystemHealthCheck` شاملِ **کلیدِ** چکِ خراب است. اگر هر دو
     * یک کلید داشتند، «صفِ تحویل درست شد ولی یک ابطال معلق ماند» هیچ خبری
     * نمی‌ساخت — همان کوریِ که این ابزار برای رفعش هست.
     */
    public function test_it_has_its_own_check_key(): void
    {
        $keys = collect(app(SystemHealth::class)->checks())->pluck('key');

        $this->assertTrue($keys->contains('manual_lifecycle'));
        $this->assertTrue($keys->contains('services'));
    }

    // ═══════════════ ۳) درِ خروج ═══════════════

    /** بی‌دکمهٔ مدیر، ناظر برای همیشه قرمز می‌مانْد. */
    public function test_the_admin_can_mark_it_done(): void
    {
        $s = $this->licence();
        app(ManualLifecycleNotice::class)->flag($s, 'suspend');

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/services/'.$s->id.'/ack-manual')
            ->assertRedirect();

        $this->assertNull($s->fresh()->pendingManualAction());
    }

    /** و دوباره‌زدنش خطا می‌دهد، نه یک «موفق»ِ توخالی. */
    public function test_acknowledging_nothing_is_refused(): void
    {
        $s = $this->licence();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/services/'.$s->id.'/ack-manual')
            ->assertSessionHasErrors();
    }

    // ═══════════════ ۴) سیم‌کشی — نه خودِ کلاس ═══════════════

    /*
    | 🔴 چرا این سه تست جدا لازم بودند.
    |
    | تست‌های بالا `ManualLifecycleNotice` را **مستقیم** صدا می‌زنند. با
    | mutation معلوم شد آن کافی نیست: حذفِ هر سه فراخوانی از
    | `ProvisioningService` و `PaymentService` را ۵۲ تست ندیدند و همه سبز
    | ماندند. یعنی کلاس تست داشت و **وصل‌بودنش** نه.
    |
    | همان تلهٔ ثبت‌شدهٔ پروژه: گاردی که فقط واحد را می‌سنجد، وقتی لایهٔ
    | فراخوان عوض شود بی‌صدا می‌میرد. پس این سه از **مسیرِ واقعی** می‌روند.
    */

    public function test_suspending_a_licence_through_the_real_path_flags_it(): void
    {
        $s = $this->licence();

        app(ProvisioningService::class)->suspend($s);

        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertSame('suspend', $s->fresh()->pendingManualAction()['kind'] ?? null,
            'تعلیق شد ولی کسی نمی‌داند لایسنس نزدِ تأمین‌کننده باید غیرفعال شود — هزینه‌اش پای ماست');
    }

    public function test_terminating_a_licence_through_the_real_path_flags_it(): void
    {
        $s = $this->licence();

        app(ProvisioningService::class)->terminate($s);

        $this->assertSame('terminate', $s->fresh()->pendingManualAction()['kind'] ?? null,
            'سرویس بسته شد ولی ابطالِ لایسنس به کسی گفته نشد — ماهانه بابتش پول می‌دهیم');
    }

    /**
     * از همان مسیری که بازگشتِ موفقِ درگاه می‌رود.
     *
     * ⚠️ و ادعای دوم مهم‌تر است: خریدِ **اول** نباید نشانه بگیرد. آن از قبل
     * `provision_status='manual'` می‌گیرد و در صفِ تحویل دیده می‌شود؛ دو
     * نشانه برای یک کار، صف را شلوغ می‌کند و ردیفِ واقعی را گم.
     */
    public function test_renewing_a_licence_through_a_real_payment_flags_it(): void
    {
        $s = $this->licence(['next_due_at' => now()->addDays(3)]);

        $this->payFor($s);

        $this->assertSame('renew', $s->fresh()->pendingManualAction()['kind'] ?? null,
            'تمدید پرداخت شد ولی کسی نمی‌داند لایسنس نزدِ تأمین‌کننده باید تمدید شود');
        $this->assertSame('active', $s->fresh()->status,
            'تمدید نباید سرویسِ زندهٔ مشتری را به «در انتظارِ تحویل» ببرد');
    }

    public function test_a_first_purchase_is_not_flagged_twice(): void
    {
        $s = $this->licence(['activated_at' => null, 'status' => 'pending', 'provision_status' => null]);

        $this->payFor($s);

        $this->assertNull($s->fresh()->pendingManualAction(),
            'خریدِ اول از قبل در صفِ تحویل است — نشانهٔ دوم فقط صف را شلوغ می‌کند');
        $this->assertSame('manual', $s->fresh()->provision_status);
    }

    /** فاکتور + پرداختِ موفق، از مسیرِ عمومی. */
    private function payFor(Service $service): void
    {
        $invoice = Invoice::create([
            'customer_id' => $service->customer_id, 'service_id' => $service->id,
            'number' => 'ML-'.$service->id.'-'.random_int(100, 999),
            'kind' => 'service', 'currency_code' => 'IRT', 'status' => 'unpaid',
            'paid' => 0, 'subtotal' => 390000, 'tax' => 0, 'total' => 390000,
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => $invoice->total,
            'status' => 'redirected', 'external_ref' => 'ML-'.random_int(1000, 9999),
        ]);

        app(PaymentService::class)->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));
    }

    /** ⚠️ و فقط مدیر. */
    public function test_a_customer_cannot_clear_it(): void
    {
        $s = $this->licence();
        app(ManualLifecycleNotice::class)->flag($s, 'terminate');

        $this->post('/admin/services/'.$s->id.'/ack-manual');

        $this->assertNotNull($s->fresh()->pendingManualAction(),
            'نشانه بی‌احرازِ مدیر پاک شد');
    }
}
