<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MadarMission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'reward_campaign_id' => 'integer', 'reward_amount_irt' => 'integer', 'version' => 'integer',
            'published_at' => 'datetime', 'submissions_until' => 'datetime'];
    }
}
