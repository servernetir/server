<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قاعدهٔ کارفرما: **یا حتماً تحویل شود، یا اصلاً برای فروش موجود نباشد.**
 *
 * ═══ ماجرایی که این فایل از آن آمد ═══
 *
 * دو سفارشِ واقعی شکست خوردند، هر دو روی یک زیرساخت:
 *
 *   «You don't have enough permissions for this action»
 *   «Proxy internal server error» (HTTP 500)
 *
 * هیچ‌کدام گذرا نبودند — توکن دسترسی نداشت و حساب اعتبار نداشت. ولی پلن‌ها
 * سرِ جایشان در فروشگاه ماندند، پس **هر مشتریِ بعدی هم همان تجربه را می‌گرفت**.
 * و چون سرورِ ساعتی پیش‌پرداخت است، هر بار پول از کیفِ پول کسر می‌شد و چیزی
 * تحویل نمی‌شد.
 */
class CloudDeliveryPromiseTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'd'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function plan(string $provider, array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => $provider, 'provider_ref' => 'ref-'.uniqid(), 'slug' => 'cv-1-2-'.uniqid(),
            'public_name' => 'CV-1-2', 'vcpu' => 1, 'ram_mb' => 2048, 'disk_gb' => 30,
            'location_code' => 'se-stockholm', 'cost_eur_cents' => 500, 'price_irt' => 1000000,
            'is_active' => true, 'admin_disabled' => false,
        ], $over));
    }

    /** خطای ساختاری ⇒ فروشِ **همهٔ** پلن‌های آن زیرساخت بسته می‌شود */
    public function test_a_structural_provider_error_closes_that_providers_plans(): void
    {
        $bad = $this->plan('aeza');
        $alsoBad = $this->plan('aeza');
        $healthy = $this->plan('hetzner');

        $this->invokeQuarantine($bad, "You don't have enough permissions for this action");

        $this->assertTrue($bad->fresh()->admin_disabled, 'پلنِ خرابه هنوز فروخته می‌شود');
        $this->assertTrue($alsoBad->fresh()->admin_disabled, 'بقیهٔ پلن‌های همان زیرساخت بسته نشدند');
        $this->assertFalse($healthy->fresh()->admin_disabled,
            'زیرساختِ سالم هم بسته شد — یک خطا نباید کلِ فروش را بخواباند');
    }

    public function test_a_proxy_500_from_the_provider_also_closes_sales(): void
    {
        $p = $this->plan('aeza');

        $this->invokeQuarantine($p, 'Proxy internal server error (see traceId) {"slug":"proxy_internal_server_error"}');

        $this->assertTrue($p->fresh()->admin_disabled);
    }

    /**
     * 🔴 و همان خطا در شکلِ **بافاصله** — که تا امروز هرگز نمی‌گرفت.
     *
     * ═══ چرا این تست قبلاً چیزی نمی‌سنجید ═══
     *
     * نسخهٔ بالا اسلاگِ `proxy_internal_server_error` را **خودش** به ورودی
     * می‌چسباند و روی همان اسلاگی که خودش ساخته بود سبز می‌شد. ولی چیزی که
     * درایور واقعاً برمی‌گرداند پیامِ خامِ گیت‌وی است، بی‌هیچ اسلاگی:
     *
     *     تحویلِ سرور ناموفق: Proxy internal server error (see traceId)
     *
     * سرویس‌های ۶ و ۱۳ روی پروداکشن دقیقاً همین متن را دارند و قرنطینه
     * **نگرفت** — پلن‌ها در فروشگاه ماندند و مشتریِ بعدی همان تجربه را گرفت.
     *
     * ⚠️ ولی این شکل مبهم است (هم «شکلِ درخواستت را نمی‌شناسم» هم «سرورم خراب
     * است»)، پس عمداً **دو ضربه‌ای** است: بارِ اول فقط ثبت می‌شود تا یک قطعیِ دو
     * دقیقه‌ای ۲۲۱ پلن را نبندد؛ بارِ دوم در نیم‌ساعت یعنی گذرا نبوده.
     */
    public function test_the_bare_gateway_500_quarantines_only_on_the_second_strike(): void
    {
        $p = $this->plan('aeza');

        // همان بایت‌هایی که درایور واقعاً می‌سازد — بی‌اسلاگ
        $bare = 'Proxy internal server error (see traceId)';

        $this->invokeQuarantine($p, $bare);

        $this->assertFalse($p->fresh()->admin_disabled,
            'یک بلیپِ گذرای گیت‌وی نباید بی‌درنگ کلِ کاتالوگِ یک زیرساخت را ببندد');

        $this->invokeQuarantine($p, $bare);

        $this->assertTrue($p->fresh()->admin_disabled,
            'تکرارِ همان خطا در نیم‌ساعت یعنی ساختاری است و باید فروش را ببندد');
        $this->assertStringContainsString('خودکار بسته شد', (string) $p->fresh()->admin_note);
    }

    /**
     * ⚠️ ولی خطای **گذرا** نباید کاتالوگ را ببندد.
     *
     * یک قطعیِ دو دقیقه‌ایِ ظرفیت، نباید فروشِ یک زیرساخت را تا دخالتِ دستی
     * بخواباند — آن‌وقت درمان از خودِ بیماری بدتر است.
     */
    public function test_a_transient_error_leaves_the_catalogue_open(): void
    {
        $p = $this->plan('aeza');

        foreach (['resource_unavailable', 'timeout while connecting', 'server type not available in this location'] as $msg) {
            $this->invokeQuarantine($p, $msg);

            $this->assertFalse($p->fresh()->admin_disabled, "«{$msg}» نباید فروش را ببندد");
        }
    }

    /** `admin_disabled` و نه `is_active` — وگرنه کرونِ سینک بی‌صدا بازش می‌کند */
    public function test_the_flag_is_the_one_the_sync_cron_never_touches(): void
    {
        $p = $this->plan('aeza');

        $this->invokeQuarantine($p, 'insufficient balance');

        $fresh = $p->fresh();

        $this->assertTrue($fresh->admin_disabled);
        $this->assertTrue($fresh->is_active, 'is_active دست‌خورد — سینکِ دو روزه تصمیم را برمی‌گرداند');
        $this->assertStringContainsString('خودکار بسته شد', (string) $fresh->admin_note);
    }

    // ═══════════════ پولِ پیش‌گرفته‌شده ═══════════════

    /**
     * 🔴 سرورِ ساعتی **پیش‌پرداخت** است: ساعتِ اول پیش از تحویل کسر می‌شود.
     *
     * اگر تحویل نشود و برنگردانیم، مشتری پول داده و چیزی نگرفته — و از دستِ
     * خودش هم کاری برنمی‌آید.
     */
    public function test_a_failed_hourly_delivery_returns_the_prepaid_hour(): void
    {
        $c = $this->customer();
        $service = $this->hourlyService($c, 2000);

        $this->invokeFail($service, 'تحویلِ سرور ناموفق: هرچه');

        $this->assertSame(2000, (int) CreditEntry::where('customer_id', $c->id)
            ->where('reason', 'cloud_hourly_refund')->sum('amount'),
            'ساعتِ پیش‌پرداخت برنگشت');
    }

    /** ⚠️ و فقط **یک بار** — کرون ممکن است چند بار fail بزند */
    public function test_the_refund_never_happens_twice(): void
    {
        $c = $this->customer();
        $service = $this->hourlyService($c, 2000);

        $this->invokeFail($service, 'بار اول');
        $this->invokeFail($service, 'بار دوم');
        $this->invokeFail($service, 'بار سوم');

        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)
            ->where('reason', 'cloud_hourly_refund')->count(),
            'برگشتِ تکراری خورد — اعتبارِ مشتری بی‌دلیل بالا می‌رود');
    }

    /** سرویسِ ماهانه پیش‌پرداخت نیست، پس برگشتی هم ندارد */
    public function test_a_cycle_service_gets_no_refund(): void
    {
        $c = $this->customer();

        $service = Service::create([
            'customer_id' => $c->id, 'name' => 'ماهانه', 'currency_code' => 'IRT',
            'price' => 1000000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'billing_mode' => 'cycle', 'cloud_plan_id' => $this->plan('aeza')->id,
        ]);

        $this->invokeFail($service, 'هرچه');

        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)
            ->where('reason', 'cloud_hourly_refund')->count());
    }

    // ═══════════════ کمکی ═══════════════

    private function hourlyService(Customer $c, int $rate): Service
    {
        return Service::create([
            'customer_id' => $c->id, 'name' => 'ساعتی', 'currency_code' => 'IRT',
            'price' => 1000000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'billing_mode' => 'hourly', 'hourly_rate_irt' => $rate,
            'cloud_plan_id' => $this->plan('aeza')->id,
        ]);
    }

    private function invokePrivate(string $method, array $args): void
    {
        $p = app(CloudProvisioner::class);
        $m = new \ReflectionMethod($p, $method);
        $m->setAccessible(true);
        $m->invokeArgs($p, $args);
    }

    private function invokeQuarantine(CloudPlan $plan, string $message): void
    {
        $this->invokePrivate('quarantineProvider', [$plan, $message]);
    }

    private function invokeFail(Service $service, string $reason): void
    {
        $this->invokePrivate('fail', [$service, $reason]);
    }
}
