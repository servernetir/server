<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * فهرستِ سیاه — «دیگر هرگز به این نشانی ننویس».
 *
 * 🔴 این جدول فقط رشد می‌کند. هیچ‌جای کد از آن حذف نمی‌کند و هیچ دکمه‌ای در پنل
 * برای پاک کردنش نیست. یک «no» یعنی هرگزِ دائمی — هم ادبِ کار است، هم الزامِ
 * CAN-SPAM و CASL که درخواستِ لغو باید برای همیشه محترم شمرده شود.
 *
 * دامنه هم نگه داشته می‌شود: اگر `info@x.com` لغو کرد، `sales@x.com` هم نباید
 * پیام بگیرد. همان شرکت است و همان آدم جواب می‌دهد.
 */
class CrmSuppression extends Model
{
    protected $table = 'crm_suppression';

    protected $fillable = ['email', 'domain', 'reason', 'note'];

    /**
     * آیا این نشانی (یا دامنه‌اش) در فهرستِ سیاه است؟
     * هر مسیرِ ارسال **باید** قبل از صف‌کردن این را صدا بزند.
     */
    public static function blocks(?string $email): bool
    {
        if (blank($email)) {
            return true;   // نشانیِ خالی = ارسال نکن
        }

        $email  = mb_strtolower(trim($email));
        $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : null;

        return static::where('email', $email)
            ->when($domain, fn ($q) => $q->orWhere('domain', $domain))
            ->exists();
    }

    /** افزودن به فهرست — اگر بود، دست نمی‌خورد (دلیلِ اولیه محفوظ می‌ماند). */
    public static function add(string $email, string $reason = 'unsubscribe', ?string $note = null): void
    {
        $email  = mb_strtolower(trim($email));
        if (! str_contains($email, '@')) {
            return;
        }
        $domain = substr(strrchr($email, '@'), 1);

        static::firstOrCreate(
            ['email' => $email],
            ['domain' => $domain, 'reason' => $reason, 'note' => $note],
        );
    }
}
