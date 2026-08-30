<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 `/system/health` یک اهرمِ از‌کاراندازی بود.
 *
 * ═══ اندازه‌گیریِ واقعی (پروداکشن، مرداد ۱۴۰۵) ═══
 *
 * روت **۵۲٫۸ ثانیه** طول می‌کشید و ۲۰۰ برمی‌گرداند. بدترین‌حالتش بیشتر است:
 * پنج هدف × ۱۲ ثانیه + نگهبانِ رله ۱۵ + امضاشده ۲۵ ⇒ تا ۱۰۰ ثانیه.
 *
 * و `throttle:tools` یعنی **۴۰ درخواست در دقیقه از یک آی‌پی، بی‌هیچ احرازی**.
 * یعنی یک نفر به‌تنهایی می‌توانست ده‌ها پروسهٔ PHP را هرکدام ~۵۳ ثانیه اشغال
 * کند و هم‌زمان ۷ تماسِ خروجی به آی‌پی‌پنل و زرین‌پال و زحل بفرستد.
 *
 * ⚠️ ادعا روی **تعدادِ تماسِ خروجی** است، نه روی زمان یا کدِ وضعیت. زمان در
 * تست بی‌معنی است (همه‌چیز فِیک است) و کدِ ۲۰۰ در هر دو حالت یکی است — یعنی
 * تستی که آن‌ها را بسنجد، با کشِ **حذف‌شده** هم سبز می‌مانَد.
 */
class SystemHealthIsCheapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_hit_makes_no_outbound_calls_at_all(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->get('/system/health')->assertOk();
        $firstBatch = count(Http::recorded());

        $this->assertGreaterThan(0, $firstBatch,
            'سنجشِ اول باید واقعاً تماس بگیرد، وگرنه این روت هیچ‌چیز را پایش نمی‌کند');

        $this->get('/system/health')->assertOk();

        $this->assertSame($firstBatch, count(Http::recorded()),
            'درخواستِ دوم دوباره به بیرون وصل شد — کش کار نمی‌کند و روت هنوز اهرمِ از‌کاراندازی است');
    }

    /** پاسخِ کش‌شده باید **بگوید** که کش‌شده است و چند ثانیه سن دارد. */
    public function test_the_answer_says_how_stale_it_is(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $fresh = $this->get('/system/health')->assertOk()->json();
        $this->assertFalse($fresh['cached'], 'اولین پاسخ نباید «کش‌شده» علامت بخورد');

        $second = $this->get('/system/health')->assertOk()->json();
        $this->assertTrue($second['cached']);
        $this->assertIsInt($second['age_seconds'],
            'بی‌سن، پایشگر نمی‌داند عددی که می‌بیند تازه است یا عکسِ یک دقیقه پیش');
    }

    /**
     * ⚠️ کش روی همان دیتابیسی است که این روت قرار است سلامتش را گزارش کند.
     * اگر کش بخوابد، گزارش نباید بخوابد — وگرنه ناظر دقیقاً وقتی ساکت می‌شود
     * که بیشترین نیاز به آن هست.
     */
    public function test_a_broken_cache_falls_back_to_a_live_probe(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache down'));

        $res = $this->get('/system/health')->assertOk()->json();

        $this->assertTrue($res['cache_error'] ?? false, 'شکستِ کش باید صریح گزارش شود');
        $this->assertGreaterThan(0, count(Http::recorded()), 'با کشِ خراب باید سنجشِ زنده انجام شود');
    }
}
