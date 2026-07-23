<?php

namespace App\Support;

/**
 * تشخیص بانک از روی شش رقم اول کارت.
 *
 * چرا محلی و نه از سرویس استعلام:
 *   • رایگان و آنی است؛ استعلام پول دارد و ممکن است در دسترس نباشد
 *   • املای برگشتی از سرویس ثابت نیست («بانک ملت» / «ملت» / «Mellat»)
 *   • حتی قبل از تأیید کارت هم می‌شود بانک را نشان داد
 *
 * اگر BIN ناشناخته بود، null برمی‌گردد و رابط به نامی که سرویس داده
 * برمی‌گردد — یعنی هیچ‌وقت بدتر از قبل نمی‌شود.
 */
final class IranianBank
{
    /**
     * @return array{slug:string,name:string,short:string,color:string}|null
     */
    public static function fromBin(?string $bin): ?array
    {
        if (blank($bin)) {
            return null;
        }

        $bin  = substr(preg_replace('/\D/', '', $bin) ?? '', 0, 6);
        $slug = config('banks.bins.'.$bin);

        return $slug === null ? null : self::bySlug($slug);
    }

    /** @return array{slug:string,name:string,short:string,color:string}|null */
    public static function bySlug(?string $slug): ?array
    {
        if (blank($slug)) {
            return null;
        }

        $b = config('banks.banks.'.$slug);

        if (! is_array($b)) {
            return null;
        }

        return [
            'slug'  => $slug,
            'name'  => app()->getLocale() === 'fa' ? $b['fa'] : $b['en'],
            'short' => app()->getLocale() === 'fa' ? $b['short'] : mb_substr($b['en'], 0, 2),
            'color' => $b['color'],
        ];
    }

    /**
     * بهترین نامی که می‌شود نشان داد.
     * اولویت با تشخیص محلی است چون املایش یکدست است؛ اگر نشد، هرچه سرویس داد.
     */
    public static function label(?string $bin, ?string $fallback = null): ?string
    {
        return self::fromBin($bin)['name'] ?? $fallback;
    }
}
