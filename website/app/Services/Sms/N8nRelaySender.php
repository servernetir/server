<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * رلهٔ پیامک — **مستقیم** به وب‌هوکِ n8n.
 *
 * ═══ چرا این جایگزینِ مسیرِ بله شد ═══
 *
 * مسیرِ قبلی این بود:
 *
 *     پروژه → رباتِ فرستنده → گروهِ خصوصیِ بله → رباتِ گیرنده → n8n
 *
 * و **هرگز کار نکرد**. بله (مثلِ تلگرام که کپی‌اش است) پیامِ یک ربات را به
 * رباتِ دیگر تحویل نمی‌دهد. شواهدِ قطعی:
 *
 *   • وب‌هوکِ رباتِ گیرنده درست ست بود (`getWebhookInfo` تأیید کرد)
 *   • `pending_update_count: 0` — یعنی چیزی هم در صف گیر نکرده بود
 *   • رباتِ فرستنده پیام را در گروه **می‌نوشت** (کارفرما دید)
 *   • و n8n برای هیچ‌کدام از آن پیام‌ها **هیچ اجرایی** نساخت، در حالی که
 *     درخواستِ مستقیمِ من به همان وب‌هوک بی‌درنگ اجرا می‌ساخت
 *
 * ═══ سه سودِ هم‌زمانِ حذفِ آن حلقه ═══
 *
 * ۱) **دو نقطهٔ شکست کمتر.** گروه و رباتِ گیرنده هر دو حذف شدند.
 *
 * ۲) 🔴 **حفرهٔ امنیتی بسته شد.** در مسیرِ بله، پاکت با Base64 کدگذاری می‌شد و
 *    Base64 رمزنگاری نیست: هر عضوِ آن گروه — و خودِ بله — با یک `base64 -d`
 *    شمارهٔ موبایل و **کدِ ورودِ** هر مشتری را می‌دید. این ایراد گزارش و
 *    آگاهانه پذیرفته شده بود؛ حالا رایگان حل شد چون پاکت فقط روی TLS بینِ
 *    سرورِ ما و n8n می‌رود.
 *
 * ۳) تأخیرِ کمتر — یک پرش به‌جای چهار.
 *
 * ═══ امنیت ═══
 *
 * وب‌هوک عمومی است، پس **امضا تنها دروازه است** و همان امضایی است که مسیرِ
 * بله هم داشت (HMAC-SHA256 روی رشتهٔ Base64، رازِ مشترک با n8n). به‌علاوه n8n
 * پاکتِ خارج از پنجرهٔ ۱۸۰ ثانیه را رد می‌کند، پس بازپخشِ کهنه بی‌اثر است.
 *
 * ⚠️ رازِ رله **در پاکت نیست** — فقط امضا را می‌سازد. پس حتی اگر کسی پاکتی را
 * ببیند، نمی‌تواند پاکتِ تازه بسازد.
 */
class N8nRelaySender extends SignedRelaySender
{
    public function __construct(
        private ?string $url,
        private ?string $sharedSecret,
    ) {}

    public function enabled(): bool
    {
        // ⚠️ نشانیِ غیرِ https رد می‌شود: پاکت شمارهٔ موبایل و کدِ ورود دارد و
        //    روی http هر واسطی می‌خواندش. بی‌این بررسی، یک اشتباهِ تایپی در
        //    .env کلِ حفاظت را بی‌صدا برمی‌داشت.
        return filled($this->url)
            && filled($this->sharedSecret)
            && str_starts_with(strtolower((string) $this->url), 'https://');
    }

    public function name(): string
    {
        return 'n8n-relay';
    }

    protected function secret(): string
    {
        return (string) $this->sharedSecret;
    }

    protected function deliver(string $envelope): bool
    {
        $res = Http::asJson()->acceptJson()->timeout(15)->retry(2, 500)
            ->post((string) $this->url, ['envelope' => $envelope]);

        /*
        | ⚠️ کدِ ۲۰۰ کافی **نیست**.
        |
        | ورک‌فلو برای پیامی که از فیلتر رد نشود هم ۲۰۰ می‌دهد، با بدنهٔ
        |   {"status":"ignored","reason":"bad_signature"}
        | اگر فقط به کدِ HTTP نگاه کنیم، رازِ ناهماهنگ یا الگوی ناشناخته
        | «موفق» گزارش می‌شود و ما باور می‌کنیم پیامک رفته — دقیقاً همان
        | خرابیِ خاموشی که این کلاس برای رفعش نوشته شد.
        */
        if (! $res->successful()) {
            throw new \RuntimeException('n8n کدِ '.$res->status().' داد: '.mb_substr($res->body(), 0, 200));
        }

        $status = (string) $res->json('status', '');

        if ($status === 'ignored' || $status === 'failed') {
            throw new \RuntimeException('n8n پاکت را نپذیرفت: '.(string) $res->json('reason', 'بی‌دلیل'));
        }

        return true;
    }
}
