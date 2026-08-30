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
     * پیام با **دکمه‌های شیشه‌ای** (inline keyboard).
     *
     * ⚠️ متدِ جداست و `call()` را دست نمی‌زند: آن متد `bool` برمی‌گرداند و
     * `sendInvoice`/`answerPreCheckout` — که روی مسیرِ زندهٔ پرداخت‌اند — رویش
     * سوارند. عوض‌کردنِ امضایش یعنی ریسک روی پولِ مشتری برای یک قابلیتِ
     * مدیریتی.
     *
     * ⚠️ `callback_data` سقفِ ۶۴ بایت دارد (مثلِ تلگرام). پس هرگز متن یا شناسهٔ
     * بلند داخلش نگذار — فقط فعل و یک عدد.
     *
     * @param  array<int,array<int,array{text:string,data:string}>>  $rows
     * @return int|null  message_id در صورتِ موفقیت (برای ویرایشِ بعدی)
     */
    public function sendButtons(string $chatId, string $text, array $rows): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        $keyboard = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($row as $btn) {
                $line[] = [
                    'text'          => (string) $btn['text'],
                    'callback_data' => mb_substr((string) $btn['data'], 0, 64),
                ];
            }

            if ($line !== []) {
                $keyboard[] = $line;
            }
        }

        try {
            $res = Http::timeout(12)->asJson()->post(
                rtrim($this->base, '/').'/bot'.$this->token.'/sendMessage',
                [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'reply_markup' => ['inline_keyboard' => $keyboard],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('بله (دکمهٔ شیشه‌ای) در دسترس نبود', ['error' => $e->getMessage()]);

            return null;
        }

        if ($res->json('ok') !== true) {
            Log::warning('بله دکمهٔ شیشه‌ای را رد کرد', [
                'http' => $res->status(),
                'desc' => $res->json('description') ?: mb_substr($res->body(), 0, 150),
            ]);

            return null;
        }

        return (int) $res->json('result.message_id') ?: null;
    }

    /**
     * ارسالِ یک فایل (مدرکِ KYC و مانندش) به چت — multipart، مثلِ تلگرام.
     *
     * ⚠️ فقط برای چتِ **متصلِ مدیر** استفاده شود: محتوای این فایل‌ها مدرکِ
     * هویتیِ مشتری است و فرستادنش به هر مقصدِ دیگری نشتِ داده است.
     * عکس‌ها هم عمداً با sendDocument می‌روند نه sendPhoto — فشرده‌سازیِ
     * عکسِ پاسپورت یعنی مدرکی که ریزچاپش ناخواناست.
     */
    public function sendDocument(string $chatId, string $absolutePath, string $caption = ''): bool
    {
        if (! $this->enabled() || ! is_file($absolutePath)) {
            return false;
        }

        try {
            $res = Http::timeout(30)
                ->attach('document', file_get_contents($absolutePath), basename($absolutePath))
                ->post(rtrim($this->base, '/').'/bot'.$this->token.'/sendDocument', array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption !== '' ? mb_substr($caption, 0, 190) : null,
                ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::warning('بله (sendDocument) در دسترس نبود', ['error' => $e->getMessage()]);

            return false;
        }

        if ($res->json('ok') === true) {
            return true;
        }

        Log::warning('بله سند را رد کرد', [
            'http' => $res->status(),
            'desc' => $res->json('description') ?: mb_substr($res->body(), 0, 150),
        ]);

        return false;
    }

    /**
     * پاسخ به کلیکِ دکمه — بی‌این، دکمه در کلاینتِ کاربر تا ابد «در حالِ
     * بارگذاری» می‌مانَد و کاربر فکر می‌کند هنگ کرده.
     *
     * ⚠️ باید سریع باشد؛ متنِ بلند جایش این‌جا نیست (پیامِ جدا بفرست).
     */
    public function answerCallback(string $queryId, string $text = '', bool $alert = false): bool
    {
        return $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $queryId,
            'text'              => $text !== '' ? mb_substr($text, 0, 190) : null,
            'show_alert'        => $alert ?: null,
        ], fn ($v) => $v !== null));
    }

    /** ویرایشِ متنِ یک پیامِ فرستاده‌شده — برای بستنِ دکمه‌ها پس از کلیک */
    public function editText(string $chatId, int $messageId, string $text): bool
    {
        return $this->call('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
        ]);
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
