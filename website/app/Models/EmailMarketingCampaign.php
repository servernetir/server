<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMarketingCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['queued_at' => 'immutable_datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailMarketingDelivery::class, 'campaign_id');
    }
}
