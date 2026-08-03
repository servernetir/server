<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * مکانِ سرورِ ابری — با کدِ **خودمان** (de-frankfurt)، نه شناسهٔ ارائه‌دهنده.
 *
 * چون کد از «کشور + شهر» ساخته می‌شود، اگر هر دو ارائه‌دهنده فرانکفورت داشته
 * باشند به یک ردیف می‌رسند و مشتری یک گزینه می‌بیند.
 */
class CloudLocation extends Model
{
    protected $fillable = [
        'code', 'country', 'city', 'label_fa', 'label_en', 'label_tr',
        'flag', 'latitude', 'longitude', 'is_active', 'sort',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    /** نامِ کشور به سه زبان — برای مکان‌هایی که برچسبِ دستی نخورده‌اند */
    public const COUNTRIES = [
        'DE' => ['fa' => 'آلمان',      'en' => 'Germany',        'tr' => 'Almanya',      'flag' => '🇩🇪'],
        'FI' => ['fa' => 'فینلاند',    'en' => 'Finland',        'tr' => 'Finlandiya',   'flag' => '🇫🇮'],
        'NL' => ['fa' => 'هلند',       'en' => 'Netherlands',    'tr' => 'Hollanda',     'flag' => '🇳🇱'],
        'US' => ['fa' => 'آمریکا',     'en' => 'United States',  'tr' => 'ABD',          'flag' => '🇺🇸'],
        'GB' => ['fa' => 'انگلیس',     'en' => 'United Kingdom', 'tr' => 'Birleşik Krallık', 'flag' => '🇬🇧'],
        'FR' => ['fa' => 'فرانسه',     'en' => 'France',         'tr' => 'Fransa',       'flag' => '🇫🇷'],
        'SE' => ['fa' => 'سوئد',       'en' => 'Sweden',         'tr' => 'İsveç',        'flag' => '🇸🇪'],
        'PL' => ['fa' => 'پلند',       'en' => 'Poland',         'tr' => 'Polonya',      'flag' => '🇵🇱'],
        'TR' => ['fa' => 'ترکیه',      'en' => 'Türkiye',        'tr' => 'Türkiye',      'flag' => '🇹🇷'],
        'RU' => ['fa' => 'روسیه',      'en' => 'Russia',         'tr' => 'Rusya',        'flag' => '🇷🇺'],
        'KZ' => ['fa' => 'قزاقستان',   'en' => 'Kazakhstan',     'tr' => 'Kazakistan',   'flag' => '🇰🇿'],
        'AM' => ['fa' => 'ارمنستان',   'en' => 'Armenia',        'tr' => 'Ermenistan',   'flag' => '🇦🇲'],
        'GE' => ['fa' => 'گرجستان',    'en' => 'Georgia',        'tr' => 'Gürcistan',    'flag' => '🇬🇪'],
        'AE' => ['fa' => 'امارات',     'en' => 'UAE',            'tr' => 'BAE',          'flag' => '🇦🇪'],
        'SG' => ['fa' => 'سنگاپور',    'en' => 'Singapore',      'tr' => 'Singapur',     'flag' => '🇸🇬'],
        'JP' => ['fa' => 'ژاپن',       'en' => 'Japan',          'tr' => 'Japonya',      'flag' => '🇯🇵'],
        'CH' => ['fa' => 'سوئیس',      'en' => 'Switzerland',    'tr' => 'İsviçre',      'flag' => '🇨🇭'],
        'AT' => ['fa' => 'اتریش',      'en' => 'Austria',        'tr' => 'Avusturya',    'flag' => '🇦🇹'],
        'IR' => ['fa' => 'ایران',      'en' => 'Iran',           'tr' => 'İran',         'flag' => '🇮🇷'],
    ];

    /** نامِ شهر به فارسی — بقیهٔ زبان‌ها همان لاتین را می‌بینند */
    public const CITIES_FA = [
        // شهرهای ایران (دیتاسنترهای آروان)
        'tehran' => 'تهران', 'isfahan' => 'اصفهان', 'urmia' => 'ارومیه',
        'shiraz' => 'شیراز', 'ahvaz' => 'اهواز', 'tabriz' => 'تبریز', 'mashhad' => 'مشهد',
        'frankfurt' => 'فرانکفورت', 'falkenstein' => 'فالکن‌اشتاین',
        'nuremberg' => 'نورنبرگ', 'helsinki' => 'هلسینکی',
        'amsterdam' => 'آمستردام', 'stockholm' => 'استکهلم',
        'london' => 'لندن', 'paris' => 'پاریس', 'warsaw' => 'ورشو',
        'istanbul' => 'استانبول', 'moscow' => 'مسکو',
        'saint-petersburg' => 'سن‌پترزبورگ', 'kazan' => 'کازان',
        'yekaterinburg' => 'یکاترینبورگ', 'novosibirsk' => 'نووسیبیرسک',
        'almaty' => 'آلماتی', 'yerevan' => 'ایروان', 'tbilisi' => 'تفلیس',
        'dubai' => 'دبی', 'singapore' => 'سنگاپور', 'tokyo' => 'توکیو',
        'ashburn' => 'اشبرن', 'los-angeles' => 'لس‌آنجلس',
        'new-york' => 'نیویورک', 'miami' => 'میامی', 'dallas' => 'دالاس',
        'hillsboro' => 'هیلزبورو', 'tehran' => 'تهران',
    ];

    /** برچسبِ نمایشی در زبانِ جاری: «آلمان — فرانکفورت» */
    public function label(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $manual = $this->{'label_'.$locale} ?? null;

        if (filled($manual)) {
            return $manual;
        }

        $country = self::COUNTRIES[strtoupper((string) $this->country)][$locale]
            ?? strtoupper((string) $this->country);

        $city = $this->cityLabel($locale);

        return $city === '' ? $country : $country.' — '.$city;
    }

    public function cityLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $slug = \App\Services\Cloud\CloudNaming::slug((string) $this->city);

        if ($locale === 'fa' && isset(self::CITIES_FA[$slug])) {
            return self::CITIES_FA[$slug];
        }

        return (string) $this->city;
    }

    public function flagEmoji(): string
    {
        return $this->flag
            ?: (self::COUNTRIES[strtoupper((string) $this->country)]['flag'] ?? '🏳️');
    }

    public function countryLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();

        return self::COUNTRIES[strtoupper((string) $this->country)][$locale]
            ?? strtoupper((string) $this->country);
    }

    public function plans()
    {
        return $this->hasMany(CloudPlan::class, 'location_code', 'code');
    }
}
