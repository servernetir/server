<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExitUpstream;
use App\Models\Setting;
use App\Support\ExitCountries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * مدیریتِ «آپ‌استریم‌های اکسیت» از پنل — افزودن/ویرایش/خاموش‌وروشن/حذفِ رله‌های
 * SSH و نودهای VLESS که موتورِ اکسیتِ ایران از راهشان از کشور خارج می‌شود.
 *
 * تا امروز این کار فقط با اسکریپت‌های هاست (`servernet-relay-set`،
 * `servernet-exit-set`) دستی می‌شد. حالا پنل «حالتِ مطلوب» را در `exit_upstreams`
 * می‌نویسد و میزبانِ ایران آن را از `/agent/exitupstreams` می‌کشد و اعمال می‌کند —
 * دقیقاً همان الگوی «یک منبعِ حقیقت» که countryroutes/portforwards دارند.
 *
 * 🔴 اعتبارنامه‌ها (کلیدِ SSH، لینکِ vless) در `secret` رمزنگاری‌شده و write-only
 * می‌نشینند: در فرمِ ویرایش هرگز برنمی‌گردند و اگر خالی بمانند، مقدارِ قبلی حفظ
 * می‌شود. مقدارِ خام فقط به endpointِ توکن‌دارِ هاست می‌رود.
 */
class ExitUpstreamController extends Controller
{
    private const AGENT_STALE_MINUTES = 5;

    public function index(): View
    {
        $has = Schema::hasTable('exit_upstreams');

        $all = $has
            ? ExitUpstream::orderBy('role')
                ->orderBy('country_code')
                ->orderBy('priority')
                ->orderByDesc('id')
                ->get()
            : collect();

        $relays = $all->where('role', 'relay')->values();
        $exits  = $all->where('role', 'exit')->values();

        // خلاصه‌ی «هر کشورِ مجاز چند اکسیتِ اختصاصیِ فعال دارد» — کشوری که هیچ
        // اکسیتِ اختصاصی ندارد فقط به استخرِ رایگان تکیه دارد (هشدارِ نرم).
        $countries = [];

        foreach (ExitCountries::options('fa') as $opt) {
            if ($opt['code'] === ExitCountries::NONE) {
                continue;                       // «بدونِ اکسیت» کشورِ مقصد نیست
            }

            $forCc = $exits->filter(fn ($u) => $u->cc() === $opt['code']);

            $countries[] = [
                'code'    => $opt['code'],
                'name'    => $opt['name'],
                'flag'    => $opt['flag'],
                'total'   => $forCc->count(),
                'active'  => $forCc->where('enabled', true)->count(),
            ];
        }

        return view('admin.exit-upstreams', [
            'relays'        => $relays,
            'exits'         => $exits,
            'countries'     => $countries,
            'relayCount'    => $relays->count(),
            'relayActive'   => $relays->where('enabled', true)->count(),
            'agent'         => $this->agentPulse('agent_seen_exitupstreams'),
            'tokenSet'      => filled(Setting::getSecret('agent_pull_token')),
            'exitCountries' => Setting::get('proxmox_exit_countries') ?: 'de,nl,fi',
        ]);
    }

    public function create(): View
    {
        return view('admin.exit-upstream-form', [
            'upstream'    => new ExitUpstream(['role' => 'relay', 'type' => 'ssh', 'enabled' => true, 'priority' => 100]),
            'exitOptions' => ExitCountries::codeOptions('fa'),
            'roles'       => ExitUpstream::ROLES,
            'types'       => ExitUpstream::TYPES,
        ]);
    }

    public function edit(ExitUpstream $upstream): View
    {
        return view('admin.exit-upstream-form', [
            'upstream'    => $upstream,
            'exitOptions' => ExitCountries::codeOptions('fa'),
            'roles'       => ExitUpstream::ROLES,
            'types'       => ExitUpstream::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateInput($request);

        if (! is_array($data)) {
            return $data;                       // RedirectResponse با خطا
        }

        // ساخت: برای انواعی که اعتبارنامه لازم دارند، secret اجباری است.
        if (blank($request->input('secret')) && $this->secretRequired($data['type'])) {
            return back()->withInput()->with('err', $this->secretHint($data['type']));
        }

        $upstream = new ExitUpstream();
        $this->fill($upstream, $data, $request);
        $upstream->save();

        return redirect()->route('admin.exit-upstreams')
            ->with('ok', 'آپ‌استریمِ «'.$upstream->name.'» افزوده شد. میزبانِ ایران در پیمایشِ بعدی اعمالش می‌کند.');
    }

    public function update(Request $request, ExitUpstream $upstream): RedirectResponse
    {
        $data = $this->validateInput($request);

        if (! is_array($data)) {
            return $data;
        }

        $this->fill($upstream, $data, $request);
        $upstream->save();

        return redirect()->route('admin.exit-upstreams')
            ->with('ok', 'آپ‌استریمِ «'.$upstream->name.'» به‌روزرسانی شد.');
    }

    public function toggle(ExitUpstream $upstream): RedirectResponse
    {
        $upstream->enabled = ! $upstream->enabled;
        $upstream->save();

        $state = $upstream->enabled ? 'فعال' : 'غیرفعال';

        return back()->with('ok', 'آپ‌استریمِ «'.$upstream->name.'» '.$state.' شد.');
    }

    public function destroy(ExitUpstream $upstream): RedirectResponse
    {
        $name = $upstream->name;
        $upstream->delete();

        return back()->with('ok', 'آپ‌استریمِ «'.$name.'» حذف شد. میزبانِ ایران در پیمایشِ بعدی برش می‌دارد.');
    }

    // ─────────────────────────── داخلی ───────────────────────────

    /**
     * اعتبارسنجی + گیت‌های وابسته به نقش/نوع. آرایه‌ی نرمال‌شده برمی‌گرداند، یا
     * یک RedirectResponse اگر گیتی رد شد.
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function validateInput(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:160'],
            'role'         => ['required', Rule::in(ExitUpstream::ROLES)],
            'type'         => ['required', Rule::in(ExitUpstream::TYPES)],
            'country_code' => ['nullable', 'string', 'size:2'],
            'host'         => ['nullable', 'string', 'max:190'],
            'port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username'     => ['nullable', 'string', 'max:64'],
            'secret'       => ['nullable', 'string', 'max:8000'],
            'sni'          => ['nullable', 'string', 'max:190'],
            'priority'     => ['nullable', 'integer', 'min:1', 'max:65535'],
            'note'         => ['nullable', 'string', 'max:2000'],
        ]);

        // validate() فقط کلیدهای حاضر در درخواست را برمی‌گرداند؛ کلیدهای اختیاریِ
        // نیامده را با پیش‌فرض پر کن تا دسترسیِ بعدی «Undefined array key» ندهد.
        $data += [
            'country_code' => null, 'host' => null, 'port' => null,
            'username' => null, 'secret' => null, 'sni' => null,
            'priority' => null, 'note' => null,
        ];

        $data['type'] = strtolower($data['type']);

        // role=exit: کشور اجباری و باید مجاز باشد (همان گیتِ استخرِ کشورها).
        if ($data['role'] === 'exit') {
            $cc = strtolower(trim((string) $data['country_code']));

            if (! ExitCountries::allows($cc)) {
                return back()->withInput()->with('err',
                    'کشورِ اکسیت باید یکی از کشورهای مجاز باشد: '.implode('، ', ExitCountries::codes())
                    .'. برای افزودنِ کشورِ تازه، اول آن را در تنظیماتِ «کشورهای اکسیت» اضافه کن.');
            }

            $data['country_code'] = $cc;
        } else {
            $data['country_code'] = null;       // رله کشور ندارد
        }

        // انواعِ host-محور: host و port اجباری‌اند.
        if (in_array($data['type'], ExitUpstream::HOST_TYPES, true)) {
            if (blank($data['host']) || blank($data['port'])) {
                return back()->withInput()->with('err',
                    'برای '.strtoupper($data['type']).' هم host و هم port لازم است.');
            }
        }

        return $data;
    }

    /**
     * نوشتنِ مقادیرِ نرمال‌شده روی مدل. `secret` write-only است: فقط اگر مقدارِ
     * تازه بیاید نوشته می‌شود، وگرنه مقدارِ قبلی دست‌نخورده می‌ماند.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(ExitUpstream $upstream, array $data, Request $request): void
    {
        $upstream->name         = $data['name'];
        $upstream->role         = $data['role'];
        $upstream->type         = $data['type'];
        $upstream->country_code = $data['country_code'];
        $upstream->host         = filled($data['host']) ? trim($data['host']) : null;
        $upstream->port         = $data['port'] ?: null;
        $upstream->username     = filled($data['username'])
            ? trim($data['username'])
            : ($data['type'] === 'ssh' ? 'root' : null);   // پیش‌فرضِ منطقیِ SSH
        $upstream->sni          = filled($data['sni']) ? trim($data['sni']) : null;
        $upstream->priority     = $data['priority'] ?: 100;
        $upstream->note         = $data['note'] ?? null;
        $upstream->enabled      = $request->boolean('enabled');

        // 🔴 secret فقط وقتی مقدارِ تازه بیاید نوشته می‌شود (write-only).
        $secret = $request->input('secret');

        if (filled($secret)) {
            $upstream->secret = trim((string) $secret);
        }
    }

    /** آیا این نوع در لحظه‌ی ساخت حتماً اعتبارنامه/لینک می‌خواهد؟ */
    private function secretRequired(string $type): bool
    {
        // vless/trojan: خودِ لینک است. ssh: کلید یا رمز. socks/wireguard: اختیاری.
        return in_array($type, ['ssh', 'vless', 'trojan'], true);
    }

    private function secretHint(string $type): string
    {
        return match ($type) {
            'vless', 'trojan' => 'برای '.strtoupper($type).' لینکِ کاملِ اتصال ('.$type.'://…) لازم است.',
            default           => 'برای SSH کلیدِ خصوصی یا رمز لازم است.',
        };
    }

    /**
     * @return array{seen:?Carbon, minutes:?int, stale:bool}
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

        return ['seen' => $seen, 'minutes' => $minutes, 'stale' => $minutes > self::AGENT_STALE_MINUTES];
    }
}
