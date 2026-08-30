<?php

namespace App\Services\Notify;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * اعلان به **مدیر** (نه مشتری) — بله + ایمیل.
 *
 * یک نقطهٔ واحد برای همهٔ رویدادهایی که مدیر باید بداند: تیکت تازه، مشتری تازه،
 * پرداخت، خریدِ سرویس، تعلیقِ خودکار به‌خاطرِ عدمِ تمدید و…
 *
 * ═══ قواعد ═══
 * • هرگز جریانِ اصلی را نمی‌شکند: هر کانال در try/catch جداست. اگر بله یا SMTP
 *   قطع باشد، خریدِ مشتری یا کرونِ تمدید نباید خطا بدهد.
 * • شمارهٔ بلهٔ مدیر از config('servernet.contact.notify_phone') و ایمیل از
 *   config('servernet.contact.email') می‌آید.
 * • متن‌ها کوتاه و تلگرافی‌اند؛ مدیر روی موبایل می‌خواندشان.
 */
class AdminNotifier
{
    public function __construct(private \App\Services\Bale\BaleNotifier $bale) {}

    /**
     * یک رویداد را به مدیر اطلاع بده.
     *
     * @param  string  $title   تیترِ کوتاه (در ایمیل موضوع می‌شود)
     * @param  array<string,string|int|null>  $rows  جفت‌های «برچسب => مقدار» برای بدنه
     * @param  string|null  $url  لینکِ مربوط در پنل مدیریت (اختیاری)
     * @param  array<int,array<int,array{text:string,data:string}>>  $buttons
     *         دکمه‌های شیشه‌ای — **فقط در بله**. ایمیل متن می‌مانَد.
     *
     * ⚠️ دکمه اختیاری است و نبودش رفتارِ قبلی را عوض نمی‌کند: ده‌ها فراخوانِ
     * موجود بی‌تغییر کار می‌کنند.
     */
    public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔', array $buttons = [], ?string $key = null): void
    {
        /*
        | 🔴 کلیدِ اختیاری = «این رویداد در تنظیمات قابلِ مدیریت است».
        |
        | بی‌کلید، رفتار دقیقاً مثلِ قبل است — و این عمدی است: ۲۴ فراخوانِ موجود
        | نباید یک‌جا بازنویسی شوند. هر کدام که کلید بگیرد، مدیر می‌تواند
        | خاموشش کند یا متنش را خودش بنویسد.
        |
        | ⚠️ و **خاموش یعنی خاموش**: نه بله، نه ایمیل. اعلانی که مدیر صریح
        | نخواسته، از درِ دیگر هم نباید بیاید.
        */
        /*
        | کلید از **عنوان** پیدا می‌شود، نه از آرگومان.
        |
        | ۲۵ فراخوان شکل‌های متفاوتی دارند و افزودنِ آرگومانِ ششم به هرکدام
        | یعنی ۲۵ ویرایشِ دستی روی کدی که تست ندارد. عنوان‌ها یکتا و ثابت‌اند،
        | پس نقشهٔ `AdminAlerts` همان کار را با صفر تغییر می‌کند.
        |
        | ⚠️ عنوانِ ناشناخته ⇒ `null` ⇒ رفتارِ امروز. جهتِ خرابی امن است:
        | اعلان می‌رود، فقط مدیریت‌پذیر نیست.
        */
        $key ??= \App\Support\AdminAlerts::keyFor($title);

        if ($key !== null && ! $this->wanted($key)) {
            return;
        }

        $lines = [$emoji.' '.$title];

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = is_int($label) ? (string) $value : $label.': '.$value;
        }

        if ($url !== null && $url !== '') {
            $lines[] = $url;
        }

        $text = implode("\n", $lines);

        /*
        | متنِ دلخواهِ مدیر، با تگ‌هایی که از همان `$rows` می‌آیند.
        |
        | ⚠️ برچسبِ هر ردیف **خودش** تگ است: `{مشتری}`، `{مبلغ}`، `{IP}`. یعنی
        | هیچ فراخوانی لازم نیست فهرستِ متغیر پاس بدهد — داده از قبل آن‌جاست.
        |
        | 🔴 طرحِ اولم یک آرایهٔ `vars`ِ جدا داشت و هر ۲۴ فراخوان باید عوض
        | می‌شد. طبقِ قاعدهٔ ثبت‌شدهٔ این پروژه، فراخوانی که متغیرش را پاس ندهد
        | الگویش را **هرگز** فعال نمی‌کند و مدیر متن را ویرایش می‌کند و هیچ
        | اتفاقی نمی‌افتد — یعنی ۲۴ فرصتِ خرابیِ خاموش. این‌طور، صفر.
        |
        | 🔴 و اگر متنِ مدیر تگی داشته باشد که این رویداد نمی‌دهد،
        | `NotificationTemplate::body()` خودش به متنِ پیش‌فرض برمی‌گردد. پیامی
        | که در آن «{مبلغ}» چاپ شده، از پیامِ پیش‌فرض بدتر است.
        */
        if ($key !== null) {
            $text = \App\Models\NotificationTemplate::body(
                $key,
                ['عنوان' => $title] + $this->tagsFrom($rows),
                $text,
            );
        }

        $this->sendBale($text, $buttons);
        $this->sendMail($title, $text);
    }

    /**
     * آیا مدیر این رویداد را می‌خواهد؟
     *
     * ⚠️ نبودِ ردیف یعنی **بله**، نه خیر. رویدادی که هنوز seed نشده باید مثلِ
     * امروز بیاید؛ وگرنه یک مهاجرتِ نزده روی سرور همهٔ اعلان‌ها را بی‌صدا
     * خاموش می‌کرد. سکوت باید تصمیمِ صریحِ مدیر باشد، نه پیش‌فرضِ یک نصبِ ناقص.
     */
    private function wanted(string $key): bool
    {
        /*
        | 🔴 عمداً `forKey()` نیست.
        |
        | `forKey()` برای ردیفِ **خاموش** هم `null` می‌دهد — یعنی «نبود» و
        | «خاموش» را یکی می‌کند. و چون نبود باید «بفرست» باشد، سوییچِ خاموش
        | هیچ اثری نداشت: اعلان با وجودِ خاموشی می‌رفت. تستِ خاموشی همین را گرفت.
        */
        try {
            $row = \App\Models\NotificationTemplate::query()
                ->where('key', $key)->first(['is_active']);
        } catch (\Throwable) {
            return true;
        }

        return $row === null || (bool) $row->is_active;
    }

    /**
     * ردیف‌ها → تگ‌ها. برچسبِ عددی (خطِ آزاد) تگ نمی‌شود.
     *
     * @param  array<string|int,string|int|null>  $rows
     * @return array<string,string>
     */
    private function tagsFrom(array $rows): array
    {
        $out = [];

        foreach ($rows as $label => $value) {
            if (! is_int($label)) {
                $out[(string) $label] = (string) ($value ?? '');
            }
        }

        return $out;
    }

    private function sendBale(string $text, array $buttons = []): void
    {
        try {
            $phone = trim((string) config('servernet.contact.notify_phone', ''));

            /*
            | ⚠️ اگر ارسالِ دکمه‌دار نشد، به متنِ ساده برمی‌گردیم.
            |
            | 🔴 و نه `return`: پیامی که دکمه‌اش نرسیده باز هم باید برسد. نسخهٔ
            | بی‌این شاخه یعنی یک شکستِ کوچکِ کیبورد، کلِ اعلانِ «مشتری جواب
            | داد» را می‌بلعید — همان سکوتی که این پروژه بارها خورده.
            */
            if ($buttons !== [] && $this->bale->toAdminButtons($phone, $text, $buttons)) {
                return;
            }

            /*
            | 🔴 `toAdmin()` نه `notify()` — سفیر فقط برای مشتریان.
            |
            | مدیر مشتری نیست و هر پیامِ سفیر هزینهٔ جداگانه دارد. این متد از
            | APIِ رباتِ خودمان می‌رود (همان مسیرِ رایگانِ پیش از سفیر). عوض‌کردنش
            | به `notify()` یعنی برگرداندنِ همان هزینهٔ الکی.
            */
            $this->bale->toAdmin($phone, $text);
        } catch (\Throwable $e) {
            /*
            | 🔴 در ردیابِ خطا هم ثبت می‌شود، نه فقط `laravel.log`.
            |
            | اگر SMTP بخوابد و توکنِ بله منقضی شود، از آن لحظه **هیچ‌کدام** از
            | اعلان‌های مدیر نمی‌رسد: پرداختِ موفق، سفارشِ تازه، شکستِ تحویل،
            | دامنهٔ منقضی. مدیر فقط «سکوت» می‌بیند، که از «همه‌چیز آرام است»
            | قابلِ تشخیص نیست — و `/admin/errors` و `system:health` هر دو سبزند
            | چون خطا در فایلی است که کسی بازش نمی‌کند.
            |
            | ⚠️ همان قاعدهٔ ثبت‌شدهٔ پروژه: چیزی که قرار است از مرگِ یک وابستگی
            | خبر دهد، نباید در جایی بنشیند که فقط موقعِ عیب‌یابی دیده می‌شود.
            */
            Log::warning('اعلانِ بلهٔ مدیر نرفت', ['error' => mb_substr($e->getMessage(), 0, 160)]);
            \App\Support\ErrorTracker::note('notify', $e, ['to' => 'admin', 'channel' => 'bale']);
        }
    }

    private function sendMail(string $subject, string $text): void
    {
        try {
            $to = trim((string) config('servernet.contact.email', ''));

            if ($to === '') {
                return;
            }

            Mail::mailer('smtp')->raw($text, fn ($m) => $m->to($to)->subject('[سرورنت] '.$subject));
        } catch (\Throwable $e) {
            Log::warning('ایمیلِ اعلانِ مدیر نرفت', ['error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }
}
