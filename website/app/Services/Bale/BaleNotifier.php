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
    public function __construct(private BaleSender $sender) {}

    /**
     * تلاش برای رساندن یک متن به بلهٔ یک شماره.
     * best-effort: هیچ‌وقت استثنا نمی‌اندازد و جریان اصلی را نگه نمی‌دارد.
     */
    public function notify(string $mobile, string $text): void
    {
        try {
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
