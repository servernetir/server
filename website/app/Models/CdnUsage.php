<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مصرفِ روزانهٔ یک zone روی یک نود.
 *
 * 🔴 تفکیکِ نود اختیاری نیست. پهنای باندِ نودِ ایران چند برابرِ آلمان است، پس
 * «۱۰۰ گیگابایت» بی‌آنکه بدانیم از کجا رفته، عددی است که نه می‌شود صورت‌حساب
 * کرد نه سود و زیانش را فهمید. همان درسِ `cost_hour_eur_micro` روی سرورِ
 * ساعتی، این‌بار روی ترافیک.
 */
class CdnUsage extends Model
{
    // ⚠️ لاراول از روی نامِ کلاس `cdn_usages` می‌سازد. «usage» اسمِ غیرقابلِ
    //    شمارش است و جدول عمداً مفرد نام‌گذاری شده؛ بی‌این خط، هر پرس‌وجو با
    //    «no such table» می‌ترکد — که دستِ‌کم پرصداست. خطرناک‌ترش این بود که
    //    نامِ جدول را در مهاجرت جمع می‌بستیم و جدولِ خالیِ دومی می‌ساختیم.
    protected $table = 'cdn_usage';

    protected $fillable = [
        'cdn_zone_id', 'cdn_node_id', 'day',
        'bytes_out', 'bytes_in', 'requests', 'cache_hits', 'blocked',
    ];

    protected $casts = ['day' => 'date'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CdnNode::class, 'cdn_node_id');
    }

    public function gigabytesOut(): float
    {
        return ((int) $this->bytes_out) / 1_073_741_824;
    }

    /** بهایِ تمام‌شدهٔ همین ردیف به یورو — نود بها را می‌داند، zone نه */
    public function costEur(?CdnNode $node = null): float
    {
        $node ??= $this->node;

        return $node === null ? 0.0 : $this->gigabytesOut() * $node->costPerGbEur();
    }

    public function hitRatio(): float
    {
        $req = (int) $this->requests;

        return $req > 0 ? ((int) $this->cache_hits) / $req : 0.0;
    }
}
