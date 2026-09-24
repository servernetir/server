<?php

namespace App\Services\Ai;

use App\Models\Customer;
use App\Models\TaxRate;

/**
 * مالیات بر ارزش افزودهٔ مصرفِ AI (D10) — **روی** قیمتِ اعلام‌شده، به ازای هر تماس.
 *
 * ═══ قاعدهٔ بدهیِ مالیاتی ═══
 *
 *   مالیات می‌خورد، مگر `customers.country_code` یک کدِ ISO **غیرِ IR** باشد.
 *
 * چرا این جهت: کم‌گرفتنِ مالیات بدهیِ ماست و بعداً از جیبِ خودمان پرداخت می‌شود؛
 * بیش‌گرفتن قابلِ بازگشت است. پس «نبودِ کشور» هرگز معافیت نمی‌سازد، و زبانِ
 * صفحه هم نه — ایرانیِ که سایت را انگلیسی می‌بیند همچنان ایرانی است.
 *
 * ⚠️ امروز جدولِ `customers` ستونِ `country_code` ندارد، پس **همه** مالیات
 * می‌دهند (`vat_basis` = fa_locale یا no_country). این پیش‌فرضِ امنِ مشخصات
 * است تا مالک بگوید چه چیزی غیرِایرانی بودن را ثابت می‌کند (m5-spec §9 پرسش ۱).
 * ستون که بیاید، همین کلاس بی‌تغییر آن را می‌خواند.
 *
 * نرخ از `tax_rates` (ردیفِ `country=IR`، ترجیحاً `product_kind=ai`)؛ ردیفِ
 * سراسریِ بی‌کشور عمداً نادیده گرفته می‌شود چون در سیدر همان «خارج ۰٪» است و
 * برای مشتریِ بی‌کشور یعنی همان معافیتِ ساختگی که بالا منع شد.
 */
final class AiVat
{
    public const BASIS_IR_COUNTRY = 'ir_country';

    public const BASIS_FA_LOCALE = 'fa_locale';

    public const BASIS_NO_COUNTRY = 'no_country';

    public const BASIS_FOREIGN = 'foreign_country';

    /** ۱۰٪ — نرخِ قانونیِ فعلی؛ فقط وقتی ردیفِ `tax_rates` ایران نیست */
    public const DEFAULT_IR_BP = 1000;

    /** @return array{bp:int, basis:string} */
    public function resolve(Customer $customer): array
    {
        $country = strtoupper(trim((string) ($customer->getAttribute('country_code') ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'IR') {
            return ['bp' => 0, 'basis' => self::BASIS_FOREIGN];
        }

        $basis = match (true) {
            $country === 'IR' => self::BASIS_IR_COUNTRY,
            strtolower((string) $customer->locale) === 'fa' => self::BASIS_FA_LOCALE,
            default => self::BASIS_NO_COUNTRY,
        };

        return ['bp' => $this->iranRateBp(), 'basis' => $basis];
    }

    /** نرخِ ایران به صدمِ درصد — ۱۰٪ = 1000 */
    public function iranRateBp(): int
    {
        try {
            $row = TaxRate::resolve('IR', null, 'ai');

            if ($row !== null && strtoupper((string) $row->country) === 'IR') {
                return max(0, min(10_000, (int) $row->rate_bp));
            }
        } catch (\Throwable) {
            // نبودِ جدول نباید قیمت‌گذاری را بخواباند؛ پیش‌فرضِ قانونی می‌ماند
        }

        return self::DEFAULT_IR_BP;
    }
}
