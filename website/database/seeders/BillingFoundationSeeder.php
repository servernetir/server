<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * دادهٔ پایهٔ صورت‌حساب: ارزها و قواعد مالیات.
 * idempotent است — اجرای دوباره چیزی خراب نمی‌کند.
 */
class BillingFoundationSeeder extends Seeder
{
    public function run(): void
    {
        /* ---------- ارزها ---------- */
        // تومان: بدون اعشار، گرد به ۱۰٬۰۰۰ (مثل site_price_yearly فعلی)
        Currency::updateOrCreate(['code' => 'IRT'], [
            'exponent'      => 0,
            'rounding_step' => 10000,
            'symbol'        => '',        // واژهٔ «تومان» از __('ui.cur_IRT') می‌آید
            'symbol_before' => false,
            'is_base'       => config('app.locale') === 'fa',
            'is_active'     => true,
        ]);

        Currency::updateOrCreate(['code' => 'EUR'], [
            'exponent'      => 2,
            'rounding_step' => 1,
            'symbol'        => '€',
            'symbol_before' => true,
            'is_base'       => config('app.locale') !== 'fa',
            'is_active'     => true,
        ]);

        /* ---------- مالیات ---------- */
        // تصمیم کارفرما: ایران ۱۰٪ · خارج ۰٪ — مستقل از روش پرداخت.
        TaxRate::updateOrCreate(
            ['country' => 'IR', 'customer_type' => null, 'product_kind' => null],
            ['name' => 'مالیات بر ارزش افزوده', 'rate_bp' => 1000, 'priority' => 10, 'is_active' => true],
        );

        TaxRate::updateOrCreate(
            ['country' => null, 'customer_type' => null, 'product_kind' => null],
            ['name' => 'بدون مالیات (خارج از ایران)', 'rate_bp' => 0, 'priority' => 0, 'is_active' => true],
        );
    }
}
