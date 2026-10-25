<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingRedemption extends Model
{
    protected $table = 'marketing_redemptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'expires_at' => 'datetime', 'committed_at' => 'datetime', 'amount' => 'integer', 'tax_reduction' => 'integer'];
    }
}
