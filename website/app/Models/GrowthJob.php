<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthJob extends Model
{
    protected $table = 'growth_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'content_version' => 'integer', 'max_cost_irt' => 'integer', 'actual_cost_irt' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
