<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    protected $table = 'marketing_campaigns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule' => 'array', 'audience' => 'array', 'automation' => 'array', 'message' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'approved_at' => 'datetime', 'messages_paused' => 'boolean', 'budget' => 'integer', 'held' => 'integer', 'spent' => 'integer'];
    }
}
