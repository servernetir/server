<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک سفارشِ اسنپ‌پی.
 *
 * ⚠️ `paymentToken` این‌جا ستون ندارد؛ همان `payment->external_ref` است.
 * یک کلید، یک نویسنده.
 */
class SnappPayOrder extends Model
{
    /**
     * ⚠️ نامِ جدول صریح است، چون حدسِ خودکارِ لاراول از `SnappPayOrder` به
     * `snapp_pay_orders` می‌رسد (روی هر حرفِ بزرگ می‌شکند) و مهاجرت
     * `snapppay_orders` ساخته. بدونِ این خط، همه‌چیز lint می‌شود و فقط سرِ
     * اولین کوئری «no such table» می‌دهد.
     */
    protected $table = 'snapppay_orders';

    protected $fillable = [
        'payment_id', 'invoice_id', 'customer_id', 'transaction_id',
        'state', 'remote_status', 'checked_at', 'amount', 'mobile', 'cart',
        'verified_at', 'settled_at', 'canceled_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'integer',
            'cart'        => 'array',
            'checked_at'  => 'datetime',
            'verified_at' => 'datetime',
            'settled_at'  => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SnappPayOrderEvent::class);
    }

    /** توکنِ پرداخت — تنها منبعش ردیفِ Payment است. */
    public function token(): ?string
    {
        return $this->payment?->external_ref;
    }

    public function isSettled(): bool
    {
        return $this->state === 'settled';
    }

    /** فقط سفارشِ نهایی‌شده را می‌شود کم کرد یا لغو کرد. */
    public function isChangeable(): bool
    {
        return $this->isSettled() && filled($this->token());
    }

    /**
     * ثبتِ یک تماس در ردِ حسابرسی.
     *
     * ⚠️ هرگز پرتاب نمی‌کند: ردِ حسابرسی نباید مسیرِ پول را بشکند. نبودِ یک
     * ردیفِ لاگ بد است؛ شکستنِ تسویه به‌خاطرش بدتر.
     */
    public function note(string $kind, bool $ok, ?string $message = null, ?array $payload = null, ?int $userId = null): void
    {
        try {
            $this->events()->create([
                'kind'       => $kind,
                'ok'         => $ok,
                'message'    => $message === null ? null : mb_substr($message, 0, 255),
                'payload'    => $payload,
                'created_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('payment', $e,
                ['area' => 'snapppay-event', 'order' => $this->id, 'kind' => $kind]);
        }
    }
}
