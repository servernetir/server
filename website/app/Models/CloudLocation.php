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

    /**
     * پایتختِ هر کشور — پشتیبانِ نامِ شهر وقتی زیرساخت شهر را نمی‌دهد.
     *
     * 🔴 چرا لازم شد: بعضی زیرساخت‌ها فقط کشور را اعلام می‌کنند. تا دیروز آن
     * ردیف‌ها یا ستونِ مکانِ خالی داشتند یا — بدتر — نامِ ردهٔ محصول («AMD»،
     * «Shared») در ستونِ شهر می‌نشست. هیچ‌کدام به مشتری نمی‌گفت سرورش کجا بالا
     * می‌آید، و ستونِ مکان مهم‌ترین ستونِ این جدول است چون تأخیرِ شبکه را
     * تعیین می‌کند.
     *
     * ⚠️ این یک **تقریب** است، نه ادعای دقیق: می‌گوید «جایی در این کشور» و
     * پایتخت را به‌عنوان شناخته‌شده‌ترین نقطه می‌نویسد. اگر روزی زیرساخت شهرِ
     * واقعی را داد، همان برنده می‌شود.
     */
    public const CAPITALS = [
        'DE' => ['fa' => 'برلین',    'en' => 'Berlin',     'tr' => 'Berlin'],
        'FI' => ['fa' => 'هلسینکی',  'en' => 'Helsinki',   'tr' => 'Helsinki'],
        'NL' => ['fa' => 'آمستردام', 'en' => 'Amsterdam',  'tr' => 'Amsterdam'],
        'US' => ['fa' => 'واشینگتن', 'en' => 'Washington', 'tr' => 'Washington'],
        'GB' => ['fa' => 'لندن',     'en' => 'London',     'tr' => 'Londra'],
        'FR' => ['fa' => 'پاریس',    'en' => 'Paris',      'tr' => 'Paris'],
        'SE' => ['fa' => 'استکهلم',  'en' => 'Stockholm',  'tr' => 'Stockholm'],
        'PL' => ['fa' => 'ورشو',     'en' => 'Warsaw',     'tr' => 'Varşova'],
        'TR' => ['fa' => 'آنکارا',   'en' => 'Ankara',     'tr' => 'Ankara'],
        'RU' => ['fa' => 'مسکو',     'en' => 'Moscow',     'tr' => 'Moskova'],
        'KZ' => ['fa' => 'آستانه',   'en' => 'Astana',     'tr' => 'Astana'],
        'AM' => ['fa' => 'ایروان',   'en' => 'Yerevan',    'tr' => 'Erivan'],
        'GE' => ['fa' => 'تفلیس',    'en' => 'Tbilisi',    'tr' => 'Tiflis'],
        'AE' => ['fa' => 'ابوظبی',   'en' => 'Abu Dhabi',  'tr' => 'Abu Dabi'],
        'SG' => ['fa' => 'سنگاپور',  'en' => 'Singapore',  'tr' => 'Singapur'],
        'JP' => ['fa' => 'توکیو',    'en' => 'Tokyo',      'tr' => 'Tokyo'],
        'CH' => ['fa' => 'زوریخ',    'en' => 'Zurich',     'tr' => 'Zürih'],
        'AT' => ['fa' => 'وین',      'en' => 'Vienna',     'tr' => 'Viyana'],
        'IR' => ['fa' => 'تهران',    'en' => 'Tehran',     'tr' => 'Tahran'],
        'CA' => ['fa' => 'تورنتو',   'en' => 'Toronto',    'tr' => 'Toronto'],
        'ES' => ['fa' => 'مادرید',   'en' => 'Madrid',     'tr' => 'Madrid'],
        'IT' => ['fa' => 'میلان',    'en' => 'Milan',      'tr' => 'Milano'],
        'CZ' => ['fa' => 'پراگ',     'en' => 'Prague',     'tr' => 'Prag'],
        'UA' => ['fa' => 'کی‌یف',    'en' => 'Kyiv',       'tr' => 'Kiev'],
        'IN' => ['fa' => 'مومبای',   'en' => 'Mumbai',     'tr' => 'Mumbai'],
        'AU' => ['fa' => 'سیدنی',    'en' => 'Sydney',     'tr' => 'Sidney'],
        'BR' => ['fa' => 'سائوپائولو', 'en' => 'São Paulo', 'tr' => 'São Paulo'],
        'HK' => ['fa' => 'هنگ‌کنگ',  'en' => 'Hong Kong',  'tr' => 'Hong Kong'],
    ];

    /**
     * واژه‌هایی که زیرساخت‌ها به‌جای نامِ شهر می‌فرستند.
     *
     * ⚠️ سینک از این‌جا به بعد تمیز است، ولی ردیف‌های غلط از قبل در دیتابیس
     * نشسته‌اند و تا اجرای `cloud:sync` پاک نمی‌شوند. این نگهبان کاری می‌کند
     * که آن ردیف‌ها **همین حالا** هم به مشتری نشان داده نشوند.
     */
    private const NOT_A_CITY = [
        'shared', 'dedicated', 'amd', 'intel', 'premium', 'standard', 'basic',
        'nvme', 'ssd', 'hdd', 'cloud', 'vps', 'vds', 'general', 'epyc', 'ryzen',
        'xeon', 'arm', 'x86', 'gpu', 'storage', 'business', 'pro', 'starter',
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
        $raw = trim((string) $this->city);

        // نامِ ردهٔ محصول شهر نیست — پایتخت را بنویس، نه «AMD»
        if ($raw === '' || in_array(strtolower($raw), self::NOT_A_CITY, true)) {
            return $this->capitalLabel($locale);
        }

        $slug = \App\Services\Cloud\CloudNaming::slug($raw);

        if ($locale === 'fa' && isset(self::CITIES_FA[$slug])) {
            return self::CITIES_FA[$slug];
        }

        return $raw;
    }

    /**
     * کلیدِ **نمایشیِ** «این ردیف کدام شهر است؟» — فقط برای گروه‌بندیِ کارت‌ها.
     *
     * 🔴 ریشهٔ شهرهای تکراری (و چرا کلید از **شناسه** می‌آید نه از برچسب):
     * ردیف‌ها تکراری نیستند — `cloud_locations.code` یکتا است و پرس‌وجو
     * `unique()` می‌خورد. تکرار سرِ **رندر** ساخته می‌شود: `cityLabel()` هر جا
     * `city` خالی باشد یا در NOT_A_CITY بیفتد، `capitalLabel()` را برمی‌گرداند،
     * پس `de-ref` و `de-amd` و `de-shared` هر سه «برلین» چاپ می‌شوند.
     *
     * ⚠️ و دقیقاً برای همین، گروه‌بندی روی **برچسب** فاجعه است: در ایران
     * `ir-ref` (شهر خالی) هم «تهران» چاپ می‌شود، ولی تهران نیست. ادغامش با
     * `ir-tehran` یعنی فروختنِ دیتاسنتری در جای دیگر و پاک‌کردنِ شواهدِ خرابیِ
     * پارس. پس ردیفِ بی‌شهر کلیدِ **مخصوصِ خودش** (`#code`) می‌گیرد و هرگز در
     * کارتِ پایتخت حل نمی‌شود.
     *
     * هیچ شناسه‌ای این‌جا بازنویسی نمی‌شود: خروجی فقط یک کلیدِ گروه است.
     */
    public function cityIdentity(): string
    {
        $raw = trim((string) $this->city);

        if ($raw !== '' && ! in_array(mb_strtolower($raw, 'UTF-8'), self::NOT_A_CITY, true)) {
            return 'c:'.\App\Services\Cloud\CloudNaming::cityFold($raw);
        }

        return '#'.(string) $this->code;
    }

    /** پایتختِ کشورِ این مکان؛ اگر کشور هم ناشناس بود، رشتهٔ خالی */
    public function capitalLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $country = strtoupper((string) $this->country);

        return self::CAPITALS[$country][$locale] ?? self::CAPITALS[$country]['en'] ?? '';
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
