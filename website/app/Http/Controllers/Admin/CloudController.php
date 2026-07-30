<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * پنلِ مدیریتِ زیرساختِ ابری — آزمونِ اتصال، همگام‌سازی و نمایِ کاتالوگ.
 *
 * ⚠️ این تنها جایی در کلِ برنامه است که نامِ ارائه‌دهنده به چشمِ انسان می‌رسد، و
 * فقط برای **مدیر**. هر ویویی که مشتری می‌بیند باید از `CloudPlan::offers()`
 * بخواند که ستونِ `provider` را پنهان می‌کند.
 */
class CloudController extends Controller
{
    public function __construct(private CloudManager $manager) {}

    /**
     * فیلترها و مرتب‌سازیِ مجاز.
     *
     * ⚠️ **فهرستِ سفید** و نه ورودیِ آزاد: نامِ ستون از query می‌آید و اگر
     * مستقیم به `orderBy` برود، هر رشته‌ای قابلِ تزریق است. این‌جا فقط همین
     * کلیدها پذیرفته می‌شوند.
     */
    private const SORTS = [
        'price'    => ['price_irt', 'asc'],
        'price_d'  => ['price_irt', 'desc'],
        'cost'     => ['cost_eur_cents', 'asc'],
        'cost_d'   => ['cost_eur_cents', 'desc'],
        'cpu'      => ['vcpu', 'asc'],
        'cpu_d'    => ['vcpu', 'desc'],
        'ram'      => ['ram_mb', 'asc'],
        'ram_d'    => ['ram_mb', 'desc'],
        'name'     => ['public_name', 'asc'],
    ];

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('cloud_plans');

        $providers = [];
        foreach ($this->manager->all() as $slug => $driver) {
            $providers[$slug] = [
                'configured' => $driver->isConfigured(),
                'plans'      => $ready ? CloudPlan::where('provider', $slug)->where('is_active', true)->count() : 0,
                'caps'       => $driver->capabilities(),
            ];
        }

        // ── فیلترها ──
        // این جدول با ۱۱۴ پلن غیرقابلِ استفاده شده بود. فیلتر روی **ردیف‌های
        // خام** است نه عرضه‌ها، چون مدیر باید ردیفِ هر زیرساخت را جدا ببیند و
        // بتواند خاموشش کند — چیزی که در نمای «عرضه» پنهان می‌شود.
        $f = [
            'provider' => (string) $request->query('provider', ''),
            'country'  => strtoupper((string) $request->query('country', '')),
            'cpu'      => (string) $request->query('cpu', ''),
            'state'    => (string) $request->query('state', ''),
            'q'        => trim((string) $request->query('q', '')),
            'sort'     => (string) $request->query('sort', 'price'),
        ];

        $rows = collect();

        if ($ready) {
            [$col, $dir] = self::SORTS[$f['sort']] ?? self::SORTS['price'];

            $rows = CloudPlan::query()
                ->when($f['provider'] !== '', fn ($q) => $q->where('provider', $f['provider']))
                ->when($f['cpu'] !== '', fn ($q) => $q->where('cpu_kind', $f['cpu']))
                ->when($f['q'] !== '', fn ($q) => $q->where(function ($qq) use ($f) {
                    $qq->where('public_name', 'like', '%'.$f['q'].'%')
                        ->orWhere('slug', 'like', '%'.$f['q'].'%');
                }))
                ->when($f['country'] !== '', fn ($q) => $q->whereIn(
                    'location_code',
                    CloudLocation::where('country', $f['country'])->pluck('code')
                ))
                ->when($f['state'] === 'off', fn ($q) => $q->where('admin_disabled', true))
                ->when($f['state'] === 'on', fn ($q) => $q->where('admin_disabled', false))
                ->when($f['state'] === 'oos', fn ($q) => $q->where('in_stock', false))
                ->when($f['state'] === 'noprice', fn ($q) => $q->where('price_irt', 0))
                ->orderBy($col, $dir)
                ->limit(400)
                ->get();
        }

        // عرضه‌های عمومی: همان چیزی که مشتری می‌بیند — برای اینکه مدیر بتواند
        // چشمی بررسی کند که سفیدبرچسبی درست کار می‌کند و پلنِ تکراری نیست.
        $offers = $ready ? CloudPlan::offers() : collect();

        return view('admin.cloud', [
            'notReady'  => ! $ready,
            'providers' => $providers,
            // «کدام زیرساخت کجاست» — سؤالِ روزمرهٔ مدیر، حالا در خودِ صفحه
            'byProvider' => $ready ? $this->manager->locationsByProvider() : [],
            // پلن‌هایی که بهایشان گران شده و سرویسِ فعال رویشان است
            'risen' => $ready ? CloudPlan::query()
                ->whereNotNull('previous_cost_eur_cents')
                ->whereColumn('cost_eur_cents', '>', 'previous_cost_eur_cents')
                ->orderByDesc('cost_changed_at')
                ->limit(20)->get() : collect(),
            // کشورهایی که می‌فروشیم ولی صفحهٔ بازاریابی ندارند (شکافِ سئویی)
            'noPage' => $ready ? \App\Services\Cloud\CloudCountry::withoutMarketingPage() : [],
            'offers'    => $offers,
            'locations' => $ready
                ? CloudLocation::orderBy('country')->orderBy('city')->get()
                : collect(),
            'planCount' => $ready ? CloudPlan::where('is_active', true)->count() : 0,
            'rows'      => $rows,
            'f'         => $f,
            'sorts'     => array_keys(self::SORTS),
            'countries' => $ready
                ? CloudLocation::whereIn('code', CloudPlan::distinct()->pluck('location_code'))
                    ->get()->groupBy('country')->map->first()->sortBy('country')
                : collect(),
            'offProviders' => CloudPlan::disabledProviders(),
        ]);
    }

    /** آزمونِ اتصال به همهٔ ارائه‌دهندگانِ تنظیم‌شده */
    public function test(): RedirectResponse
    {
        $configured = $this->manager->configured();

        if ($configured === []) {
            return back()->with('err', 'هیچ توکنی ذخیره نشده — اول توکن را وارد و ذخیره کنید.');
        }

        $lines = [];
        $i = 0;

        foreach ($configured as $driver) {
            $i++;
            $r = $driver->testConnection();
            // در پیامِ مدیر هم نامِ ارائه‌دهنده را نمی‌نویسیم؛ «زیرساختِ ۱/۲»
            // همان ترتیبی است که در فرمِ تنظیمات دیده.
            $lines[] = ($r['ok'] ? '✅' : '❌')." زیرساختِ {$i}: ".$r['message'];
        }

        return back()->with($lines === [] ? 'err' : 'ok', implode(' | ', $lines));
    }

    /** همگام‌سازیِ کاتالوگ (یا فقط بازمحاسبهٔ قیمت) */
    public function sync(Request $request, CloudCatalogSync $sync): RedirectResponse
    {
        if (! Schema::hasTable('cloud_plans')) {
            return back()->with('err', 'جدول‌های ابری ساخته نشده‌اند — اول مهاجرت را اجرا کنید.');
        }

        if ($request->boolean('prices_only')) {
            $n = $sync->reprice();

            return back()->with('ok', "قیمتِ {$n} پلن با نرخِ روزِ یورو بازمحاسبه شد.");
        }

        $report = $sync->sync();

        if (isset($report['message'])) {
            return back()->with('err', $report['message']);
        }

        if ($report['providers'] === []) {
            return back()->with('err', 'هیچ زیرساختی تنظیم نشده است.');
        }

        $lines = [];
        $i = 0;

        $sanity = $report['providers']['__sanity'] ?? null;
        $costAlert = $report['providers']['__cost'] ?? null;
        unset($report['providers']['__sanity'], $report['providers']['__cost']);

        foreach ($report['providers'] as $r) {
            $i++;
            if (! $r['ok']) {
                $lines[] = "زیرساختِ {$i}: خطا — {$r['message']}";

                continue;
            }

            $line = "زیرساختِ {$i}: {$r['plans']} پلن، {$r['locations']} مکان، {$r['images']} سیستم‌عامل";

            // توضیحِ «چرا صفر» — اگر درایور دلیلی داشت، همان‌جا نشان بده
            if (filled($r['message'] ?? null)) {
                $line .= ' ⚠️ '.$r['message'];
            }

            $lines[] = $line;
        }

        // گران‌شدنِ بها اولِ همه — چون ضررِ در جریان است، نه یک تذکر
        if (is_string($costAlert) && $costAlert !== '') {
            array_unshift($lines, $costAlert);
        }

        // دامِ «واحدِ قیمت اشتباه»
        if (is_string($sanity) && $sanity !== '') {
            $lines[] = $sanity;
        }

        if (($report['rate'] ?? 0) <= 0) {
            $lines[] = '⚠️ نرخِ یورو در دسترس نبود، پس قیمتِ تومانی ساخته نشد و پلن‌ها در فروشگاه نمی‌آیند.';
        }

        return back()->with('ok', implode(' | ', $lines));
    }

    /**
     * خاموش/روشنِ یک پلن.
     *
     * روی `admin_disabled` می‌نویسد و **نه** `is_active` — وگرنه اجرای بعدیِ
     * کرون تصمیم را بی‌صدا برمی‌گرداند.
     */
    public function togglePlan(Request $request, int $plan): RedirectResponse
    {
        $row = CloudPlan::find($plan);

        if ($row === null) {
            return back()->with('err', 'پلن پیدا نشد.');
        }

        $off = ! $row->admin_disabled;

        $row->update([
            'admin_disabled' => $off,
            'admin_note'     => $off ? mb_substr(trim((string) $request->input('note', '')), 0, 180) : null,
        ]);

        $this->afterChange();

        return back()->with('ok', $off
            ? 'پلن «'.$row->public_name.'» بسته شد و در فروشگاه نمایش داده نمی‌شود.'
            : 'پلن «'.$row->public_name.'» باز شد.');
    }

    /** خاموش/روشنِ یک مکان — همهٔ پلن‌هایش با هم */
    public function toggleLocation(string $code): RedirectResponse
    {
        $loc = CloudLocation::where('code', $code)->first();

        if ($loc === null) {
            return back()->with('err', 'مکان پیدا نشد.');
        }

        $loc->update(['is_active' => ! $loc->is_active]);
        $this->afterChange();

        return back()->with('ok', $loc->label('fa').($loc->is_active ? ' باز شد.' : ' بسته شد.'));
    }

    /**
     * خاموش/روشنِ یک **کشور** — همهٔ مکان‌هایش.
     *
     * چرا لازم است: «آلمان را نفروش» یک تصمیمِ واحد است، ولی آلمان چند شهر
     * دارد. بی‌این، مدیر باید یکی‌یکی ببندد و یکی جا می‌افتد.
     */
    public function toggleCountry(string $iso): RedirectResponse
    {
        $iso = strtoupper($iso);
        $locs = CloudLocation::where('country', $iso)->get();

        if ($locs->isEmpty()) {
            return back()->with('err', 'کشور پیدا نشد.');
        }

        // اگر همه باز است ⇒ همه را ببند؛ وگرنه همه را باز کن
        $allOn = $locs->every(fn ($l) => (bool) $l->is_active);

        CloudLocation::where('country', $iso)->update(['is_active' => ! $allOn]);
        $this->afterChange();

        $name = CloudLocation::COUNTRIES[$iso]['fa'] ?? $iso;

        return back()->with('ok', $name.($allOn ? ' بسته شد ('.fa_num($locs->count()).' مکان).' : ' باز شد.'));
    }

    /** خاموش/روشنِ یک زیرساخت */
    public function toggleProvider(string $provider): RedirectResponse
    {
        if (! array_key_exists($provider, CloudManager::DRIVERS)) {
            return back()->with('err', 'زیرساخت ناشناخته.');
        }

        $off = ! CloudPlan::providerIsDisabled($provider);
        CloudPlan::setProviderDisabled($provider, $off);
        $this->afterChange();

        return back()->with('ok', $this->manager->realLabel($provider)
            .($off ? ' خاموش شد — پلن‌هایش فروخته نمی‌شوند.' : ' روشن شد.'));
    }

    /**
     * بعد از هر تغییرِ دسترسی، کشِ منو و کشورها باید دور ریخته شود.
     *
     * بی‌این، پکیجی که بسته‌اید تا ۱۰ دقیقه در منو و صفحات می‌مانَد و مدیر فکر
     * می‌کند دکمه کار نکرده.
     */
    private function afterChange(): void
    {
        \App\Services\SiteMenu::forget();
        \App\Services\Cloud\CloudCountry::forget();
    }

    /**
     * ساختارِ خامِ پاسخِ زیرساختِ دوم — ابزارِ عیب‌یابی.
     *
     * چرا هست: داکیومنتِ آن ارائه‌دهنده نمونهٔ کاملِ JSON نداشت، پس نگاشتِ
     * فیلدها بخشی حدسی است. با این خروجی می‌شود دقیقش کرد.
     */
    public function probe(): View
    {
        $driver = $this->manager->driver('aeza');

        $data = ($driver instanceof \App\Services\Cloud\AezaClient && $driver->isConfigured())
            ? $driver->rawProbe()
            : ['error' => 'توکنِ زیرساختِ ۲ تنظیم نشده است.'];

        return view('admin.cloud-probe', ['data' => $data]);
    }
}
