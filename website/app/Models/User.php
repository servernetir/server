<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'phone_extension'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * شمارهٔ شخصیِ این کارمند برای Click-to-Call — شماره‌ای که **اول** زنگ
     * می‌خورد و بعد به مشتری وصل می‌شود.
     *
     * ⚠️ اختیاری است. اگر خالی باشد `CLOUD_PHONE_AGENT_NUMBER` استفاده
     * می‌شود، پس دکمهٔ تماس برای همه کار می‌کند. این ستون برای روزی است که
     * تیم چند نفره شود و تماسِ هر کس باید از تلفنِ خودش برود.
     *
     * ⚠️ رشتهٔ خالی همان «ندارد» است. بی‌این نرمال‌سازی یک فیلدِ فرمِ خالی
     * به‌صورتِ `''` ذخیره می‌شود، `!== null` می‌شود، و پیش‌فرضِ سراسری بی‌صدا
     * دور زده می‌شود.
     */
    public function phoneExtension(): ?string
    {
        $ext = trim((string) ($this->phone_extension ?? ''));

        return $ext === '' ? null : $ext;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
