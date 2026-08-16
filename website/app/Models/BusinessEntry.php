<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * یک ردیف دفتر مالی کسب‌وکار.
 *
 * موجودی و سود جمعِ ردیف‌هاست، نه ستون ذخیره‌شده. اینجا فقط مدل خام است؛
 * منطق ثبت و محاسبه در BusinessLedger (سرویس) متمرکز شده.
 */
class BusinessEntry extends Model
{
    protected $table = 'business_ledger';

    protected $fillable = [
        'currency_code', 'direction', 'kind', 'category', 'amount',
        'source_type', 'source_id', 'occurred_at', 'note', 'created_by',
        // هزینهٔ تکرارشوندهٔ ماهانه — کلیدِ یکتایی‌اش در مهاجرتِ add_period_to_business_ledger
        'period', 'ref_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'integer',
            'occurred_at' => 'date',
        ];
    }

    /** ثبت خودکار سیستم بود یا دستیِ صاحب کسب‌وکار؟ */
    public function isAuto(): bool
    {
        return $this->created_by === null;
    }
}
