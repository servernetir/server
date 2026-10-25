<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthDocument extends Model
{
    protected $table = 'growth_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['review_at' => 'datetime', 'verified_at' => 'datetime'];
    }
}
