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
     * اعلانِ **داخلی** به مدیر — از APIِ ربات، **هرگز از سفیر**.
     *
     * ═══ چرا یک متدِ جداست و نه یک پرچمِ ورودی ═══
     *
     * قاعدهٔ کارفرما: «سفیر فقط برای مشتریان». هر پیامِ سفیر هزینهٔ جداگانه
     * دارد و مدیر مشتری نیست — اعلانِ داخلیِ او باید از همان مسیرِ رایگانِ
     * قبل از سفیر برود.
     *
     * 🔴 این قاعده در سه جای پروژه **نوشته** شده بود (docblockِ
     * `BaleSafirSender`، `config/services.bale_safir`، و
     * `AppServiceProvider`) و در هیچ‌جا **اجرا** نمی‌شد: `AdminNotifier` همین
     * `notify()` را صدا می‌زد و خطِ اولش سفیر است. یعنی هر رویدادِ داخلی —
     * تیکت، پرداخت، خطای تحویل — بی‌سروصدا از کانالِ پولی می‌رفت. پرچمِ
     * `bool $viaSafir` این را درست نمی‌کرد، چون فراخوانِ تازه‌ای که یادش برود
     * دوباره به پیش‌فرضِ پولی می‌افتد. متدِ جدا یعنی انتخاب **صریح** است.
     *
     * ⚠️ APIِ ربات `chat_id` می‌خواهد نه شماره. اگر هیچ‌کدام در دست نباشد،
     * **ساکت نمی‌مانیم**: در ردیابِ خطا ثبت می‌شود، چون کانالی که قرار است از
     * خرابی خبر دهد نباید خودش بی‌صدا بمیرد (§۳ در CLAUDE.md). ایمیلِ مدیر
     * مستقل از این مسیر می‌رود، پس مدیر در بدترین حالت هم کور نمی‌شود.
     */
    /**
     * همان `toAdmin()`، ولی با دکمه‌های شیشه‌ای.
     *
     * 🔴 جدا از `toAdmin()` و نه یک پارامترِ اختیاری روی آن: مسیرِ ایران
     * (`fallback`) دکمه ندارد و اگر پارامتر می‌گذاشتم، آن مسیر بی‌صدا
     * دکمه‌ها را می‌انداخت و مدیر پیامی می‌گرفت که نصفش کار نمی‌کند.
     *
     * ⚠️ نبودِ مقصد یا شکستِ ارسال ⇒ `false`. فراخوان می‌تواند به متنِ ساده
     * برگردد؛ پیامِ بی‌دکمه از هیچ پیامی بهتر است.
     *
     * @param  array<int,array<int,array{text:string,data:string}>>  $rows
     */
    public function toAdminButtons(string $mobile, string $text, array $rows): bool
    {
        try {
            $chatId = trim((string) config('servernet.contact.notify_chat_id', ''));

            if ($chatId === '' && $mobile !== '' && Schema::hasTable('bale_contacts')) {
                $chatId = (string) (BaleContact::chatIdFor($mobile) ?? '');
            }

            if ($chatId === '') {
                return false;
            }

            return $this->sender->sendButtons($chatId, $text, $rows) !== null;
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['area' => 'bale-admin-buttons']);

            return false;
        }
    }

    public function toAdmin(string $mobile, string $text): void
    {
        try {
            $chatId = trim((string) config('servernet.contact.notify_chat_id', ''));

            if ($chatId === '' && $mobile !== '' && Schema::hasTable('bale_contacts')) {
                $chatId = (string) (BaleContact::chatIdFor($mobile) ?? '');
            }

            if ($chatId === '') {
                \App\Support\ErrorTracker::noteOnce(
                    'notify',
                    'بلهٔ مدیر مقصد ندارد: نه SUPPORT_NOTIFY_CHAT_ID ست شده و نه این شماره ربات را استارت کرده. اعلانِ داخلی فقط ایمیل می‌رود.',
                    3600,
                    ['area' => 'bale-admin', 'phone' => $mobile === '' ? 'خالی' : 'ست شده'],
                );

                return;
            }

            if ($this->sender->send($chatId, $text)) {
                return;
            }

            // آلمان نشد → صفِ ایران، همان fallbackِ همیشگی. هیچ هزینه‌ای ندارد.
            if ($mobile !== '') {
                $this->queueForIran($mobile, $chatId, $text);
            }
        } catch (\Throwable) {
            // اعلانِ داخلی هرگز نباید جریان اصلی را بشکند
        }
    }

    /**
     * تلاش برای رساندن یک متن به بلهٔ یک شماره — **مسیرِ مشتری**.
     *
     * ⚠️ سفیر را صدا می‌زند، پس هزینه دارد. برای اعلانِ داخلی `toAdmin()` را
     * بزن، نه این را.
     *
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
