<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailMarketingRecipient extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['email', 'full_name', 'email_digest', 'digest_key_id', 'public_id'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'full_name' => 'encrypted', 'withdrawn_at' => 'immutable_datetime', 'suppressed_at' => 'immutable_datetime'];
    }
}
