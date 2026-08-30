<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'title', 'description', 'quantity',
        'unit_price', 'line_total', 'tax_rate_bp', 'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'unit_price'  => 'integer',
            'line_total'  => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_amount'  => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
