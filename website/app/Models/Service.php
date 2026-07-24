<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * یک سرویس فروخته‌شده به مشتری (اشتراک یا خرید یک‌بار).
 */
class Service extends Model
{
    protected $fillable = [
        'customer_id', 'name', 'description', 'currency_code', 'price',
        'tax_percent', 'cycle', 'status', 'next_due_at', 'activated_at',
        'cancelled_at', 'created_by',
        // تحویل/فراهم‌سازی
        'server_id', 'plan', 'username', 'domain', 'password', 'panel_url',
        'provision_status', 'provision_error', 'provisioned_at', 'provision_meta',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'integer',
            'tax_percent'    => 'integer',
            'next_due_at'    => 'date',
            'activated_at'   => 'datetime',
            'cancelled_at'   => 'datetime',
            'password'       => 'encrypted',   // رمزِ کنترل‌پنل — هرگز خام
            'provisioned_at' => 'datetime',
            'provision_meta' => 'array',
        ];
    }

    public const CYCLES = ['once', 'monthly', 'quarterly', 'yearly'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** سرویسی که باید روی سروری تحویل شود (نه یک خدمتِ صرفاً مالی مثل پشتیبانی) */
    public function needsProvisioning(): bool
    {
        return $this->server_id !== null && $this->provision_status !== 'done';
    }

    /** برچسبِ وضعیتِ تحویل برای نمایش @return array{0:string,1:string} */
    public function provisionBadge(): array
    {
        return match ($this->provision_status) {
            'done'    => ['تحویل شد', '#34d399'],
            'running' => ['در حال ساخت', '#22d3ee'],
            'pending' => ['در صف تحویل', '#fbbf24'],
            'manual'  => ['در انتظار تحویل دستی', '#fbbf24'],
            'failed'  => ['خطا در تحویل', '#ff6b6b'],
            default   => ['—', '#96a3ba'],
        };
    }

    /** مالیات این دوره بر حسب واحد فرعی */
    public function taxAmount(): int
    {
        return (int) round($this->price * $this->tax_percent / 100);
    }

    /** مبلغ کل هر دوره (خدمت + مالیات) */
    public function total(): int
    {
        return $this->price + $this->taxAmount();
    }

    public function isRecurring(): bool
    {
        return $this->cycle !== 'once';
    }

    /**
     * سررسید بعدی از یک مبدأ، بر اساس دوره.
     * برای «یک‌بار» سررسیدی نیست (null).
     */
    public function nextDueFrom(Carbon $from): ?Carbon
    {
        return match ($this->cycle) {
            'monthly'   => $from->copy()->addMonthNoOverflow(),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3),
            'yearly'    => $from->copy()->addYearNoOverflow(),
            default     => null,
        };
    }

    public function cycleLabel(): string
    {
        return match ($this->cycle) {
            'monthly'   => 'ماهانه',
            'quarterly' => 'سه‌ماهه',
            'yearly'    => 'سالانه',
            default     => 'یک‌بار',
        };
    }

    /** @return array{0:string,1:string} برچسب و رنگ */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'active'             => ['فعال', '#34d399'],
            'pending'            => ['منتظر پرداخت', '#fbbf24'],
            'awaiting_provision' => ['در حال آماده‌سازی', '#22d3ee'],
            'provision_failed'   => ['خطا در تحویل', '#ff6b6b'],
            'suspended'          => ['معلق', '#ff6b6b'],
            'cancelled'          => ['لغو شده', '#5f6c82'],
            'expired'            => ['منقضی', '#96a3ba'],
            default              => [$this->status, '#96a3ba'],
        };
    }
}
