<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * زیرساختِ ۵ — Proxmox VE (میزبانِ **خودمان** در ایران).
 *
 * ═══ چرا این درایور با بقیه فرق دارد ═══
 *
 * هتزنر/آیزا/آروان سرور را «می‌خریم»؛ این‌جا سرور را روی سخت‌افزارِ خودمان
 * **می‌سازیم**. پس نه کاتالوگِ فروش دارد و نه قیمتِ ارز — یک پلنِ ثابتِ
 * «Exit VPS» داریم که رویش VM با clone از یک قالبِ cloud-init بالا می‌آید.
 *
 * ساختِ سرور = clone قالب → تنظیمِ cloud-init (کاربر/رمز/شبکه) → روشن‌کردن.
 * IP از یک محدودهٔ ثابت (subnet داخلی) با اسکنِ VMهای موجود انتخاب می‌شود.
 *
 * ⚠️ سفیدبرچسبی این‌جا هم برقرار است: مشتری فقط «تهران» را می‌بیند، و نامِ
 * Proxmox/شناسهٔ VMID هرگز به `message`ِ مشتری‌رس نمی‌رود — فقط در `raw` (که
 * پنلِ مدیریت می‌خواند) و در لاگ.
 *
 * ⚠️ «تعویض‌پذیریِ» میزبان: همهٔ مقادیر (آدرس، نود، قالب، storage، …) از
 * Settings خوانده می‌شوند، پس عوض‌کردنِ سرورِ Proxmox = تغییرِ یک تنظیم، بی‌دیپلوی.
 *
 * ⚠️ مسیریابیِ کشوریِ اکسیت (per-country) عمداً این‌جا نیست — افزایشِ بعدی است.
 * فعلاً فقط برچسبِ اختیاریِ `exit_country` را در description‌ِ VM ذخیره می‌کنیم.
 *
 * قرارداد: هیچ متدی throw نمی‌کند؛ خطا در آرایهٔ برگشتی (`ok=false`) می‌آید.
 */
class ProxmoxClient implements CloudProvider
{
    private const DEFAULT_API_URL = 'https://85.9.108.118:8006/api2/json';

    private const DEFAULT_TOKEN_ID = 'svc-controller@pve!provisioner';

    public function slug(): string
    {
        return 'proxmox';
    }

    // ───────────────────────── پیکربندی (با پیش‌فرض) ─────────────────────────

    private function baseUrl(): string
    {
        return rtrim((string) (Setting::get('proxmox_api_url') ?: self::DEFAULT_API_URL), '/');
    }

    private function node(): string
    {
        return (string) (Setting::get('proxmox_node') ?: 'ir');
    }

    private function tokenId(): string
    {
        return (string) (Setting::get('proxmox_token_id') ?: self::DEFAULT_TOKEN_ID);
    }

    private function tokenSecret(): ?string
    {
        return Setting::getSecret('proxmox_token_secret');
    }

    private function templateVmid(): int
    {
        return (int) (Setting::get('proxmox_template_vmid') ?: 9002);
    }

    private function storage(): string
    {
        return (string) (Setting::get('proxmox_storage') ?: 'vmstoreid');
    }

    /** poolِ مقصدِ ماشین‌های مشتری — ACLِ توکن فقط همین‌جا اجازهٔ ساخت دارد */
    private function pool(): string
    {
        return (string) (Setting::get('proxmox_pool') ?: 'customers');
    }

    private function bridge(): string
    {
        return (string) (Setting::get('proxmox_bridge') ?: 'vmbr1');
    }

    private function gateway(): string
    {
        return (string) (Setting::get('proxmox_gateway') ?: '10.10.10.1');
    }

    private function ipStart(): string
    {
        return (string) (Setting::get('proxmox_ip_start') ?: '10.10.10.60');
    }

    /**
     * «تنظیم‌شده» = فقط توکنِ سرّی لازم است؛ بقیه پیش‌فرض دارند. بی‌توکن هیچ
     * تماسی امضا نمی‌شود و هر درخواست ۴۰۱ می‌گیرد.
     */
    public function isConfigured(): bool
    {
        return filled($this->tokenSecret());
    }

    public function capabilities(): array
    {
        return [
            'console'        => false,   // noVNC از راهِ API فعلاً استاب است
            'rebuild'        => true,
            'resize'         => false,   // تغییرِ پلن استاب است
            'snapshot'       => false,
            'metrics'        => false,   // استاب
            'reset_password' => true,
            'ipv6'           => false,
            'rescue'         => false,
            // کلیدِ SSH از راهِ cloud-init در createServer تزریق می‌شود، نه از
            // «حسابِ ما نزدِ زیرساخت» — پس مسیرِ uploadSshKeyِ پایپ‌لاین این‌جا
            // معنا ندارد و false است تا فروشگاه گزینهٔ نشدنی نشان ندهد.
            'ssh_key'        => false,
            'extra_ip'       => false,   // استاب
        ];
    }

    // ───────────────────────── لایهٔ تماس ─────────────────────────

    /**
     * ⚠️ سه نکتهٔ Proxmox:
     *  • احراز با هدرِ `Authorization: PVEAPIToken=<token_id>=<token_secret>`
     *    (نه Bearer). این توکنِ API است، پس نه نشست لازم است نه CSRF.
     *  • گواهیِ TLS خودامضا است، پس `withoutVerifying()`.
     *  • پاسخ‌ها در `{"data": …}` می‌آیند و کدِ HTTP درست داده می‌شود.
     *
     * @return array{ok:bool,status:int,body:array,message:string}
     */
    private function req(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'message' => 'اتصالِ این زیرساخت تنظیم نشده است.'];
        }

        $method = strtoupper($method);
        $url = $this->baseUrl().$path;

        try {
            $http = Http::withoutVerifying()             // گواهیِ خودامضا
                ->withHeaders(['Authorization' => 'PVEAPIToken='.$this->tokenId().'='.$this->tokenSecret()])
                ->acceptJson()
                ->timeout(30)
                ->connectTimeout(10);

            $res = match ($method) {
                // GET/DELETE پارامتر را در کوئری می‌برند و بدنه ندارند
                'GET'    => $http->get($url, $payload),
                'DELETE' => $http->delete($url.($payload !== [] ? '?'.http_build_query($payload) : '')),
                // Proxmox بدنه را به‌صورتِ form می‌خواهد
                'POST'   => $http->asForm()->post($url, $payload),
                'PUT'    => $http->asForm()->put($url, $payload),
                default  => throw new \InvalidArgumentException($method),
            };
        } catch (\Throwable $e) {
            Log::warning('proxmox.transport', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'body' => [], 'message' => 'ارتباط با زیرساخت برقرار نشد.'];
        }

        $body = (array) ($res->json() ?? []);

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'body' => $body, 'message' => ''];
        }

        // Proxmox خطا را در `errors` (نگاشتِ فیلد→پیام) یا در متنِ خام می‌دهد
        $errors = $body['errors'] ?? null;
        $msg = is_array($errors) && $errors !== []
            ? implode(' · ', array_map(fn ($k, $v) => $k.': '.$v, array_keys($errors), array_values($errors)))
            : (string) ($body['message'] ?? ('خطای زیرساخت (HTTP '.$res->status().')'));

        return ['ok' => false, 'status' => $res->status(), 'body' => $body, 'message' => $msg];
    }

    public function testConnection(): array
    {
        $r = $this->req('GET', '/nodes/'.rawurlencode($this->node()).'/qemu');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }

        $n = count((array) ($r['body']['data'] ?? []));

        return [
            'ok' => true,
            'message' => 'اتصال برقرار است — '.fa_num($n).' ماشین روی این زیرساخت.',
            'meta' => ['vms' => $n],
        ];
    }

    // ───────────────────────── کاتالوگ ─────────────────────────

    /**
     * کاتالوگ: یک «Exit VPS» به ازای هر **کشورِ خروج**، با یک مکانِ `exit-<cc>`
     * و یک پلنِ هم‌مشخصات (۲هسته/۲گیگ/۳۰گیگ). فهرستِ کشورها از تنظیمِ
     * `proxmox_exit_countries` (CSV، پیش‌فرض `de,nl,fi`) می‌آید تا افزودن/برداشتنِ
     * کشور بی‌دیپلوی باشد. ایمیج یکی است (اوبونتو ۲۴٫۰۴ = همان قالب).
     *
     * چون میزبانِ خودمان است قیمت را از ارز نمی‌سازیم و `cost_eur_cents` بهایِ
     * تمام‌شدهٔ اسمی است (CloudPricing رویش حاشیه می‌گذارد). سفیدبرچسبی برقرار
     * است: مشتری فقط کشورِ خروج را می‌بیند، نه نامِ Proxmox/نود.
     */
    public function fetchCatalog(): array
    {
        $empty = ['locations' => [], 'plans' => [], 'images' => []];

        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'اتصالِ این زیرساخت تنظیم نشده.'] + $empty;
        }

        $node = $this->node();
        $locations = [];
        $plans = [];

        foreach ($this->exitCountries() as $cc) {
            $iso = strtoupper($cc);
            // نامِ کشور از نگاشتِ سه‌زبانهٔ خودمان (همان منبعِ نام/پرچمِ مکان‌ها)
            $name = CloudLocation::COUNTRIES[$iso]['en'] ?? $iso;

            $locations[] = [
                'code'              => 'exit-'.$cc,
                'country'           => $iso,
                'city'              => $name.' (exit)',
                'provider_location' => $node,
                'latitude'          => null,
                'longitude'         => null,
            ];

            $plans[] = [
                'provider_ref'      => 'exit-vps-'.$cc,
                'provider_location' => $node,
                'location_code'     => 'exit-'.$cc,
                'vcpu'              => 2,
                'ram_mb'            => 2048,
                'disk_gb'           => 30,
                'disk_type'         => 'ssd',
                'traffic_gb'        => 1000,
                'cpu_kind'          => 'shared',
                'arch'              => 'x86',
                'cost_eur_cents'    => 400,
                'in_stock'          => true,
                'name'              => 'Exit VPS 2GB',
            ];
        }

        /*
        | ═══ خطِ VPSِ ایران — روی سختِ‌افزارِ خودمان ═══
        |
        | 🔴 چرا لازم شد (۱۱ شهریور ۱۴۰۵): مشتری SN-978603 سرورِ ایرانِ
        | ۱هسته/۱گیگ خرید و پولش را داد، ولی هیچ پلنِ ایرانی برای این زیرساخت
        | وجود نداشت. و پلن **از هیچ صفحه‌ای دستی ساخته نمی‌شود** — تنها منبعِ
        | ردیف‌های `cloud_plans` همین متد است. پس تحویل ناممکن بود، نه سخت.
        |
        | ⚠️ مکان عمداً همان `ir-tehran`ِ آروان است (همان `CloudNaming` می‌سازدش):
        | یک اسلاگِ مشترک یعنی اگر روزی هر دو زیرساخت یک اندازه داشته باشند،
        | مشتری یک کارت می‌بیند و تحویل از ارزان‌ترینِ **در دسترس** انجام
        | می‌شود — همان سفیدبرچسبی که کلِ این لایه رویش بنا شده. امروز که
        | آروان قرنطینه است، تهران خودکار روی سختِ‌افزارِ خودمان می‌افتد.
        |
        | ⚠️ اندازه‌ها از تنظیمات می‌آیند نه سخت‌کد، دقیقاً به همان دلیلِ
        | `proxmox_exit_countries`: اضافه‌کردنِ اندازهٔ بعدی نباید دیپلوی بخواهد.
        */
        $irCity = $this->irCity();

        if ($irCity !== '') {
            $locCode = CloudNaming::locationCode('IR', $irCity, 'ir');

            $locations[] = [
                'code'              => $locCode,
                'country'           => 'IR',
                'city'              => $irCity,
                'provider_location' => $node,
                'latitude'          => null,
                'longitude'         => null,
            ];

            foreach ($this->irPlans() as $spec) {
                [$vcpu, $ramGb, $diskGb] = $spec;

                $plans[] = [
                    'provider_ref'      => 'ir-vps-'.$vcpu.'-'.$ramGb.'-'.$diskGb,
                    'provider_location' => $node,
                    'location_code'     => $locCode,
                    'vcpu'              => $vcpu,
                    'ram_mb'            => $ramGb * 1024,
                    'disk_gb'           => $diskGb,
                    'disk_type'         => 'ssd',
                    'traffic_gb'        => 1000,
                    'cpu_kind'          => 'shared',
                    'arch'              => 'x86',
                    'cost_eur_cents'    => self::irCostCents($vcpu, $ramGb, $diskGb),
                    'in_stock'          => true,
                    'name'              => 'Iran VPS '.$ramGb.'GB',
                ];
            }
        }

        return [
            'ok' => true, 'message' => '',
            'locations' => $locations,
            'images' => [[
                // شناسهٔ ایمیج = VMIDِ قالب؛ createServer/rebuild از همین کلون می‌کند
                'provider_ref' => (string) $this->templateVmid(),
                'key'          => 'ubuntu-24.04',
                'kind'         => 'os',
                'family'       => 'ubuntu',
                'version'      => '24.04',
                'label'        => 'Ubuntu 24.04',
                'arch'         => 'x86',
                'min_disk_gb'  => 10,
            ]],
            'plans' => $plans,
        ];
    }

    /**
     * کشورهای خروجِ فعال از تنظیمات (CSV، پیش‌فرض `de,nl,fi`). کوچک‌شده،
     * تروخالی‌زدایی‌شده و بی‌تکرار؛ اگر خالی بود به پیش‌فرض برمی‌گردد.
     *
     * @return array<int,string>
     */
    private function exitCountries(): array
    {
        $raw = (string) (Setting::get('proxmox_exit_countries') ?: 'de,nl,fi');

        $list = array_values(array_unique(array_filter(
            array_map(fn ($c) => strtolower(trim($c)), explode(',', $raw)),
            fn ($c) => $c !== '',
        )));

        return $list === [] ? ['de', 'nl', 'fi'] : $list;
    }

    /**
     * شهرِ خطِ ایران (تنظیمِ `proxmox_ir_city`، پیش‌فرض `tehran`).
     *
     * رشتهٔ خالی یعنی «این خط را نساز» — راهِ خاموش‌کردنش بی‌دیپلوی.
     */
    private function irCity(): string
    {
        $raw = Setting::get('proxmox_ir_city');

        return strtolower(trim((string) ($raw ?? 'tehran')));
    }

    /**
     * اندازه‌های خطِ ایران از تنظیمِ `proxmox_ir_plans` — CSV با شکلِ
     * `vcpu-ramGB-diskGB` (مثلاً `1-1-25,2-2-40,4-8-80`).
     *
     * ⚠️ ردیفِ بدشکل **بی‌صدا رد** می‌شود، نه اینکه کلِ کاتالوگ را بشکند:
     * یک تایپو در تنظیمات نباید همگام‌سازیِ زیرساخت را از کار بیندازد. ولی
     * اگر هیچ ردیفِ سالمی نماند، به پیش‌فرض برمی‌گردیم تا خط بی‌صدا غیب نشود.
     *
     * @return array<int, array{0:int,1:int,2:int}>
     */
    private function irPlans(): array
    {
        $raw = (string) (Setting::get('proxmox_ir_plans') ?: self::IR_PLANS_DEFAULT);
        $out = [];

        foreach (explode(',', $raw) as $row) {
            $parts = array_map('trim', explode('-', trim($row)));

            if (count($parts) !== 3) {
                continue;
            }

            [$vcpu, $ram, $disk] = array_map('intval', $parts);

            // کرانِ عقل: صفر یا منفی پلنِ بی‌معنا می‌سازد، و عددِ نجومی
            // ردیفی که هیچ‌وقت تحویل نمی‌شود.
            if ($vcpu < 1 || $vcpu > 64 || $ram < 1 || $ram > 512 || $disk < 5 || $disk > 4000) {
                continue;
            }

            $out[$vcpu.'-'.$ram.'-'.$disk] = [$vcpu, $ram, $disk];
        }

        return array_values($out) ?: self::parseDefaultIrPlans();
    }

    /** پیش‌فرضِ خطِ ایران — تنها جایی که این اعداد نوشته شده‌اند. */
    private const IR_PLANS_DEFAULT = '1-1-25,2-2-40,4-8-80';

    /** @return array<int, array{0:int,1:int,2:int}> */
    private static function parseDefaultIrPlans(): array
    {
        $out = [];

        foreach (explode(',', self::IR_PLANS_DEFAULT) as $row) {
            [$vcpu, $ram, $disk] = array_map('intval', explode('-', $row));
            $out[] = [$vcpu, $ram, $disk];
        }

        return $out;
    }

    /**
     * بهایِ تمام‌شدهٔ **اسمی** به سنتِ یورو — میزبانِ خودمان است، پس این عدد
     * فاکتورِ کسی نیست؛ فقط پایه‌ای است که `CloudPricing` حاشیه رویش می‌گذارد.
     *
     * ⚠️ ضریب‌ها طوری چیده شده‌اند که «۲هسته/۲گیگ/۳۰گیگ» همان ۴۰۰ سنتِ
     * Exit VPS در بیاید — وگرنه دو محصولِ هم‌اندازه روی یک سختِ‌افزار دو
     * بهایِ متفاوت می‌گرفتند و گزارشِ سود بی‌معنا می‌شد.
     */
    private static function irCostCents(int $vcpu, int $ramGb, int $diskGb): int
    {
        return 110 + ($vcpu * 60) + ($ramGb * 40) + ($diskGb * 3);
    }

    // ───────────────────────── ساخت ─────────────────────────

    /**
     * ساختِ سرور. idempotent روی `name`.
     *
     * جریان: (الف) اگر VMی با همین نام هست همان را برگردان؛ (ب) شناسه و IPِ آزاد
     * بگیر؛ (ج) قالب را clone کن؛ (د) cloud-init + شبکه + دیسک را تنظیم کن؛
     * (ه) روشن کن؛ (و) نتیجه را با رمزِ ساخته‌شده برگردان.
     */
    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'error'];
        $name = (string) ($spec['name'] ?? '');

        if ($name === '') {
            return ['ok' => false, 'message' => 'نامِ سرور خالی است.'] + $fail;
        }

        try {
            $node = $this->node();

            // ── (الف) idempotency ──
            $list = $this->req('GET', '/nodes/'.rawurlencode($node).'/qemu');

            if (! $list['ok']) {
                return ['ok' => false, 'message' => 'فهرستِ ماشین‌ها خوانده نشد.', 'raw' => ['detail' => $list['message']]] + $fail;
            }

            $vms = array_values(array_filter((array) ($list['body']['data'] ?? []), 'is_array'));

            foreach ($vms as $vm) {
                if ((string) ($vm['name'] ?? '') === $name) {
                    $vmid = (string) ($vm['vmid'] ?? '');

                    return [
                        'ok' => true, 'message' => 'سرور از قبل ساخته شده بود.',
                        'ref'  => $vmid,
                        'ipv4' => $this->vmIp($node, $vmid),
                        'ipv6' => null,
                        // رمز فقط لحظهٔ ساخت در دست است؛ این‌جا نداریم
                        'root_password' => null,
                        'status' => $this->mapStatus((string) ($vm['status'] ?? '')),
                        'raw' => ['vmid' => $vmid, 'node' => $node],
                    ];
                }
            }

            // ── (ب) شناسه + IPِ آزاد ──
            $idr = $this->req('GET', '/cluster/nextid');
            $vmid = (string) ($idr['body']['data'] ?? '');

            if (! $idr['ok'] || $vmid === '') {
                return ['ok' => false, 'message' => 'شناسهٔ آزاد برای ماشین گرفته نشد.'] + $fail;
            }

            $ip = $this->allocateIp($this->usedIps($node, $vms));

            if ($ip === null) {
                return ['ok' => false, 'message' => 'آدرسِ IP آزاد در محدوده پیدا نشد.'] + $fail;
            }

            // ── (ج) کلونِ قالب ──
            // ایمیج = VMIDِ قالب (از کاتالوگ)؛ اگر نامعتبر بود، قالبِ پیش‌فرضِ تنظیمات.
            $imageRef = (string) ($spec['image_ref'] ?? '');
            $tpl = ctype_digit($imageRef) ? $imageRef : (string) $this->templateVmid();

            $password = $this->randomPassword();

            /*
            | 🔴 `pool` اجباری است، نه سلیقه‌ای (رخدادِ سرویس #74).
            |
            | ACLِ توکنِ کنترلر روی Proxmox عمداً کم‌دسترسی است: VM.Allocate
            | فقط روی `/pool/customers` — نه روی `/vms`. کلونِ بدونِ pool یعنی
            | بررسیِ allocate روی `/vms/{newid}` انجام می‌شود و رد می‌شود؛ و
            | تازه اگر هم می‌گذشت، ماشینِ بیرون‌ازpool برای همهٔ فراخوان‌های
            | بعدیِ همین توکن (config/start/حذف) نامرئی می‌ماند.
            */
            $clone = $this->req('POST', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($tpl).'/clone', [
                'newid'   => (int) $vmid,
                'name'    => $name,
                'full'    => 1,
                'storage' => $this->storage(),
                'pool'    => $this->pool(),
            ]);

            if (! $clone['ok']) {
                return ['ok' => false, 'message' => 'ساختِ ماشین از قالب انجام نشد.', 'raw' => ['detail' => $clone['message']]] + $fail;
            }

            // کلون async است و ماشین را «قفل» می‌کند؛ تا پایانش صبر کن وگرنه
            // تنظیمِ بعدی «VM is locked» می‌گیرد.
            $this->waitForTask($node, (string) ($clone['body']['data'] ?? ''));

            // ── (د) cloud-init + شبکه ──
            $config = [
                'ciuser'      => 'root',
                'cipassword'  => $password,
                'ipconfig0'   => 'ip='.$ip.'/24,gw='.$this->gateway(),
                'net0'        => 'virtio,bridge='.$this->bridge(),
                'description' => $this->describe($spec),
            ];

            if (filled($spec['ssh_keys'] ?? null)) {
                // Proxmox کلیدها را **url-encode‌شده** می‌خواهد (خط‌به‌خط)
                $config['sshkeys'] = rawurlencode(implode("\n", (array) $spec['ssh_keys']));
            }

            $this->req('PUT', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($vmid).'/config', $config);

            // اندازهٔ دیسک — best-effort؛ شکستش تحویل را نمی‌شکند (سرور با دیسکِ
            // پیش‌فرضِ قالب هم بالا می‌آید).
            $this->resizeDisk($node, $vmid, (int) ($spec['disk_gb'] ?? 0));

            // ── (ه) روشن‌کردن ──
            $this->req('POST', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($vmid).'/status/start');

            // ── (و) بازگشت ──
            return [
                'ok' => true, 'message' => '',
                'ref'           => $vmid,
                'ipv4'          => $ip,
                'ipv6'          => null,
                'root_password' => $password,
                'status'        => 'building',
                'raw'           => ['vmid' => $vmid, 'node' => $node],
            ];
        } catch (\Throwable $e) {
            Log::warning('proxmox.create', ['name' => $name, 'err' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'ساختِ سرور با خطای غیرمنتظره روبه‌رو شد.'] + $fail;
        }
    }

    /** description‌ِ VM — برچسب‌ها (سرویس و کشورِ خروجِ اختیاری) را نگه می‌دارد */
    private function describe(array $spec): string
    {
        $labels = (array) ($spec['labels'] ?? []);
        $parts = ['managed-by=servernet'];

        if (filled($labels['snet-service'] ?? null)) {
            $parts[] = 'service='.$labels['snet-service'];
        }

        // ⚠️ فعلاً فقط **ذخیره** می‌شود؛ مسیریابیِ کشوری افزایشِ بعدی است.
        if (filled($labels['exit_country'] ?? null)) {
            $parts[] = 'exit_country='.$labels['exit_country'];
        }

        return implode(' ', $parts);
    }

    /**
     * IPهای اشغال‌شده از روی `ipconfig0`ِ همهٔ VMها — تا آدرسِ تکراری ندهیم.
     *
     * @param  array<int,array<string,mixed>>  $vms  خروجیِ فهرستِ qemu
     * @return array<string,bool>
     */
    private function usedIps(string $node, array $vms): array
    {
        $used = [];

        foreach ($vms as $vm) {
            $ip = $this->vmIp($node, (string) ($vm['vmid'] ?? ''));

            if ($ip !== null) {
                $used[$ip] = true;
            }
        }

        return $used;
    }

    /** IPِ یک VM از `ipconfig0`ِ کانفیگش (فرمت: `ip=10.10.10.60/24,gw=…`) */
    private function vmIp(string $node, string $vmid): ?string
    {
        if ($vmid === '') {
            return null;
        }

        $r = $this->req('GET', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($vmid).'/config');

        if (! $r['ok']) {
            return null;
        }

        $ipc = (string) data_get($r['body'], 'data.ipconfig0', '');

        return preg_match('/ip=([0-9]{1,3}(?:\.[0-9]{1,3}){3})/', $ipc, $m) === 1 ? $m[1] : null;
    }

    /**
     * اولین IPِ آزاد از `ip_start` به بالا، در همان /24.
     *
     * @param  array<string,bool>  $used
     */
    private function allocateIp(array $used): ?string
    {
        $startLong = ip2long($this->ipStart());

        if ($startLong === false) {
            return null;
        }

        $network = $startLong & 0xFFFFFF00;             // همان /24ِ آدرسِ شروع

        for ($host = ($startLong & 0xFF); $host <= 254; $host++) {
            $ip = long2ip($network | $host);

            if (! isset($used[$ip])) {
                return $ip;
            }
        }

        return null;
    }

    /** اندازهٔ دیسک را مطلق تنظیم کن؛ نامِ دیسکِ بوت بین قالب‌ها فرق دارد */
    private function resizeDisk(string $node, string $vmid, int $diskGb): void
    {
        if ($diskGb < 1) {
            return;
        }

        foreach (['scsi0', 'virtio0', 'sata0'] as $disk) {
            $r = $this->req('PUT', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($vmid).'/resize', [
                'disk' => $disk,
                'size' => $diskGb.'G',
            ]);

            if ($r['ok']) {
                return;
            }
        }
    }

    /**
     * صبر تا پایانِ یک تسکِ async (clone/stop/…). سقفِ زمانی دارد تا اگر تسک
     * گیر کرد، درایور تا ابد بلاک نشود.
     */
    private function waitForTask(string $node, string $upid): void
    {
        if ($upid === '') {
            return;
        }

        for ($i = 0; $i < 30; $i++) {
            $r = $this->req('GET', '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/status');
            $status = strtolower((string) data_get($r['body'], 'data.status', ''));

            // 'running' یعنی هنوز کار می‌کند؛ هر چیزِ دیگر (stopped/خالی) یعنی تمام.
            if ($status !== 'running') {
                return;
            }

            usleep(400000);
        }
    }

    // ───────────────────────── وضعیت و فهرست ─────────────────────────

    public function serverStatus(string $ref): array
    {
        $none = ['ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
        $node = $this->node();

        $r = $this->req('GET', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($ref).'/status/current');

        if (! $r['ok']) {
            // ماشینِ ناموجود = «حذف‌شده» (برای تطبیقِ موجودی). Proxmox گاهی
            // ۵۰۰ با «does not exist» می‌دهد نه ۴۰۴.
            $lower = mb_strtolower($r['message']);
            $absent = $r['status'] === 404
                || str_contains($lower, 'does not exist')
                || str_contains($lower, 'no such');

            return $absent
                ? ['ok' => true, 'message' => '', 'status' => 'deleted'] + $none
                : ['ok' => false, 'message' => $r['message'], 'status' => 'unknown'] + $none;
        }

        $data = (array) ($r['body']['data'] ?? []);
        $qmp = (string) ($data['qmpstatus'] ?? $data['status'] ?? '');

        return [
            'ok' => true, 'message' => '',
            'status'          => $this->mapStatus($qmp),
            // IP در status/current نیست؛ نمونه IPِ ذخیره‌شده‌اش را نگه می‌دارد.
            'ipv4'            => null,
            'ipv6'            => null,
            'traffic_used_gb' => null,
            'raw'             => ['status' => $data['status'] ?? null, 'qmpstatus' => $data['qmpstatus'] ?? null],
        ];
    }

    public function listServers(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'اتصالِ این زیرساخت تنظیم نشده.', 'servers' => []];
        }

        $node = $this->node();
        $r = $this->req('GET', '/nodes/'.rawurlencode($node).'/qemu');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['message'], 'servers' => []];
        }

        $servers = [];

        foreach ((array) ($r['body']['data'] ?? []) as $vm) {
            if (! is_array($vm)) {
                continue;
            }

            $vmid = (string) ($vm['vmid'] ?? '');

            if ($vmid === '') {
                continue;
            }

            $servers[] = [
                'ref'      => $vmid,
                'name'     => (string) ($vm['name'] ?? $vmid),
                'status'   => $this->mapStatus((string) ($vm['status'] ?? '')),
                'ipv4'     => $this->vmIp($node, $vmid),
                'ipv6'     => null,
                'plan'     => null,
                'location' => $node,
                'created'  => null,
            ];
        }

        return ['ok' => true, 'message' => '', 'servers' => $servers];
    }

    /** وضعیتِ Proxmox → واژگانِ ما */
    private function mapStatus(string $s): string
    {
        return match (strtolower(trim($s))) {
            'running' => 'running',
            'stopped' => 'off',
            ''        => 'deleted',       // نبودِ وضعیت = ماشین رفته
            default   => 'unknown',
        };
    }

    // ───────────────────────── چرخهٔ عمر ─────────────────────────

    public function power(string $ref, string $action): array
    {
        // «خاموش» را نرم (ACPI shutdown) می‌فرستیم نه هارد؛ مثلِ هتزنر، تا
        // دادهٔ دیسک خراب نشود.
        $path = match ($action) {
            'on'              => 'start',
            'off', 'shutdown' => 'shutdown',
            'reboot'          => 'reboot',
            'reset'           => 'reset',
            default           => null,
        };

        if ($path === null) {
            return ['ok' => false, 'message' => 'عملیاتِ ناشناخته.'];
        }

        $r = $this->req('POST', '/nodes/'.rawurlencode($this->node()).'/qemu/'.rawurlencode($ref).'/status/'.$path);

        return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
    }

    public function deleteServer(string $ref): array
    {
        try {
            $r = $this->req('DELETE', '/nodes/'.rawurlencode($this->node()).'/qemu/'.rawurlencode($ref), [
                'purge'                      => 1,
                'destroy-unreferenced-disks' => 1,
            ]);

            // ۴۰۴ (یا پیامِ «does not exist») یعنی از قبل نیست — برای خاتمه «موفق»
            $lower = mb_strtolower($r['message']);

            if (! $r['ok'] && ($r['status'] === 404 || str_contains($lower, 'does not exist') || str_contains($lower, 'no such'))) {
                return ['ok' => true, 'message' => 'سرور از قبل حذف شده بود.'];
            }

            return ['ok' => $r['ok'], 'message' => $r['ok'] ? '' : $r['message']];
        } catch (\Throwable $e) {
            Log::warning('proxmox.delete', ['ref' => $ref, 'err' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'حذفِ سرور انجام نشد.'];
        }
    }

    public function resetPassword(string $ref): array
    {
        try {
            $password = $this->randomPassword();

            // رمزِ جدیدِ cloud-init؛ در بوتِ بعدی اعمال می‌شود.
            $r = $this->req('PUT', '/nodes/'.rawurlencode($this->node()).'/qemu/'.rawurlencode($ref).'/config', [
                'ciuser'     => 'root',
                'cipassword' => $password,
            ]);

            return [
                'ok' => $r['ok'],
                'message' => $r['ok'] ? '' : $r['message'],
                'root_password' => $r['ok'] ? $password : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('proxmox.resetpw', ['ref' => $ref, 'err' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'تغییرِ رمز انجام نشد.', 'root_password' => null];
        }
    }

    /**
     * نصبِ دوباره = خاموش‌کردن، حذف، و clone‌ِ تازه با **همان شناسه/IP** از
     * قالبِ خواسته‌شده. داده پاک می‌شود (لایهٔ بالاتر تأیید گرفته است).
     */
    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        try {
            $node = $this->node();

            // مشخصاتِ فعلی را نگه دار تا سرورِ تازه همان IP/نام/برچسب را بگیرد
            $cfg = $this->req('GET', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($ref).'/config');

            if (! $cfg['ok']) {
                return ['ok' => false, 'message' => 'وضعیتِ سرور خوانده نشد.', 'root_password' => null];
            }

            $name = (string) data_get($cfg['body'], 'data.name', 'sn-'.$ref);
            $ipconfig0 = (string) data_get($cfg['body'], 'data.ipconfig0', '');
            $description = (string) data_get($cfg['body'], 'data.description', '');

            // خاموش کن و تا واقعاً خاموش‌شدن صبر کن (حذفِ ماشینِ روشن رد می‌شود)
            $stop = $this->req('POST', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($ref).'/status/stop');
            $this->waitForTask($node, (string) ($stop['body']['data'] ?? ''));

            $del = $this->req('DELETE', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($ref), ['purge' => 1]);

            if (! $del['ok'] && $del['status'] !== 404) {
                return ['ok' => false, 'message' => 'نصبِ دوباره انجام نشد.', 'root_password' => null];
            }

            $tpl = ctype_digit($imageRef) ? $imageRef : (string) $this->templateVmid();
            $password = $password ?: $this->randomPassword();

            $clone = $this->req('POST', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($tpl).'/clone', [
                'newid'   => (int) $ref,
                'name'    => $name,
                'full'    => 1,
                'storage' => $this->storage(),
            ]);

            if (! $clone['ok']) {
                return ['ok' => false, 'message' => 'نصبِ دوباره انجام نشد.', 'root_password' => null];
            }

            $this->waitForTask($node, (string) ($clone['body']['data'] ?? ''));

            $config = ['ciuser' => 'root', 'cipassword' => $password];

            if ($ipconfig0 !== '') {
                $config['ipconfig0'] = $ipconfig0;
            }
            if ($description !== '') {
                $config['description'] = $description;
            }

            $this->req('PUT', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($ref).'/config', $config);
            $this->req('POST', '/nodes/'.rawurlencode($node).'/qemu/'.rawurlencode($ref).'/status/start');

            return ['ok' => true, 'message' => '', 'root_password' => $password];
        } catch (\Throwable $e) {
            Log::warning('proxmox.rebuild', ['ref' => $ref, 'err' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'نصبِ دوباره با خطای غیرمنتظره روبه‌رو شد.', 'root_password' => null];
        }
    }

    // ───────────────────────── استاب‌ها ─────────────────────────
    // این قابلیت‌ها را روی این زیرساخت (فعلاً) نداریم؛ مثلِ آیزا صریح ok:false
    // می‌دهند و در capabilities() هم false‌اند تا رابطِ کاربری دکمهٔ بی‌فایده نسازد.

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

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این سرور در دسترس نیست.', 'ips' => []];
    }

    /**
     * کلیدِ SSH از راهِ cloud-init در createServer تزریق می‌شود، نه از یک
     * «حسابِ ما نزدِ زیرساخت». پس این‌جا no-op است و ok می‌دهد تا پایپ‌لاین
     * نشکند.
     */
    public function uploadSshKey(string $name, string $publicKey): array
    {
        return ['ok' => true, 'message' => '', 'ref' => null];
    }

    // ───────────────────────── ابزار ─────────────────────────

    /** رمزِ قوی — بدونِ نویسه‌های شبیه‌به‌هم تا مشتری اشتباه تایپ نکند */
    private function randomPassword(int $len = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#%+=';
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
