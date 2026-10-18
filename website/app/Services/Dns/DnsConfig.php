<?php

namespace App\Services\Dns;

use App\Models\Setting;

/**
 * تنها خوانندهٔ پیکربندیِ DNSِ مدیریت‌شده (قراردادِ §2).
 *
 * 🔴 چرا یک در و نه پنج: نسخهٔ یک نیم‌سرورها را از Setting، آدرسِ API را از
 * Setting یا config و کلید را جای دیگر می‌خواند. هر خوانندهٔ اضافه یعنی روزی
 * پنل یک فهرست نشان بدهد و انتشار/تأییدِ تفویض با فهرستِ دیگری کار کند — و
 * چنین خرابی‌ای خاموش است. همه از این کلاس می‌پرسند.
 *
 * ترتیبِ منبع: تنظیمِ مدیر (`Setting`) ← `config('edge.dns')` ← پیش‌فرضِ همین
 * کلاس. پیش‌فرضِ داخلی برای این است که نبودنِ بخشِ `dns` در یک config قدیمیِ
 * جامانده از دیپلوی، صفحه را ۵۰۰ نکند.
 *
 * ⚠️ متدها static‌اند (و `app(DnsConfig::class)->x()` هم کار می‌کند). هیچ
 * حافظهٔ static ندارد: Setting خودش حافظهٔ درون‌درخواستی دارد و حافظهٔ
 * static بینِ تست‌ها نشت می‌کرد.
 *
 * ⚠️ کلیدِ API فقط از `apiKey()` بیرون می‌آید و هیچ متدِ دیگری آن را در
 * خروجی، پیام یا آرایهٔ خلاصه نمی‌گذارد.
 */
final class DnsConfig
{
    /**
     * نیم‌سرورهای cPanel/WHM. دامنه‌ای که مشتری به این‌ها بدهد هرگز به PowerDNS
     * نمی‌رسد؛ پس هرگز نیم‌سرورِ DNSِ مدیریت‌شده نیستند — حتی اگر مدیر بنویسد.
     */
    public const RESERVED = [
        'ns1.servernet.cloud', 'ns2.servernet.cloud',
        'ns1.servernet.ir', 'ns2.servernet.ir',
    ];

    private const DEFAULT_CATALOG = 'catalog.servernet.invalid';

    private const DEFAULT_TSIG = 'xfr-servernet';

    private const DEFAULT_SOA = [
        'email'   => 'hostmaster.servernet.cloud',
        'refresh' => 3600,
        'retry'   => 600,
        'expire'  => 2419200,
        'minimum' => 300,
    ];

    private const DEFAULT_LIMITS = [
        'zones_per_customer'          => 50,
        'records_per_zone'            => 500,
        'pending_claims_per_customer' => 20,
        'claim_ttl_days'              => 7,
        'import_max_lines'            => 5000,
        'import_max_bytes'            => 1048576,
        'txt_max_bytes'               => 4096,
        'add_per_minute'              => 10,
        'record_writes_per_minute'    => 60,
        'imports_per_minute'          => 6,
        'checks_per_minute'           => 10,
    ];

    /** نام‌های کوتاه — تا یک حدسِ متفاوت در فراخوان به استثنا نرسد */
    private const LIMIT_ALIASES = [
        'zones'          => 'zones_per_customer',
        'records'        => 'records_per_zone',
        'claims'         => 'pending_claims_per_customer',
        'pending_claims' => 'pending_claims_per_customer',
    ];

    /* ───────────────────────── پرچم و API ───────────────────────── */

    /** پرچمِ ویژگی. مقدارِ نامفهومِ Setting یعنی خاموش، نه پیش‌فرضِ config. */
    public static function enabled(): bool
    {
        $s = Setting::get('dns_managed_enabled');

        if ($s === null || trim($s) === '') {
            return (bool) config('edge.dns.enabled', false);
        }

        return filter_var(trim($s), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /** آدرسِ پایهٔ API بی‌اسلشِ پایانی؛ null اگر تنظیم نشده */
    public static function apiUrl(): ?string
    {
        $raw = Setting::get('pdns_api_url');

        if ($raw === null || trim($raw) === '') {
            $raw = config('edge.dns.api_url');
        }

        $raw = trim((string) $raw);

        return $raw === '' ? null : rtrim($raw, '/');
    }

    /** کلیدِ API — فقط برای سرآیندِ درخواست. هرگز لاگ/نمایش/بازگردانده نشود. */
    public static function apiKey(): ?string
    {
        $k = Setting::getSecret('pdns_api_key');
        $k = $k === null ? '' : trim($k);

        return $k === '' ? null : $k;
    }

    public static function apiUrlIsAcceptable(): bool
    {
        return self::urlIsAcceptable(self::apiUrl());
    }

    /**
     * آیا این آدرس برای فرستادنِ کلیدِ API امن است؟ (برای اعتبارسنجیِ فرمِ مدیر هم)
     *
     * 🔴 https همیشه. http فقط وقتی میزبان loopback/RFC1918/link-local **و**
     * `DNS_ALLOW_INSECURE_API=true` باشد. نامِ میزبان (جز `localhost`) هرگز
     * «خصوصی» حساب نمی‌شود: تشخیصش نیازِ resolve دارد و جوابِ DNS را می‌شود
     * عوض کرد — یعنی کلید بی‌رمز به هر جایی برود.
     *
     * ⚠️ نام‌کاربری/رمز در URL رد می‌شود: آن‌ها در هر لاگ و پیامِ خطای HTTP
     * تکرار می‌شوند.
     */
    public static function urlIsAcceptable(?string $url): bool
    {
        if ($url === null || $url === '' || preg_match('~[\x00-\x20\x7f]~', $url)) {
            return false;
        }

        $p = parse_url($url);

        if (! is_array($p) || empty($p['scheme']) || ! isset($p['host']) || $p['host'] === '') {
            return false;
        }

        if (isset($p['user']) || isset($p['pass'])) {
            return false;
        }

        $scheme = strtolower($p['scheme']);

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme !== 'http' || ! (bool) config('edge.dns.allow_insecure_api', false)) {
            return false;
        }

        return self::isLocalHost($p['host']);
    }

    /** شناسهٔ سرور در مسیرِ API (`/api/v1/servers/{id}`) */
    public static function serverId(): string
    {
        $id = trim((string) config('edge.dns.server_id', 'localhost'));

        // در مسیرِ URL می‌نشیند؛ نویسهٔ عجیب یعنی مسیرِ دیگری روی API
        return preg_match('~^[A-Za-z0-9._-]{1,64}$~', $id) ? $id : 'localhost';
    }

    public static function connectTimeout(): int
    {
        return max(1, (int) config('edge.dns.http.connect_timeout', 4));
    }

    public static function timeout(): int
    {
        return max(1, (int) config('edge.dns.http.timeout', 8));
    }

    /* ───────────────────────── zone و انتقال ───────────────────────── */

    /** نامِ zoneِ catalog بی‌نقطهٔ پایانی (فراخوان `.` را خودش می‌افزاید) */
    public static function catalog(): string
    {
        return self::dnsName(Setting::get('dns_catalog_zone'), false)
            ?? self::dnsName(config('edge.dns.catalog'), false)
            ?? self::DEFAULT_CATALOG;
    }

    /** فقط **نامِ** کلیدِ TSIG؛ رازش هرگز در این برنامه نیست */
    public static function tsigKey(): string
    {
        return self::dnsName(Setting::get('dns_xfr_tsig_key'), true)
            ?? self::dnsName(config('edge.dns.tsig_key'), true)
            ?? self::DEFAULT_TSIG;
    }

    /**
     * نیم‌سرورهای عمومی: `[{host, ipv4, ipv6, port}]`.
     *
     * 🔴 Setting اگر هست (حتی خراب) حرفِ آخر را می‌زند: JSONِ خراب ⇒ فهرستِ
     * **خالی**، نه بازگشت به config. بازگشتِ بی‌صدا یعنی مدیر فکر کند فهرستِ
     * خودش فعال است در حالی که نام‌های دیگری به مشتری داده می‌شود. فهرستِ خالی
     * سرویس را می‌بندد (`serviceOpen`) و خطا دیده می‌شود.
     *
     * @return array<int,array{host:string,ipv4:?string,ipv6:?string,port:int}>
     */
    public static function nameservers(): array
    {
        return self::validateNameservers(self::rawNameservers() ?? [])['nameservers'];
    }

    /**
     * ایرادهای فهرستِ ذخیره‌شده — برای `serviceOpen` و صفحهٔ مدیر.
     *
     * @return array<int,array{index:?int,field:string,code:string}>
     */
    public static function nameserverErrors(): array
    {
        $list = self::rawNameservers();

        if ($list === null) {
            return [['index' => null, 'field' => 'nameservers', 'code' => 'ns_json_invalid']];
        }

        return self::validateNameservers($list)['errors'];
    }

    /**
     * نرمال‌سازیِ یک فهرستِ خام؛ فقط فهرستِ تمیز (سازگاری با فراخوان‌های قبلی).
     *
     * @param  array<mixed>  $list
     * @return array<int,array{host:string,ipv4:?string,ipv6:?string,port:int}>
     */
    public static function normalizeNameservers(array $list): array
    {
        return self::validateNameservers($list)['nameservers'];
    }

    /**
     * اعتبارسنجیِ فهرستِ خام — فرمِ مدیر **باید** با `errors !== []` رد کند.
     *
     * ردیفِ بی‌نامِ معتبر، رزروشده یا تکراری از فهرست **حذف** می‌شود؛ IPِ
     * نامعتبر null می‌شود (ردیف می‌ماند تا NSِ zone همان چیزی باشد که مدیر
     * نوشته، نه بی‌صدا کوچک‌تر). هر دو حالت در `errors` با **کد** می‌آیند
     * (`dns.err.<code>` در lang)، و هر خطا `serviceOpen` را می‌بندد.
     *
     * 🔴 نودِ بی‌هیچ آدرس (`ns_no_address`) را NodeVerifier نمی‌تواند بپرسد،
     * پس هیچ zoneی هرگز «منتشر روی همه» نمی‌شد در حالی که سرویس «باز» بود.
     *
     * @param  array<mixed>  $list
     * @return array{nameservers:array<int,array{host:string,ipv4:?string,ipv6:?string,port:int}>,
     *               errors:array<int,array{index:?int,field:string,code:string}>}
     */
    public static function validateNameservers(array $list): array
    {
        $out = [];
        $errors = [];
        $seen = [];
        $err = function (?int $i, string $field, string $code) use (&$errors): void {
            $errors[] = ['index' => $i, 'field' => $field, 'code' => $code];
        };

        foreach (array_values($list) as $i => $e) {
            if (! is_array($e)) {
                $err($i, 'nameservers', 'ns_entry_invalid');

                continue;
            }

            $host = self::hostname($e['host'] ?? null);

            if ($host === null) {
                $err($i, 'host', 'ns_host_invalid');

                continue;
            }

            if (in_array($host, self::RESERVED, true)) {
                $err($i, 'host', 'ns_host_reserved');

                continue;
            }

            if (isset($seen[$host])) {
                $err($i, 'host', 'ns_host_duplicate');

                continue;
            }

            $seen[$host] = true;

            $ipv4 = self::ipv4($e['ipv4'] ?? null);
            $ipv6 = self::ipv6($e['ipv6'] ?? null);
            $badAddress = false;

            if ($ipv4 === null && ! self::blank($e['ipv4'] ?? null)) {
                $err($i, 'ipv4', 'ns_ipv4_invalid');
                $badAddress = true;
            }

            if ($ipv6 === null && ! self::blank($e['ipv6'] ?? null)) {
                $err($i, 'ipv6', 'ns_ipv6_invalid');
                $badAddress = true;
            }

            if ($ipv4 === null && $ipv6 === null && ! $badAddress) {
                $err($i, 'ipv4', 'ns_no_address');
            }

            $port = 53;

            if (! self::blank($e['port'] ?? null)) {
                $p = filter_var($e['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

                if ($p === false) {
                    $err($i, 'port', 'ns_port_invalid');
                } else {
                    $port = (int) $p;
                }
            }

            $out[] = ['host' => $host, 'ipv4' => $ipv4, 'ipv6' => $ipv6, 'port' => $port];
        }

        if (count(array_filter($out, fn (array $n) => $n['ipv4'] !== null)) < 2) {
            $err(null, 'nameservers', 'ns_too_few');
        }

        return ['nameservers' => $out, 'errors' => $errors];
    }

    /**
     * فهرستِ خام: Setting اگر هست، وگرنه config. null یعنی Settingِ خراب.
     *
     * @return array<mixed>|null
     */
    private static function rawNameservers(): ?array
    {
        $raw = Setting::get('dns_nameservers');

        if ($raw === null || trim($raw) === '') {
            return (array) config('edge.dns.nameservers', []);
        }

        $list = json_decode($raw, true);

        return is_array($list) && array_is_list($list) ? $list : null;
    }

    /** @return array<int,string> نام‌ها، کوچک و بی‌نقطهٔ پایانی */
    public static function nameserverHosts(): array
    {
        return array_column(self::nameservers(), 'host');
    }

    /** @return array{email:string,refresh:int,retry:int,expire:int,minimum:int} */
    public static function soa(): array
    {
        $c = (array) config('edge.dns.soa', []);
        $out = [];

        foreach (self::DEFAULT_SOA as $k => $default) {
            $v = $c[$k] ?? $default;
            $out[$k] = is_int($default) ? max(0, (int) $v) : (string) $v;
        }

        return $out;
    }

    /**
     * یک سهمیه/نرخ.
     *
     * 🔴 کلیدِ ناشناخته استثنا می‌دهد، نه صفر: صفرِ بی‌صدا یعنی سقفی که هیچ
     * مشتری‌ای از آن رد نمی‌شود و فقط با شکایت پیدا می‌شود. غلطِ تایپی باید در
     * اولین تست بترکد.
     */
    public static function limit(string $k): int
    {
        $k = self::LIMIT_ALIASES[$k] ?? $k;
        $c = (array) config('edge.dns.limits', []);

        if (array_key_exists($k, $c)) {
            return max(0, (int) $c[$k]);
        }

        if (array_key_exists($k, self::DEFAULT_LIMITS)) {
            return self::DEFAULT_LIMITS[$k];
        }

        throw new \InvalidArgumentException('Unknown DNS limit ['.$k.']');
    }

    /**
     * آیا سرویس برای مشتری باز است؟
     *
     * 🔴 تا سرورِ واقعی پشتِ دست‌کم دو نیم‌سرورِ دارای IPv4 نباشد، مشتری‌ای که
     * نیم‌سرور عوض کند سایت و ایمیلش همان لحظه می‌میرد. آدرسِ ناامنِ API هم
     * «بسته» است، چون کلاینت به آن وصل نمی‌شود.
     *
     * 🔴 فهرستِ نیم‌سرور باید **بی‌هیچ ایراد** باشد (`nameserverErrors`)، نه فقط
     * «دو تا با IPv4»: نودِ سومی که آدرس ندارد هرگز `in_sync` نمی‌شود و سرویسِ
     * «باز» هیچ zoneی را فعال نمی‌کرد.
     *
     * ⚠️ ترتیبِ شرط‌ها عمدی است: پرچمِ خاموش (حالتِ عادیِ امروز) پیش از
     * رمزگشاییِ کلید برمی‌گردد — این از منوی هر صفحهٔ پنل صدا زده می‌شود.
     */
    public static function serviceOpen(): bool
    {
        if (! self::enabled() || ! self::apiUrlIsAcceptable() || self::apiKey() === null) {
            return false;
        }

        return self::nameserverErrors() === [];
    }

    /** @return array<int,string> */
    public static function reserved(): array
    {
        return self::RESERVED;
    }

    /* ───────────────────────── کمکی ───────────────────────── */

    /**
     * نامِ میزبانِ LDH، کوچک، بی‌نقطهٔ پایانی، دست‌کم دو برچسب.
     *
     * ⚠️ برچسبِ آخرِ تمام‌عددی رد می‌شود (RFC 1123 §2.1، RFC 3696 §2): هیچ
     * TLDی عددی نیست، و بی‌این قاعده `192.0.2.1` به‌عنوانِ «نامِ» NS پذیرفته
     * می‌شد — رکوردِ NS باید نام باشد، نه IP.
     */
    private static function hostname(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $h = self::dnsName($raw, false);

        if ($h === null || ! str_contains($h, '.')) {
            return null;
        }

        return ctype_digit(substr($h, strrpos($h, '.') + 1)) ? null : $h;
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    /** نامِ DNS؛ `$underscore` برای نامِ کلیدِ TSIG */
    private static function dnsName(mixed $raw, bool $underscore): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $n = strtolower(trim($raw));

        if (str_ends_with($n, '.')) {
            $n = substr($n, 0, -1);
        }

        if ($n === '' || strlen($n) > 253) {
            return null;
        }

        $label = $underscore
            ? '~^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$~'
            : '~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$~';

        foreach (explode('.', $n) as $l) {
            if (! preg_match($label, $l)) {
                return null;
            }
        }

        return $n;
    }

    /**
     * IPv4ای که یک سرورِ DNS واقعاً می‌تواند رویش جواب دهد.
     *
     * ⚠️ 0/8 (نامشخص)، 127/8 (loopback)، 224/4 (multicast) و 240/4 (رزرو، با
     * broadcast) رد می‌شوند: پرس‌وجوی NodeVerifier به آن‌ها یا به خودِ همین
     * ماشین می‌رسد یا به هیچ‌جا — «هم‌گام» یا «ناموجود»ِ دروغ.
     * RFC1918 عمداً مجاز است (آزمایشگاه و شبکهٔ خصوصیِ پایش). از
     * `FILTER_FLAG_NO_RES_RANGE` استفاده نمی‌شود چون دامنه‌اش بینِ نسخه‌های
     * PHP عوض شده است.
     */
    private static function ipv4(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $ip = trim($raw);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $first = (int) explode('.', $ip)[0];

        return ($first === 0 || $first === 127 || $first >= 224) ? null : $ip;
    }

    /**
     * IPv6 به شکلِ فشرده و کوچک — تا مقایسه با پاسخِ واقعی رشته‌ای باشد.
     *
     * ⚠️ `::`، `::1`، multicast (ff00::/8)، link-local (fe80::/10، بی‌شناسهٔ
     * رابط قابلِ اتصال نیست) و IPv4-mapped رد می‌شوند — همان دلیلِ `ipv4()`.
     */
    private static function ipv6(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $ip = trim($raw);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $bin = @inet_pton($ip);

        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }

        $zero = str_repeat("\0", 15);

        if ($bin === $zero."\0" || $bin === $zero."\1"
            || ord($bin[0]) === 0xFF
            || (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)
            || substr($bin, 0, 12) === str_repeat("\0", 10)."\xFF\xFF") {
            return null;
        }

        return strtolower((string) inet_ntop($bin));
    }

    /** loopback، RFC1918 یا link-local — فقط IPِ صریح یا `localhost` */
    private static function isLocalHost(string $host): bool
    {
        $h = strtolower(trim($host, '[]'));

        if ($h === 'localhost') {
            return true;
        }

        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isLocalV4($h);
        }

        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $b = (string) @inet_pton($h);

        if (strlen($b) !== 16) {
            return false;
        }

        if ($b === str_repeat("\0", 15)."\1") {
            return true;                                         // ::1
        }

        if (ord($b[0]) === 0xFE && (ord($b[1]) & 0xC0) === 0x80) {
            return true;                                         // fe80::/10
        }

        // ‎::ffff:a.b.c.d — همان قاعدهٔ IPv4
        if (substr($b, 0, 12) === str_repeat("\0", 10)."\xFF\xFF") {
            return self::isLocalV4((string) inet_ntop(substr($b, 12)));
        }

        return false;
    }

    private static function isLocalV4(string $ip): bool
    {
        $n = ip2long($ip);

        if ($n === false) {
            return false;
        }

        foreach ([
            ['127.0.0.0', 8], ['10.0.0.0', 8], ['172.16.0.0', 12],
            ['192.168.0.0', 16], ['169.254.0.0', 16],
        ] as [$net, $bits]) {
            $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;

            if (($n & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
