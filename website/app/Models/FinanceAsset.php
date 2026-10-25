<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAsset extends Model
{
    protected $table = 'finance_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['in_service_at' => 'date', 'cost_irt' => 'integer', 'residual_irt' => 'integer', 'useful_life_months' => 'integer'];
    }
}
