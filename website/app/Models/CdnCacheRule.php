<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک قاعدهٔ کش.
 *
 * 🔴 **اولین تطبیق برنده است** و همین جمله باید در پنل هم نوشته شود. کاربری
 * که فکر می‌کند قواعد روی هم جمع می‌شوند، یک قاعدهٔ bypassِ عمومی بالای فهرست
 * می‌گذارد و بعد تعجب می‌کند چرا هیچ‌چیز کش نمی‌شود — و ما هم هیچ خطایی
 * نداریم که نشانش دهیم.
 */
class CdnCacheRule extends Model
{
    protected $fillable = [
        'cdn_zone_id', 'position', 'match_type', 'pattern',
        'action', 'ttl', 'ignore_query', 'enabled',
    ];

    protected $casts = [
        'ignore_query' => 'bool',
        'enabled'      => 'bool',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    /**
     * شکلی که به snapshot می‌رود.
     *
     * ⚠️ `ttl` نال یعنی «از zone بگیر»، نه «کش نکن». این تمایز در Lua هم باید
     * برقرار بماند وگرنه هر قاعده‌ای بدونِ TTL بی‌صدا کش را خاموش می‌کند.
     */
    public function toSnapshot(CdnZone $zone): array
    {
        return [
            'match'  => $this->match_type,
            'p'      => $this->pattern,
            'action' => $this->action,
            'ttl'    => $this->ttl !== null ? (int) $this->ttl : (int) $zone->default_ttl,
            'noq'    => (bool) $this->ignore_query,
        ];
    }
}
