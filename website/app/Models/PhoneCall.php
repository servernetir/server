<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک مکالمه — جمع‌بندیِ همهٔ رویدادهای یک `CallReferenceId`.
 *
 * ⚠️ این جدول **مشتق** است. هرگز مستقیم ویرایشش نکن؛ `CallIngestor::rebuild()`
 * را صدا بزن. تنها استثنا `recording_id` است که از CDR می‌آید نه از وبهوک.
 */
class PhoneCall extends Model
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_LOCAL = 'local';

    public const MATCH_MANY = 'many';

    public const MATCH_NONE = 'none';

    protected $fillable = [
        'call_reference_id', 'direction',
        'caller_number', 'callee_extension', 'transferred_to_number', 'caller_number_norm',
        'customer_id', 'match_confidence',
        'started_at', 'ended_at', 'duration_seconds',
        'answered', 'was_transferred', 'legs',
        'entry_type', 'final_handler', 'menu_name', 'menu_input', 'initiation_source',
        'recording_id', 'recording_checked_at',
        'last_event_at', 'event_count',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_event_at' => 'datetime',
        'recording_checked_at' => 'datetime',
        'answered' => 'boolean',
        'was_transferred' => 'boolean',
        'duration_seconds' => 'integer',
        'legs' => 'integer',
        'event_count' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(PhoneCallEvent::class, 'call_reference_id', 'call_reference_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * تماس‌های از‌دست‌رفته.
     *
     * 🔴 `answered = false` صریح، نه `!answered`. تماسی که هنوز `Ended` نگرفته
     * `answered = null` دارد و **از‌دست‌رفته نیست** — فقط تمام نشده. اگر
     * `whereNot(true)` می‌نوشتیم، هر تماسِ در جریان یک تیکتِ الکی می‌ساخت.
     */
    public function scopeMissed(Builder $q): Builder
    {
        return $q->where('answered', false);
    }

    /** تماسی که به پروندهٔ مشتری چسبیده — با هر درجه‌ای از قطعیت. */
    public function scopeMatched(Builder $q): Builder
    {
        return $q->whereNotNull('customer_id');
    }

    /**
     * آیا تطبیقِ مشتری قطعی است؟
     *
     * ⚠️ رابط کاربری باید برای `local` نشانهٔ تردید نشان دهد. شمارهٔ ثابت
     * بدونِ پیش‌شماره می‌آید، پس «۳۴۲۶۱۰۰۰» در سه شهر سه مشتریِ متفاوت است.
     */
    public function isConfidentMatch(): bool
    {
        return $this->match_confidence === self::MATCH_EXACT;
    }

    public function hasRecording(): bool
    {
        return $this->recording_id !== null;
    }
}
