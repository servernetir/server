<?php

namespace App\Services\Identity;

/**
 * سرویس احراز هویت ایرانی (شاهکار، استعلام هویت، تطبیق کارت بانکی).
 *
 * پیاده‌سازی فعلی: زحل (zohal.io). قرارداد عمداً از ارائه‌دهنده مستقل است تا
 * اگر فردا سرویس عوض شد، فقط یک کلاس جایگزین شود.
 */
interface IdentityProvider
{
    public function enabled(): bool;

    /**
     * شاهکار: آیا این شمارهٔ موبایل به این کد ملی تعلق دارد؟
     * این اولین دروازهٔ ثبت‌نام است — بدون آن جلو نمی‌رویم.
     */
    public function shahkar(string $nationalId, string $mobile): ShahkarResult;

    /**
     * استعلام هویت: از کد ملی و تاریخ تولد، نام و نام خانوادگی رسمی را برمی‌گرداند.
     * عمداً از کاربر نام نمی‌پرسیم — نامی که ثبت احوال می‌دهد حرف آخر است.
     */
    public function identity(string $nationalId, string $birthDate): IdentityResult;

    /**
     * تطبیق کارت بانکی با صاحب حساب، و در صورت تطابق، گرفتن شماره حساب و شبا.
     * اگر کارت به نام کاربر نباشد، هرگز ذخیره نمی‌شود.
     */
    public function cardOwner(string $cardNumber): CardResult;
}

final readonly class ShahkarResult
{
    public function __construct(
        public bool $matched,
        public ?string $error = null,
        /** آیا خطا از سمت ما بود یا سرویس در دسترس نبود */
        public bool $serviceDown = false,
    ) {}
}

final readonly class IdentityResult
{
    public function __construct(
        public bool $ok,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $fatherName = null,
        public ?bool $alive = null,
        public ?string $error = null,
        public bool $serviceDown = false,
    ) {}

    public function fullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }
}

final readonly class CardResult
{
    public function __construct(
        public bool $ok,
        /** نام صاحب کارت طبق بانک */
        public ?string $ownerName = null,
        public ?string $bankName = null,
        public ?string $accountNumber = null,
        public ?string $iban = null,          // شبا
        public ?string $error = null,
        public bool $serviceDown = false,
    ) {}
}
