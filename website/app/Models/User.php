<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasTwoFactor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'name_latin', 'email', 'password', 'role', 'phone_extension'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTwoFactor, Notifiable;

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

    /**
     * نامِ نمایشیِ این کارمند به زبانِ مخاطب.
     *
     * فارسی → `name` (فارسی)؛ en/tr → `name_latin`. اگر نامِ لاتین ثبت نشده،
     * `null` برمی‌گردد — نه نامِ فارسی: نامِ فارسی وسطِ رابطِ انگلیسی از
     * ننوشتنش بدتر است و فراخوان با `null` به برچسبِ عمومیِ «ServerNet
     * Support» سقوط می‌کند.
     */
    public function displayNameFor(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'fa') {
            $name = trim((string) $this->name);

            return $name === '' ? null : $name;
        }

        $latin = trim((string) ($this->name_latin ?? ''));

        return $latin === '' ? null : $latin;
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

            // دومرحله‌ای — رمزنگاری در سطحِ مدل، دقیقاً مثلِ Customer: مقدارِ
            // خام هرگز وارد دیتابیس نمی‌شود، پس دسترسیِ خواندنی به دیتابیس
            // (بکاپِ لورفته، phpMyAdmin) رازِ کسی را لو نمی‌دهد.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
