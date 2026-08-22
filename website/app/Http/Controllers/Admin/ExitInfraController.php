<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Support\ExitCountries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * دیدِ اپراتور به پلتفرمِ Exit VPS + **سوییچِ کشورِ خروج** (فازِ A).
 *
 * دو چیز را یک‌جا نشان می‌دهد:
 *   • کدام ماشین‌های Proxmox هستند و از کدام کشور خارج می‌شوند — با آی‌پیِ
 *     داخلی، دسترسیِ عمومیِ host:port، وضعیت، و مشتریِ صاحبِ سرویس.
 *   • آیا pull-agentِ میزبانِ ایران زنده است — از ضربانی که PullController در
 *     هر پیمایش ثبت می‌کند (`agent_seen_*`). کهنه‌تر از ۵ دقیقه = خوابیده.
 *
 * ═══ فازِ A: سوییچِ کشور ═══
 *
 * هر ردیف یک منوی انتخابِ کشور دارد. اپراتور کشور را عوض می‌کند →
 * `meta['exit_country']` ست می‌شود → PullController آن را در `countryroutes`
 * برمی‌گرداند → ایجنتِ ایران در پیمایشِ بعدی `servernet-vm-country` را با کشورِ
 * تازه می‌زند (که خودش قاعدهٔ کشورِ قبلی را پاک و تازه را می‌نشاند). گزینهٔ
 * «بدونِ اکسیت» → ایجنت در reconcile آن IP را از split-routing درمی‌آورد.
 *
 * ⚠️ این‌جا هیچ دستوری روی هاست اجرا نمی‌شود؛ فقط «حالتِ مطلوب» در دیتابیس عوض
 * می‌شود و ایجنتِ ایران (که مالکِ iptables/routing است) آن را می‌کشد. یعنی
 * تغییرِ کشور چند دقیقه (تا پیمایشِ بعدیِ ایجنت) طول می‌کشد — و همین درست است:
 * یک منبعِ حقیقت، نه دو جا که از هم بیفتند.
 */
class ExitInfraController extends Controller
{
    /** بیش از این دقیقه بدونِ ضربان یعنی ایجنت نمی‌دود */
    private const AGENT_STALE_MINUTES = 5;

    /**
     * 🔴 خطِ‌قرمز: این VMIDها هرگز نباید واردِ سیستمِ اکسیت شوند و کشور/پورتشان
     * از پنل عوض شود. VM 108 (محمدی) خطِ‌قرمزِ مطلقِ این پروژه است؛ اگر روزی
     * روتینگِ کشوری رویش اعمال شود، خروجیِ اینترنتِ او بی‌خبر عوض می‌شود.
     * (از config خوانده می‌شود تا بدونِ تغییرِ کد قابلِ‌گسترش باشد.)
     *
     * @return array<int, string>
     */
    private function protectedVmids(): array
    {
        $raw = config('servernet.exit.protected_vmids', ['108']);
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $raw)));
    }

    /** آیا این VMID خطِ‌قرمز است؟ (provider_ref ماشین‌های Proxmox همان vmid است) */
    private function isProtectedVmid(?string $vmid): bool
    {
        $vmid = trim((string) $vmid);

        return $vmid !== '' && in_array($vmid, $this->protectedVmids(), true);
    }

    public function index(): View
    {
        // روی سروری که هنوز مهاجرتِ ابری را نخورده، صفحه نباید ۵۰۰ شود.
        $hasTable = Schema::hasTable('cloud_instances');

        $publicIp = (string) (Setting::get('public_ip') ?: config('servernet.exit.public_ip', ''));

        $instances = $hasTable
            ? CloudInstance::where('provider', 'proxmox')
                ->with('service.customer')
                ->orderByDesc('id')
                ->get()
            : collect();

        $rows = [];
        $perCountry = [];

        foreach ($instances as $inst) {
            // کدِ کشورِ خروجِ **مؤثر** (override بر location_code مقدم) — همان چیزی
            // که ایجنت واقعاً اعمال می‌کند. null یعنی خروجِ عادیِ ایران.
            $cc  = $inst->exitCountryCode() ?? '';
            $iso = strtoupper($cc);
            $country = CloudLocation::COUNTRIES[$iso] ?? null;

            $service  = $inst->service;              // ممکن است null باشد (سرورِ یتیم)
            $customer = $service?->customer;

            $port = (int) ($inst->meta['public_port'] ?? 0);

            if ($port <= 0) {
                $public = '';
            } elseif ($publicIp !== '') {
                $public = $publicIp.':'.$port;
            } else {
                $public = (string) $port;           // فقط پورت؛ IP عمومی هنوز تنظیم نشده
            }

            $rows[] = [
                'id'            => $inst->id,
                'ref'           => (string) $inst->provider_ref,     // vmidِ Proxmox
                'iso'           => $iso,
                'country_name'  => $country['fa'] ?? ($iso !== '' ? $iso : 'ایران (بدونِ اکسیت)'),
                'flag'          => $country['flag'] ?? ($iso === '' ? '🇮🇷' : '🏳️'),
                'ipv4'          => (string) $inst->ipv4,
                'port'          => $port,
                'public_host'   => $public,
                'status_label'  => $inst->statusLabel('fa'),
                'status_color'  => $inst->statusColor(),
                'customer_name' => $customer?->displayName(),
                'customer_code' => $customer?->code,
                'created_at'    => $inst->created_at,
                // فازِ A: انتخابِ جاریِ منو + آیا override دستی خورده
                'exit_cc'       => $cc !== '' ? $cc : ExitCountries::NONE,
                'exit_override' => $inst->exitCountryIsOverride(),
                'actionable'    => in_array($inst->status, ['running', 'off', 'building'], true),
                // «یتیم» = بی‌سرویس/مشتری (VMِ زیرساختیِ ما) — فقط این‌ها detach می‌شوند
                'is_orphan'     => $service === null,
                'protected'     => $this->isProtectedVmid((string) $inst->provider_ref),
            ];

            if ($iso !== '') {
                $perCountry[$iso] = ($perCountry[$iso] ?? 0) + 1;
            }
        }

        arsort($perCountry);                        // پرشمارترین کشور اول

        $countrySummary = [];

        foreach ($perCountry as $iso => $count) {
            $c = CloudLocation::COUNTRIES[$iso] ?? null;
            $countrySummary[] = [
                'iso'   => $iso,
                'name'  => $c['fa'] ?? $iso,
                'flag'  => $c['flag'] ?? '🏳️',
                'count' => $count,
            ];
        }

        return view('admin.exit-infra', [
            'rows'           => $rows,
            'total'          => count($rows),
            'countrySummary' => $countrySummary,
            'publicIp'       => $publicIp,
            'exitOptions'    => ExitCountries::options('fa'),   // فازِ A: منوی سوییچ
            // برای ویرایشِ پورت و دکمه‌ی «وارد کردنِ VM»
            'portMin'        => (int) config('servernet.exit.sale_port_min', 20000),
            'portMax'        => (int) config('servernet.exit.sale_port_max', 20999),
            'proxmoxConfigured' => filled(Setting::getSecret('proxmox_token_secret')),
            'agents'         => [
                'countryroutes' => $this->agentPulse('agent_seen_countryroutes'),
                'portforwards'  => $this->agentPulse('agent_seen_portforwards'),
            ],
            'config'         => [
                'exit_countries' => Setting::get('proxmox_exit_countries') ?: 'de,nl,fi',
                'agent_token'    => filled(Setting::getSecret('agent_pull_token')),
                'proxmox_token'  => filled(Setting::getSecret('proxmox_token_secret')),
            ],
        ]);
    }

    /**
     * سوییچِ کشورِ خروجِ یک ماشین (فازِ A).
     *
     * فقط «حالتِ مطلوب» را در `meta['exit_country']` می‌نویسد؛ اعمالِ واقعی با
     * ایجنتِ ایران در پیمایشِ بعدی است. ورودیِ `ir/none/''` یعنی خاموش‌کردنِ
     * اکسیت (خروجِ عادیِ ایران).
     */
    public function setCountry(Request $request, CloudInstance $instance): RedirectResponse
    {
        // فقط ماشین‌های Proxmoxِ میزبانِ ایران split-routing کشوری دارند.
        if ($instance->provider !== 'proxmox') {
            return back()->with('err', 'فقط ماشین‌های میزبانِ ایران (Proxmox) اکسیتِ کشوری دارند.');
        }

        // 🔴 خطِ‌قرمز (VM108): هرگز روتینگِ کشوری روی این‌ها اعمال نمی‌شود.
        if ($this->isProtectedVmid((string) $instance->provider_ref)) {
            return back()->with('err', 'این ماشین خطِ‌قرمز است؛ کشورِ خروجش از پنل تغییر نمی‌کند.');
        }

        $cc = strtolower(trim((string) $request->input('country')));

        if (! ExitCountries::accepts($cc)) {
            return back()->with('err', 'کشورِ انتخابی مجاز نیست. کشورهای مجاز: '.implode('، ', ExitCountries::codes()).' (یا «بدونِ اکسیت»).');
        }

        $disable = ExitCountries::isNone($cc);

        $meta = $instance->meta ?? [];
        $meta['exit_country']    = $disable ? ExitCountries::NONE : $cc;
        $meta['exit_country_at'] = now()->toIso8601String();
        $meta['exit_country_by'] = 'admin';         // ردِ حداقلی برای ممیزی
        $instance->meta = $meta;
        $instance->save();

        $label = $disable ? 'بدونِ اکسیت (ایران)' : strtoupper($cc);
        $where = $instance->ipv4 ?: ('#'.$instance->id);

        return back()->with('ok', "کشورِ خروجِ سرورِ {$where} روی «{$label}» تنظیم شد. ایجنتِ ایران در پیمایشِ بعدی (چند دقیقه) اعمال می‌کند.");
    }

    /**
     * صفحه‌ی «وارد کردنِ VM» — اسکنِ Proxmox (ماشین‌های واقعیِ نود) + فرمِ ثبتِ دستی.
     *
     * اسکن عمداً فقط با `?scan=1` اجرا می‌شود (هر بار چند تماسِ زنده با Proxmox
     * است). ماشین‌های ازقبل‌ثبت‌شده و خطِ‌قرمز علامت می‌خورند تا دوباره/اشتباه
     * وارد نشوند.
     */
    public function importForm(Request $request, CloudManager $manager): View
    {
        $proxmox    = $manager->driver('proxmox');
        $configured = $proxmox !== null && $proxmox->isConfigured();
        $scan       = null;

        if ($request->boolean('scan') && $configured) {
            $res = $proxmox->listServers();

            $registered = CloudInstance::where('provider', 'proxmox')
                ->whereNotNull('provider_ref')
                ->pluck('provider_ref')
                ->map(fn ($r) => (string) $r)
                ->flip();

            $servers = collect($res['servers'] ?? [])->map(function ($s) use ($registered) {
                $ref = (string) ($s['ref'] ?? '');

                return [
                    'ref'        => $ref,
                    'name'       => (string) ($s['name'] ?? $ref),
                    'status'     => (string) ($s['status'] ?? ''),
                    'ipv4'       => (string) ($s['ipv4'] ?? ''),
                    'registered' => $registered->has($ref),
                    'protected'  => $this->isProtectedVmid($ref),
                ];
            })->values()->all();

            $scan = ['ok' => (bool) ($res['ok'] ?? false), 'message' => (string) ($res['message'] ?? ''), 'servers' => $servers];
        }

        return view('admin.exit-infra-import', [
            'configured'  => $configured,
            'scan'        => $scan,
            'exitOptions' => ExitCountries::codeOptions('fa'),
            'osOptions'   => self::OS_OPTIONS,
        ]);
    }

    /** سیستم‌عامل‌های رایج → کلیدِ image_key (ویندوز = پورتِ RDP در port-forward) */
    private const OS_OPTIONS = [
        'ubuntu-24.04' => 'Ubuntu 24.04',
        'ubuntu-22.04' => 'Ubuntu 22.04',
        'debian-13'    => 'Debian 13',
        'debian-12'    => 'Debian 12',
        'rocky-9'      => 'Rocky 9',
        'alma-9'       => 'AlmaLinux 9',
        'windows-2022' => 'Windows Server 2022',
    ];

    /**
     * ثبتِ یک ماشینِ Proxmox در سیستمِ اکسیت (به‌صورتِ «یتیم» — بی‌سرویس/مشتری).
     * از اسکن (با `ref`/vmid) یا دستی. خطِ‌قرمز و تکراری رد می‌شوند.
     */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ref'      => ['nullable', 'string', 'max:32'],
            'hostname' => ['required', 'string', 'max:190'],
            'ipv4'     => ['required', 'ipv4'],
            'os'       => ['required', 'string', 'max:40'],
            'country'  => ['nullable', 'string', 'size:2'],
            'port'     => ['nullable', 'integer', 'min:1', 'max:65535'],
            'status'   => ['nullable', 'string', 'max:16'],
        ]);

        $ref = trim((string) ($data['ref'] ?? ''));

        // 🔴 خطِ‌قرمز
        if ($this->isProtectedVmid($ref)) {
            return back()->withInput()->with('err', 'این VMID خطِ‌قرمز است و وارد نمی‌شود.');
        }

        // تکراری: همان vmid قبلاً ثبت شده
        if ($ref !== '' && CloudInstance::where('provider', 'proxmox')->where('provider_ref', $ref)->exists()) {
            return back()->withInput()->with('err', 'این ماشین (vmid '.$ref.') از قبل ثبت شده است.');
        }

        $cc   = strtolower(trim((string) ($data['country'] ?? '')));
        $loc  = null;
        $meta = [];

        if ($cc !== '' && ! ExitCountries::isNone($cc)) {
            if (! ExitCountries::allows($cc)) {
                return back()->withInput()->with('err', 'کشورِ اکسیت مجاز نیست: '.implode('، ', ExitCountries::codes()));
            }
            $loc = 'exit-'.$cc;
        }

        if (! empty($data['port'])) {
            $meta['public_port'] = (int) $data['port'];
        }

        $status = in_array($data['status'] ?? '', ['running', 'off', 'building'], true)
            ? $data['status'] : 'running';

        $inst = CloudInstance::create([
            'service_id'    => null,                    // یتیم — VMِ زیرساختی، نه خریدِ مشتری
            'provider'      => 'proxmox',
            'provider_ref'  => $ref !== '' ? $ref : null,
            'location_code' => $loc,
            'image_key'     => $data['os'],
            'hostname'      => $data['hostname'],
            'ipv4'          => $data['ipv4'],
            'status'        => $status,
            'meta'          => $meta ?: null,
        ]);

        return redirect()->route('admin.exit-infra')
            ->with('ok', 'ماشینِ «'.$inst->hostname.'» به سیستمِ اکسیت افزوده شد.');
    }

    /**
     * تنظیمِ پورتِ عمومیِ یک ماشین (override بر تخصیصِ خودکارِ port-forward).
     * فقط Proxmox، غیرِخطِ‌قرمز، و پورتِ بی‌تداخل.
     */
    public function setPort(Request $request, CloudInstance $instance): RedirectResponse
    {
        if ($instance->provider !== 'proxmox') {
            return back()->with('err', 'فقط ماشین‌های Proxmox پورتِ عمومی می‌گیرند.');
        }

        if ($this->isProtectedVmid((string) $instance->provider_ref)) {
            return back()->with('err', 'این ماشین خطِ‌قرمز است.');
        }

        $data = $request->validate(['port' => ['required', 'integer', 'min:1', 'max:65535']]);
        $port = (int) $data['port'];

        // یکتا بودن — در PHP می‌سنجیم (پرتابل، مثلِ portForwards).
        $clash = CloudInstance::whereNotNull('meta')->where('id', '!=', $instance->id)
            ->get(['id', 'meta'])
            ->contains(fn ($r) => (int) ($r->meta['public_port'] ?? 0) === $port);

        if ($clash) {
            return back()->with('err', 'این پورت روی ماشینِ دیگری تنظیم شده است.');
        }

        $meta = $instance->meta ?? [];
        $meta['public_port'] = $port;
        $instance->meta = $meta;
        $instance->save();

        return back()->with('ok', 'پورتِ عمومیِ ماشین روی '.$port.' تنظیم شد. ایجنتِ ایران در پیمایشِ بعدی اعمال می‌کند.');
    }

    /**
     * حذفِ یک ماشین از **فهرستِ اکسیت** (نه از Proxmox). فقط ماشینِ «یتیم»
     * (بی‌سرویس) — هرگز نمونه‌ی خریدِ یک مشتری، وگرنه پنلِ مشتری/صورت‌حساب می‌شکند.
     */
    public function detach(Request $request, CloudInstance $instance): RedirectResponse
    {
        if ($instance->service_id !== null) {
            return back()->with('err', 'این ماشین به سرویسِ یک مشتری وصل است و از این‌جا حذف نمی‌شود.');
        }

        $name = $instance->hostname ?: ('#'.$instance->id);
        $instance->delete();

        return back()->with('ok', 'ماشینِ «'.$name.'» از فهرستِ اکسیت حذف شد (خودِ VM روی Proxmox دست‌نخورده است).');
    }

    /**
     * وضعیتِ ضربانِ یک ایجنت: زمانِ آخرین دیده‌شدن، دقیقهٔ گذشته، و کهنه/تازه‌بودن.
     *
     * @return array{seen:?\Illuminate\Support\Carbon, minutes:?int, stale:bool}
     */
    private function agentPulse(string $key): array
    {
        $raw = Setting::get($key);

        if (blank($raw)) {
            return ['seen' => null, 'minutes' => null, 'stale' => true];
        }

        try {
            $seen = Carbon::parse($raw);
        } catch (\Throwable) {
            return ['seen' => null, 'minutes' => null, 'stale' => true];
        }

        $minutes = (int) round(abs($seen->diffInMinutes(now())));

        return [
            'seen'    => $seen,
            'minutes' => $minutes,
            'stale'   => $minutes > self::AGENT_STALE_MINUTES,
        ];
    }
}
