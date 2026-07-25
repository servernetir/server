<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک سرورِ تحویل — WHM/cPanel، Plesk، DirectAdmin یا زیرساختِ VPS/اختصاصی.
 *
 * توکن/رمزِ API با cast=encrypted ذخیره می‌شود و هرگز خام روی صفحه نمی‌آید.
 */
class Server extends Model
{
    protected $fillable = [
        'name', 'type', 'country', 'city', 'hostname', 'port', 'username', 'api_token', 'verify_tls',
        'server_ip', 'nameservers', 'status', 'max_accounts', 'active_accounts', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'api_token'       => 'encrypted',   // هرگز خام ذخیره نمی‌شود
            'verify_tls'      => 'boolean',
            'port'            => 'integer',
            'max_accounts'    => 'integer',
            'active_accounts' => 'integer',
            'meta'            => 'array',
        ];
    }

    /** نوع‌هایی که تحویلِ خودکار دارند (درایورِ API) در مقابلِ دستی */
    public const AUTO_TYPES = ['whm', 'directadmin'];

    public const TYPES = ['whm', 'plesk', 'directadmin', 'vps', 'dedicated', 'generic'];

    /** پورتِ پیش‌فرضِ هر نوع کنترل‌پنل */
    public const DEFAULT_PORTS = [
        'whm' => 2087, 'plesk' => 8443, 'directadmin' => 2222,
    ];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** آیا این نوع سرور تحویلِ خودکار دارد (فعلاً فقط WHM)؟ */
    public function isAutoProvisioned(): bool
    {
        return in_array($this->type, self::AUTO_TYPES, true);
    }

    public function effectivePort(): int
    {
        return $this->port ?: (self::DEFAULT_PORTS[$this->type] ?? 443);
    }

    /** ظرفیت پر شده؟ */
    public function isFull(): bool
    {
        return $this->status === 'full'
            || ($this->max_accounts !== null && $this->active_accounts >= $this->max_accounts);
    }

    public function canAcceptNew(): bool
    {
        return $this->status === 'active' && ! $this->isFull();
    }

    /** @return array{0:string,1:string} برچسب و رنگ */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'active'      => ['فعال', '#34d399'],
            'maintenance' => ['تعمیر', '#fbbf24'],
            'full'        => ['پر', '#ff6b6b'],
            default       => [$this->status, '#96a3ba'],
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'whm'         => 'WHM / cPanel',
            'plesk'       => 'Plesk',
            'directadmin' => 'DirectAdmin',
            'vps'         => 'VPS',
            'dedicated'   => 'سرور اختصاصی',
            default       => 'عمومی',
        };
    }

    // ─────────────────────────── مکان (ایران/آلمان) ───────────────────────────

    /** برچسبِ کشور به زبانِ جاری؛ اگر کشور ست نشده باشد خالی */
    public function locationLabel(): string
    {
        if (blank($this->country)) {
            return '';
        }

        $loc = config('billing.locations.'.strtoupper((string) $this->country));
        $label = is_array($loc['label'] ?? null)
            ? ($loc['label'][app()->getLocale()] ?? $loc['label']['fa'] ?? $this->country)
            : (string) $this->country;

        return trim(($loc['flag'] ?? '').' '.$label.($this->city ? ' — '.$this->city : ''));
    }

    /**
     * کشورهایی که همین حالا می‌توانند سرویسِ تازه بپذیرند.
     *
     * فقط سرورهای فعالِ غیرِپر و دارای تحویلِ خودکار حساب می‌شوند — اگر مکانی
     * سرورِ آماده ندارد، نباید در صفحهٔ خرید نمایش داده شود، وگرنه مشتری پول
     * می‌دهد و سرویسش روی هوا می‌ماند.
     *
     * @return list<string>
     */
    public static function availableCountries(): array
    {
        return static::query()
            // فعلاً فقط WHM: پکیج‌های کاتالوگ (sn_<slug>) روی WHM ساخته می‌شوند،
            // پس تبلیغِ مکانی که فقط سرورِ DirectAdmin دارد به شکستِ تحویل می‌رسد.
            ->where('type', 'whm')
            ->where('status', 'active')
            ->whereNotNull('country')
            ->get()
            ->filter(fn (self $s) => $s->canAcceptNew())
            ->map(fn (self $s) => strtoupper((string) $s->country))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * کم‌بارترین سرورِ آمادهٔ یک کشور — مقصدِ تحویلِ این خرید.
     *
     * lockForUpdate عمداً نیست: انتخاب فقط یک پیشنهاد است و خودِ ProvisioningService
     * پیش از ساختِ حساب دوباره وضعیت را می‌سنجد؛ قفلِ بلندمدت روی جدولِ سرورها
     * زیرِ بارِ همزمان بیشتر ضرر داشت.
     */
    public static function pickForCountry(string $country): ?self
    {
        return static::query()
            ->where('type', 'whm')                  // همان قیدِ availableCountries()
            ->where('status', 'active')
            ->whereRaw('UPPER(country) = ?', [strtoupper($country)])
            ->orderBy('active_accounts')
            ->get()
            ->first(fn (self $s) => $s->canAcceptNew());
    }
}
