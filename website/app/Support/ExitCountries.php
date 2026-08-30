<?php

namespace App\Support;

use App\Models\CloudLocation;
use App\Models\Setting;

/**
 * منبعِ یکتای «کشورهای خروجِ مجاز» برای سوییچِ اکسیت.
 *
 * ═══ چرا این کلاس ═══
 *
 * سه‌جا باید بدانند یک ماشین را می‌شود به کدام کشورها فرستاد: پنلِ مدیریت
 * (منوی انتخاب)، پنلِ مشتری (همان منو)، و اعتبارسنجیِ درخواست. اگر هر سه از
 * روی یک لیستِ سخت‌کدشده بخوانند، روزی که کشوری اضافه/کم شود دو جا یادمان
 * می‌رود. پس همه از این‌جا می‌خوانند.
 *
 * ⚠️ «مجاز» یعنی میزبانِ ایران **واقعاً** برایش تونل/pool دارد — همان
 * `proxmox_exit_countries` (پیش‌فرض `de,nl,fi`). انتخابِ کشوری بی‌تونل فقط
 * یعنی خروجِ آن ماشین می‌شکند؛ پس اصلاً در منو نمی‌آید.
 *
 * ⚠️ کدِ ویژهٔ `ir` = «بدونِ اکسیت» (خروجِ عادیِ ایران). این‌جا یک کشورِ مقصد
 * نیست؛ یک گزینهٔ خاموش‌کردنِ اکسیت است و در {@see options()} جدا افزوده می‌شود،
 * ولی هرگز در {@see codes()} نمی‌آید.
 */
class ExitCountries
{
    /** کدِ ویژه‌ای که یعنی «اکسیت را خاموش کن، از ایران خارج شو» */
    public const NONE = 'ir';

    /**
     * کدهای کشورِ خروجِ مجاز (lowercase)، مثلِ `['de','nl','fi']`.
     *
     * از Setting `proxmox_exit_countries` می‌خوانَد (همان منبعی که صفحهٔ
     * «زیرساختِ اکسیت» نشان می‌دهد). `ir` و مقادیرِ خالی پاک می‌شوند.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        $raw = (string) (Setting::get('proxmox_exit_countries') ?: 'de,nl,fi');

        return collect(explode(',', $raw))
            ->map(fn ($c) => strtolower(trim($c)))
            ->filter(fn ($c) => $c !== '' && $c !== self::NONE)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * مثلِ `options()` ولی **بدونِ** ردیفِ «بدونِ اکسیت (ایران)».
     *
     * فرم‌هایی که خودشان گزینهٔ خالی را دستی می‌گذارند (وارد کردنِ ماشین، و
     * فرمِ آپ‌استریم که اصلاً «ایران» برایش بی‌معنی است) این را می‌خواهند؛
     * وگرنه یک ردیفِ تکراری با `value=""` در منو ظاهر می‌شود.
     *
     * @return array<int, array{code:string, name:string, flag:string}>
     */
    public static function codeOptions(?string $locale = 'fa'): array
    {
        return array_values(array_filter(
            self::options($locale),
            fn (array $o) => ($o['code'] ?? '') !== self::NONE,
        ));
    }

    /** آیا این کد یک کشورِ خروجِ مجاز است؟ (بدونِ `ir`) */
    public static function allows(?string $cc): bool
    {
        return in_array(strtolower(trim((string) $cc)), self::codes(), true);
    }

    /**
     * آیا این ورودی یعنی «خاموش‌کردنِ اکسیت»؟ (`''`, `ir`, `none`)
     */
    public static function isNone(?string $cc): bool
    {
        $cc = strtolower(trim((string) $cc));

        return $cc === '' || $cc === self::NONE || $cc === 'none';
    }

    /**
     * آیا این ورودی یک انتخابِ **معتبر** است — یا خاموش‌کردن، یا یک کشورِ مجاز؟
     * برای اعتبارسنجیِ فرمِ سوییچ در هر دو پنل.
     */
    public static function accepts(?string $cc): bool
    {
        return self::isNone($cc) || self::allows($cc);
    }

    /**
     * گزینه‌های منوی انتخاب برای ویو: اول «بدونِ اکسیت (ایران)»، بعد هر کشورِ
     * مجاز با نام و پرچمِ فارسی.
     *
     * @return array<int, array{code:string, name:string, flag:string}>
     */
    public static function options(?string $locale = 'fa'): array
    {
        $locale = $locale ?: 'fa';

        $ir = CloudLocation::COUNTRIES['IR'] ?? ['fa' => 'ایران', 'flag' => '🇮🇷'];
        $out = [[
            'code' => self::NONE,
            'name' => 'بدونِ اکسیت ('.($ir[$locale] ?? $ir['fa'] ?? 'ایران').')',
            'flag' => $ir['flag'] ?? '🇮🇷',
        ]];

        foreach (self::codes() as $cc) {
            $iso = strtoupper($cc);
            $c = CloudLocation::COUNTRIES[$iso] ?? null;
            $out[] = [
                'code' => $cc,
                'name' => $c[$locale] ?? $c['fa'] ?? $iso,
                'flag' => $c['flag'] ?? '🏳️',
            ];
        }

        return $out;
    }
}
