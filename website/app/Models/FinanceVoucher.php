<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceVoucher extends Model
{
    protected $table = 'finance_vouchers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'date', 'owns_business_entry' => 'boolean', 'amount_irt' => 'integer', 'original_minor' => 'integer', 'settlement_minor' => 'integer', 'input_vat_irt' => 'integer'];
    }
}
