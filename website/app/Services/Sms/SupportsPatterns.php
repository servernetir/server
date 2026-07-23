<?php

namespace App\Services\Sms;

/**
 * درایورهایی که «الگو» دارند.
 *
 * جدا از SmsSender است چون همهٔ ارائه‌دهنده‌ها الگو ندارند و نباید مجبور
 * شوند متدی را پیاده کنند که برایشان بی‌معنی است. کد بالادست با
 * instanceof بررسی می‌کند و اگر نبود، به پیام آزاد برمی‌گردد.
 */
interface SupportsPatterns
{
    /**
     * null = الگویی برای این رویداد تعریف نشده (برو سراغ پیام آزاد)
     * false = تلاش شد و نشد (خطا را گزارش کن، به پیام آزاد برنگرد —
     *         وگرنه یک پیام ناموفق دو بار هزینه می‌شود)
     *
     * @param  array<string,string|int>  $values
     */
    public function sendPattern(string $mobile, string $event, array $values): ?bool;

    public function hasPattern(string $event): bool;
}
