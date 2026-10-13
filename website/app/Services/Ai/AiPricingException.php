<?php

namespace App\Services\Ai;

/**
 * «این مدل الان فروختنی نیست» — با کدِ ماشینی و فهرستِ دلیل‌ها.
 *
 * کدها همان کدهای API در m5-spec §7 هستند تا M5.1b بی‌ترجمه به ۵۰۳/۴۱۳
 * برساندشان: `pricing_incomplete` · `fx_unavailable` · `request_too_large`.
 * دلیل‌ها (`reasons`) برای پیش‌نمایشِ مدیر است؛ به مشتری نشان داده نمی‌شوند
 * چون نامِ ارائه‌دهنده و ساختِ بها را لو می‌دهند.
 */
final class AiPricingException extends \RuntimeException
{
    public const PRICING_INCOMPLETE = 'pricing_incomplete';

    public const FX_UNAVAILABLE = 'fx_unavailable';

    public const TOO_LARGE = 'request_too_large';

    /** @param list<string> $reasons */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $reasons = [],
    ) {
        parent::__construct($errorCode.($reasons ? ': '.implode(' · ', $reasons) : ''));
    }
}
