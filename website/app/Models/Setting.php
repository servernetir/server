<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * تنظیم کلید-مقدار. get/set با کش سبک تا هر صفحه یک پرس‌وجوی جدا نزند.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * کلیدِ حافظهٔ درون‌درخواستی در کانتینر.
     *
     * عمداً کانتینر و نه `static` ساده: کانتینر در هر درخواست (و در هر تست) از
     * نو ساخته می‌شود، پس حافظه هرگز از یک درخواست به درخواستِ بعد یا از یک
     * تست به تستِ بعد نشت نمی‌کند.
     */
    private const MEMO = 'settings.request-memo';

    /**
     * همهٔ تنظیمات — **یک بار در هر درخواست**.
     *
     * 🔴 چرا این لایه لازم شد: `get()` در هر فراخوانی یک `Schema::hasTable()`
     * (روی MariaDB یعنی پرس‌وجو از `information_schema`) و یک خواندن از کش
     * می‌زد. تک‌تک بی‌ضررند، ولی `CloudPlan::trafficLabel()` برای **هر ردیفِ
     * پلن** صدا زده می‌شود — و صفحهٔ `/vps/iran` ‏۱۵۱ ردیف دارد (پنج شهرِ
     * ایران × ~۳۰ مشخصات)، هر ردیف دو بار. یعنی حدود ۳۰۰ رفت‌وبرگشتِ اضافه در
     * یک بازدید. سنجشِ محلی با فقط ۳۰ ردیف: ۶۵ پرس‌وجوی `hasTable` و ۶۶
     * خواندنِ کش برای یک صفحه.
     *
     * ⚠️ چرا این «فقط کندی» نیست: کش و نشست هر دو روی همان دیتابیس‌اند
     * (بخشِ «یک قطعیِ گذرای دیتابیس» در CLAUDE.md). هر یک از آن ۳۰۰ خواندن یک
     * فرصتِ مردن است؛ صفحه‌ای که ۳۰۰ برابرِ بقیه به دیتابیس دست می‌زند،
     * ۳۰۰ برابر بیشتر با ۵۰۰ (صفحهٔ سفید) روبه‌رو می‌شود. سنگین‌ترین صفحهٔ سایت
     * نباید شکننده‌ترینش هم باشد.
     *
     * ⚠️ نامش عمداً `all()` **نیست**: `Eloquent\Model::all($columns = ['*'])`
     * از قبل وجود دارد و بازتعریفِ ناسازگارش یک خطای **زمانِ کامپایل** است —
     * یعنی به‌محضِ بارگذاریِ کلاس کلِ پردازه می‌میرد، نه فقط این متد.
     *
     * @return array<string, string|null>
     */
    public static function cached(): array
    {
        $app = app();

        if ($app->bound(self::MEMO)) {
            return (array) $app->make(self::MEMO);
        }

        // نبودِ جدول عمداً حافظه‌دار نمی‌شود: مسیرِ `/system/migrate` می‌تواند
        // وسطِ همان درخواست جدول را بسازد و حافظهٔ «نبود» آن را نامرئی می‌کرد.
        if (! Schema::hasTable('settings')) {
            return [];
        }

        $all = (array) Cache::remember('settings.all', 300, fn () => static::pluck('value', 'key')->toArray());

        $app->instance(self::MEMO, $all);

        return $all;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::cached();

        return array_key_exists($key, $all) && $all[$key] !== null ? (string) $all[$key] : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
        app()->forgetInstance(self::MEMO);
    }

    /**
     * تنظیمِ محرمانه (توکنِ API و…) — رمزنگاری‌شده ذخیره می‌شود.
     *
     * مثلِ توکنِ WHM روی مدلِ Server: مقدارِ خام هرگز در دیتابیس و هرگز در
     * فرمِ برگشتی نیست. اگر خالی بفرستی، تنظیمِ قبلی پاک می‌شود.
     */
    public static function putSecret(string $key, ?string $plain): void
    {
        static::put($key, filled($plain) ? \Illuminate\Support\Facades\Crypt::encryptString($plain) : null);
    }

    /** مقدارِ خامِ یک تنظیمِ محرمانه؛ null اگر نبود یا کلیدِ رمز عوض شده بود */
    public static function getSecret(string $key): ?string
    {
        $v = static::get($key);

        if (blank($v)) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($v);
        } catch (\Throwable) {
            return null;                    // APP_KEY عوض شده یا مقدار دست‌کاری شده
        }
    }

    /** آیا مشخصات بانکی برای «واریز به حساب» کامل است؟ */
    public static function bankReady(): bool
    {
        return filled(static::get('bank_sheba')) || filled(static::get('bank_account'));
    }
}
