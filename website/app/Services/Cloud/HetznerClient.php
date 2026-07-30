<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * درایورِ Hetzner Cloud (API v1).
 *
 * برخلافِ زحل و OpenProvider و Cloudflare، هتزنر **کدِ HTTP را درست می‌دهد**:
 * ۲۰۰/۲۰۱ موفق، ۴xx/۵xx خطا با بدنهٔ `{"error":{"code":…,"message":…}}`.
 * پس اینجا به کدِ HTTP تکیه می‌کنیم — ولی پیامِ خطا را از بدنه می‌خوانیم چون
 * `message` هتزنر دقیقاً می‌گوید چه چیزی غلط است.
 *
 * دو تلهٔ واقعیِ این API:
 *
 * ۱) **IPv4 دیگر در قیمتِ سرور نیست.** از ۲۰۲۴ هتزنر برای هر IPv4 جدا پول
 *    می‌گیرد. اگر آن را به بهایِ تمام‌شده اضافه نکنیم، روی هر سرور ماهی
 *    ~۰٫۶ یورو ضرر می‌دهیم — کم به نظر می‌رسد ولی روی حاشیهٔ سودِ VPS زیاد است.
 *
 * ۲) **`name` سرور در کلِ پروژه یکتاست** و تلاشِ دوباره خطای `uniqueness_error`
 *    می‌گیرد. کرونِ تحویل ممکن است دو بار بدود، پس در آن حالت سرورِ موجود را
 *    پیدا و برمی‌گردانیم — نه اینکه تحویل را شکست‌خورده اعلام کنیم.
 */
class HetznerClient implements CloudProvider
{
    private const BASE = 'https://api.hetzner.cloud/v1';

    /** مصرفِ ترافیک به بایت می‌آید */
    private const GB = 1073741824;

    public function slug(): string
    {
        return 'hetzner';
    }

    private function token(): ?string
    {
        return Setting::getSecret('hetzner_api_token');
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function capabilities(): array
    {
        return [
            'console' => true, 'rebuild' => true, 'resize' => true,
            'snapshot' => true, 'metrics' => true, 'reset_password' => true,
            'ipv6' => true, 'rescue' => true,
            'ssh_key' => true, 'extra_ip' => true,
        ];
    }

    /**
     * بارگذاریِ کلیدِ SSH — idempotent روی اثرِ انگشت.
     *
     * ⚠️ اگر کلید از قبل در حساب باشد، خطای «تکراری» می‌آید. آن خطا **موفقیت**
     * است نه شکست: یعنی همان کلید هست. بی‌این تفسیر، دومین سرورِ همان مشتری
     * هرگز تحویل نمی‌شد.
     */
    public function uploadSshKey(string $name, string $publicKey): array
    {
        $r = $this->req('POST', '/ssh_keys', [
            'name'       => $name,
            'public_key' => $publicKey,
        ]);

        if ($r['ok']) {
            return ['ok' => true, 'message' => '', 'ref' => (string) data_get($r['body'], 'ssh_key.id', '')];
        }

        // تکراری → همان کلیدِ موجود را پیدا کن
        if (str_contains($r['message'], 'uniqueness_error')) {
            $found = $this->findSshKey($publicKey);

            if ($found !== null) {
                return ['ok' => true, 'message' => 'کلید از قبل ثبت شده بود.', 'ref' => $found];
            }
        }

        return ['ok' => false, 'message' => $r['message'], 'ref' => null];
    }

    /** جستجوی کلید با اثرِ انگشت — چون نامش می‌تواند فرق کند */
    private function findSshKey(string $publicKey): ?string
    {
        $parts = explode(' ', trim(preg_replace('/\s+/', ' ', $publicKey) ?? ''));
        $body = base64_decode($parts[1] ?? '', true);

        if ($body === false) {
            return null;
        }

        $fingerprint = implode(':', str_split(md5($body), 2));
        $r = $this->req('GET', '/ssh_keys', ['fingerprint' => $fingerprint]);

        $id = data_get($r['body'], 'ssh_keys.0.id');

        return $r['ok'] && filled($id) ? (string) $id : null;
    }

    /**
     * IP اضافه = Floating IP که به سرور بسته می‌شود.
     *
     * چرا Floating و نه Primary: Primary IP خودِ آدرسِ اصلیِ سرور است و یکی
     * بیشتر نمی‌شود. Floating IP آدرسِ مستقلی است که می‌توان به سرور بست و
     * بعداً به سرورِ دیگری منتقل کرد — همان چیزی که مشتری از «IP اضافه»
     * می‌خواهد.
     *
     * هر IP جدا ساخته می‌شود و اگر یکی نشد، همان‌هایی که شدند برگردانده
     * می‌شوند؛ گزارشِ نیمه از شکستِ کامل بهتر است چون مشتری بخشی از آنچه خریده
     * را دارد و پشتیبانی می‌داند دقیقاً کجا مانده.
     */
    public function addExtraIps(string $ref, int $count): array
    {
        $ips = [];
        $lastError = '';

        for ($i = 0; $i < max(0, $count); $i++) {
            $r = $this->req('POST', '/floating_ips', [
                'type'      => 'ipv4',
                'server'    => (int) $ref,
                'labels'    => ['snet' => 'extra'],
            ]);

            if ($r['ok']) {
                $ip = (string) data_get($r['body'], 'floating_ip.ip', '');

                if ($ip !== '') {
                    $ips[] = $ip;
                }

                continue;
            }

            $lastError = $r['message'];
            break;
        }

        return [
            'ok'      => $ips !== [] && count($ips) === max(0, $count),
            'message' => $ips === [] ? $lastError : ($lastError !== '' ? 'بخشی از IPها ساخته شد: '.$lastError : ''),
            'ips'     => $ips,
        ];
    }

    // ───────────────────────── لایهٔ تماس ─────────────────────────

    /** @return array{ok:bool,status:int,body:array,message:string} */
    private function req(string $method, string $path, array $payload = []): array
    {
        $token = $this->token();

        if (blank($token)) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'message' => 'توکنِ ارائه‌دهندهٔ ابری تنظیم نشده است.'];
        }

        try {
            $http = Http::withToken($token)
                ->acceptJson()
                ->timeout(25)
                ->connectTimeout(10);

            /** @var Response $res */
            $res = match (strtoupper($method)) {
                'GET'    => $http->get(self::BASE.$path, $payload),
                'POST'   => $http->post(self::BASE.$path, $payload),
                'PUT'    => $http->put(self::BASE.$path, $payload),
                'DELETE' => $http->delete(self::BASE.$path, $payload),
                default  => throw new \InvalidArgumentException($method),
            };
        } catch (\Throwable $e) {
            Log::warning('hetzner.transport', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'message' => 'ارتباط با ارائه‌دهنده برقرار نشد.'];
        }

        $body = (array) ($res->json() ?? []);

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'body' => $body, 'message' => ''];
        }

        $code = (string) data_get($body, 'error.code', '');
        $msg  = (string) data_get($body, 'error.message', 'خطای نامشخص');

        // پیامِ خامِ انگلیسی را نگه می‌داریم؛ برای پنلِ مدیریت لازم است و
        // ترجمهٔ حدسی، عیب‌یابی را سخت‌تر می‌کند. کد را جلو می‌گذاریم.
        return [
            'ok' => false, 'status' => $res->status(), 'body' => $body,
            'message' => $code !== '' ? "[$code] $msg" : $msg,
        ];
    }

    /**
     * صفحه‌به‌صفحه خواندن — هتزنر پیش‌فرض ۲۵ ردیف می‌دهد و پلن‌ها بیشترند.
     *
     * `$error` علتِ واقعیِ خالی‌بودن را بیرون می‌دهد. بی‌آن، مدیر پیامِ مبهمِ
     * «فهرست خوانده نشد» می‌دید و نمی‌فهمید توکنش غلط است یا شبکه قطع است —
     * و همان ابهام، عیب‌یابی را به حدس‌وگمان تبدیل می‌کرد.
     */
    private function paged(string $path, string $key, array $query = [], ?string &$error = null): array
    {
        $out = [];
        $page = 1;

        do {
            $r = $this->req('GET', $path, $query + ['page' => $page, 'per_page' => 50]);

            if (! $r['ok']) {
                $error = $r['message'];

                return $page === 1 ? [] : $out;      // صفحهٔ اول خطا = چیزی نداریم
            }

            $rows = (array) ($r['body'][$key] ?? []);
            $out = array_merge($out, $rows);

            $last = (int) data_get($r['body'], 'meta.pagination.last_page', 1);
            $page++;
        } while ($page <= $last && $page <= 20);      // سقفِ ایمنی

        return $out;
    }

    public function testConnection(): array
    {
        $r = $this->req('GET', '/locations');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }

        $n = count((array) ($r['body']['locations'] ?? []));

        return ['ok' => true, 'message' => "اتصال برقرار است — {$n} مکان در دسترس.", 'meta' => ['locations' => $n]];
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];

        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'توکنِ هتزنر تنظیم نشده.'] + $empty;
        }

        $error = null;
        $locations = $this->paged('/locations', 'locations', [], $error);

        if ($locations === []) {
            return ['ok' => false, 'message' => $error ?: 'فهرستِ مکان‌ها خوانده نشد.'] + $empty;
        }

        $outLocations = [];
        $codeByRef = [];

        foreach ($locations as $l) {
            $ref = (string) ($l['name'] ?? '');
            if ($ref === '') {
                continue;
            }

            $code = CloudNaming::locationCode((string) ($l['country'] ?? ''), (string) ($l['city'] ?? ''), $ref);
            $codeByRef[$ref] = $code;

            $outLocations[] = [
                'code'              => $code,
                'country'           => strtoupper((string) ($l['country'] ?? '')),
                'city'              => (string) ($l['city'] ?? '') ?: null,
                'provider_location' => $ref,
                'latitude'          => isset($l['latitude']) ? (float) $l['latitude'] : null,
                'longitude'         => isset($l['longitude']) ? (float) $l['longitude'] : null,
            ];
        }

        // موجودی: هتزنر «در دسترس بودنِ نوعِ سرور» را در سطحِ datacenter می‌دهد،
        // نه location. اگر این را نخوانیم، پلنِ تمام‌شده را می‌فروشیم و تحویل
        // با «resource_unavailable» شکست می‌خورد — بدترین حالت: پولِ گرفته‌شده.
        $availability = [];
        foreach ($this->paged('/datacenters', 'datacenters') as $dc) {
            $loc = (string) data_get($dc, 'location.name', '');
            foreach ((array) data_get($dc, 'server_types.available', []) as $id) {
                $availability[$loc][(int) $id] = true;
            }
        }

        $ipv4Cents = $this->ipv4MonthlyCents();

        $outPlans = [];
        foreach ($this->paged('/server_types', 'server_types') as $t) {
            if (! empty($t['deprecated'])) {
                continue;
            }

            $ref = (string) ($t['name'] ?? '');
            $id  = (int) ($t['id'] ?? 0);
            if ($ref === '') {
                continue;
            }

            foreach ((array) ($t['prices'] ?? []) as $p) {
                $locRef = (string) ($p['location'] ?? '');
                $code = $codeByRef[$locRef] ?? null;
                if ($code === null) {
                    continue;
                }

                // net = بی‌مالیات. مالیاتِ آلمان به ما (شرکتِ خارج از اروپا)
                // نمی‌خورد و gross رقمِ گمراه‌کننده‌ای برای بهای تمام‌شده است.
                $monthly = (float) data_get($p, 'price_monthly.net', 0);
                if ($monthly <= 0) {
                    continue;
                }

                $traffic = (int) (($p['included_traffic'] ?? $t['included_traffic'] ?? 0) / self::GB);

                $outPlans[] = [
                    'provider_ref'      => $ref,
                    'provider_location' => $locRef,
                    'location_code'     => $code,
                    'name'              => strtoupper($ref),
                    'vcpu'              => (int) ($t['cores'] ?? 1),
                    // memory به گیگابایتِ اعشاری می‌آید (۰٫۵ هم دیده می‌شود)
                    'ram_mb'            => (int) round(((float) ($t['memory'] ?? 1)) * 1024),
                    'disk_gb'           => (int) ($t['disk'] ?? 0),
                    'disk_type'         => ((string) ($t['storage_type'] ?? '')) === 'network' ? 'network' : 'nvme',
                    'traffic_gb'        => $traffic,
                    'cpu_kind'          => ((string) ($t['cpu_type'] ?? 'shared')) === 'dedicated' ? 'dedicated' : 'shared',
                    'arch'              => ((string) ($t['architecture'] ?? 'x86')) === 'arm' ? 'arm' : 'x86',
                    'cost_eur_cents'    => (int) round($monthly * 100) + $ipv4Cents,
                    'in_stock'          => (bool) ($availability[$locRef][$id] ?? false),
                ];
            }
        }

        return [
            'ok' => true, 'message' => '',
            'locations' => $outLocations,
            'plans'     => $outPlans,
            'images'    => $this->fetchImages(),
        ];
    }

    /** بهایِ ماهانهٔ یک IPv4 به سنت — از /pricing، با پشتیبانِ تنظیماتی */
    private function ipv4MonthlyCents(): int
    {
        $override = (int) Setting::get('cloud_ipv4_eur_cents', '-1');
        if ($override >= 0) {
            return $override;
        }

        $r = $this->req('GET', '/pricing');
        if (! $r['ok']) {
            return 60;                                  // ~۰٫۶ یورو، تخمینِ محافظه‌کارانه
        }

        foreach ((array) data_get($r['body'], 'pricing.primary_ips', []) as $ip) {
            if ((string) ($ip['type'] ?? '') !== 'ipv4') {
                continue;
            }

            foreach ((array) ($ip['prices'] ?? []) as $p) {
                $v = (float) data_get($p, 'price_monthly.net', 0);
                if ($v > 0) {
                    return (int) round($v * 100);       // اولین مکان کافی است؛ اختلافش ناچیز
                }
            }
        }

        return 60;
    }

    /** سیستم‌عامل‌ها + نرم‌افزارهای آماده */
    private function fetchImages(): array
    {
        $out = [];

        foreach (['system' => 'os', 'app' => 'app'] as $type => $kind) {
            foreach ($this->paged('/images', 'images', ['type' => $type, 'status' => 'available']) as $img) {
                if (! empty($img['deprecated'])) {
                    continue;
                }

                // ایمیجِ اپ گاهی `name` ندارد و باید با شناسهٔ عددی سفارش داد.
                $ref = (string) ($img['name'] ?? '') ?: (string) ($img['id'] ?? '');
                if ($ref === '') {
                    continue;
                }

                $label = (string) ($img['description'] ?? $ref);
                $family = (string) ($img['os_flavor'] ?? '') ?: null;
                $version = (string) ($img['os_version'] ?? '') ?: null;

                $out[] = [
                    'provider_ref' => $ref,
                    'key'          => CloudNaming::imageKey($kind, $family, $version, $label),
                    'kind'         => $kind,
                    'family'       => $kind === 'app' ? CloudNaming::appFamily($label) : $family,
                    'version'      => $version,
                    'label'        => $label,
                    'arch'         => ((string) ($img['architecture'] ?? 'x86')) === 'arm' ? 'arm' : 'x86',
                    'min_disk_gb'  => (int) ($img['disk_size'] ?? 0),
                ];
            }
        }

        return $out;
    }

    // ───────────────────────── ساخت و مدیریت ─────────────────────────

    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'error'];

        $body = [
            'name'               => $spec['name'],
            'server_type'        => $spec['plan_ref'],
            'location'           => $spec['location_ref'],
            'image'              => $spec['image_ref'],
            'start_after_create' => true,
            'public_net'         => ['enable_ipv4' => true, 'enable_ipv6' => true],
        ];

        if (filled($spec['ssh_keys'] ?? null)) {
            $body['ssh_keys'] = $spec['ssh_keys'];
        }
        if (filled($spec['user_data'] ?? null)) {
            $body['user_data'] = $spec['user_data'];
        }
        if (filled($spec['labels'] ?? null)) {
            $body['labels'] = $spec['labels'];
        }

        $r = $this->req('POST', '/servers', $body);

        // idempotency: نامِ تکراری یعنی «قبلاً ساختیم» نه «نشد»
        if (! $r['ok'] && str_contains($r['message'], 'uniqueness_error')) {
            $found = $this->findByName($spec['name']);

            if ($found !== null) {
                return [
                    'ok' => true, 'message' => 'سرور از قبل ساخته شده بود.',
                    'ref' => (string) $found['id'],
                    'ipv4' => data_get($found, 'public_net.ipv4.ip'),
                    'ipv6' => data_get($found, 'public_net.ipv6.ip'),
                    // رمز فقط لحظهٔ ساخت برمی‌گردد؛ اینجا نداریم
                    'root_password' => null,
                    'status' => $this->mapStatus((string) ($found['status'] ?? '')),
                ];
            }
        }

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']] + $fail;
        }

        $srv = (array) ($r['body']['server'] ?? []);

        return [
            'ok' => true, 'message' => '',
            'ref'           => (string) ($srv['id'] ?? ''),
            'ipv4'          => data_get($srv, 'public_net.ipv4.ip'),
            'ipv6'          => data_get($srv, 'public_net.ipv6.ip'),
            'root_password' => $r['body']['root_password'] ?? null,
            'status'        => $this->mapStatus((string) ($srv['status'] ?? 'initializing')),
            'raw'           => ['server_type' => data_get($srv, 'server_type.name')],
        ];
    }

    private function findByName(string $name): ?array
    {
        $r = $this->req('GET', '/servers', ['name' => $name]);

        return $r['ok'] ? ((array) ($r['body']['servers'][0] ?? [])) ?: null : null;
    }

    public function serverStatus(string $ref): array
    {
        $r = $this->req('GET', '/servers/'.rawurlencode($ref));

        if (! $r['ok']) {
            return [
                'ok' => false, 'message' => $r['message'], 'status' => 'unknown',
                'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null,
            ];
        }

        $srv = (array) ($r['body']['server'] ?? []);
        $out = (int) ($srv['outgoing_traffic'] ?? 0);

        return [
            'ok' => true, 'message' => '',
            'status'          => $this->mapStatus((string) ($srv['status'] ?? '')),
            'ipv4'            => data_get($srv, 'public_net.ipv4.ip'),
            'ipv6'            => data_get($srv, 'public_net.ipv6.ip'),
            'traffic_used_gb' => round($out / self::GB, 2),
            'raw'             => [
                'plan'       => data_get($srv, 'server_type.name'),
                'created'    => $srv['created'] ?? null,
                'rescue'     => $srv['rescue_enabled'] ?? false,
                'locked'     => $srv['locked'] ?? false,
            ],
        ];
    }

    /** وضعیتِ هتزنر → واژگانِ ما */
    private function mapStatus(string $s): string
    {
        return match ($s) {
            'running'                              => 'running',
            'off', 'stopping'                      => 'off',
            'initializing', 'starting', 'migrating', 'rebuilding' => 'building',
            'deleting'                             => 'deleted',
            default                                => 'unknown',
        };
    }

    public function power(string $ref, string $action): array
    {
        // «خاموش کردن» را عمداً `shutdown` (نرم، ACPI) می‌فرستیم نه `poweroff`
        // (کشیدنِ برق). poweroff روی سرورِ دیتابیس‌دار داده را خراب می‌کند.
        $path = match ($action) {
            'on'       => 'poweron',
            'off'      => 'shutdown',
            'shutdown' => 'shutdown',
            'reboot'   => 'reboot',
            'reset'    => 'reset',
            default    => null,
        };

        if ($path === null) {
            return ['ok' => false, 'message' => 'عملیاتِ ناشناخته.'];
        }

        $r = $this->req('POST', '/servers/'.rawurlencode($ref).'/actions/'.$path);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        // هتزنر رمز را خودش می‌سازد و پارامترِ رمزِ دلخواه ندارد.
        $r = $this->req('POST', '/servers/'.rawurlencode($ref).'/actions/rebuild', ['image' => $imageRef]);

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'root_password' => $r['body']['root_password'] ?? null,
        ];
    }

    public function resetPassword(string $ref): array
    {
        $r = $this->req('POST', '/servers/'.rawurlencode($ref).'/actions/reset_password');

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'root_password' => $r['body']['root_password'] ?? null,
        ];
    }

    public function console(string $ref): array
    {
        $r = $this->req('POST', '/servers/'.rawurlencode($ref).'/actions/request_console');

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'url'      => $r['body']['wss_url'] ?? null,
            'password' => $r['body']['password'] ?? null,
        ];
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        $hours = match ($window) {
            '1h'  => 1,
            '7d'  => 168,
            '30d' => 720,
            default => 24,
        };

        // گامِ نمونه‌برداری: ~۱۲۰ نقطه در هر بازه، تا نمودار هم روان باشد هم
        // پاسخ کوچک بماند.
        $step = max(60, (int) round($hours * 3600 / 120));

        $r = $this->req('GET', '/servers/'.rawurlencode($ref).'/metrics', [
            'type'  => 'cpu,network',
            'start' => now()->subHours($hours)->toIso8601String(),
            'end'   => now()->toIso8601String(),
            'step'  => $step,
        ]);

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'series' => []];
        }

        $series = [];
        foreach ((array) data_get($r['body'], 'metrics.time_series', []) as $name => $data) {
            $key = match ($name) {
                'cpu'                 => 'cpu',
                'network.0.bandwidth.in'  => 'net_in',
                'network.0.bandwidth.out' => 'net_out',
                default               => null,
            };

            if ($key === null) {
                continue;
            }

            // مقادیر رشته‌ای می‌آیند: [[unix_ts, "1.23"], …]
            $series[$key] = array_map(
                fn ($p) => [(int) ($p[0] ?? 0), (float) ($p[1] ?? 0)],
                (array) ($data['values'] ?? [])
            );
        }

        return ['ok' => true, 'message' => '', 'series' => $series];
    }

    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
    {
        // هتزنر برای change_type سرورِ **خاموش** می‌خواهد. اگر روشن باشد خطای
        // روشنی می‌دهد؛ همان را به مشتری نشان می‌دهیم تا خودش خاموش کند.
        $r = $this->req('POST', '/servers/'.rawurlencode($ref).'/actions/change_type', [
            'server_type'  => $planRef,
            'upgrade_disk' => $upgradeDisk,
        ]);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function deleteServer(string $ref): array
    {
        $r = $this->req('DELETE', '/servers/'.rawurlencode($ref));

        // ۴۰۴ یعنی از قبل نیست — برای خاتمهٔ سرویس همان «موفق» است
        if (! $r['ok'] && $r['status'] === 404) {
            return ['ok' => true, 'message' => 'سرور از قبل حذف شده بود.'];
        }

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }
}
