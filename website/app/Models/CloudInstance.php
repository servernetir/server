<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * سرورِ ابریِ واقعیِ یک مشتری — ۱:۱ با `services`.
 *
 * رمزِ root فقط **رمزنگاری‌شده** ذخیره می‌شود و پرچمِ `password_seen` نگه می‌دارد
 * که یک‌بار به مشتری نشان داده شده یا نه؛ بعد از آن در پنل پنهان می‌ماند و مشتری
 * باید «رمزِ تازه بساز» بزند. دلیل: نگه‌داشتنِ رمزِ خواندنی در صفحهٔ همیشه‌باز،
 * با یک نشستِ رهاشده روی لپ‌تاپِ عمومی، سرور را می‌دهد به رهگذر.
 */
class CloudInstance extends Model
{
    protected $fillable = [
        'service_id', 'provider', 'provider_ref', 'location_code', 'image_key',
        'hostname', 'ipv4', 'ipv6', 'root_password_enc', 'password_seen',
        'status', 'last_error', 'specs', 'meta', 'synced_at',
    ];

    /** نامِ ارائه‌دهنده و رمز هرگز در JSON بیرون نمی‌روند */
    protected $hidden = ['provider', 'provider_ref', 'root_password_enc'];

    protected $casts = [
        'specs'         => 'array',
        'meta'          => 'array',
        'password_seen' => 'bool',
        'synced_at'     => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    // ───────────────────────── رمز ─────────────────────────

    public function setPassword(?string $plain): void
    {
        $this->root_password_enc = filled($plain) ? Crypt::encryptString($plain) : null;
        $this->password_seen = false;
    }

    /** رمزِ خام؛ null اگر نبود یا APP_KEY عوض شده بود */
    public function password(): ?string
    {
        if (blank($this->root_password_enc)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->root_password_enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasPassword(): bool
    {
        return filled($this->root_password_enc);
    }

    // ───────────────────────── نمایش ─────────────────────────

    public function statusLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();

        $map = [
            'fa' => [
                'running' => 'روشن', 'off' => 'خاموش', 'building' => 'در حالِ آماده‌سازی',
                'error' => 'خطا', 'deleted' => 'حذف‌شده', 'unknown' => 'نامشخص',
            ],
            'en' => [
                'running' => 'Running', 'off' => 'Powered off', 'building' => 'Building',
                'error' => 'Error', 'deleted' => 'Deleted', 'unknown' => 'Unknown',
            ],
            'tr' => [
                'running' => 'Çalışıyor', 'off' => 'Kapalı', 'building' => 'Hazırlanıyor',
                'error' => 'Hata', 'deleted' => 'Silindi', 'unknown' => 'Bilinmiyor',
            ],
        ];

        return $map[$locale][$this->status] ?? $map['fa'][$this->status] ?? (string) $this->status;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'running'  => '#34d399',
            'off'      => '#94a3b8',
            'building' => '#fbbf24',
            'error'    => '#ff6b6b',
            default    => '#64748b',
        };
    }

    /** آیا در حالتی است که عملیاتِ کاربر پذیرفته می‌شود؟ */
    public function isActionable(): bool
    {
        return in_array($this->status, ['running', 'off'], true) && filled($this->provider_ref);
    }

    public function location(): ?CloudLocation
    {
        return $this->location_code
            ? CloudLocation::where('code', $this->location_code)->first()
            : null;
    }
}
