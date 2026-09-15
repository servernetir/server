<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use App\Services\Ai\AiCaller;
use App\Services\Ai\AiCallOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * رابطِ عمومیِ سازگارِ OpenAI — `POST /v1/chat/completions` (M4-b).
 *
 * ═══ ترتیبِ ثابتِ گیت‌ها ═══
 *
 *   Bearer → `CustomerApiToken::findByPlain` (ناشناخته/خالی = ۴۰۱ invalid_token)
 *   → `AiAdmission::authorize($token, 'ai:chat', $ip)` — کد و پیامِ رد
 *     **بی‌واسطه و بی‌بازنویسی** چاپ می‌شود؛ این کنترلر قاضی نیست
 *   → الزامِ `model` (خالی/غایب = ۴۲۲ invalid_payload)
 *   → `AiCaller::handle` — تنها ارکستراتورِ پول‌خور
 *
 * ═══ قراردادِ خطا ═══
 *
 * هر پاسخِ غیرِسبزِ این مسیر همان شکلِ `{code, message}` است و `code`
 * پایدار است — همان کدی که admission یا AiCaller تولید کرده. هیچ ۵۰۰ِ
 * بی‌کدی برای حالتی که کدِ پایدار دارد ساخته نمی‌شود؛ `settle_failed`
 * تنها کدی است که به ۵۰۰ می‌رود (نشتِ ممیزیِ دفتر، نه خطایِ مشتری).
 *
 *   ── جدولِ کد → HTTP (عمدی و پین‌شده با V1ChatTest) ──
 *
 *     401  invalid_token · token_expired · token_revoked
 *     402  insufficient_funds        ← «پرداخت لازم است»؛ معنایِ دقیقِ کیفِ خالی
 *     403  ip_not_allowed · insufficient_scope · not_ai_key · not_ai_scope
 *          account_inactive · project_missing · project_inactive · budget_window_stale
 *     404  model_not_found
 *     409  duplicate_request         ← هم‌ارزی؛ پاسخِ ضبط‌شده بازپخش نمی‌شود (پایین)
 *     422  invalid_payload
 *     500  settle_failed
 *     502  upstream_error · upstream_unreachable
 *     503  provider_unconfigured · provider_not_live · model_inactive
 *          driver_unsupported · not_priced
 *
 * ═══ 🔴 چرا احراز در کنترلر است و نه در میدل‌ورِ `CustomerApiToken` ═══
 *
 * آن میدل‌ور (مسیرِ `api/v1` دامنه) سه چیز دارد که این‌جا نباید تکرار شود:
 * شکلِ خطای `{ok, error, message}` (قراردادِ `/v1` این‌جا `{code, message}`
 * سازگارِ OpenAI است)، شمارشِ `use_count` پیش از admission (admission خودش
 * فقط در مسیرِ سبز می‌شمارد)، و نادیده‌گرفتنِ گیت‌های پروژه/بودجه که
 * فقط در `AiAdmission` هستند. استخراجِ Bearer با همان ابزارِ همگانی
 * (`$request->bearerToken()` + `findByPlain`) انجام می‌شود — سیستمِ
 * احرازِ موازی ساخته نشده، فقط لایهٔ JSON میدل‌ورِ نشستی جایگزین شده.
 *
 * ═══ 🔴 مرزِ عمدیِ M4-b (مستندشده) ═══
 *
 * بازپخشِ پاسخِ ضبط‌شدهٔ جدولِ `ai_calls` برای کلیدِ هم‌ارزیِ تکراری
 * **در این گام پیاده نشده**: درخواستِ تکراری همان `duplicate_request` با
 * وضعیتِ ۴۰۹ می‌گیرد (و مطابقِ قراردادِ AiCaller، هیچ تماسِ بالادستیِ
 * دومی و هیچ رزروِ دومی باز نمی‌شود). بازگرداندِ بدنهٔ پاسخِ تماسِ
 * نخست، آیتمِ M4-c است و باید از روی ردیفِ ضبط‌شدهٔ ai_calls بخواند.
 */
final class V1ChatController extends Controller
{
    /*
    | 🔴 سقفِ per-token (M4-c) — سطلِ `throttle:ai` بر IP است؛ کاربرانِ
    |    پشتِ یک IP (شرکت/NAT/CGNAT) سهمِ IP را به هم می‌خورند. این سقفِ
    |    دوم با کلیدِ **هشِ Bearer** است (هرگز متنِ کلید در کش نمی‌نشیند)،
    |    مستقل از IP. سقف بالاتر از سطلِ IP است چون این دومین دیوارست،
    |    نه دیوارِ نخست — هر دو با رزرو/TTL معماریِ پول، سقف سوم‌اند.
    */
    public const PER_TOKEN_PER_MINUTE = 240;

    /** قراردادِ ثابتِ بدنهٔ خطا — یک شکل برای همهٔ ردها */
    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    public function chat(Request $request): JsonResponse
    {
        // ── گیتِ توکن: هدرِ Bearer — ناشناخته یا غایب، هر دو یک کدِ پایدار ──
        $bearer = (string) ($request->bearerToken() ?? '');

        if ($bearer !== '') {
            $limiterKey = 'ai-v1-token|'.hash('sha256', $bearer);

            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limiterKey, self::PER_TOKEN_PER_MINUTE)) {
                $retry = \Illuminate\Support\Facades\RateLimiter::availableIn($limiterKey);

                return response()->json([
                    'code'    => 'rate_limited',
                    'message' => 'شمارِ درخواست‌هایِ این کلید از حدِ مجاز گذشت؛ کمی بعد دوباره تلاش کنید.',
                    'retry_after' => $retry,
                ], 429, ['Retry-After' => (string) $retry]);
            }

            \Illuminate\Support\Facades\RateLimiter::hit($limiterKey, 60);
        }

        $token = $bearer !== '' ? CustomerApiToken::findByPlain($bearer) : null;

        if ($token === null) {
            return $this->fail('invalid_token', 'کلیدِ API شناخته نشد یا ارسال نشده است.', 401);
        }

        /*
        | admission — تنها قاضیِ «آیا این کلید اجازه دارد؟». کد و پیامِ رد
        | بی‌واسطه بالا می‌رود؛ این‌جا هیچ پیامی بازنویسی یا ترجمه نمی‌شود.
        */
        $auth = AiAdmission::authorize($token, 'ai:chat', $request->ip());

        if (! $auth->ok) {
            return $this->fail(
                $auth->code,
                $auth->message !== '' ? $auth->message : 'دسترسی پذیرفته نشد.',
                self::statusFor($auth->code),
            );
        }

        // ── الزامِ `model` — همان قراردادِ OpenAI، پیش از هر تماسِ پول‌خور ──
        $payload = (array) $request->input();
        $modelSlug = trim((string) ($payload['model'] ?? ''));

        if ($modelSlug === '') {
            return $this->fail('invalid_payload', 'فیلدِ «model» الزامی است.', 422);
        }

        unset($payload['model']); // اسلاگِ عمومی هرگز به بالادست نمی‌رود؛ درایور نامِ واقعی را می‌نویسد

        /*
        | کلیدِ هم‌ارزی — سربرگِ `Idempotency-Key`، اختیاری. در AiCaller
        | همان کلیدِ رزرو است: تکرار = همان ردیف، بدونِ تماسِ دوم و
        | بدونِ خرجِ دوباره (کدِ `duplicate_request`).
        */
        $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?? ''));

        $outcome = app(AiCaller::class)->handle(
            $auth,
            $modelSlug,
            $payload,
            $idempotencyKey !== '' ? $idempotencyKey : null,
        );

        if ($outcome->ok) {
            /*
            | بدنهٔ ارائه‌دهنده **بی‌تفسیر** برمی‌گردد — قراردادِ سازگارِ
            | OpenAI یعنی مشتری همان JSONای را ببیند که خودش می‌دید.
            | no-store عمدی: پاسخِ چت هرگز نباید در کشِ واسطه بنشیند.
            */
            return response()->json($outcome->response, 200, [
                'Cache-Control' => 'no-store',
            ]);
        }

        /*
        | مسیرِ قرمز — کدِ پایدارِ outcome مالکِ وضعیت است؛ هیچ استثنایی
        | با ۵۰۰ِ بی‌کدی بلعیده نمی‌شود. کدِ ناشناخته (پدیدهٔ آینده) هم
        | هرگز بی‌کد نمی‌ماند: ۵۰۰ + خودِ کد.
        */
        return $this->fail($outcome->code, $outcome->message, self::statusFor($outcome->code));
    }

    /**
     * نگاشتِ کدِ پایدار → وضعیتِ HTTP — تنها منبعِ حقیقتِ این مسیر.
     * هر کدِ جدید باید همین‌جا و در V1ChatTest با هم قفل شود.
     */
    public static function statusFor(string $code): int
    {
        return match ($code) {
            // احراز — کلیدِ غایب/ناشناخته/مرده
            'invalid_token', 'token_expired', 'token_revoked' => 401,

            // پول — موجودی ناکافی؛ معنایِ ۴۰۲ همین است
            'insufficient_funds' => 402,

            // دامنه و پروژه — کلیدِ سالم ولی بی‌اجازه
            'not_ai_scope', 'not_ai_key', 'insufficient_scope', 'ip_not_allowed',
            'account_inactive', 'project_missing', 'project_inactive',
            'budget_window_stale' => 403,

            // مدل — شناخته‌نشده
            'model_not_found' => 404,

            // هم‌ارزی — کلیدِ تکراری؛ پاسخِ ذخیره‌شده بازپخش نمی‌شود (M4-c)
            'duplicate_request' => 409,

            // بدنه — قراردادِ OpenAI: «model» الزامی
            'invalid_payload' => 422,

            // بالادست — خطایِ ارائه‌دهنده یا بی‌ردی
            'upstream_error', 'upstream_unreachable' => 502,

            // سمتِ ما — پیکربندی/فروش‌پذیریِ مدل؛ مشتی که مشتری نمی‌تواند درست کند
            'provider_unconfigured', 'provider_not_live', 'model_inactive',
            'driver_unsupported', 'not_priced' => 503,

            // استثنای ممیزی — پاسخِ بالادست ممکن سالم باشد ولی دفتر نشت کرده
            'settle_failed' => 500,

            // کدِ ناشناخته — پایدار چاپ می‌شود ولی با وضعیتِ «خطای سرور»
            default => 500,
        };
    }
}
