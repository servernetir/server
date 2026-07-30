<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * درایورِ Aeza (my.aeza.net/api).
 *
 * ⚠️ صداقت دربارهٔ درجهٔ اطمینان — این را دست‌کم نگیر:
 *
 *  • **قطعی** (از داکیومنتِ رسمیِ AezaGroup/dev-docs): آدرسِ پایه
 *    `https://my.aeza.net/api`، هدرِ `X-API-Key`، مسیرهای `/products`، `/os`،
 *    `/vm/recipe`، `POST /services/orders` با فیلدهای
 *    `count,term,name,productId,parameters,autoProlong,method`،
 *    `GET /services/orders/{id}` با `createdServiceIds`، `GET /services/{id}`،
 *    `POST /services/{id}/ctl` با `action`، `POST /services/{id}/reinstall` با
 *    `os,recipe,password`، `PUT /services/{id}/changePassword`،
 *    `DELETE /services/{id}`، پاسخِ فهرستی با `items`/`total`، و اینکه
 *    **قیمت‌ها سمتِ سرور به روبل‌اند** و ضریبِ تبدیل از `payment/currencies`.
 *
 *  • **استنتاجی**: نامِ دقیقِ فیلدهای داخلِ هر محصول (هسته/رم/دیسک/مکان). داکیومنت
 *    نمونهٔ کاملِ JSON نداشت. پس اینجا هر مقدار از **چند مسیرِ ممکن** خوانده
 *    می‌شود و اگر هیچ‌کدام نبود، آن محصول رد می‌شود نه اینکه با عددِ صفر ذخیره
 *    شود (پلنِ صفرهسته/صفرگیگ روی سایت، از نبودنِ پلن بدتر است).
 *
 *  • برای بستنِ همین شکاف، `rawProbe()` هست: در پنلِ مدیریت یک دکمه، ساختارِ
 *    واقعیِ JSON را نشان می‌دهد تا بی‌حدس‌وگمان دقیق شود.
 *
 * Aeza کنسولِ تحتِ وب و نمودارِ مصرف را در API عمومی‌اش ندارد، پس
 * `capabilities()` آنها را false می‌گوید و رابطِ کاربری دکمهٔ بی‌فایده نمی‌سازد.
 */
class AezaClient implements CloudProvider
{
    private const BASE = 'https://my.aeza.net/api';

    /**
     * ⚠️ چرا «نامزدِ مسیر» و نه مسیرِ ثابت.
     *
     * متنِ داکیومنت مسیرِ فهرستِ محصولات را `/products` می‌نویسد، ولی نمونهٔ
     * `curl` همان صفحه `…/api/services/products` است. یکی از این دو غلط است و
     * ما از بیرون نمی‌دانیم کدام.
     *
     * بدتر: گیت‌وی این ارائه‌دهنده برای مسیرِ ناموجود **۴۰۴ تمیز نمی‌دهد**؛
     * «Proxy internal server error» می‌دهد. یعنی خطای «مسیر را غلط زدی» شکلِ
     * خطای «سرورشان خراب است» را دارد و ساعت‌ها می‌شود دنبالِ توکن و شبکه گشت.
     * دقیقاً همین اتفاق افتاد.
     *
     * راهِ درست: یک‌بار نامزدها را امتحان کن، برندهٔ درست را **ذخیره کن** و از
     * آن به بعد فقط همان را بزن. نه حدس می‌زنیم، نه هر بار چند درخواستِ اضافه
     * می‌فرستیم.
     *
     * @var array<string, array<int, string>>
     */
    private const PATH_CANDIDATES = [
        'products' => ['services/products', 'products'],
        'os'       => ['os', 'services/os', 'vm/os'],
        'recipe'   => ['vm/recipe', 'services/recipe', 'recipes'],
    ];

    public function slug(): string
    {
        return 'aeza';
    }

    private function token(): ?string
    {
        return Setting::getSecret('aeza_api_token');
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function capabilities(): array
    {
        return [
            'console' => false, 'rebuild' => true, 'resize' => false,
            'snapshot' => false, 'metrics' => false, 'reset_password' => true,
            'ipv6' => true, 'rescue' => false,
        ];
    }

    // ───────────────────────── لایهٔ تماس ─────────────────────────

    /** @return array{ok:bool,status:int,body:array,message:string} */
    private function req(string $method, string $path, array $payload = []): array
    {
        $token = $this->token();

        if (blank($token)) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'message' => 'توکنِ ارائه‌دهندهٔ دومِ ابری تنظیم نشده است.'];
        }

        try {
            $http = Http::withHeaders(['X-API-Key' => $token])
                ->acceptJson()
                ->timeout(25)
                ->connectTimeout(10);

            $url = self::BASE.'/'.ltrim($path, '/');

            $res = match (strtoupper($method)) {
                'GET'    => $http->get($url, $payload),
                'POST'   => $http->post($url, $payload),
                'PUT'    => $http->put($url, $payload),
                'DELETE' => $http->delete($url, $payload),
                default  => throw new \InvalidArgumentException($method),
            };
        } catch (\Throwable $e) {
            Log::warning('aeza.transport', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'message' => 'ارتباط با ارائه‌دهنده برقرار نشد.'];
        }

        $body = (array) ($res->json() ?? []);

        // برخلافِ هتزنر، مطمئن نیستیم Aeza همیشه کدِ HTTP را درست می‌دهد. پس
        // **هم** کدِ HTTP را می‌بینیم **هم** فیلدِ خطای بدنه — درسِ زحل و
        // Cloudflare: هرگز فقط به کدِ HTTP تکیه نکن.
        $err = $body['error'] ?? null;

        if ($res->successful() && blank($err)) {
            return ['ok' => true, 'status' => $res->status(), 'body' => $body, 'message' => ''];
        }

        $msg = is_array($err)
            ? (string) ($err['message'] ?? $err['slug'] ?? json_encode($err, JSON_UNESCAPED_UNICODE))
            : (string) ($err ?: ($body['message'] ?? 'خطای نامشخص'));

        return ['ok' => false, 'status' => $res->status(), 'body' => $body, 'message' => $msg];
    }

    /**
     * ردیف‌های یک پاسخِ فهرستی. Aeza پاسخ را در `data` می‌پیچد و فهرست را در
     * `items` می‌گذارد، ولی هر دو حالت (با و بی `data`) پذیرفته می‌شود.
     */
    private function items(array $body): array
    {
        foreach ([['data', 'items'], ['items'], ['data']] as $path) {
            $v = data_get($body, implode('.', $path));

            if (is_array($v) && $v !== [] && array_is_list($v)) {
                return $v;
            }
            if (is_array($v) && isset($v['items']) && is_array($v['items'])) {
                return $v['items'];
            }
        }

        return [];
    }

    /**
     * مسیرِ درستِ یک منبع — یک‌بار کشف، بعد از آن از تنظیمات خوانده می‌شود.
     *
     * `$force` برای صفحهٔ عیب‌یابی است تا کشف را از نو انجام دهد.
     */
    private function resolvePath(string $key, bool $force = false): ?string
    {
        $settingKey = 'aeza_path_'.$key;

        if (! $force && filled($saved = Setting::get($settingKey))) {
            return (string) $saved;
        }

        foreach (self::PATH_CANDIDATES[$key] ?? [] as $candidate) {
            $r = $this->req('GET', '/'.$candidate, ['count' => 1]);

            // «موفق» یعنی مسیر وجود دارد. پاسخِ خالی هم قبول است (شاید حساب
            // فعلاً محصولی ندارد) — مهم این است که خطا نداد.
            if ($r['ok']) {
                Setting::put($settingKey, $candidate);

                return $candidate;
            }

            // ۴۰۱/۴۰۳ یعنی مسیر درست است ولی **توکن** مشکل دارد؛ ادامهٔ امتحانِ
            // مسیرها بی‌فایده است و فقط درخواستِ اضافه می‌فرستد.
            if (in_array($r['status'], [401, 403], true)) {
                return null;
            }
        }

        return null;
    }

    public function testConnection(): array
    {
        $path = $this->resolvePath('products', true);

        if ($path === null) {
            // پیامِ خامِ آخرین تلاش را بده — بی‌آن، مدیر نمی‌داند توکن غلط است
            // یا مسیر یا شبکه.
            $r = $this->req('GET', '/'.self::PATH_CANDIDATES['products'][0], ['count' => 1]);

            return [
                'ok' => false,
                'message' => $r['message'].' (کدِ HTTP: '.$r['status'].') — اگر «Proxy internal server error» است، '
                    .'یعنی مسیر روی گیت‌وی آنها شناخته نشد؛ صفحهٔ «ساختارِ خامِ پاسخ» را ببینید.',
            ];
        }

        $r = $this->req('GET', '/'.$path, ['count' => 5]);
        $n = count($this->items($r['body']));

        return [
            'ok' => true,
            'message' => $n > 0
                ? "اتصال برقرار است — {$n} محصول خوانده شد (مسیر: {$path})."
                : "اتصال برقرار است ولی محصولی برنگشت (مسیر: {$path}).",
            'meta' => ['products' => $n, 'path' => $path],
        ];
    }

    /**
     * ساختارِ خامِ پاسخ‌ها برای عیب‌یابی در پنلِ مدیریت.
     *
     * چرا لازم است: نامِ فیلدهای محصول در داکیومنت نبود. با یک نگاه به خروجیِ
     * این متد، نگاشتِ زیر دقیق می‌شود — به‌جای حدس‌زدن.
     */
    public function rawProbe(): array
    {
        $out = [];

        foreach (self::PATH_CANDIDATES as $key => $candidates) {
            $out[$key] = ['tried' => []];

            foreach ($candidates as $path) {
                $r = $this->req('GET', '/'.$path, ['count' => 2]);
                $items = $r['ok'] ? $this->items($r['body']) : [];

                // برای هر نامزد: کدِ HTTP، پیام، و **دو ردیفِ خامِ اول**.
                // ردیفِ خام همان چیزی است که نگاشتِ فیلدها را قطعی می‌کند
                // (نامِ کلیدهای هسته/رم/دیسک/مکان در داکیومنت نبود).
                $out[$key]['tried'][$path] = [
                    'http'    => $r['status'],
                    'ok'      => $r['ok'],
                    'message' => $r['message'] ?: null,
                    'count'   => count($items),
                    'sample'  => array_slice($items, 0, 2),
                    // اگر ساختار را نشناختیم، کلیدهای سطحِ اولِ بدنه را نشان بده
                    'body_keys' => $items === [] && is_array($r['body']) ? array_keys($r['body']) : null,
                ];

                if ($r['ok'] && $items !== []) {
                    $out[$key]['winner'] = $path;
                    Setting::put('aeza_path_'.$key, $path);
                    break;
                }
            }
        }

        return $out;
    }

    // ───────────────────────── نرخِ ارز ─────────────────────────

    /**
     * ضریبِ روبل → یورو.
     *
     * داکیومنت: «قیمت‌ها سمتِ سرور به روبل ذخیره می‌شوند» و برای تبدیل باید
     * ضریب را از `payment/currencies` گرفت. اگر نشد، به نرخِ زندهٔ خودمان
     * برمی‌گردیم؛ و اگر آن هم نبود، ۰ برمی‌گردانیم تا **قیمتِ غلط ساخته نشود**
     * (پلنی که قیمتش را نمی‌دانیم نباید روی سایت برود).
     */
    private function rubToEurRate(): float
    {
        $r = $this->req('GET', '/payment/currencies');

        if ($r['ok']) {
            foreach ($this->items($r['body']) as $c) {
                $code = strtoupper((string) ($c['code'] ?? $c['currency'] ?? $c['name'] ?? ''));

                if ($code === 'EUR') {
                    $m = (float) ($c['multiplier'] ?? $c['rate'] ?? $c['value'] ?? 0);

                    if ($m > 0) {
                        return $m;
                    }
                }
            }
        }

        // پشتیبان: نرخِ زندهٔ خودمان (تومان) — RUB→EUR = تومانِ روبل ÷ تومانِ یورو
        try {
            $ex = app(\App\Services\ExchangeRate::class);
            $eur = (float) ($ex->toToman('EUR') ?: 0);
            $rub = (float) ($ex->toToman('RUB') ?: 0);

            if ($eur > 0 && $rub > 0) {
                return $rub / $eur;
            }
        } catch (\Throwable) {
            // بی‌صدا — پایین با ۰ برمی‌گردیم
        }

        return 0.0;
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];

        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'توکنِ Aeza تنظیم نشده.'] + $empty;
        }

        $path = $this->resolvePath('products');

        if ($path === null) {
            return ['ok' => false, 'message' => 'مسیرِ فهرستِ محصولات شناخته نشد — صفحهٔ «ساختارِ خامِ پاسخ» را ببینید.'] + $empty;
        }

        $r = $this->req('GET', '/'.$path, ['count' => 500, 'extra' => 1]);

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']] + $empty;
        }

        $rate = $this->rubToEurRate();

        if ($rate <= 0) {
            return ['ok' => false, 'message' => 'نرخِ تبدیلِ روبل به یورو به دست نیامد؛ قیمت‌گذاری انجام نشد.'] + $empty;
        }

        $locations = [];
        $plans = [];

        foreach ($this->items($r['body']) as $p) {
            // فقط سرورِ مجازی. Aeza دامنه و پروکسی و WAF هم می‌فروشد.
            $type = strtolower((string) ($p['type'] ?? $p['serviceType'] ?? ''));
            if ($type !== '' && ! in_array($type, ['vm', 'vps', 'server'], true)) {
                continue;
            }

            $ref = (string) ($p['id'] ?? '');
            if ($ref === '') {
                continue;
            }

            $specs = $this->specsOf($p);

            // مشخصاتِ ناقص = رد. پلنِ «۰ هسته / ۰ گیگ» روی سایت، فاجعهٔ اعتماد است.
            if ($specs['vcpu'] < 1 || $specs['ram_mb'] < 128 || $specs['disk_gb'] < 1) {
                continue;
            }

            $rub = $this->monthlyRub($p);
            if ($rub <= 0) {
                continue;
            }

            [$country, $city, $locRef] = $this->locationOf($p);
            if ($country === '') {
                continue;
            }

            $code = CloudNaming::locationCode($country, $city, $locRef ?: $ref);

            $locations[$code] = [
                'code'              => $code,
                'country'           => strtoupper($country),
                'city'              => $city ?: null,
                'provider_location' => $locRef,
                'latitude'          => null,
                'longitude'         => null,
            ];

            $plans[] = [
                'provider_ref'      => $ref,
                'provider_location' => $locRef,
                'location_code'     => $code,
                'name'              => (string) ($p['name'] ?? $ref),
                'vcpu'              => $specs['vcpu'],
                'ram_mb'            => $specs['ram_mb'],
                'disk_gb'           => $specs['disk_gb'],
                'disk_type'         => $specs['disk_type'],
                'traffic_gb'        => $specs['traffic_gb'],
                'cpu_kind'          => $specs['cpu_kind'],
                'arch'              => 'x86',
                'cost_eur_cents'    => (int) round($rub * $rate * 100),
                'in_stock'          => $this->inStock($p),
            ];
        }

        return [
            'ok' => true, 'message' => '',
            'locations' => array_values($locations),
            'plans'     => $plans,
            'images'    => $this->fetchImages(),
        ];
    }

    /**
     * مشخصات از محصول. کلیدها بین نسخه‌های API فرق کرده‌اند، پس چند مسیر
     * امتحان می‌شود. رم ممکن است مگابایت یا گیگابایت باشد — با بزرگیِ عدد
     * تشخیص داده می‌شود (کمتر از ۱۰۲۴ یعنی گیگابایت).
     */
    private function specsOf(array $p): array
    {
        $get = function (array $keys, $default = 0) use ($p) {
            foreach ($keys as $k) {
                $v = data_get($p, $k);
                if ($v !== null && $v !== '' && $v !== []) {
                    return $v;
                }
            }

            return $default;
        };

        $vcpu = (int) $get(['cpu', 'cores', 'vcpu', 'configuration.cpu', 'configuration.cores', 'parameters.cpu', 'specs.cpu']);
        $ramRaw = (float) $get(['ram', 'memory', 'configuration.ram', 'configuration.memory', 'parameters.ram', 'specs.ram']);
        $disk = (int) $get(['disk', 'storage', 'configuration.disk', 'configuration.storage', 'parameters.disk', 'specs.disk']);
        $traffic = (int) $get(['traffic', 'bandwidth', 'configuration.traffic', 'parameters.traffic'], 0);

        $ramMb = $ramRaw > 0 && $ramRaw < 1024 ? (int) round($ramRaw * 1024) : (int) round($ramRaw);

        // ترافیک اگر بزرگ بود احتمالاً به گیگ است؛ اگر کوچک، به ترابایت
        if ($traffic > 0 && $traffic < 100) {
            $traffic *= 1024;
        }

        $diskType = strtolower((string) $get(['diskType', 'disk_type', 'configuration.diskType'], 'nvme'));

        return [
            'vcpu'       => $vcpu,
            'ram_mb'     => $ramMb,
            'disk_gb'    => $disk,
            'disk_type'  => str_contains($diskType, 'hdd') ? 'hdd' : (str_contains($diskType, 'ssd') ? 'ssd' : 'nvme'),
            'traffic_gb' => $traffic,
            'cpu_kind'   => str_contains(strtolower((string) ($p['name'] ?? '')), 'dedicat') ? 'dedicated' : 'shared',
        ];
    }

    /** قیمتِ ماهانه به روبل */
    private function monthlyRub(array $p): float
    {
        foreach ([
            'prices.month.value', 'prices.month', 'price.month', 'payment.month',
            'prices.monthly', 'priceMonth', 'monthPrice', 'price',
        ] as $path) {
            $v = data_get($p, $path);

            if (is_array($v)) {
                $v = $v['value'] ?? $v['amount'] ?? null;
            }

            if (is_numeric($v) && (float) $v > 0) {
                $f = (float) $v;

                // بعضی نقاطِ API قیمت را در «کوپک» (صدمِ روبل) می‌دهند. عددِ
                // بی‌معنا بزرگ (بیش از ۵۰۰٫۰۰۰ روبل در ماه برای یک VPS) نشانهٔ
                // همین است؛ تقسیم بر ۱۰۰ منطقی‌ترین تفسیر است.
                return $f > 500000 ? $f / 100 : $f;
            }
        }

        return 0.0;
    }

    /** @return array{0:string,1:string,2:string} کشور، شهر، شناسهٔ مکانِ ارائه‌دهنده */
    private function locationOf(array $p): array
    {
        $country = (string) (data_get($p, 'location.country')
            ?? data_get($p, 'country')
            ?? data_get($p, 'group.country')
            ?? '');

        $city = (string) (data_get($p, 'location.city')
            ?? data_get($p, 'city')
            ?? data_get($p, 'location.name')
            ?? data_get($p, 'group.name')
            ?? '');

        $ref = (string) (data_get($p, 'location.id')
            ?? data_get($p, 'locationId')
            ?? data_get($p, 'groupId')
            ?? '');

        // اگر کشور نبود ولی شهر بود، از شهر حدس بزن (کدِ کشور برای گروه‌بندی لازم است)
        if ($country === '' && $city !== '') {
            $country = self::countryOfCity($city);
        }

        return [$country, $city, $ref];
    }

    /** حدسِ کشور از نامِ شهر — فقط برای وقتی ارائه‌دهنده کشور را نداده */
    private static function countryOfCity(string $city): string
    {
        $map = [
            'frankfurt' => 'DE', 'falkenstein' => 'DE', 'nuremberg' => 'DE', 'düsseldorf' => 'DE',
            'amsterdam' => 'NL', 'helsinki' => 'FI', 'stockholm' => 'SE', 'london' => 'GB',
            'paris' => 'FR', 'warsaw' => 'PL', 'istanbul' => 'TR', 'moscow' => 'RU',
            'saint petersburg' => 'RU', 'st. petersburg' => 'RU', 'kazan' => 'RU',
            'yekaterinburg' => 'RU', 'novosibirsk' => 'RU', 'almaty' => 'KZ',
            'yerevan' => 'AM', 'tbilisi' => 'GE', 'dubai' => 'AE', 'singapore' => 'SG',
            'tokyo' => 'JP', 'ashburn' => 'US', 'los angeles' => 'US', 'new york' => 'US',
            'miami' => 'US', 'dallas' => 'US', 'hillsboro' => 'US',
        ];

        $c = strtolower(trim($city));

        foreach ($map as $needle => $iso) {
            if (str_contains($c, $needle)) {
                return $iso;
            }
        }

        return '';
    }

    private function inStock(array $p): bool
    {
        foreach (['available', 'inStock', 'in_stock', 'stock'] as $k) {
            $v = $p[$k] ?? null;

            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return (int) $v > 0;
            }
        }

        return true;              // نبودِ فیلد = فرضِ موجود (خودِ سفارش خطا می‌دهد)
    }

    private function fetchImages(): array
    {
        $out = [];

        // سیستم‌عامل
        $osPath = $this->resolvePath('os');
        $r = $osPath === null
            ? ['ok' => false, 'body' => []]
            : $this->req('GET', '/'.$osPath, ['count' => 300]);

        if ($r['ok']) {
            foreach ($this->items($r['body']) as $os) {
                $ref = (string) ($os['id'] ?? $os['slug'] ?? '');
                $label = (string) ($os['name'] ?? $os['title'] ?? $ref);

                if ($ref === '' || $label === '') {
                    continue;
                }

                [$family, $version] = self::splitOsLabel($label, $os);

                $out[] = [
                    'provider_ref' => $ref,
                    'key'          => CloudNaming::imageKey('os', $family, $version, $label),
                    'kind'         => 'os',
                    'family'       => $family,
                    'version'      => $version,
                    'label'        => $label,
                    'arch'         => 'x86',
                    'min_disk_gb'  => (int) ($os['minDisk'] ?? $os['min_disk'] ?? 0),
                ];
            }
        }

        // نرم‌افزارهای آماده (recipe)
        $recipePath = $this->resolvePath('recipe');
        $r = $recipePath === null
            ? ['ok' => false, 'body' => []]
            : $this->req('GET', '/'.$recipePath, ['count' => 300]);

        if ($r['ok']) {
            foreach ($this->items($r['body']) as $rec) {
                $ref = (string) ($rec['id'] ?? $rec['slug'] ?? '');
                $label = (string) ($rec['name'] ?? $rec['title'] ?? $ref);

                if ($ref === '' || $label === '') {
                    continue;
                }

                $out[] = [
                    'provider_ref' => 'recipe:'.$ref,
                    'key'          => CloudNaming::imageKey('app', null, null, $label),
                    'kind'         => 'app',
                    'family'       => CloudNaming::appFamily($label),
                    'version'      => null,
                    'label'        => $label,
                    'arch'         => 'x86',
                    'min_disk_gb'  => (int) ($rec['minDisk'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** «Ubuntu 24.04» → ['ubuntu', '24.04'] */
    private static function splitOsLabel(string $label, array $row = []): array
    {
        $family = (string) ($row['os'] ?? $row['family'] ?? '');
        $version = (string) ($row['version'] ?? '');

        if ($family !== '' && $version !== '') {
            return [strtolower($family), $version];
        }

        if (preg_match('/^\s*([A-Za-z][A-Za-z\s]*?)\s*v?([0-9]+(?:\.[0-9]+)*)/', $label, $m)) {
            return [CloudNaming::slug($m[1]), $m[2]];
        }

        return [CloudNaming::slug($label), ''];
    }

    // ───────────────────────── ساخت و مدیریت ─────────────────────────

    /**
     * سفارشِ سرور.
     *
     * ⚠️ Aeza دومرحله‌ای است: `POST /services/orders` یک **سفارش** می‌سازد و
     * شناسهٔ سرویس بعداً در `createdServiceIds` ظاهر می‌شود. پس اینجا کوتاه صبر
     * می‌کنیم و چند بار می‌پرسیم؛ اگر نرسید، `ref` را با پیشوندِ `order:`
     * برمی‌گردانیم تا کرونِ تحویل بعداً همان را پی بگیرد و **سفارشِ دوم ثبت نشود**
     * (وگرنه هر اجرای کرون یک سرورِ جدید می‌خرید).
     */
    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'error'];

        // ایمیجِ ما یا سیستم‌عامل است یا recipe (با پیشوند)
        $imageRef = (string) $spec['image_ref'];
        $params = ['name' => $spec['name']];

        if (str_starts_with($imageRef, 'recipe:')) {
            $params['recipe'] = substr($imageRef, 7);
        } else {
            $params['os'] = $imageRef;
        }

        $r = $this->req('POST', '/services/orders', [
            'count'       => 1,
            'term'        => 'month',
            'name'        => $spec['name'],
            'productId'   => $spec['plan_ref'],
            'parameters'  => $params,
            'autoProlong' => false,          // تمدید را **ما** مدیریت می‌کنیم، نه ارائه‌دهنده
            'method'      => 'balance',      // از موجودیِ حسابِ ما کم شود
        ]);

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']] + $fail;
        }

        $orderId = (string) (data_get($r['body'], 'data.id') ?? data_get($r['body'], 'id') ?? '');
        $ids = (array) (data_get($r['body'], 'data.createdServiceIds') ?? data_get($r['body'], 'createdServiceIds') ?? []);

        // چند تلاشِ کوتاه؛ بیش از این را به کرون می‌سپاریم تا وب‌هوکِ درگاه معطل نشود
        for ($i = 0; $i < 5 && $ids === [] && $orderId !== ''; $i++) {
            usleep(1500000);
            $o = $this->req('GET', '/services/orders/'.rawurlencode($orderId));
            $ids = (array) (data_get($o['body'], 'data.createdServiceIds') ?? data_get($o['body'], 'createdServiceIds') ?? []);
        }

        if ($ids === []) {
            return [
                'ok' => true,
                'message' => 'سفارش ثبت شد؛ سرور در حالِ آماده‌سازی است.',
                'ref' => $orderId !== '' ? 'order:'.$orderId : null,
                'ipv4' => null, 'ipv6' => null, 'root_password' => null,
                'status' => 'building',
            ];
        }

        $serviceId = (string) reset($ids);
        $info = $this->serverStatus($serviceId);

        return [
            'ok' => true, 'message' => '',
            'ref'  => $serviceId,
            'ipv4' => $info['ipv4'],
            'ipv6' => $info['ipv6'],
            // رمز در پاسخِ سفارش نمی‌آید؛ پس از ساخت با changePassword ست می‌کنیم
            'root_password' => null,
            'status' => $info['status'] ?? 'building',
        ];
    }

    /** پی‌گیریِ سفارشِ نیمه‌کاره: `order:123` → شناسهٔ سرویسِ واقعی */
    public function resolveOrder(string $orderRef): ?string
    {
        if (! str_starts_with($orderRef, 'order:')) {
            return null;
        }

        $r = $this->req('GET', '/services/orders/'.rawurlencode(substr($orderRef, 6)));
        $ids = (array) (data_get($r['body'], 'data.createdServiceIds') ?? data_get($r['body'], 'createdServiceIds') ?? []);

        return $ids === [] ? null : (string) reset($ids);
    }

    public function serverStatus(string $ref): array
    {
        $none = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];

        if (str_starts_with($ref, 'order:')) {
            return ['ok' => true, 'message' => 'در حالِ آماده‌سازی', 'status' => 'building'] + $none;
        }

        $r = $this->req('GET', '/services/'.rawurlencode($ref), ['extra' => 1]);

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'status' => 'unknown'] + $none;
        }

        $s = (array) (data_get($r['body'], 'data.items.0') ?? data_get($r['body'], 'data') ?? $r['body']);

        return [
            'ok' => true, 'message' => '',
            'status'          => $this->mapStatus((string) ($s['currentStatus'] ?? $s['status'] ?? '')),
            'ipv4'            => $this->firstIp($s, 4),
            'ipv6'            => $this->firstIp($s, 6),
            'traffic_used_gb' => null,
            'raw'             => ['status' => $s['status'] ?? null, 'name' => $s['name'] ?? null],
        ];
    }

    private function firstIp(array $s, int $version): ?string
    {
        foreach (['ip', 'ips', 'payload.ip', 'payload.ips', 'parameters.ip', 'network.ip'] as $path) {
            $v = data_get($s, $path);

            foreach ((is_array($v) ? $v : [$v]) as $ip) {
                $ip = is_array($ip) ? ($ip['ip'] ?? $ip['address'] ?? null) : $ip;

                if (! is_string($ip) || $ip === '') {
                    continue;
                }

                $is6 = str_contains($ip, ':');

                if (($version === 6) === $is6) {
                    return $ip;
                }
            }
        }

        return null;
    }

    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'active', 'running', 'online'         => 'running',
            'stopped', 'off', 'suspended', 'paused' => 'off',
            'pending', 'creating', 'installing', 'processing' => 'building',
            'deleted', 'removed', 'terminated'    => 'deleted',
            default                               => 'unknown',
        };
    }

    public function power(string $ref, string $action): array
    {
        $map = [
            'on'       => 'start',
            'off'      => 'stop',
            'shutdown' => 'stop',
            'reboot'   => 'reboot',
            'reset'    => 'reboot',
        ];

        if (! isset($map[$action])) {
            return ['ok' => false, 'message' => 'عملیاتِ ناشناخته.'];
        }

        $r = $this->req('POST', '/services/'.rawurlencode($ref).'/ctl', ['action' => $map[$action]]);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        $body = [];

        if (str_starts_with($imageRef, 'recipe:')) {
            $body['recipe'] = substr($imageRef, 7);
        } else {
            $body['os'] = $imageRef;
        }

        // Aeza رمز را می‌پذیرد؛ اگر ندادیم خودش می‌سازد و ما آن را نمی‌بینیم.
        // پس همیشه یکی می‌سازیم تا بشود به مشتری نشان داد.
        $password = $password ?: self::randomPassword();
        $body['password'] = $password;

        $r = $this->req('POST', '/services/'.rawurlencode($ref).'/reinstall', $body);

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'root_password' => $r['ok'] ? $password : null,
        ];
    }

    public function resetPassword(string $ref): array
    {
        $password = self::randomPassword();

        $r = $this->req('PUT', '/services/'.rawurlencode($ref).'/changePassword', ['password' => $password]);

        return [
            'ok' => $r['ok'],
            'message' => $r['ok'] ? '' : $r['message'],
            'root_password' => $r['ok'] ? $password : null,
        ];
    }

    /** رمزِ قوی — بدونِ نویسه‌های شبیه‌به‌هم تا مشتری اشتباه تایپ نکند */
    public static function randomPassword(int $len = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#%+=';
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
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
        return ['ok' => false, 'message' => 'تغییرِ پلن برای این سرور خودکار نیست؛ با پشتیبانی تماس بگیرید.'];
    }

    public function deleteServer(string $ref): array
    {
        if (str_starts_with($ref, 'order:')) {
            $real = $this->resolveOrder($ref);

            if ($real === null) {
                return ['ok' => false, 'message' => 'سفارش هنوز به سرور تبدیل نشده؛ حذف ممکن نیست.'];
            }

            $ref = $real;
        }

        $r = $this->req('DELETE', '/services/'.rawurlencode($ref));

        if (! $r['ok'] && $r['status'] === 404) {
            return ['ok' => true, 'message' => 'سرور از قبل حذف شده بود.'];
        }

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }
}
