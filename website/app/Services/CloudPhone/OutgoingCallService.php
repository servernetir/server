<?php

namespace App\Services\CloudPhone;

use App\Support\ErrorTracker;
use App\Support\IranianPhone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * تماس خروجی (Click-to-Call) — از راهِ رلهٔ n8n.
 *
 * ═══ چرا رله و نه تماسِ مستقیم ═══
 *
 * `coreapi.daftareshoma.com` از سرورِ آلمان **در دسترس نیست** (connect timeout).
 * دقیقاً همان وضعیتِ آی‌پی‌پنل. پس مثلِ `N8nRelaySender`، درخواست از n8nِ
 * ایرانی رد می‌شود:
 *
 *     Laravel (آلمان) ──پاکتِ امضاشده──▶ n8n (ایران) ──▶ coreapi.daftareshoma.com
 *
 * ⚠️ جهتِ وبهوکِ ورودی برعکس است و رله نمی‌خواهد — سرورِ «دفتر شما» بدونِ
 * مشکل به سایتِ ما می‌رسد (با ۱۰ رویدادِ واقعی تأیید شد).
 *
 * ═══ چرا امضا، وقتی خودمان فرستنده‌ایم ═══
 *
 * وب‌هوکِ n8n عمومی است. بی‌امضا، هر کسی که نشانی‌اش را بداند می‌تواند از خطِ
 * ما به هر شماره‌ای زنگ بزند — هزینه رویِ ما و شمارهٔ ما رویِ کالر آی‌دیِ
 * غریبه. همان HMAC-SHA256 و پنجرهٔ ۱۸۰ ثانیه‌ایِ رلهٔ پیامک.
 *
 * 🔴 `PHONE_TOKEN` **در پاکت نیست.** در گرهٔ Relay Config داخلِ n8n می‌ماند،
 * دقیقاً مثلِ توکنِ آی‌پی‌پنل. پاکتی که لو برود نباید کلیدِ حساب را لو بدهد.
 */
final class OutgoingCallService
{
    private const VERSION = 1;

    private const PREFIX = 'CLOUD_PHONE_V1:';

    public const OK = 'ok';

    public const DISABLED = 'disabled';

    public const BAD_NUMBER = 'bad_number';

    public const NO_EXTENSION = 'no_extension';

    public const FAILED = 'failed';

    public function enabled(): bool
    {
        $url = (string) config('services.cloud_phone.relay_url');

        /*
        | ⚠️ نشانیِ غیر-https رد می‌شود. پاکت شمارهٔ مشتری دارد و رویِ http هر
        | واسطی می‌خواندش. بی‌این بررسی، یک اشتباهِ تایپی در `.env` کلِ حفاظت را
        | بی‌صدا برمی‌داشت — همان درسی که در `N8nRelaySender` ثبت است.
        */
        return $url !== ''
            && str_starts_with(strtolower($url), 'https://')
            && (string) config('services.cloud_phone.relay_secret') !== '';
    }

    /**
     * یک تماس خروجی برقرار کن.
     *
     * @return array{status: string, message: string, request_id: ?string}
     */
    public function place(string $toNumber, ?string $callerExtension): array
    {
        if (! $this->enabled()) {
            return $this->result(
                self::DISABLED,
                'رلهٔ تلفن ابری پیکربندی نشده (CLOUD_PHONE_RELAY_URL و CLOUD_PHONE_RELAY_SECRET).',
            );
        }

        /*
        | 🔴 بدونِ داخلی هیچ تماسی برقرار نمی‌شود.
        |
        | سامانه اول داخلیِ کارشناس را زنگ می‌زند و بعد مقصد را می‌گیرد. اگر
        | داخلی خالی برود، بهترین حالت خطای تأمین‌کننده است و بدترین حالت این
        | که تماس از یک داخلیِ پیش‌فرضِ نامعلوم برقرار شود و کارشناس هیچ‌وقت
        | نفهمد چرا تلفنش زنگ نخورد.
        */
        if ($callerExtension === null || trim($callerExtension) === '') {
            return $this->result(
                self::NO_EXTENSION,
                'داخلیِ شما ثبت نشده. در تنظیمات حساب کاربری داخلی‌تان را وارد کنید.',
            );
        }

        $normalised = IranianPhone::normalize($toNumber);
        $kind = IranianPhone::kind($toNumber);

        if ($normalised === null || $kind === IranianPhone::KIND_UNKNOWN) {
            return $this->result(self::BAD_NUMBER, 'شمارهٔ مقصد معتبر نیست.');
        }

        /*
        | ⚠️ شمارهٔ محلیِ بی‌پیش‌شماره را نمی‌شود گرفت.
        |
        | تماس‌گیرندهٔ ورودی ممکن است `34261000` باشد (تأمین‌کننده پیش‌شماره را
        | حذف می‌کند)، ولی برای **زنگ‌زدن** به آن شماره باید بدانیم کدام شهر.
        | حدس‌زدنِ پیش‌شماره یعنی زنگ‌زدن به یک غریبه در شهرِ دیگر.
        */
        if ($kind === IranianPhone::KIND_LOCAL || $kind === IranianPhone::KIND_EXTENSION) {
            return $this->result(
                self::BAD_NUMBER,
                'این شماره پیش‌شمارهٔ شهر ندارد و قابل شماره‌گیری نیست. شمارهٔ کامل را وارد کنید.',
            );
        }

        $requestId = (string) Str::uuid();

        $payload = [
            'version' => self::VERSION,
            'action' => 'outgoing_call',
            // شکلِ ملیِ بدونِ صفر — n8n خودش قالبِ موردِ نیازِ API را می‌سازد
            'to_number' => $normalised,
            'caller_extension' => trim($callerExtension),
            // 🔴 برای idempotency در سمتِ n8n: اگر HTTP retry بخورد، مشتری
            //    نباید دو بار زنگ بخورد.
            'request_id' => $requestId,
            // ⚠️ n8n پاکتِ خارج از پنجره را رد می‌کند (ضدِّ بازپخش)
            'issued_at' => time(),
        ];

        try {
            $res = Http::asJson()->acceptJson()->timeout(20)
                ->post(
                    (string) config('services.cloud_phone.relay_url'),
                    ['envelope' => $this->encode($payload)],
                );

            if (! $res->successful()) {
                return $this->fail($requestId, 'n8n کدِ '.$res->status().' داد: '.mb_substr($res->body(), 0, 200));
            }

            /*
            | 🔴 fail-closed: **فقط** `sent` موفقیت است.
            |
            | ورک‌فلو برای پاکتِ ردشده هم ۲۰۰ می‌دهد، با بدنهٔ
            |   {"status":"ignored","reason":"bad_signature"}
            | اگر فقط به کدِ HTTP نگاه کنیم، رازِ ناهماهنگ «موفق» گزارش می‌شود و
            | مدیر منتظرِ زنگی می‌ماند که هرگز نمی‌آید. همان درسِ رلهٔ پیامک.
            */
            if (($res->json('status') ?? null) !== 'sent') {
                return $this->fail(
                    $requestId,
                    'رله تماس را برقرار نکرد: '.(string) ($res->json('reason') ?? 'پاسخ ناشناخته'),
                );
            }
        } catch (\Throwable $e) {
            return $this->fail($requestId, $e->getMessage());
        }

        return [
            'status' => self::OK,
            'message' => 'تماس در حال برقراری است — ابتدا داخلیِ شما زنگ می‌خورد.',
            'request_id' => $requestId,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────

    /** پاکت: `CLOUD_PHONE_V1:` + base64url(json) + `.` + HMAC-SHA256 */
    private function encode(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $sig = hash_hmac('sha256', $b64, (string) config('services.cloud_phone.relay_secret'));

        return self::PREFIX.$b64.'.'.$sig;
    }

    private function fail(string $requestId, string $reason): array
    {
        ErrorTracker::note('cloud-phone', 'تماس خروجی ناموفق: '.$reason, ['request_id' => $requestId]);

        return [
            'status' => self::FAILED,
            'message' => 'تماس برقرار نشد. جزئیات در ردیاب خطا ثبت شد.',
            'request_id' => $requestId,
        ];
    }

    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message, 'request_id' => null];
    }
}
