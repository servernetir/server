<?php

namespace App\Http\Controllers;

use App\Models\SmsOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * پل پیامک — سمت آلمان.
 *
 * سرور ایران این دو مسیر را صدا می‌زند:
 *   pull   → پیام‌های نفرستاده را بردار (و قفلشان کن)
 *   report → نتیجهٔ هر کدام را بگو
 *
 * ═══ احراز هویت ═══
 *
 * همان کلید مشترکی که برای رابط تعریف شده بود. امضا روی بدنهٔ خام، با
 * پنجرهٔ زمانی و nonce یک‌بارمصرف — دقیقاً مثل رابط، فقط در جهت مخالف.
 *
 * بدون این، هر کسی می‌توانست صف پیامک ما را بخواند و شمارهٔ مشتریان و
 * کدهای ورودشان را بردارد.
 *
 * ═══ چرا قفل و نه فقط «برداشتن» ═══
 *
 * اگر دو اجرای کران هم‌زمان بیفتند — که با اجرای دقیقه‌ای پیش می‌آید —
 * هر دو همان پیام را می‌بینند و کاربر دو پیامک می‌گیرد و ما دو بار پول
 * می‌دهیم. قفل با UPDATE شرطی گرفته می‌شود، نه با SELECT سپس UPDATE.
 */
class SmsBridgeController extends Controller
{
    private const MAX_SKEW    = 120;   // ثانیه
    private const MAX_ATTEMPT = 3;
    private const CLAIM_TTL   = 120;   // ثانیه — بعدش پیام دوباره آزاد می‌شود

    public function pull(Request $request): JsonResponse
    {
        if (($deny = $this->authorize($request)) !== null) {
            return $deny;
        }

        /*
         * ردِ زندهٔ فرستنده.
         *
         * بدون این، «پیامک نرفت» دو علت کاملاً متفاوت دارد که از بیرون یکی
         * دیده می‌شوند: یا فرستندهٔ ایران اصلاً بالا نیامده، یا آمده و
         * آی‌پی‌پنل ردش کرده. این مهر زمانی آن دو را از هم جدا می‌کند.
         */
        cache()->put('smsbridge:last_pull', now()->toIso8601String(), now()->addDay());

        $token = (string) Str::uuid();
        $limit = min(20, max(1, (int) $request->input('limit', 10)));

        DB::transaction(function () use ($token, $limit) {
            // قفل اتمی: فقط ردیف‌هایی که هیچ‌کس نگرفته یا قفلشان کهنه شده
            $ids = SmsOutbox::query()
                ->where('status', 'queued')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', self::MAX_ATTEMPT)
                ->where(function ($q) {
                    $q->whereNull('claimed_at')
                      ->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_TTL));
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                SmsOutbox::whereIn('id', $ids)->update([
                    'claim_token' => $token,
                    'claimed_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        });

        // پیام‌های منقضی را همین‌جا جمع می‌کنیم — کد مردهٔ سه‌دقیقه‌ای
        // نباید نیم‌ساعت بعد فرستاده شود
        SmsOutbox::where('status', 'queued')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $messages = SmsOutbox::where('claim_token', $token)
            ->orderBy('id')
            ->get(['id', 'destination', 'event', 'body', 'params']);

        return response()->json([
            'claim'    => $token,
            'messages' => $messages->map(fn (SmsOutbox $m) => [
                'id'          => $m->id,
                'destination' => $m->destination,
                'event'       => $m->event,
                'body'        => $m->body,
                'params'      => $m->params,
            ])->all(),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        if (($deny = $this->authorize($request)) !== null) {
            return $deny;
        }

        $results = $request->input('results', []);

        if (! is_array($results)) {
            return $this->deny('bad_results', 422);
        }

        $updated = 0;

        foreach (array_slice($results, 0, 50) as $r) {
            $id = (int) ($r['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            /** @var SmsOutbox|null $m */
            $m = SmsOutbox::find($id);

            if ($m === null || $m->status !== 'queued') {
                continue;
            }

            $ok = (bool) ($r['ok'] ?? false);

            $m->forceFill([
                'attempts'         => $m->attempts + 1,
                'status'           => $ok ? 'sent' : ($m->attempts + 1 >= self::MAX_ATTEMPT ? 'failed' : 'queued'),
                'sent_at'          => $ok ? now() : null,
                'provider_code'    => isset($r['code']) ? mb_substr((string) $r['code'], 0, 24) : null,
                'provider_message' => isset($r['message']) ? mb_substr((string) $r['message'], 0, 255) : null,
                // آزاد کردن قفل تا اگر لازم شد دوباره تلاش شود
                'claim_token'      => null,
                'claimed_at'       => null,
            ])->save();

            $updated++;
        }

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /** رد کردن، با ثبت علت برای تشخیص از راه دور */
    private function deny(string $reason, int $status): JsonResponse
    {
        cache()->put('smsbridge:last_deny', $reason.' @ '.now()->toIso8601String(), now()->addDay());

        return response()->json(['ok' => false, 'reason' => $reason], $status);
    }

    // ───────────────────────────── احراز هویت ─────────────────────────────

    /** null یعنی مجاز؛ در غیر این صورت پاسخ خطا */
    private function authorize(Request $request): ?JsonResponse
    {
        /*
         * ثبت *هر* تماس، حتی ردشده.
         *
         * بدون این، «فرستنده اصلاً نیامده» و «آمده ولی کلیدش غلط بوده» از
         * بیرون یکسان دیده می‌شوند — و آن دو راه‌حل کاملاً متفاوتی دارند.
         * اینجا قبل از بررسی امضاست تا حتی تماس نامعتبر هم دیده شود.
         */
        cache()->put('smsbridge:last_attempt', now()->toIso8601String(), now()->addDay());

        $secret = (string) config('services.sms.relay_secret');

        if ($secret === '' || strlen($secret) < 24) {
            return $this->deny('bridge_not_configured', 503);
        }

        $ts    = (string) $request->header('X-Relay-Timestamp', '');
        $nonce = (string) $request->header('X-Relay-Nonce', '');
        $sig   = (string) $request->header('X-Relay-Signature', '');

        if ($ts === '' || $nonce === '' || $sig === '') {
            return $this->deny('missing_signature', 401);
        }

        if (! ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW) {
            return $this->deny('stale_timestamp', 401);
        }

        if (preg_match('/^[A-Za-z0-9._-]{8,64}$/', $nonce) !== 1) {
            return $this->deny('bad_nonce', 400);
        }

        $expected = hash_hmac('sha256', $ts."\n".$nonce."\n".$request->getContent(), $secret);

        if (! hash_equals($expected, $sig)) {
            return $this->deny('bad_signature', 401);
        }

        // nonce یک‌بارمصرف — درخواست ضبط‌شده دوباره کار نکند.
        // add() اتمی است: اگر کلید باشد false می‌دهد.
        if (! cache()->add('smsbridge:nonce:'.hash('sha256', $nonce), 1, now()->addSeconds(self::MAX_SKEW * 3))) {
            return $this->deny('nonce_replayed', 409);
        }

        return null;
    }
}
