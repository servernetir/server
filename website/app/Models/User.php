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

    /**
     * نقش‌های کارمندی و برچسبشان.
     *
     * ⚠️ `support` نقشِ تازه است ولی **هیچ مهاجرتی نمی‌خواهد**: ستونِ `role`
     * رشته است و مقدارِ تازه فقط یک مقدارِ دیگر است. اعتبارسنجیِ فرمِ کاربران
     * از همین ثابت می‌خوانَد تا اگر روزی نقشی اضافه شد، فرم و گاردها با هم
     * جلو بروند.
     */
    public const ROLES = [
        'admin'   => 'مدیر',
        'support' => 'پشتیبان',
        'author'  => 'نویسنده',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSupport(): bool
    {
        return $this->role === 'support';
    }

    /**
     * «کارمندِ پشتیبانی» — مدیر یا پشتیبان.
     *
     * 🔴 چرا مدیر هم هست: هر جایی که پشتیبان اجازه دارد، مدیر هم باید اجازه
     * داشته باشد. اگر این متد فقط `support` را می‌سنجید، افزودنِ گاردِ تازه به
     * یک صفحهٔ پشتیبانی، خودِ مدیر را از آن بیرون می‌انداخت — و آن خرابی در
     * تستِ نقشِ پشتیبان دیده نمی‌شد.
     *
     * ⚠️ نقشِ `author` عمداً این‌جا نیست: نویسنده برای بلاگ ساخته می‌شود و
     * پروندهٔ مالیِ مشتریان به او ربطی ندارد.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isSupport();
    }

    public function roleLabel(): string
    {
        return self::ROLES[(string) $this->role] ?? (string) $this->role;
    }

    /**
     * کسانی که می‌شود پاسخِ تیکت را به نامشان ثبت کرد.
     *
     * ⚠️ فقط کارمندانِ پشتیبانی — نه نویسنده. نامِ کسی که هرگز تیکت جواب
     * نمی‌دهد در فهرستِ «به نامِ چه کسی» فقط اشتباهِ کلیکی می‌سازد.
     */
    public static function staffMembers()
    {
        return static::query()
            ->whereIn('role', ['admin', 'support'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
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
