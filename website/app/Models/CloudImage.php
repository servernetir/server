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
     * خانواده‌هایی که لوگوی SVGِ خودمیزبان دارند: `public/assets/os/{family}.svg`.
     *
     * چرا SVGِ خودمیزبان و نه PNGِ خارجی: CSP منابعِ بیرونی را بی‌صدا بلاک می‌کند
     * (`img-src 'self'`)، و PNGِ رَستر در رتینا محو می‌شود. SVG همان «لوگوی
     * تصویریِ هم‌اندازه»‌ای است که کارفرما خواست، ولی خط‌تیز در هر مقیاس و بی‌هیچ
     * درخواستِ بیرونی. هر خانوادهٔ ناموجود به لوگوی عمومی می‌افتد، نه ۴۰۴.
     */
    public const LOGOS = [
        'ubuntu', 'debian', 'fedora', 'opensuse', 'linux',
        'centos', 'rocky', 'almalinux', 'alpine', 'arch',
        'windows', 'freebsd', 'app',
        'cpanel', 'plesk', 'mysql', 'postgresql', 'redis', 'mongodb',
        'docker', 'wordpress', 'nginx', 'gitlab', 'nextcloud', 'n8n',
    ];

    /**
     * نشانیِ لوگوی تصویریِ این ایمیج — همیشه هم‌اندازه، خودمیزبان.
     *
     * ریشه‌نسبی (`/assets/...`) عمداً: مستقل از پیشوندِ زبان و APP_URL درست
     * می‌مانَد. خانوادهٔ بی‌لوگو به `linux` (سیستم‌عامل) یا `app` (نرم‌افزار)
     * می‌افتد تا هیچ‌گاه تصویرِ شکسته نشود.
     */
    public function logo(): string
    {
        $fam = (string) $this->family;

        if (in_array($fam, self::LOGOS, true)) {
            return "/assets/os/{$fam}.svg";
        }

        return '/assets/os/'.($this->kind === 'app' ? 'app' : 'linux').'.svg';
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

    /**
     * شناسهٔ بومیِ این کلید نزدِ یک ارائه‌دهنده — قلبِ ترجمهٔ سفیدبرچسب.
     *
     * `$arch` را همیشه از پلنی که سرور رویش ساخته می‌شود بده. یک کلید (مثلِ
     * `ubuntu-24.04`) نزدِ هتزنر **دو ردیف** دارد — x86 و arm — و اگر ردیفِ
     * معماریِ دیگر برگردد، زیرساخت سفارش را با «معماریِ ناسازگار» رد می‌کند:
     * پول گرفته شده و سرور تحویل نمی‌شود.
     *
     * اگر معماریِ خواسته‌شده نباشد **null** برمی‌گردد، نه ردیفِ ناجور؛ فراخوان
     * می‌تواند سراغِ زیرساختِ دیگری برود که همان سیستم‌عامل را دارد.
     */
    public static function refFor(string $provider, string $key, ?string $arch = null): ?string
    {
        $rows = static::query()
            ->usable()
            ->where('provider', $provider)
            ->where('key', $key)
            ->get(['provider_ref', 'arch']);

        if ($rows->isEmpty()) {
            return null;
        }

        if (! filled($arch)) {
            return (string) $rows->first()->provider_ref;
        }

        // اول تطبیقِ دقیق، بعد ایمیجِ بی‌قیدِ معماری (زیرساختی که معماری اعلام
        // نمی‌کند). ردیفِ معماریِ دیگر هرگز برنمی‌گردد.
        $match = $rows->firstWhere('arch', $arch)
            ?? $rows->first(fn ($r) => ! filled($r->arch));

        return $match !== null ? (string) $match->provider_ref : null;
    }
}
