<?php

namespace App\Models;

use App\Support\ExitCountries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * یک «آپ‌استریمِ اکسیت» — رله یا نودی که موتورِ اکسیتِ ایران از راهِ آن از کشور
 * خارج می‌شود. این مدل همان چیزی است که «افزودنِ SSH-VPN/VLESSِ تازه به زیرساختِ
 * اکسیت» را از پنل ممکن می‌کند.
 *
 *   • role=relay → آپ‌لینکِ فرار از DPI (مثلِ `servernet-relay-set add`). کشور ندارد.
 *   • role=exit  → اکسیتِ اختصاصیِ یک کشور (مثلِ `servernet-exit-set <cc> --ssh/--socks/--link`).
 *
 * 🔴 سفیدبرچسبی/امنیت: `secret` با cast=encrypted رمزنگاری می‌شود و در `$hidden`
 * است، پس هیچ `toArray()`/`toJson()` مقدارِ خام (کلیدِ SSH یا لینکِ vless) را لو
 * نمی‌دهد. تنها راهِ بیرون‌آمدنِ مقدارِ خام، {@see toAgentArray()} است که فقط
 * PullControllerِ توکن‌دار صدایش می‌زند — چون هاستِ ایران برای dial لازمش دارد.
 */
class ExitUpstream extends Model
{
    protected $fillable = [
        'name', 'role', 'type', 'country_code',
        'host', 'port', 'username', 'secret', 'sni',
        'enabled', 'priority', 'health', 'last_seen_at', 'last_latency_ms',
        'meta', 'note',
    ];

    /**
     * 🔴 `secret` هرگز از هیچ سریال‌سازی‌ای بیرون نمی‌آید. همان قاعده‌ی
     * `Server::$hidden` روی `api_token`: یک `toJson()` در جای اشتباه نباید
     * کلیدِ خصوصیِ رله یا لینکِ vless را لو بدهد.
     */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret'          => 'encrypted',   // خام هرگز ذخیره نمی‌شود
            'enabled'         => 'boolean',
            'port'            => 'integer',
            'priority'        => 'integer',
            'last_latency_ms' => 'integer',
            'last_seen_at'    => 'datetime',
            'meta'            => 'array',
        ];
    }

    /** نقش‌ها: رله‌ی آپ‌لینک، یا اکسیتِ اختصاصیِ کشوری */
    public const ROLES = ['relay', 'exit'];

    /** پروتکل‌های پشتیبانی‌شده‌ی آپ‌استریم */
    public const TYPES = ['ssh', 'socks', 'vless', 'trojan', 'wireguard'];

    /** پروتکل‌هایی که برای اتصال، host+port لازم دارند (نه لینکِ خودبسنده) */
    public const HOST_TYPES = ['ssh', 'socks', 'wireguard'];

    /** پروتکل‌هایی که کلِ اتصال داخلِ یک لینک/URI است (vless://…, trojan://…) */
    public const LINK_TYPES = ['vless', 'trojan'];

    public function isRelay(): bool
    {
        return $this->role === 'relay';
    }

    public function isExit(): bool
    {
        return $this->role === 'exit';
    }

    /** آیا این پروتکل host+port می‌خواهد (در برابرِ لینکِ خودبسنده)؟ */
    public function needsHost(): bool
    {
        return in_array($this->type, self::HOST_TYPES, true);
    }

    /**
     * آیا اعتبارنامه/کلید ذخیره شده؟ — فقط حضورِ مقدار را می‌سنجد و **رمزگشایی
     * نمی‌کند** (پس اگر APP_KEY عوض شده باشد هم صفحه ۵۰۰ نمی‌شود). مقدارِ خام در
     * `$attributes` همان ciphertext است، چه تازه ست شده باشد چه از DB آمده باشد.
     */
    public function hasSecret(): bool
    {
        return filled($this->getAttributes()['secret'] ?? null);
    }

    /**
     * مقدارِ خامِ اعتبارنامه، یا null اگر نبود / رمزگشایی نشد (APP_KEY عوض شده).
     * تنها مصرف‌کننده {@see toAgentArray()} است.
     */
    public function secretPlain(): ?string
    {
        if (! $this->hasSecret()) {
            return null;
        }

        try {
            return $this->secret;               // cast رمزگشایی می‌کند
        } catch (\Throwable) {
            return null;                        // مثلِ Setting::getSecret — fail-safe
        }
    }

    /** کدِ کشورِ مؤثر (lowercase) یا null برای رله */
    public function cc(): ?string
    {
        $cc = strtolower(trim((string) $this->country_code));

        return $cc !== '' ? $cc : null;
    }

    public function countryName(?string $locale = 'fa'): string
    {
        $cc = $this->cc();

        if ($cc === null) {
            return '—';
        }

        $iso = strtoupper($cc);
        $c = CloudLocation::COUNTRIES[$iso] ?? null;

        return $c[$locale ?: 'fa'] ?? $c['fa'] ?? $iso;
    }

    public function flag(): string
    {
        $cc = $this->cc();

        if ($cc === null) {
            return '🔗';   // رله = آپ‌لینک، بی‌کشور
        }

        return CloudLocation::COUNTRIES[strtoupper($cc)]['flag'] ?? '🏳️';
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'relay' => 'رله (آپ‌لینک)',
            'exit'  => 'اکسیتِ کشوری',
            default => (string) $this->role,
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'ssh'       => 'SSH',
            'socks'     => 'SOCKS5',
            'vless'     => 'VLESS',
            'trojan'    => 'Trojan',
            'wireguard' => 'WireGuard',
            default     => (string) $this->type,
        };
    }

    /** «host:port» برای نمایش (بدونِ لو دادنِ اعتبارنامه) */
    public function endpointLabel(): string
    {
        $host = trim((string) $this->host);

        if ($host === '') {
            return $this->hasSecret() && in_array($this->type, self::LINK_TYPES, true)
                ? 'لینک'          // vless/trojan: اتصال داخلِ لینک است
                : '—';
        }

        return $this->port ? $host.':'.$this->port : $host;
    }

    /** @return array{0:string,1:string} برچسب و رنگِ سلامت */
    public function healthBadge(): array
    {
        return match ($this->health) {
            'up'    => ['زنده', '#34d399'],
            'down'  => ['خاموش', '#ff6b6b'],
            default => ['نامعلوم', '#96a3ba'],
        };
    }

    // ─────────────────────────── scopes ───────────────────────────

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('enabled', true);
    }

    public function scopeRelays(Builder $q): Builder
    {
        return $q->where('role', 'relay');
    }

    public function scopeExits(Builder $q): Builder
    {
        return $q->where('role', 'exit');
    }

    public function scopeForCountry(Builder $q, string $cc): Builder
    {
        return $q->where('role', 'exit')->where('country_code', strtolower(trim($cc)));
    }

    /**
     * شکلی که endpointِ توکن‌دارِ هاست می‌گیرد — **شاملِ مقدارِ خامِ `secret`**،
     * چون میزبانِ ایران برای dial لازمش دارد. این تنها جایی است که `secret` را
     * بیرون می‌دهد؛ همه‌جای دیگر `$hidden` نگهش می‌دارد.
     *
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        $out = [
            'id'       => $this->id,
            'name'     => (string) $this->name,
            'type'     => (string) $this->type,
            'priority' => (int) $this->priority,
        ];

        if ($this->isExit()) {
            $out['cc'] = $this->cc();
        }

        if (filled($this->host)) {
            $out['host'] = (string) $this->host;
        }

        if ($this->port) {
            $out['port'] = (int) $this->port;
        }

        if (filled($this->username)) {
            $out['username'] = (string) $this->username;
        }

        if (filled($this->sni)) {
            $out['sni'] = (string) $this->sni;
        }

        // مقدارِ خام فقط این‌جا (کلیدِ SSH / لینکِ vless / رمز)
        $secret = $this->secretPlain();

        if ($secret !== null) {
            $out['secret'] = $secret;
        }

        return $out;
    }

    /**
     * آیا این کشور مجازِ اکسیت است؟ (همان گیتِ `ExitCountries`) — برای اعتبارسنجی.
     */
    public static function acceptsCountry(?string $cc): bool
    {
        return ExitCountries::allows($cc);
    }
}
