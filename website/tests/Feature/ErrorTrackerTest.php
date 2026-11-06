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

    public function test_console_failure_has_no_fabricated_customer_request_or_http_status(): void
    {
        $argv = $_SERVER['argv'] ?? null;
        try {
            $_SERVER['argv'] = ['artisan', 'cloud:sync', '--token=private-argument'];
            ErrorTracker::exception(new \RuntimeException('Background failure'), null);
        } finally {
            if ($argv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $argv;
            }
        }
        $row = ErrorTracker::recent(1)[0];
        $this->assertSame('console', $row['source']);
        $this->assertSame('cloud:sync', $row['command']);
        $this->assertSame('console', $row['who']);
        $this->assertNull($row['url']);
        $this->assertNull($row['status']);
        $this->assertStringNotContainsString('private-argument', json_encode($row));
    }

    public function test_real_http_failure_stays_an_http_failure_even_for_a_scheduler_like_message(): void
    {
        $request = \Illuminate\Http\Request::create('https://console.servernet.cloud/account/services', 'POST');
        ErrorTracker::exception(new \RuntimeException('Scheduled command [customer-supplied-text]'), $request);
        $row = ErrorTracker::recent(1)[0];
        $this->assertFalse(ErrorTracker::isConsoleRecord($row));
        $this->assertSame('http', $row['source']);
        $this->assertSame(500, $row['status']);
        $this->assertSame('POST', $row['method']);
    }

    public function test_bale_webhook_secret_is_redacted_from_http_error_urls(): void
    {
        $token = str_repeat('a', 32);
        $request = \Illuminate\Http\Request::create('https://servernet.cloud/bale/webhook/'.$token, 'POST');

        ErrorTracker::exception(new \RuntimeException('Webhook failure'), $request);

        $row = ErrorTracker::recent(1)[0];
        $this->assertSame('https://servernet.cloud/bale/webhook/[redacted]', $row['url']);
        $this->assertStringNotContainsString($token, json_encode($row));
    }

    public function test_legacy_console_records_are_recognized_without_guessing_from_guest_or_loopback(): void
    {
        $this->assertTrue(ErrorTracker::isConsoleRecord(['frame' => 'artisan:16']));
        $this->assertTrue(ErrorTracker::isConsoleRecord(['message' => 'Scheduled command [artisan example:run] failed.']));
        $this->assertTrue(ErrorTracker::isConsoleRecord(['file' => 'Command line code:1']));
        $this->assertFalse(ErrorTracker::isConsoleRecord(['who' => 'guest', 'ip' => '127.0.0.1', 'url' => 'https://servernet.cloud']));
    }

    public function test_admin_page_keeps_console_failures_visible_in_their_own_section(): void
    {
        ErrorTracker::exception(new \RuntimeException('Background-only sentinel'), null);
        $html = $this->actingAs($this->admin())->get('/admin/errors')->assertOk()->getContent();
        $this->assertStringContainsString('خطاهای فرمان و زمان‌بند', $html);
        $this->assertStringContainsString('Background-only sentinel', $html);
        $this->assertStringContainsString('۰ خطای وب', $html);
        $this->assertCount(1, ErrorTracker::recent(10));
    }

    public function test_invalid_utf8_does_not_silently_drop_an_incident(): void
    {
        ErrorTracker::note('provider', 'Invalid byte: '.chr(255));
        $this->assertCount(1, ErrorTracker::recent(10));
        $this->assertStringStartsWith('Invalid byte:', ErrorTracker::recent(1)[0]['message']);
    }

    public function test_a_console_failure_flood_cannot_evict_or_hide_a_web_failure(): void
    {
        ErrorTracker::trimOneWriteIn(1);
        ErrorTracker::exception(new \RuntimeException('Rare-web-failure-sentinel'),
            \Illuminate\Http\Request::create('https://servernet.cloud/account/services'));
        for ($i = 0; $i < 500; $i++) {
            ErrorTracker::exception(new \RuntimeException('Console failure '.$i), null);
        }
        $rows = ErrorTracker::recent(1000);
        $this->assertCount(401, $rows);
        $this->assertContains('Rare-web-failure-sentinel', array_column($rows, 'message'));
        for ($i = 0; $i < 500; $i++) {
            ErrorTracker::note('notify', 'Repeated caught failure '.$i);
        }
        $this->assertCount(801, ErrorTracker::recent(1200));
        $this->actingAs($this->admin())->get('/admin/errors')->assertOk()->assertSee('Rare-web-failure-sentinel');
    }

    public function test_concurrent_append_and_trim_retain_a_full_valid_window(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec') || ! is_executable('/usr/bin/true')) {
            $this->markTestSkipped('Concurrent tracker regression requires pcntl and /usr/bin/true.');
        }
        ErrorTracker::trimOneWriteIn(1);
        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                for ($entry = 0; $entry < 160; $entry++) {
                    ErrorTracker::note('parallel', 'worker-'.$worker.'-'.$entry);
                }
                // Avoid inherited PHPUnit shutdown handlers deleting the parent
                // test's isolated tracker directory while its siblings write.
                pcntl_exec('/usr/bin/true');
                exit(1);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $rows = ErrorTracker::recent(1000);
        $this->assertCount(400, $rows);
        $this->assertCount(400, array_unique(array_column($rows, 'message')));
        ErrorTracker::note('parallel', 'parent-after-trim');
        $this->assertSame('parent-after-trim', ErrorTracker::recent(1)[0]['message']);
    }
}
