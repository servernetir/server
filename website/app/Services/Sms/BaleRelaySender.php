<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * رلهٔ پیامک از راهِ بله.
 *
 * ═══ 🔴 این مسیر کار نمی‌کند — پیش از استفاده بخوان ═══
 *
 * زنجیره این بود:
 *
 *     پروژه → رباتِ فرستنده → گروهِ خصوصیِ بله → رباتِ گیرنده → n8n → آی‌پی‌پنل
 *
 * و حلقهٔ «رباتِ گیرنده» **هرگز بسته نشد**: بله، مثلِ تلگرام که کپی‌اش است،
 * پیامِ یک ربات را به رباتِ دیگر تحویل نمی‌دهد. این با شواهدِ قطعی ثابت شد —
 * وب‌هوکِ رباتِ گیرنده درست ست بود، `pending_update_count` صفر بود، رباتِ
 * فرستنده پیام را در گروه می‌نوشت، و n8n برای هیچ‌کدام اجرایی نمی‌ساخت.
 *
 * **جایگزین: `N8nRelaySender`** — همان پاکت، همان امضا، مستقیم به وب‌هوکِ n8n.
 * با `SMS_DRIVER=n8n_relay` فعال می‌شود.
 *
 * این کلاس عمداً حذف نشد: اگر روزی وب‌هوک از سرورِ آلمان در دسترس نبود، یا
 * بله رفتارش را عوض کرد، مسیرِ دوم آماده است و فقط یک خطِ `.env` فاصله دارد.
 *
 * ═══ ⚠️ هشدارِ امنیتیِ ثبت‌شده ═══
 *
 * بدنه با Base64URL کدگذاری و با HMAC-SHA256 **امضا** می‌شود. امضا جلوی
 * **جعل** را می‌گیرد، ولی Base64 رمزنگاری نیست: هر کسی که به آن گروهِ بله
 * دسترسی پیدا کند — و خودِ بله — با یک `base64 -d` شمارهٔ موبایل و **کدِ ورودِ**
 * هر مشتری را می‌بیند.
 *
 * این ایراد به کارفرما گزارش شد و آگاهانه پذیرفته شده بود. `N8nRelaySender`
 * آن را **رایگان** حل می‌کند، چون پاکت اصلاً از گروه عبور نمی‌کند.
 *
 * ═══ چرا درایور، نه سرویسِ مستقل ═══
 *
 * اسپکِ اولیه یک کلاسِ مستقل می‌خواست و جایگزینیِ تک‌تکِ فراخوان‌های آی‌پی‌پنل.
 * ولی این پروژه از قبل قراردادِ `SmsSender`/`SupportsPatterns` و یک رجیستریِ
 * درایور در `AppServiceProvider` دارد. با پیاده‌سازی به‌شکلِ درایور، **هیچ**
 * فراخوانی در کد عوض نمی‌شود — و همین بود که گذارِ امروز به مسیرِ مستقیم را
 * به یک خطِ `.env` تبدیل کرد، نه بازنویسیِ ده‌ها نقطه.
 */
class BaleRelaySender extends SignedRelaySender
{
    public function __construct(
        private ?string $botToken,
        private ?string $chatId,
        private ?string $sharedSecret,
        private string $base = 'https://tapi.bale.ai',
    ) {}

    public function enabled(): bool
    {
        return filled($this->botToken) && filled($this->chatId) && filled($this->sharedSecret);
    }

    public function name(): string
    {
        return 'bale-relay';
    }

    protected function secret(): string
    {
        return (string) $this->sharedSecret;
    }

    protected function deliver(string $envelope): bool
    {
        $res = Http::asJson()->acceptJson()->timeout(10)->retry(2, 500)
            ->post(rtrim($this->base, '/')."/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text'    => $envelope,
            ]);

        // ⚠️ بله مثلِ خیلی از APIهای این حوزه روی خطا هم ۲۰۰ می‌دهد؛
        //    نتیجهٔ واقعی در فیلدِ `ok` بدنه است.
        if (! $res->successful() || $res->json('ok') !== true) {
            throw new \RuntimeException('بله پیام را نپذیرفت ('.$res->status().'): '
                .mb_substr($res->body(), 0, 200));
        }

        return true;
    }
}
