<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\Setting;
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
                'iso'           => $iso,
                'country_name'  => $country['fa'] ?? ($iso !== '' ? $iso : 'ایران (بدونِ اکسیت)'),
                'flag'          => $country['flag'] ?? ($iso === '' ? '🇮🇷' : '🏳️'),
                'ipv4'          => (string) $inst->ipv4,
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
