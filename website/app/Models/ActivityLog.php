<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * یک ردیف لاگ فعالیت. تغییرناپذیر — فقط created_at.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id', 'actor', 'action', 'description', 'ip', 'user_agent',
        'geo_cc', 'geo_country', 'geo_region',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * ثبت یک رویداد. best-effort و بی‌خطر: هرگز جریان اصلی را نمی‌شکند و اگر
     * جدول هنوز ساخته نشده باشد بی‌صدا رد می‌شود.
     */
    public static function record(
        ?int $customerId,
        string $action,
        string $description,
        ?Request $request = null,
        string $actor = 'customer',
    ): void {
        try {
            if (! Schema::hasTable('activity_logs')) {
                return;
            }

            $request ??= request();

            $row = [
                'customer_id' => $customerId,
                'actor'       => $actor,
                'action'      => $action,
                'description' => mb_substr($description, 0, 400),
                'ip'          => $request?->ip(),
                'user_agent'  => mb_substr((string) $request?->userAgent(), 0, 200) ?: null,
            ];

            // مکانِ IP فقط اگر ستون‌هایش ساخته شده باشند (روی سرورِ ازقبل‌مهاجرت
            // نکرده، این بخش بی‌صدا رد می‌شود و لاگ همچنان ثبت می‌شود).
            if (Schema::hasColumn('activity_logs', 'geo_cc')) {
                $geo = [];
                try {
                    $geo = app(\App\Services\GeoIp::class)->locate($request?->ip());
                } catch (\Throwable) {
                    // مکان‌یابی نباید لاگ را بشکند
                }
                $row['geo_cc']      = $geo['cc'] ?? null;
                $row['geo_country'] = $geo['country'] ?? null;
                $row['geo_region']  = $geo['region'] ?? null;
            }

            static::create($row);
        } catch (\Throwable) {
            // لاگ نباید خودش منبع خطا شود
        }
    }

    /** تجزیهٔ دستگاه/مرورگر از user-agent — برای نمایش */
    public function device(): array
    {
        return ua_parse($this->user_agent);
    }

    /** رشتهٔ مکان: پرچم + کشور (و استان)، یا '' اگر نداریم */
    public function geoLabel(): string
    {
        if (blank($this->geo_country)) {
            return '';
        }

        $place = $this->geo_region
            ? $this->geo_country.'، '.$this->geo_region
            : $this->geo_country;

        $flag = $this->geo_cc ? \App\Services\GeoIp::flag($this->geo_cc).' ' : '';

        return $flag.$place;
    }

    /** آیکن مناسب هر action برای نمایش */
    public function icon(): string
    {
        return match ($this->action) {
            'login'       => 'i-key',
            'payment'     => 'i-coins',
            'service'     => 'i-server',
            'password'    => 'i-lock',
            'bank_receipt', 'bank_approved' => 'i-db',
            default       => 'i-flow',
        };
    }
}
