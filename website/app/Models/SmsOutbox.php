<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsOutbox extends Model
{
    protected $table = 'sms_outbox';

    protected $fillable = [
        'destination', 'event', 'body', 'params', 'status', 'attempts',
        'claim_token', 'claimed_at', 'expires_at', 'sent_at',
        'bale_chat_id', 'bale_sent',
        'provider_code', 'provider_message',
    ];

    protected function casts(): array
    {
        return [
            'params'     => 'array',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at'    => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
