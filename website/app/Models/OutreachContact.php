<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * یک ردیف از فهرستِ کمپین: «این دامنه، این ایمیل، این گزارش، این وضعیت».
 */
class OutreachContact extends Model
{
    protected $fillable = ['host', 'email', 'audit_report_id', 'status', 'error', 'sent_at',
        'unsubscribe_token', 'unsubscribed_at', 'batch', 'created_by'];

    protected $casts = [
        'sent_at'         => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    protected $hidden = ['unsubscribe_token'];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->unsubscribe_token = $c->unsubscribe_token ?: Str::lower(Str::random(32));
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(AuditReport::class, 'audit_report_id');
    }

    /**
     * 🔴 لغوِ اشتراک روی **ایمیل** است، نه روی ردیف.
     *
     * یک نفر می‌تواند در چند کمپین و برای چند دامنه در فهرست باشد. اگر «دیگر
     * نفرست» را فقط روی همان ردیفی بنشانیم که کلیک از آن آمده، همان شخص فردا
     * برای دامنهٔ دومش دوباره ایمیل می‌گیرد — که دقیقاً همان چیزی است که او
     * گفته بود نمی‌خواهد، و ما را از «کمپین» به «اسپم» می‌برد.
     */
    public static function isSuppressed(string $email): bool
    {
        return static::where('email', Str::lower(trim($email)))
            ->whereNotNull('unsubscribed_at')
            ->exists();
    }

    public static function suppress(string $email): int
    {
        return static::where('email', Str::lower(trim($email)))
            ->whereNull('unsubscribed_at')
            ->update(['unsubscribed_at' => now(), 'status' => 'skipped']);
    }

    /** آمادهٔ ارسال: گزارش دارد، هنوز نرفته، و لغو نکرده. */
    public function isSendable(): bool
    {
        return $this->audit_report_id !== null
            && $this->status === 'pending'
            && $this->unsubscribed_at === null
            && ! static::isSuppressed($this->email);
    }

    public function unsubscribeUrl(): string
    {
        return url('report/unsubscribe/'.$this->unsubscribe_token);
    }
}
