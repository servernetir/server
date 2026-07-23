<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * هزینهٔ ثابت یک سرویس — که صاحب کسب‌وکار خودش تعیین می‌کند.
 *
 * دفتر مالی به‌جای عددِ حدسیِ config، از این‌جا می‌خواند. اگر جدول هنوز
 * ساخته نشده (سروری که مهاجرت نکرده)، به config برمی‌گردیم تا چیزی نشکند.
 */
class ServiceCost extends Model
{
    protected $fillable = ['key', 'label', 'currency_code', 'amount', 'note', 'is_system'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'is_system' => 'boolean'];
    }

    /**
     * مبلغ یک سرویس بر حسب کلید — با fallback به config و در نهایت صفر.
     *
     * صفر یعنی «هزینه‌اش را نمی‌دانم»؛ دفتر مالی هزینهٔ صفر را ثبت نمی‌کند،
     * پس تا وقتی مدیر عددش را ننوشته، حدس اشتباه وارد گزارش نمی‌شود.
     */
    public static function amountFor(string $key): int
    {
        if (Schema::hasTable('service_costs')) {
            $row = static::query()->where('key', $key)->first();

            if ($row !== null) {
                return (int) $row->amount;
            }
        }

        return (int) config("finance.costs.{$key}", 0);
    }
}
