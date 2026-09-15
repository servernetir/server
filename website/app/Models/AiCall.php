<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سجلِ تماسِ AI — پاسخِ ضبط‌شدهٔ یک تماسِ سبزِ دروازه، برای بازپخش (M4-c).
 *
 * این مدل **نثر نمی‌نویسد و پول جابه‌جا نمی‌کند**: هیچ مسیرِ نوشتنی
 * غیر از ضبطِ پس ازِ تسویه در `AiCaller` ندارد و بازپخش فقط «خواندنِ
 * `response_body`» است — عینِ JSON ارائه‌دهنده، بی‌تفسیر. کلیدِ
 * (`customer_id`, `idempotency_key`) یونیک است؛ تماس‌هایِ بی‌کلید
 * (`idempotency_key` نال) ضبط می‌شوند ولی هرگز بازپخش‌پذیر نیستند.
 */
class AiCall extends Model
{
    protected $fillable = [
        'customer_id',
        'ai_reservation_id',
        'model_slug',
        'idempotency_key',
        'ok',
        'request_body',
        'response_body',
        'upstream_status',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'request_body' => 'array',
            'response_body' => 'array',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(AiReservation::class, 'ai_reservation_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
