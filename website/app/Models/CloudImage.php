<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * سیستم‌عامل یا نرم‌افزارِ آمادهٔ سرورِ ابری.
 *
 * مشتری `key` را انتخاب می‌کند (مثلِ `ubuntu-24.04`) و در لحظهٔ ساخت، ردیفِ
 * **همان ارائه‌دهنده‌ای** که سرور رویش ساخته می‌شود خوانده و شناسهٔ بومی‌اش
 * فرستاده می‌شود. پس اگر سرور از آیزا دربیاید، همان اوبونتو با شناسهٔ آیزا نصب
 * می‌شود بی‌آنکه چیزی در ظاهر عوض شود.
 */
class CloudImage extends Model
{
    protected $fillable = [
        'provider', 'provider_ref', 'key', 'kind', 'family', 'version',
        'label', 'arch', 'min_disk_gb', 'is_active', 'sort',
    ];

    protected $hidden = ['provider', 'provider_ref'];

    protected $casts = ['is_active' => 'bool'];

    /** لوگو/آیکونِ خانواده — برای انتخابگرِ زیبا در پنل */
    public const ICONS = [
        'ubuntu' => '🟠', 'debian' => '🔴', 'centos' => '🟣', 'rocky' => '🟢',
        'almalinux' => '🔵', 'fedora' => '🔷', 'alpine' => '🏔️', 'arch' => '🔹',
        'opensuse' => '🦎', 'windows' => '🪟', 'freebsd' => '😈',
        'docker' => '🐳', 'wordpress' => '📝', 'nextcloud' => '☁️',
        'plesk' => '🎛️', 'cpanel' => '🎚️', 'gitlab' => '🦊', 'nginx' => '⚡',
        'mysql' => '🐬', 'postgresql' => '🐘', 'redis' => '🧱', 'mongodb' => '🍃',
        'n8n' => '🔗', 'coolify' => '🧊', 'portainer' => '📦', 'grafana' => '📊',
        'zabbix' => '🔍', 'odoo' => '🧾', 'openvpn' => '🔐', 'wireguard' => '🛡️',
    ];

    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function icon(): string
    {
        return self::ICONS[(string) $this->family] ?? ($this->kind === 'app' ? '🧩' : '💽');
    }

    /**
     * فهرستِ یکسان‌شده برای نمایش به مشتری — بی‌تکرار، بی‌نامِ ارائه‌دهنده.
     *
     * فقط ایمیج‌هایی می‌آیند که **دستِ‌کم یکی** از ارائه‌دهنده‌ها داشته باشد؛
     * چون تحویل روی ارائه‌دهندهٔ ارزان‌ترِ موجود انجام می‌شود، در `forProvider()`
     * دوباره فیلتر می‌شود تا گزینهٔ نشدنی به مشتری نشان داده نشود.
     *
     * @return \Illuminate\Support\Collection<int, CloudImage>
     */
    public static function catalog(string $kind = 'os', ?string $provider = null)
    {
        return static::query()
            ->usable()
            ->where('kind', $kind)
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->orderBy('family')
            ->orderByDesc('version')
            ->get()
            ->unique('key')
            ->values();
    }

    /** شناسهٔ بومیِ این کلید نزدِ یک ارائه‌دهنده — قلبِ ترجمهٔ سفیدبرچسب */
    public static function refFor(string $provider, string $key): ?string
    {
        return static::query()
            ->usable()
            ->where('provider', $provider)
            ->where('key', $key)
            ->value('provider_ref');
    }
}
