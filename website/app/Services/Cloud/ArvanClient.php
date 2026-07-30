<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * درایورِ ابرآروان (ArvanCloud ECC) — زیرساختِ **ایرانی**.
 *
 * نامِ فیلدها و مسیرها **حدسی نیست**: از Terraform-providerِ رسمیِ خودِ آروان
 * (github.com/arvancloud/terraform-provider-arvan) درآمده — همان‌جا که
 * ساختارها تایپ‌شده نوشته شده‌اند.
 *
 * ═══ سه تفاوتِ مهم با هتزنر/آیزا ═══
 *
 * ۱) **سفیدبرچسبی اینجا هم برقرار است، ولی به دلیلِ دیگری.** آروان زیرساختِ
 *    ایرانی است و مشتری از دیدنِ «سرور تهران» می‌فهمد ایرانی است — این اشکالی
 *    ندارد. ولی نامِ **خودِ برند** («ابرآروان») باز هم پنهان می‌مانَد، چون
 *    کارفرما آن را در کنارِ هتزنر/آیزا به‌عنوان یک زیرساختِ بی‌نام می‌فروشد و
 *    نمی‌خواهد رقیبش بداند از کجا می‌خرد. پس مثلِ بقیه: فقط مکان دیده می‌شود.
 *
 * ۲) **قیمت‌ها به تومان‌اند، نه یورو.** آروان `price_per_month` را عددِ اعشاری
 *    به **ریال/تومانِ ایران** می‌دهد. پس زنجیرهٔ «یورو → تومان»ِ بقیهٔ درایورها
 *    این‌جا لازم نیست: عدد مستقیم قیمتِ پایهٔ تومانی است. برای یکدست‌ماندن با
 *    قرارداد، آن را به «سنتِ یورویِ معادل» تبدیل می‌کنیم تا `CloudPricing` بتواند
 *    مثلِ بقیه رویش حاشیهٔ سود بگذارد — با نرخِ روزِ یورو، معکوسِ کاری که برای
 *    آیزا می‌کنیم.
 *
 * ۳) **ساختِ سرور به شبکه نیاز دارد.** برخلافِ هتزنر که خودش IP می‌دهد، آروان
 *    `network_ids` می‌خواهد. شبکهٔ عمومیِ پیش‌فرضِ هر منطقه را یک‌بار پیدا و
 *    نگه می‌داریم.
 */
class ArvanClient implements CloudProvider
{
    private const BASE = 'https://napi.arvancloud.ir';

    /** پیشوندِ همهٔ مسیرهای ECC */
    private const ECC = '/ecc/v1';

    public function slug(): string
    {
        return 'arvan';
    }

    private function token(): ?string
    {
        return Setting::getSecret('arvan_api_token');
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function capabilities(): array
    {
        return [
            'console' => false,          // کنسولِ تحتِ وب در API عمومی نیست
            'rebuild' => true,
            'resize'  => true,           // change-flavor
            'snapshot' => true,
            'metrics' => false,
            'reset_password' => true,
            'ipv6' => false,
            'rescue' => false,
            'ssh_key' => true,
            'extra_ip' => false,         // floating IP جدا API دارد؛ فعلاً نه
        ];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        // آروان کلیدِ SSH را per-region نگه می‌دارد و ساختش نیاز به region دارد،
        // پس نمی‌شود این‌جا (بی‌منطقه) بارگذاری کرد. تحویل با رمزِ root انجام
        // می‌شود؛ اگر روزی لازم شد، در createServer per-region ساخته می‌شود.
        return ['ok' => false, 'message' => 'بارگذاریِ کلیدِ SSH برای این سرور در دسترس نیست.', 'ref' => null];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این سرور در دسترس نیست.', 'ips' => []];
    }

    // ───────────────────────── لایهٔ تماس ─────────────────────────

    /**
     * ⚠️ ساختارِ پاسخ: آروان همه‌چیز را در `{"data": …}` می‌پیچد و **کدِ HTTP
     * را درست می‌دهد** (برخلافِ زحل/آیزا). ولی پیامِ خطا هم در بدنه است، پس
     * هم کد را می‌بینیم هم `message`.
     *
     * @return array{ok:bool,status:int,data:mixed,message:string}
     */
    private function req(string $method, string $path, array $payload = [], array $query = []): array
    {
        $token = $this->token();

        if (blank($token)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'توکنِ زیرساختِ ایرانی تنظیم نشده است.'];
        }

        try {
            // ⚠️ کلیدِ آروان **خودش** شاملِ پیشوندِ «Apikey » است (کاربر همان را
            // از پنلِ آروان کپی می‌کند)، پس عیناً در هدر می‌نشیند — نه Bearer،
            // نه پیشوندِ دستی. این را از api.go رسمی گرفتیم: `Set("Authorization", ApiKey)`.
            $http = Http::withHeaders(['Authorization' => $token])
                ->acceptJson()
                ->timeout(30)
                ->connectTimeout(10);

            $url = self::BASE.$path;

            $res = match (strtoupper($method)) {
                'GET'    => $http->get($url, $query),
                'POST'   => $http->post($url, $payload),
                'PATCH'  => $http->patch($url, $payload),
                'DELETE' => $http->delete($url, $payload),
                default  => throw new \InvalidArgumentException($method),
            };
        } catch (\Throwable $e) {
            Log::warning('arvan.transport', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'ارتباط با زیرساخت برقرار نشد.'];
        }

        $body = (array) ($res->json() ?? []);
        $data = $body['data'] ?? $body;

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'data' => $data, 'message' => (string) ($body['message'] ?? '')];
        }

        $msg = (string) ($body['message'] ?? data_get($body, 'errors.0') ?? 'خطای نامشخص');

        return ['ok' => false, 'status' => $res->status(), 'data' => $data, 'message' => $msg];
    }

    public function testConnection(): array
    {
        $r = $this->req('GET', self::ECC.'/regions/details');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }

        $n = count($this->creatableRegions($r['data']));

        return [
            'ok' => true,
            'message' => "اتصال برقرار است — {$n} منطقهٔ قابلِ ساخت.",
            'meta' => ['regions' => $n],
        ];
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];

        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'توکنِ ابرآروان تنظیم نشده.'] + $empty;
        }

        $r = $this->req('GET', self::ECC.'/regions/details');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']] + $empty;
        }

        $regions = $this->creatableRegions($r['data']);

        if ($regions === []) {
            return ['ok' => false, 'message' => 'هیچ منطقهٔ قابلِ ساختی برنگشت.'] + $empty;
        }

        // نرخِ یورو برای تبدیلِ معکوس (تومان → سنتِ یورو). بی‌آن، نمی‌شود قیمت را
        // مثلِ بقیهٔ زیرساخت‌ها در یک واحد نگه داشت و حاشیهٔ سود گذاشت.
        $eurToman = $this->eurToman();

        if ($eurToman <= 0) {
            return ['ok' => false, 'message' => 'نرخِ یورو در دسترس نبود؛ قیمت‌گذاری انجام نشد.'] + $empty;
        }

        $locations = [];
        $plans = [];
        $images = [];
        $seenImageKey = [];

        foreach ($regions as $region) {
            $code = (string) ($region['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $country = strtoupper((string) ($region['country'] ?? 'IR'));
            $city = $this->cityOf($region);

            $locCode = CloudNaming::locationCode($country, $city, $code);

            $locations[$locCode] = [
                'code'              => $locCode,
                'country'           => $country,
                'city'              => $city ?: null,
                'provider_location' => $code,     // کدِ منطقهٔ آروان برای ساخت
                'latitude'          => null,
                'longitude'         => null,
            ];

            // پلن‌ها (sizes) این منطقه
            foreach ($this->regionSizes($code) as $size) {
                $plan = $this->mapSize($size, $code, $locCode, $eurToman);

                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }

            // ایمیج‌ها یک‌بار کافی‌اند (بین مناطق تکرارند)، ولی چون شناسه
            // per-region است، همه را با منطقه‌شان نگه می‌داریم.
            foreach ($this->regionImages($code) as $img) {
                $key = $img['key'];

                // برای فهرستِ مشتری فقط یک‌بار (unique key)؛ ولی refِ per-region
                // را نگه می‌داریم تا تحویل روی همان منطقه درست باشد.
                $dedupe = $key.'@'.$code;

                if (isset($seenImageKey[$dedupe])) {
                    continue;
                }

                $seenImageKey[$dedupe] = true;
                $images[] = $img;
            }
        }

        return [
            'ok' => true, 'message' => '',
            'locations' => array_values($locations),
            'plans'     => $plans,
            'images'    => $images,
        ];
    }

    /** فقط مناطقی که واقعاً می‌شود در آنها سرور ساخت */
    private function creatableRegions(mixed $data): array
    {
        $out = [];

        foreach ((array) $data as $region) {
            if (! is_array($region)) {
                continue;
            }

            // `create` یعنی ساختِ سرور مجاز است؛ `soon`/`visible=false` را رد کن
            if (($region['create'] ?? false) === true && ($region['visible'] ?? true) !== false) {
                $out[] = $region;
            }
        }

        return $out;
    }

    private function cityOf(array $region): string
    {
        // `dc` نامِ خواناتری از `city_code` دارد (مثلِ «Tehran»)، وگرنه از code
        foreach (['dc', 'city_code', 'region'] as $k) {
            $v = trim((string) ($region[$k] ?? ''));

            if ($v !== '') {
                return $v;
            }
        }

        return (string) ($region['code'] ?? '');
    }

    private function regionSizes(string $regionCode): array
    {
        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/sizes');

        return $r['ok'] ? (array) $r['data'] : [];
    }

    /**
     * یک size آروان → ساختارِ پلنِ ما.
     *
     * قیمت: `price_per_month` تومان است. حاشیهٔ سود را CloudPricing می‌گذارد،
     * پس این‌جا فقط بهایِ تمام‌شده را به سنتِ یورویِ معادل تبدیل می‌کنیم تا با
     * بقیهٔ زیرساخت‌ها یکدست بماند.
     */
    private function mapSize(array $size, string $regionCode, string $locCode, float $eurToman): ?array
    {
        $ref = (string) ($size['id'] ?? '');
        $vcpu = (int) ($size['cpu_count'] ?? 0);
        $ram = (int) ($size['memory'] ?? 0);        // مگابایت
        $disk = (int) ($size['disk'] ?? 0);         // گیگابایت
        $priceToman = (float) ($size['price_per_month'] ?? 0);

        // مشخصاتِ ناقص = رد (پلنِ صفرهسته روی سایت فاجعه است)
        if ($ref === '' || $vcpu < 1 || $ram < 128 || $disk < 1 || $priceToman <= 0) {
            return null;
        }

        // پلنِ تخفیف‌دارِ موقت (off) را کنار بگذار — مثلِ PROMOیِ آیزا، نرخِ
        // تمدیدش پایدار نیست و قیمتِ قفل‌شده ضرر می‌دهد.
        if (Setting::get('arvan_include_promo') !== '1'
            && (($size['off'] ?? '') !== '' || ((float) ($size['off_percent'] ?? 0)) > 0)) {
            return null;
        }

        // بهایِ تمام‌شده به سنتِ یورو: تومان ÷ (تومانِ هر یورو) × ۱۰۰
        $costEurCents = (int) round(($priceToman / $eurToman) * 100);

        if ($costEurCents <= 0) {
            return null;
        }

        // `cpu_share` نوعِ پردازنده را می‌گوید (dedicated / shared / general)
        $share = strtolower((string) ($size['cpu_share'] ?? ''));
        $cpuKind = str_contains($share, 'dedicat') ? 'dedicated' : 'shared';

        return [
            'provider_ref'      => $ref,
            'provider_location' => $regionCode,
            'location_code'     => $locCode,
            'name'              => (string) ($size['name'] ?? $ref),
            'vcpu'              => $vcpu,
            'ram_mb'            => $ram,
            'disk_gb'           => $disk,
            'disk_type'         => 'ssd',
            'traffic_gb'        => 0,                // آروان مصرفِ منصفانه دارد
            'cpu_kind'          => $cpuKind,
            'arch'              => 'x86',
            'cost_eur_cents'    => $costEurCents,
            'in_stock'          => true,
        ];
    }

    private function regionImages(string $regionCode): array
    {
        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/images', [], ['type' => 'distributions']);

        if (! $r['ok']) {
            return [];
        }

        $out = [];

        // ساختار: [{name: "Ubuntu", images: [{id, name, ...}]}] یا فهرستِ تخت
        foreach ((array) $r['data'] as $group) {
            $children = is_array($group['images'] ?? null) ? $group['images'] : [$group];
            $family = strtolower((string) ($group['name'] ?? ''));

            foreach ($children as $img) {
                if (! is_array($img)) {
                    continue;
                }

                $ref = (string) ($img['id'] ?? '');
                $label = (string) ($img['name'] ?? $img['distribution_name'] ?? $ref);

                if ($ref === '' || $label === '') {
                    continue;
                }

                [$fam, $ver] = $this->splitOs($label, $family);

                $out[] = [
                    'provider_ref' => $ref,
                    'key'          => CloudNaming::imageKey('os', $fam, $ver, $label),
                    'kind'         => 'os',
                    'family'       => $fam,
                    'version'      => $ver,
                    'label'        => $label,
                    'arch'         => 'x86',
                    'min_disk_gb'  => (int) ($img['disk'] ?? $img['min_disk'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** «Ubuntu 22.04» → ['ubuntu', '22.04'] */
    private function splitOs(string $label, string $familyHint): array
    {
        if (preg_match('/^\s*([A-Za-z][A-Za-z\s]*?)\s*v?([0-9]+(?:\.[0-9]+)*)/', $label, $m)) {
            return [CloudNaming::slug($m[1]), $m[2]];
        }

        return [$familyHint !== '' ? CloudNaming::slug($familyHint) : CloudNaming::slug($label), ''];
    }

    /**
     * نرخِ یک یورو به تومان — همان منبعِ CloudPricing، تا هر دو یکی ببینند.
     */
    private function eurToman(): int
    {
        $override = (int) Setting::get('pricing_rate_override', '0');

        if ($override > 0) {
            return $override;
        }

        try {
            return (int) (app(\App\Services\ExchangeRate::class)->toToman('EUR') ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    // ───────────────────────── ساخت و مدیریت ─────────────────────────

    /**
     * شبکهٔ عمومیِ پیش‌فرضِ یک منطقه — برای ساختِ سرور لازم است.
     *
     * آروان بی‌network_ids سرور نمی‌سازد. اولین شبکهٔ عمومی را می‌گیریم و
     * per-region کش می‌کنیم (شبکه‌ها به‌ندرت عوض می‌شوند).
     */
    private function publicNetworkId(string $regionCode): ?string
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'arvan.net.'.$regionCode,
            3600,
            function () use ($regionCode) {
                $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($regionCode).'/networks');

                if (! $r['ok']) {
                    return null;
                }

                // شبکهٔ عمومی: اولی که gateway دارد یا نامش public است
                foreach ((array) $r['data'] as $net) {
                    $id = (string) ($net['network_id'] ?? $net['id'] ?? '');
                    $name = strtolower((string) ($net['name'] ?? ''));

                    if ($id !== '' && (str_contains($name, 'public') || ($net['enable_gateway'] ?? false))) {
                        return $id;
                    }
                }

                // وگرنه اولین شبکه
                $first = (array) (($r['data'][0] ?? []));

                return (string) ($first['network_id'] ?? $first['id'] ?? '') ?: null;
            }
        );
    }

    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'error'];

        $region = (string) $spec['location_ref'];
        $networkId = $this->publicNetworkId($region);

        if ($networkId === null) {
            return ['ok' => false, 'message' => 'شبکهٔ عمومیِ این منطقه پیدا نشد.'] + $fail;
        }

        // idempotency: نامِ قطعی. اگر از قبل ساخته شده، همان را برگردان.
        $existing = $this->findByName($region, $spec['name']);

        if ($existing !== null) {
            return $this->serverToResult($existing, $region);
        }

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers', [
            'name'        => $spec['name'],
            'flavor_id'   => (string) $spec['plan_ref'],
            'image_id'    => (string) $spec['image_ref'],
            'network_ids' => [$networkId],
            'disk_size'   => (int) ($spec['disk_gb'] ?? 25),
            'count'       => 1,
            'ha_enabled'  => false,
        ]);

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']] + $fail;
        }

        // پاسخ ممکن است تکِ سرور یا آرایه (count) باشد
        $server = is_array($r['data'][0] ?? null) ? $r['data'][0] : (array) $r['data'];

        $out = $this->serverToResult($server, $region);
        // رمزِ root فقط لحظهٔ ساخت برمی‌گردد
        $out['root_password'] = $server['password'] ?? null;

        return $out;
    }

    private function findByName(string $region, string $name): ?array
    {
        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($region).'/servers');

        if (! $r['ok']) {
            return null;
        }

        foreach ((array) $r['data'] as $s) {
            if (is_array($s) && (string) ($s['name'] ?? '') === $name) {
                return $s;
            }
        }

        return null;
    }

    /** @return array{ok:bool,message:string,ref:?string,ipv4:?string,ipv6:?string,root_password:?string,status:string} */
    private function serverToResult(array $server, string $region): array
    {
        $id = (string) ($server['id'] ?? '');

        return [
            'ok' => true, 'message' => '',
            // ⚠️ ref را `region:id` می‌کنیم چون آروان **region-محور** است و هر
            // عملیاتِ بعدی (روشن/خاموش/حذف) به کدِ منطقه نیاز دارد، ولی قرارداد
            // فقط یک `$ref` می‌دهد. `split()` بعداً بازش می‌کند.
            'ref'  => $id !== '' ? $region.':'.$id : null,
            'ipv4' => $this->firstIp($server),
            'ipv6' => null,
            'root_password' => null,
            'status' => $this->mapStatus((string) ($server['status'] ?? '')),
            'raw' => ['region' => $region],
        ];
    }

    private function firstIp(array $server): ?string
    {
        foreach (['addresses', 'ips', 'ip', 'public_ip'] as $path) {
            $v = data_get($server, $path);

            foreach ((is_array($v) ? \Illuminate\Support\Arr::flatten($v) : [$v]) as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'active', 'running'                       => 'running',
            'shutoff', 'stopped', 'paused', 'suspended' => 'off',
            'build', 'building', 'rebuild', 'creating', 'installing' => 'building',
            'deleted', 'deleting'                     => 'deleted',
            default                                   => 'unknown',
        };
    }

    /**
     * ⚠️ آروان region-محور است: هر عملیات به کدِ منطقه نیاز دارد، ولی ما فقط
     * شناسهٔ سرور را داریم. منطقه را در `provider_location`ِ نمونه نگه داشته‌ایم
     * و از آن‌جا می‌آید — ولی امضای قرارداد فقط `$ref` می‌دهد. پس شناسه را
     * `region:id` رمزگذاری می‌کنیم تا هر متد بتواند بازش کند.
     */
    private function split(string $ref): array
    {
        return str_contains($ref, ':') ? explode(':', $ref, 2) : ['', $ref];
    }

    public function serverStatus(string $ref): array
    {
        [$region, $id] = $this->split($ref);
        $none = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];

        if ($region === '') {
            return ['ok' => false, 'message' => 'منطقهٔ سرور نامشخص است.', 'status' => 'unknown'] + $none;
        }

        $r = $this->req('GET', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id));

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'status' => 'unknown'] + $none;
        }

        $s = (array) $r['data'];

        return [
            'ok' => true, 'message' => '',
            'status'          => $this->mapStatus((string) ($s['status'] ?? '')),
            'ipv4'            => $this->firstIp($s),
            'ipv6'            => null,
            'traffic_used_gb' => null,
        ];
    }

    public function power(string $ref, string $action): array
    {
        [$region, $id] = $this->split($ref);

        $path = match ($action) {
            'on'       => 'power-on',
            'off', 'shutdown' => 'power-off',
            'reboot', 'reset' => 'reboot',
            default    => null,
        };

        if ($path === null || $region === '') {
            return ['ok' => false, 'message' => 'عملیاتِ نامعتبر.'];
        }

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/'.$path);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/rebuild', [
            'image_id' => $imageRef,
        ]);

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'root_password' => data_get($r['data'], 'password'),
        ];
    }

    public function resetPassword(string $ref): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/reset-root-password');

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            // آروان رمزِ تازه را در پاسخ یا با ایمیل می‌دهد؛ اگر در پاسخ بود، بده
            'root_password' => data_get($r['data'], 'password'),
        ];
    }

    public function console(string $ref): array
    {
        return ['ok' => false, 'message' => 'کنسولِ تحتِ وب برای این سرور در دسترس نیست.', 'url' => null, 'password' => null];
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        return ['ok' => false, 'message' => 'نمودارِ مصرف برای این سرور در دسترس نیست.', 'series' => []];
    }

    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('POST', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id).'/resize', [
            'flavor_id' => $planRef,
        ]);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function deleteServer(string $ref): array
    {
        [$region, $id] = $this->split($ref);

        $r = $this->req('DELETE', self::ECC.'/regions/'.rawurlencode($region).'/servers/'.rawurlencode($id));

        if (! $r['ok'] && $r['status'] === 404) {
            return ['ok' => true, 'message' => 'سرور از قبل حذف شده بود.'];
        }

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }
}
