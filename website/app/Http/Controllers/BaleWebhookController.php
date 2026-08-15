<?php

namespace App\Http\Controllers;

use App\Models\BaleContact;
use App\Services\Bale\BaleSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * وب‌هوک بله — این‌جا chat_id کاربر را به دست می‌آوریم.
 *
 * ═══ جریان اتصال ═══
 *
 *   ۱) کاربر ربات را استارت می‌کند → بله یک update با /start می‌فرستد
 *   ۲) ما دکمه‌ای می‌فرستیم: «اشتراک شماره» (request_contact)
 *   ۳) کاربر می‌زند → بله update با contact.phone_number + from.id می‌دهد
 *   ۴) این را در bale_contacts ذخیره می‌کنیم (شماره → chat_id)
 *
 * از این به بعد هر پیامکِ آن شماره، در بله هم می‌رود.
 *
 * ═══ امنیت ═══
 *
 * آدرس وب‌هوک با یک توکن مخفی در مسیر محافظت می‌شود (بله خودش امضا ندارد).
 * پس فقط کسی که توکن را می‌داند می‌تواند update جعل کند — و توکن فقط به بله
 * داده شده. مسیر در robots هم مسدود است.
 */
class BaleWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, BaleSender $sender): JsonResponse
    {
        // توکن وب‌هوک = هش توکن ربات، تا رشتهٔ جدا لازم نباشد و قابل حدس نباشد
        $expected = substr(hash('sha256', (string) config('services.bale.token')), 0, 32);

        if (! hash_equals($expected, $token)) {
            return response()->json(['ok' => false], 404);
        }

        if (! Schema::hasTable('bale_contacts')) {
            return response()->json(['ok' => true]);
        }

        $update = $request->all();

        // ── پرداخت: تأیید پیش از تسویه (باید ظرف ۱۰ ثانیه پاسخ داده شود) ──
        if (isset($update['pre_checkout_query'])) {
            $this->preCheckout($update['pre_checkout_query'], $sender);

            return response()->json(['ok' => true]);
        }

        $message = $update['message'] ?? [];
        $chatId  = (string) ($message['chat']['id'] ?? $message['from']['id'] ?? '');

        // ── پرداخت: تسویهٔ نهایی ──
        if (isset($message['successful_payment'])) {
            $this->successfulPayment($message['successful_payment']);

            return response()->json(['ok' => true]);
        }

        if ($chatId === '') {
            return response()->json(['ok' => true]);
        }

        // کاربر شماره‌اش را share کرد → ثبت نگاشت
        if (isset($message['contact']['phone_number'])) {
            $this->link($message, $chatId, $sender);

            return response()->json(['ok' => true]);
        }

        /*
        | ── کنسولِ مدیر ── فقط چتِ متصل‌شده، فقط فرمانِ شناخته‌شده.
        |
        | جایگاهش عمدی است: **بعد از** هر چهار شاخهٔ مشتریِ بالا. یعنی نه مهلتِ
        | ۱۰ ثانیه‌ایِ `pre_checkout_query` را لمس می‌کند، نه `successful_payment`
        | را (کارفرما ممکن است با کیفِ بلهٔ خودش پول بدهد و آن آپدیت `from.id`ِ
        | برابرِ چتِ متصل دارد)، و نه زنجیرهٔ «اشتراکِ شماره»ی مشتری را.
        |
        | 🔴 کلِ بلوک — **شاملِ ساختِ خودِ سرویس** — در try/catch است. یک استثنا
        | این‌جا یعنی وب‌هوک ۵۰۰ می‌دهد و بله همان آپدیت را دوباره می‌فرستد؛ و
        | چون دپلوی این پروژه فایل‌به‌فایل است، «یک فایل جا ماند» یک حالتِ واقعی
        | است نه فرضی. آن‌وقت `/start`ِ مشتریِ تازه هم بی‌پاسخ می‌مانْد و او
        | هرگز به بله وصل نمی‌شد — با کدِ ۵۰۰ که هیچ‌کس نمی‌بیندش.
        */
        try {
            $console = app(\App\Services\Bale\Admin\AdminBaleRouter::class);

            if ($console->matches($update)) {
                $console->handle($update);

                return response()->json(['ok' => true]);
            }
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::noteOnce('bale-admin', $e, 3600);
        }

        // /start یا هر پیام دیگر → دکمهٔ اشتراک شماره را نشان بده
        $text = (string) ($message['text'] ?? '');
        $this->promptForContact($sender, $chatId, str_starts_with($text, '/start'));

        return response()->json(['ok' => true]);
    }

    /**
     * PreCheckoutQuery — بله قبل از برداشت پول می‌پرسد «تأیید می‌کنی؟».
     *
     * باید مبلغ و payload را با پرداختِ منتظر بسنجیم. اگر نخواند، رد می‌کنیم
     * تا پول برداشته نشود. این تنها فرصت است؛ بعد از تأیید، پول رفته.
     */
    private function preCheckout(array $query, BaleSender $sender): void
    {
        $queryId = (string) ($query['id'] ?? '');
        $payload = (string) ($query['invoice_payload'] ?? '');
        $amount  = (int) ($query['total_amount'] ?? 0);

        if ($queryId === '') {
            return;
        }

        $payment = $this->paymentFromPayload($payload);

        if ($payment === null || ! $payment->isVerifiable()) {
            $sender->answerPreCheckout($queryId, false, 'این پرداخت معتبر یا فعال نیست.');

            return;
        }

        // مبلغی که بله می‌گوید باید دقیقاً برابر مبلغ مورد انتظار ما باشد
        $registry = app(\App\Services\Payment\GatewayRegistry::class);
        $gateway  = $registry->get('bale');
        $expected = $gateway instanceof \App\Services\Payment\BaleGateway
            ? $gateway->expectedRial($payment)
            : $payment->amount * 10;

        if ($amount !== $expected) {
            $sender->answerPreCheckout($queryId, false, 'مبلغ پرداخت با فاکتور نمی‌خواند.');

            return;
        }

        $sender->answerPreCheckout($queryId, true);
    }

    /**
     * SuccessfulPayment — پول از کیف پول کاربر برداشته شده. تسویه کن.
     *
     * این خودِ تأیید است (بله قبلاً برداشته)، پس settleConfirmed بدون verify.
     * idempotent است: اگر بله رویداد را دو بار بفرستد، پرداخت دو بار تسویه
     * نمی‌شود.
     */
    private function successfulPayment(array $sp): void
    {
        $payment = $this->paymentFromPayload((string) ($sp['invoice_payload'] ?? ''));

        if ($payment === null) {
            \Illuminate\Support\Facades\Log::warning('پرداخت موفق بله با payload ناشناخته', ['sp' => $sp]);

            return;
        }

        $refId = (string) ($sp['provider_payment_charge_id']
            ?? $sp['telegram_payment_charge_id']
            ?? '') ?: null;

        app(\App\Services\Payment\PaymentService::class)->settleConfirmed($payment, $refId);
    }

    /** پرداخت را از روی payload پیدا کن — payload برابر external_ref است */
    private function paymentFromPayload(string $payload): ?\App\Models\Payment
    {
        if ($payload === '') {
            return null;
        }

        return \App\Models\Payment::where('gateway', 'bale')
            ->where('external_ref', $payload)
            ->first();
    }

    /** ذخیرهٔ نگاشت شماره → chat_id و پیام تأیید */
    private function link(array $message, string $chatId, BaleSender $sender): void
    {
        $mobile = $this->normalize((string) $message['contact']['phone_number']);

        if ($mobile === '') {
            return;
        }

        $name = trim(($message['contact']['first_name'] ?? '').' '.($message['contact']['last_name'] ?? ''));

        BaleContact::updateOrCreate(
            ['mobile' => $mobile],
            ['chat_id' => $chatId, 'name' => $name ?: null, 'linked_at' => now()],
        );

        $sender->send($chatId,
            "✅ شمارهٔ شما با موفقیت به سرورنت متصل شد.\n"
            ."از این پس کد ورود و اطلاعیه‌های حساب شما در همین‌جا هم دریافت می‌شود.");
    }

    /** دکمهٔ «اشتراک شماره» (request_contact) */
    private function promptForContact(BaleSender $sender, string $chatId, bool $isStart): void
    {
        $greeting = $isStart
            ? "به ربات سرورنت خوش آمدید 👋\n\nبرای دریافت کد ورود و اطلاعیه‌ها در بله، شمارهٔ خود را با دکمهٔ زیر به اشتراک بگذارید."
            : "برای اتصال حساب، شمارهٔ خود را با دکمهٔ زیر به اشتراک بگذارید.";

        $sender->sendWithContactButton($chatId, $greeting);
    }

    /** موبایل بله ممکن است +98 یا 98 یا 0 داشته باشد — به 09xxxxxxxxx */
    private function normalize(string $raw): string
    {
        $d = preg_replace('/[^0-9]/', '', $raw) ?? '';

        $d = match (true) {
            str_starts_with($d, '0098') => '0'.substr($d, 4),
            str_starts_with($d, '98')   => '0'.substr($d, 2),
            str_starts_with($d, '9')    => '0'.$d,
            default                     => $d,
        };

        return preg_match('/^09\d{9}$/', $d) === 1 ? $d : '';
    }
}
