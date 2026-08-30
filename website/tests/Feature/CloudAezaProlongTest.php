<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\AezaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * سرورِ ساعتی باید هر دوره خودش تمدید شود؛ ماهانه نباید.
 *
 * ═══ رخدادِ واقعی ═══
 *
 * مشتری سرورِ ساعتی خرید و **هر ساعت** سرورش می‌مرد. در پنلِ زیرساخت به
 * `waiting prolongation` می‌رفت — «منتظرِ پرداختِ دوره» — در حالی که اعتبارِ
 * همان مشتری نزدِ ما پر بود. کارفرما مجبور بود دستی تمدید کند.
 *
 * علت: `autoProlong: false` را برای **همه** می‌فرستادیم، با استدلالِ «تمدید را
 * ما مدیریت می‌کنیم». ولی آن استدلال یک پیش‌فرضِ نانوشته داشت — اینکه راهی
 * برای تمدید داریم. **نداریم**: SDKِ رسمیِ خودشان هیچ مسیرِ prolong/renew
 * ندارد. پس «ما مدیریت می‌کنیم» یعنی «هیچ‌کس مدیریت نمی‌کند».
 *
 * ⚠️ درسِ عمومی: هر تصمیمی که با «این کار را خودمان می‌کنیم» توجیه می‌شود، باید
 * کدِ انجام‌دهنده‌اش **وجود داشته باشد** — وگرنه فقط قابلیت را خاموش کرده‌ایم.
 * همین الگو یک بار در چرخهٔ عمرِ دامنه هم رخ داد: `autoRenew:false` + کامنتِ
 * «تمدید را ما می‌فروشیم» + هیچ فراخوانی.
 *
 * ⚠️ فیکسچر: نسخهٔ اولِ همین تست `new AezaClient('k')` می‌ساخت و هیچ درخواستی
 * ثبت نمی‌شد. توکن از `Setting` می‌آید و کلاینت باید از کانتینر برداشته شود؛
 * `Sleep::fake()` هم لازم است وگرنه حلقهٔ پی‌گیریِ سفارش تست را کند می‌کند.
 */
class CloudAezaProlongTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Setting::putSecret('aeza_api_token', 'k');

        // ⚠️ `Http::swap` لازم است — اولین استابِ تطبیق‌یافته برنده است
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => ['id' => 8801, 'createdServiceIds' => [55]]], 200)]);
    }

    /** @return array<string,mixed> بدنهٔ سفارشی که واقعاً روی سیم رفت */
    private function orderBodyFor(string $term): array
    {
        app(AezaClient::class)->createServer([
            'name' => 'sn-svc-42', 'plan_ref' => '153', 'location_ref' => 'nl',
            'image_ref' => 'ubuntu_2404', 'ssh_keys' => [], 'disk_gb' => 60,
            'labels' => [], 'term' => $term,
        ]);

        $body = [];

        Http::assertSent(function ($r) use (&$body) {
            if (str_contains($r->url(), 'services/orders') && $r->method() === 'POST') {
                $body = $r->data();
            }

            return true;
        });

        return $body['orders'][0] ?? [];
    }

    /** 🔴 قلبِ باگ: ساعتیِ بدونِ تمدیدِ خودکار سرِ هر ساعت می‌میرد */
    public function test_an_hourly_order_asks_the_provider_to_auto_prolong(): void
    {
        $order = $this->orderBodyFor('hour');

        $this->assertSame('hour', $order['term'] ?? null, 'دورهٔ خرید ساعتی نرفت');
        $this->assertTrue($order['autoProlong'] ?? null,
            'سفارشِ ساعتی بدونِ autoProlong رفت — سرور سرِ هر ساعت به waiting prolongation می‌افتد');
    }

    /**
     * ⚠️ نیمهٔ دومِ قاعده، و به همان اندازه مهم.
     *
     * تمدیدِ ماهانه را واقعاً خودمان می‌فروشیم (`services:renew-due` + فاکتور).
     * اگر زیرساخت هم خودش تمدید کند، برای ماهی که مشتری پولش را نداده **ما**
     * پول می‌دهیم و راهی برای پس‌گرفتن نیست.
     */
    public function test_a_monthly_order_never_auto_prolongs(): void
    {
        $order = $this->orderBodyFor('month');

        $this->assertSame('month', $order['term'] ?? null);
        $this->assertFalse($order['autoProlong'] ?? null,
            'سفارشِ ماهانه با autoProlong رفت — برای ماهِ پرداخت‌نشده هم ما پول می‌دهیم');
    }
}
