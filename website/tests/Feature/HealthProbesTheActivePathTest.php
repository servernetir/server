<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 صفحهٔ سلامت باید مسیرِ **فعال** را بسنجد، نه هر مسیری که در .env مانده.
 *
 * ═══ خرابیِ واقعی که این می‌بندد ═══
 *
 * تا شهریور ۱۴۰۵، `/system/health` همیشه رلهٔ قدیمیِ `servernet.ir` را می‌زد
 * چون `SMS_RELAY_URL` هنوز در .env بود. آن فایل مدت‌ها پیش بازنشسته شده بود و
 * ۴۱۰ می‌داد، پس `relay.guard.ok` روی پروداکشن **همیشه false** بود.
 *
 * دو خرابی هم‌زمان، و دومی خطرناک‌تر:
 *
 *   · آژیرِ همیشه‌روشن برای مسیری که هیچ‌کس استفاده نمی‌کند. آژیری که همیشه
 *     قرمز است، آژیرِ بعدی را هم می‌بلعد — همان الگویی که این پروژه بارها خورده.
 *
 *   · 🔴 مسیری که **واقعاً** پیامک را می‌برد (n8n) هیچ ناظری نداشت. یعنی
 *     صفحهٔ سلامت دربارهٔ تنها چیزی که مهم بود ساکت بود، و این سکوت شبیهِ
 *     «سالم است» دیده می‌شد.
 */
class HealthProbesTheActivePathTest extends TestCase
{
    private function useN8n(): void
    {
        config([
            'services.sms.driver'            => 'n8n_relay',
            'services.sms.n8n_relay.url'     => 'https://flow.example/webhook/relay',
            'services.sms.n8n_relay.secret'  => 'shhh',
            // ⚠️ عمداً پر می‌مانَد: تلهٔ اصلی همین بود که این‌ها در .env بمانند
            'services.sms.relay_url'         => 'https://legacy.example/sms-relay.php',
            'services.sms.relay_secret'      => 'old',
        ]);
    }

    private function relay(): array
    {
        return (array) $this->get('/system/health')->assertOk()->json('relay');
    }

    /** 🔴 با درایورِ n8n، رلهٔ بازنشسته نباید اصلاً زده شود. */
    public function test_the_retired_relay_is_not_probed_when_it_is_not_the_active_path(): void
    {
        $this->useN8n();

        Http::fake([
            'flow.example/*'   => Http::response(['status' => 'ignored', 'reason' => 'bad_signature'], 200),
            'legacy.example/*' => Http::response('gone', 410),
        ]);

        $relay = $this->relay();

        $this->assertSame('n8n_relay', $relay['active_path'] ?? null);
        $this->assertTrue((bool) ($relay['guard']['ok'] ?? false),
            'مسیرِ فعال سالم بود ولی گزارش قرمز داد');

        // ⚠️ `assertNothingSent` کال‌بک نمی‌گیرد و «هیچ درخواستی» را ادعا می‌کند —
        //    که این‌جا غلط است چون به n8n که درخواست می‌رود. `assertNotSent` درست است.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'legacy.example'));
    }

    /**
     * 🔴 و پاکتِ سنجش نباید بتواند پیامک بفرستد.
     *
     * سنجشی که خودش عارضه داشته باشد سنجش نیست: امضا ۶۴ صفر است، پس ورک‌فلو
     * پیش از هر ارسالی ردش می‌کند. رلهٔ قدیمی پاکتِ **امضاشده** می‌فرستاد و فقط
     * به این تکیه می‌کرد که آی‌پی‌پنل بدنهٔ بی‌معنا را رد کند.
     */
    public function test_the_probe_envelope_can_never_send_anything(): void
    {
        $this->useN8n();

        Http::fake(['flow.example/*' => Http::response(['status' => 'ignored', 'reason' => 'bad_signature'], 200)]);

        $this->relay();

        /*
        | ⚠️ اول به درخواستِ n8n **فیلتر** می‌شود.
        |
        | صفحهٔ سلامت چند مقصدِ دیگر را هم می‌زند (آی‌پی‌پنل، زرین‌پال، بله…).
        | نسخهٔ اولِ همین تست ادعاها را داخلِ `assertSent` گذاشت و روی اولین
        | درخواستِ نامربوط شکست — یعنی دربارهٔ چیزی حرف می‌زد که نمی‌سنجید.
        */
        $env = null;

        Http::assertSent(function ($r) use (&$env) {
            if (! str_contains($r->url(), 'flow.example')) {
                return false;
            }

            $env = (string) data_get($r->data(), 'envelope');

            return true;
        });

        $this->assertStringStartsWith('SMS_RELAY_V1:', (string) $env);
        $this->assertStringEndsWith(str_repeat('0', 64), (string) $env,
            'امضا واقعی است — این پاکت می‌تواند پیامک بفرستد');
    }

    /**
     * ⚠️ کدِ ۲۰۰ کافی نیست.
     *
     * ورک‌فلو برای ردشدن هم ۲۰۰ می‌دهد. اگر فقط به کدِ HTTP نگاه کنیم، یک
     * ورک‌فلوی نیمه‌ویرایش‌شده که همه‌چیز را دور می‌ریزد «سالم» گزارش می‌شود.
     */
    public function test_a_200_with_the_wrong_body_is_not_healthy(): void
    {
        $this->useN8n();

        Http::fake(['flow.example/*' => Http::response(['status' => 'ignored', 'reason' => 'no_text_message'], 200)]);

        $this->assertFalse((bool) ($this->relay()['guard']['ok'] ?? true),
            'پاسخِ ۲۰۰ با بدنهٔ نامربوط «سالم» شمرده شد');
    }

    /** و وبهوکِ در دسترس‌نبودن باید قرمز باشد — نه اینکه صفحه بشکند. */
    public function test_an_unreachable_webhook_is_red_not_fatal(): void
    {
        $this->useN8n();

        Http::fake(['flow.example/*' => fn () => throw new \RuntimeException('cURL error 28')]);

        $relay = $this->relay();

        $this->assertFalse((bool) ($relay['guard']['ok'] ?? true));
        $this->assertSame(0, $relay['guard']['http'] ?? null);
    }

    /**
     * ⚠️ و درایورِ قدیمی هنوز مسیرِ خودش را داشته باشد.
     *
     * اگر روزی برگشتیم، نباید معلوم شود ناظرش را هم با خودش برده‌ایم.
     */
    public function test_the_legacy_path_is_still_probed_when_it_is_the_active_one(): void
    {
        config([
            'services.sms.driver'       => 'relay',
            'services.sms.relay_url'    => 'https://legacy.example/sms-relay.php',
            'services.sms.relay_secret' => 'old',
        ]);

        Http::fake(['legacy.example/*' => Http::response(['reason' => 'no_signature'], 401)]);

        $relay = $this->relay();

        $this->assertSame('relay', $relay['active_path'] ?? null);
        $this->assertTrue((bool) ($relay['guard']['ok'] ?? false));
    }

    /** ⚠️ درایورِ بی‌رله (مثلاً `log`) باید صریح بگوید نسنجید، نه اینکه قرمز باشد. */
    public function test_a_driver_without_a_relay_reports_that_plainly(): void
    {
        config([
            'services.sms.driver'       => 'log',
            'services.sms.relay_url'    => '',
            'services.sms.relay_secret' => '',
        ]);

        Http::fake();

        $relay = $this->relay();

        $this->assertSame('log', $relay['active_path'] ?? null);
        $this->assertFalse($relay['configured'] ?? true);
    }
}
