<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\MailboxSync;
use App\Services\SystemHealth;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 شکستِ خواندنِ صندوق نباید شبیهِ «امروز کسی ایمیل نزده» باشد.
 *
 * ═══ چرا ═══
 *
 * `mailbox:sync` ساعتی می‌دود و روی شکستِ IMAP فقط `Log::error` می‌زد و با کدِ
 * ۱ تمام می‌شد. آن لاگ روی پروداکشن ۱۰ مگابایت است و از API فایلِ cPanel
 * **خالی** برمی‌گردد — یعنی خواندنش SSH می‌خواهد. پس تنها چیزی که مدیر
 * می‌دید این بود: `/admin/mail` نامهٔ تازه‌ای ندارد.
 *
 * و آن دو حالت روی صفحه یک شکل داشتند. خرابیِ خاموشی که هفته‌ها بماند.
 *
 * ⚠️ همان قاعدهٔ ثبت‌شده در CLAUDE.md، از درِ دیگر: چیزی که فقط در لاگِ سرور
 * رد می‌گذارد، از دیدِ مدیر اصلاً وجود ندارد. و «نبودِ خبر» را «خبرِ خوب»
 * نخوان — وضعیتِ خالی یعنی این کرون هرگز کامل نشده، نه اینکه سالم است.
 */
class MailboxSyncVisibleFailureTest extends TestCase
{
    use RefreshDatabase;

    /** یک صندوقِ پیکربندی‌شده، بی‌هیچ تماسِ IMAP. */
    private function configureBoxes(): void
    {
        config(['mailboxes.accounts' => [
            ['key' => 'ceo', 'label' => 'مدیرعامل', 'user' => 'ceo@example.test', 'pass' => 'x'],
        ]]);
    }

    private function health(string $key): array
    {
        foreach (app(SystemHealth::class)->checks() as $row) {
            if (($row['key'] ?? null) === $key) {
                return $row;
            }
        }

        $this->fail("چکِ {$key} پیدا نشد");
    }

    private function saveState(array $state): void
    {
        Setting::put(MailboxSync::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    // ═══════════════ لایهٔ سلامت ═══════════════

    public function test_a_failing_mailbox_turns_the_health_check_red_with_the_real_error(): void
    {
        $this->configureBoxes();
        $this->saveState(['ceo' => ['ok' => false, 'at' => now()->toIso8601String(),
            'error' => 'AUTHENTICATIONFAILED Invalid credentials']]);

        $row = $this->health('mailboxes');

        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('AUTHENTICATIONFAILED', $row['detail'],
            'متنِ واقعیِ خطا باید در پنل باشد — وگرنه رفعش به SSH گره می‌خورد');
        $this->assertStringContainsString('مدیرعامل', $row['detail'], 'باید بگوید کدام صندوق');
        $this->assertNotEmpty($row['links'], 'باید میان‌بری به صفحهٔ صندوق‌ها بدهد');
    }

    public function test_a_healthy_mailbox_stays_green(): void
    {
        $this->configureBoxes();
        $this->saveState(['ceo' => ['ok' => true, 'at' => now()->toIso8601String()]]);

        $this->assertTrue($this->health('mailboxes')['ok']);
    }

    /**
     * ⚠️ «هرگز اجرا نشده» سبز نیست — همان تلهٔ `CloudInventory`.
     *
     * کرونی که یک بار هم کامل نشده، دقیقاً شبیهِ «همه‌چیز آرام» به‌نظر می‌رسد.
     */
    public function test_a_sync_that_never_ran_is_not_reported_as_healthy(): void
    {
        $this->configureBoxes();

        $row = $this->health('mailboxes');

        $this->assertFalse($row['ok'], 'نبودِ خبر «خبرِ خوب» نیست');
        $this->assertSame('warn', $row['level'], 'ولی شدتش هم اندازهٔ خرابیِ واقعی نیست');
    }

    /** بی‌صندوقِ پیکربندی‌شده، کرون اصلاً بیدار نمی‌شود؛ هشدارش بی‌معنی است. */
    public function test_an_install_with_no_mailboxes_says_nothing(): void
    {
        config(['mailboxes.accounts' => []]);

        $this->assertTrue($this->health('mailboxes')['ok']);
    }

    // ═══════════════ صفحهٔ صندوق ═══════════════

    public function test_the_mail_page_shows_the_real_error_instead_of_looking_empty(): void
    {
        $this->configureBoxes();
        $this->saveState(['ceo' => ['ok' => false, 'at' => now()->toIso8601String(),
            'error' => 'AUTHENTICATIONFAILED Invalid credentials']]);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/mail')->assertOk()->getContent();

        $this->assertStringContainsString('AUTHENTICATIONFAILED', $html);
        $this->assertStringContainsString('mk-alert', $html, 'کلاسِ نوار در CSS هست؛ بی‌آن بی‌استایل رندر می‌شود');
    }

    public function test_the_mail_page_is_quiet_when_every_box_reads_fine(): void
    {
        $this->configureBoxes();
        $this->saveState(['ceo' => ['ok' => true, 'at' => now()->toIso8601String()]]);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/mail')->assertOk()->getContent();

        $this->assertStringNotContainsString('mk-alert', $html);
    }

    // ═══════════════ ردیابِ خطا ═══════════════

    /**
     * 🔴 گلوگاه لازم است و حذفش باگ است.
     *
     * پنجرهٔ ردیاب ۴۰۰ خط است و این کرون ساعتی می‌دود؛ رمزِ باطل هفته‌ها همان
     * می‌مانَد. بی‌گلوگاه، روزی ۲۴ خطِ تکراری بقیهٔ خطاها را بیرون می‌انداخت —
     * همان خرابیِ سیلِ ۴۰۴ که در CLAUDE.md ثبت است.
     */
    public function test_an_hourly_failure_does_not_flood_the_error_tracker(): void
    {
        $this->configureBoxes();

        $this->mock(MailboxSync::class, function ($m) {
            $m->shouldReceive('run')->andReturn(['ceo' => ['new' => 0, 'seen' => 0, 'error' => 'LOGIN failed']]);
        });

        foreach (range(1, 3) as $ignored) {
            $this->artisan('mailbox:sync')->assertExitCode(0);
        }

        $hits = collect(ErrorTracker::recent(500))
            ->filter(fn ($e) => str_contains((string) ($e['message'] ?? ''), 'صندوق خوانده نشد'));

        $this->assertCount(1, $hits, 'سه اجرا باید یک ردِ throttleشده بگذارد، نه سه تا');
        $this->assertStringContainsString('LOGIN failed', $hits->first()['message'],
            'ردِ ثبت‌شده باید متنِ واقعیِ خطا را داشته باشد، نه «خطایی رخ داد»');
    }

    // ═══════════════ کدِ خروجی ═══════════════

    /** یک صندوقِ خراب را شبیه‌سازی می‌کند، بی‌هیچ تماسِ IMAP. */
    private function mockOneBadBox(): void
    {
        $this->configureBoxes();

        $this->mock(MailboxSync::class, function ($m) {
            $m->shouldReceive('run')->andReturn(['ceo' => ['new' => 0, 'seen' => 0, 'error' => 'LOGIN failed']]);
        });
    }

    /**
     * 🔴 خرابیِ واقعی: بخشِ «خطاهای سرور (۵۰۰)» با یک رمزِ باطل پر می‌شد.
     *
     * با کدِ خروجیِ ۱، زمان‌بند هر ساعت یک استثنا می‌ساخت:
     * «Scheduled command [… mailbox:sync] failed with exit code [1]» — و آن
     * استثنا کنارِ کرشِ واقعیِ سایت می‌نشست، با متنی که هیچ نمی‌گفت. پنجرهٔ
     * ردیاب ۴۰۰ خط است، پس یک رمزِ باطلِ هفتگی ۵۰۰های واقعی را بیرون می‌انداخت.
     *
     * ⚠️ ادعای این تست «کدِ صفر» نیست، **«این خبر جای دیگری هست»** است — پس
     * هر دو نیمه سنجیده می‌شوند.
     */
    public function test_a_bad_mailbox_is_not_reported_as_a_broken_cron(): void
    {
        $this->mockOneBadBox();

        $this->artisan('mailbox:sync')->assertExitCode(0);

        $noted = collect(ErrorTracker::recent(500))
            ->contains(fn ($e) => str_contains((string) ($e['message'] ?? ''), 'صندوق خوانده نشد'));

        $this->assertTrue($noted, 'ساکت‌شدنِ کدِ خروجی نباید خبر را هم ببرد');
        $this->assertFalse($this->health('mailboxes')['ok'], 'و لایهٔ سلامت باید همچنان قرمز بمانَد');
    }

    /** اجرای دستی هنوز کدِ خروجیِ معنادار می‌خواهد. */
    public function test_strict_still_fails_for_a_human_running_it_by_hand(): void
    {
        $this->mockOneBadBox();

        $this->artisan('mailbox:sync --strict')->assertExitCode(1);
    }

    /** صندوقِ سالم در هر دو حالت سبز است. */
    public function test_a_healthy_run_succeeds_even_in_strict_mode(): void
    {
        $this->configureBoxes();

        $this->mock(MailboxSync::class, function ($m) {
            $m->shouldReceive('run')->andReturn(['ceo' => ['new' => 2, 'seen' => 9]]);
        });

        $this->artisan('mailbox:sync --strict')->assertExitCode(0);
    }

    // ═══════════════ خودِ ذخیرهٔ وضعیت ═══════════════

    /**
     * ⚠️ `--account=ceo` نباید وضعیتِ صندوق‌های دیگر را پاک کند.
     *
     * خالی‌شدنشان یعنی «هنوز اجرا نشده» و یک شکستِ زنده را از صفحه محو می‌کرد.
     */
    public function test_a_single_account_run_keeps_what_it_did_not_check(): void
    {
        $this->saveState(['support' => ['ok' => false, 'at' => now()->toIso8601String(), 'error' => 'قدیمی']]);

        // ⚠️ بی‌تماسِ IMAP: این ماشین به هر پورتی «وصل» می‌شود، پس فیکسچرِ
        //    شبکه‌ای این‌جا نتیجهٔ دروغ می‌دهد (بخشِ تست در CLAUDE.md).
        $sync = new class extends MailboxSync
        {
            public function rememberOnly(array $results): void
            {
                $this->rememberState($results);
            }
        };

        $sync->rememberOnly(['ceo' => ['new' => 0, 'seen' => 0]]);

        $state = MailboxSync::state();
        $this->assertTrue($state['ceo']['ok']);
        $this->assertFalse($state['support']['ok'], 'صندوقِ بررسی‌نشده نباید پاک شود');
    }
}
