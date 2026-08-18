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
     * داخلیِ تلفن ابریِ این کارمند — بدونِ آن Click-to-Call ممکن نیست.
     *
     * ⚠️ رشتهٔ خالی همان «ندارد» است. بی‌این نرمال‌سازی، یک فیلدِ فرمِ خالی
     * به‌صورتِ `''` ذخیره می‌شود، `!== null` می‌شود، دکمهٔ تماس فعال می‌مانَد و
     * تماس با خطای مبهمِ تأمین‌کننده شکست می‌خورد.
     */
    public function phoneExtension(): ?string
    {
        $ext = trim((string) ($this->phone_extension ?? ''));

        return $ext === '' ? null : $ext;
    }

    public function canPlaceCalls(): bool
    {
        return $this->phoneExtension() !== null;
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
