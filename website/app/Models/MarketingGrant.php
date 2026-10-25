<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingGrant extends Model
{
    protected $table = 'marketing_grants';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'budget_held' => 'boolean', 'liability' => 'integer'];
    }
}
