<?php

namespace App\Services\Bale;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ارسال پیام بله از سرور آلمان — مستقیم.
 *
 * بله زیرساخت ایرانی است ولی سنجش نشان داد از آلمان قابل دسترسی است (برخلاف
 * آی‌پی‌پنل). پس مسیر اصلی همین است؛ اگر روزی از آلمان نشد، صف ایران fallback
 * می‌شود (در BaleNotifier).
 *
 * API مثل تلگرام است: POST /bot<token>/sendMessage با chat_id و text.
 */
class BaleSender
{
    public function __construct(
        private ?string $token,
        private string $base = 'https://tapi.bale.ai',
    ) {}

    public function enabled(): bool
    {
        return filled($this->token);
    }

    /** true = تحویل شد؛ false = نشد (شبکه، توکن، یا chat_id نامعتبر) */
    public function send(string $chatId, string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $res = Http::timeout(12)->asJson()->post(
                rtrim($this->base, '/').'/bot'.$this->token.'/sendMessage',
                ['chat_id' => $chatId, 'text' => $text],
            );
        } catch (\Throwable $e) {
            Log::warning('بله در دسترس نبود', ['error' => $e->getMessage()]);

            return false;
        }

        // بله مثل تلگرام: {"ok":true,...} یا {"ok":false,"description":...}
        if (($res->json('ok')) === true) {
            return true;
        }

        Log::warning('بله پیام را رد کرد', [
            'http' => $res->status(),
            'desc' => $res->json('description') ?: mb_substr($res->body(), 0, 150),
        ]);

        return false;
    }

    /**
     * فرستادن فاکتور پرداخت به چت بلهٔ کاربر.
     *
     * ⚠ amount بر حسب **ریال** است (مثل زرین‌پال). قیمت‌های ما تومان‌اند، پس
     * تبدیل بیرون از این متد و در BaleGateway انجام می‌شود — همان‌جا که
     * تبدیل زرین‌پال هم هست.
     *
     * payload کلید تطبیق است: در PreCheckoutQuery و SuccessfulPayment
     * برمی‌گردد، پس همان external_ref پرداخت را می‌گذاریم تا بتوانیم دقیقاً
     * پیدایش کنیم.
     *
     * @return bool آیا فاکتور به چت کاربر تحویل شد
     */
    public function sendInvoice(
        string $chatId,
        string $title,
        string $description,
        string $payload,
        string $providerToken,
        int $amountRial,
        string $priceLabel,
    ): bool {
        if (! $this->enabled()) {
            return false;
        }

        return $this->call('sendInvoice', [
            'chat_id'        => $chatId,
            'title'          => mb_substr($title, 0, 32),
            'description'    => mb_substr($description, 0, 255),
            'payload'        => $payload,
            'provider_token' => $providerToken,
            'prices'         => [['label' => mb_substr($priceLabel, 0, 32), 'amount' => $amountRial]],
        ]);
    }

    /**
     * پاسخ به PreCheckoutQuery — باید ظرف ۱۰ ثانیه باشد وگرنه پرداخت لغو
     * می‌شود. ok=false یعنی رد کن (مثلاً مبلغ نمی‌خواند).
     */
    public function answerPreCheckout(string $queryId, bool $ok, ?string $errorMessage = null): bool
    {
        return $this->call('answerPreCheckoutQuery', array_filter([
            'pre_checkout_query_id' => $queryId,
            'ok'            => $ok,
            'error_message' => $ok ? null : ($errorMessage ?? 'پرداخت قابل انجام نیست'),
        ], fn ($v) => $v !== null));
    }

    /** فراخوانی عمومی یک متد ربات؛ true اگر ok:true برگشت */
    private function call(string $method, array $body): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $res = Http::timeout(12)->asJson()->post(
                rtrim($this->base, '/').'/bot'.$this->token.'/'.$method,
                $body,
            );
        } catch (\Throwable $e) {
            Log::warning("بله ({$method}) در دسترس نبود", ['error' => $e->getMessage()]);

            return false;
        }

        if ($res->json('ok') === true) {
            return true;
        }

        Log::warning("بله ({$method}) رد کرد", [
            'http' => $res->status(),
            'desc' => $res->json('description') ?: mb_substr($res->body(), 0, 150),
        ]);

        return false;
    }

    /**
     * پیام با دکمهٔ «اشتراک شماره».
     *
     * ربات فقط وقتی می‌تواند به کاربر پیام دهد که chat_id داشته باشد، و
     * chat_id را از همین دکمه می‌گیریم: کاربر می‌زند، بله شماره + from.id را
     * در update می‌فرستد. reply_markup مثل تلگرام است.
     */
    public function sendWithContactButton(string $chatId, string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $res = Http::timeout(12)->asJson()->post(
                rtrim($this->base, '/').'/bot'.$this->token.'/sendMessage',
                [
                    'chat_id' => $chatId,
                    'text'    => $text,
                    'reply_markup' => [
                        'keyboard' => [[
                            ['text' => '📱 اشتراک شمارهٔ من', 'request_contact' => true],
                        ]],
                        'resize_keyboard'   => true,
                        'one_time_keyboard' => true,
                    ],
                ],
            );

            return $res->json('ok') === true;
        } catch (\Throwable $e) {
            Log::warning('بله (دکمهٔ تماس) در دسترس نبود', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
