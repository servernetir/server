<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * توکنِ APIِ مشتری. فقط هش ذخیره می‌شود؛ متنِ خام یک‌بار برگردانده می‌شود.
 */
class CustomerApiToken extends Model
{
    protected $fillable = [
        'customer_id', 'name', 'token_hash', 'abilities', 'last_used_at', 'last_used_ip',
    ];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function can(string $ability): bool
    {
        $a = $this->abilities ?? [];

        return in_array('*', $a, true) || in_array($ability, $a, true);
    }

    /**
     * صدور توکنِ تازه. خروجی: [مدل، متنِ خام]. متنِ خام فقط همین‌جا در دسترس
     * است و دیگر بازیابی نمی‌شود.
     *
     * @return array{0:self,1:string}
     */
    public static function issue(int $customerId, string $name, array $abilities = ['read']): array
    {
        $plain = 'sn_'.bin2hex(random_bytes(24));   // پیشوندِ برند + ۴۸ رقمِ hex

        $token = static::create([
            'customer_id' => $customerId,
            'name'        => $name,
            'token_hash'  => hash('sha256', $plain),
            'abilities'   => $abilities,
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        return static::where('token_hash', hash('sha256', $plain))->first();
    }
}
