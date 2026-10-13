<?php

namespace App\Services\Payment\SnappPay;

/**
 * اسنپ‌پی یک تغییرِ ادمین را نپذیرفت.
 *
 * عمداً یک استثنا است نه یک مقدارِ بازگشتی: پرتاب‌شدنش از داخلِ
 * `DB::transaction` کاهشِ فاکتور را **برمی‌گرداند**، که دقیقاً همان رفتاری
 * است که لازم داریم — فاکتورِ ما هرگز کم‌شده نمی‌ماند وقتی بدهیِ مشتری نزدِ
 * اسنپ‌پی دست‌نخورده است.
 */
class SnappPayRejected extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?array $body = null,
        public readonly ?string $remoteError = null,
    ) {
        parent::__construct($message);
    }
}
