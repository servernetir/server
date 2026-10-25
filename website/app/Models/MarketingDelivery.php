<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingDelivery extends Model
{
    protected $table = 'marketing_deliveries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'scheduled_at' => 'datetime', 'attempted_at' => 'datetime', 'accepted_at' => 'datetime', 'delivered_at' => 'datetime', 'estimated_cost' => 'integer', 'segments' => 'integer'];
    }
}
