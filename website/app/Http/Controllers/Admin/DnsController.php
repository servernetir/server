<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CdnEvent;
use App\Models\CdnZone;
use App\Models\DnsZoneNode;
use App\Models\Setting;
use App\Services\Dns\DelegationInspector;
use App\Services\Dns\DnsConfig;
use App\Services\Dns\DomainName;
use App\Services\Dns\PowerDnsClient;
use App\Services\Dns\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مرکزِ عملیاتِ DNSِ مدیریت‌شده — قرارداد §2، §4، §10.
 *
 * ⚠️ کلیدِ API با `Setting::putSecret` رمزنگاری‌شده ذخیره می‌شود و هرگز به فرم
 * برنمی‌گردد (فیلدِ خالی = «عوضش نکن»). خطای خامِ PowerDNS فقط این‌جا (برای مدیر)
 * و پاک‌سازی‌شده (بی‌کلید و بی‌نشانی) نمایش داده می‌شود.
 */
class DnsController extends Controller
{
    private const FILTERS = ['all', 'failed', 'lagging', 'pending', 'active', 'lost', 'dnssec', 'deleting', 'suspended'];

    public function __construct(private readonly ZoneService $zones, private readonly PowerDnsClient $pdns) {}

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('cdn_zones') && Schema::hasColumn('cdn_zones', 'desired_version');
        $filter = in_array($request->query('f'), self::FILTERS, true) ? $request->query('f') : 'all';
        $q = trim((string) $request->query('q', ''));

        $zones = collect();
        $counts = array_fill_keys(self::FILTERS, 0);
        if ($ready) {
            $base = fn () => CdnZone::query()->where('mode', 'ns');
            foreach (self::FILTERS as $f) {
                $counts[$f] = $this->applyFilter($base(), $f)->count();
            }
            $query = $this->applyFilter($base(), $filter)->with(['customer', 'nodes'])->withCount('records');
            if ($q !== '') {
                $query->where('domain', 'like', '%'.strtolower($q).'%');
            }
            $zones = $query->orderByRaw("CASE publish_state WHEN 'failed' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(300)->get();
        }

        return view('admin.dns', [
            'ready'       => $ready,
            'zones'       => $zones,
            'counts'      => $counts,
            'filter'      => $filter,
            'q'           => $q,
            'enabled'     => DnsConfig::enabled(),
            'open'        => DnsConfig::serviceOpen(),
            'apiUrl'      => DnsConfig::apiUrl(),
            'apiOk'       => DnsConfig::apiUrlIsAcceptable(),
            'hasKey'      => filled(DnsConfig::apiKey()),
            'tsigKey'     => DnsConfig::tsigKey(),
            'catalog'     => DnsConfig::catalog(),
            'nameservers' => DnsConfig::nameservers(),
            'nsErrors'    => DnsConfig::nameserverErrors(),
            'health'      => session('dns_health'),
            'reconcile'   => json_decode((string) Setting::get(\App\Console\Commands\DnsReconcile::REPORT_KEY), true) ?: null,
            'events'      => $ready ? CdnEvent::query()->audits()->orderByDesc('id')->limit(25)->get() : collect(),
        ]);
    }

    private function applyFilter($q, string $f)
    {
        return match ($f) {
            'failed'    => $q->where('publish_state', 'failed'),
            'lagging'   => $q->whereIn('status', ['pending', 'active'])->whereHas('nodes', fn ($n) => $n->where('state', '!=', DnsZoneNode::IN_SYNC)),
            'pending'   => $q->where('status', 'pending'),
            'active'    => $q->where('status', 'active'),
            'lost'      => $q->where('status', 'active')->whereIn('delegation_state', ['not_delegated', 'partial']),
            'dnssec'    => $q->whereNotNull('parent_ds')->where('parent_ds', '!=', '[]'),
            'deleting'  => $q->where('status', 'deleting'),
            'suspended' => $q->where('status', 'suspended'),
            default     => $q,
        };
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled'         => ['nullable', 'boolean'],
            'pdns_api_url'    => ['required', 'string', 'max:190'],
            'pdns_api_key'    => ['nullable', 'string', 'max:190'],
            'tsig_key'        => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9._-]+$/'],
            'catalog'         => ['required', 'string', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/'],
            'ns'              => ['array', 'max:6'],
            'ns.*.host'       => ['nullable', 'string', 'max:190'],
            'ns.*.ipv4'       => ['nullable', 'string', 'max:45'],
            'ns.*.ipv6'       => ['nullable', 'string', 'max:45'],
            'ns.*.port'       => ['nullable', 'string', 'max:5'],
        ]);

        $url = rtrim(trim($data['pdns_api_url']), '/');
        if (! DnsConfig::urlIsAcceptable($url)) {
            return back()->withInput()->withErrors(['pdns_api_url' => 'نشانیِ API باید https باشد (یا نشانیِ خصوصی فقط وقتی DNS_ALLOW_INSECURE_API در محیطِ آزمایشگاه روشن است). کلید روی HTTPِ عمومی فرستاده نمی‌شود.']);
        }

        // کلیدِ ذخیره‌شده هرگز به میزبانِ تازه‌ای نمی‌رود که کلید برایش دوباره وارد نشده:
        // وگرنه نشستِ مدیرِ ربوده‌شده با عوض‌کردنِ نشانی، کلید را بی‌دیدنش بیرون می‌کشید
        $oldHost = strtolower((string) parse_url((string) DnsConfig::apiUrl(), PHP_URL_HOST));
        $newHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($oldHost !== '' && $oldHost !== $newHost && filled(DnsConfig::apiKey()) && blank($data['pdns_api_key'] ?? null)) {
            return back()->withInput()->withErrors(['pdns_api_key' => 'میزبانِ API عوض شده است؛ کلیدِ API را دوباره وارد کنید. کلیدِ ذخیره‌شده به میزبانِ تازه فرستاده نمی‌شود.']);
        }

        $rows = array_values(array_filter((array) ($data['ns'] ?? []), fn ($r) => filled($r['host'] ?? null)));
        $v = DnsConfig::validateNameservers($rows);
        if ($v['errors'] !== []) {
            $msg = collect($v['errors'])->map(fn ($e) => ($e['index'] !== null ? 'ردیفِ '.($e['index'] + 1).': ' : '').(self::NS_ERRORS[$e['code']] ?? $e['code']))->implode(' · ');

            return back()->withInput()->withErrors(['ns' => $msg]);
        }

        $before = DnsConfig::nameserverHosts();
        Setting::put('dns_managed_enabled', $request->boolean('enabled') ? '1' : '0');
        Setting::put('pdns_api_url', $url);
        Setting::put('dns_xfr_tsig_key', $data['tsig_key']);
        Setting::put('dns_catalog_zone', strtolower(rtrim($data['catalog'], '.')));
        Setting::put('dns_nameservers', json_encode($v['nameservers'], JSON_UNESCAPED_SLASHES));
        if (filled($data['pdns_api_key'] ?? null)) {
            Setting::putSecret('pdns_api_key', trim($data['pdns_api_key']));
        }

        $msg = 'تنظیماتِ DNS ذخیره شد.';
        $after = array_column($v['nameservers'], 'host');
        if ($before !== [] && $before !== $after && Schema::hasTable('cdn_zones')) {
            // NSِ apexِ همهٔ zoneها باید عوض شود؛ ناشر در کرون می‌فرستد
            CdnZone::query()->where('mode', 'ns')->whereIn('status', ['pending', 'active'])->each(function (CdnZone $z) {
                try {
                    $z->bumpVersion();
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    // zone همین وسط حذف شد — چیزی برای انتشارِ دوباره نیست
                }
            });
            $msg .= ' نامِ نیم‌سرورها عوض شد؛ همهٔ zoneها برای انتشارِ دوباره علامت خوردند. مشتریانِ فعال باید NSِ دامنه‌شان را هم عوض کنند.';
        }
        CdnEvent::audit('settings_changed', null, [
            'enabled'     => $request->boolean('enabled'),
            'api_host'    => $newHost,
            'api_host_was' => $oldHost !== $newHost ? $oldHost : null,
            'key_changed' => filled($data['pdns_api_key'] ?? null),
        ], 'admin:'.auth()->id(), $request->ip());

        return back()->with('ok', $msg);
    }

    /** آزمایشِ اتصال: API، وجودِ catalog روی primary، و SOAِ catalog روی هر نیم‌سرور (UDP و TCP) */
    public function test(DelegationInspector $inspector): RedirectResponse
    {
        $info = $this->pdns->serverInfo();
        $catalog = $info['ok'] ? $this->pdns->getZone(DnsConfig::catalog()) : null;
        $nodes = [];
        foreach (DnsConfig::nameservers() as $ns) {
            foreach (array_filter([$ns['ipv4'], $ns['ipv6']]) as $ip) {
                foreach (['udp' => false, 'tcp' => true] as $proto => $tcp) {
                    $s = $inspector->soaAt($ip, $ns['port'], DnsConfig::catalog(), $tcp);
                    $nodes[] = ['host' => $ns['host'], 'ip' => $ip, 'proto' => $proto, 'state' => $s['state'], 'aa' => (bool) ($s['aa'] ?? false), 'serial' => $s['serial'] ?? null];
                }
            }
        }

        return back()->with('dns_health', [
            'api'     => $info['ok'] ? 'ok — PowerDNS '.($info['data']['version'] ?? '?') : 'ناموفق: '.$info['error'],
            'apiOk'   => $info['ok'],
            'catalog' => $catalog === null ? null : ($catalog['ok'] ? 'catalog روی primary هست (serial '.($catalog['data']['serial'] ?? '?').')' : 'catalog روی primary نیست: '.$catalog['error']),
            'nodes'   => $nodes,
        ]);
    }

    public function republish(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        $this->zones->republish($z, 'admin:'.auth()->id(), $request->ip());

        return back()->with('ok', $z->domain.': انتشارِ دوباره در صف است.');
    }

    public function check(int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        $r = $this->zones->checkDelegation($z);

        return back()->with('ok', $z->domain.': تفویض «'.$r['state'].'»'.($r['active'] ? ' — فعال' : '').($r['error'] ? ' ('.$r['error'].')' : ''));
    }

    public function status(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        $suspend = $request->input('status') === 'suspended';
        $this->zones->setSuspended($z, $suspend, 'admin:'.auth()->id(), $request->ip());

        return back()->with('ok', $z->domain.($suspend ? ' معلق شد و از نیم‌سرورها برداشته می‌شود.' : ' از تعلیق خارج شد و دوباره منتشر می‌شود.'));
    }

    public function destroy(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        if (DomainName::normalize((string) $request->input('confirm')) !== $z->domain) {
            return back()->withErrors(['dns' => 'برای حذف، نامِ دامنه را دقیقاً وارد کنید.']);
        }
        $this->zones->requestDelete($z, 'admin:'.auth()->id(), $request->ip());

        return back()->with('ok', $z->domain.': حذف آغاز شد؛ پس از برداشته‌شدن از هر دو نیم‌سرور کامل می‌شود.');
    }

    private function zone(int $id): CdnZone
    {
        abort_unless(Schema::hasTable('cdn_zones'), 404);

        return CdnZone::findOrFail($id);
    }

    public const NS_ERRORS = [
        'ns_entry_invalid'  => 'ردیفِ نامعتبر',
        'ns_host_invalid'   => 'نامِ میزبان نامعتبر است',
        'ns_host_reserved'  => 'این نام مالِ DNSِ هاست است (ns1/ns2.servernet.*) و نباید استفاده شود',
        'ns_host_duplicate' => 'نامِ تکراری',
        'ns_ipv4_invalid'   => 'IPv4 نامعتبر',
        'ns_ipv6_invalid'   => 'IPv6 نامعتبر',
        'ns_no_address'     => 'دست‌کم یک IP لازم است',
        'ns_port_invalid'   => 'پورت نامعتبر',
        'ns_too_few'        => 'دست‌کم دو نیم‌سرور با IPv4 لازم است',
        'ns_json_invalid'   => 'تنظیمِ ذخیره‌شده خراب است',
    ];
}
