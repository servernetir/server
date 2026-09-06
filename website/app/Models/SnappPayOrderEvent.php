<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک تماس با اسنپ‌پی، همان‌طور که رخ داد.
 *
 * 🔴 برای `update` و `cancel` این تنها سندِ «چه کسی، کِی، چه چیزی» است.
 * هر دو برگشت‌ناپذیرند.
 */
class SnappPayOrderEvent extends Model
{
    /** همان دلیلِ SnappPayOrder — حدسِ خودکار `snapp_pay_order_events` می‌شود. */
    protected $table = 'snapppay_order_events';

    protected $fillable = ['snapppay_order_id', 'kind', 'ok', 'message', 'payload', 'created_by'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'payload' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SnappPayOrder::class, 'snapppay_order_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
