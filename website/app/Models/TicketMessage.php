<?php

namespace App\Models;

use App\Support\CardRedactor;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketMessage extends Model
{
    protected $fillable = [
        'ticket_id', 'author_role', 'author_id', 'author_name', 'body', 'is_internal',
    ];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    /**
     * 🔴 شماره‌ی کاملِ کارت هرگز در متنِ تیکت نمی‌نشیند.
     *
     * ⚠️ عمداً یک mutator روی **مدل** است و نه یک تمیزکاری در
     * `Ticket::addMessage()`. سه دلیلِ مستقل:
     *
     *  ۱ این تنها درِ نوشتن نیست — `LicenseOrderTicket` مستقیم
     *    `TicketMessage::create()` می‌زند، و درِ بعدی هم روزی اضافه می‌شود.
     *    محافظی که در یکی از چند مسیر بنشیند، همان روزی که مسیرِ تازه اضافه
     *    شود بی‌صدا دور زده می‌شود.
     *  ۲ `update` را هم می‌گیرد، نه فقط `create`.
     *  ۳ چون در لحظهٔ **ست‌کردن** اجرا می‌شود، نسخهٔ درون‌حافظه هم ماسک‌خورده
     *    است — پس ایمیل و پیامکِ اعلان که از همین شیء متن برمی‌دارند، شمارهٔ
     *    کامل را به بیرون نمی‌برند. تمیزکاریِ سطحِ دیتابیس این را نمی‌داد.
     */
    protected function body(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => CardRedactor::mask($value),
        );
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function fromStaff(): bool
    {
        return $this->author_role === 'staff';
    }

    /**
     * نامِ کارشناس برای نمایش به **مشتری**، به زبانِ خودِ مشتری.
     *
     * 🔴 از `author_id` زنده خوانده می‌شود، نه از `author_name`ِ ذخیره‌شده:
     * آن ستون عکسِ لحظهٔ ارسال است و فقط یک زبان دارد — پیامی که پارسال با
     * «ebrahimi» ثبت شده باید امروز «احسان ابراهیمی/Ehsan Ebrahimi» دیده شود.
     *
     * `null` یعنی «نامی نشان نده» و ویو به برچسبِ عمومیِ tk_staff سقوط
     * می‌کند — از جمله برای مشتریِ en/tr وقتی کارمند نامِ لاتین ندارد.
     *
     * ⚠️ کشِ ایستا برای صفحهٔ تیکت است که یک رشته پیام از یکی‌دو کارمند
     * دارد؛ بدونش هر پیام یک SELECT جدا می‌زد.
     */
    public function staffDisplayName(): ?string
    {
        if (! $this->fromStaff()) {
            return null;
        }

        if ($this->author_id === null) {
            return app()->getLocale() === 'fa'
                ? (trim((string) $this->author_name) ?: null)
                : null;
        }

        static $cache = [];

        if (! array_key_exists($this->author_id, $cache)) {
            $cache[$this->author_id] = User::find($this->author_id);
        }

        $user = $cache[$this->author_id];

        if ($user === null) {
            // کارمندِ حذف‌شده — همان عکسِ ذخیره‌شده، فقط برای فارسی
            return app()->getLocale() === 'fa'
                ? (trim((string) $this->author_name) ?: null)
                : null;
        }

        return $user->displayNameFor();
    }
}
