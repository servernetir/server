<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 کارفرما: «کنسولِ تحتِ وب وقتی تمام‌صفحه می‌کنم، تمام‌صفحه نمی‌شود و کوچک
 * دیده می‌شود.»
 *
 * علت: `#vnc-screen` ارتفاعِ `460px` را به‌صورت **inline** داشت. استایلِ inline
 * را هیچ قاعدهٔ CSS نمی‌شکند (مگر با !important)، پس در حالتِ تمام‌صفحه ظرف
 * بزرگ می‌شد ولی خودِ صفحهٔ کنسول ۴۶۰ پیکسل می‌ماند و noVNC تصویر را در همان
 * ۴۶۰ پیکسل مقیاس می‌کرد — کنسولِ کوچک وسطِ زمینهٔ سیاهِ بزرگ.
 *
 * این تست‌ها روی **HTMLِ رندرشده** قضاوت می‌کنند نه کدِ ۲۰۰ — درسِ ثبت‌شدهٔ
 * پروژه: صفحه بارها ۲۰۰ داده و جاوااسکریپت/استایلش مرده بوده.
 */
class CloudConsoleFullscreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** سرویسِ ابریِ تحویل‌شده + بلیتِ معتبرِ کنسول */
    private function consoleUrl(Customer $c): string
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی تستی', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $plan->id, 'activated_at' => now(),
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '42',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '203.0.113.45',
            'status' => 'running', 'password_seen' => true,
        ]);

        $ticket = 'tkt'.random_int(100000, 999999);
        Cache::put('cloud-console:'.$s->id.':'.$ticket,
            ['url' => 'wss://example.invalid/vnc', 'password' => 'x'], 300);

        return route('account.cloud.console.view', [$s, 't' => $ticket], false);
    }

    private function html(): string
    {
        $c = $this->customer();

        return $this->actingAs($c, 'customer')
            ->get($this->consoleUrl($c))->assertOk()->getContent();
    }

    /** 🔴 خودِ باگ: ارتفاعِ inline که هیچ قاعده‌ای نمی‌توانست بشکندش */
    public function test_screen_height_is_not_inline(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('id="vnc-screen" style=', $html,
            'ارتفاعِ inline یعنی تمام‌صفحه دوباره می‌شکند');
        $this->assertStringNotContainsString('height:460px', $html);
    }

    public function test_fullscreen_rules_exist_for_both_vendor_prefixes(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('#vnc-wrap:fullscreen #vnc-screen{ height:100vh }', $html);
        $this->assertStringContainsString('#vnc-wrap:-webkit-full-screen #vnc-screen{ height:100vh }', $html);
    }

    /**
     * ⚠️ اگر این دو با کاما در یک قاعده جمع شوند، مرورگری که یکی از سلکتورها را
     * نشناسد **کلِ قاعده** را دور می‌ریزد و تمام‌صفحه بی‌صدا می‌شکند.
     */
    public function test_prefixed_selectors_are_separate_rules_not_a_comma_list(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString(':fullscreen,', $html);
        $this->assertStringNotContainsString(':-webkit-full-screen,', $html);
    }

    /** ارتفاعِ عادی هم باید با نمایشگر بزرگ شود، نه ۴۶۰ ثابت */
    public function test_normal_height_scales_with_viewport(): void
    {
        $this->assertStringContainsString('height:clamp(460px, 68vh, 900px)', $this->html());
    }

    /** برچسبِ دکمه باید داخلِ span باشد وگرنه JS آیکون را هم پاک می‌کند */
    public function test_button_label_is_wrapped_so_js_can_swap_only_the_text(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '~id="vnc-full".*?<svg class="icon"><use href="#i-monitor"/></svg><span>~s', $html,
            'بدونِ span، تعویضِ متن آیکونِ SVG را هم می‌بلعد');
    }

    /** متنِ خروج از تمام‌صفحه باید ترجمه‌شده به JS برسد، نه کلیدِ خام */
    public function test_fullscreen_labels_reach_javascript_translated(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('btn_exit_fullscreen', $html);

        // ⚠️ `@json()` غیرِ ASCII را به `\uXXXX` می‌برد، پس متنِ فارسی عیناً در
        // HTML نیست. با همان تبدیل می‌سنجیم تا تست به نحوهٔ اسکیپ گره نخورد
        // (رشته نیم‌فاصله هم دارد که جداگانه اسکیپ می‌شود).
        $encoded = trim(json_encode('خروج از تمام‌صفحه', JSON_UNESCAPED_SLASHES), '"');
        $this->assertStringContainsString($encoded, $html, 'ترجمه باید به JS برسد');

        $this->assertStringNotContainsString('ui.vnc_btn_exit_fullscreen', $html,
            'کلیدِ خام یعنی ترجمه جا افتاده');
    }

    /** بلیتِ نامعتبر نباید کنسول بدهد */
    public function test_invalid_ticket_is_rejected(): void
    {
        $c = $this->customer();
        $url = $this->consoleUrl($c);

        $this->actingAs($c, 'customer')
            ->get(preg_replace('~t=.*~', 't=forged', $url))
            ->assertRedirect();
    }
}
