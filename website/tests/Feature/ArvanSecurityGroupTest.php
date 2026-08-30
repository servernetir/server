<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ساختِ سرورِ آروان: گروهِ امنیتی اجباری است.
 *
 * ═══ حادثهٔ واقعی (۹ شهریور ۱۴۰۵) ═══
 *
 * 🔴 مشتری سرور خرید، پول رفت، و تحویل نشد — با این پیام از آروان:
 * «At least one firewall should be selected». پیلودِ ما هیچ‌وقت
 * `security_groups` نمی‌فرستاد، پس **هر** سفارشِ آروان شکست می‌خورد. چند روز
 * تکرار شد و کارفرما گفت «هی این اتفاق می‌افتد».
 *
 * ⚠️ و دو برابر بد: چون این پیام در فهرستِ خطاهای «ساختاری» نبود، قرنطینهٔ
 * خودکار پلن‌ها را از فروش برنداشت و مشتریِ بعدی هم همان شکست را خرید.
 */
class ArvanSecurityGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();                                   // کشِ کشفِ گروه بین تست‌ها نشت نکند
        Setting::putSecret('arvan_api_token', 'Apikey test-123');
    }

    /**
     * پاسخ‌های جعلیِ آروان.
     *
     * @param  array<int,array<string,mixed>>  $groups  گروه‌های امنیتیِ موجود
     */
    private function fakeArvan(array $groups, ?callable $onCreate = null): void
    {
        // 🔴 کشِ کشف (شبکه و گروهِ امنیتی) بینِ تست‌ها نشت می‌کرد: نتیجهٔ یک
        //    سناریو سناریوی بعدی را آلوده می‌کرد و شکستِ ساختگی می‌ساخت.
        Cache::flush();
        Http::swap(new Factory);

        Http::fake(['napi.arvancloud.ir/*' => function ($request) use ($groups, $onCreate) {
            $url = $request->url();

            if (str_contains($url, '/networks')) {
                return Http::response(['data' => [[
                    'id' => 'net-public', 'name' => 'public', 'enable_gateway' => true,
                ]]], 200);
            }

            if (str_contains($url, '/securities') || str_contains($url, '/security-groups')) {
                return Http::response(['data' => $groups], 200);
            }

            if (str_contains($url, '/servers') && $request->method() === 'POST') {
                if ($onCreate !== null) {
                    $onCreate($request);
                }

                // 🔴 رفتارِ واقعیِ آروان: بی‌گروهِ امنیتی رد می‌کند
                if (blank($request['security_groups'] ?? null)) {
                    return Http::response(['message' => 'At least one firewall should be selected'], 422);
                }

                return Http::response(['data' => [
                    'id' => 'srv-1', 'name' => 'x', 'status' => 'ACTIVE', 'password' => 'p',
                ]], 200);
            }

            return Http::response(['data' => []], 200);
        }]);
    }

    private function spec(): array
    {
        return [
            'name' => 'vps-test', 'plan_ref' => 'flavor-1', 'location_ref' => 'ir-thr-c2',
            'image_ref' => 'img-1', 'disk_gb' => 25,
        ];
    }

    /** 🔴 هستهٔ رفع: درخواستِ ساخت باید گروهِ امنیتی حمل کند. */
    public function test_the_create_request_carries_a_security_group(): void
    {
        $sent = null;
        $this->fakeArvan(
            [['id' => 'sg-default', 'name' => 'arDefault', 'default' => true]],
            function ($request) use (&$sent) { $sent = $request['security_groups'] ?? null; },
        );

        $r = app(ArvanClient::class)->createServer($this->spec());

        $this->assertSame(['sg-default'], $sent, 'گروهِ امنیتی فرستاده نشد — آروان سفارش را رد می‌کند');
        $this->assertTrue($r['ok'], 'ساخت ناموفق ماند: '.($r['message'] ?? ''));
    }

    /** گروهِ `default` بر بقیه مقدم است، حتی اگر اول فهرست نباشد. */
    public function test_the_default_group_wins_over_order(): void
    {
        $sent = null;
        $this->fakeArvan([
            ['id' => 'sg-other', 'name' => 'custom'],
            ['id' => 'sg-def', 'name' => 'arDefault', 'default' => true],
        ], function ($request) use (&$sent) { $sent = $request['security_groups'] ?? null; });

        app(ArvanClient::class)->createServer($this->spec());

        $this->assertSame(['sg-def'], $sent);
    }

    /** بی‌گروهِ default، اولین گروهِ موجود — بهتر از شکست است. */
    public function test_it_falls_back_to_the_first_group(): void
    {
        $sent = null;
        $this->fakeArvan(
            [['id' => 'sg-only', 'name' => 'my-rules']],
            function ($request) use (&$sent) { $sent = $request['security_groups'] ?? null; },
        );

        app(ArvanClient::class)->createServer($this->spec());

        $this->assertSame(['sg-only'], $sent);
    }

    /**
     * ⚠️ هیچ گروهی نبود ⇒ پیامِ روشنِ خودمان، نه ارسالِ درخواستِ ناقص.
     *
     * پیامِ گنگِ زنجیره («At least one firewall…») به مدیر نمی‌گوید چه کند؛
     * این می‌گوید.
     */
    public function test_no_group_at_all_fails_with_an_actionable_message(): void
    {
        $called = false;
        $this->fakeArvan([], function () use (&$called) { $called = true; });

        $r = app(ArvanClient::class)->createServer($this->spec());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('firewall', $r['message'], 'پیام به مدیر نمی‌گوید چه بسازد');
        $this->assertFalse($called, 'درخواستِ ناقص فرستاده شد — سهمیهٔ API بی‌دلیل سوخت');
    }

    /**
     * 🔴 خطای فایروال باید فروش را **متوقف** کند.
     *
     * تا امروز در فهرستِ خطاهای ساختاری نبود، پس پلن‌ها در فروش می‌ماندند و
     * مشتریِ بعدی همان شکست را می‌خرید. قاعدهٔ کارفرما: یا حتماً تحویل شود،
     * یا اصلاً برای فروش موجود نباشد.
     */
    public function test_a_firewall_error_quarantines_the_provider(): void
    {
        $plan = CloudPlan::create([
            'provider' => 'arvan', 'provider_ref' => 'f1', 'provider_location' => 'ir-thr-c2',
            'location_code' => 'ir-tehran', 'public_name' => 'CV-1-1',
            'slug' => 'cv-1c-1g-25d-ir-'.random_int(1, 99999),
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 25, 'disk_type' => 'ssd',
            'traffic_gb' => 1024, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 300, 'price_eur_cents' => 400, 'price_irt' => 880000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $m = new \ReflectionMethod(CloudProvisioner::class, 'quarantineProvider');
        $m->setAccessible(true);
        $m->invokeArgs(app(CloudProvisioner::class), [$plan, 'At least one firewall should be selected']);

        $this->assertTrue((bool) $plan->fresh()->admin_disabled,
            'خطای فایروال فروش را نبست — مشتریِ بعدی همان شکست را می‌خرد');
    }
}
