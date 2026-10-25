<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthCampaign extends Model
{
    protected $table = 'growth_campaigns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}
