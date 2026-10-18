<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Server;

/**
 * خرید مقصد ایران فقط با نتیجهٔ ثبت‌شدهٔ شاهکار مجاز است: status=verified
 * و shahkar_matched=true. تأیید مدارک، پروفایل، زبان و تابعیت جای آن نیستند.
 * پرس‌وجوی تازه مانع اتکا به رابطهٔ بارگذاری‌شده پس از لغو تأیید است.
 * این قاعده فقط خرید جدید را محدود می‌کند؛ KYC عمومی و خدمات موجود مستقل‌اند.
 */
class IranSalesGate
{
    public const SETTING = 'iran_sales_open_to_unverified';

    /** @deprecated تنظیم قدیمی مجوز دورزدن الزام شاهکار نیست. */
    public static function openToUnverified(): bool
    {
        return false;
    }

    /** آیا برای این مشتری، کلِ محصولاتِ ایران بسته است؟ */
    public static function blocksIranFor(?Customer $customer): bool
    {
        return $customer === null || ! $customer->identityVerification()
            ->where('status', 'verified')->where('shahkar_matched', true)->exists();
    }

    /** آیا این سفارشِ مشخص (با این مقصد) باید رد شود؟ */
    public static function blocks(?Customer $customer, ?string $target): bool
    {
        return self::isIranTarget($target) && self::blocksIranFor($customer);
    }

    /** «IR» (کشورِ هاست) یا «ir-…» (مکانِ ابری) */
    public static function isIranTarget(?string $target): bool
    {
        $t = strtolower(trim((string) $target));

        return $t === 'ir' || str_starts_with($t, 'ir-');
    }

    /**
     * مقصد واقعی سفارش بر محل پیش‌فرض محصول مقدم است. در فهرست خرید، وجود
     * یک مقصد خارجی مجاز، محصول چندمکانه را قابل انتخاب نگه می‌دارد.
     */
    public static function blocksProduct(?Customer $customer, Product $product, ?string $selectedCountry = null, ?Server $resolvedServer = null): bool
    {
        if ($product->isLicense() || ! self::blocksIranFor($customer)) {
            return false;
        }
        if (filled($selectedCountry) || $resolvedServer !== null) {
            return self::isIranTarget($selectedCountry) || self::isIranTarget($resolvedServer?->country);
        }
        $countries = $product->availableCountries();
        if ($countries !== []) {
            return collect($countries)->every(fn ($country) => self::isIranTarget($country));
        }
        if (filled($product->server?->country)) {
            return self::isIranTarget($product->server->country);
        }

        return collect((array) $product->locations)->contains(fn ($country) => self::isIranTarget($country));
    }

    public static function message(): string
    {
        return __('iran-sales.required');
    }
}
