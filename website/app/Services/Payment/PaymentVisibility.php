<?php

namespace App\Services\Payment;

use App\Models\PaymentAccount;
use App\Models\Setting;

/** Controls new payment choices by the active site language, never settlement. */
class PaymentVisibility
{
    public const SETTING = 'payment_visibility_by_locale';
    public const LOCALES = ['fa' => 'فارسی', 'en' => 'English', 'tr' => 'Türkçe'];
    public const METHODS = [
        'zarinpal' => 'زرین‌پال',
        'snapppay' => 'اسنپ‌پی',
        'bale' => 'بله',
        'bank_iran' => 'حساب بانکی ایران',
        'bank_foreign' => 'حواله به حساب بانکی خارجی',
        'crypto' => 'رمزارز (خودکار و رسید دستی)',
    ];

    public function matrix(): array
    {
        $raw = Setting::get(self::SETTING);
        $stored = $raw === null ? null : json_decode($raw, true);
        $matrix = [];
        foreach (self::LOCALES as $locale => $_) {
            foreach (self::METHODS as $method => $label) {
                // Only a missing setting uses defaults. Malformed saved data fails closed.
                $matrix[$locale][$method] = $raw === null
                    ? ($locale === 'fa' || in_array($method, ['bank_foreign', 'crypto'], true))
                    : (is_array($stored) && ($stored[$locale][$method] ?? false) === true);
            }
        }
        return $matrix;
    }

    public function allows(string $method, ?string $locale = null): bool
    {
        return $this->matrix()[$locale ?? app()->getLocale()][$method] ?? false;
    }

    public function accountMethod(PaymentAccount $account): string
    {
        if ($account->isCrypto()) {
            return 'crypto';
        }
        $country = mb_strtolower(trim((string) $account->country));
        $iran = in_array(strtoupper((string) $account->currency_code), ['IRT', 'IRR'], true)
            || str_starts_with(strtoupper(trim((string) $account->iban)), 'IR')
            || in_array($country, ['ir', 'irn'], true)
            || preg_match('/(?:^|[^a-z])iran(?:$|[^a-z])|ایران|ايران/u', $country) === 1;
        return $iran ? 'bank_iran' : 'bank_foreign';
    }

    public function allowsAccount(PaymentAccount $account, ?string $locale = null): bool
    {
        return $this->allows($this->accountMethod($account), $locale);
    }
}
