<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id', 'customer_id', 'gateway', 'currency_code', 'amount',
        'status', 'external_ref', 'ref_id', 'card_mask', 'fee', 'fee_type',
        'error_code', 'error_message', 'expires_at', 'paid_at', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'integer',
            'fee'        => 'integer',
            'expires_at' => 'datetime',
            'paid_at'    => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * آیا هنوز می‌شود این تلاش را تأیید کرد؟
     *
     * تلاشِ منقضی عمداً قابل تأیید نیست: اگر کاربر تب درگاه را یک ساعت باز
     * بگذارد و بعد پرداخت کند، ما دیگر فاکتور را با آن تسویه نمی‌کنیم و
     * پرداخت به بازرسی دستی می‌رود. تأیید خودکارِ یک پرداخت خیلی قدیمی
     * می‌تواند فاکتوری را ببندد که در این فاصله از راه دیگری تسویه شده.
     */
    public function isVerifiable(): bool
    {
        return in_array($this->status, ['pending', 'redirected'], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** شرحی که روی صورتحساب درگاه و پیامک بانک دیده می‌شود */
    public function description(): string
    {
        $invoice = $this->invoice;

        // در لحظهٔ نمایش ترجمه می‌شود (متنِ ذخیره‌شده نیست) — زبانِ صفحهٔ جاری
        return match ($invoice?->kind) {
            'topup' => __('ui.pay_desc_topup'),
            default => __('ui.pay_desc_invoice', ['n' => $invoice?->number ?? '']),
        };
    }
}
