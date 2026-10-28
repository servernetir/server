<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirthdayRedemption extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['snapshot'];
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'amount' => 'integer', 'expires_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
