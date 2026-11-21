<?php

namespace App\Services\Customer;

use App\Models\Customer;

/** Purchase authorization for domestic infrastructure requires Shahkar verification. */
class IranSalesGate
{
    public const SETTING = 'iran_sales_open_to_unverified';

    /** The legacy setting cannot override mandatory verification. */
    public static function openToUnverified(): bool
    {
        return false;
    }

    /** آیا برای این مشتری، کلِ محصولاتِ ایران بسته است؟ */
    public static function blocksIranFor(?Customer $customer): bool
    {
        if ($customer === null) {
            return true;
        }
        try {
            return ! $customer->isVerified()
                || ! $customer->identityVerification()
                    ->where('status', 'verified')
                    ->where('shahkar_matched', true)
                    ->exists();
        } catch (\Throwable) {
            return true;
        }
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

    public static function message(): string
    {
        return __('ui.iran_gate_blocked');
    }
}
