<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineConsoleGrant extends Model
{
    protected $table = 'cloud_engine_console_grants';
    protected $guarded = [];
    protected $hidden = ['token_hash', 'session_hash', 'fingerprint', 'vmid', 'node_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'redeemed_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
