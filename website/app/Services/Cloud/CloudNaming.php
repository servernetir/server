<?php

namespace App\Services\Cloud;

/**
 * یکسان‌سازیِ نام‌ها بین ارائه‌دهنده‌ها — قلبِ «سفیدبرچسب بودن».
 *
 * هتزنر پلنش را `CX22` صدا می‌زند و آیزا `EPs-1`؛ هتزنر مکان را `fsn1` و آیزا با
 * شناسهٔ عددی. اگر همین‌ها را نشان دهیم، مشتری با یک جستجوی ساده می‌فهمد سرور از
 * کجاست. پس همه‌چیز به واژگانِ **خودمان** ترجمه می‌شود:
 *
 *   مکان   → `de-frankfurt`  (کشور + شهر)
 *   پلن    → `CV-2-4`        (مشخصات، نه نامِ ارائه‌دهنده)
 *   ایمیج  → `ubuntu-24.04`  (خانواده + نسخه)
 *
 * سودِ جانبی و مهم: چون کدِ مکان از **شهر** ساخته می‌شود، «فرانکفورتِ هتزنر» و
 * «فرانکفورتِ آیزا» خودبه‌خود یک مکان می‌شوند و مشتری یک گزینه می‌بیند. همین
 * برای پلن هم رخ می‌دهد (اسلاگِ مشخصات‌محور)، و ما آزادیم ارزان‌ترینِ موجود را
 * تحویل دهیم بدون آنکه چیزی در ظاهرِ سایت عوض شود.
 */
class CloudNaming
{
    /**
     * نامِ شهرها گاهی با املای محلی/مخفف می‌آید. این نگاشت باعث می‌شود دو
     * ارائه‌دهنده که یک شهر را با دو نام می‌نویسند، به یک کدِ واحد برسند.
     */
    private const CITY_ALIASES = [
        'frankfurt am main' => 'frankfurt',
        'frankfurt/main'    => 'frankfurt',
        'fra'               => 'frankfurt',
        'falkenstein'       => 'falkenstein',
        'nuremberg'         => 'nuremberg',
        'nürnberg'          => 'nuremberg',
        'nbg'               => 'nuremberg',
        'helsinki'          => 'helsinki',
        'hel'               => 'helsinki',
        'ashburn'           => 'ashburn',
        'ash'               => 'ashburn',
        'hillsboro'         => 'hillsboro',
        'hil'               => 'hillsboro',
        'singapore'         => 'singapore',
        'sin'               => 'singapore',
        'moscow'            => 'moscow',
        'moskva'            => 'moscow',
        'msk'               => 'moscow',
        'saint petersburg'  => 'saint-petersburg',
        'st. petersburg'    => 'saint-petersburg',
        'spb'               => 'saint-petersburg',
        'amsterdam'         => 'amsterdam',
        'ams'               => 'amsterdam',
        'stockholm'         => 'stockholm',
        'london'            => 'london',
        'lon'               => 'london',
        'paris'             => 'paris',
        'warsaw'            => 'warsaw',
        'istanbul'          => 'istanbul',
        'tokyo'             => 'tokyo',
        'los angeles'       => 'los-angeles',
        'new york'          => 'new-york',
        'miami'             => 'miami',
        'dallas'            => 'dallas',
        'kazan'             => 'kazan',
        'yekaterinburg'     => 'yekaterinburg',
        'novosibirsk'       => 'novosibirsk',
        'almaty'            => 'almaty',
        'yerevan'           => 'yerevan',
        'tbilisi'           => 'tbilisi',
        'dubai'             => 'dubai',
    ];

    /** خانوادهٔ نرم‌افزارهای آماده — عنوانِ بازاریابیِ ارائه‌دهنده را یکسان می‌کند */
    private const APP_ALIASES = [
        'docker ce'      => 'docker',
        'docker'         => 'docker',
        'wordpress'      => 'wordpress',
        'nextcloud'      => 'nextcloud',
        'plesk'          => 'plesk',
        'cpanel'         => 'cpanel',
        'jitsi meet'     => 'jitsi',
        'openlitespeed'  => 'openlitespeed',
        'lamp stack'     => 'lamp',
        'lemp'           => 'lemp',
        'nginx'          => 'nginx',
        'gitlab'         => 'gitlab',
        'jenkins'        => 'jenkins',
        'mongodb'        => 'mongodb',
        'mysql'          => 'mysql',
        'postgresql'     => 'postgresql',
        'redis'          => 'redis',
        'odoo'           => 'odoo',
        'prestashop'     => 'prestashop',
        'joomla'         => 'joomla',
        'drupal'         => 'drupal',
        'zabbix'         => 'zabbix',
        'grafana'        => 'grafana',
        'portainer'      => 'portainer',
        'coolify'        => 'coolify',
        'n8n'            => 'n8n',
        'supabase'       => 'supabase',
        'directadmin'    => 'directadmin',
        'wireguard'      => 'wireguard',
        'openvpn'        => 'openvpn',
        'marzban'        => 'marzban',
        'x-ui'           => 'xui',
        '3x-ui'          => 'xui',
    ];

    /** کدِ مکانِ ما: `de-frankfurt`. اگر شهر نبود، از شناسهٔ ارائه‌دهنده. */
    public static function locationCode(string $country, string $city, string $fallback): string
    {
        $country = strtolower(trim($country)) ?: 'xx';
        $city = self::normalizeCity($city !== '' ? $city : $fallback);

        return substr($country.'-'.$city, 0, 32);
    }

    private static function normalizeCity(string $city): string
    {
        $c = strtolower(trim($city));

        // مخففِ عددیِ هتزنر: fsn1 → fsn، nbg1 → nbg، hel1 → hel
        $c = preg_replace('/(\d+)$/', '', $c) ?: $c;
        $c = trim($c);

        if (isset(self::CITY_ALIASES[$c])) {
            return self::CITY_ALIASES[$c];
        }

        // fsn = Falkenstein — در نگاشت با نامِ کامل آمده، ولی مخففش نه
        $short = ['fsn' => 'falkenstein', 'nbg' => 'nuremberg'];
        if (isset($short[$c])) {
            return $short[$c];
        }

        return self::slug($c) ?: 'unknown';
    }

    /**
     * نامِ عمومیِ پلن از مشخصات — بی‌هیچ اشاره‌ای به ارائه‌دهنده.
     * `CV-2-4` = سرورِ ابری، ۲ هسته، ۴ گیگابایت رم.
     */
    public static function planName(int $vcpu, int $ramMb, string $cpuKind = 'shared'): string
    {
        $ram = self::gb($ramMb);
        $prefix = $cpuKind === 'dedicated' ? 'CVD' : 'CV';

        return $prefix.'-'.$vcpu.'-'.$ram;
    }

    /** اسلاگِ عمومیِ پلن = کلیدِ گروه. دو ارائه‌دهنده با مشخصاتِ یکسان → یک اسلاگ. */
    public static function planSlug(int $vcpu, int $ramMb, int $diskGb, string $locationCode, string $cpuKind = 'shared'): string
    {
        $ram = self::gb($ramMb);
        $prefix = $cpuKind === 'dedicated' ? 'cvd' : 'cv';

        return substr("{$prefix}-{$vcpu}c-{$ram}g-{$diskGb}d-{$locationCode}", 0, 96);
    }

    /** مگابایت → عددِ گیگابایتِ خوانا (۵۱۲ مگ → «0.5») */
    private static function gb(int $ramMb): string
    {
        $g = $ramMb / 1024;

        return $g < 1 ? rtrim(rtrim(number_format($g, 1, '.', ''), '0'), '.') : (string) (int) round($g);
    }

    /**
     * کلیدِ یکسانِ ایمیج.
     *
     * سیستم‌عامل: `ubuntu-24.04` · نرم‌افزار: `app-docker`
     * اگر خانواده/نسخه نداشتیم، از برچسب می‌سازیم (بهتر از هیچ).
     */
    public static function imageKey(string $kind, ?string $family, ?string $version, string $label): string
    {
        if ($kind === 'app') {
            return substr('app-'.self::appFamily($label), 0, 64);
        }

        $family = self::slug((string) $family);
        $version = self::slugVersion((string) $version);

        if ($family !== '' && $version !== '') {
            return substr($family.'-'.$version, 0, 64);
        }

        return substr(self::slug($label) ?: 'os', 0, 64);
    }

    /** خانوادهٔ نرم‌افزارِ آماده از برچسبِ بازاریابی */
    public static function appFamily(string $label): string
    {
        $l = strtolower(trim($label));

        foreach (self::APP_ALIASES as $needle => $family) {
            if (str_contains($l, $needle)) {
                return $family;
            }
        }

        // اولین واژه معمولاً نامِ نرم‌افزار است: «Zabbix 7 on Ubuntu» → zabbix
        $first = preg_split('/[\s\-–—]+/u', $l)[0] ?? $l;

        return self::slug($first) ?: 'app';
    }

    /** نسخه: نقطه را نگه می‌دارد (۲۴٫۰۴ ≠ ۲۴۰۴) ولی بقیه را پاک می‌کند */
    private static function slugVersion(string $v): string
    {
        $v = strtolower(trim($v));
        $v = preg_replace('/[^a-z0-9.]+/', '-', $v) ?? '';

        return trim($v, '-.');
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim($s, '-');
    }
}
