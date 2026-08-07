<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Services\Notify\CustomerNotifier;
use App\Services\Notify\NotifyEvent;
use App\Services\Sms\SignedRelaySender;
use App\Services\Sms\SmsDispatcher;
use App\Services\Sms\SmsSender;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * سکوت — بدترین حالتِ لایهٔ اعلان.
 *
 * ═══ خرابی‌ای که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * `CustomerNotifier::templated()` کلیدِ رویداد را دور می‌ریخت و `'__none__'`
 * می‌فرستاد، با این استدلال که «پیامکِ این رویدادها همیشه متنِ آزاد باشد».
 * آن استدلال وقتی درست بود که درایور `IppanelSender` بود؛ با رلهٔ n8n متنِ
 * آزاد **عمداً** فرستاده نمی‌شود. پس:
 *
 *     Notifier::fire()  →  templated()  →  '__none__'  →  الگو نیست
 *       →  send()  →  false  →  هیچ‌کس ثبتش نمی‌کرد  →  سکوتِ کامل
 *
 * نتیجه: هیچ‌کدام از ۲۵ رویدادِ کاتالوگ پیامک نمی‌فرستاد. نه استثنایی، نه
 * لاگی، نه ردیفی در ردیابِ خطا. کارفرما یک پرداختِ آزمایشی زد و هیچ پیامکی
 * نگرفت — و تا آن لحظه هیچ‌چیز در سامانه نگفته بود که اعلان‌ها خاموش‌اند.
 */
class NotificationSilenceTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 's'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** فرستنده‌ای که فقط الگوهای واقعی را می‌شناسد و متنِ آزاد را رد می‌کند */
    private function relayLike(): SmsSender
    {
        return new class implements SmsSender, \App\Services\Sms\SupportsPatterns
        {
            public array $patterns = [];

            public array $free = [];

            public function enabled(): bool { return true; }

            public function name(): string { return 'fake-relay'; }

            public function hasPattern(string $e): bool
            {
                return in_array($e, SignedRelaySender::TEMPLATES, true);
            }

            public function sendPattern(string $m, string $e, array $v): ?bool
            {
                if (! $this->hasPattern($e) || $v === []) {
                    return null;
                }

                $this->patterns[] = ['event' => $e, 'vars' => $v];

                return true;
            }

            public function sendOtp(string $m, string $c): bool { return true; }

            /** ⚠️ مثلِ رلهٔ واقعی: متنِ آزاد عمداً نمی‌رود */
            public function send(string $m, string $t): bool
            {
                $this->free[] = $t;

                return false;
            }
        };
    }

    // ═══════════════ 🔴 قلبِ فایل ═══════════════

    /**
     * `templated()` باید کلیدِ **واقعی** و متغیرها را به مسیرِ الگو برساند.
     *
     * این تنها راهِ رسیدنِ `Notifier::fire()` به مشتری است. اگر کلید را دور
     * بریزد، کلِ کاتالوگ خاموش می‌شود.
     */
    public function test_the_funnel_reaches_the_pattern_path_with_the_real_key(): void
    {
        $sms = $this->relayLike();
        $this->app->instance(SmsSender::class, $sms);
        $this->app->instance(SmsDispatcher::class, new SmsDispatcher($sms));

        app(CustomerNotifier::class)->templated(
            $this->customer(), 'renewed', ['service' => 'LX-2', 'until' => '۱۴۰۵/۰۹/۱۲'], 'متن'
        );

        $this->assertSame([['event' => 'renewed', 'vars' => ['service' => 'LX-2', 'until' => '۱۴۰۵/۰۹/۱۲']]],
            $sms->patterns,
            "\ncustomerNotifier::templated() کلیدِ رویداد را دور ریخت.\n"
            .'نتیجه‌اش این است که هیچ‌کدام از رویدادهای کاتالوگ پیامک نمی‌فرستند.');
    }

    /**
     * 🔴 و اگر واقعاً نرود، باید **دیده شود**.
     *
     * «الگو نداشت و متنِ آزاد هم نرفت» تنها مسیری بود که کاملاً ساکت می‌مانْد.
     */
    public function test_a_silent_drop_is_recorded_where_the_operator_can_see_it(): void
    {
        ErrorTracker::clear();
        Cache::forget('sms:last_error');

        $sms = $this->relayLike();

        /*
        | ⚠️ کلیدی که عمداً **هرگز** الگو نخواهد داشت.
        |
        | نسخهٔ اول این تست `suspended` را می‌گذاشت و وقتی برایش الگو ساخته شد
        | قرمز شد. تستِ رفتارِ دیسپچر نباید به محتوای فهرستِ الگو گره بخورد —
        | وگرنه هر بار که الگویی اضافه شود، باید تست را هم دست‌کاری کرد و آن
        | دست‌کاری‌ها همان جایی است که محافظ‌ها می‌میرند.
        */
        $this->assertFalse((new SmsDispatcher($sms))->event('09121234567', 'no_such_pattern', ['x' => '1'], 'متنِ پشتیبان'));

        $err = Cache::get('sms:last_error');

        $this->assertIsArray($err, 'شکستِ کامل در وضعیتِ عمومی دیده نمی‌شود');
        $this->assertSame('no_such_pattern', $err['template']);

        $noted = collect(ErrorTracker::recent(20))
            ->contains(fn ($r) => str_contains((string) ($r['message'] ?? ''), 'no_such_pattern'));

        $this->assertTrue($noted, 'ردیابِ خطا چیزی دربارهٔ پیامکِ نرفته نمی‌داند');
    }

    /** ⚠️ ولی موفقیت نباید خطای کاذب بسازد */
    public function test_a_successful_pattern_records_nothing(): void
    {
        ErrorTracker::clear();
        Cache::forget('sms:last_error');

        $sms = $this->relayLike();

        $this->assertTrue((new SmsDispatcher($sms))->event('09121234567', 'renewed', ['service' => 'X', 'until' => 'ی'], 'متن'));
        $this->assertNull(Cache::get('sms:last_error'));
    }

    // ═══════════════ متغیرِ شبح در الگو ═══════════════

    /**
     * 🔴 هر جای‌نگهدارِ متنِ الگو باید واقعاً فرستاده شود.
     *
     * بدنهٔ `invoice` متغیرِ `{due}` داشت و هیچ فراخوانی آن را نمی‌فرستاد. هر
     * دو خوانندهٔ الگو اگر بعد از جایگزینی هنوز `{چیزی}` ببینند عمداً الگو را
     * کنار می‌گذارند — پس **هیچ ایمیلِ فاکتوری فرستاده نمی‌شد**، ماه‌ها،
     * بی‌هیچ خطایی.
     *
     * ⚠️ و صفحهٔ ادمین دروغ می‌گفت: «ارسالِ آزمایشی» کار می‌کرد چون آن‌جا برای
     * هر متغیرِ اعلام‌شده مقدارِ نمونه ساخته می‌شود. یعنی مدیر تأیید می‌گرفت که
     * چیزی کار می‌کند که در عمل هرگز اجرا نمی‌شد.
     */
    public function test_no_template_uses_a_variable_the_event_never_sends(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $bad = [];

        foreach (NotificationTemplate::all() as $tpl) {
            $known = NotifyEvent::vars((string) $tpl->key);

            if ($known === [] && ! NotifyEvent::has((string) $tpl->key)) {
                continue;   // الگویی بیرونِ کاتالوگ — بُعدِ دیگری آن را می‌سنجد
            }

            foreach (['bale_body', 'email_body', 'email_subject'] as $col) {
                preg_match_all('~\{([a-z_]+)\}~i', (string) $tpl->{$col}, $m);

                foreach (array_unique($m[1]) as $var) {
                    if (! in_array($var, $known, true)) {
                        $bad[] = "{$tpl->key}.{$col}: «{{$var}}» را هیچ فراخوانی نمی‌فرستد";
                    }
                }
            }
        }

        $this->assertSame([], $bad,
            "\nمتغیرِ شبح در الگو ⇒ الگو برای همیشه کنار گذاشته می‌شود و مدیر خبردار نمی‌شود:\n"
            .implode("\n", array_unique($bad)));
    }

    /** و ترمیم واقعاً روی ردیفِ خرابِ موجود اجرا می‌شود، نه فقط ردیفِ تازه */
    public function test_the_seeder_repairs_an_already_broken_row(): void
    {
        NotificationTemplate::create([
            'key' => 'invoice', 'title' => 'صدور فاکتور', 'group' => 'billing',
            'sms_event' => 'invoice',
            'email_subject' => 'فاکتور تازه — سرورنت',
            'email_body' => '<p>فاکتور شماره <b>{number}</b> به مبلغ <b>{amount}</b> تومان صادر شد.</p><p>سررسید: {due}</p>',
            'bale_body' => 'فاکتور {number} به مبلغ {amount} تومان صادر شد. سررسید: {due}',
            'variables' => [['name' => 'due', 'desc' => 'تاریخ سررسید']],
        ]);

        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $fixed = NotificationTemplate::where('key', 'invoice')->first();

        $this->assertStringNotContainsString('{due}', (string) $fixed->bale_body,
            'ردیفِ خرابِ موجود ترمیم نشد — firstOrCreate هرگز ردیفِ موجود را دست نمی‌زند');
        $this->assertStringNotContainsString('{due}', (string) $fixed->email_body);
    }

    /**
     * ⚠️ ولی متنِ **ویرایش‌شدهٔ مدیر** نباید بازنویسی شود.
     *
     * ترمیمِ خودکارِ متنِ دست‌نویس از خودِ باگ بدتر است: مدیر ساعت‌ها روی متن
     * کار می‌کند و یک دیپلوی آن را برمی‌گرداند.
     */
    public function test_the_repair_never_overwrites_an_admin_edit(): void
    {
        NotificationTemplate::create([
            'key' => 'invoice', 'title' => 'صدور فاکتور', 'group' => 'billing',
            'sms_event' => 'invoice',
            'email_subject' => 'فاکتور شما',
            'email_body' => '<p>متنِ دست‌نویسِ مدیر — {number}</p>',
            'bale_body' => 'متنِ دست‌نویسِ مدیر — {number}',
            'variables' => [['name' => 'number', 'desc' => 'شماره']],
        ]);

        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $this->assertSame('متنِ دست‌نویسِ مدیر — {number}',
            (string) NotificationTemplate::where('key', 'invoice')->first()->bale_body);
    }
}
