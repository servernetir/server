<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthContent extends Model
{
    protected $table = 'growth_contents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['brief' => 'string', 'version' => 'integer', 'approved_at' => 'datetime', 'scheduled_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
