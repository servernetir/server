<?php

namespace App\Support;

/**
 * ساختِ جفت‌کلیدِ WireGuard (منحنیِ X25519، خروجی base64 استاندارد).
 *
 * چرا sodium و نه فراخوانیِ `wg genkey`: باینریِ `wg` روی هاستِ اشتراکی نیست و
 * `exec` هم بسته است. libsodium داخلِ PHP همان عملیات را انجام می‌دهد —
 * `crypto_scalarmult_base` دقیقاً همان clampingِ RFC 7748 را می‌کند که خودِ
 * WireGuard می‌کند، پس کلیدِ عمومیِ تولیدشده با آنچه روتر محاسبه می‌کند یکی است.
 */
class WireGuardKey
{
    /** @return array{private:string,public:string} */
    public static function generate(): array
    {
        $private = random_bytes(32);

        return [
            'private' => base64_encode($private),
            'public' => base64_encode(sodium_crypto_scalarmult_base($private)),
        ];
    }

    /** کلیدِ عمومیِ متناظرِ یک کلیدِ خصوصیِ base64 — برای راستی‌آزمایی. */
    public static function publicFrom(string $privateBase64): ?string
    {
        $raw = base64_decode(trim($privateBase64), true);

        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }

        return base64_encode(sodium_crypto_scalarmult_base($raw));
    }

    /** آیا رشته شکلِ یک کلیدِ WireGuard را دارد (۳۲ بایت در base64)؟ */
    public static function looksValid(string $key): bool
    {
        $raw = base64_decode(trim($key), true);

        return $raw !== false && strlen($raw) === 32;
    }
}
