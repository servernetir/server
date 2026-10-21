<?php

namespace App\Services\Bale\Admin;

use App\Models\Setting;
use App\Services\Bale\BaleSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Separate owner ingress; never takes over the shared Bale webhook. */
class HermesBaleRelay
{
    public const URL = 'https://flow.servernet.cloud/webhook/servernet-hermes-owner';

    public function __construct(private AdminBaleGate $gate, private BaleSender $sender) {}

    public static function isCommand(string $text): bool
    {
        return preg_match('~^/hermes(?:@servernetbot)?(?:\s|$)~iu', trim($text)) === 1;
    }

    public static function isReply(array $update): bool
    {
        $reply = $update['message']['reply_to_message'] ?? [];
        return (string) ($reply['from']['id'] ?? '') === '2017652664'
            && str_starts_with((string) ($reply['text'] ?? ''), "🤖 Hermes\n")
            ;
    }

    public function handle(array $update): void
    {
        // Recheck here too: this class must remain safe outside the router.
        if (! app(AdminBaleRouter::class)->matches($update) || ! $this->gate->enabled()) {
            return;
        }
        $bind = $this->gate->binding();
        $text = trim((string) ($update['message']['text'] ?? ''));
        $isReply = ! self::isCommand($text) && self::isReply($update);
        if ($isReply) {
            $text = '/hermes '.$text;
        }
        $id = $update['update_id'] ?? null;
        if (! $bind || ! self::isCommand($text) || ! is_int($id) || $id < 0 || mb_strlen($text) > 16000) {
            return;
        }
        $owner = (string) $bind['chat_id'];
        if (! Cache::add('hermes-bale-update:'.$owner.':'.$id, 1, 86400)) {
            return;
        }
        if (Setting::get('hermes_bale_enabled') !== '1'
            || Setting::get('hermes_n8n_webhook') !== self::URL
            || ! ($secret = Setting::getSecret('hermes_n8n_secret'))) {
            $this->sender->send($owner, 'اتصال Hermes هنوز فعال نشده است؛ فرمان شناخته شد و نیاز به تکمیل اتصال دارد.');
            return;
        }
        $arg = trim((string) preg_replace('~^/hermes(?:@servernetbot)?\s*~iu', '', $text));
        try {
            $response = Http::connectTimeout(2)->timeout(5)->withHeaders([
                'X-ServerNet-Hermes-Ingress' => $secret,
            ])->asJson()->post(self::URL, [
                'bot' => 'servernetbot', 'update_id' => $id,
                'owner_chat_id' => $owner, 'owner_user_id' => (string) $bind['user_id'],
                'text' => $text, 'timestamp' => time(),
            ]);
            if (! $response->successful()) {
                $this->sender->send($owner, 'ورودی Hermes درخواست را نپذیرفت؛ وضعیت اتصال نیاز به بررسی دارد. درخواست خودکار تکرار نشد.');
            }
        } catch (\Throwable) {
            // Timeout may follow successful acceptance. Never automatically retry.
            $this->sender->send($owner, 'وصول درخواست Hermes قطعی نیست؛ خودکار تکرار نشد. پیش از ارسال دوباره وضعیت را بررسی کنید.');
        }
    }
}
