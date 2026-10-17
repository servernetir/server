<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityApplication extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'concurrency' => 2];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'daily_cap_irt' => 'integer', 'concurrency' => 'integer'];
    }
}
