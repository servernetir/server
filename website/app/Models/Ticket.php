<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تیکت پشتیبانی.
 *
 * جریان وضعیت در یک جا نگه داشته می‌شود (متدهای زیر)، نه پخش در کنترلرها،
 * تا «مشتری پاسخ داد یعنی open، ما پاسخ دادیم یعنی answered» همه‌جا یکی باشد.
 */
class Ticket extends Model
{
    protected $fillable = [
        'customer_id', 'number', 'subject', 'department', 'priority',
        'status', 'last_reply_role', 'last_reply_at',
        'subject_ref_type', 'subject_ref_id', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'closed_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            if (blank($t->number)) {
                $t->number = 'TK-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** پیام‌هایی که مشتری مجاز است ببیند — یادداشت داخلی حذف می‌شود */
    public function visibleMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** نوبت پاسخ با ماست؟ (برای صف پشتیبانی) */
    public function awaitingStaff(): bool
    {
        return $this->status === 'open';
    }

    /**
     * صفِ پشتیبانی — تیکت‌هایی که نوبتشان با ماست، قدیمی‌ترین اول.
     *
     * ⚠️ عمداً `status === 'open'` است و نه `isOpen()`: آن یکی
     * `status !== 'closed'` است و `answered` را هم می‌گیرد — یعنی تیکتی که
     * خودمان همین الان جوابش را داده‌ایم دوباره در صف می‌نشیند.
     *
     * تعریف این‌جا متمرکز است تا رباتِ بله و پنل **یک** صف ببینند. صفِ
     * دست‌نویسِ موازی یعنی روزی یکی بی‌صدا کهنه می‌شود و می‌گوید «چیزی در صف
     * نیست» در حالی که مشتری منتظر است.
     */
    public function scopeQueue($query)
    {
        return $query->where('status', 'open')->orderBy('last_reply_at');
    }

    /**
     * ثبت یک پاسخ و به‌روزرسانی وضعیت در یک حرکت.
     *
     * منطق وضعیت اینجا متمرکز است: پاسخ مشتری تیکت را باز می‌کند (نوبت ما)،
     * پاسخ کارکنان آن را answered می‌کند (نوبت مشتری). یادداشت داخلی وضعیت
     * را دست نمی‌زند — چون گفتگوی مشتری را جلو نمی‌برد.
     */
    public function addMessage(string $role, ?int $authorId, ?string $authorName, string $body, bool $internal = false): TicketMessage
    {
        $message = $this->messages()->create([
            'author_role' => $role,
            'author_id'   => $authorId,
            'author_name' => $authorName,
            'body'        => $body,
            'is_internal' => $internal,
        ]);

        if (! $internal) {
            $this->forceFill([
                'status'          => $role === 'staff' ? 'answered' : 'open',
                'last_reply_role' => $role,
                'last_reply_at'   => now(),
                // پاسخ روی تیکت بسته، دوباره بازش می‌کند
                'closed_at'       => null,
            ])->save();
        }

        return $message;
    }
}
