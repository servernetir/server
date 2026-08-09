<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudDeliveryWatch;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «شارژ ساعتی باید به حق باشد. مشتری هرچقدر استفاده میکند پولشو بدهد.»
 *
 * تصمیمِ کارفرما (مرداد ۱۴۰۵) به چهار قاعدهٔ سنجیدنی ترجمه می‌شود، و این فایل
 * هر چهار تا را با **مبلغِ دقیقِ تومان** قفل می‌کند:
 *
 *  ۱) واحد = یک ساعتِ کامل. کم‌تر از یک ساعت = هیچ. (کف‌گیرِ `max(1, …)` که به
 *     ۵۹ دقیقه یک ساعتِ کامل می‌بست، حذف شد.)
 *  ۲) ماشینی که تحویل نشده هرگز صورت‌حساب نمی‌شود — سه شکلِ «تحویل‌نشده» که
 *     پیش از این هر کدام روزی ۱۲۰٬۰۰۰ تومان از مشتری می‌گرفتند.
 *  ۳) ساعت‌های انتظارِ تحویل رایگان‌اند؛ لنگر روی لحظهٔ تحویل جلو می‌رود و
 *     **هرگز عقب نمی‌رود** (پس این قاعده فقط می‌تواند ببخشد، نه اضافه‌کسر کند).
 *  ۴) ساعتِ خاموشی (تعلیقِ مدیر) پس از روشن‌شدن پس‌گرفته نمی‌شود.
 *
 * ⚠️ هیچ تماسِ واقعیِ API. زیرساخت سندباکس ندارد.
 */
class CloudFairHourlyMeteringTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();                 // هیچ تماسِ بیرونی، در هیچ مسیری
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function customer(int $irt): Customer
    {
        $c = Customer::create([
            'email' => 'fm'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($irt !== 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
                'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
                'source_id' => $c->id, 'note' => 'test',
            ]);
        }

        return $c;
    }

    private function service(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'VPS ساعتی', 'currency_code' => 'IRT',
            'price' => self::RATE * 720, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => self::RATE, 'status' => 'active', 'activated_at' => now()->subDay(),
            'provision_status' => 'done', 'on_credit_out' => 'suspend',
            'last_metered_at' => now()->subHour(),
        ], $over));
    }

    /** ماشینِ واقعاً تحویل‌شده — شناسهٔ واقعی + IP + روشن + ایمیلِ تحویل رفته. */
    private function machine(Service $s, array $over = []): CloudInstance
    {
        $i = CloudInstance::create(array_merge([
            'service_id' => $s->id, 'provider' => 'aeza', 'provider_ref' => 'srv-'.$s->id,
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.0.0.7',
            'status' => 'running', 'ready_notified_at' => now()->subDays(10),
        ], $over));

        // تایم‌استمپ‌ها fillable نیستند؛ ردیفِ «تازه» هیچ‌وقت گیرکرده شمرده نمی‌شود.
        $i->newQuery()->whereKey($i->getKey())->update([
            'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);

        return $i->fresh();
    }

    private function spend(Customer $c): int
    {
        return (int) abs((int) CreditEntry::where('customer_id', $c->id)
            ->where('amount', '<', 0)->sum('amount'));
    }

    // ═══════════════ ۱) واحد = یک ساعتِ کامل ═══════════════

    public function test_exactly_one_hour_charges_exactly_one_hour(): void
    {
        $c = $this->customer(100_000);
        $s = $this->service($c, ['last_metered_at' => now()->subMinutes(60)]);
        $this->machine($s);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(95_000, $c->creditBalance('IRT'));
        $this->assertDatabaseHas('credit_ledger', [
            'source_type' => Service::class, 'source_id' => $s->id,
            'amount' => -5000, 'reason' => 'cloud_hourly',
        ]);
    }

    /**
     * 🔴 رگرسیونِ حذفِ `max(1, floor(...))`.
     *
     * پیش از این هر ردیفی که به متر می‌رسید دستِ‌کم یک ساعتِ کامل کسر می‌خورد،
     * حتی اگر ۵۹ دقیقه گذشته بود — و هر ردیفی با `last_metered_at`ِ نال هم،
     * صرف‌نظر از سنِ واقعی‌اش.
     */
    public function test_less_than_one_hour_charges_nothing(): void
    {
        $c = $this->customer(100_000);
        $s = $this->service($c, ['last_metered_at' => now()->subMinutes(59)]);
        $this->machine($s);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(100_000, $c->creditBalance('IRT'), '۵۹ دقیقه = هیچ');
        $this->assertSame(0, CreditEntry::where('source_type', Service::class)
            ->where('source_id', $s->id)->where('reason', 'cloud_hourly')->count());
        $this->assertSame(
            now()->subMinutes(59)->format('Y-m-d H:i'),
            $s->fresh()->last_metered_at->format('Y-m-d H:i'),
            'لنگر نباید جابه‌جا شود'
        );
    }

    public function test_one_hundred_nineteen_minutes_is_one_hour_and_one_twenty_is_two(): void
    {
        $c1 = $this->customer(100_000);
        $s1 = $this->service($c1, ['last_metered_at' => now()->subMinutes(119)]);
        $this->machine($s1);

        $c2 = $this->customer(100_000);
        $s2 = $this->service($c2, ['last_metered_at' => now()->subMinutes(120)]);
        $this->machine($s2);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(95_000, $c1->creditBalance('IRT'), '۱۱۹ دقیقه = یک ساعت');
        $this->assertSame(90_000, $c2->creditBalance('IRT'), '۱۲۰ دقیقه = دو ساعت');

        // لنگر دقیقاً به اندازهٔ ساعت‌های کسرشده جلو می‌رود، نه تا «الان»
        $this->assertSame(
            now()->subMinutes(119)->addHour()->format('Y-m-d H:i'),
            $s1->fresh()->last_metered_at->format('Y-m-d H:i')
        );
        $this->assertSame(
            now()->subMinutes(120)->addHours(2)->format('Y-m-d H:i'),
            $s2->fresh()->last_metered_at->format('Y-m-d H:i')
        );
    }

    public function test_meter_is_idempotent_within_the_hour(): void
    {
        $c = $this->customer(100_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHour()]);
        $this->machine($s);

        $this->artisan('cloud:meter');
        $this->artisan('cloud:meter');

        $this->assertSame(95_000, $c->creditBalance('IRT'));
        $this->assertSame(1, CreditEntry::where('source_type', Service::class)
            ->where('source_id', $s->id)->where('reason', 'cloud_hourly')->count());
    }

    // ═══════════════ ۲) تحویل‌نشده = بی‌هزینه ═══════════════

    /** سفارشی که هرگز به سرورِ واقعی تبدیل نشد — رخدادی که کارفرما گزارش کرد */
    public function test_a_server_stuck_on_an_order_ref_is_never_charged(): void
    {
        $c = $this->customer(1_000_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHours(24)]);
        $this->machine($s, ['provider_ref' => 'order:9911', 'status' => 'building', 'ipv4' => null]);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(1_000_000, $c->creditBalance('IRT'), 'پیش از این ۱۲۰٬۰۰۰ کسر می‌شد');
        $this->assertSame(0, CreditEntry::where('source_type', Service::class)
            ->where('source_id', $s->id)->count());
    }

    /** «تحویل‌شده» ثبت شده ولی اصلاً ردیفِ نمونه‌ای ساخته نشده */
    public function test_a_service_with_no_instance_row_is_never_charged(): void
    {
        $c = $this->customer(1_000_000);
        $this->service($c, ['last_metered_at' => now()->subHours(24)]);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(1_000_000, $c->creditBalance('IRT'));
    }

    /**
     * ماشین نزدِ زیرساخت پاک شده ولی `services.status` هنوز `active` است.
     *
     * ⚠️ این همان حالتی است که ثابت می‌کند `isDelivered()` در گیت لازم است:
     * `CloudDeliveryWatch::reasonFor()` برای نمونهٔ حذف‌شده عمداً `null` می‌دهد
     * («آگاهانه پاک شده، هشدار نده») و به‌تنهایی اجازهٔ صورت‌حساب می‌داد.
     */
    public function test_an_already_deleted_instance_is_never_charged(): void
    {
        $c = $this->customer(1_000_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHours(24)]);
        $this->machine($s, ['status' => 'deleted']);

        $this->assertNull(CloudDeliveryWatch::reasonFor($s->fresh()),
            'ناظرِ تحویل عمداً ساکت است — پس گیتِ متر نمی‌تواند فقط به آن تکیه کند');

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(1_000_000, $c->creditBalance('IRT'));
    }

    /** IP دارد و روشن است ولی ایمیلِ تحویل نرفته: بی‌هزینه — و **بلند** */
    public function test_a_server_whose_ready_notice_never_went_is_not_charged_but_is_loud(): void
    {
        $c = $this->customer(1_000_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHours(24)]);
        $this->machine($s, ['ready_notified_at' => null]);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(1_000_000, $c->creditBalance('IRT'));

        // توقفِ متر نباید «سواریِ مجانی» شود: همان ردیف در صفِ گیرکرده‌ها است
        $this->assertTrue(CloudDeliveryWatch::stalled()->contains(fn ($x) => $x->id === $s->id),
            'ردیفِ بی‌هزینه باید در ناظرِ تحویل دیده شود');
    }

    // ═══════════════ ۳) تأخیرِ تحویل رایگان است ═══════════════

    /**
     * 🔴 رخدادِ کارفرما: خرید در T، تحویلِ واقعی در T+۵ ساعت.
     *
     * پیش از این اولین تیکِ بعد از تحویل، **پنج ساعت را یک‌جا** کسر می‌کرد —
     * بابتِ ماشینی که مشتری اصلاً ندیده بود.
     */
    public function test_the_delivery_delay_is_never_billed(): void
    {
        $c = $this->customer(100_000);

        // ساعتِ اولِ خرید (در تسویه کسر شده) — لنگر روی لحظهٔ خرید
        $s = $this->service($c, ['last_metered_at' => now()->subHours(6)]);
        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -self::RATE,
            'balance_after' => 100_000 - self::RATE, 'reason' => 'cloud_hourly',
            'source_type' => Service::class, 'source_id' => $s->id, 'note' => 'ساعتِ اول',
        ]);

        // واقعاً یک ساعت پیش تحویل شد
        $this->machine($s, ['ready_notified_at' => now()->subHour()]);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(10_000, $this->spend($c),
            'کلِ عمر: ساعتِ اولِ خرید + یک ساعتِ واقعی = ۱۰٬۰۰۰ (نه ۳۰٬۰۰۰)');
        $this->assertSame(90_000, $c->creditBalance('IRT'));
    }

    /** `max()` فقط جلو می‌برد: تحویلِ **پیش از** لنگر نباید چیزی را کم کند */
    public function test_the_re_anchor_never_moves_backwards(): void
    {
        $c = $this->customer(100_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHours(3)]);
        $this->machine($s, ['ready_notified_at' => now()->subHours(5)]);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(85_000, $c->creditBalance('IRT'), 'سه ساعت، نه کم‌تر');
    }

    // ═══════════════ ۴) خاموشی صورت‌حساب نمی‌شود ═══════════════

    /**
     * 🔴 تعلیق/رفعِ تعلیقِ **مدیر** نباید کلِ پنجرهٔ خاموشی را پس بگیرد.
     *
     * `ProvisioningService::unsuspend()` فقط `status` را می‌نوشت و لنگر کهنه
     * می‌مانْد؛ تیکِ بعدی تا ۴۸ ساعتِ خاموشی را از مشتری می‌گرفت.
     */
    public function test_an_admin_suspend_unsuspend_cycle_does_not_back_charge(): void
    {
        $c = $this->customer(100_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHours(10)]);
        $this->machine($s);

        $prov = app(ProvisioningService::class);
        $prov->suspend($s);
        $prov->unsuspend($s->fresh());

        // یک ساعت پس از روشن‌شدن
        Carbon::setTestNow(now()->addHour());

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(95_000, $c->creditBalance('IRT'),
            'فقط یک ساعتِ روشن — نه ده ساعتِ خاموشی');
    }

    // ═══════════════ سقفِ جبران دیگر بی‌صدا نیست ═══════════════

    public function test_the_catchup_cap_leaves_a_written_record(): void
    {
        $c = $this->customer(1_000_000);
        $s = $this->service($c, ['last_metered_at' => now()->subHours(200)]);
        $this->machine($s, ['ready_notified_at' => now()->subHours(300)]);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(1_000_000 - 240_000, $c->creditBalance('IRT'), '۴۸ × ۵۰۰۰');

        $notes = ActivityLog::where('service_id', $s->id)->pluck('description')->implode(' | ');
        $this->assertStringContainsString('152', $notes,
            'ساعت‌های کسرنشده باید ردِ مکتوب داشته باشند');
    }
}
