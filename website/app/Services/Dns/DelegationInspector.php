<?php

namespace App\Services\Dns;

use App\Models\CdnZone;
use App\Services\Dns\Wire\DnsFailure;
use App\Services\Dns\Wire\DnsMessage;
use App\Services\Dns\Wire\DnsResponse;
use App\Services\Dns\Wire\DnsWireClient;
use App\Services\NetworkTools;
use Illuminate\Support\Carbon;

/**
 * «والد واقعاً چه گفته؟» — پیمایشِ تکراری از ریشه با پرس‌وجوی مستقیمِ RD=0 (طراحی §5.3).
 *
 * ═══ چرا resolverِ عمومی نه ═══
 *
 * resolver پاسخِ **کش‌شده** می‌دهد (تا TTLِ NS، معمولاً ۱–۲ روز پس از تغییرِ
 * نیم‌سرور) و نمی‌گوید پاسخ از والد آمده یا از خودِ zone. تأییدِ مالکیت و
 * «تفویض به ما رسید؟» هر دو باید حرفِ **والد** را همین الان بشنوند. پس از
 * ریشه پایین می‌رویم و فقط ارجاع/پاسخِ AA را می‌پذیریم.
 *
 * ═══ ورودیِ خصمانه ═══
 *
 *  • ارجاع فقط **رو به پایین** (فرزندِ اکیدِ zoneِ فعلی که نامِ پرسیده را در بر
 *    دارد) — ارجاعِ بالارو/کناری حلقه یا مسموم‌سازی است.
 *  • glue فقط داخلِ bailiwickِ سرورِ ارجاع‌دهنده؛ glueِ بیرونی بردارِ مسمومیت است.
 *  • پاسخِ بی‌AA هرگز «جواب» نیست — ریشه‌ای که RA=1 و جواب می‌دهد یعنی شبکه
 *    DNS را رهگیری می‌کند (`intercepted`)، نه اینکه والد چیزی گفته.
 *  • IPِ خصوصی/رزروشده از glue/resolve پذیرفته نمی‌شود: وگرنه دامنهٔ هر کسی
 *    می‌تواند سرورِ ما را وادار کند به شبکهٔ داخلی (مثلاً 127.0.0.1:53 = PowerDNSِ
 *    cPanel) پرس‌وجو بفرستد.
 *  • همه‌چیز سقف دارد: تعدادِ ارجاع، تلاش در هر zone، عمقِ resolveِ NSِ بی‌glue،
 *    کلِ پرس‌وجوها و کلِ زمان. هیچ متدی استثنا بیرون نمی‌دهد (جز checkDelegation
 *    برای خطای دیتابیس).
 */
class DelegationInspector
{
    /** a–m.root-servers.net، IPv4 از root hintsِ IANA (b از ۲۰۲۳-۱۱ نشانیِ تازه دارد) */
    public const ROOT_HINTS = [
        ['host' => 'a.root-servers.net', 'ip' => '198.41.0.4'],
        ['host' => 'b.root-servers.net', 'ip' => '170.247.170.2'],
        ['host' => 'c.root-servers.net', 'ip' => '192.33.4.12'],
        ['host' => 'd.root-servers.net', 'ip' => '199.7.91.13'],
        ['host' => 'e.root-servers.net', 'ip' => '192.203.230.10'],
        ['host' => 'f.root-servers.net', 'ip' => '192.5.5.241'],
        ['host' => 'g.root-servers.net', 'ip' => '192.112.36.4'],
        ['host' => 'h.root-servers.net', 'ip' => '198.97.190.53'],
        ['host' => 'i.root-servers.net', 'ip' => '192.36.148.17'],
        ['host' => 'j.root-servers.net', 'ip' => '192.58.128.30'],
        ['host' => 'k.root-servers.net', 'ip' => '193.0.14.129'],
        ['host' => 'l.root-servers.net', 'ip' => '199.7.83.42'],
        ['host' => 'm.root-servers.net', 'ip' => '202.12.27.33'],
    ];

    /** هیچ نامِ واقعی به ۱۰ ارجاع هم نمی‌رسد؛ بیشتر یعنی زنجیرهٔ ساختگی */
    private const MAX_REFERRALS = 16;

    /** سرورهای یک zone که پیش از «نشد» امتحان می‌شوند */
    private const MAX_TRIES_PER_ZONE = 6;

    /** عمقِ تو در توییِ resolveِ NSِ بی‌glue (NSی که NSش هم بی‌glue است …) */
    private const MAX_LEVEL = 3;

    private const MAX_NS = 13;

    private const MAX_IPS_PER_HOST = 2;

    private const MAX_CNAME = 4;

    /** سرورهای پرس‌وجوی مستقیمِ TXT/اسکن (پس از حذفِ IPِ تکراری) */
    private const MAX_AUTH_SERVERS = 8;

    /** کشِ درون‌نمونه: ارجاع‌های TLD و نشانیِ NSها بینِ دامنه‌های یک کرون */
    private const CACHE_TTL = 300.0;

    /** کشِ تفویضِ خودِ دامنه فقط برای اسکن (ده‌ها پرس‌وجو پشتِ سرِ هم) */
    private const DELEGATION_TTL = 120.0;

    private readonly DnsWireClient $wire;

    /** @var list<array{host:string,ip:string,port:int}> */
    private readonly array $roots;

    private readonly bool $shuffleRoots;

    private readonly \Closure $fallback;

    /** @var array<string,array{at:float,servers:list<array{host:string,ip:?string,port:int}>}> */
    private array $zoneCache = [];

    /** @var array<string,array{at:float,ips:list<string>}> */
    private array $addrCache = [];

    /** @var array<string,array{at:float,result:array}> */
    private array $delegationCache = [];

    /** @var array<string,true> نام‌هایی که همین الان در حالِ resolve‌اند (گاردِ چرخه) */
    private array $resolving = [];

    private int $sessionDepth = 0;

    private int $queries = 0;

    private float $deadline = 0.0;

    /**
     * @param  list<array{host:string,ip:string,port?:int}>|null  $rootHints  برای تست: سلسله‌مراتبِ جعلی
     * @param  \Closure(string):list<string>|null  $fallbackResolver  آخرین چاره برای **نشانیِ** NS
     * @param  bool  $allowPrivate  فقط تست/آزمایشگاه: IPِ خصوصی در glue پذیرفته شود
     */
    public function __construct(
        ?DnsWireClient $wire = null,
        ?array $rootHints = null,
        ?\Closure $fallbackResolver = null,
        private readonly float $queryTimeout = 2.0,
        private readonly float $budget = 20.0,
        private readonly int $maxQueries = 80,
        private readonly bool $allowPrivate = false,
    ) {
        $this->wire = $wire ?? new DnsWireClient;
        $this->roots = self::normalizeHints($rootHints ?? self::ROOT_HINTS);
        // ترتیبِ ثابت فقط برای hintِ تزریقی (تستِ قطعی)؛ در تولید بار پخش می‌شود
        $this->shuffleRoots = $rootHints === null;
        $this->fallback = $fallbackResolver ?? fn (string $host): array => $this->publicAddress($host);
    }

    /* ═══════════════════════════ API ═══════════════════════════ */

    /**
     * چند پرس‌وجو زیرِ **یک** بودجهٔ زمان و تعداد (پیش‌فرض ۲۰ ثانیه / ۸۰ پرس‌وجو).
     * بی‌آن هر `queryAuthoritative` بودجهٔ تازه می‌گرفت و درخواستِ وب با نیم‌سرورهای
     * کُند چند دقیقه طول می‌کشید. پس از تمام‌شدنِ بودجه، پاسخ‌ها `budget_exhausted` هستند.
     */
    public function batch(\Closure $fn): mixed
    {
        return $this->session($fn);
    }

    /**
     * NSِ دامنه از دیدِ **والد**، DSِ والد، و نشانیِ هر NS.
     *
     * `ok=true` یعنی پاسخِ قطعی گرفتیم؛ `ns=[]` با ok=true یعنی والد می‌گوید
     * تفویضی نیست (`status`: nxdomain|no_delegation). `ok=false` یعنی «نتوانستیم
     * بپرسیم» و هرگز نباید «رد شد» خوانده شود.
     *
     * 🔴 اگر NS معلوم شد ولی DS خوانده نشد، ok=false است (`ds_lookup_failed`) —
     * چون «DS ندارد»ِ اشتباه یعنی چک‌لیست جلوی تعویضِ NSِ دامنهٔ امضاشده را
     * نمی‌گیرد و دامنه برای resolverهای اعتبارسنج می‌میرد. ns/servers همچنان پر است.
     *
     * @return array{ok:bool, ns:list<string>, ds:list<array>, servers:list<array{host:string,ip:string}>, error:?string, parent:?string, status:string}
     */
    public function parentDelegation(string $domain): array
    {
        $d = DomainName::normalize($domain);
        if ($d === null) {
            return self::delegationFailure('invalid_domain');
        }

        try {
            return $this->session(fn () => $this->inspect($d));
        } catch (\Throwable) {
            return self::delegationFailure('internal');
        }
    }

    /**
     * مقدارهای TXTِ یک نام، مستقیم از نیم‌سرورهای فعلیِ دامنه (طبقِ والد)، فقط پاسخِ AA.
     *
     * هر مقدار = رشته‌های یک RR پشتِ هم (همان معنای SPF/تأییدِ دامنه). `answered`
     * = تعدادِ سرورهایی که پاسخِ معتبر (AA، NOERROR/NXDOMAIN) دادند.
     *
     * @param  array|null  $delegation  نتیجهٔ از پیش گرفته‌شدهٔ parentDelegation (ردیفِ server می‌تواند port داشته باشد)
     * @return array{ok:bool, values:list<string>, answered:int, error:?string}
     */
    public function authoritativeTxt(string $domain, string $name, ?array $delegation = null): array
    {
        $fail = fn (string $e): array => ['ok' => false, 'values' => [], 'answered' => 0, 'error' => $e];

        $d = DomainName::normalize($domain);
        if ($d === null) {
            return $fail('invalid_domain');
        }
        $fqdn = self::qualify($name, $d);
        if ($fqdn === null) {
            return $fail('invalid_name');
        }

        try {
            return $this->session(function () use ($d, $fqdn, $delegation, $fail): array {
                $del = $delegation ?? $this->inspect($d);

                if (($del['servers'] ?? []) === []) {
                    if (($del['ok'] ?? false) && ($del['ns'] ?? []) === []) {
                        // والد قطعاً گفت تفویضی نیست ⇒ TXT هم قطعاً نیست (نه «نتوانستیم»)
                        return ['ok' => true, 'values' => [], 'answered' => 0, 'error' => 'not_delegated'];
                    }

                    return $fail(($del['ok'] ?? false) ? 'no_servers' : (string) ($del['error'] ?? 'lookup_failed'));
                }

                $values = [];
                $answered = 0;
                $error = null;

                foreach (self::uniqueServers($del['servers']) as $srv) {
                    if ($this->exhausted()) {
                        $error ??= 'budget_exhausted';
                        break;
                    }
                    $r = $this->ask($srv, $fqdn, 'TXT');
                    if ($r instanceof DnsFailure) {
                        $error = $r->code;

                        continue;
                    }
                    // پاسخِ بی‌AA ممکن است از کش/فورواردر باشد — مالکیت با آن ثابت نمی‌شود
                    if (! $r->aa) {
                        $error = 'no_aa';

                        continue;
                    }
                    if ($r->rcode === 3) {
                        $answered++;

                        continue;
                    }
                    if ($r->rcode !== 0) {
                        $error = 'rcode_'.strtolower($r->rcodeName());

                        continue;
                    }
                    $answered++;
                    foreach ($r->records('answer', 'TXT', $fqdn) as $rr) {
                        $v = implode('', array_map('strval', (array) $rr['data']));
                        if (! in_array($v, $values, true)) {
                            $values[] = $v;
                        }
                    }
                }

                return $answered > 0
                    ? ['ok' => true, 'values' => $values, 'answered' => $answered, 'error' => null]
                    : $fail($error ?? 'no_answer');
            });
        } catch (\Throwable) {
            return $fail('internal');
        }
    }

    /**
     * SOAِ یک zone روی یک سرورِ مشخص — خام، بی‌تفسیرِ هم‌گامی (برای NodeVerifier §4.3).
     *
     * state: answer | refused | notauth | nxdomain | no_aa | timeout | error
     * `notauth` شاملِ پاسخِ AA بی SOAِ همین نام هم هست (سرور zoneِ بالادست را
     * سرو می‌کند، نه این zone را).
     *
     * @return array{state:string, serial:?int, aa:bool, rcode:?string, transport:?string, error:?string}
     */
    public function soaAt(string $ip, int $port, string $zone, bool $tcpOnly = false, ?float $timeout = null): array
    {
        try {
            $z = DnsMessage::normalizeName($zone);
            $r = $this->wire->query($ip, $port, $z, 'SOA', false, $timeout ?? $this->queryTimeout, $tcpOnly);
        } catch (\Throwable) {
            $z = '';
            $r = new DnsFailure(DnsFailure::NETWORK, 'internal');
        }

        if ($r instanceof DnsFailure) {
            return [
                'state' => $r->code === DnsFailure::TIMEOUT ? 'timeout' : 'error',
                'serial' => null, 'aa' => false, 'rcode' => null, 'transport' => null, 'error' => $r->code,
            ];
        }

        $base = ['serial' => null, 'aa' => $r->aa, 'rcode' => $r->rcodeName(), 'transport' => $r->transport, 'error' => null];

        $state = match (true) {
            $r->rcode === 5 => 'refused',
            $r->rcode === 9 => 'notauth',
            $r->rcode === 3 => 'nxdomain',
            $r->rcode !== 0 => 'error',
            ! $r->aa        => 'no_aa',
            default         => null,
        };
        if ($state !== null) {
            return ['state' => $state, 'error' => $state === 'error' ? 'rcode_'.strtolower($r->rcodeName()) : null] + $base;
        }

        $soa = $r->records('answer', 'SOA', $z);
        if ($soa === [] || ! is_array($soa[0]['data'] ?? null)) {
            return ['state' => 'notauth'] + $base;
        }

        return ['state' => 'answer', 'serial' => (int) $soa[0]['data']['serial']] + $base;
    }

    /**
     * یک پرس‌وجو از نیم‌سرورهای فعلیِ دامنه برای اسکنِ واردکردن (§7).
     *
     * اولین پاسخِ AA (NOERROR یا NXDOMAIN) برنده است. `records` = رکوردهای
     * بخشِ answer با مالکِ همین نام (CNAME هم، تا اسکن ببیند www نام مستعار است).
     *
     * @return array{ok:bool, rcode:?string, records:list<array>, server:?string, error:?string}
     */
    public function queryAuthoritative(string $domain, string $name, string $type, ?array $delegation = null): array
    {
        $fail = fn (string $e): array => ['ok' => false, 'rcode' => null, 'records' => [], 'server' => null, 'error' => $e];

        $d = DomainName::normalize($domain);
        if ($d === null) {
            return $fail('invalid_domain');
        }
        $fqdn = self::qualify($name, $d);
        if ($fqdn === null) {
            return $fail('invalid_name');
        }
        $type = strtoupper(trim($type));
        $code = DnsMessage::typeCode($type);
        if ($code === null || $type === 'OPT' || $type === 'ANY') {
            return $fail('unsupported_type');
        }
        $type = DnsMessage::typeNameOf($code);

        try {
            return $this->session(function () use ($d, $fqdn, $type, $delegation, $fail): array {
                $del = $delegation ?? $this->cachedDelegation($d);
                if (($del['servers'] ?? []) === []) {
                    return $fail(($del['ok'] ?? false) ? 'not_delegated' : (string) ($del['error'] ?? 'lookup_failed'));
                }

                $error = 'no_answer';
                foreach (self::uniqueServers($del['servers']) as $srv) {
                    if ($this->exhausted()) {
                        return $fail('budget_exhausted');
                    }
                    $r = $this->ask($srv, $fqdn, $type);
                    if ($r instanceof DnsFailure) {
                        $error = $r->code;

                        continue;
                    }
                    if (! $r->aa) {
                        $error = 'no_aa';

                        continue;
                    }
                    if ($r->rcode !== 0 && $r->rcode !== 3) {
                        $error = 'rcode_'.strtolower($r->rcodeName());

                        continue;
                    }

                    return [
                        'ok'      => true,
                        'rcode'   => $r->rcodeName(),
                        'records' => $r->records('answer', null, $fqdn),
                        'server'  => $srv['host'],
                        'error'   => null,
                    ];
                }

                return $fail($error);
            });
        } catch (\Throwable) {
            return $fail('internal');
        }
    }

    /**
     * تفویضِ zone را با نیم‌سرورهای ما مقایسه و در ستون‌های §3.2 ثبت می‌کند.
     *
     * 🔴 `lookup_failed` فقط وضعیت و زمان را می‌نویسد؛ NS/DSِ آخرین بارِ موفق
     * می‌ماند و هیچ zoneی به خاطرش غیرفعال/حذف نمی‌شود — «نتوانستیم بپرسیم»
     * دربارهٔ دامنهٔ مشتری هیچ نمی‌گوید.
     *
     * فعال‌سازی (pending→active) این‌جا نیست: به انتشار و هم‌گامیِ نودها هم
     * بسته است و جای دیگری تصمیم گرفته می‌شود.
     *
     * @return array{state:string, ns:list<string>, ds:list<array>, error:?string}
     */
    public function checkDelegation(CdnZone $zone): array
    {
        $d = $this->parentDelegation((string) $zone->domain);
        $now = Carbon::now();

        if (! $d['ok']) {
            $state = 'lookup_failed';
            $attrs = ['delegation_state' => $state, 'delegation_checked_at' => $now];
        } else {
            $state = self::classify($d['ns'], DnsConfig::nameserverHosts());
            $attrs = [
                'delegation_state'      => $state,
                'delegation_checked_at' => $now,
                'delegation_ns'         => $d['ns'],
                'parent_ds'             => $d['ds'],
            ];
            // verified_at در v2 = نخستین باری که تفویض به ما دیده شد (§3.2)
            if ($state === 'delegated' && $zone->verified_at === null) {
                $attrs['verified_at'] = $now;
            }
        }

        $zone->forceFill($attrs)->save();

        return ['state' => $state, 'ns' => $d['ns'], 'ds' => $d['ds'], 'error' => $d['error']];
    }

    /**
     * NSِ والد در برابرِ NSِ ما: delegated (برابر) | partial (اشتراک، نابرابر) | not_delegated.
     *
     * @param  array<int,string>  $parentNs
     * @param  array<int,string>  $ours
     */
    public static function classify(array $parentNs, array $ours): string
    {
        $norm = static function (array $list): array {
            $out = [];
            foreach ($list as $h) {
                $n = DnsMessage::normalizeName((string) $h);
                if ($n !== '.') {
                    $out[$n] = true;
                }
            }
            $out = array_keys($out);
            sort($out);

            return $out;
        };

        $p = $norm($parentNs);
        $o = $norm($ours);

        if ($p === [] || $o === [] || array_intersect($p, $o) === []) {
            return 'not_delegated';
        }

        return $p === $o ? 'delegated' : 'partial';
    }

    /* ═══════════════════════════ هستهٔ پیمایش ═══════════════════════════ */

    private function inspect(string $domain): array
    {
        $w = $this->walk($domain, 'NS', $domain, 0);

        switch ($w['kind']) {
            case 'fail':
                return self::delegationFailure((string) ($w['error'] ?? 'lookup_failed'));
            case 'nxdomain':
                return self::definite($w['zone'], 'nxdomain');
            case 'nodata':
            case 'cname':
                // نام داخلِ zoneِ والد هست ولی برش (zone cut) نیست
                return self::definite($w['zone'], 'no_delegation');
        }

        if ($w['kind'] === 'answer') {
            // سرورِ والد خودش zoneِ فرزند را هم سرو می‌کند و AA جواب داد
            $ns = [];
            foreach ($w['records'] as $rr) {
                $h = self::host((string) $rr['data']);
                if ($h !== null && ! in_array($h, $ns, true)) {
                    $ns[] = $h;
                }
            }
            $ns = array_slice($ns, 0, self::MAX_NS);
            $glue = $this->glue($w['response'], $ns, $domain);
            $status = 'answer';
        } else {
            $ns = $w['ns'];
            $glue = $w['glue'];
            $status = 'referral';
        }

        $ds = $this->fetchDs($domain, $w['from'], $w['servers']);
        $servers = $this->addressesOf($ns, $glue);

        return [
            'ok'      => $ds !== null,
            'ns'      => $ns,
            'ds'      => $ds ?? [],
            'servers' => $servers,
            'error'   => $ds === null ? 'ds_lookup_failed' : null,
            'parent'  => $w['zone'],
            'status'  => $status,
        ];
    }

    /**
     * از نزدیک‌ترین zoneِ شناخته‌شده پایین می‌رود تا پاسخِ نهایی.
     *
     * kind: cut (ارجاعی که دقیقاً `$cutAt` را نام برد) | answer | cname | nodata | nxdomain | fail
     */
    private function walk(string $qname, string $qtype, ?string $cutAt, int $level): array
    {
        [$zone, $servers] = $this->startingPoint($qname, $cutAt);
        $error = 'no_servers';

        for ($step = 0; $step <= self::MAX_REFERRALS; $step++) {
            $next = null;
            $from = null;
            $tries = 0;

            foreach ($this->expand($servers, $level) as $srv) {
                if ($this->exhausted()) {
                    return ['kind' => 'fail', 'error' => 'budget_exhausted', 'zone' => $zone];
                }
                if ($tries++ >= self::MAX_TRIES_PER_ZONE) {
                    break;
                }

                $res = $this->ask($srv, $qname, $qtype);
                if ($res instanceof DnsFailure) {
                    $error = self::worse($error, $res->code);

                    continue;
                }

                $v = $this->interpret($res, $qname, $qtype, $zone);
                if ($v['kind'] === 'lame') {
                    $error = self::worse($error, $v['error']);

                    continue;
                }
                if ($v['kind'] === 'referral') {
                    $next = $v;
                    $from = $srv;
                    break;
                }

                return $v + ['zone' => $zone, 'servers' => $servers, 'from' => $srv];
            }

            if ($next === null) {
                return ['kind' => 'fail', 'error' => $error, 'zone' => $zone];
            }

            if ($cutAt !== null && $next['child'] === $cutAt) {
                // DS را از همین سرورهای والد می‌پرسیم، پس servers = سرورهای zoneِ والد
                return [
                    'kind' => 'cut', 'ns' => $next['ns'], 'glue' => $next['glue'],
                    'zone' => $zone, 'servers' => $servers, 'from' => $from,
                ];
            }

            $servers = self::serversFrom($next['ns'], $next['glue']);
            $zone = $next['child'];
            $this->zoneCache[$zone] = ['at' => $this->now(), 'servers' => $servers];
        }

        return ['kind' => 'fail', 'error' => 'too_many_referrals', 'zone' => $zone];
    }

    /** یک پاسخ از دیدِ پیمایش: ارجاعِ رو به پایین، پاسخِ AA، یا «لنگ» (سرورِ بعدی) */
    private function interpret(DnsResponse $r, string $qname, string $qtype, string $zone): array
    {
        if ($r->rcode === 3) {
            return $r->aa ? ['kind' => 'nxdomain'] : ['kind' => 'lame', 'error' => 'nxdomain_without_aa'];
        }
        if ($r->rcode !== 0) {
            return ['kind' => 'lame', 'error' => 'rcode_'.strtolower($r->rcodeName())];
        }

        $own = $r->records('answer', null, $qname);
        if ($own !== []) {
            if (! $r->aa) {
                return ['kind' => 'lame', 'error' => $this->looksIntercepted($r, $zone) ? 'intercepted' : 'answer_without_aa'];
            }
            $match = array_values(array_filter($own, fn (array $rr) => $rr['type'] === $qtype));
            if ($match !== []) {
                return ['kind' => 'answer', 'records' => $match, 'response' => $r];
            }
            $cname = $r->records('answer', 'CNAME', $qname);
            if ($cname !== [] && $qtype !== 'CNAME') {
                return ['kind' => 'cname', 'target' => (string) $cname[0]['data']];
            }

            return ['kind' => 'lame', 'error' => 'unexpected_answer'];
        }

        $ref = $this->referral($r, $qname, $zone);
        if ($ref !== null) {
            return $ref;
        }

        if ($r->aa) {
            return ['kind' => 'nodata'];
        }

        return ['kind' => 'lame', 'error' => $this->looksIntercepted($r, $zone) ? 'intercepted' : 'no_referral'];
    }

    /**
     * ارجاعِ معتبر: NS با مالکِ Z که qname زیرِ آن و Z اکیداً زیرِ zoneِ فعلی است.
     */
    private function referral(DnsResponse $r, string $qname, string $zone): ?array
    {
        $best = null;
        foreach ($r->authority as $rr) {
            if ($rr['type'] !== 'NS') {
                continue;
            }
            $owner = (string) $rr['name'];
            if (! self::within($qname, $owner, true) || ! self::within($owner, $zone, false)) {
                continue;
            }
            if ($best === null || self::labels($owner) > self::labels($best)) {
                $best = $owner;
            }
        }
        if ($best === null) {
            return null;
        }

        $ns = [];
        foreach ($r->records('authority', 'NS', $best) as $rr) {
            $h = self::host((string) $rr['data']);
            if ($h !== null && ! in_array($h, $ns, true)) {
                $ns[] = $h;
            }
        }
        if ($ns === []) {
            return null;
        }
        $ns = array_slice($ns, 0, self::MAX_NS);

        return ['kind' => 'referral', 'child' => $best, 'ns' => $ns, 'glue' => $this->glue($r, $ns, $zone)];
    }

    /**
     * Aهای بخشِ additional برای هدف‌های NS، فقط داخلِ bailiwick.
     *
     * @param  list<string>  $ns
     * @return array<string,list<string>>
     */
    private function glue(DnsResponse $r, array $ns, string $bailiwick): array
    {
        $out = [];
        foreach ($r->additional as $rr) {
            if ($rr['type'] !== 'A') {
                continue;
            }
            $h = (string) $rr['name'];
            if (! in_array($h, $ns, true) || ! self::within($h, $bailiwick, true)) {
                continue;
            }
            $ip = (string) $rr['data'];
            if (! $this->usableIp($ip) || in_array($ip, $out[$h] ?? [], true)) {
                continue;
            }
            if (count($out[$h] ?? []) < self::MAX_IPS_PER_HOST) {
                $out[$h][] = $ip;
            }
        }

        return $out;
    }

    /**
     * DSِ دامنه از سرورهای والد (RD=0). null یعنی «نتوانستیم بخوانیم».
     *
     * ارجاعِ بی‌AA به خودِ دامنه یعنی سرورِ والد DNSSEC نمی‌شناسد ⇒ DSی هم ندارد.
     *
     * @return list<array>|null
     */
    private function fetchDs(string $domain, array $from, array $parentServers): ?array
    {
        $candidates = [$from];
        foreach ($this->expand($parentServers, 1) as $srv) {
            if ($srv['ip'] !== $from['ip']) {
                $candidates[] = $srv;
            }
            if (count($candidates) >= 3) {
                break;
            }
        }

        foreach ($candidates as $srv) {
            if ($this->exhausted()) {
                return null;
            }
            $r = $this->ask($srv, $domain, 'DS');
            if ($r instanceof DnsFailure) {
                continue;
            }
            if ($r->aa && $r->rcode === 0) {
                return array_values(array_map(
                    fn (array $rr) => (array) $rr['data'],
                    $r->records('answer', 'DS', $domain)
                ));
            }
            if ($r->aa && $r->rcode === 3) {
                return [];
            }
            if (! $r->aa && $r->rcode === 0 && $r->records('authority', 'NS', $domain) !== []) {
                return [];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $ns
     * @param  array<string,list<string>>  $glue
     * @return list<array{host:string,ip:string}>
     */
    private function addressesOf(array $ns, array $glue): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice($ns, 0, self::MAX_NS) as $h) {
            $ips = $glue[$h] ?? [];
            if ($ips === []) {
                $ips = $this->resolveAddress($h, 1);
            }
            foreach (array_slice($ips, 0, self::MAX_IPS_PER_HOST) as $ip) {
                if (! isset($seen[$h.'|'.$ip])) {
                    $seen[$h.'|'.$ip] = true;
                    $out[] = ['host' => $h, 'ip' => $ip];
                }
            }
        }

        return $out;
    }

    /**
     * نشانیِ IPv4ِ یک NSِ بی‌glue: اول همان پیمایشِ تکراری، آخر resolverِ عمومی.
     *
     * @return list<string>
     */
    private function resolveAddress(string $host, int $level): array
    {
        $cached = $this->addrCache[$host] ?? null;
        if ($cached !== null && $this->now() - $cached['at'] < self::CACHE_TTL) {
            return $cached['ips'];
        }
        if ($level > self::MAX_LEVEL || isset($this->resolving[$host]) || $this->exhausted()) {
            return [];
        }

        $this->resolving[$host] = true;
        try {
            $ips = [];
            $name = $host;
            for ($hop = 0; $hop <= self::MAX_CNAME; $hop++) {
                $w = $this->walk($name, 'A', null, $level);
                if ($w['kind'] === 'answer') {
                    foreach ($w['records'] as $rr) {
                        $ip = (string) $rr['data'];
                        if ($this->usableIp($ip)) {
                            $ips[] = $ip;
                        }
                    }
                    break;
                }
                if ($w['kind'] === 'cname' && ($t = self::host((string) $w['target'])) !== null && $t !== $name) {
                    $name = $t;

                    continue;
                }
                break;
            }

            if ($ips === [] && $this->now() < $this->deadline) {
                // آخرین چاره فقط برای «نشانی»؛ NS و TXT هرگز از resolverِ عمومی خوانده نمی‌شود
                try {
                    foreach ((array) ($this->fallback)($host) as $ip) {
                        if (is_string($ip) && $this->usableIp($ip)) {
                            $ips[] = $ip;
                        }
                    }
                } catch (\Throwable) {
                    // resolverِ عمومی هم نشد ⇒ همین NS بی‌نشانی می‌ماند
                }
            }

            $ips = array_slice(array_values(array_unique($ips)), 0, self::MAX_IPS_PER_HOST);
            if ($ips !== []) {
                // نتیجهٔ منفی کش نمی‌شود: ممکن است فقط بودجه تمام شده باشد
                $this->addrCache[$host] = ['at' => $this->now(), 'ips' => $ips];
            }

            return $ips;
        } finally {
            unset($this->resolving[$host]);
        }
    }

    /** پیش‌فرضِ آخرین چاره: 8.8.8.8 با RD=1، و اگر وقت ماند DoHِ گوگل */
    private function publicAddress(string $host): array
    {
        $left = $this->deadline - $this->now();
        if ($left <= 0.05) {
            return [];
        }

        $ips = [];
        $r = $this->wire->query('8.8.8.8', 53, $host, 'A', true, min($this->queryTimeout, $left));
        if ($r instanceof DnsResponse && $r->rcode === 0) {
            foreach ($r->records('answer', 'A') as $rr) {
                $ips[] = (string) $rr['data'];
            }
        }

        // curlِ DoH مهلتِ خودش (۸ ثانیه) را دارد؛ فقط وقتی بودجه جا دارد
        if ($ips === [] && $this->deadline - $this->now() >= 8.0) {
            $doh = (new NetworkTools)->dns($host, 'A');
            if (($doh['ok'] ?? false) === true) {
                foreach ((array) ($doh['records'] ?? []) as $rec) {
                    if (($rec['type'] ?? null) === 'A') {
                        $ips[] = (string) ($rec['data'] ?? '');
                    }
                }
            }
        }

        return $ips;
    }

    /**
     * سرورهای یک zone به ترتیب: glueدارها، سپس بی‌glueها (همین‌جا resolve می‌شوند).
     *
     * @param  list<array{host:string,ip:?string,port:int}>  $servers
     * @return \Generator<int,array{host:string,ip:string,port:int}>
     */
    private function expand(array $servers, int $level): \Generator
    {
        $seen = [];
        foreach ($servers as $s) {
            if ($s['ip'] !== null && ! isset($seen[$s['ip'].'#'.$s['port']])) {
                $seen[$s['ip'].'#'.$s['port']] = true;
                yield $s;
            }
        }
        foreach ($servers as $s) {
            if ($s['ip'] !== null) {
                continue;
            }
            foreach ($this->resolveAddress($s['host'], $level + 1) as $ip) {
                if (! isset($seen[$ip.'#'.$s['port']])) {
                    $seen[$ip.'#'.$s['port']] = true;
                    yield ['host' => $s['host'], 'ip' => $ip, 'port' => $s['port']];
                }
            }
        }
    }

    /** @return array{0:string,1:list<array{host:string,ip:?string,port:int}>} */
    private function startingPoint(string $qname, ?string $cutAt): array
    {
        $best = null;
        $bestLabels = -1;
        $now = $this->now();

        foreach ($this->zoneCache as $zone => $entry) {
            if ($now - $entry['at'] >= self::CACHE_TTL) {
                unset($this->zoneCache[$zone]);

                continue;
            }
            $zone = (string) $zone;
            if (! self::within($qname, $zone, true)) {
                continue;
            }
            // برای دیدِ والد باید از zoneی **بالاتر** از خودِ دامنه شروع کنیم
            if ($cutAt !== null && ! self::within($cutAt, $zone, false)) {
                continue;
            }
            if (self::labels($zone) > $bestLabels) {
                $best = $zone;
                $bestLabels = self::labels($zone);
            }
        }

        if ($best !== null) {
            return [$best, $this->zoneCache[$best]['servers']];
        }

        $roots = $this->roots;
        if ($this->shuffleRoots && count($roots) > 1) {
            $k = random_int(0, count($roots) - 1);
            $roots = array_merge(array_slice($roots, $k), array_slice($roots, 0, $k));
        }

        return ['.', $roots];
    }

    private function cachedDelegation(string $domain): array
    {
        $c = $this->delegationCache[$domain] ?? null;
        if ($c !== null && $this->now() - $c['at'] < self::DELEGATION_TTL) {
            return $c['result'];
        }

        $res = $this->inspect($domain);
        if ($res['servers'] !== []) {
            $this->delegationCache[$domain] = ['at' => $this->now(), 'result' => $res];
        }

        return $res;
    }

    /* ═══════════════════════════ بودجه و پرس‌وجو ═══════════════════════════ */

    /** بودجهٔ زمان/پرس‌وجو برای کلِ یک فراخوانیِ عمومی، نه هر مرحله */
    private function session(\Closure $fn): mixed
    {
        if ($this->sessionDepth === 0) {
            $this->queries = 0;
            $this->deadline = $this->now() + max(0.1, $this->budget);
            $this->resolving = [];
        }

        $this->sessionDepth++;
        try {
            return $fn();
        } finally {
            $this->sessionDepth--;
        }
    }

    private function exhausted(): bool
    {
        return $this->queries >= $this->maxQueries || $this->now() >= $this->deadline;
    }

    private function ask(array $srv, string $qname, string $qtype): DnsResponse|DnsFailure
    {
        $left = $this->deadline - $this->now();
        $this->queries++;

        return $this->wire->query(
            (string) $srv['ip'],
            (int) ($srv['port'] ?? 53),
            $qname,
            $qtype,
            false,
            max(0.05, min($this->queryTimeout, $left)),
        );
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /* ═══════════════════════════ کمکی ═══════════════════════════ */

    /** ریشه‌ای که RA=1 دارد سرورِ ریشه نیست — شبکه پرس‌وجو را به resolver برگردانده */
    private function looksIntercepted(DnsResponse $r, string $zone): bool
    {
        return $zone === '.' && $r->ra;
    }

    private function usableIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return $this->allowPrivate
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** خطای «گویاتر» می‌ماند: رهگیری مهم‌تر از timeoutِ سرورِ بعدی است */
    private static function worse(string $current, string $new): string
    {
        return $current === 'intercepted' ? $current : $new;
    }

    /** آیا name زیرِ zone است؟ ریشه ('.') همه را در بر دارد */
    private static function within(string $name, string $zone, bool $orSelf): bool
    {
        if ($zone === '.') {
            return $name !== '.' || $orSelf;
        }
        if ($name === $zone) {
            return $orSelf;
        }

        return str_ends_with($name, '.'.$zone);
    }

    private static function labels(string $name): int
    {
        return $name === '.' ? 0 : substr_count($name, '.') + 1;
    }

    /** نامِ میزبانِ قابلِ پرس‌وجو، یا null */
    private static function host(string $name): ?string
    {
        $n = DnsMessage::normalizeName($name);
        if ($n === '.') {
            return null;
        }
        try {
            DnsMessage::encodeName($n);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $n;
    }

    /** نامِ نسبی/مطلق → FQDNِ داخلِ دامنه؛ نامِ مطلقِ بیرون از دامنه رد می‌شود */
    private static function qualify(string $name, string $domain): ?string
    {
        $raw = trim($name);
        if ($raw === '' || $raw === '@') {
            return $domain;
        }
        $absolute = str_ends_with($raw, '.');
        $n = DnsMessage::normalizeName($raw);
        if ($n === '.') {
            return null;
        }
        if (! self::within($n, $domain, true)) {
            if ($absolute) {
                return null;
            }
            $n .= '.'.$domain;
        }

        return self::host($n);
    }

    /**
     * @param  array<int,array>  $servers
     * @return list<array{host:string,ip:string,port:int}>
     */
    private static function uniqueServers(array $servers): array
    {
        $out = [];
        $seen = [];
        foreach ($servers as $s) {
            $ip = trim((string) ($s['ip'] ?? ''));
            $port = (int) ($s['port'] ?? 53);
            if ($ip === '' || isset($seen[$ip.'#'.$port])) {
                continue;
            }
            $seen[$ip.'#'.$port] = true;
            $out[] = ['host' => (string) ($s['host'] ?? $ip), 'ip' => $ip, 'port' => $port];
            if (count($out) >= self::MAX_AUTH_SERVERS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $ns
     * @param  array<string,list<string>>  $glue
     * @return list<array{host:string,ip:?string,port:int}>
     */
    private static function serversFrom(array $ns, array $glue): array
    {
        $out = [];
        foreach ($ns as $h) {
            if (($glue[$h] ?? []) === []) {
                $out[] = ['host' => $h, 'ip' => null, 'port' => 53];

                continue;
            }
            foreach ($glue[$h] as $ip) {
                $out[] = ['host' => $h, 'ip' => $ip, 'port' => 53];
            }
        }

        return $out;
    }

    /** @return list<array{host:string,ip:string,port:int}> */
    private static function normalizeHints(array $hints): array
    {
        $out = [];
        foreach ($hints as $h) {
            $ip = trim((string) ($h['ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $out[] = [
                'host' => DnsMessage::normalizeName((string) ($h['host'] ?? $ip)),
                'ip'   => $ip,
                'port' => max(1, min(65535, (int) ($h['port'] ?? 53))),
            ];
        }

        return $out;
    }

    private static function delegationFailure(string $error): array
    {
        return ['ok' => false, 'ns' => [], 'ds' => [], 'servers' => [], 'error' => $error, 'parent' => null, 'status' => 'error'];
    }

    private static function definite(string $parent, string $status): array
    {
        return ['ok' => true, 'ns' => [], 'ds' => [], 'servers' => [], 'error' => null, 'parent' => $parent, 'status' => $status];
    }
}
