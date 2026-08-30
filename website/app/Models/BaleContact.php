<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * نگاشت شمارهٔ موبایل به chat_id بله.
 * وقتی کاربر ربات را استارت و شماره‌اش را share می‌کند، وب‌هوک این را می‌سازد.
 */
class BaleContact extends Model
{
    protected $fillable = ['mobile', 'chat_id', 'name', 'linked_at'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }

    /** chat_id برای یک شماره — null یعنی کاربر بله را وصل نکرده */
    public static function chatIdFor(string $mobile): ?string
    {
        return static::where('mobile', $mobile)->value('chat_id');
    }
}
