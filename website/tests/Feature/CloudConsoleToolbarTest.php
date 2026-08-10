<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * نوارِ ابزارِ کنسول: بازگشت، چسباندن، و آی‌پی با پرچمِ کشور.
 *
 * ⚠️ این تست‌ها **محتوای واقعیِ رندرشده** را می‌سنجند نه کدِ ۲۰۰. درسِ
 * گران‌قیمتِ این پروژه: صفحه بارها ۲۰۰ داده و چیزی که باید، داخلش نبوده.
 */
class CloudConsoleToolbarTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠️ نامش `setup` نبود: با `setUp()`ِ PHPUnit تصادم می‌کند (نامِ متد در PHP حساس به حروف نیست) */
    private function makeServer(): array
    {
        $c = Customer::create([
            'email' => 'c'.random_int(100, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CloudLocation::create([
            'code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein',
            'is_active' => true, 'sort' => 0,
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور ابری', 'currency_code' => 'IRT',
            'price' => 1000000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'p1', 'provider_ref' => '1',
            'location_code' => 'de-falkenstein', 'ipv4' => '203.0.113.7',
            'status' => 'running',
        ]);

        // ⚠️ کنسول بدونِ بلیتِ معتبر ریدایرکت می‌کند و اصلاً رندر نمی‌شود.
        // کلید همان چیزی است که کنترلر می‌سازد: cloud-console:{id}:{ticket}
        Cache::put('cloud-console:'.$s->id.':tkt',
            ['url' => 'wss://example.test/vnc', 'password' => null], now()->addMinutes(5));

        return [$c, $s];
    }

    private function html(): string
    {
        [$c, $s] = $this->makeServer();

        return $this->actingAs($c, 'customer')
            ->get(route('account.cloud.console.view', [$s, 't' => 'tkt']))
            ->assertOk()->getContent();
    }

    public function test_the_console_shows_a_back_link_to_the_server_page(): void
    {
        $this->assertStringContainsString(__('ui.vnc_back'), $this->html());
    }

    /** 🔴 آی‌پی: تنها چیزی که دو کنسولِ باز را از هم جدا می‌کند */
    public function test_the_console_shows_the_server_ip(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('203.0.113.7', $html);
        $this->assertStringContainsString('id="vnc-ip"', $html);
    }

    public function test_the_console_shows_the_country_flag_and_location(): void
    {
        $html = $this->html();

        // ⚠️ اموجی بود و شد تصویر: پرچمِ اموجی روی ویندوز «D E» رندر می‌شود و
        // این نوار دقیقاً برای این هست که دو کنسولِ باز را از هم جدا کند.
        $this->assertStringContainsString('src="/assets/flags/de.svg"', $html);
        $this->assertStringContainsString('فالکن‌اشتاین', $html);
    }

    public function test_the_paste_box_is_present_and_starts_hidden(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="vnc-paste-box"', $html);
        $this->assertMatchesRegularExpression('~id="vnc-paste-box"\s+hidden~', $html,
            'کادرِ چسباندن نباید از اول باز باشد و کنسول را بپوشاند');
        $this->assertStringContainsString(__('ui.vnc_btn_paste'), $html);
    }

    /**
     * ⚠️ چسباندن باید **تایپ** کند نه کلیپ‌بوردِ VNC بزند: کلیپ‌بورد به agentِ
     * مهمان نیاز دارد و روی سرورِ تازه‌نصب — همان‌جا که کاربر بیشترین نیاز را
     * دارد — هیچ agentی نیست.
     */
    public function test_paste_types_keystrokes_rather_than_using_the_vnc_clipboard(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('sendKey', $html);
        $this->assertStringNotContainsString('clipboardPasteFrom', $html);
    }

    /** سرورِ بی‌آی‌پی نباید صفحه را بشکند */
    public function test_a_console_without_an_ip_still_renders(): void
    {
        $c = Customer::create([
            'email' => 'n'.random_int(100, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور', 'currency_code' => 'IRT',
            'price' => 1, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
        ]);
        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'p1', 'provider_ref' => '2',
            'location_code' => null, 'ipv4' => null, 'status' => 'running',
        ]);

        Cache::put('cloud-console:'.$s->id.':tkt',
            ['url' => 'wss://example.test/vnc', 'password' => null], now()->addMinutes(5));

        $this->actingAs($c, 'customer')->get(route('account.cloud.console.view', [$s, 't' => 'tkt']))->assertOk();
    }
}
