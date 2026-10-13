<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک قاعدهٔ فایروال.
 *
 * چهار نوع، چون چهار چیزِ واقعاً متفاوت‌اند و ریختنشان در یک «عبارت» آزاد،
 * پنل را به یک زبانِ برنامه‌نویسی تبدیل می‌کرد که مشتری بلد نیست:
 *
 *  • `ip`         — یک IP یا CIDR
 *  • `geo`        — کدِ کشور
 *  • `rate_limit` — N درخواست در M ثانیه روی یک مسیر
 *  • `custom`     — عبارتِ ساده روی مسیر/عامل کاربر
 */
class CdnWafRule extends Model
{
    protected $fillable = [
        'cdn_zone_id', 'position', 'kind', 'label',
        'expression', 'action', 'params', 'enabled',
    ];

    protected $casts = [
        'params'  => 'array',
        'enabled' => 'bool',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    /**
     * ⚠️ `allow` عمداً در فهرستِ اقدام‌ها **نیست**.
     *
     * یک قاعدهٔ allow که بالای فهرست بنشیند، همهٔ قواعدِ زیرش و کلِ مجموعه‌قواعدِ
     * OWASP را برای آن ترافیک خاموش می‌کند. تا وقتی پنل نتواند این را به‌روشنی
     * به مشتری بگوید، دادنش یعنی دادنِ یک دکمهٔ «امنیت را خاموش کن» با برچسبِ
     * «اجازه بده».
     */
    public function isValidAction(): bool
    {
        return in_array($this->action, (array) config('edge.waf.actions', []), true);
    }

    public function toSnapshot(): array
    {
        $row = [
            'kind'   => $this->kind,
            'e'      => (string) $this->expression,
            'action' => $this->action,
        ];

        if ($this->kind === 'rate_limit') {
            // پیش‌فرض‌ها این‌جا هستند نه در Lua: نودی که با snapshotِ ناقص
            // مواجه شود نباید خودش سیاست بسازد.
            $row['n'] = (int) ($this->params['requests'] ?? 60);
            $row['w'] = (int) ($this->params['window'] ?? 60);
        }

        return $row;
    }
}
