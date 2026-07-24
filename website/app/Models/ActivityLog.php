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

            static::create([
                'customer_id' => $customerId,
                'actor'       => $actor,
                'action'      => $action,
                'description' => mb_substr($description, 0, 400),
                'ip'          => $request?->ip(),
                'user_agent'  => mb_substr((string) $request?->userAgent(), 0, 200) ?: null,
            ]);
        } catch (\Throwable) {
            // لاگ نباید خودش منبع خطا شود
        }
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
