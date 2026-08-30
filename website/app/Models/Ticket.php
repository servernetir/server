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
    /**
     * وضعیت‌های تیکت — **تنها منبع**، با برچسبِ کارکنان.
     *
     * ═══ چرا فقط همین چهار تا ═══
     *
     * هر وضعیت باید به یک سؤال جوابِ بی‌ابهام بدهد: «نوبتِ کیست؟»
     *
     *   open     → نوبتِ ما (تیکتِ تازه یا پاسخِ مشتری) — در صف
     *   answered → نوبتِ مشتری — بیرونِ صف
     *   held     → عمداً کنار گذاشته شده (منتظرِ قطعه، تأمین‌کننده، …) —
     *              بیرونِ صف؛ پاسخِ مشتری خودکار به open برش می‌گرداند
     *   closed   → تمام
     *
     * وضعیتی مثلِ «در حال بررسی» عمداً نیست: نوبت را عوض نمی‌کند، و برچسبی
     * که با واقعیت جلو نرود می‌پوسد — تیکتی که ساعتِ ۹ «در حال بررسی» شد و
     * فراموش شد، تا ابد همان می‌مانَد و صف را دروغگو می‌کند.
     *
     * ⚠️ صف (پنل + رباتِ بله) فقط `open` را می‌بیند؛ `held` بودنش در صف
     * نقضِ غرض است — نگه‌داشتن یعنی «الان دستم به این نمی‌رسد».
     */
    public const STATUSES = [
        'open'     => 'در انتظار بررسی',
        'answered' => 'پاسخ داده شد',
        'held'     => 'نگه‌داشته‌شده',
        'closed'   => 'بسته',
    ];

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

    public function isHeld(): bool
    {
        return $this->status === 'held';
    }

    /**
     * تغییرِ وضعیت با قاعدهٔ واحد — پنل، عملیاتِ گروهی و ربات همه از همین.
     *
     * 🔴 `closed_at` این‌جا مدیریت می‌شود و نه در هر کنترلر: بستن مهر می‌زند،
     * هر وضعیتِ دیگری پاکش می‌کند. اگر هر فراخوان خودش تصمیم می‌گرفت، دیر یا
     * زود یکی جا می‌انداخت و تیکتِ «باز» با تاریخِ بسته‌شدن می‌ماند — که
     * گزارش‌ها و تقویمِ کسب‌وکار را بی‌صدا غلط می‌کند.
     *
     * وضعیتِ ناشناخته نادیده گرفته می‌شود، نه استثنا: ورودی از فرم می‌آید و
     * فرمِ دست‌کاری‌شده نباید ۵۰۰ بسازد.
     */
    public function transitionTo(string $status): bool
    {
        if (! array_key_exists($status, self::STATUSES) || $status === $this->status) {
            return false;
        }

        $this->status = $status;
        $this->closed_at = $status === 'closed' ? now() : null;
        $this->save();

        return true;
    }

    /** برچسبِ فارسیِ وضعیت برای کارکنان. */
    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
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
