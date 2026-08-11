<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک پیامِ رفته یا آمده — ایمیل، یا (دستی) لینکدین و اینستاگرام.
 *
 * پیامِ لینکدین/اینستاگرام هم اینجا ثبت می‌شود، ولی **ارسالش خودکار نیست**:
 * اتوماسیونِ آن دو پلتفرم نقضِ شرایطشان است و اکانت را می‌سوزاند. سیستم متن را
 * آماده می‌کند، انسان می‌فرستد و همین‌جا `sent` می‌خورد.
 */
class CrmMessage extends Model
{
    protected $table = 'crm_messages';

    protected $fillable = [
        'lead_id', 'channel', 'direction', 'subject', 'body',
        'status', 'sequence', 'provider_id', 'error', 'sent_at',
    ];

    protected $casts = [
        'sequence' => 'int',
        'sent_at'  => 'datetime',
    ];

    /** سقفِ مطلقِ دنباله: پیامِ اول + دو فالوآپ. پیامِ چهارم وجود ندارد. */
    public const MAX_SEQUENCE = 2;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function scopeOutbound($q)
    {
        return $q->where('direction', 'out');
    }

    public function scopeInbound($q)
    {
        return $q->where('direction', 'in');
    }
}
