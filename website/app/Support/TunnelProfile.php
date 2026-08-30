<?php

namespace App\Support;

use App\Models\CloudInstance;

/**
 * پروفایلِ «تونلِ TCP» یک سرورِ اکسیت.
 *
 * زمینه: در شبکه‌های موبایلِ ایران، امضای پروتکلِ WireGuard روی UDP شناسایی و
 * دراپ می‌شود. راهکارِ ساخته‌شده، خودِ WireGuard را دست‌نخورده نگه می‌دارد ولی
 * بسته‌هایش را داخل یک جریانِ TCP/TLS (VLESS + Reality) حمل می‌کند. سمتِ کاربر
 * یک کلاینتِ sing-box این کار را می‌کند؛ سمتِ سرور، روتر مشتری.
 *
 * این کلاس فقط «مولّدِ متن» است: از روی پارامترهای ذخیره‌شده در
 * `CloudInstance.meta['tunnel']` دو خروجی می‌سازد —
 *
 *   ۱) یک خطِ دستورِ RouterOS برای ثبتِ peer روی روترِ مشتری،
 *   ۲) کانفیگِ آمادهٔ کلاینت (sing-box) برای دستگاهِ کاربرِ نهایی.
 *
 * ⚠️ چرا پنل خودش peer را روی روتر نمی‌سازد: روترِ مشتری از سمتِ پنل قابلِ
 * دسترسی نیست (سرویسِ SSHـش با `available-from` محدود شده و API خاموش است).
 * پس مسیرِ صادقانه این است که پنل دستور را بسازد و مشتری آن را در ترمینالِ
 * روترِ خودش اجرا کند. هر وقت دسترسیِ API فراهم شد، فقط لایهٔ اجرا اضافه
 * می‌شود و بقیهٔ این کلاس دست‌نخورده می‌ماند.
 *
 * ⚠️ کلیدِ خصوصی هیچ‌وقت ذخیره نمی‌شود. مثلِ «نمایشِ یک‌بارهٔ رمزِ روت»، کلید
 * ساخته می‌شود، یک‌بار به مشتری نشان داده می‌شود و می‌رود؛ در `peers` فقط نام،
 * آدرس و کلیدِ عمومی می‌ماند.
 */
class TunnelProfile
{
    /** سقفِ تعدادِ اکانت — هم برای بزرگ‌نشدنِ ستونِ meta، هم مرزِ منطقیِ یک /24. */
    public const MAX_PEERS = 100;

    /** @param array<string,mixed> $data */
    private function __construct(
        private array $data,
        private ?CloudInstance $instance = null,
    ) {}

    /**
     * پروفایل را از روی نمونه می‌خواند. اگر تنظیم نشده یا ناقص باشد `null`.
     *
     * نبودِ پروفایل یعنی «این سرور تونلِ TCP ندارد» و بخشِ مربوطه در پنل اصلاً
     * رندر نمی‌شود — همان الگوی `exitCapable`.
     */
    public static function fromInstance(?CloudInstance $instance): ?self
    {
        if ($instance === null) {
            return null;
        }

        $meta = $instance->meta ?? [];
        $raw = $meta['tunnel'] ?? null;

        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return null;
        }

        $p = new self($raw, $instance);

        return $p->missingKeys() === [] ? $p : null;
    }

    /** برای تست و ابزارِ خطِ فرمان: ساخت از آرایهٔ خام بدونِ نمونه. */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    /**
     * فیلدهای اجباریِ نداشته. خالی‌بودنِ آن یعنی پروفایل قابلِ استفاده است.
     *
     * @return list<string>
     */
    public function missingKeys(): array
    {
        $required = ['host', 'port', 'uuid', 'sni', 'pbk', 'sid', 'wg_pub', 'wg_host', 'wg_port', 'iface', 'subnet'];
        $missing = [];

        foreach ($required as $k) {
            $v = $this->data[$k] ?? null;

            if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
                $missing[] = $k;
            }
        }

        if (! in_array('subnet', $missing, true) && $this->subnetBase() === null) {
            $missing[] = 'subnet';
        }

        return $missing;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->data[$key] ?? $default;

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /**
     * نشانی‌های بازنشسته — hostهای قبلیِ همین سرویس، پس از انتقال به IPِ تازه.
     *
     * پنل این‌ها را خط‌خورده نشان می‌دهد تا مدیر/مشتری بداند کانفیگ‌های قدیمی
     * دیگر پاسخ نمی‌گیرند. نویسنده‌اش مسیرِ جابه‌جاییِ سرور است که هنگامِ تغییرِ
     * host، مقدارِ قبلی را به همین فهرست می‌راند؛ نبودِ کلید یعنی فهرستِ خالی
     * و بخشِ مربوطه اصلاً رندر نمی‌شود.
     *
     * (بازسازی‌شده از رفتارِ نسخهٔ سرور — ۶ شهریور ۱۴۰۵؛ کلید: retired_hosts)
     *
     * @return list<string>
     */
    public function retiredHosts(): array
    {
        $raw = $this->data['retired_hosts'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $h) {
            if (is_string($h) && trim($h) !== '') {
                $out[] = trim($h);
            }
        }

        return array_values(array_unique($out));
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->data[$key] ?? $default;

        return is_numeric($v) ? (int) $v : $default;
    }

    /** اکانت‌های صادرشده — فقط نام/آدرس/کلیدِ عمومی/زمان. @return list<array<string,string>> */
    public function peers(): array
    {
        $rows = $this->data['peers'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['name'], $r['ip'])) {
                continue;
            }

            $out[] = [
                'name' => (string) $r['name'],
                'ip' => (string) $r['ip'],
                'pub' => (string) ($r['pub'] ?? ''),
                'at' => (string) ($r['at'] ?? ''),
            ];
        }

        return $out;
    }

    /** آدرس‌هایی که نباید صادر شوند: رزروهای پروفایل + هرچه قبلاً داده شده. @return list<string> */
    public function usedIps(): array
    {
        $used = [];
        $reserved = $this->data['reserved'] ?? [];

        if (is_array($reserved)) {
            foreach ($reserved as $ip) {
                if (is_string($ip)) {
                    $used[] = trim($ip);
                }
            }
        }

        foreach ($this->peers() as $p) {
            $used[] = $p['ip'];
        }

        // آدرسِ خودِ روتر همیشه رزرو است، حتی اگر در فهرست نیامده باشد.
        $base = $this->subnetBase();

        if ($base !== null) {
            $used[] = $base.'.1';
        }

        return array_values(array_unique($used));
    }

    /** سه اکتتِ اولِ ساب‌نت (فقط /24 پشتیبانی می‌شود) یا `null` اگر نامعتبر. */
    public function subnetBase(): ?string
    {
        $subnet = $this->str('subnet');

        if (! preg_match('~^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\d{1,3}/24$~', $subnet, $m)) {
            return null;
        }

        foreach ([$m[1], $m[2], $m[3]] as $o) {
            if ((int) $o > 255) {
                return null;
            }
        }

        return $m[1].'.'.$m[2].'.'.$m[3];
    }

    public function ipInSubnet(string $ip): bool
    {
        $base = $this->subnetBase();

        if ($base === null || ! preg_match('~^(\d{1,3}\.\d{1,3}\.\d{1,3})\.(\d{1,3})$~', trim($ip), $m)) {
            return false;
        }

        $host = (int) $m[2];

        return $m[1] === $base && $host >= 2 && $host <= 254;
    }

    public function ipIsFree(string $ip): bool
    {
        return ! in_array(trim($ip), $this->usedIps(), true);
    }

    public function nameIsFree(string $name): bool
    {
        foreach ($this->peers() as $p) {
            if (strcasecmp($p['name'], trim($name)) === 0) {
                return false;
            }
        }

        return true;
    }

    /** اولین آدرسِ آزادِ ساب‌نت، یا `null` اگر پر شده باشد. */
    public function nextIp(): ?string
    {
        $base = $this->subnetBase();

        if ($base === null) {
            return null;
        }

        $used = $this->usedIps();

        for ($i = 2; $i <= 254; $i++) {
            $ip = $base.'.'.$i;

            if (! in_array($ip, $used, true)) {
                return $ip;
            }
        }

        return null;
    }

    /** نامِ پیشنهادی برای اکانتِ بعدی، بر اساسِ آخرین اکتتِ آدرسِ آزاد. */
    public function suggestedName(): string
    {
        $ip = $this->nextIp();

        return 'user'.($ip === null ? '' : substr($ip, (int) strrpos($ip, '.') + 1));
    }

    /** خطِ دستورِ ثبتِ peer روی روترِ مشتری. */
    public function routerAddCommand(string $name, string $ip, string $publicKey): string
    {
        return '/interface/wireguard/peers/add'
            .' interface='.$this->str('iface')
            .' name='.$name
            .' public-key="'.$publicKey.'"'
            .' allowed-address='.$ip.'/32'
            .' comment="'.$name.' (panel)"';
    }

    /** خطِ دستورِ حذفِ peer از روترِ مشتری. */
    public function routerRemoveCommand(string $name): string
    {
        return '/interface/wireguard/peers/remove [find name="'.$name.'"]';
    }

    /**
     * کانفیگِ کلاینت.
     *
     * `$format`:
     *  - `singbox` — اسکیمای `endpoints` (sing-box ≥ ۱٫۱۱ و Hiddify نسل جدید)
     *  - `legacy`  — اسکیمای قدیمیِ «wireguard به‌عنوانِ outbound» برای اپ‌های قدیمی‌تر
     *
     * @return array<string,mixed>
     */
    public function clientConfig(string $ip, string $privateKey, string $format = 'singbox'): array
    {
        $vless = [
            'type' => 'vless',
            'tag' => 'vless-out',
            'server' => $this->str('host'),
            'server_port' => $this->int('port'),
            'uuid' => $this->str('uuid'),
            'flow' => 'xtls-rprx-vision',
            'packet_encoding' => 'xudp',
            'tls' => [
                'enabled' => true,
                'server_name' => $this->str('sni'),
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'reality' => [
                    'enabled' => true,
                    'public_key' => $this->str('pbk'),
                    'short_id' => $this->str('sid'),
                ],
            ],
        ];

        $tunMtu = $this->int('tun_mtu', 1200);
        $wgMtu = $this->int('wg_mtu', 1280);
        $bypass = [$this->str('host').'/32'];

        if ($format === 'legacy') {
            return [
                'log' => ['level' => 'warn'],
                'dns' => [
                    'servers' => [['tag' => 'remote', 'address' => '1.1.1.1', 'detour' => 'wg']],
                    'final' => 'remote',
                    'strategy' => 'ipv4_only',
                ],
                'inbounds' => [[
                    'type' => 'tun', 'tag' => 'tun-in', 'inet4_address' => '172.19.0.1/30',
                    'mtu' => $tunMtu, 'auto_route' => true, 'strict_route' => false,
                    'stack' => 'mixed', 'sniff' => true,
                ]],
                'outbounds' => [
                    [
                        'type' => 'wireguard', 'tag' => 'wg', 'detour' => 'vless-out',
                        'server' => $this->str('wg_host'), 'server_port' => $this->int('wg_port'),
                        'local_address' => [$ip.'/32'],
                        'private_key' => $privateKey,
                        'peer_public_key' => $this->str('wg_pub'),
                        'mtu' => $wgMtu,
                    ],
                    $vless,
                    ['type' => 'direct', 'tag' => 'direct'],
                    ['type' => 'dns', 'tag' => 'dns-out'],
                ],
                'route' => [
                    'rules' => [
                        ['protocol' => 'dns', 'outbound' => 'dns-out'],
                        ['ip_cidr' => $bypass, 'outbound' => 'direct'],
                    ],
                    'final' => 'wg',
                    'auto_detect_interface' => true,
                ],
            ];
        }

        return [
            'log' => ['level' => 'warn'],
            'dns' => [
                'servers' => [['tag' => 'remote', 'type' => 'udp', 'server' => '1.1.1.1', 'detour' => 'wg']],
                'final' => 'remote',
                'strategy' => 'ipv4_only',
                'independent_cache' => true,
            ],
            'inbounds' => [[
                'type' => 'tun', 'tag' => 'tun-in', 'address' => ['172.19.0.1/30'],
                'mtu' => $tunMtu, 'auto_route' => true, 'strict_route' => false, 'stack' => 'mixed',
            ]],
            'outbounds' => [$vless, ['type' => 'direct', 'tag' => 'direct']],
            'endpoints' => [[
                'type' => 'wireguard', 'tag' => 'wg', 'system' => false, 'mtu' => $wgMtu,
                'address' => [$ip.'/32'],
                'private_key' => $privateKey,
                'detour' => 'vless-out',
                'peers' => [[
                    'address' => $this->str('wg_host'),
                    'port' => $this->int('wg_port'),
                    'public_key' => $this->str('wg_pub'),
                    'allowed_ips' => ['0.0.0.0/0'],
                    'persistent_keepalive_interval' => 25,
                ]],
            ]],
            'route' => [
                'rules' => [
                    ['action' => 'sniff'],
                    ['protocol' => 'dns', 'action' => 'hijack-dns'],
                    ['ip_cidr' => $bypass, 'outbound' => 'direct'],
                ],
                'final' => 'wg',
                'auto_detect_interface' => true,
            ],
        ];
    }

    public function configJson(string $ip, string $privateKey, string $format = 'singbox'): string
    {
        return (string) json_encode(
            $this->clientConfig($ip, $privateKey, $format),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** آدرسِ داخلِ تونل که مشتری برای مدیریتِ روتر به آن وصل می‌شود. */
    public function routerAddress(): string
    {
        $base = $this->subnetBase();

        return $base === null ? '' : $base.'.1';
    }

    /** @return array<string,mixed> ساختارِ خام، برای نوشتنِ دوباره در meta. */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * افزودنِ اکانت به فهرست و نوشتن در نمونه.
     *
     * @return array<string,string> ردیفِ ثبت‌شده
     */
    public function addPeer(string $name, string $ip, string $publicKey): array
    {
        $row = ['name' => $name, 'ip' => $ip, 'pub' => $publicKey, 'at' => now()->toIso8601String()];

        $peers = $this->peers();
        $peers[] = $row;
        $this->data['peers'] = $peers;
        $this->persist();

        return $row;
    }

    /** حذفِ اکانت از فهرست. `true` اگر چیزی حذف شد. */
    public function removePeer(string $name): bool
    {
        $before = $this->peers();
        $after = array_values(array_filter(
            $before,
            fn (array $p): bool => strcasecmp($p['name'], trim($name)) !== 0
        ));

        if (count($after) === count($before)) {
            return false;
        }

        $this->data['peers'] = $after;
        $this->persist();

        return true;
    }

    /** نوشتنِ پروفایل در `meta` — همان الگوی خواندن‌ـتغییر‌ـذخیرهٔ بقیهٔ فیچرها. */
    private function persist(): void
    {
        if ($this->instance === null) {
            return;
        }

        $meta = $this->instance->meta ?? [];
        $meta['tunnel'] = $this->data;
        $this->instance->meta = $meta;
        $this->instance->save();
    }
}
