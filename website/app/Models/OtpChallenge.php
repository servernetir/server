<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpChallenge extends Model
{
    protected $fillable = [
        'channel', 'destination', 'purpose', 'code_hash',
        'attempts', 'resends', 'ip', 'expires_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
