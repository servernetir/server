<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Services\SystemHealth;
use Illuminate\Console\Scheduling\CacheAware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * لایهٔ سلامت — و باگی که ساختش را لازم کرد.
 *
 * ردیابِ خطا در یک روز ۱۳ بار `Connection refused` روی جدولِ `cache` ثبت کرد.
 * هر کدام یک دقیقهٔ کرونِ مرده بود: تحویلِ سرور، ثبتِ دامنه، فاکتورِ تمدید.
 * هیچ‌کس نفهمید، چون هیچ‌کدام خطا تولید نکردند — فقط **اتفاق نیفتادند**.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([SystemHealth::HEARTBEAT, 'health-state'] as $f) {
            @unlink(storage_path('app/'.$f));
        }

        $this->greenTheChecksThisFileIsNotAbout();
    }

    /**
     * چک‌هایی که در نصبِ خالی قرمزند ولی موضوعِ این پرونده نیستند.
     *
     * 🔴 `test_recovery_is_announced` ذاتاً به «همهٔ چک‌ها سبز» نیاز دارد،
     * پس هر چکِ تازه‌ای که در نصبِ خالی قرمز باشد آن را می‌شکند — بی‌آنکه
     * چیزی خراب شده باشد. دقیقاً همین رخ داد: چکِ `domain_margin` اضافه شد و
     * چون `DOMAIN_MARGIN_PCT` پیش‌فرضِ صفر دارد، همیشه هشدار می‌داد.
     *
     * ⚠️ **این متد نباید به یک زباله‌دانِ ساکت تبدیل شود.** اگر چکِ تازه‌ای
     * این‌جا لازم شد، اول بپرس چرا در نصبِ خالی قرمز است: گاهی جواب «تنظیمِ
     * اختیاری» است (مثلِ این)، و گاهی «پیش‌فرضِ کد غلط است» — و آن دومی یک
     * باگِ واقعی است که این تست تازه لوش داده.
     *
     * ⚠️ حاشیهٔ سود عمداً این‌جا ست می‌شود و نه در `config`: پیش‌فرضِ صفرِ
     * `DOMAIN_MARGIN_PCT` دست‌نخورده می‌مانَد تا چک روی پروداکشن واقعاً
     * هشدار بدهد. فروش به قیمتِ تمام‌شده خطِ قرمزِ کارفراست و نباید با یک
     * پیش‌فرضِ راحت‌کننده پنهان شود.
     */
    private function greenTheChecksThisFileIsNotAbout(): void
    {
        \App\Models\Setting::put('domain_margin_pct', '25');
    }

    private function beat(?\Carbon\Carbon $at = null): void
    {
        File::put(storage_path('app/'.SystemHealth::HEARTBEAT), ($at ?? now())->toDateTimeString());
    }

    /** @return array<string,array<string,mixed>> */
    private function checks(): array
    {
        $out = [];
        foreach (app(SystemHealth::class)->checks() as $c) {
            $out[$c['key']] = $c;
        }

        return $out;
    }

    // ═══════════════ 🔴 قفلِ زمان‌بند ═══════════════

    /**
     * 🔴 مهم‌ترین تستِ این فایل.
     *
     * هر کارِ زمان‌بندی‌شده `withoutOverlapping()` دارد و آن قفلش را در **کش**
     * می‌گیرد. کش پیش‌فرض `database` است. پس یک لحظه قطعیِ MariaDB کلِ
     * `schedule:run` را می‌کشت و آن دقیقه **هیچ** کاری اجرا نمی‌شد.
     *
     * این تست ثابت می‌کند قفل روی فایل نشسته — مستقل از سلامتِ دیتابیس.
     */
    public function test_the_scheduler_mutex_does_not_live_on_the_database(): void
    {
        $schedule = app(Schedule::class);

        $mutex = (fn () => $this->eventMutex)->call($schedule);

        $this->assertInstanceOf(CacheAware::class, $mutex,
            'قفلِ زمان‌بند باید بتواند انبارِ کش بگیرد');

        $store = (fn () => $this->store)->call($mutex);

        $this->assertSame('file', $store,
            '🔴 قفلِ زمان‌بند روی دیتابیس است: یک قطعیِ گذرا کلِ کرونِ آن دقیقه را می‌کشد');
    }

    /** ضربان هم نباید در کش باشد — ضربانی که با بیمار بمیرد بی‌فایده است */
    public function test_the_heartbeat_is_written_to_a_file_not_the_cache(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringNotContainsString("cache()->put('sn.schedule.last'", $console,
            'ضربان نباید در کش (یعنی دیتابیس) بنشیند');
        $this->assertStringContainsString('SystemHealth::HEARTBEAT', $console);
    }

    /** فرمانِ نگهبان باید واقعاً زمان‌بندی شده باشد — وگرنه فقط یک فایلِ بی‌مصرف است */
    public function test_the_watchdog_is_actually_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command);

        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'system:health')),
            'system:health زمان‌بندی نشده — همان تلهٔ domains:provision که یک‌بار جا افتاد');
    }

    // ═══════════════ چکِ کرون ═══════════════

    public function test_a_missing_heartbeat_is_a_failure(): void
    {
        $c = $this->checks()['cron'];

        $this->assertFalse($c['ok']);
        $this->assertSame('fail', $c['level']);
    }

    public function test_a_fresh_heartbeat_is_healthy(): void
    {
        $this->beat();

        $this->assertSame('ok', $this->checks()['cron']['level']);
    }

    public function test_a_silent_cron_is_a_failure(): void
    {
        $this->beat(now()->subMinutes(SystemHealth::CRON_SILENT_MINUTES + 5));

        $c = $this->checks()['cron'];

        $this->assertSame('fail', $c['level']);
        $this->assertStringContainsString('دقیقه پیش', $c['detail']);
    }

    // ═══════════════ صفِ دامنه ═══════════════

    private function domain(string $provision, string $status = 'pending', ?\Carbon\Carbon $touched = null): Domain
    {
        $c = Customer::create([
            'email' => 'h'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => uniqid('d').'.com',
            'sld' => 'x', 'tld' => 'com', 'status' => $status,
            'provision_status' => $provision, 'period_years' => 1,
        ]);

        if ($touched) {
            Domain::where('id', $d->id)->update(['updated_at' => $touched]);
        }

        return $d->refresh();
    }

    public function test_a_paid_domain_stuck_in_the_queue_is_a_failure(): void
    {
        $this->beat();
        $this->domain('pending', 'pending', now()->subHours(3));

        $c = $this->checks()['domains'];

        $this->assertSame('fail', $c['level']);
        $this->assertStringContainsString('ثبت نشده', $c['detail']);
    }

    /** تازه‌واردِ صف هشدار نیست — کرون هنوز فرصت نکرده */
    public function test_a_domain_queued_a_moment_ago_is_not_flagged(): void
    {
        $this->beat();
        $this->domain('pending');

        $this->assertSame('ok', $this->checks()['domains']['level']);
    }

    /**
     * 🔴 دامنهٔ **پرداخت‌نشده** نباید هشدار بسازد.
     *
     * ردیفِ سفارشِ باز `provision_status='none'` دارد و ماه‌ها می‌مانَد. اگر
     * این‌جا شمرده می‌شد، پایشگر همیشه قرمز بود و مدیر از هفتهٔ دوم نگاهش
     * نمی‌کرد — یعنی درست وقتی خرابیِ واقعی می‌آمد، کسی نمی‌دید.
     */
    public function test_an_unpaid_domain_row_is_not_a_stuck_queue(): void
    {
        $this->beat();
        $this->domain('none', 'pending', now()->subMonth());

        $this->assertSame('ok', $this->checks()['domains']['level']);
    }

    public function test_a_domain_in_the_manual_queue_is_a_warning(): void
    {
        $this->beat();
        $this->domain('manual');

        $c = $this->checks()['domains'];

        $this->assertSame('warn', $c['level']);
        $this->assertStringContainsString('دستی', $c['detail']);
    }

    // ═══════════════ فرمانِ نگهبان ═══════════════

    /** ⚠️ سکوتِ کرون در خروجی باید صریح باشد، نه فقط کدِ خروج */
    public function test_the_command_reports_the_dead_cron(): void
    {
        $this->artisan('system:health')
            ->expectsOutputToContain('زمان‌بند')
            ->assertSuccessful();
    }

    /**
     * 🔴 خروجِ موفق حتی وقتی همه‌چیز خراب است — عمدی.
     *
     * کدِ خروجِ غیرِ صفر را `schedule:run` شکستِ فرمان می‌شمارد و در لاگِ کرون
     * سروصدا می‌کند. نگهبان کارش را درست کرده؛ فقط خبرِ بد آورده.
     */
    public function test_the_command_still_exits_zero_when_things_are_broken(): void
    {
        $this->artisan('system:health')->assertExitCode(0);
    }

    /**
     * ضدِ اسپم: بارِ دوم با همان وضعیت، اعلانِ تازه نمی‌رود.
     *
     * ⚠️ هر ۱۵ دقیقه یعنی روزی ۹۶ پیام. مدیری که ۹۶ پیامِ تکراری بگیرد، از
     * روزِ دوم همه را نادیده می‌گیرد — و آن بدتر از نداشتنِ هشدار است، چون
     * توهمِ پایش می‌سازد.
     */
    public function test_the_watchdog_only_speaks_when_the_state_changes(): void
    {
        $sent = 0;

        $this->mock(\App\Services\Notify\AdminNotifier::class, function ($m) use (&$sent) {
            $m->shouldReceive('event')->andReturnUsing(function () use (&$sent) {
                $sent++;
            });
        });

        $this->artisan('system:health');
        $this->assertSame(1, $sent, 'بارِ اول باید خبر بدهد');

        $this->artisan('system:health');
        $this->assertSame(1, $sent, 'وضعیت عوض نشده — نباید دوباره خبر بدهد');
    }

    /**
     * 🔴 ولی تغییرِ **نوعِ** خرابی باید خبر بدهد، حتی اگر شدت یکی بماند.
     *
     * اگر امضا فقط شدت بود، «کرون درست شد ولی صفِ دامنه گیر کرد» هر دو `fail`
     * می‌شدند و هیچ خبری نمی‌رفت — یعنی دقیقاً همان کوریِ که این ابزار برای
     * رفعش ساخته شد.
     */
    public function test_a_different_failure_at_the_same_severity_still_alerts(): void
    {
        $sent = [];

        $this->mock(\App\Services\Notify\AdminNotifier::class, function ($m) use (&$sent) {
            $m->shouldReceive('event')->andReturnUsing(function ($title, $rows = []) use (&$sent) {
                $sent[] = array_keys($rows);
            });
        });

        $this->artisan('system:health');              // کرون مرده

        $this->beat();                                 // کرون درست شد…
        $this->domain('pending', 'pending', now()->subHours(3));   // …ولی صف گیر کرد

        $this->artisan('system:health');

        $this->assertCount(2, $sent, 'خرابیِ تازه با همان شدت هم باید خبر بدهد');
        $this->assertContains('صفِ دامنه', $sent[1]);
    }

    /** برگشت به حالتِ عادی هم خبر دارد — وگرنه مدیر نمی‌داند مشکل حل شده */
    public function test_recovery_is_announced(): void
    {
        $titles = [];

        $this->mock(\App\Services\Notify\AdminNotifier::class, function ($m) use (&$titles) {
            $m->shouldReceive('event')->andReturnUsing(function ($title) use (&$titles) {
                $titles[] = $title;
            });
        });

        $this->artisan('system:health');              // خراب
        $this->beat();
        $this->artisan('system:health');              // سالم

        $this->assertCount(2, $titles);
        $this->assertStringContainsString('عادی', $titles[1]);
    }

    // ═══════════════ صفحهٔ مدیریت ═══════════════

    public function test_the_error_page_shows_the_health_panel(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get('/admin/errors')->assertOk()->getContent();

        $this->assertStringContainsString('سلامتِ سامانه', $html);
        $this->assertStringContainsString('زمان‌بند', $html);
    }
}
