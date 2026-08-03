<?php

namespace App\Services\Notify;

use App\Models\Customer;
use App\Services\Bale\BaleNotifier;
use App\Services\Sms\SmsDispatcher;

/**
 * تنها نقطه‌ای که برای «اعلان به مشتری» صدا زده می‌شود.
 *
 * قاعدهٔ کارفرما: هر چیزی که پیامک می‌شود، بله هم می‌شود. با یک هاب مرکزی
 * این قاعده در یک جا تضمین می‌شود، نه اینکه هر رویداد یادش باشد هر دو کانال
 * را بزند و یکی جا بیفتد.
 *
 *   پیامک → از طریق SmsDispatcher (صف، مسیر الگو، تحویل از ایران)
 *   بله   → از طریق BaleNotifier (آلمان اول، اگر نشد ایران)
 *
 * هر دو best-effort و مستقل‌اند: اگر یکی نشد، دیگری می‌رود. هیچ‌کدام جریان
 * اصلی را نمی‌شکند.
 */
class CustomerNotifier
{
    public function __construct(
        private SmsDispatcher $sms,
        private BaleNotifier $bale,
    ) {}

    /**
     * اعلان رویدادی به مشتری، هر دو کانال.
     *
     * @param  string  $event      نام رویداد برای الگوی پیامک (invoice، paid، ticket_reply…)
     * @param  array   $smsParams  متغیرهای الگوی پیامک
     * @param  string  $text       متن کامل — پشتیبان پیامک و متن بله
     */
    public function event(Customer $customer, string $event, array $smsParams, string $text): void
    {
        $mobile = (string) $customer->phone;

        if ($mobile === '') {
            return;
        }

        // متنِ الگو (اگر مدیر در /admin/templates تعریفش کرده) جای متنِ کد
        // می‌نشیند. اگر الگو نبود، خاموش بود، یا متغیری از قلم افتاده بود،
        // همین `$text` می‌رود — پس این لایه هیچ اعلانی را نمی‌شکند.
        $text = \App\Models\NotificationTemplate::body($event, $smsParams, $text);

        // پیامک — الگو اگر باشد، وگرنه متن آزاد
        try {
            $this->sms->event($mobile, $event, $smsParams, $text);
        } catch (\Throwable) {
            // اعلان هرگز نباید جریان اصلی را بشکند
        }

        // بله — همان متن، اگر کاربر وصل کرده باشد
        $this->bale->notify($mobile, $text);
    }

    /**
     * رویدادی که متنش از `/admin/templates` می‌آید ولی **الگوی پیامک ندارد**.
     *
     * چرا از `event()` جدا: آن‌جا `$event` هم‌زمان دو کار می‌کند — کلیدِ الگوی ما
     * و کدِ الگوی اپراتورِ پیامک. برای رویدادهای چرخهٔ عمر (تعلیق، رفعِ تعلیق،
     * یادآوری) خطرناک است که هر دو یکی باشند: `SmsDispatcher::event()` عمداً
     * وقتی الگویی پیدا شود و ارسالش شکست بخورد، **متنِ آزاد را جایگزین
     * نمی‌فرستد** (تا یک پیامِ ناموفق دو بار هزینه نکند). پس اگر این‌ها را به
     * `event()` بدهیم و کدِ الگو در تنظیمات باشد ولی متغیرهایش با اپراتور نخوانَد،
     * یادآوریِ تمدید بی‌صدا گم می‌شود — یعنی مشتری بی‌خبر می‌ماند و سرویسش قطع
     * می‌شود. این‌جا پیامک همیشه متنِ آزاد است، و الگو فقط بله/ایمیل را می‌سازد.
     *
     * @param  array<string,string|int>  $vars
     * @param  string  $text  متنِ کد — اگر الگو نبود یا ناقص بود، همین می‌رود
     * @return bool آیا ایمیلِ الگو واقعاً فرستاده شد؟ فراخوان از روی همین
     *              تصمیم می‌گیرد که ایمیلِ سادهٔ پشتیبانِ خودش را بفرستد یا نه —
     *              وگرنه مشتری دو ایمیل می‌گرفت.
     */
    public function templated(Customer $customer, string $key, array $vars, string $text): bool
    {
        $text = \App\Models\NotificationTemplate::body($key, $vars, $text);

        $this->event($customer, '__none__', [], $text);

        return $this->emailFromTemplate($customer, $key, $vars);
    }

    /**
     * ایمیلِ الگو — فقط اگر الگو متنِ ایمیلِ **کاملی** داشته باشد.
     *
     * نبودِ الگو یعنی «ایمیل نفرست»، نه خطا: پیش از این هم این رویدادها یا اصلاً
     * ایمیل نداشتند یا `Mail::raw`ِ بی‌قالب می‌فرستادند.
     */
    private function emailFromTemplate(Customer $customer, string $key, array $vars): bool
    {
        $email = (string) $customer->email;

        if ($email === '') {
            return false;
        }

        try {
            $mail = \App\Models\NotificationTemplate::email($key, $vars);

            if ($mail === null) {
                return false;
            }

            \Illuminate\Support\Facades\Mail::mailer('smtp')->to($email)
                ->send(new \App\Mail\TemplateMail($mail['subject'], $mail['html']));

            return true;
        } catch (\Throwable) {
            // اعلان هرگز نباید جریان اصلی را بشکند. false یعنی «نرفت»، پس
            // فراخوان ایمیلِ سادهٔ خودش را می‌فرستد و پیام کلاً گم نمی‌شود.
            return false;
        }
    }

    /** پیام آزاد بدون الگو، هر دو کانال */
    public function message(Customer $customer, string $text): void
    {
        $this->event($customer, '__none__', [], $text);
    }
}
