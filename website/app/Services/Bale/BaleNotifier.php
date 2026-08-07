<?php

namespace App\Services\Bale;

use App\Models\BaleContact;
use App\Models\SmsOutbox;
use Illuminate\Support\Facades\Schema;

/**
 * ارسال بله موازی پیامک — «اول آلمان، اگر نشد ایران».
 *
 * این کلاس تصمیم مسیر را می‌گیرد تا هیچ‌جای دیگر برنامه لازم نباشد بداند بله
 * از کجا می‌رود:
 *
 *   ۱) اگر chat_id این شماره را نداریم → کاری نمی‌شود کرد (کاربر بله را
 *      وصل نکرده). بی‌صدا رد می‌شویم؛ پیامک که رفته.
 *   ۲) تلاش مستقیم از آلمان (BaleSender). موفق شد → تمام.
 *   ۳) نشد → یک ردیف «فقط‌بله» در صف می‌گذاریم تا فرستندهٔ ایران بفرستد.
 *
 * همیشه بی‌خطر است: نبودِ جدول یا توکن، هیچ‌چیز را نمی‌شکند.
 */
class BaleNotifier
{
    public function __construct(
        private BaleSender $sender,
        private BaleSafirSender $safir,
    ) {}

    /**
     * کدِ یک‌بارمصرف به بلهٔ کاربر.
     *
     * ⚠️ جدا از `notify()` چون سفیر برای کد مسیرِ اختصاصی دارد و کلاینتِ بله
     * آن را قابلِ‌کپی و بی‌پیش‌نمایش نشان می‌دهد. اگر سفیر در دسترس نبود، به
     * همان متنِ معمولی برمی‌گردیم — کد نباید به‌خاطرِ زیبایی گم شود.
     */
    public function otp(string $mobile, string $code): void
    {
        try {
            if ($this->safir->enabled() && $this->safir->otp($mobile, $code)) {
                return;
            }

            $this->notify($mobile, "کد ورود سرورنت: {$code}");
        } catch (\Throwable) {
            // بله هرگز نباید منبع خطای جریان اصلی باشد
        }
    }

    /**
     * تلاش برای رساندن یک متن به بلهٔ یک شماره.
     * best-effort: هیچ‌وقت استثنا نمی‌اندازد و جریان اصلی را نگه نمی‌دارد.
     */
    public function notify(string $mobile, string $text): void
    {
        try {
            /*
            | 🔴 اول سفیر — چون با **شماره** کار می‌کند.
            |
            | مسیرِ قدیمی `chat_id` می‌خواست، و `chat_id` فقط وقتی وجود داشت که
            | کاربر خودش وارد ربات شده و شماره‌اش را به اشتراک گذاشته باشد.
            | یعنی برای اکثریتِ مشتری‌ها این تابع **بی‌صدا از خطِ زیر رد
            | می‌شد** و هیچ پیامی نمی‌رفت. با سفیر، بله از یک قابلیتِ اختیاری
            | به مسیرِ دومِ واقعی تبدیل می‌شود.
            |
            | ⚠️ مسیرِ قدیمی حذف نشد: اگر سفیر اعتبار نداشته باشد یا کاربر
            | حسابِ بله نداشته باشد، هنوز ممکن است `chat_id` داشته باشیم.
            */
            if ($this->safir->enabled() && $this->safir->text($mobile, $text)) {
                return;
            }

            if (! Schema::hasTable('bale_contacts')) {
                return;
            }

            $chatId = BaleContact::chatIdFor($mobile);

            if ($chatId === null) {
                return;   // کاربر بله را وصل نکرده
            }

            // مسیر اصلی: آلمان مستقیم
            if ($this->sender->send($chatId, $text)) {
                return;
            }

            // fallback: صف برای ایران
            $this->queueForIran($mobile, $chatId, $text);
        } catch (\Throwable) {
            // بله هرگز نباید منبع خطای جریان اصلی باشد
        }
    }

    private function queueForIran(string $mobile, string $chatId, string $text): void
    {
        if (! Schema::hasTable('sms_outbox')) {
            return;
        }

        SmsOutbox::create([
            'destination'  => $mobile,
            'event'        => 'bale_only',   // به فرستندهٔ ایران می‌گوید فقط بله
            'body'         => $text,
            'bale_chat_id' => $chatId,
            'status'       => 'queued',
            // بله برای اعلان است نه فقط کد، پس عمر کمی بلندتر — ولی نه زیاد
            'expires_at'   => now()->addMinutes(10),
        ]);
    }
}
