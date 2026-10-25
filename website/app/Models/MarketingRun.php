<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingRun extends Model
{
    protected $table = 'marketing_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
