<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک تلاشِ پرداختِ رمزارز روی یک آدرسِ مشخص، با مبلغِ قفل‌شده و مهلت.
 */
class CryptoPayment extends Model
{
    /** مهلتِ پرداخت. کوتاه‌تر یعنی نرخِ قفل‌شده کمتر از واقعیت فاصله می‌گیرد */
    public const WINDOW_MINUTES = 20;

    /**
     * ⚠️ کم‌پرداختِ قابلِ چشم‌پوشی.
     *
     * صرافی‌ها گاهی کارمزد را از خودِ مبلغ کم می‌کنند. سخت‌گیریِ مطلق یعنی
     * پرداختِ درست رد شود و تیکت بیاید؛ سخاوتِ زیاد یعنی ضرر. یک درصد،
     * مرزِ معقولی است و بقیه به بازبینیِ دستی می‌رود، نه به رد شدن.
     */
    public const TOLERANCE_BP = 100;   // ۱٪ = ۱۰۰ در ده‌هزار

    protected $fillable = [
        'invoice_id', 'customer_id', 'crypto_wallet_id', 'chain', 'asset', 'network',
        'address', 'amount_atomic', 'decimals', 'invoice_amount', 'invoice_currency',
        'rate_micro', 'received_atomic', 'txid', 'confirmations', 'status',
        'expires_at', 'confirmed_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount_atomic' => 'integer', 'received_atomic' => 'integer',
            'invoice_amount' => 'integer', 'rate_micro' => 'integer',
            'decimals' => 'integer', 'confirmations' => 'integer',
            'expires_at' => 'datetime', 'confirmed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CryptoWallet::class, 'crypto_wallet_id');
    }

    /** پرداخت‌هایی که واچر باید بپاید — باز و منقضی‌نشده */
    public function scopeWatchable(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending', 'seen'])->where('expires_at', '>', now()->subHours(2));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'seen'], true);
    }

    public function isExpired(): bool
    {
        return $this->isOpen() && $this->expires_at->isPast();
    }

    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    /** مبلغِ خوانا برای نمایش — «12.345678» */
    public function amountHuman(): string
    {
        return rtrim(rtrim(number_format(
            $this->amount_atomic / (10 ** $this->decimals), $this->decimals, '.', ''
        ), '0'), '.') ?: '0';
    }

    /**
     * آیا مبلغِ رسیده کافی است؟
     *
     * ⚠️ عمداً روی **مجموعِ رسیده** سنجیده می‌شود نه یک تراکنش، تا پرداختِ
     * دومرحله‌ای هم شمرده شود.
     */
    public function isPaidEnough(): bool
    {
        $min = (int) floor($this->amount_atomic * (10000 - self::TOLERANCE_BP) / 10000);

        return $this->received_atomic >= $min;
    }
}
