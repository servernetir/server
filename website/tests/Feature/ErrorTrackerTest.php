<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ردیابِ خطا — دو خرابیِ ساختاری که سال‌ها پنهان بود.
 *
 * ۱) ۴۰۴ و ۵۰۰ یک فایل و یک سقف داشتند. ۴۰۴ در هر سایتی ده‌ها برابرِ ۵۰۰ است
 *    (خزنده، لینکِ قدیمی، اسکنرِ خودکار)، پس سیلِ ۴۰۴ همان خطاهایی را که ابزار
 *    برایشان ساخته شده از پنجره بیرون می‌انداخت.
 *
 * ۲) هیچ راهی برای ثبتِ خرابیِ **گرفته‌شده** نبود. ولی مسیرهای پول و تحویلِ این
 *    پروژه همه بگیر-و-ادامه‌بده‌اند، پس ردیاب دقیقاً نسبت به همان کلاسی از باگ
 *    نابینا بود که بیشترین ضرر را زده: «شکست نخورد، فقط اتفاق نیفتاد».
 */
class ErrorTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ErrorTracker::clear();
    }

    protected function tearDown(): void
    {
        ErrorTracker::clear();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    // ═══════════ کانالِ خرابیِ گرفته‌شده ═══════════

    public function test_note_records_a_caught_failure(): void
    {
        ErrorTracker::note('payment', new \RuntimeException('فعال‌سازی نشد'), ['invoice' => 42]);

        $rows = ErrorTracker::recent(10, 'error');

        $this->assertCount(1, $rows);
        $this->assertSame('incident', $rows[0]['type']);
        $this->assertSame('payment', $rows[0]['area']);
        $this->assertSame('فعال‌سازی نشد', $rows[0]['message']);
        $this->assertSame(42, $rows[0]['ctx']['invoice']);
    }

    public function test_note_accepts_a_plain_string(): void
    {
        ErrorTracker::note('provision', 'سرور ساخته نشد چون ظرفیت نبود');

        $rows = ErrorTracker::recent(10, 'error');

        $this->assertSame('سرور ساخته نشد چون ظرفیت نبود', $rows[0]['message']);
        $this->assertNull($rows[0]['class']);
    }

    /** ردیاب هرگز نباید خودش منبعِ خطا شود */
    public function test_note_never_throws(): void
    {
        ErrorTracker::note('x', new \RuntimeException(str_repeat('ط', 5000)), ['a' => null]);

        $this->assertNotEmpty(ErrorTracker::recent(10, 'error'));
    }

    // ═══════════ جداییِ ۴۰۴ از خطای واقعی ═══════════

    /**
     * 🔴 قلبِ ماجرا: سیلِ ۴۰۴ نباید خطای واقعی را از پنجره بیرون بیندازد.
     *
     * روی همین نصب نسبت ۴۶۱ به ۲ بود؛ یعنی هر ۵۰۰ی که رخ می‌داد، پیش از آنکه
     * کسی نگاه کند از فایل بیرون رفته بود.
     */
    public function test_a_flood_of_404s_does_not_evict_real_errors(): void
    {
        // 🔴 برش را قطعی کن. در پروداکشن هر نوشتن با احتمالِ ۱ به ۲۵ برش می‌زند،
        // و این تست دقیقاً روی **فشارِ برش** ادعا دارد. با آهنگِ تصادفی، ادعا
        // گاهی سنجیده می‌شود و گاهی نه — یعنی سبزش هیچ‌چیز را ثابت نمی‌کند.
        ErrorTracker::trimOneWriteIn(1);

        ErrorTracker::note('payment', 'خطای مهمِ اول');

        for ($i = 0; $i < 600; $i++) {
            ErrorTracker::notFound(
                \Illuminate\Http\Request::create('https://servernet.cloud/gone-'.$i)
            );
        }

        $errors = ErrorTracker::recent(150, 'error');
        $messages = array_column($errors, 'message');

        $this->assertContains('خطای مهمِ اول', $messages,
            'سیلِ ۴۰۴ نباید خطای واقعی را بیرون بیندازد — دو فایلِ جدا لازم است');

        // و ثابت کن فشار واقعاً ساخته شد: سیل باید ردیف‌های **خودش** را بیرون
        // انداخته باشد. بی‌این، روزی که سقف یا تعدادِ سیل عوض شود، تستِ بالا
        // بی‌صدا بی‌معنی می‌شود — سبز می‌مانَد چون هیچ‌چیز بریده نشده.
        $this->assertLessThan(600, count(ErrorTracker::recent(600, 'notfound')),
            'اگر خودِ ۴۰۴ها بریده نشده باشند، این تست هیچ فشاری نساخته است');
    }

    public function test_404s_land_in_their_own_bucket(): void
    {
        ErrorTracker::notFound(\Illuminate\Http\Request::create('https://servernet.cloud/nope'));
        ErrorTracker::note('payment', 'خطای واقعی');

        $this->assertCount(1, ErrorTracker::recent(50, 'notfound'));
        $this->assertCount(1, ErrorTracker::recent(50, 'error'));
    }

    public function test_clear_empties_both_buckets(): void
    {
        ErrorTracker::notFound(\Illuminate\Http\Request::create('https://servernet.cloud/nope'));
        ErrorTracker::note('payment', 'خطای واقعی');

        ErrorTracker::clear();

        $this->assertEmpty(ErrorTracker::recent(50, 'notfound'));
        $this->assertEmpty(ErrorTracker::recent(50, 'error'));
    }

    // ═══════════ صفحهٔ مدیریت ═══════════

    public function test_the_admin_page_shows_silent_failures(): void
    {
        ErrorTracker::note('provision', 'سرورِ مشتری ساخته نشد', ['service' => 77]);

        $html = $this->actingAs($this->admin())->get('/admin/errors')->assertOk()->getContent();

        $this->assertStringContainsString('خرابی‌های خاموش', $html);
        $this->assertStringContainsString('سرورِ مشتری ساخته نشد', $html);
        $this->assertStringContainsString('service=77', $html);
    }

    public function test_the_page_still_renders_with_nothing_recorded(): void
    {
        $this->actingAs($this->admin())->get('/admin/errors')->assertOk();
    }
}
