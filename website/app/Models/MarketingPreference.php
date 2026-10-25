<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingPreference extends Model
{
    protected $table = 'marketing_preferences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime', 'suppressed' => 'boolean', 'tags' => 'array', 'birth_month' => 'integer', 'birth_day' => 'integer', 'birth_year' => 'integer'];
    }
}
