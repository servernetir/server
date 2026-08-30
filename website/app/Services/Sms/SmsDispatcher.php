<?php

namespace App\Services\Sms;

/**
 * تنها نقطه‌ای که بقیهٔ برنامه برای پیامک صدا می‌زند.
 *
 * چرا یک لایهٔ دیگر روی SmsSender: هر پیامکِ رویدادمحور (فاکتور، تحویل
 * سرویس، پاسخ تیکت، هشدار انقضا) باید **اول** مسیر الگو را امتحان کند، چون
 * فقط آن مسیر فوری تحویل می‌شود. ولی نباید هر جای برنامه این تصمیم را
 * تکرار کند. پس:
 *
 *     Sms::event($mobile, 'invoice', ['amount' => '۲٬۴۹۰٬۰۰۰'], 'فاکتور شما صادر شد …')
 *
 * اگر الگو تعریف شده باشد → مسیر خدماتی
 * اگر نه                  → همان متن به‌صورت پیام آزاد
 *
 * و هرگز هر دو، چون هر پیامک پول است.
 */
class SmsDispatcher
{
    public function __construct(private SmsSender $sender) {}

    /**
     * @param  array<string,string|int>  $values  متغیرهای الگو
     * @param  string  $fallback  متنی که اگر الگو نبود فرستاده می‌شود
     */
    public function event(string $mobile, string $event, array $values, string $fallback): bool
    {
        if ($this->sender instanceof SupportsPatterns) {
            $result = $this->sender->sendPattern($mobile, $event, $values);

            // null یعنی الگو نداشت؛ false یعنی داشت و نشد — در حالت دوم
            // دوباره نمی‌فرستیم تا یک پیام ناموفق دو بار هزینه نکند
            if ($result !== null) {
                return $result;
            }
        }

        if ($this->sender->send($mobile, $fallback)) {
            return true;
        }

        /*
        | 🔴 «الگو نداشت **و** متنِ آزاد هم نرفت» = پیامی که هیچ‌وقت نرسید.
        |
        | این تنها مسیری در کلِ لایهٔ اعلان بود که کاملاً ساکت می‌مانْد: نه
        | استثنایی، نه لاگی، نه ردیفی در ردیابِ خطا. `SignedRelaySender::send()`
        | عمداً `false` می‌دهد (متنِ آزاد به هیچ الگویی نمی‌خورد)، و چون هیچ‌کس
        | این `false` را ثبت نمی‌کرد، ۲۵ رویداد ماه‌ها می‌توانستند خاموش باشند و
        | تنها نشانه‌اش شکایتِ یک مشتری باشد.
        |
        | ⚠️ ثبت این‌جاست نه در درایور، چون درایور نمی‌داند کدام **رویداد** بود؛
        | و بدونِ نامِ رویداد، این پیام برای عیب‌یابی بی‌فایده است.
        */
        \App\Support\ErrorTracker::note('notify',
            'پیامکِ رویداد «'.$event.'» نرفت: الگویی برایش تعریف نشده و درایورِ '
            .$this->sender->name().' متنِ آزاد را نمی‌پذیرد', ['event' => $event]);

        \Illuminate\Support\Facades\Cache::put('sms:last_error', [
            'driver'   => $this->sender->name(),
            'template' => $event,
            'reason'   => 'الگو تعریف نشده و متنِ آزاد پشتیبانی نمی‌شود',
            'at'       => now()->toIso8601String(),
        ], now()->addDay());

        return false;
    }

    public function otp(string $mobile, string $code): bool
    {
        return $this->sender->sendOtp($mobile, $code);
    }

    public function raw(string $mobile, string $text): bool
    {
        return $this->sender->send($mobile, $text);
    }

    public function driver(): string
    {
        return $this->sender->name();
    }
}
