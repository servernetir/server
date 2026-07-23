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
        $message = $update['message'] ?? [];
        $chatId  = (string) ($message['chat']['id'] ?? $message['from']['id'] ?? '');

        if ($chatId === '') {
            return response()->json(['ok' => true]);
        }

        // کاربر شماره‌اش را share کرد → ثبت نگاشت
        if (isset($message['contact']['phone_number'])) {
            $this->link($message, $chatId, $sender);

            return response()->json(['ok' => true]);
        }

        // /start یا هر پیام دیگر → دکمهٔ اشتراک شماره را نشان بده
        $text = (string) ($message['text'] ?? '');
        $this->promptForContact($sender, $chatId, str_starts_with($text, '/start'));

        return response()->json(['ok' => true]);
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
