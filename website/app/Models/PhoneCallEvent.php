<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک رویدادِ خامِ وبهوکِ تلفن ابری.
 *
 * 🔴 این جدول **فقط نوشته می‌شود، هرگز بازنویسی نمی‌شود.** تاریخِ خام است.
 * جمع‌بندی در `PhoneCall` می‌نشیند و هر وقت لازم شد از روی همین بازساخته
 * می‌شود — پس اگر منطقِ جمع‌بندی عوض شد، هیچ داده‌ای از دست نمی‌رود.
 */
class PhoneCallEvent extends Model
{
    protected $fillable = [
        'event_id', 'call_reference_id', 'call_id', 'event_type', 'occurred_at',
        'caller_number', 'callee_extension', 'transferred_to_number', 'caller_number_norm',
        'result', 'call_entry_type', 'final_handler', 'menu_name', 'menu_input',
        'started_at', 'ended_at', 'duration_seconds', 'initiation_source',
        'payload', 'received_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'received_at' => 'datetime',
        'result' => 'boolean',
        'duration_seconds' => 'integer',
        'payload' => 'array',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(PhoneCall::class, 'call_reference_id', 'call_reference_id');
    }
}
