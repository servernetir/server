<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostingUpgrade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quote' => 'array', 'attempted_at' => 'datetime', 'completed_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
