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
 *  • **از SDKهای تایپ‌شده** (نه از داکیومنت، که نمونهٔ کاملِ JSON ندارد): نامِ
 *    فیلدهای محصول. `configuration[]{slug,base,max}` · `summaryConfiguration`
 *    `{cpu,ram,rom}` — **دیسک `rom` است نه `disk`** · `prices|rawPrices|
 *    individualPrices` با دوره‌های `hour|month|year|half_year|quarter_year` و
 *    واحدِ **کوپک** · `type ∈ {vps,vds,hicpu,storage,gpu,dedicated,domain}` ·
 *    `serviceHandler ∈ {vm6,manual,feru,s3,ispmgr}` · مکان روی **گروه**:
 *    `group.payload.{code,label,mode,isDisabled}`. جزئیات و منبعِ هر کدام،
 *    بالای بخشِ «نگاشتِ فیلدهای محصول» پایین‌تر آمده.
 *
 *  • **همچنان استنتاجی**: واحدِ رم و دیسک (مگابایت یا گیگابایت) در هیچ منبعی
 *    صریح نبود؛ از بزرگیِ عدد تشخیص داده می‌شود. اگر هیچ مقداری خوانده نشد،
 *    محصول **رد** می‌شود نه اینکه با صفر ذخیره شود (پلنِ صفرهسته/صفرگیگ روی
 *    سایت، از نبودنِ پلن بدتر است).
 *
 *  • برای بستنِ هر شکافِ باقی‌مانده، `rawProbe()` هست: در پنلِ مدیریت یک دکمه،
 *    ساختارِ واقعیِ JSON را **به‌همراه** اسلاگ‌های مشخصات و نتیجهٔ نگاشت روی
 *    همان ردیف نشان می‌دهد.
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
        'currencies' => ['payment/currencies', 'currencies', 'payments/currencies'],
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
            // این دو را در API عمومی‌شان نداریم، پس صریح false — تا سرورساز
            // چیزی نفروشد که تحویلش ممکن نیست.
            'ssh_key' => false, 'extra_ip' => false,
        ];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        return ['ok' => false, 'message' => 'کلیدِ SSH برای این سرور در دسترس نیست.', 'ref' => null];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این سرور در دسترس نیست.', 'ips' => []];
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
                return $this->flattenGroups($v);
            }
            if (is_array($v) && isset($v['items']) && is_array($v['items'])) {
                return $this->flattenGroups($v['items']);
            }
        }

        return [];
    }

    /**
     * فهرستِ **گروه‌بندی‌شده** را به فهرستِ محصول تبدیل کن.
     *
     * ⚠️ چرا لازم شد: زیرساختِ ۲ محصولات را گروه‌به‌گروه می‌دهد (هر ردیف یک
     * دسته با آرایهٔ `products` درونش). بی‌این باز کردن، ما **گروه‌ها** را
     * محصول می‌فهمیدیم و چون گروه هسته/رم/دیسک ندارد، همه‌شان رد می‌شدند و
     * گزارش «۰ پلن» می‌داد — درحالی‌که سیستم‌عامل‌ها درست خوانده می‌شدند و همین
     * تناقض، سرنخِ ماجرا بود.
     *
     * ویژگی‌های خودِ گروه (مثلِ مکان) به فرزندان ارث می‌رسد، چون معمولاً مکان
     * روی گروه است نه روی هر محصول.
     */
    private function flattenGroups(array $rows): array
    {
        $out = [];
        $nested = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $children = null;

            foreach (['products', 'items', 'tariffs', 'plans'] as $key) {
                if (isset($row[$key]) && is_array($row[$key]) && $row[$key] !== [] && array_is_list($row[$key])) {
                    $children = $row[$key];
                    break;
                }
            }

            if ($children === null) {
                $out[] = $row;

                continue;
            }

            $nested = true;
            $parent = $row;

            // خودِ آرایهٔ فرزندان از والد حذف می‌شود تا ارث‌بری آلوده نشود
            foreach (['products', 'items', 'tariffs', 'plans'] as $key) {
                unset($parent[$key]);
            }

            foreach ($children as $child) {
                if (is_array($child)) {
                    // فیلدهای خودِ محصول اولویت دارند؛ والد فقط جای خالی را پر می‌کند
                    $out[] = $child + ['group' => $parent] + $parent;
                }
            }
        }

        return $nested ? $out : $rows;
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
                    // ⚠️ اگر فهرست خوانده شد ولی چیزی نگاشت نشد، **این** بخش کار را
                    // در می‌آورد: اسلاگ‌های واقعیِ مشخصات، دوره‌های قیمت، کلیدهای
                    // payloadِ گروه، و اینکه هر پنج متدِ نگاشت روی همان ردیف چه
                    // خواندند. با یک نگاه معلوم می‌شود کدام نام عوض شده.
                    'shape' => $items === [] ? null : $this->describeRow($items[0]),
                    // نگاشتِ عمیقِ بدنه وقتی `items` پیدا نشد — «data» تو در تو بوده؟
                    'body_shape' => $items === [] && is_array($r['body'])
                        ? $this->shallowKeyTree($r['body'])
                        : null,
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

    /**
     * کارتِ تشخیصِ یک ردیفِ نمونه — «چه دیدم و از آن چه فهمیدم».
     *
     * چرا لازم شد: گزارشِ «۳۸۹ محصول خوانده شد، ۰ پلن ساخته شد» به مدیر
     * نمی‌گفت کدام نام نخوانده. این متد **اسلاگ‌های واقعی** و خروجیِ هر پنج
     * متدِ نگاشت را کنارِ هم می‌گذارد؛ عیب‌یابی از حدس‌وگمان در می‌آید.
     */
    private function describeRow(mixed $row): array
    {
        if (! is_array($row)) {
            return ['not_an_object' => gettype($row)];
        }

        $keysOf = static fn (string $path) => is_array($v = data_get($row, $path)) ? array_keys($v) : null;

        $slugs = [];

        foreach (['summaryConfiguration', 'configuration'] as $bag) {
            $rows = data_get($row, $bag);

            if (! is_array($rows)) {
                continue;
            }

            $slugs[$bag] = [];

            foreach ($rows as $k => $item) {
                $slugs[$bag][] = is_string($k)
                    ? $k
                    : (string) (data_get($item, 'slug') ?? data_get($item, 'key') ?? '?');
            }
        }

        return [
            'keys'                => array_keys($row),
            'type'                => data_get($row, 'type'),
            'service_handler'     => data_get($row, 'serviceHandler'),
            'config_slugs'        => $slugs,
            'price_terms'         => array_filter([
                'prices'           => $keysOf('prices'),
                'rawPrices'        => $keysOf('rawPrices'),
                'individualPrices' => $keysOf('individualPrices'),
            ]),
            'group_keys'          => $keysOf('group'),
            'group_payload'       => data_get($row, 'group.payload'),
            'payload_keys'        => $keysOf('payload'),
            // و این‌که نگاشتِ فعلی روی همین ردیف چه نتیجه‌ای می‌دهد
            'parsed' => [
                'is_vps'      => $this->isVpsProduct($row),
                'vps_verdict' => $this->vpsVerdict($row) ?: 'ok',
                'specs'       => $this->specsOf($row),
                'monthly_rub' => $this->monthlyRub($row),
                'location'    => $this->locationOf($row),
                'in_stock'    => $this->inStock($row),
            ],
        ];
    }

    /** درختِ کم‌عمقِ کلیدها — برای وقتی `items` پیدا نشد و باید بدانیم کجاست */
    private function shallowKeyTree(array $body, int $depth = 2): array
    {
        $out = [];

        foreach ($body as $k => $v) {
            $out[$k] = is_array($v)
                ? ($depth > 1 ? $this->shallowKeyTree($v, $depth - 1) : array_slice(array_keys($v), 0, 20))
                : gettype($v);
        }

        return array_slice($out, 0, 20, true);
    }

    // ───────────────────────── نرخِ ارز ─────────────────────────

    /**
     * ضریبِ روبل → یورو (چند روبل = یک یورو، به‌صورتِ ضریبِ ضرب‌شدنی).
     *
     * ⚠️ روبل انتخابِ ما نیست: **API این ارائه‌دهنده قیمت را به روبل می‌دهد**،
     * هر ارزی که حسابِ ما باشد. داکیومنتشان می‌گوید ضریبِ تبدیل را از
     * `payment/currencies` بگیر.
     *
     * سه منبع، به ترتیبِ اولویت:
     *
     *  ۱) **نرخِ دستیِ مدیر** (`aeza_rub_per_eur`). عمداً اولِ صف است: نرخِ خودِ
     *     ارائه‌دهنده گاهی حاشیهٔ صرافیِ خودشان را دارد، ولی مدیر می‌داند واقعاً
     *     چند پرداخته. یک عدد است و ماه‌ها تغییرِ محسوس ندارد.
     *  ۲) ضریبِ خودِ ارائه‌دهنده از `payment/currencies`.
     *  ۳) نرخِ زندهٔ خودمان — ولی سرویسِ نرخِ ما فقط دلار و یورو دارد و **روبل
     *     ندارد**، پس این راه عملاً بسته است و فقط برای روزی است که اضافه شود.
     *
     * اگر هیچ‌کدام نشد **۰** برمی‌گردد و کاتالوگ ساخته نمی‌شود. این عمدی است:
     * پلنی که بهایِ تمام‌شده‌اش را نمی‌دانیم نباید قیمت بخورد و روی سایت برود.
     */
    private function rubToEurRate(): float
    {
        // ① نرخِ دستیِ مدیر: «۱ یورو چند روبل» → ضریبِ ضرب‌شدنی = ۱/آن
        $perEur = (float) Setting::get('aeza_rub_per_eur', '0');

        if ($perEur > 0) {
            return 1 / $perEur;
        }

        // ② ضریبِ خودِ ارائه‌دهنده
        $path = $this->resolvePath('currencies');
        $r = $path === null
            ? ['ok' => false, 'body' => []]
            : $this->req('GET', '/'.$path);

        if ($r['ok']) {
            $rows = $this->items($r['body']);

            // بعضی پاسخ‌ها فهرست نیستند و نگاشتِ کد→ضریب‌اند: {"EUR": 0.0098, …}
            if ($rows === []) {
                $flat = (array) (data_get($r['body'], 'data') ?? $r['body']);

                foreach ($flat as $code => $val) {
                    if (strtoupper((string) $code) === 'EUR') {
                        $m = is_array($val)
                            ? (float) ($val['multiplier'] ?? $val['rate'] ?? $val['value'] ?? 0)
                            : (float) $val;

                        if ($m > 0) {
                            return $this->normalizeMultiplier($m);
                        }
                    }
                }
            }

            foreach ($rows as $c) {
                $code = strtoupper((string) ($c['code'] ?? $c['currency'] ?? $c['name'] ?? ''));

                if ($code === 'EUR') {
                    $m = (float) ($c['multiplier'] ?? $c['rate'] ?? $c['value'] ?? 0);

                    if ($m > 0) {
                        return $this->normalizeMultiplier($m);
                    }
                }
            }
        }

        // ③ نرخِ زندهٔ خودمان — سرویسِ ما فعلاً روبل ندارد، پس معمولاً بی‌نتیجه
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

    /**
     * ضریب را در جهتِ درست نگه دار.
     *
     * ما «یورو به‌ازای هر روبل» می‌خواهیم — عددی خیلی کوچک (~۰٫۰۱). اگر
     * ارائه‌دهنده جهتِ عکس را بدهد («روبل به‌ازای هر یورو»، ~۱۰۰)، ضربِ مستقیم
     * قیمت را **۱۰٬۰۰۰ برابر** می‌کند و ما سرورِ ۵ یورویی را چند صد یورو
     * می‌فروشیم. مرزِ ۱ برای تشخیص کافی است: هیچ ارزِ واقعی‌ای نسبتِ ۱:۱ با روبل
     * ندارد.
     */
    private function normalizeMultiplier(float $m): float
    {
        return $m > 1 ? 1 / $m : $m;
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
            return ['ok' => false, 'message' =>
                'قیمت‌های این زیرساخت در API به **روبل** می‌آیند و ضریبِ تبدیل به یورو به دست نیامد، '
                .'پس عمداً هیچ قیمتی ساخته نشد (قیمتِ حدسی از نبودِ قیمت بدتر است). '
                .'راهِ حل: در تنظیمات، «۱ یورو چند روبل» را وارد کنید.',
            ] + $empty;
        }

        $locations = [];
        $plans = [];

        // ⚠️ چرا شمارنده: قبلاً اگر همهٔ محصولات فیلتر می‌شدند، گزارش فقط
        // «۰ پلن» می‌گفت و هیچ سرنخی نبود که مشکل کدام صافی است — نامِ فیلد؟
        // نبودِ مکان؟ نبودِ قیمت؟ این شمارنده «هیچی نیاورد» را به «۴۰ محصول
        // خوانده شد، ۴۰ تا بی‌فیلدِ مکان رد شد» تبدیل می‌کند.
        $seen = 0;
        $why = [
            'not_vps_type' => 0, 'not_vps_handler' => 0, 'not_vps_name' => 0,
            'no_id' => 0, 'no_specs' => 0, 'partial_specs' => 0,
            'no_price' => 0, 'ambiguous_price' => 0, 'promo' => 0, 'no_location' => 0,
        ];

        foreach ($this->items($r['body']) as $p) {
            $seen++;
            // ⚠️ فقط **سرورِ مجازی** — خواستهٔ صریحِ کارفرما «فعلاً فقط سرور مجازی».
            // این ارائه‌دهنده پروکسی و WAF و دامنه و سرورِ فیزیکی هم می‌فروشد؛
            // اگر همه را بیاوریم، محصولی روی سایت می‌نشیند که نه صفحه‌اش را
            // ساخته‌ایم نه تحویلش را — و مشتری می‌تواند بخردش.
            //
            // دلیلِ رد **تفکیک‌شده** شمرده می‌شود: «۲۷۸ غیرِ سرورِ مجازی» به مدیر
            // نمی‌گفت که مشکل نوعِ محصول است یا سخت‌گیریِ صافیِ نام. حالا می‌گوید.
            $verdict = $this->vpsVerdict($p);

            if ($verdict !== '') {
                $why[$verdict]++;

                continue;
            }

            $ref = (string) ($p['id'] ?? '');
            if ($ref === '') {
                $why['no_id']++;

                continue;
            }

            $specs = $this->specsOf($p);

            // مشخصاتِ ناقص = رد. پلنِ «۰ هسته / ۰ گیگ» روی سایت، فاجعهٔ اعتماد است.
            if ($specs['vcpu'] < 1 || $specs['ram_mb'] < 128 || $specs['disk_gb'] < 1) {
                // «هیچی نخواند» با «نصفش خواند» دو عیبِ متفاوت‌اند: اولی یعنی
                // نامِ فیلد عوض شده، دومی یعنی این محصول واقعاً مشخصات ندارد
                // (پروکسی، لایسنس، …). تفکیکشان عیب‌یابی را کوتاه می‌کند.
                $nothing = $specs['vcpu'] < 1 && $specs['ram_mb'] < 128 && $specs['disk_gb'] < 1;
                $why[$nothing ? 'no_specs' : 'partial_specs']++;

                continue;
            }

            // پلنِ تشویقی: قیمتش پایدار نیست، پس فروشش تعهدی است که نمی‌توانیم
            // نگه داریم (سرِ سفارش قیمت قفل می‌شود ولی بهایِ ما بالا می‌رود).
            // پیش‌فرض کنار می‌رود و در گزارش شمرده می‌شود.
            if ($this->isPromoProduct($p)) {
                $why['promo']++;

                continue;
            }

            $rub = $this->monthlyRub($p);
            if ($rub <= 0) {
                $why['no_price']++;

                continue;
            }

            [$country, $city, $locRef] = $this->locationOf($p);
            if ($country === '') {
                $why['no_location']++;

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

        // اگر چیزی خوانده شد ولی هیچ پلنی نساخت، **دلیلش** را بگو. پیامِ
        // «۰ پلن» بی‌دلیل، عیب‌یابی را به حدس‌وگمان تبدیل می‌کند.
        $note = '';

        if ($plans === [] && $seen > 0) {
            $parts = [];

            foreach (array_filter($why) as $reason => $count) {
                $parts[] = match ($reason) {
                    'not_vps_type'    => $count.' با نوعِ محصولِ غیرِ سرورِ مجازی',
                    'not_vps_handler' => $count.' با دستگیرهٔ سرویسِ غیرِ سرورِ مجازی (دامنه/فضا/لایسنس/فیزیکی)',
                    'not_vps_name'    => $count.' با نامِ مشخصاً غیرِ سرورِ مجازی',
                    'promo'           => $count.' تشویقی/موقت — قیمتِ تمدیدشان پایدار نیست، پس عمداً فروخته نمی‌شوند (در تنظیمات قابلِ روشن‌کردن)',
                    'no_id'           => $count.' بی‌شناسه',
                    'no_specs'        => $count.' که هیچ مشخصه‌ای نداشت (نامِ فیلدِ هسته/رم/دیسک نخواند)',
                    'partial_specs'   => $count.' با مشخصاتِ ناقص (بخشی خوانده شد)',
                    'no_price'        => $count.' بی‌قیمتِ ماهانه',
                    'no_location'     => $count.' بی‌مکانِ قابلِ تشخیص',
                    default           => $count.' '.$reason,
                };
            }

            $note = $seen.' محصول خوانده شد ولی هیچ پلنی ساخته نشد — '
                .implode(' · ', $parts)
                .'. صفحهٔ «ساختارِ خامِ پاسخ» نامِ واقعیِ فیلدها را نشان می‌دهد.';
        }

        return [
            'ok' => true, 'message' => $note,
            'locations' => array_values($locations),
            'plans'     => $plans,
            'images'    => $this->fetchImages(),
        ];
    }

    // ═════════════════ نگاشتِ فیلدهای محصول ═════════════════
    //
    // 🔴 چرا این بخش بازنویسی شد — خرابیِ واقعی روی حسابِ کارفرما:
    // ۳۸۹ محصول خوانده شد و **صفر** پلن ساخته شد (۲۷۸ «غیرِ VPS» + ۱۱۱ «مشخصاتِ
    // ناخوانا»). علتش این بود که نام‌های اینجا **حدسی** بودند. داکیومنتِ رسمی
    // نمونهٔ کاملِ JSON ندارد، پس نام‌های واقعی از SDKهای **تایپ‌شده** درآمد:
    //
    //  • carlsmei/go-aeza-sdk (Go) — `Product{ id, type, groupId, name,
    //    configuration []Configuration{max,base,slug,type}, rawPrices map[string]int,
    //    prices map[string]Price{value,suffix,defaultCurrency,slug}, group, serviceHandler }`
    //  • scinfra-pro/terraform-provider-aeza (Go) — همان + `summaryConfiguration`،
    //    `group.payload.{code,label,mode,isDisabled}`، دوره‌های قیمت
    //    `hour|month|year|half_year|quarter_year`، `type ∈ {vps,vds,hicpu,storage,gpu,
    //    dedicated,domain}`، `serviceHandler ∈ {vm6=سرورِ ابری, manual=فیزیکی,
    //    feru=دامنه, s3=فضا, ispmgr=لایسنس}`، و صریحاً: «Prices are specified in the
    //    smallest currency units (kopecks, cents, etc.)»
    //  • nikolai-in/aeza1password (Python) — `summaryConfiguration.{cpu,ram,rom}.count`
    //    و `locationCode` که کدِ **دو حرفیِ کشور** است
    //  • AezaGroup/aeza-net-sdk (رسمی، Node) — `id`, `name`, `payload.oslist`, `prices`
    //
    // دو کشفی که تمامِ ماجرا را توضیح می‌دهد:
    //  ۱) «دیسک» در این API `disk` نیست، **`rom`** است.
    //  ۲) مشخصات در یک **فهرست** است (`configuration[] = {slug, base, max}`)، نه
    //     فیلدهای صافِ `configuration.cpu`. پس مسیرهای قبلی هیچ‌وقت مقدار نمی‌دادند.
    //
    // ⚠️ نام‌های حدسیِ قبلی **حذف نشده‌اند**، فقط بعد از نام‌های واقعی صف کشیده‌اند.
    // این API یک‌بار عوض شده (core.aeza.net → my.aeza.net) و باز هم عوض می‌شود.

    /**
     * نوعِ محصولاتی که «سرورِ مجازی» حساب می‌شوند.
     *
     * `hicpu` و `vds` هم سرورِ مجازی‌اند و قبلاً **به‌غلط رد می‌شدند** — بخشِ
     * بزرگی از آن ۲۷۸ محصولِ ردشده همین‌ها بودند. `dedicated`/`gpu`/`storage`/
     * `domain` عمداً اینجا نیستند.
     */
    private const VPS_TYPES = [
        'vps', 'vds', 'hicpu', 'hi-cpu', 'highcpu', 'vm', 'virtual', 'cloud', 'cloudserver', 'server',
    ];

    /**
     * دستگیرهٔ سرویس — قطعی‌ترین نشانهٔ «این VPS نیست».
     *
     * `manual` یعنی تحویلِ دستی (سرورِ فیزیکی)، `feru` ثبتِ دامنه، `s3` فضای
     * ابری، `ispmgr` لایسنسِ پنل. سرورِ مجازی `vm6` است.
     */
    private const NON_VPS_HANDLERS = [
        'manual', 'feru', 's3', 'ispmgr', 'waf', 'vpn', 'soft', 'proxy', 'domain', 'dns', 'ssl',
    ];

    /** کف قیمتِ ماهانهٔ باورپذیر به روبل — پایین‌تر از ارزان‌ترین VPSِ واقعی */
    /**
     * بازهٔ منطقیِ اجارهٔ **ماهانهٔ** یک سرورِ مجازی، به روبل.
     *
     * 🔴 چرا بازه و نه فقط یک کف: نسخهٔ قبلی فقط کفِ ۵۰ روبل داشت و این حساب
     * را نمی‌کرد که خودِ عددِ تقسیم‌شده هم می‌تواند «به‌ظاهر منطقی» باشد.
     * پلنِ واقعیِ ۵٬۰۰۰ روبلی که به روبل ذخیره شده بود، ۵۰۰۰/۱۰۰ = ۵۰ می‌شد و
     * چون ۵۰ از کفِ ۵۰ کمتر **نبود**، پذیرفته می‌شد: یک‌صدمِ قیمتِ واقعی.
     * یعنی هرچه پلن گران‌تر، خطا بی‌صداتر — و گران‌ترین پلن‌ها بیشترین ضرر.
     *
     * ارقام از بازارِ واقعی: ارزان‌ترین VPS این ارائه‌دهنده حدودِ ۱۰۰–۲۰۰ روبل
     * در ماه است و گران‌ترین پلنِ چندده‌هسته‌ای هم از چند ده هزار روبل بالاتر
     * نمی‌رود.
     */
    private const MONTHLY_RUB_MIN = 20.0;

    private const MONTHLY_RUB_MAX = 2000000.0;

    /**
     * آیا این محصول یک **سرورِ مجازی** است؟ (پوشش برای `vpsVerdict`)
     */
    private function isVpsProduct(array $p): bool
    {
        return $this->vpsVerdict($p) === '';
    }

    /**
     * `''` یعنی سرورِ مجازی است؛ وگرنه **دلیلِ رد** برمی‌گردد.
     *
     * سه صافیِ پشتِ‌سرِهم، به ترتیبِ قطعیت:
     *
     *  ۱) **دستگیرهٔ سرویس** (`serviceHandler`) — اگر `manual`/`feru`/`s3`/… باشد
     *     قطعاً سرورِ مجازی نیست، هرچه فیلدِ نوع بگوید.
     *  ۲) **نوعِ محصول** — اگر فیلدِ نوع هست، حرفِ آخر را می‌زند. با نوعِ صریحِ
     *     `vps`/`hicpu`/`vds` سراغِ صافیِ نام نمی‌رویم؛ آن صافی حدسی است و
     *     می‌تواند VPSِ واقعی را به‌خاطرِ یک واژه در نامش بی‌دلیل رد کند.
     *  ۳) فقط **وقتی نوع نداریم**: واژه‌های مشخصاً غیرِ VPS در نام/گروه/برچسب.
     *
     * صافیِ چهارم و مهم‌ترین جای دیگری است: `specsOf` مشخصاتِ ناقص را رد می‌کند،
     * پس محصولی که هسته/رم/دیسک ندارد (پروکسی، لایسنس، دامنه) هرگز پلن نمی‌شود.
     */
    private function vpsVerdict(array $p): string
    {
        $handler = '';

        foreach (['serviceHandler', 'service_handler', 'typeObject.serviceHandler',
            'type.serviceHandler', 'group.serviceHandler', 'group.type.serviceHandler'] as $k) {
            $v = data_get($p, $k);

            if (is_string($v) && trim($v) !== '') {
                $handler = strtolower(trim($v));
                break;
            }
        }

        if ($handler !== '' && in_array($handler, self::NON_VPS_HANDLERS, true)) {
            return 'not_vps_handler';
        }

        // نوعِ **خودِ محصول** حرفِ آخر است
        foreach (['type', 'type.slug', 'typeObject.slug', 'serviceType', 'productType'] as $k) {
            $v = data_get($p, $k);

            if (is_string($v) && trim($v) !== '') {
                return in_array(strtolower(trim($v)), self::VPS_TYPES, true) ? '' : 'not_vps_type';
            }
        }

        // نوعِ **گروه** نشانهٔ ضعیف‌تری است: گروهِ مکان می‌تواند `vps` باشد ولی
        // محصولِ درونش پروکسیِ همان مکان. پس فقط برای **رد کردن** به‌کار می‌آید،
        // نه برای دور زدنِ صافیِ نام.
        foreach (['group.type.slug', 'group.typeObject.slug', 'group.type'] as $k) {
            $v = data_get($p, $k);

            if (is_string($v) && trim($v) !== '' && ! in_array(strtolower(trim($v)), self::VPS_TYPES, true)) {
                return 'not_vps_type';
            }
        }

        $haystack = strtolower(trim(implode(' ', array_filter([
            (string) ($p['name'] ?? ''),
            (string) (data_get($p, 'group.name') ?? ''),
            (string) ($p['groupName'] ?? ''),
            (string) (data_get($p, 'group.payload.label') ?? ''),
        ], 'strlen'))));

        if ($haystack === '') {
            return '';
        }

        foreach ([
            'proxy', 'پروکسی', 'socks', 'waf', 'ddos protection', 'domain', 'دامنه',
            'ssl', 'mail', 'email', 'storage box', 'backup space', 'hosting', 'cdn',
            'license', 'panel only',
            // سرورِ **فیزیکی**: مشخصاتش شبیهِ VPS است ولی محصولِ دیگری است، صفحهٔ
            // فروشش را نساخته‌ایم و تحویلش خودکار نیست.
            'dedicated server', 'bare metal', 'baremetal', 'اختصاصی', 'physical',
        ] as $bad) {
            if (str_contains($haystack, $bad)) {
                return 'not_vps_name';
            }
        }

        return '';
    }

    /**
     * مشخصاتِ محصول را از **همهٔ** شکل‌های شناخته‌شده بیرون بکش.
     *
     * ⚠️ گرانبهاترین درسِ این فایل: مشخصات در این API **فیلدِ صاف نیست**. یک
     * فهرست از آیتم‌هاست که هر آیتم `slug` دارد:
     *
     *     "configuration": [
     *        {"slug":"cpu","base":2,"max":8,"type":"..."},
     *        {"slug":"ram","base":4096,"max":16384},
     *        {"slug":"rom","base":60,"max":400}
     *     ]
     *
     * و روی سرویس (نه محصول) همان داده نگاشتِ `summaryConfiguration` است:
     * `{"cpu":{"count":2},"ram":{"count":4096},"rom":{"count":60}}`.
     *
     * **دیسک `rom` است، نه `disk`.** این یک کلمه، علتِ «۱۱۱ محصول با مشخصاتِ
     * ناخوانا» بود.
     */
    private function specsOf(array $p): array
    {
        $cfg = $this->configOf($p);

        // ① نام‌های واقعی (از SDKها) ② نام‌های حدسیِ قبلی به‌عنوان پشتیبان
        $vcpu = (int) round($this->pick($cfg, ['cpu', 'cores', 'core', 'vcpu', 'cpu_count', 'processor'])
            ?: $this->flat($p, ['cpu', 'cores', 'vcpu', 'configuration.cpu', 'configuration.cores', 'parameters.cpu', 'specs.cpu']));

        $ramRaw = $this->pick($cfg, ['ram', 'memory', 'mem'])
            ?: $this->flat($p, ['ram', 'memory', 'configuration.ram', 'configuration.memory', 'parameters.ram', 'specs.ram']);

        [$diskSlug, $diskRaw] = $this->pickWithKey($cfg, ['rom', 'disk', 'storage', 'nvme', 'ssd', 'hdd', 'space']);

        if ($diskRaw <= 0) {
            $diskRaw = $this->flat($p, ['disk', 'storage', 'configuration.disk', 'configuration.storage', 'parameters.disk', 'specs.disk']);
        }

        $traffic = (int) round($this->pick($cfg, ['traffic', 'bandwidth', 'transfer'])
            ?: $this->flat($p, ['traffic', 'bandwidth', 'configuration.traffic', 'parameters.traffic']));

        // ⚠️ واحدِ رم در هیچ منبعی صریح نبود، پس از بزرگیِ عدد تشخیص می‌دهیم.
        // مرزِ ۵۱۲: پلنِ نیم‌گیگ به‌صورت `512` (مگابایت) می‌آید و پلنِ ۵۱۲ گیگ رم
        // سرورِ مجازی نیست. هر دو تفسیر برای ۱ و ۱۰۲۴ به همان جواب می‌رسند.
        $ramMb = $ramRaw > 0 && $ramRaw < 512 ? (int) round($ramRaw * 1024) : (int) round($ramRaw);

        // دیسک تقریباً همیشه گیگابایت است؛ عددِ بی‌معنا بزرگ یعنی مگابایت
        // (۱۵ گیگ = ۱۵۳۶۰). دیسکِ ۱۰ ترابایتیِ سرورِ مجازی وجود ندارد.
        $diskGb = (int) round($diskRaw >= 10240 ? $diskRaw / 1024 : $diskRaw);

        // ترافیک اگر بزرگ بود احتمالاً به گیگ است؛ اگر کوچک، به ترابایت
        if ($traffic > 0 && $traffic < 100) {
            $traffic *= 1024;
        }

        return [
            'vcpu'       => $vcpu,
            'ram_mb'     => $ramMb,
            'disk_gb'    => $diskGb,
            'disk_type'  => $this->diskTypeOf($p, $diskSlug),
            'traffic_gb' => $traffic,
            'cpu_kind'   => $this->cpuKindOf($p),
        ];
    }

    /**
     * نگاشتِ `slug ⇒ عدد` از تمامِ شکل‌های ممکنِ مشخصات.
     *
     * ترتیبِ خواندنِ مقدار درونِ هر آیتم مهم است: `count` مقدارِ واقعیِ یک سرویسِ
     * موجود است، `base` مقدارِ پایهٔ تعرفه، و `max` سقفِ ارتقا. `max` فقط آخرین
     * چاره است — وگرنه پلنِ «۲ هسته با سقفِ ۸» را ۸ هسته می‌فروشیم.
     *
     * @return array<string, float>
     */
    private function configOf(array $p): array
    {
        $out = [];

        $take = function ($slug, $item) use (&$out): void {
            $slug = is_string($slug) ? strtolower(trim($slug)) : '';

            if ($slug === '' || isset($out[$slug])) {
                return;
            }

            $val = 0.0;

            if (is_array($item)) {
                foreach (['count', 'base', 'value', 'amount', 'quantity', 'max'] as $k) {
                    if (isset($item[$k]) && is_numeric($item[$k]) && (float) $item[$k] > 0) {
                        $val = (float) $item[$k];
                        break;
                    }
                }
            } elseif (is_numeric($item)) {
                $val = (float) $item;
            }

            if ($val > 0) {
                $out[$slug] = $val;
            }
        };

        // ① `summaryConfiguration` — نگاشتِ slug ⇒ آیتم (سرویس و محصول، هر دو)
        // ② `configuration` — روی محصول **فهرست** است، روی سرویس نگاشت
        foreach (['summaryConfiguration', 'summary_configuration', 'configuration', 'specs', 'params'] as $bag) {
            $rows = data_get($p, $bag);

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $k => $item) {
                // فهرست ⇒ اسلاگ درونِ آیتم است؛ نگاشت ⇒ اسلاگ همان کلید است
                $take(is_string($k) ? $k : (data_get($item, 'slug') ?? data_get($item, 'key')), $item);
            }
        }

        return $out;
    }

    /** اولین اسلاگی که مقدار دارد */
    private function pick(array $cfg, array $slugs): float
    {
        return $this->pickWithKey($cfg, $slugs)[1];
    }

    /** @return array{0:string,1:float} اسلاگِ برنده و مقدارش */
    private function pickWithKey(array $cfg, array $slugs): array
    {
        foreach ($slugs as $s) {
            if (($cfg[$s] ?? 0) > 0) {
                return [$s, (float) $cfg[$s]];
            }
        }

        return ['', 0.0];
    }

    /** نام‌های صافِ حدسیِ قبلی — پشتیبانِ چندمسیره، چون API عوض می‌شود */
    private function flat(array $p, array $paths): float
    {
        foreach ($paths as $path) {
            $v = data_get($p, $path);

            if (is_numeric($v) && (float) $v > 0) {
                return (float) $v;
            }
        }

        return 0.0;
    }

    /** nvme/ssd/hdd — از اسلاگِ دیسک، فیلدِ صریح، یا متنِ نام/ویژگی‌ها */
    private function diskTypeOf(array $p, string $diskSlug): string
    {
        $hay = strtolower(implode(' ', array_filter([
            $diskSlug,
            (string) (data_get($p, 'diskType') ?? data_get($p, 'disk_type') ?? ''),
            (string) ($p['name'] ?? ''),
            (string) (data_get($p, 'group.name') ?? ''),
        ], 'strlen')));

        if (str_contains($hay, 'hdd') || str_contains($hay, 'sata')) {
            return 'hdd';
        }

        if (str_contains($hay, 'ssd') && ! str_contains($hay, 'nvme')) {
            return 'ssd';
        }

        return 'nvme';
    }

    /**
     * هستهٔ اشتراکی یا اختصاصی.
     *
     * `group.payload.mode ∈ {shared, dedicated}` و برچسبِ گروه (`NL-SHARED`،
     * `US-DEDICATED`) این را می‌گوید — و `hicpu` هم به‌معنای هستهٔ اختصاصی است.
     * ⚠️ این «سرورِ فیزیکی» نیست؛ VPS با هستهٔ اختصاصی است و باید فروخته شود.
     */
    private function cpuKindOf(array $p): string
    {
        $mode = strtolower((string) (data_get($p, 'group.payload.mode') ?? data_get($p, 'payload.mode') ?? ''));

        if ($mode === 'dedicated') {
            return 'dedicated';
        }

        if ($mode === 'shared') {
            return 'shared';
        }

        $type = strtolower((string) (data_get($p, 'type') ?: ''));

        if (in_array($type, ['hicpu', 'hi-cpu', 'highcpu'], true)) {
            return 'dedicated';
        }

        $hay = strtolower(implode(' ', array_filter([
            (string) ($p['name'] ?? ''),
            (string) (data_get($p, 'group.payload.label') ?? ''),
        ], 'strlen')));

        return str_contains($hay, 'dedicat') ? 'dedicated' : 'shared';
    }

    /**
     * قیمتِ ماهانه به **روبل**.
     *
     * ترتیبِ ظرف‌ها اتفاقی نیست:
     *  ۱) `individualPrices` — قیمتِ اختصاصیِ حسابِ ما (اگر گذاشته باشند، همان
     *     است که واقعاً می‌پردازیم).
     *  ۲) `rawPrices` — قیمت به ارزِ **پایه**، بی‌تبدیلِ نمایشی. «raw» یعنی همین.
     *  ۳) `prices` — قیمتِ عادی (ممکن است ضریبِ نمایشیِ ارزِ حساب خورده باشد).
     *
     * `firstPrices` عمداً استفاده نمی‌شود: قیمتِ تشویقیِ دورهٔ اول است و اگر
     * بهایِ تمام‌شده حسابش کنیم، از تمدیدِ دوم به بعد زیرِ قیمتِ خرید می‌فروشیم.
     *
     * ⚠️ واحد: داکیومنتِ ترافورم‌پروایدر صریح می‌گوید «Prices are specified in the
     * smallest currency units (kopecks)» و خودش هم سه جا بر ۱۰۰ تقسیم می‌کند.
     * پس عددِ API **کوپک** است. ولی اشتباه در جهتِ تقسیم گران است — اگر روزی
     * روبلِ خالص بدهند و ما تقسیم کنیم، سرورِ ۵۰ یورویی را نیم‌یورو می‌فروشیم.
     * پس اگر تفسیرِ کوپک عددی بی‌معنا کوچک بدهد، همان عدد را روبل می‌گیریم.
     */
    /**
     * آیا این محصول **تشویقی** (promo) است؟
     *
     * ═══ چرا این خطرناک است و به چشم نمی‌آید ═══
     *
     * این ارائه‌دهنده خطِ محصولِ «PROMO» دارد که قیمتِ ماهانه‌اش واقعاً پایین
     * است — ولی تشویقی است: موجودیِ محدود، یا نرخِ **تمدید** بالاتر.
     *
     * مشکل این‌جاست که ما قیمتِ مشتری را **سرِ سفارش قفل می‌کنیم** و تا وقتی
     * سرویس زنده است همان را فاکتور می‌کنیم. اگر بهایِ تمام‌شدهٔ ما از دورهٔ دوم
     * بالا برود، از آن لحظه **هر تمدید ضرر خالص است** — و چون سرویس خودکار
     * تمدید می‌شود، ضرر ماه‌به‌ماه بی‌صدا تکرار می‌شود.
     *
     * پس پیش‌فرض: کنارشان می‌گذاریم. اگر مدیر مطمئن شد قیمت واقعاً پایدار است،
     * با یک تنظیم برشان می‌گرداند. این همان قاعدهٔ همیشگیِ این پروژه است:
     * چیزی که نمی‌دانیم پایدار است، نباید بی‌خبر فروخته شود.
     */
    private function isPromoProduct(array $p): bool
    {
        if (\App\Models\Setting::get('aeza_include_promo') === '1') {
            return false;
        }

        $hay = mb_strtolower(trim(
            ((string) ($p['name'] ?? '')).' '
            .((string) data_get($p, 'group.name', '')).' '
            .((string) data_get($p, 'group.payload.label', ''))
        ));

        foreach (['promo', 'акци', 'sale', 'discount', 'special offer', 'trial'] as $needle) {
            if ($hay !== '' && str_contains($hay, $needle)) {
                return true;
            }
        }

        // نشانهٔ دومِ قطعی‌تر از نام: قیمتِ دورهٔ اول از قیمتِ عادی کمتر است.
        // یعنی خودِ ارائه‌دهنده می‌گوید این نرخ موقتی است.
        $first = $this->rawTermValue($p, ['firstPrices', 'first_prices']);
        $normal = $this->rawTermValue($p, ['prices', 'rawPrices', 'raw_prices']);

        return $first > 0 && $normal > 0 && $first < $normal * 0.9;
    }

    /** عددِ خامِ قیمتِ ماهانه از یک فهرستِ ظرفِ مشخص (بی‌تفسیرِ واحد) */
    private function rawTermValue(array $p, array $bags): float
    {
        foreach ($bags as $bag) {
            foreach (['month', 'monthly', '1_month', 'mo'] as $term) {
                $v = data_get($p, $bag.'.'.$term);

                if (is_array($v)) {
                    $v = $v['value'] ?? $v['amount'] ?? null;
                }

                if (is_numeric($v) && (float) $v > 0) {
                    return (float) $v;
                }
            }
        }

        return 0.0;
    }

    private function monthlyRub(array $p): float
    {
        $raw = 0.0;

        foreach (['individualPrices', 'individual_prices', 'rawPrices', 'raw_prices',
            'prices', 'price', 'payment'] as $bag) {
            foreach (['month', 'monthly', '1_month', 'mo'] as $term) {
                $v = data_get($p, $bag.'.'.$term);

                if (is_array($v)) {
                    $v = $v['value'] ?? $v['amount'] ?? null;
                }

                if (is_numeric($v) && (float) $v > 0) {
                    $raw = (float) $v;
                    break 2;
                }
            }
        }

        if ($raw <= 0) {
            foreach (['priceMonth', 'monthPrice', 'monthlyPrice', 'price'] as $k) {
                $v = data_get($p, $k);

                if (is_numeric($v) && (float) $v > 0) {
                    $raw = (float) $v;
                    break;
                }
            }
        }

        if ($raw <= 0) {
            return 0.0;
        }

        return $this->interpretMonthlyRub($raw);
    }

    /**
     * عددِ خام → روبلِ ماهانه.
     *
     * ═══ چرا این یک **تنظیم** است و نه یک حدسِ هوشمند ═══
     *
     * تلاشِ اول این بود که واحد را از بزرگیِ عدد حدس بزنیم (کوپک یا روبل). آن
     * روش **قابلِ اتکا نیست** و دلیلش ریاضی است، نه سلیقه: عددِ ۵۰٬۰۰۰ اگر کوپک
     * باشد ۵۰۰ روبل است و اگر روبل باشد ۵۰٬۰۰۰ روبل — و **هر دو** برای یک سرورِ
     * مجازی قیمتِ کاملاً منطقی‌ای هستند. هیچ بازه‌ای این دو را از هم جدا نمی‌کند.
     *
     * پس واحد را نمی‌شود از داده فهمید؛ خصوصیتِ ثابتِ **API** است. یک بار درست
     * تعیین می‌شود و همه‌جا همان اعمال می‌گردد.
     *
     * پیش‌فرض ۱۰۰ (کوپک) از داکیومنتِ Terraform-providerِ خودِ آن ارائه‌دهنده
     * می‌آید که می‌گوید مقادیر «کوچک‌ترین یکای ارز» اند و در سه مبدلش هم بر ۱۰۰
     * تقسیم می‌کند. ولی این را روی حسابِ واقعی راستی‌آزمایی نکرده‌ایم و **پولِ
     * واقعی وسط است**، پس مدیر می‌تواند در تنظیمات عوضش کند و صفحهٔ عیب‌یابی
     * عددِ خام را کنارِ عددِ تفسیرشده نشان می‌دهد تا با فاکتورِ خودش مقایسه کند.
     *
     * بازهٔ منطقی هنوز هست، ولی نقشش عوض شده: دیگر واحد را انتخاب نمی‌کند، فقط
     * عددِ **بی‌معنا** را رد می‌کند (۳ روبل یا ۹ میلیون روبل در ماه).
     */
    private function interpretMonthlyRub(float $raw): float
    {
        $divisor = self::priceDivisor();
        $value = $divisor > 0 ? $raw / $divisor : $raw;

        return $this->plausibleMonthlyRub($value) ? $value : 0.0;
    }

    /**
     * مقسومِ قیمت — ۱۰۰ یعنی «عددها کوپک‌اند»، ۱ یعنی «همان روبل‌اند».
     *
     * public است چون صفحهٔ عیب‌یابی هم نشانش می‌دهد؛ مدیر باید ببیند با چه
     * فرضی قیمت ساخته شده.
     */
    public static function priceDivisor(): float
    {
        $v = (float) (\App\Models\Setting::get('aeza_price_divisor') ?: 0);

        // فقط دو مقدارِ معنادار؛ هر چیزِ دیگری اشتباهِ تایپی است و نباید
        // بی‌صدا قیمت را خراب کند.
        return in_array($v, [1.0, 100.0], true) ? $v : 100.0;
    }

    private function plausibleMonthlyRub(float $v): bool
    {
        return $v >= self::MONTHLY_RUB_MIN && $v <= self::MONTHLY_RUB_MAX;
    }

    /**
     * @return array{0:string,1:string,2:string} کشور، شهر، شناسهٔ مکانِ ارائه‌دهنده
     *
     * ⚠️ مکان روی **محصول** نیست، روی **گروهِ** محصول است:
     * `group.payload.code` کدِ دوحرفیِ کشور (`nl`, `de`, `fr`) و
     * `group.payload.label` برچسبِ مکان (`NL-SHARED`, `US-DEDICATED`).
     * روی سرویسِ ساخته‌شده هم `locationCode` همان کدِ دوحرفیِ کشور است.
     *
     * این ارائه‌دهنده **شهر نمی‌دهد**، فقط کشور. پس شهر را از هر متنی که در
     * دست است بیرون می‌کشیم؛ اگر نشد، کدِ مکان کشوری می‌ماند. این ناخوشایند
     * است ولی صادقانه — شهرِ ساختگی، گروه‌بندیِ عرضه‌ها را دروغ می‌کند.
     */
    private function locationOf(array $p): array
    {
        $country = '';

        foreach (['group.payload.code', 'payload.code', 'locationCode', 'location_code',
            'location.country', 'country', 'group.country', 'group.payload.country'] as $k) {
            $v = data_get($p, $k);

            if (is_string($v) && trim($v) !== '') {
                $country = trim($v);
                break;
            }
        }

        $city = '';

        /*
        | 🔴 فقط فیلدهایی که **اسمشان شهر است** مستقیم اعتماد می‌شوند.
        |
        | قبلاً `group.names.en` و `location.name` هم در همین فهرست بودند، ولی
        | آن‌ها نامِ **گروهِ محصول**اند نه مکان: «Shared»، «Dedicated»، «AMD».
        | نتیجه‌اش این بود که وقتی فیلدِ واقعیِ شهر خالی می‌آمد، ردهٔ محصول
        | به‌عنوان شهر می‌نشست — و مشتری در صفحهٔ آلمان ستونِ مکان را «AMD»
        | می‌دید و در فرانسه «Shared». بدتر از زشتی: `CloudNaming::locationCode`
        | از همین شهر ساخته می‌شود، پس مکان‌های ساختگی و صفحاتِ `/cloud/{code}`
        | بی‌معنا هم تولید می‌شدند.
        |
        | حالا آن دو فیلد به متنی تبدیل می‌شوند که از آن **شهرِ شناخته‌شده**
        | استخراج می‌شود؛ اگر شهری در آن نبود، شهر خالی می‌مانَد — که درست است.
        */
        foreach (['location.city', 'city', 'group.payload.city', 'payload.city'] as $k) {
            $v = data_get($p, $k);

            if (is_string($v) && trim($v) !== '') {
                $city = trim($v);
                break;
            }
        }

        $label = (string) (data_get($p, 'group.payload.label') ?? data_get($p, 'payload.label') ?? '');
        $groupName = (string) (data_get($p, 'group.name') ?? '');

        // شهر در فیلدِ خودش نبود؟ از متنِ گروه/برچسب/نام دربیاور — فقط شهرهای
        // شناخته‌شده، تا «فرانکفورتِ» دو زیرساخت به یک کدِ مکان برسند.
        if ($city === '') {
            $city = self::cityFromText(implode(' ', [
                $groupName,
                $label,
                (string) ($p['name'] ?? ''),
                (string) (data_get($p, 'group.names.en') ?? ''),
                (string) (data_get($p, 'location.name') ?? ''),
            ]));
        }

        // برچسبِ گروه با کدِ کشور شروع می‌شود (`NL-SHARED`) — آخرین راهِ کشور
        if ($country === '' && preg_match('/^([A-Za-z]{2})[-_\s]/', $label, $m) === 1) {
            $country = $m[1];
        }

        if ($country === '' && $city !== '') {
            $country = self::countryOfCity($city);
        }

        $ref = '';

        foreach (['group.payload.label', 'location.id', 'locationId', 'group.id', 'groupId'] as $k) {
            $v = data_get($p, $k);

            if ((is_string($v) && trim($v) !== '') || is_int($v)) {
                $ref = (string) $v;
                break;
            }
        }

        // کشورِ دوحرفی ولی بی‌شهر: کدِ مکان `nl-nl` می‌شود. زشت است ولی پایدار و
        // بی‌دروغ؛ عوض کردنش یعنی جعلِ شهر.
        return [$country, $city, $ref];
    }

    /** شهرِ شناخته‌شده در یک متنِ آزاد — فقط برای بهبودِ گروه‌بندی، نه حدسِ داده */
    private static function cityFromText(string $text): string
    {
        $t = strtolower($text);

        foreach ([
            'frankfurt', 'falkenstein', 'nuremberg', 'düsseldorf', 'amsterdam', 'helsinki',
            'stockholm', 'london', 'paris', 'warsaw', 'istanbul', 'moscow', 'saint petersburg',
            'kazan', 'yekaterinburg', 'novosibirsk', 'almaty', 'yerevan', 'tbilisi', 'dubai',
            'singapore', 'tokyo', 'ashburn', 'los angeles', 'new york', 'miami', 'dallas',
            'hillsboro',
        ] as $city) {
            if (str_contains($t, $city)) {
                return $city;
            }
        }

        return '';
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

    /**
     * موجودی — جایی که پول برمی‌گردد.
     *
     * `group.payload.isDisabled` تنها نشانهٔ صریحی است که در SDKها پیدا شد
     * (ترافورم‌پروایدر آن را `is_disabled` گروه می‌کند): یعنی آن مکان/سرور
     * موقتاً بسته است. بی‌خواندنش پلنِ تمام‌شده را می‌فروشیم و تحویل شکست
     * می‌خورد — پولِ گرفته‌شده و تحویلِ ناممکن.
     */
    private function inStock(array $p): bool
    {
        foreach (['group.payload.isDisabled', 'payload.isDisabled', 'isDisabled',
            'disabled', 'outOfStock', 'out_of_stock', 'soldOut'] as $k) {
            $v = data_get($p, $k);

            if ($v === true || (is_numeric($v) && (int) $v > 0)) {
                return false;
            }
        }

        foreach (['available', 'isAvailable', 'inStock', 'in_stock', 'stock', 'enabled', 'isEnabled'] as $k) {
            $v = data_get($p, $k);

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
            // ⚠️ پیامِ خالی‌ودرشتِ «Proxy internal server error» ساعت‌ها وقت گرفت،
            // چون نه کدِ HTTP داشت نه بدنه. این‌جا هر سه را نگه می‌داریم تا
            // دفعهٔ بعد **علت** معلوم باشد، نه فقط اینکه «نشد».
            $raw = mb_substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE) ?: '', 0, 300);
            $hint = str_contains(strtolower((string) $r['message']), 'proxy internal server error')
                ? ' — این پیامِ گیت‌وی معمولاً یعنی مسیر شناخته نشد یا موجودیِ حسابِ زیرساخت کافی نیست.'
                : '';

            return ['ok' => false, 'message' => 'ثبتِ سفارش نزدِ زیرساخت انجام نشد: '
                .$r['message'].' (کدِ HTTP: '.$r['status'].')'.$hint
                .($raw !== '' && $raw !== '[]' ? ' | پاسخ: '.$raw : ''), ] + $fail;
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

    /**
     * فهرستِ همهٔ سرویس‌های این حساب.
     *
     * ⚠️ پارامترِ اندازهٔ صفحه در این زیرساخت **`count`** است، نه `limit`؛ همان
     * چیزی که بقیهٔ این کلاس همه‌جا می‌فرستد. یک بار با `limit/offset` نوشته شد
     * و خطرش این بود: زیرساخت پارامترِ ناشناخته را نادیده می‌گیرد، صفحهٔ
     * پیش‌فرض (~۲۵ ردیف) برمی‌گردد، شرطِ «کمتر از سقف ⇒ تمام شد» فعال می‌شود و
     * فهرستِ **ناقص** با `ok=true` و پیامِ خالی بیرون می‌آید — یعنی دقیقاً همان
     * شکلی که `CloudInventory` کاملاً معتبر می‌شمارد. نتیجه: صدها سرورِ زندهٔ
     * مشتری «شبح» گزارش می‌شدند.
     *
     * پس: `count` می‌فرستیم، **`total`ِ خودِ پاسخ** را می‌خوانیم (تنها سیگنالِ
     * قطعیِ داکیومنت‌شده)، و اگر آنچه جمع کردیم به `total` نرسید صریح می‌گوییم
     * فهرست ناقص است.
     *
     * از `items()` عمداً استفاده نمی‌کنیم: آن `flattenGroups` را صدا می‌زند که
     * برای **محصول** نوشته شده و هر ردیفِ دارای کلیدِ `items` را باز می‌کند —
     * روی فهرستِ سرویس، همان باز کردن یک سرور را به چند ردیفِ قلابی تبدیل
     * می‌کند و گزارشِ یتیم‌ها را بی‌معنا می‌سازد.
     */
    public function listServers(): array
    {
        $servers = [];
        $seen = [];
        $page = 0;
        $size = 500;
        $total = null;

        for ($i = 0; $i < 20; $i++) {
            $r = $this->req('GET', '/services', [
                'extra' => 1, 'count' => $size, 'offset' => $page * $size,
            ]);

            if (! $r['ok']) {
                // صفحهٔ اول که خطا دهد یعنی هیچ نمی‌دانیم؛ خطا را بالا می‌دهیم تا
                // «۰ سرور» با «نتوانستیم بپرسیم» اشتباه گرفته نشود.
                return $i === 0
                    ? ['ok' => false, 'message' => $r['message'], 'servers' => []]
                    : ['ok' => true, 'message' => 'فهرست ناقص خوانده شد.', 'servers' => $servers];
            }

            if ($total === null) {
                $t = data_get($r['body'], 'data.total') ?? data_get($r['body'], 'total');
                $total = is_numeric($t) ? (int) $t : null;
            }

            $rows = data_get($r['body'], 'data.items');
            $rows = is_array($rows) ? $rows : (array) (data_get($r['body'], 'items') ?? data_get($r['body'], 'data') ?? []);
            $rows = array_values(array_filter($rows, 'is_array'));

            if ($rows === []) {
                break;
            }

            $fresh = 0;

            foreach ($rows as $s) {
                $ref = (string) ($s['id'] ?? $s['serviceId'] ?? '');

                // بی‌این محافظ، اگر زیرساخت `offset` را نادیده بگیرد همان صفحهٔ
                // اول ۲۰ بار تکرار می‌شد و مدیر ۲۰۰۰ سرورِ تکراری می‌دید.
                if ($ref === '' || isset($seen[$ref])) {
                    continue;
                }

                $seen[$ref] = true;
                $fresh++;

                $servers[] = [
                    'ref'      => $ref,
                    'name'     => (string) ($s['name'] ?? $s['hostname'] ?? $ref),
                    'status'   => $this->mapStatus((string) ($s['currentStatus'] ?? $s['status'] ?? '')),
                    'ipv4'     => $this->firstIp($s, 4),
                    'ipv6'     => $this->firstIp($s, 6),
                    'plan'     => data_get($s, 'product.name') ?? data_get($s, 'productName'),
                    'location' => data_get($s, 'location.name') ?? data_get($s, 'locationCode'),
                    'created'  => $s['createdAt'] ?? $s['created_at'] ?? null,
                ];
            }

            // تکرارِ صفحه (offset نادیده گرفته شد) یا رسیدن به کل → بس است
            if ($fresh === 0 || ($total !== null && count($servers) >= $total)) {
                break;
            }

            // بی‌`total` نمی‌شود مطمئن بود؛ صفحهٔ کوتاه‌تر از سقف را پایان می‌گیریم
            if ($total === null && count($rows) < $size) {
                break;
            }

            $page++;
        }

        // 🔴 اگر زیرساخت گفت ۱۴۰ تا داری و ما ۲۵ تا گرفتیم، **صریح** بگو ناقص
        // است. سکوت در این حالت یعنی گزارشِ یتیم/شبح روی داده‌ای ناقص ساخته
        // می‌شود و مدیر بر اساسش سرویسِ سالم را می‌بندد.
        $short = $total !== null && count($servers) < $total;

        return [
            'ok'      => true,
            'message' => $short
                ? 'فهرست ناقص است: زیرساخت '.$total.' سرویس دارد ولی '.count($servers).' تا خوانده شد.'
                : '',
            'servers' => $servers,
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
