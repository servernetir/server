<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'title', 'description', 'quantity',
        'unit_price', 'line_total', 'discount', 'tax_rate_bp', 'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'unit_price'  => 'integer',
            'line_total'  => 'integer',
            'discount'    => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_amount'  => 'integer',
        ];
    }

    /**
     * مبلغِ خالصِ این ردیف — مأخذِ واقعیِ مالیات.
     *
     * ⚠️ هرجا تخفیفِ ردیفی می‌گذارید، `tax_amount` باید از همین بیاید نه از
     * `line_total`؛ وگرنه از مشتری مالیاتِ پولی گرفته‌ایم که نگرفته‌ایم.
     */
    public function netTotal(): int
    {
        return max(0, (int) $this->line_total - (int) $this->discount);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
