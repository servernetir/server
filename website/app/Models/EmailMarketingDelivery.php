<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailMarketingDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
