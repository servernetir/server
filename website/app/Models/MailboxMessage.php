<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * یک نامه در یکی از صندوق‌های مدیریتی — سرآیند و پیش‌نمایش، نه متنِ کامل.
 *
 * ⚠️ **این ردیف هنوز بدنهٔ نامه را ندارد و نباید داشته باشد.** خواندن و پاسخ
 * از مرداد ۱۴۰۵ در پنل هست (`MailboxReader` + `MailboxReplier`)، ولی متنِ
 * کامل در لحظه از IMAP خوانده می‌شود و هیچ‌جا نمی‌نشیند. دلیلش در مهاجرت
 * نوشته شده: صندوقِ support@ پر از دادهٔ مشتری است.
 *
 * پرسشی که این جدول جواب می‌دهد همان است: «چه چیزی هست که هنوز کسی حواسش به
 * آن نبوده؟»
 */
class MailboxMessage extends Model
{
    protected $table = 'mailbox_messages';

    protected $fillable = [
        'account', 'uid_hash', 'message_id',
        'from_email', 'from_name', 'subject', 'snippet', 'received_at',
        'is_system', 'category', 'importance', 'needs_reply', 'summary',
        'reported_at', 'handled_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'reported_at' => 'datetime',
        'handled_at'  => 'datetime',
        'is_system'   => 'bool',
        'needs_reply' => 'bool',
        'importance'  => 'int',
    ];

    /** کلیدِ ضدِ تکرار. یک نامه در دو صندوق = دو ردیف، و این درست است. */
    public static function hashFor(string $account, string $messageId): string
    {
        return hash('sha256', $account.'|'.trim(mb_strtolower($messageId)));
    }

    public function categoryLabel(): string
    {
        return config('mailboxes.categories.'.$this->category) ?? $this->category ?? '—';
    }

    public function accountLabel(): string
    {
        foreach ((array) config('mailboxes.accounts', []) as $a) {
            if (($a['key'] ?? null) === $this->account) {
                return $a['label'] ?? $this->account;
            }
        }

        return $this->account;
    }

    /**
     * نامزدهای گزارشِ بله: نه سیستمی، نه قبلاً گزارش‌شده.
     *
     * 🔴 `is_system` اینجا فیلتر می‌شود و نه در زمانِ ساختِ متنِ گزارش. اگر
     * پایین‌دست فیلتر می‌شد، هر مسیرِ تازه‌ای که فراموش می‌کرد فیلتر کند،
     * اعلان‌های سیستمی را دوباره می‌فرستاد — دقیقاً چیزی که قرار بود نشود.
     */
    public function scopeUnreported($q)
    {
        return $q->where('is_system', false)->whereNull('reported_at');
    }

    /** چیزی که هنوز کسی جوابش را نداده */
    public function scopeOpen($q)
    {
        return $q->where('is_system', false)->whereNull('handled_at');
    }
}
