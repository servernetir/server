<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * یک بررسیِ ذخیره‌شدهٔ سئو/سلامتِ سایت، با نشانیِ عمومیِ خودش.
 *
 * ⚠️ نامش عمداً `AuditReport` است نه `SiteAudit`: `App\Services\SiteAudit`
 * موتورِ بررسی است و هم‌نامی، importهای اشتباه می‌سازد.
 */
class AuditReport extends Model
{
    use HasFactory;

    protected $fillable = ['token', 'url', 'host', 'score', 'grade', 'locale', 'result', 'source', 'created_by'];

    protected $casts = [
        'result' => 'array',
        'score'  => 'integer',
    ];

    /**
     * توکن هرگز در JSON نمی‌رود مگر خودمان بخواهیم.
     *
     * توکن **همان رمزِ** گزارش است؛ هر جا مدل سریالایز شود (پاسخِ API، لاگ،
     * خطای دیباگ) نشانیِ گزارشِ یک مشتری به دستِ دیگری می‌افتد. همان قاعدهٔ
     * `CloudPlan::$hidden`.
     */
    protected $hidden = ['token', 'created_by'];

    /** روزهای اعتبار — بعد از آن گزارش «کهنه» علامت می‌خورد، نه حذف. */
    public const FRESH_DAYS = 30;

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->token = $r->token ?: static::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::lower(Str::random(32));
    }

    /**
     * ساختِ گزارش از خروجیِ `SiteAudit::run()`.
     *
     * ⚠️ فقط نتیجهٔ موفق ذخیره می‌شود. گزارشی که «سایت در دسترس نبود» بگوید،
     * لینکِ فرستادنی نیست — و بدتر، اگر برای مشتری بفرستیم چیزی نشانش می‌دهد
     * که دربارهٔ سایتش هیچ نمی‌گوید.
     */
    public static function fromAudit(array $audit, string $source = 'tool', ?int $userId = null, ?string $locale = null): ?self
    {
        if (($audit['ok'] ?? false) !== true) {
            return null;
        }

        return static::create([
            'url'        => (string) ($audit['url'] ?? ''),
            'host'       => (string) ($audit['host'] ?? ''),
            'score'      => (int) ($audit['overall'] ?? 0),
            'grade'      => (string) ($audit['grade'] ?? ''),
            'locale'     => $locale ?: app()->getLocale(),
            'result'     => $audit,
            'source'     => $source,
            'created_by' => $userId,
        ]);
    }

    /**
     * نشانیِ عمومیِ گزارش، در زبانی که ساخته شده.
     *
     * ⚠️ `ConsoleHost::siteUrl()` نه `url()`: مدیر گزارش را از پنل می‌فرستد و
     * پنل روی `console.` است، پس `url()` نشانیِ پنلِ مدیریت را در ایمیلِ مشتری
     * می‌گذاشت.
     */
    public function url(): string
    {
        $prefix = ['fa' => '', 'en' => 'en/', 'tr' => 'tr/'][$this->locale] ?? '';

        return \App\Http\Middleware\ConsoleHost::siteUrl($prefix.'report/'.$this->token);
    }

    public function isStale(): bool
    {
        return $this->created_at !== null
            && $this->created_at->lt(now()->subDays(self::FRESH_DAYS));
    }

    /** «چند مورد باید درست شود» — همان عددی که در ایمیل وعده داده می‌شود. */
    public function issueCount(): int
    {
        $counts = $this->result['counts'] ?? [];

        return (int) ($counts['fail'] ?? 0) + (int) ($counts['warn'] ?? 0);
    }

    public function failCount(): int
    {
        return (int) ($this->result['counts']['fail'] ?? 0);
    }
}
