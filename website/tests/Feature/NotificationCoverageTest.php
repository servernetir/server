<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\CustomerNotifier;
use App\Services\Notify\Notifier;
use App\Services\Notify\NotifyEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پوششِ اطلاع‌رسانی باید **اثبات‌پذیر** باشد، نه به‌خاطرسپردنی.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * الگوهای `welcome` و `invoice` سال‌ها در پنل بودند، مدیر می‌توانست متنشان را
 * ویرایش کند و «ذخیره شد» بگیرد — و **هیچ کدی هرگز صدایشان نمی‌زد**. هیچ خطایی
 * هم تولید نمی‌شد. این تست همان کلاس از خرابی را می‌گیرد.
 */
class NotificationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'n'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    // ═══════════════ یکپارچگیِ کاتالوگ ═══════════════

    public function test_every_event_declares_a_complete_row(): void
    {
        $bad = [];

        foreach (NotifyEvent::ALL as $key => $e) {
            if (! preg_match('~^[a-z][a-z0-9_]*$~', $key)) {
                $bad[] = "$key: کلید باید snake_case باشد";
            }

            foreach (['title', 'group', 'audience', 'vars', 'wired'] as $col) {
                if (! array_key_exists($col, $e)) {
                    $bad[] = "$key: ستونِ {$col} ندارد";
                }
            }

            if (! in_array($e['audience'] ?? '', [NotifyEvent::CUSTOMER, NotifyEvent::ADMIN, NotifyEvent::BOTH], true)) {
                $bad[] = "$key: مخاطبِ نامعتبر";
            }
        }

        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    /**
     * 🔴 قلبِ این فایل: هر رویدادی که ادعا می‌کند وصل است، باید فراخوان داشته باشد.
     *
     * جستجو در کلِ `app/` دنبالِ نامِ کلید. اگر جایی پیدا نشد، آن رویداد یک
     * الگوی مرده است — مدیر متنش را ویرایش می‌کند و هیچ اتفاقی نمی‌افتد.
     */
    public function test_every_wired_event_really_has_a_caller(): void
    {
        $code = $this->appSource();
        $dead = [];

        foreach (NotifyEvent::ALL as $key => $e) {
            if (! $e['wired']) {
                continue;
            }

            /*
            | ⚠️ فقط در **بافتِ فراخوانِ اعلان**، نه هر جای کد.
            |
            | جستجوی سادهٔ `'terminated'` یا `'invoice'` همیشه چیزی پیدا می‌کند،
            | چون این‌ها به‌عنوان **وضعیتِ سرویس** و **نوعِ فاکتور** هم در کد
            | هستند. آن نسخه سبز می‌شد بی‌آنکه چیزی بسنجد.
            */
            $q = preg_quote($key, '~');

            $found = preg_match('~->fire\(\s*[\'"]'.$q.'[\'"]~', $code)
                || preg_match('~->templated\([^,()]+,\s*[\'"]'.$q.'[\'"]~', $code)
                || preg_match('~->event\([^,()]+,\s*[\'"]'.$q.'[\'"]~', $code)
                // ⚠️ `otp` مسیرِ خودش را دارد: `SmsDispatcher::otp()` مستقیم
                //    صدا زده می‌شود، نه از راهِ الگو — چون کدِ ورود نباید به
                //    متنِ آزاد برگردد و نباید در بله تکرار شود.
                // بسته‌بندی‌کنندهٔ محلی هم قبول است (مثلِ
                // `DomainRegistrar::announce()`) — مهم این است که کلیدِ ادبی
                // در یک فراخوانِ **اعلان** ظاهر شود، نه اینکه حتماً `fire` باشد.
                || preg_match('~->announce\(\s*[\'"]'.$q.'[\'"]~', $code)
                || ($key === 'otp' && preg_match('~->sendOtp\(~', $code));

            if (! $found) {
                $dead[] = $key.' — «'.$e['title'].'»';
            }
        }

        $this->assertSame([], $dead,
            "\nاین رویدادها ادعا می‌کنند وصل‌اند ولی هیچ فراخوانی ندارند:\n".implode("\n", $dead));
    }

    /** و برعکس: رویدادِ `wired: false` نباید بی‌سروصدا وصل شده باشد */
    public function test_unwired_events_are_honestly_marked(): void
    {
        $this->assertSame(['domain_transfer'], NotifyEvent::unwired(),
            'فهرستِ رویدادهای وصل‌نشده عوض شده — کاتالوگ را به‌روز کن');
    }

    // ═══════════════ رفتارِ قیف ═══════════════

    /** @return array{0:array<int,string>,1:array<int,string>} */
    private function capture(callable $run): array
    {
        $toCustomer = [];
        $toAdmin = [];

        $this->mock(CustomerNotifier::class, function ($m) use (&$toCustomer) {
            $m->shouldReceive('templated')->andReturnUsing(function ($c, $key) use (&$toCustomer) {
                $toCustomer[] = $key;

                return true;
            });
        });

        $this->mock(AdminNotifier::class, function ($m) use (&$toAdmin) {
            $m->shouldReceive('event')->andReturnUsing(function ($title) use (&$toAdmin) {
                $toAdmin[] = $title;
            });
        });

        $run(app(Notifier::class));

        return [$toCustomer, $toAdmin];
    }

    /** رویدادِ `both` باید واقعاً به هر دو برسد */
    public function test_a_both_event_reaches_customer_and_admin(): void
    {
        $c = $this->customer();

        [$cust, $adm] = $this->capture(fn (Notifier $n) => $n->fire(
            'service_ready', $c, ['service' => 'LX-2', 'ip' => '1.2.3.4'], 'آماده شد'
        ));

        $this->assertSame(['service_ready'], $cust);
        $this->assertCount(1, $adm);
    }

    /** رویدادِ فقط-مشتری نباید مدیر را بیدار کند */
    public function test_a_customer_only_event_does_not_reach_the_admin(): void
    {
        $c = $this->customer();

        [$cust, $adm] = $this->capture(fn (Notifier $n) => $n->fire(
            'otp', $c, ['code' => '123456'], 'کد'
        ));

        $this->assertSame(['otp'], $cust);
        $this->assertSame([], $adm, 'کدِ ورود نباید به مدیر برود');
    }

    /**
     * 🔴 کلیدِ ناشناخته بی‌صدا رد نمی‌شود.
     *
     * بی‌این، یک غلطِ املایی در نامِ رویداد یعنی پیامی که به هیچ‌جا نمی‌رسد و
     * تنها نشانه‌اش شکایتِ ماه‌ها بعدِ یک مشتری است.
     */
    public function test_an_unknown_event_is_recorded_not_swallowed(): void
    {
        \App\Support\ErrorTracker::clear();
        $c = $this->customer();

        [$cust, $adm] = $this->capture(fn (Notifier $n) => $n->fire(
            'not_a_real_event', $c, [], 'x'
        ));

        $this->assertSame([], $cust);
        $this->assertSame([], $adm);

        $noted = collect(\App\Support\ErrorTracker::recent(20))
            ->contains(fn ($r) => str_contains((string) ($r['message'] ?? ''), 'not_a_real_event'));

        $this->assertTrue($noted, 'رویدادِ ناشناخته در ردیاب ثبت نشد');
    }

    /** ⚠️ خطای یک کانال نباید کانالِ دیگر یا جریانِ اصلی را بشکند */
    public function test_a_broken_channel_never_breaks_the_caller(): void
    {
        $c = $this->customer();
        $adminSeen = 0;

        $this->mock(CustomerNotifier::class, function ($m) {
            $m->shouldReceive('templated')->andThrow(new \RuntimeException('SMTP down'));
        });

        $this->mock(AdminNotifier::class, function ($m) use (&$adminSeen) {
            $m->shouldReceive('event')->andReturnUsing(function () use (&$adminSeen) {
                $adminSeen++;
            });
        });

        app(Notifier::class)->fire('service_ready', $c, ['service' => 'X', 'ip' => '-'], 'متن');

        $this->assertSame(1, $adminSeen, 'شکستِ کانالِ مشتری، اعلانِ مدیر را هم خواباند');
    }

    /** اعلانِ مدیر باید بگوید مالِ چه کسی است */
    public function test_the_admin_notice_always_names_the_customer(): void
    {
        $c = $this->customer();
        $rows = [];

        $this->mock(CustomerNotifier::class, fn ($m) => $m->shouldReceive('templated')->andReturn(true));
        $this->mock(AdminNotifier::class, function ($m) use (&$rows) {
            $m->shouldReceive('event')->andReturnUsing(function ($t, $r = []) use (&$rows) {
                $rows = $r;
            });
        });

        app(Notifier::class)->fire('paid', $c, ['amount' => '۲٬۵۰۰٬۰۰۰'], 'پرداخت شد');

        $this->assertArrayHasKey('مشتری', $rows);
        $this->assertArrayHasKey('مبلغ', $rows);
    }

    // ═══════════════ کمکی ═══════════════

    /**
     * 🔴 خودِ کاتالوگ و قیف از جستجو **کنار گذاشته می‌شوند**.
     *
     * نسخهٔ اول این تست کلِ `app/` را می‌گشت — و چون `NotifyEvent.php` خودش
     * آن‌جاست، هر کلید حتماً پیدا می‌شد و تست **همیشه سبز** بود بی‌آنکه چیزی
     * بسنجد. یعنی دقیقاً همان «ادعای توخالی» که این فایل برای گرفتنش نوشته شد،
     * در خودش بود.
     *
     * حالا فقط کدِ **مصرف‌کننده** جستجو می‌شود: اگر کلیدی جز در تعریفش جای
     * دیگری نباشد، آن رویداد واقعاً مرده است.
     */
    private function appSource(): string
    {
        $skip = [
            app_path('Services/Notify/NotifyEvent.php'),
            app_path('Services/Notify/Notifier.php'),
        ];

        $out = '';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (in_array($file->getPathname(), $skip, true)) {
                continue;
            }

            $out .= file_get_contents($file->getPathname());
        }

        return $out;
    }
}
