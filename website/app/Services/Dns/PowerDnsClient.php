<?php

namespace App\Services\Dns;

use App\Models\CdnNode;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ PowerDNS Authoritative — لایهٔ DNSِ مدیریت‌شدهٔ مشتریان.
 *
 * ═══ چرا جدا از CloudflareDns و DomainZoneProvisioner ═══
 *
 * `CloudflareDns` رکوردِ زیردامنهٔ رایگانِ **خودمان** را روی zoneِ خودمان
 * می‌نشاند؛ `DomainZoneProvisioner` zoneِ هاست را روی BINDِ سرورِ cPanel
 * می‌سازد. این کلاس zoneهای **مشتریان** را روی نیم‌سرورهای DNSِ مدیریت‌شده
 * نگه می‌دارد. سه مسئولیت روی سه سرور؛ یکی‌کردنشان یعنی خرابیِ یکی، بقیه را
 * هم می‌خواباند.
 *
 * ═══ جهتِ حقیقت ═══
 *
 * منبعِ حقیقت جدول‌های `cdn_zones` / `cdn_records` است و PowerDNS **آینه**.
 * `syncZone()` آینه را دقیقاً برابرِ دیتابیس می‌کند (افزودن، تغییر **و حذف**)،
 * پس بدترین حالتِ خرابیِ سرورِ DNS «دوباره پرش کن» است، نه «دادهٔ مشتری رفت».
 *
 * ═══ قراردادِ خطا ═══
 *
 * هیچ متدی استثنا نمی‌دهد؛ نتیجه همیشه `['ok'=>bool,'reason'=>?string,...]`.
 * فراخوان‌ها از کرون و مسیرِ درخواستِ کاربر می‌آیند و استثنای رهاشده آن‌جا
 * کلِ دقیقهٔ کرون را می‌کشد.
 *
 * ⚠️ برخلافِ Cloudflare و OpenProvider، PowerDNS کدِ HTTP را **درست** می‌دهد
 * (۴۲۲ بدنهٔ نامعتبر، ۴۰۴ zoneِ نبود) و متنِ خطا در فیلدِ `error` است.
 */
class PowerDnsClient
{
    /** @var array<string,array<int,string>>  IPهای نودهای لبه به ازای ستون — یک بار در هر نمونه */
    private array $edgeIps = [];

    public function isConfigured(): bool
    {
        return filled(Setting::getSecret('pdns_api_key')) && filled(self::apiUrl());
    }

    /** آدرسِ API: تنظیمِ مدیر، وگرنه config */
    public static function apiUrl(): string
    {
        return rtrim((string) (Setting::get('pdns_api_url') ?: config('edge.pdns.url', '')), '/');
    }

    /* ───────────────────────── همگام‌سازی ───────────────────────── */

    /**
     * zone را می‌سازد اگر نباشد، و آن را **دقیقاً** با دیتابیس برابر می‌کند.
     *
     * 🔴 «برابر» یعنی هم REPLACE برای هر (نام، نوع)ِ دیتابیس، هم **DELETE**
     * برای هر rrsetی که روی PowerDNS هست و در دیتابیس نیست. نسخهٔ اولِ این
     * کلاس فقط REPLACE می‌فرستاد: رکوردی که مشتری حذف می‌کرد از پنل می‌رفت ولی
     * روی سرورِ DNS **برای همیشه** جواب می‌داد — پنل درست را نشان می‌داد و
     * فقط `dig` حقیقت را می‌گفت.
     *
     * دو استثنا که هرگز از دیتابیس نمی‌آیند: SOA (مالِ PowerDNS) و NSِ apex
     * (مالِ ما — همیشه از `nameservers()` بازنویسی می‌شود).
     *
     * @return array{ok:bool, reason:?string, created:bool, changes:int}
     */
    public function syncZone(CdnZone $zone): array
    {
        if (! $this->isConfigured()) {
            return $this->syncFail('سرورِ DNS هنوز در تنظیماتِ مدیریت پیکربندی نشده است.');
        }

        $created = false;
        $current = $this->request('get', '/zones/'.$this->canonical($zone->domain));

        if (! $current['ok'] && $current['status'] !== 404) {
            // «نمی‌دانم» هرگز «نیست» خوانده نمی‌شود — وگرنه zoneِ زنده را از نو می‌سازیم
            return $this->syncFail($current['reason'] ?? 'وضعیتِ zone روی سرورِ DNS خوانده نشد.');
        }

        if (! $current['ok']) {
            $make = $this->createZone($zone);
            if (! $make['ok']) {
                return $this->syncFail($make['reason']);
            }
            $created = true;
            $current = ['data' => ['rrsets' => (array) ($make['data']['rrsets'] ?? [])]];
        }

        $patch = $this->diff($zone, (array) ($current['data']['rrsets'] ?? []));

        if ($patch === []) {
            return ['ok' => true, 'reason' => null, 'created' => $created, 'changes' => 0];
        }

        $res = $this->request('patch', '/zones/'.$this->canonical($zone->domain), ['rrsets' => $patch]);

        return ['ok' => $res['ok'], 'reason' => $res['reason'], 'created' => $created, 'changes' => $res['ok'] ? count($patch) : 0];
    }

    /**
     * rrsetهای لازم برای رسیدن از «آنچه روی سرور است» به «آنچه باید باشد».
     *
     * فقط تفاوت فرستاده می‌شود: PATCHِ کامل روی هر ذخیره یعنی افزایشِ serial و
     * NOTIFYِ بی‌دلیل به نیم‌سرورِ دوم برای zoneی که عوض نشده.
     *
     * @param  array<int,array<string,mixed>>  $live
     * @return array<int,array<string,mixed>>
     */
    public function diff(CdnZone $zone, array $live): array
    {
        $want = [];
        foreach ($this->buildRrsets($zone) as $set) {
            $want[$set['name'].'|'.$set['type']] = $set;
        }

        $have = [];
        foreach ($live as $set) {
            $have[mb_strtolower((string) ($set['name'] ?? '')).'|'.($set['type'] ?? '')] = $set;
        }

        $patch = [];

        foreach ($want as $key => $set) {
            if (isset($have[$key]) && $this->equal($have[$key], $set)) {
                continue;
            }
            $patch[] = $set + ['changetype' => 'REPLACE'];
        }

        foreach ($have as $key => $set) {
            if (isset($want[$key]) || ($set['type'] ?? '') === 'SOA') {
                continue;
            }

            // چالشِ فعالِ ACME مالِ `putChallenge` است و از دیتابیس نمی‌آید
            if (str_starts_with($key, '_acme-challenge.')) {
                continue;
            }

            $patch[] = ['name' => $set['name'], 'type' => $set['type'], 'changetype' => 'DELETE'];
        }

        return $patch;
    }

    /**
     * رکوردهای دیتابیس به rrsetهای PowerDNS (بی‌changetype).
     *
     * ⚠️ PowerDNS رکوردها را **گروهی به ازای (نام، نوع)** می‌گیرد. فرستادنِ
     * سه A همنام در سه rrset یعنی هرکدام قبلی را پاک می‌کند و فقط آخری
     * می‌مانَد — خاموش، و فقط با `dig` دیدنی.
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildRrsets(CdnZone $zone): array
    {
        $apex = $this->canonical($zone->domain);

        // 🔴 NSِ apex همیشه از فهرستِ ما — هرچه در دیتابیس باشد نادیده گرفته می‌شود
        $groups = [$apex.'|NS' => [
            'name'    => $apex,
            'type'    => 'NS',
            'ttl'     => 86400,
            'records' => array_map(
                fn ($ns) => ['content' => $this->canonical($ns), 'disabled' => false],
                $zone->nameservers()
            ),
        ]];

        foreach ($zone->records()->where('disabled', false)->orderBy('id')->get() as $rec) {
            $name = $this->canonical($rec->name);

            if ($name === $apex && $rec->type === 'NS') {
                continue;
            }

            foreach ($this->answers($rec) as [$type, $content]) {
                $key = $name.'|'.$type;
                $groups[$key] ??= ['name' => $name, 'type' => $type, 'ttl' => (int) $rec->ttl, 'records' => []];

                $formatted = $this->formatContent($type, $content, $rec->prio);

                // تکرار در یک rrset را PowerDNS با ۴۲۲ رد می‌کند
                if (! in_array($formatted, array_column($groups[$key]['records'], 'content'), true)) {
                    $groups[$key]['records'][] = ['content' => $formatted, 'disabled' => false];
                }
            }
        }

        return array_values($groups);
    }

    /**
     * پاسخ(های) واقعیِ یک رکورد: [[نوع، محتوا], ...].
     *
     * رکوردِ proxied به IPهای لبه پاسخ می‌دهد — IPv4 برای A، IPv6 برای AAAA.
     *
     * 🔴 اگر هیچ نودِ لبهٔ فعالی نیست، **مقدارِ اصلی** سرو می‌شود، نه هیچ.
     * نسخهٔ اول رکورد را جا می‌انداخت: خاموش‌کردنِ آخرین نودِ لبه یعنی
     * NXDOMAIN برای سایتِ همهٔ مشتریانِ CDN. مستقیم به مبدأ بدتر از کش است،
     * ولی بی‌نهایت بهتر از سایتِ مرده.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function answers(CdnRecord $rec): array
    {
        if (! $rec->proxied || ! in_array($rec->type, ['A', 'AAAA'], true)) {
            return [[$rec->type, $rec->content]];
        }

        $ips = $this->edgeIps($rec->type === 'A' ? 'public_ip' : 'public_ip6');

        return $ips === [] ? [[$rec->type, $rec->content]] : array_map(fn ($ip) => [$rec->type, $ip], $ips);
    }

    /** @return array<int,string> */
    private function edgeIps(string $column): array
    {
        /*
         * ⚠️ خاصیتِ نمونه، نه `static`: متغیرِ static تا پایانِ **پروسه** زنده
         * می‌مانَد و در ورکرِ صف نودی که ساعت‌ها پیش خاموش شده هنوز سرو می‌شود.
         */
        if (! isset($this->edgeIps[$column])) {
            try {
                $this->edgeIps[$column] = CdnNode::query()->edge()->serving()
                    ->whereNotNull($column)->pluck($column)->all();
            } catch (\Throwable) {
                $this->edgeIps[$column] = [];
            }
        }

        return $this->edgeIps[$column];
    }

    /** rrsetِ زنده با rrsetِ خواسته برابر است؟ — بی‌توجه به ترتیبِ رکوردها */
    private function equal(array $live, array $want): bool
    {
        if ((int) ($live['ttl'] ?? 0) !== (int) $want['ttl']) {
            return false;
        }

        $norm = fn (array $rows) => collect($rows)
            ->reject(fn ($r) => (bool) ($r['disabled'] ?? false))
            ->map(fn ($r) => mb_strtolower((string) ($r['content'] ?? '')))
            ->sort()->values()->all();

        return $norm((array) ($live['records'] ?? [])) === $norm($want['records']);
    }

    /** قالبِ محتوا به ازای هر نوع (قالبِ presentationِ PowerDNS) */
    private function formatContent(string $type, string $content, ?int $prio): string
    {
        $content = trim($content);

        return match ($type) {
            'TXT'         => $this->quoteTxt($content),
            'MX'          => ($prio ?? 10).' '.$this->canonical($content),
            'CNAME', 'NS' => $this->canonical($content),
            'SRV'         => $this->srv($content, $prio),
            default       => $content,
        };
    }

    /**
     * TXT به رشته‌های گیومه‌دارِ حداکثر ۲۵۵ بایتی.
     *
     * 🔴 هر رشتهٔ DNS حداکثر ۲۵۵ بایت است. کلیدِ DKIMِ ۲۰۴۸بیتی ~۴۱۰ نویسه است؛
     * فرستادنِ یکجا را PowerDNS رد می‌کند و **کلِ PATCH** — یعنی همهٔ تغییراتِ
     * دیگرِ همان zone — با آن می‌رود.
     *
     * تکه‌کردن روی متنِ خام است و escape بعد از آن، تا یک `\"` وسطِ مرز نصف نشود.
     */
    public function quoteTxt(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        return implode(' ', array_map(
            fn (string $part) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $part).'"',
            str_split($value, 255)
        ));
    }

    private function srv(string $content, ?int $prio): string
    {
        $parts = preg_split('~\s+~', trim($content)) ?: [];

        if (count($parts) === 3) {
            [$weight, $port, $target] = $parts;

            return ($prio ?? 0).' '.$weight.' '.$port.' '.($target === '.' ? '.' : $this->canonical($target));
        }

        return ($prio ?? 0).' '.$content;
    }

    /* ───────────────────────── چرخهٔ zone ───────────────────────── */

    /** @return array{ok:bool, reason:?string, data:array, status:int} */
    private function createZone(CdnZone $zone): array
    {
        $ns = array_map(fn ($n) => $this->canonical($n), $zone->nameservers());
        $soa = (array) config('edge.pdns.soa', []);

        /*
         * ⚠️ `Native` و نه `Master`: تکثیر بینِ دو نیم‌سرور با تکثیرِ خودِ
         * دیتابیس است (infra/dns/README.md)، نه AXFR. با `Master`، PowerDNS
         * برای هر تغییر NOTIFY می‌فرستد به نیم‌سرورِ دومی که اصلاً AXFR
         * نمی‌خواهد — و لاگِ خطایش واقعی را زیرِ خودش دفن می‌کند.
         */
        return $this->request('post', '/zones', [
            'name'         => $this->canonical($zone->domain),
            'kind'         => 'Native',
            'nameservers'  => $ns,
            'soa_edit_api' => 'DEFAULT',
            'rrsets'       => [[
                'name'       => $this->canonical($zone->domain),
                'type'       => 'SOA',
                'ttl'        => (int) ($soa['ttl'] ?? 3600),
                'changetype' => 'REPLACE',
                'records'    => [[
                    'content' => sprintf(
                        '%s %s 1 %d %d %d %d',
                        $ns[0] ?? 'localhost.',
                        $this->canonical($soa['email'] ?? 'hostmaster.servernet.cloud'),
                        (int) ($soa['refresh'] ?? 3600),
                        (int) ($soa['retry'] ?? 600),
                        (int) ($soa['expire'] ?? 604800),
                        (int) ($soa['ttl'] ?? 3600),
                    ),
                    'disabled' => false,
                ]],
            ]],
        ]);
    }

    /** @return bool|null  null یعنی «نپرسیدیم»، نه «نیست» */
    public function zoneExists(string $domain): ?bool
    {
        $res = $this->request('get', '/zones/'.$this->canonical($domain));

        if ($res['ok']) {
            return true;
        }

        // فقط ۴۰۴ یعنی واقعاً نیست — همان درسِ `accountState()` سه‌حالته در WHM
        return $res['status'] === 404 ? false : null;
    }

    /** @return array{ok:bool, reason:?string} */
    public function deleteZone(string $domain): array
    {
        $res = $this->request('delete', '/zones/'.$this->canonical($domain));

        // zoneِ ازقبل‌نبوده = موفق. همان قاعدهٔ `terminate()` در بقیهٔ درایورها.
        if (! $res['ok'] && $res['status'] === 404) {
            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => $res['ok'], 'reason' => $res['reason']];
    }

    /**
     * سلامتِ API — دکمهٔ «آزمایشِ اتصال» در پنلِ مدیریت.
     *
     * @return array{ok:bool, reason:?string, version:?string}
     */
    public function health(): array
    {
        $res = $this->request('get', '');

        return [
            'ok'      => $res['ok'],
            'reason'  => $res['reason'],
            'version' => $res['ok'] ? (string) ($res['data']['version'] ?? '') : null,
        ];
    }

    /**
     * نامِ همهٔ zoneهای روی سرور — برای یافتنِ «یتیم»ها.
     *
     * @return array<int,string>|null  null یعنی نپرسیدیم
     */
    public function zoneNames(): ?array
    {
        $res = $this->request('get', '/zones');

        if (! $res['ok']) {
            return null;
        }

        return array_values(array_map(fn ($z) => rtrim((string) ($z['name'] ?? ''), '.'), $res['data']));
    }

    /**
     * رکوردِ TXT چالشِ ACME. مستقیم روی PowerDNS می‌نشیند و از `cdn_records`
     * رد نمی‌شود — موقتی است و نباید در پنلِ مشتری دیده شود. `diff()` هم
     * عمداً دست به `_acme-challenge.*` نمی‌زند.
     */
    public function putChallenge(string $zoneDomain, string $fqdn, string $value): array
    {
        return $this->request('patch', '/zones/'.$this->canonical($zoneDomain), [
            'rrsets' => [[
                'name'       => $this->canonical($fqdn),
                'type'       => 'TXT',
                'ttl'        => 60,
                'changetype' => 'REPLACE',
                'records'    => [['content' => $this->quoteTxt($value), 'disabled' => false]],
            ]],
        ]);
    }

    public function clearChallenge(string $zoneDomain, string $fqdn): array
    {
        return $this->request('patch', '/zones/'.$this->canonical($zoneDomain), [
            'rrsets' => [['name' => $this->canonical($fqdn), 'type' => 'TXT', 'changetype' => 'DELETE']],
        ]);
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    private function canonical(string $name): string
    {
        $n = mb_strtolower(rtrim(trim($name), '.'));

        return $n === '' ? '.' : $n.'.';
    }

    private function baseUrl(): string
    {
        return self::apiUrl().'/api/v1/servers/'.config('edge.pdns.server', 'localhost');
    }

    private function syncFail(?string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'created' => false, 'changes' => 0];
    }

    /** @return array{ok:bool, reason:?string, data:array, status:int} */
    private function request(string $method, string $path, array $body = []): array
    {
        $key = Setting::getSecret('pdns_api_key');

        if (blank($key)) {
            return ['ok' => false, 'reason' => 'کلیدِ API پاوردی‌ان‌اس وارد نشده است.', 'data' => [], 'status' => 0];
        }

        try {
            $req = Http::withHeaders(['X-API-Key' => $key])
                ->acceptJson()
                ->timeout(12)
                ->connectTimeout(5);

            $url = $this->baseUrl().$path;

            $resp = in_array($method, ['get', 'delete'], true)
                ? $req->{$method}($url)
                : $req->{$method}($url, $body);
        } catch (\Throwable $e) {
            return [
                'ok'     => false,
                'reason' => 'ارتباط با سرورِ DNS برقرار نشد: '.mb_substr($e->getMessage(), 0, 120),
                'data'   => [],
                'status' => 0,
            ];
        }

        $status = $resp->status();
        $json = $resp->json();

        if ($resp->successful()) {
            return ['ok' => true, 'reason' => null, 'data' => is_array($json) ? $json : [], 'status' => $status];
        }

        $reason = is_array($json) && filled($json['error'] ?? null)
            ? (string) $json['error']
            : 'خطای سرورِ DNS (HTTP '.$status.')';

        return ['ok' => false, 'reason' => mb_substr($reason, 0, 240), 'data' => [], 'status' => $status];
    }
}
