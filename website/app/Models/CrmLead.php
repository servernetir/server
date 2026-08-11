<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک سرنخِ فروش — کسب‌وکاری که هنوز مشتری نیست.
 *
 * ⚠️ این مدل **عمداً** هیچ رابطه‌ای با `Customer` یا `Service` ندارد. سرنخ تا وقتی
 * مشتری نشده، شهروندِ حوزهٔ بازاریابی است و نه بیشتر. اگر روزی خرید کرد، یک
 * `Customer` تازه ساخته می‌شود و این ردیف فقط `stage=won` می‌خورد. گره‌زدنِ این دو
 * وسوسه‌انگیز است ولی یعنی یک باگِ بازاریابی می‌تواند دادهٔ صورت‌حساب را لمس کند.
 */
class CrmLead extends Model
{
    protected $table = 'crm_leads';

    protected $fillable = [
        'domain_hash', 'company', 'contact_name', 'country', 'city', 'vertical',
        'website', 'email', 'phone', 'source',
        'audit_score', 'audit', 'observation',
        'offer', 'value_eur', 'stage',
        'next_action_at', 'last_contacted_at', 'replied_at',
        'won_at', 'lost_at', 'lost_reason', 'notes',
    ];

    protected $casts = [
        'audit'             => 'array',
        'audit_score'       => 'int',
        'value_eur'         => 'int',
        'next_action_at'    => 'date',
        'last_contacted_at' => 'datetime',
        'replied_at'        => 'datetime',
        'won_at'            => 'datetime',
        'lost_at'           => 'datetime',
    ];

    /** مراحل به ترتیبِ قیف. کلید در دیتابیس، برچسب برای نمایش. */
    public const STAGES = [
        'new'      => 'سرنخ جدید',
        'contacted'=> 'تماس گرفته شد',
        'fu1'      => 'فالوآپ ۱',
        'replied'  => 'جواب داد',
        'review'   => 'ممیزی فرستاده شد',
        'proposal' => 'پیشنهاد ارسال شد',
        'won'      => 'برنده',
        'lost'     => 'از دست رفت',
    ];

    /**
     * فاصلهٔ اقدامِ بعدی بر حسبِ روز.
     *
     * 🔴 بعد از `fu1` عمداً هیچ فاصله‌ای نیست. کسی که دو بار جواب نداده مشتری
     * نیست، و پیامِ سوم فقط شانسِ سالِ آینده را می‌سوزاند.
     */
    public const CADENCE = [
        'contacted' => 5,
        'fu1'       => 7,
        'replied'   => 1,
        'review'    => 3,
        'proposal'  => 4,
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(CrmMessage::class, 'lead_id');
    }

    /** پیام‌هایی که ما فرستاده‌ایم — برای شمردنِ دنباله */
    public function outbound(): HasMany
    {
        return $this->messages()->where('direction', 'out');
    }

    // ───────────────────────── کلیدِ ضدِ تکرار ─────────────────────────

    /**
     * دامنه را نرمال می‌کند و هش می‌دهد. `https://WWW.X.com/a?b=1` و `x.com`
     * باید یک کلید بدهند، وگرنه همان کلینیک دو بار وارد می‌شود و دو بار
     * ایمیل می‌گیرد.
     */
    public static function hashFor(string $websiteOrEmail): string
    {
        $s = trim(mb_strtolower($websiteOrEmail));

        if (str_contains($s, '@')) {
            $s = substr(strrchr($s, '@'), 1);
        }

        $s = (string) preg_replace('#^https?://#', '', $s);
        $s = (string) preg_replace('#^www\.#', '', $s);
        $s = explode('/', $s)[0];
        $s = explode(':', $s)[0];

        return hash('sha256', $s);
    }

    // ───────────────────────── وضعیت ─────────────────────────

    /** آیا این سرنخ آمادهٔ ارسالِ پیام است؟ */
    public function isContactable(): bool
    {
        return filled($this->email)
            && filled($this->observation)          // قانونِ ۶۰ ثانیه
            && ! in_array($this->stage, ['won', 'lost'], true);
    }

    /** چند پیام بیرونی تا حالا رفته — سقفِ سخت در سرویسِ ارسال اعمال می‌شود */
    public function sentCount(): int
    {
        return $this->outbound()->whereIn('status', ['sent'])->count();
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    public function scopeDue($q)
    {
        return $q->whereNotNull('next_action_at')
            ->whereDate('next_action_at', '<=', now()->toDateString())
            ->whereNotIn('stage', ['won', 'lost']);
    }
}
