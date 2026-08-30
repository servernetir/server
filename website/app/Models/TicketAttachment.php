<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    protected $fillable = [
        'ticket_id', 'ticket_message_id', 'disk', 'path',
        'original_name', 'mime', 'size',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /** اندازهٔ خوانا برای انسان */
    public function humanSize(): string
    {
        $kb = $this->size / 1024;
        if ($kb < 1024) {
            return number_format($kb, 0).' KB';
        }

        return number_format($kb / 1024, 1).' MB';
    }
}
