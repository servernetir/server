<?php

namespace App\Services\Payment\SnappPay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * لایهٔ خامِ HTTP اسنپ‌پی — هیچ تصمیمِ کسب‌وکاری این‌جا نیست.
 *
 * ═══ واحدِ مبلغ: مهم‌ترین نکتهٔ این فایل ═══
 *
 * قیمت‌های ما تومان‌اند. **تمام** APIهای اسنپ‌پی ریال می‌گیرند (مستندات:
 * "Currency: IRR" روی amount، discountAmount، externalSourceAmount،
 * shippingAmount، taxAmount، totalAmount و amountِ هر آیتم).
 *
 * تبدیل فقط این‌جاست، در `toRial()`، و هیچ‌جای دیگرِ برنامه حق ندارد در ۱۰
 * ضرب کند — همان قاعده‌ای که در ZarinPalGateway نوشته شد چون اشتباهِ ضربدر ۱۰
 * یک بار در این پروژه رخ داده.
 *
 * ═══ قالبِ پاسخ ═══
 *
 * موفق:   {"successful": true,  "response": {...}}
 * ناموفق: {"successful": false, "errorData": {"errorCode": 400, "message": "...", "data": null}}
 *
 * پس `HTTP 200` به‌تنهایی یعنی هیچ. `successful` تنها چیزی است که حکم می‌دهد.
 *
 * ═══ توکن ═══
 *
 * توکنِ JWT سه‌هزار و ششصد ثانیه اعتبار دارد. کش می‌شود ولی با حاشیهٔ امن —
 * مستندات هشدار می‌دهد توکنِ منقضی در کش، خطای دسترسی می‌دهد و آن خطا شبیه
 * «آی‌پی وایت نیست» به نظر می‌رسد؛ یعنی ساعت‌ها دنبالِ مشکلِ اشتباه می‌گردید.
 */
class SnappPayClient
{
    /** حاشیهٔ امنِ کشِ توکن: زودتر از انقضا دور انداخته می‌شود */
    private const TOKEN_SAFETY_MARGIN = 120;

    private const TOKEN_CACHE_KEY = 'snapppay:access_token';

    public function configured(): bool
    {
        return filled(config('snapppay.base_url'))
            && filled(config('snapppay.client_id'))
            && filled(config('snapppay.client_secret'))
            && filled(config('snapppay.username'))
            && filled(config('snapppay.password'));
    }

    // ─────────────────────────── تبدیل واحد ───────────────────────────

    /**
     * تومان → ریال. تنها جای برنامه که این ضرب برای اسنپ‌پی انجام می‌شود.
     */
    public function toRial(int $toman): int
    {
        return $toman * 10;
    }

    /**
     * ریال → تومان، برای عددهایی که اسنپ‌پی برمی‌گرداند (مبلغِ کالبک، status).
     *
     * رو به بالا گرد می‌شود: مبلغِ کم‌برآوردشده یعنی فاکتوری که فکر می‌کنیم
     * کمتر پرداخت شده و بی‌دلیل «ناتمام» می‌مانَد.
     */
    public function toToman(int $rial): int
    {
        return $rial > 0 ? intdiv($rial + 9, 10) : 0;
    }

    // ──────────────────────────── احراز هویت ────────────────────────────

    /**
     * توکنِ دسترسی — از کش، وگرنه تازه.
     *
     * ⚠️ `Cache::get` عمداً بدونِ remember: اگر گرفتنِ توکن شکست بخورد نباید
     * `null` در کش بنشیند و درخواستِ بعدی را هم بی‌صدا بشکند.
     */
    public function accessToken(bool $fresh = false): ?string
    {
        if (! $fresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        if (! $this->configured()) {
            return null;
        }

        $basic = base64_encode(config('snapppay.client_id').':'.config('snapppay.client_secret'));

        try {
            $res = Http::withHeaders(['Authorization' => 'Basic '.$basic])
                ->asForm()
                ->timeout((int) config('snapppay.timeout', 20))
                ->post($this->url('/api/online/v1/oauth/token'), [
                    'grant_type' => 'password',
                    'scope'      => 'online-merchant',
                    'username'   => config('snapppay.username'),
                    'password'   => config('snapppay.password'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('اسنپ‌پی در دسترس نبود (توکن)', ['error' => $e->getMessage()]);

            return null;
        }

        $token = (string) data_get($res->json(), 'access_token');

        if ($token === '') {
            /*
            | 🔴 «Access Denied» این‌جا تقریباً همیشه یعنی آی‌پیِ سرور وایت
            | نشده، و «Unauthorized» یعنی client_id/secret غلط انکد شده.
            | نوشتنِ کدِ وضعیت در لاگ، همان تفکیکی است که ساعت‌ها وقت را
            | نجات می‌دهد (بخشِ سوالاتِ متداولِ مستندات).
            */
            Log::warning('اسنپ‌پی توکن نداد', [
                'status' => $res->status(),
                'body'   => mb_substr((string) $res->body(), 0, 300),
            ]);

            return null;
        }

        $ttl = max(60, (int) data_get($res->json(), 'expires_in', 3600) - self::TOKEN_SAFETY_MARGIN);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    // ───────────────────────────── سرویس‌ها ─────────────────────────────

    /**
     * آیا این مبلغ برای این کاربر اقساطی است؟
     *
     * 🔴 مستندات صریح است: عنوان و توضیحِ برگشتی باید **بدونِ تغییر** نمایش
     * داده شود و هیچ محاسبهٔ دستی سمتِ ما مجاز نیست. با هر تغییرِ مبلغ هم
     * باید دوباره صدا زده شود، چون اقساط را اسنپ‌پی داینامیک حساب می‌کند.
     *
     * @return array{eligible:bool,title:?string,description:?string}
     */
    public function eligible(int $amountToman): array
    {
        $json = $this->send('GET', '/api/online/offer/v1/eligible', [
            'amount' => $this->toRial($amountToman),
        ]);

        // شکستِ تماس یعنی «نمی‌دانیم»، و «نمی‌دانیم» باید مثلِ «نه» رفتار کند:
        // نمایشِ درگاهی که بعداً رد می‌شود، بدتر از ندیدنش است.
        return [
            'eligible'    => (bool) data_get($json, 'response.eligible', false),
            'title'       => data_get($json, 'response.title_message'),
            'description' => data_get($json, 'response.description'),
        ];
    }

    /**
     * توکنِ پرداخت + آدرسِ صفحهٔ پرداخت.
     *
     * @param  array<string,mixed>  $payload  سبد، ساخته‌شده در SnappPayCart
     * @return array{token:?string,url:?string,error:?string}
     */
    public function paymentToken(array $payload): array
    {
        $json = $this->send('POST', '/api/online/payment/v1/token', body: $payload);

        return [
            'token' => data_get($json, 'response.paymentToken'),
            'url'   => data_get($json, 'response.paymentPageUrl'),
            'error' => $this->errorOf($json),
        ];
    }

    /** تأیید تراکنش. مستندات: فقط **یک بار**، حتی اگر کالبک چند بار آمد. */
    public function verify(string $paymentToken): array
    {
        return $this->tokenCall('/api/online/payment/v1/verify', $paymentToken,
            timeout: (int) config('snapppay.verify_timeout', 30));
    }

    /** نهایی‌سازی. بعد از verify اجباری است. */
    public function settle(string $paymentToken): array
    {
        return $this->tokenCall('/api/online/payment/v1/settle', $paymentToken);
    }

    /** لغوِ کاملِ سفارشِ settle‌شده — برگشت‌ناپذیر. */
    public function cancel(string $paymentToken): array
    {
        return $this->tokenCall('/api/online/payment/v1/cancel', $paymentToken);
    }

    /**
     * کاهشِ مبلغ/آیتم‌های سفارشِ settle‌شده — برگشت‌ناپذیر.
     *
     * ⚠️ مبلغِ تازه باید **کمتر** از مبلغِ سفارش باشد، و آیتمی که کاملاً حذف
     * می‌شود باید از cartItems هم برداشته شود نه اینکه count صفر بگیرد.
     */
    public function update(array $payload): array
    {
        $json = $this->send('POST', '/api/online/payment/v1/update', body: $payload);

        return $this->outcome($json);
    }

    /**
     * وضعیتِ سفارش نزدِ اسنپ‌پی: SETTLE | CANCEL | VERIFY | PENDING | REVERT.
     *
     * مستندات این را برای رفعِ مغایرت اجباری کرده: هرجا verify یا settle
     * پاسخ نداد، حکم از این‌جا گرفته می‌شود نه از حدس.
     *
     * @return array{ok:bool,status:?string,transactionId:?string,amountToman:?int,error:?string}
     */
    public function status(string $paymentToken): array
    {
        $json = $this->send('GET', '/api/online/payment/v1/status', [
            'paymentToken' => $paymentToken,
        ]);

        $rial = data_get($json, 'response.amount');

        return [
            'ok'            => (bool) data_get($json, 'successful', false),
            'status'        => data_get($json, 'response.status'),
            'transactionId' => data_get($json, 'response.transactionId'),
            'amountToman'   => $rial === null ? null : $this->toToman((int) $rial),
            'error'         => $this->errorOf($json),
        ];
    }

    // ───────────────────────────── درونی ─────────────────────────────

    /** @return array{ok:bool,transactionId:?string,error:?string} */
    private function tokenCall(string $path, string $paymentToken, ?int $timeout = null): array
    {
        $json = $this->send('POST', $path, body: ['paymentToken' => $paymentToken], timeout: $timeout);

        return $this->outcome($json);
    }

    /** @return array{ok:bool,transactionId:?string,error:?string} */
    private function outcome(?array $json): array
    {
        return [
            'ok'            => (bool) data_get($json, 'successful', false),
            'transactionId' => data_get($json, 'response.transactionId'),
            'error'         => $this->errorOf($json),
        ];
    }

    /**
     * پیامِ خطا — یا `null` وقتی خطایی نیست.
     *
     * ⚠️ `$json === null` یعنی تماس اصلاً برقرار نشد (تایم‌اوت/شبکه). این با
     * «اسنپ‌پی گفت نه» فرق دارد و صداکننده باید بتواند تفکیکشان کند، چون در
     * حالتِ اول باید سراغِ getPaymentStatus برود نه اینکه سفارش را ناموفق کند.
     */
    private function errorOf(?array $json): ?string
    {
        if ($json === null) {
            return 'no-response';
        }

        if (data_get($json, 'successful') === true) {
            return null;
        }

        return (string) (data_get($json, 'errorData.message')
            ?: data_get($json, 'errorData.errorCode')
            ?: 'unknown');
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>|null  null یعنی پاسخی نیامد
     */
    private function send(string $method, string $path, array $query = [], array $body = [], ?int $timeout = null): ?array
    {
        $token = $this->accessToken();

        if ($token === null) {
            return null;
        }

        $attempt = function (string $bearer) use ($method, $path, $query, $body, $timeout) {
            $req = Http::withHeaders([
                'Authorization' => 'Bearer '.$bearer,
                'Accept'        => 'application/json',
            ])->timeout($timeout ?? (int) config('snapppay.timeout', 20));

            return $method === 'GET'
                ? $req->get($this->url($path), $query)
                : $req->asJson()->post($this->url($path), $body);
        };

        try {
            $res = $attempt($token);

            /*
            | 🔴 توکنِ کش‌شده می‌تواند پیش از انقضای محاسبه‌شده باطل شود (ری‌استارتِ
            | سمتِ اسنپ‌پی، تغییرِ اعتبار). یک بار — و فقط یک بار — با توکنِ تازه
            | دوباره تلاش می‌کنیم. بی‌این، یک ۴۰۱ِ گذرا کلِ درگاه را تا انقضای
            | کش می‌خواباند.
            */
            if ($res->status() === 401) {
                $this->forgetToken();
                $fresh = $this->accessToken(fresh: true);

                if ($fresh !== null) {
                    $res = $attempt($fresh);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('اسنپ‌پی پاسخ نداد', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        $json = $res->json();

        if (! is_array($json)) {
            Log::warning('اسنپ‌پی پاسخِ غیرِ JSON داد', [
                'path' => $path, 'status' => $res->status(),
                'body' => mb_substr((string) $res->body(), 0, 300),
            ]);

            return null;
        }

        if (data_get($json, 'successful') !== true) {
            Log::warning('اسنپ‌پی درخواست را رد کرد', [
                'path'   => $path,
                'status' => $res->status(),
                'code'   => data_get($json, 'errorData.errorCode'),
                'msg'    => data_get($json, 'errorData.message'),
            ]);
        }

        return $json;
    }

    private function url(string $path): string
    {
        return config('snapppay.base_url').$path;
    }
}
