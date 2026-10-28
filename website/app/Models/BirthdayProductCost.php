<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirthdayProductCost extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['reference', 'verified_by'];
    protected function casts(): array
    {
        return ['amount' => 'integer', 'verified_at' => 'datetime', 'valid_until' => 'datetime'];
    }
}
