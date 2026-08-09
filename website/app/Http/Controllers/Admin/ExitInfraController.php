<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * دیدِ فقط‌خواندنیِ اپراتور به پلتفرمِ Exit VPS (فازهای D1/D2).
 *
 * دو چیز را یک‌جا نشان می‌دهد:
 *   • کدام Exit VPSها هستند و از کدام کشور خارج می‌شوند (نمونه‌های Proxmox که
 *     مکانشان `exit-<cc>` است) — با آی‌پیِ داخلی، دسترسیِ عمومیِ host:port،
 *     وضعیت، و مشتریِ صاحبِ سرویس.
 *   • آیا pull-agentِ میزبانِ ایران زنده است — از ضربانی که PullController در
 *     هر پیمایش ثبت می‌کند (`agent_seen_*`). کهنه‌تر از ۵ دقیقه = خوابیده.
 *
 * هیچ عملیاتی این‌جا نیست؛ همه‌چیز خواندنی است.
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
            // کدِ کشور از خودِ کدِ مکان: `exit-de` → `de` (مثلِ PullController)
            $cc  = str_starts_with((string) $inst->location_code, 'exit-')
                ? substr((string) $inst->location_code, 5)
                : '';
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
                'iso'           => $iso,
                'country_name'  => $country['fa'] ?? ($iso !== '' ? $iso : '—'),
                'flag'          => $country['flag'] ?? '🏳️',
                'ipv4'          => (string) $inst->ipv4,
                'public_host'   => $public,
                'status_label'  => $inst->statusLabel('fa'),
                'status_color'  => $inst->statusColor(),
                'customer_name' => $customer?->displayName(),
                'customer_code' => $customer?->code,
                'created_at'    => $inst->created_at,
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
