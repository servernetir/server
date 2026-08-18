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

    public const NO_AGENT = 'no_agent';

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
     * شماره‌ای که **اول** زنگ می‌خورد.
     *
     * ترتیب عمدی است: شمارهٔ شخصیِ کاربر (اگر ثبت کرده) مقدم بر پیش‌فرضِ
     * سراسری. تا وقتی تیم یک نفره است `.env` کافی است؛ روزی که چند نفر شدند،
     * هر کس شمارهٔ خودش را ثبت می‌کند و هیچ کدی عوض نمی‌شود.
     */
    public function agentNumberFor(?string $userNumber): ?string
    {
        $candidate = trim((string) ($userNumber ?? '')) !== ''
            ? (string) $userNumber
            : (string) config('services.cloud_phone.agent_number');

        return trim($candidate) === '' ? null : trim($candidate);
    }

    /** خطِ ابری — همان که روی کالر آی‌دیِ مشتری می‌افتد. */
    public function extension(): ?string
    {
        $ext = trim((string) config('services.cloud_phone.extension'));

        return $ext === '' ? null : $ext;
    }

    /**
     * یک تماس خروجی برقرار کن.
     *
     * @param  string  $toNumber  شمارهٔ مشتری
     * @param  ?string  $agentNumber  شماره‌ای که اول زنگ می‌خورد (نال ⇒ پیش‌فرضِ سراسری)
     * @return array{status: string, message: string, request_id: ?string}
     */
    public function place(string $toNumber, ?string $agentNumber = null): array
    {
        if (! $this->enabled()) {
            return $this->result(
                self::DISABLED,
                'رلهٔ تلفن ابری پیکربندی نشده (CLOUD_PHONE_RELAY_URL و CLOUD_PHONE_RELAY_SECRET).',
            );
        }

        /*
        | 🔴 بدونِ شمارهٔ تماس‌گیرنده هیچ تماسی برقرار نمی‌شود.
        |
        | سامانه اول این شماره را می‌گیرد و وقتی برداشتی، مشتری را با کالر
        | آی‌دیِ خطِ ابری صدا می‌زند. اگر خالی برود، بهترین حالت خطای
        | تأمین‌کننده است و بدترین حالت این که تماس از یک شمارهٔ نامعلوم برقرار
        | شود و هیچ‌کس نفهمد چرا تلفنش زنگ نخورد.
        */
        $agent = $this->agentNumberFor($agentNumber);

        if ($agent === null) {
            return $this->result(
                self::NO_AGENT,
                'شمارهٔ تماس‌گیرنده تنظیم نشده (CLOUD_PHONE_AGENT_NUMBER در .env).',
            );
        }

        /*
        | 🔴 «نال نیست» کافی **نیست** — و این را با یک خرابیِ واقعی یاد گرفتیم.
        |
        | یک کاربر عددِ `1` را در فیلدِ شمارهٔ تماس‌گیرنده ثبت کرده بود.
        | `normalize('1')` مقدارِ `'1'` می‌دهد نه `null`، پس از این نگهبان رد شد،
        | در پاکت نشست، و رله `from_number: "01"` را به تأمین‌کننده فرستاد.
        | نتیجه: تماس شکست خورد و علتش سه لایه آن‌طرف‌تر پیدا شد.
        |
        | حالا همان قاعدهٔ مقصد این‌جا هم اعمال می‌شود: فقط موبایل یا ثابتِ
        | **با پیش‌شماره**. شمارهٔ محلیِ بی‌پیش‌شماره و داخلیِ کوتاه رد می‌شوند،
        | چون هیچ‌کدام شماره‌گیری‌شدنی نیستند.
        */
        $agentKind = IranianPhone::kind($agent);

        if ($agentKind !== IranianPhone::KIND_MOBILE && $agentKind !== IranianPhone::KIND_LANDLINE) {
            return $this->result(
                self::NO_AGENT,
                'شمارهٔ تماس‌گیرنده معتبر نیست: «'.$agent.'». شمارهٔ کامل با پیش‌شماره لازم است (مثلاً ۰۹۱۴۲۲۲۳۳۴۳).',
            );
        }

        if ($this->extension() === null) {
            return $this->result(
                self::NO_AGENT,
                'خطِ ابری تنظیم نشده (CLOUD_PHONE_EXTENSION در .env).',
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
            /*
            | نگاشت با رویدادِ واقعیِ `CallOutgoingEnded` تأیید شد:
            |   CallerNumber    ← from_number       (پایی که اول زنگ می‌خورد)
            |   CalleeExtension ← caller_extension  (خطِ ابری)
            */
            'from_number' => IranianPhone::normalize($agent),
            'caller_extension' => $this->extension(),
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
                /*
                | ⚠️ `detail` هم ثبت می‌شود.
                |
                | «api_status_500» به‌تنهایی ما را یک ساعت دنبالِ حدس فرستاد.
                | گره حالا بدنهٔ پاسخِ تأمین‌کننده را هم برمی‌گرداند و همان یک
                | جمله معمولاً مستقیم می‌گوید چه چیزی را نپسندیده.
                */
                $detail = (string) ($res->json('detail') ?? '');

                return $this->fail(
                    $requestId,
                    'رله تماس را برقرار نکرد: '.(string) ($res->json('reason') ?? 'پاسخ ناشناخته')
                    .($detail !== '' ? ' — '.mb_substr($detail, 0, 300) : ''),
                );
            }
        } catch (\Throwable $e) {
            return $this->fail($requestId, $e->getMessage());
        }

        return [
            'status' => self::OK,
            'message' => 'تماس در حال برقراری است — ابتدا '.$agent.' زنگ می‌خورد، بعد مشتری.',
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
