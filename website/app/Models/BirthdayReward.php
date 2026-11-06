<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirthdayReward extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['profile_fingerprint', 'sms_pattern_reference'];
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
            'sms_attempted_at' => 'datetime', 'sms_accepted_at' => 'datetime', 'cycle_year' => 'integer'];
    }
}
