<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\OvhClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * زیرساختِ ۴ — OVHcloud.
 *
 * 🔴 چرا امضا مهم‌ترین چیزِ این فایل است: OVH هر درخواست را جداگانه امضا
 * می‌کند و اگر امضا غلط باشد، **۴۰۳ِ بی‌توضیح** می‌دهد — نه می‌گوید کدام جزء
 * غلط بود، نه اینکه اصلاً مشکل از امضاست. بدونِ تستِ دقیقِ فرمول، عیب‌یابی‌اش
 * ساعت‌ها حدس‌زدن است.
 */
class OvhClientTest extends TestCase
{
    use RefreshDatabase;

    private const AK = 'appkey123';
    private const AS = 'appsecret456';
    private const CK = 'consumer789';

    private function configure(): void
    {
        Setting::putSecret('ovh_app_key', self::AK);
        Setting::putSecret('ovh_app_secret', self::AS);
        Setting::putSecret('ovh_consumer_key', self::CK);
    }

    private function fake(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/1.0/auth/time' => Http::response((string) time())] + $stubs);
    }

    private function client(): OvhClient
    {
        return app(OvhClient::class);
    }

    // ═══════════ پیکربندی ═══════════

    public function test_it_is_not_configured_without_all_three_keys(): void
    {
        Setting::putSecret('ovh_app_key', self::AK);
        Setting::putSecret('ovh_app_secret', self::AS);

        // دو کلید از سه‌تا یعنی امضای همیشه‌غلط — بهتر است اصلاً تلاش نکنیم
        $this->assertFalse($this->client()->isConfigured());

        Setting::putSecret('ovh_consumer_key', self::CK);
        $this->assertTrue($this->client()->isConfigured());
    }

    public function test_an_unconfigured_client_fails_cleanly(): void
    {
        $r = $this->client()->serverStatus('vps-1');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('تنظیم نشده', $r['message']);
    }

    // ═══════════ امضا ═══════════

    /**
     * 🔴 فرمولِ رسمی، جزء‌به‌جزء:
     *   `'$1$' . sha1(AS + '+' + CK + '+' + METHOD + '+' + URL + '+' + BODY + '+' + TS)`
     *
     * `URL` باید **کاملِ** آدرس باشد (با https:// و کوئری)، نه مسیرِ تنها.
     */
    public function test_every_request_carries_a_correct_signature(): void
    {
        $this->configure();
        $this->fake(['*/1.0/vps' => Http::response(['vps-1'])]);

        $this->client()->listServers();

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/1.0/vps')) {
                return true;                       // فقط همان یک درخواست را می‌سنجیم
            }

            $ts = $request->header('X-Ovh-Timestamp')[0] ?? '';

            $expected = '$1$'.sha1(implode('+', [
                self::AS, self::CK, 'GET', $request->url(), '', $ts,
            ]));

            return ($request->header('X-Ovh-Application')[0] ?? '') === self::AK
                && ($request->header('X-Ovh-Consumer')[0] ?? '') === self::CK
                && ($request->header('X-Ovh-Signature')[0] ?? '') === $expected;
        });
    }

    /** ⚠️ بدنه باید **دقیقاً** همان چیزی باشد که امضا شده */
    public function test_a_post_signs_the_exact_body_it_sends(): void
    {
        $this->configure();
        $this->fake(['*/1.0/vps/*/reinstall' => Http::response([])]);

        $this->client()->rebuild('vps-1', 'img-99');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/reinstall')) {
                return true;
            }

            $ts = $request->header('X-Ovh-Timestamp')[0] ?? '';
            $body = $request->body();

            $expected = '$1$'.sha1(implode('+', [
                self::AS, self::CK, 'POST', $request->url(), $body, $ts,
            ]));

            return ($request->header('X-Ovh-Signature')[0] ?? '') === $expected
                && str_contains($body, 'img-99');
        });
    }

    /** درخواستِ بی‌بدنه باید رشتهٔ **خالی** را امضا کند، نه "null" یا "[]" */
    public function test_a_bodyless_post_signs_an_empty_body(): void
    {
        $this->configure();
        $this->fake(['*/1.0/vps/*/reboot' => Http::response([])]);

        $this->client()->power('vps-1', 'reboot');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/reboot')) {
                return true;
            }

            $ts = $request->header('X-Ovh-Timestamp')[0] ?? '';
            $expected = '$1$'.sha1(implode('+', [self::AS, self::CK, 'POST', $request->url(), '', $ts]));

            return ($request->header('X-Ovh-Signature')[0] ?? '') === $expected;
        });
    }

    // ═══════════ رفتار ═══════════

    public function test_it_lists_servers_with_details(): void
    {
        $this->configure();
        $this->fake([
            '*/1.0/vps' => Http::response(['vps-abc']),
            '*/1.0/vps/vps-abc' => Http::response([
                'displayName' => 'سرور امیر', 'state' => 'running',
                'model' => ['name' => 'VPS-2'], 'zone' => 'gra',
            ]),
            '*/1.0/vps/vps-abc/ips' => Http::response(['203.0.113.7']),
        ]);

        $r = $this->client()->listServers();

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['servers']);
        $this->assertSame('vps-abc', $r['servers'][0]['ref']);
        $this->assertSame('سرور امیر', $r['servers'][0]['name']);
        $this->assertSame('running', $r['servers'][0]['status']);
        $this->assertSame('203.0.113.7', $r['servers'][0]['ipv4']);
    }

    /** 🔴 خطا ≠ «صفر سرور» — وگرنه گزارشِ موجودی همه را شبح می‌کند */
    public function test_a_failed_list_is_not_an_empty_list(): void
    {
        $this->configure();
        $this->fake(['*/1.0/vps' => Http::response(['message' => 'Invalid signature'], 403)]);

        $r = $this->client()->listServers();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Invalid signature', $r['message']);
        // ۴۰۳ در OVH تقریباً همیشه امضا/ساعت است؛ پیام باید راهنمایی کند
        $this->assertStringContainsString('کلید', $r['message']);
    }

    /**
     * 🔴 خریدِ خودکار **عمداً** غیرفعال است و باید `manual` بدهد نه شکست.
     *
     * مسیرِ سفارشِ OVH چندمرحله‌ای و برگشت‌ناپذیر است. تا وقتی روی حسابِ واقعی
     * آزمایش نشده، سفارش باید به صفِ دستیِ مدیر برود — نه اینکه پولی خرج شود
     * و سروری نیاید، و نه اینکه مشتری خطای عمومی ببیند.
     */
    public function test_buying_a_new_server_goes_to_the_manual_queue(): void
    {
        $this->configure();
        $this->fake([]);

        $r = $this->client()->createServer(['name' => 'sn-svc-1']);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['manual']);
        $this->assertNull($r['ref']);
        Http::assertNothingSent();
    }

    /** کاتالوگِ خودکار هم تا راستی‌آزمایی خاموش است — قیمتی که نشود خرید بدتر است */
    public function test_the_catalogue_is_explicitly_off(): void
    {
        $this->configure();

        $r = $this->client()->fetchCatalog();

        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['plans']);
    }

    // ═══════════ منطقه ═══════════

    /**
     * 🔴 `ovh-eu` / `ovh-ca` / `ovh-us` سه شرکتِ حقوقیِ جدا با پایگاهِ کاربریِ
     * جدا هستند. کلیدی که روی یکی ساخته شده روی دیگری **وجود ندارد** و پاسخش
     * همان ۴۰۳ِ بی‌توضیحِ امضاست. یعنی منطقهٔ اشتباه دقیقاً شبیهِ کلیدِ غلط
     * دیده می‌شود، و بی‌این تست هیچ‌چیز نمی‌گوید کدامش بوده.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function regions(): array
    {
        return [
            'اروپا'  => ['eu', 'https://eu.api.ovh.com/1.0/vps'],
            'کانادا' => ['ca', 'https://ca.api.ovh.com/1.0/vps'],
            'آمریکا' => ['us', 'https://api.us.ovhcloud.com/1.0/vps'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('regions')]
    public function test_each_region_talks_to_its_own_endpoint(string $region, string $expected): void
    {
        $this->configure();
        Setting::put('ovh_region', $region);
        $this->fake(['*/1.0/vps' => Http::response([])]);

        $this->client()->listServers();

        $this->assertTrue(
            collect(Http::recorded())->contains(fn ($pair) => $pair[0]->url() === $expected),
            "درخواست باید به {$expected} می‌رفت."
        );
    }

    /** پیش‌فرض باید همان رفتارِ قبلیِ کلاس بمانَد — نصبِ موجود نباید جابه‌جا شود */
    public function test_an_unset_region_still_means_europe(): void
    {
        $this->configure();
        $this->fake(['*/1.0/vps' => Http::response([])]);

        $this->client()->listServers();

        $this->assertTrue(
            collect(Http::recorded())->contains(
                fn ($pair) => $pair[0]->url() === 'https://eu.api.ovh.com/1.0/vps'
            )
        );
    }

    /** مقدارِ بی‌معنا نباید کلاینت را بترکاند؛ به پیش‌فرض برمی‌گردد */
    public function test_a_nonsense_region_falls_back_instead_of_breaking(): void
    {
        $this->configure();
        Setting::put('ovh_region', 'atlantis');
        $this->fake(['*/1.0/vps' => Http::response([])]);

        $this->client()->listServers();

        $this->assertTrue(
            collect(Http::recorded())->contains(
                fn ($pair) => $pair[0]->url() === 'https://eu.api.ovh.com/1.0/vps'
            )
        );
    }

    /**
     * ⚠️ امضا شاملِ **کاملِ** آدرس است، پس اگر روزی میزبانِ امضا و میزبانِ ارسال
     * از هم جدا شوند، هر درخواست ۴۰۳ می‌گیرد و پیام هیچ اشاره‌ای به منطقه ندارد.
     */
    public function test_the_signature_follows_the_region_it_actually_calls(): void
    {
        $this->configure();
        Setting::put('ovh_region', 'us');
        $this->fake(['*/1.0/vps' => Http::response([])]);

        $this->client()->listServers();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/1.0/vps')) {
                return true;
            }

            $ts = $request->header('X-Ovh-Timestamp')[0] ?? '';

            $expected = '$1$'.sha1(implode('+', [
                self::AS, self::CK, 'GET', 'https://api.us.ovhcloud.com/1.0/vps', '', $ts,
            ]));

            return ($request->header('X-Ovh-Signature')[0] ?? '') === $expected;
        });
    }

    // ═══════════ سفیدبرچسبی ═══════════

    public function test_it_is_registered_and_never_leaks_its_name(): void
    {
        $m = app(CloudManager::class);

        $this->assertNotNull($m->driver('ovh'));
        $this->assertStringContainsString('زیرساخت', $m->label('ovh'));
        $this->assertStringNotContainsStringIgnoringCase('ovh', $m->label('ovh'));

        // نامِ واقعی فقط برای مدیر
        $this->assertStringContainsString('OVH', $m->realLabel('ovh'));
    }
}
