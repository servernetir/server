<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CdnEvent;
use App\Models\CdnZone;
use App\Models\Setting;
use App\Services\Dns\PowerDnsClient;
use App\Services\Dns\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مرکزِ عملیاتِ DNSِ مدیریت‌شده — اتصال به PowerDNS، نیم‌سرورها، و همهٔ zoneها.
 *
 * ⚠️ کلیدِ API با `Setting::putSecret` رمزنگاری‌شده ذخیره می‌شود و هرگز به
 * فرم برنمی‌گردد (فیلدِ خالی = «عوضش نکن»). همان قراردادِ توکنِ Cloudflare.
 */
class DnsController extends Controller
{
    public function __construct(private readonly ZoneService $zones, private readonly PowerDnsClient $pdns) {}

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('cdn_zones');

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $list = collect();
        $counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'dirty' => 0, 'lost' => 0];

        if ($ready) {
            $query = CdnZone::query()->with('customer')->withCount('records')->where('mode', 'ns');

            if ($q !== '') {
                $query->where('domain', 'like', '%'.mb_strtolower($q).'%');
            }

            match ($status) {
                'dirty'  => $query->where('dirty', true),
                'lost'   => $query->where('status', 'active')->whereNotNull('verify_error'),
                ''       => null,
                default  => $query->where('status', $status),
            };

            $list = $query->orderByDesc('id')->limit(300)->get();

            $base = CdnZone::query()->where('mode', 'ns');
            $counts = [
                'all'       => (clone $base)->count(),
                'active'    => (clone $base)->where('status', 'active')->count(),
                'pending'   => (clone $base)->where('status', 'pending')->count(),
                'suspended' => (clone $base)->where('status', 'suspended')->count(),
                'dirty'     => (clone $base)->where('dirty', true)->count(),
                // فعال ولی دیگر به ما تفویض نشده — مشتری نیم‌سرور را برگردانده
                'lost'      => (clone $base)->where('status', 'active')->whereNotNull('verify_error')->count(),
            ];
        }

        return view('admin.dns', [
            'ready'       => $ready,
            'zones'       => $list,
            'counts'      => $counts,
            'q'           => $q,
            'status'      => $status,
            'configured'  => $this->pdns->isConfigured(),
            'apiUrl'      => PowerDnsClient::apiUrl(),
            'hasKey'      => filled(Setting::getSecret('pdns_api_key')),
            'nameservers' => CdnZone::ourNameservers(),
            'health'      => session('dns_health'),
            'events'      => $ready && Schema::hasTable('cdn_events')
                ? CdnEvent::where('type', 'dns')->orderByDesc('id')->limit(20)->get()
                : collect(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pdns_api_url'    => ['required', 'url:http,https', 'max:190'],
            'pdns_api_key'    => ['nullable', 'string', 'max:190'],
            'dns_nameservers' => ['required', 'string', 'max:400'],
        ]);

        $ns = array_values(array_unique(array_filter(array_map(
            fn ($n) => mb_strtolower(rtrim(trim($n), '.')),
            preg_split('~[\s,]+~', $data['dns_nameservers']) ?: []
        ))));

        foreach ($ns as $n) {
            if (! preg_match('~^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$~', $n)) {
                return back()->withErrors(['dns_nameservers' => 'نامِ «'.$n.'» یک نامِ میزبانِ معتبر نیست.'])->withInput();
            }
        }

        if (count($ns) < 2) {
            // رجیسترارها کمتر از دو نیم‌سرور را نمی‌پذیرند؛ یکی یعنی مشتری گیر می‌کند
            return back()->withErrors(['dns_nameservers' => 'دست‌کم دو نیم‌سرور لازم است.'])->withInput();
        }

        $changedNs = $ns !== CdnZone::ourNameservers();

        Setting::put('pdns_api_url', rtrim($data['pdns_api_url'], '/'));
        Setting::put('dns_nameservers', implode(',', $ns));

        if (filled($data['pdns_api_key'] ?? null)) {
            Setting::putSecret('pdns_api_key', trim($data['pdns_api_key']));
        }

        $msg = 'تنظیماتِ DNS ذخیره شد.';

        if ($changedNs && Schema::hasTable('cdn_zones')) {
            /*
             * 🔴 عوض‌شدنِ نیم‌سرورها یعنی NSِ apexِ **همهٔ** zoneها باید عوض شود.
             * فقط علامت می‌زنیم و کرون می‌فرستد — فرستادنِ همه در همین درخواست
             * با صدها zone از مهلتِ PHP می‌گذشت و نیمه‌کاره می‌ماند.
             * ⚠️ zoneهای فعال تفویضشان را نزدِ رجیستری به نام‌های **قبلی** دارند؛
             * تا مشتری‌ها نام‌ها را عوض نکنند، `dns:verify-pending` آنها را
             * «تفویض ناقص» نشان می‌دهد. این تغییر فقط پیش از اولین مشتری امن است.
             */
            CdnZone::query()->where('mode', 'ns')->update(['dirty' => true]);
            $msg .= ' نیم‌سرورها تغییر کرد؛ همهٔ zoneها برای همگام‌سازیِ دوباره علامت خوردند.';
        }

        return back()->with('ok', $msg);
    }

    public function test(): RedirectResponse
    {
        $res = $this->pdns->health();

        return back()->with('dns_health', $res);
    }

    public function reconcile(int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        $z->markDirty();
        $res = $this->zones->sync($z);

        return $res['ok']
            ? back()->with('ok', $z->domain.' با سرورِ DNS همگام شد.')
            : back()->withErrors(['dns' => $z->domain.': '.$res['reason']]);
    }

    public function reconcileAll(): RedirectResponse
    {
        /*
         * ⚠️ فقط zoneهای dirty، و در پس‌زمینه‌ی همین درخواست نه صف: تعدادشان
         * کم است. `--all` عمداً دکمه ندارد (با صدها zone از مهلتِ درخواست
         * می‌گذرد) — آن کارِ کرون یا خطِ فرمان است.
         */
        Artisan::call('dns:reconcile');

        return back()->with('ok', trim(Artisan::output()) ?: 'همگام‌سازی انجام شد.');
    }

    public function verify(int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        $res = $this->zones->verify($z);

        return back()->with('ok', $z->domain.': '.\App\Services\Dns\DelegationCheck::explain($res['reason'])
            .($res['found'] ? ' (دیده‌شده: '.implode('، ', $res['found']).')' : ''));
    }

    public function status(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zone($zone);
        $to = (string) $request->input('status');

        if (! in_array($to, ['active', 'suspended'], true)) {
            abort(422);
        }

        // رفعِ تعلیق به «فعال» فقط اگر قبلاً تأیید شده؛ وگرنه برمی‌گردد به «در انتظار»
        if ($to === 'active' && ! $z->verified_at) {
            $to = 'pending';
        }

        $z->forceFill(['status' => $to, 'dirty' => true])->save();
        CdnEvent::note('dns', $z->id, $to === 'suspended' ? 'تعلیق توسطِ مدیر' : 'رفعِ تعلیق توسطِ مدیر', [
            'admin' => auth()->id(),
        ]);

        $res = $this->zones->sync($z);

        return back()->with('ok', $z->domain.($to === 'suspended'
            ? ' معلق شد و از سرورِ DNS برداشته شد.'
            : ' دوباره فعال شد.').($res['ok'] ? '' : ' (همگام‌سازی ناموفق: '.$res['reason'].')'));
    }

    public function destroy(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zone($zone);

        if (CdnZone::normalizeDomain((string) $request->input('confirm')) !== $z->domain) {
            return back()->withErrors(['dns' => 'برای حذف، نامِ دامنه را دقیقاً وارد کنید.']);
        }

        $res = $this->zones->remove($z, 'حذف توسطِ مدیر');

        return $res['ok']
            ? back()->with('ok', $z->domain.' حذف شد.')
            : back()->withErrors(['dns' => $res['reason']]);
    }

    private function zone(int $id): CdnZone
    {
        abort_unless(Schema::hasTable('cdn_zones'), 404);

        return CdnZone::findOrFail($id);
    }
}
