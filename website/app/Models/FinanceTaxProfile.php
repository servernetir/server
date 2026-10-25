<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceTaxProfile extends Model
{
    protected $table = 'finance_tax_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'reviewed' => 'boolean', 'income_rate_bp' => 'integer'];
    }
}
