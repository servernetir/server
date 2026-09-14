<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\InfraAsset;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\FleetScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ناوگانِ زیرساخت — یک صفحه که به سؤالِ «بابتِ چه چیزهایی پول می‌دهیم و کدامشان
 * درآمد ندارند» جواب می‌دهد.
 *
 * تفاوتش با `/admin/cloud/inventory` (که می‌ماند): آن یک **گزارشِ لحظه‌ای** است و
 * همان چهار دسته را نشان می‌دهد؛ این یک **ابزارِ مدیریت** است. یعنی جست‌وجو روی
 * کلِ ناوگان، فیلتر، مرتب‌سازی، بهایِ یورویی/تومانی، سنِ رهاشدگی و ضررِ انباشته،
 * طبقه‌بندیِ دستی برای اینکه هشدارها بی‌اعتبار نشوند، خروجیِ CSV، و دکمهٔ حذفِ
 * ماشینِ بی‌درآمد نزدِ زیرساخت.
 *
 * چرا هر دو می‌مانند: گزارشِ زنده جواب می‌دهد به «همین الان چه می‌بینی» — و در
 * لحظهٔ تصمیمِ حذف، تنها منبعی است که کهنه نیست. این صفحه جواب می‌دهد به «چه
 * چیزی از دستمان در رفته و از کِی».
 *
 * ⚠️ همهٔ صفحه از `infra_assets` می‌خوانَد، نه از زیرساخت. یعنی سریع است و
 * ممکن است کهنه باشد؛ برای همین «آخرین اسکن» همیشه بالای صفحه است و اگر کهنه
 * باشد قرمز می‌شود. صفحه‌ای که نمی‌گوید داده‌اش مالِ کِی است، بدتر از نبودنش است.
 */
class FleetController extends Controller
{
    public function __construct(
        private FleetScanner $scanner,
        private CloudManager $manager,
    ) {}

    /** بعد از چند دقیقه عکسِ ناوگان «کهنه» شمرده می‌شود */
    private const STALE_MINUTES = 180;

    /** ستون‌هایی که مرتب‌سازی رویشان مجاز است — فهرستِ سفید، چون از کوئری می‌آید */
    private const SORTABLE = [
        'idle'     => 'unlinked_since',
        'cost'     => 'cost_eur_cents',
        'name'     => 'name',
        'ip'       => 'ipv4',
        'created'  => 'provider_created_at',
        'seen'     => 'last_seen_at',
        'state'    => 'link_state',
        'provider' => 'provider',
    ];

    public function index(Request $request): View
    {
        if (! Schema::hasTable('infra_assets')) {
            // 🔴 هرگز ۵۰۰. دیپلویِ این پروژه فایل‌به‌فایل است و مهاجرت را مدیر
            // دستی می‌زند؛ پنجرهٔ «کد هست، جدول نیست» همیشه وجود دارد و صفحهٔ
            // سفید در آن پنجره یعنی مدیر فکر می‌کند قابلیت خراب است.
            return view('admin.fleet', ['needsMigration' => true] + $this->emptyView());
        }

        $filters = $this->filters($request);
        $query = $this->applyFilters(InfraAsset::query(), $filters);

        $this->applyOrder($query, $filters);

        $assets = $query
            // مرتب‌سازیِ دومِ پایدار: بی‌این، ردیف‌هایی با مقدارِ برابر بین
            // صفحه‌ها جابه‌جا می‌شوند و مدیر همان سرور را دو بار می‌بیند.
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $ids = $assets->getCollection();

        return view('admin.fleet', [
            'needsMigration' => false,
            'assets'    => $assets,
            'filters'   => $filters,
            'summary'   => $this->summary(),
            'byProvider' => $this->byProvider(),
            'services'  => $this->serviceIndex($ids),
            'customers' => $this->customerIndex($ids),
            'lastScan'  => FleetScanner::lastScan(),
            'stale'     => $this->isStale(),
            'providers' => $this->providerOptions(),
            'eurRate'   => $this->eurRate(),
            'realLabel' => fn (?string $p) => $this->manager->realLabel($p),
        ]);
    }

    /** ورودی‌های خالیِ ویو برای حالتِ «هنوز مهاجرت نخورده» */
    private function emptyView(): array
    {
        return [
            'assets'     => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50),
            'filters'    => $this->filters(request()),
            'summary'    => [],
            'byProvider' => [],
            'services'   => collect(),
            'customers'  => collect(),
            'lastScan'   => null,
            'stale'      => true,
            'providers'  => $this->providerOptions(),
            'eurRate'    => 0,
            'realLabel'  => fn (?string $p) => $this->manager->realLabel($p),
        ];
    }

    // ───────────────────────── فیلتر ─────────────────────────

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        return [
            'q'        => trim((string) $request->query('q', '')),
            'state'    => (string) $request->query('state', ''),
            'provider' => (string) $request->query('provider', ''),
            'role'     => (string) $request->query('role', ''),
            'status'   => (string) $request->query('status', ''),
            'todo'     => (string) $request->query('todo', ''),
            'sort'     => (string) $request->query('sort', ''),
            'dir'      => (string) $request->query('dir', ''),
        ];
    }

    private function applyFilters($query, array $f)
    {
        $query->search($f['q']);

        if (in_array($f['state'], [InfraAsset::STATE_ATTACHED, InfraAsset::STATE_ORPHAN,
            InfraAsset::STATE_ZOMBIE, InfraAsset::STATE_GHOST], true)) {
            $query->where('link_state', $f['state']);
        }

        if ($f['provider'] !== '' && isset(CloudManager::DRIVERS[$f['provider']])) {
            $query->where('provider', $f['provider']);
        }

        if ($f['role'] !== '' && isset(InfraAsset::ROLES[$f['role']])) {
            $query->where('role', $f['role']);
        }

        if ($f['status'] !== '') {
            $query->where('provider_status', $f['status']);
        }

        // «فقط آنهایی که تصمیم می‌خواهند» — همان فهرستی که مدیر صبح‌ها باز می‌کند
        if ($f['todo'] === '1') {
            $query->whereIn('link_state', InfraAsset::LEAKING_STATES)
                ->whereNull('acknowledged_at')
                ->whereNotIn('role', InfraAsset::OWNED_ROLES);
        }

        return $query;
    }

    /**
     * ترتیبِ ردیف‌ها.
     *
     * 🔴 پیش‌فرض عمداً یک `orderBy` ساده **نیست**. «قدیمی‌ترین رهاشده اول» با
     * `orderBy('unlinked_since','asc')` دقیقاً برعکس در می‌آید: ماشینِ متصل
     * `unlinked_since = NULL` دارد و NULL در صعودی **اول** می‌نشیند. یعنی صفحه
     * با ردیف‌های سالم شروع می‌شد و آن چند ماشینی که پول می‌سوزانند — کلِ دلیلِ
     * وجودِ این صفحه — می‌افتادند ته فهرست، شاید صفحهٔ دوم.
     *
     * پس اول بر اساسِ «آیا اصلاً بی‌صاحب است» گروه می‌شود و بعد بر اساسِ سن.
     * (`is null` در MariaDB و SQLite هر دو ۰/۱ می‌دهد و ۰ اول می‌آید.)
     */
    private function applyOrder($query, array $f): void
    {
        $col = self::SORTABLE[$f['sort']] ?? null;

        if ($col === null) {
            $query->orderByRaw('unlinked_since is null')->orderBy('unlinked_since');

            return;
        }

        $query->orderBy($col, $f['dir'] === 'asc' ? 'asc' : 'desc');
    }

    // ───────────────────────── آمار ─────────────────────────

    /**
     * اعدادِ بالای صفحه — روی **کلِ** ناوگان، نه روی فیلتر.
     *
     * ⚠️ عمداً مستقل از فیلتر: اگر کاشی‌ها با فیلتر عوض می‌شدند، مدیر بعد از یک
     * جست‌وجو عددِ «نشتیِ ماهانه» را کوچک می‌دید و نتیجه می‌گرفت مشکل حل شده.
     */
    private function summary(): array
    {
        $rows = InfraAsset::query()
            ->selectRaw('link_state, role, acknowledged_at is null as unseen, count(*) as n, sum(cost_eur_cents) as cost')
            ->groupBy('link_state', 'role', 'unseen')
            ->get();

        $out = [
            'total' => 0, 'attached' => 0, 'orphan' => 0, 'zombie' => 0, 'ghost' => 0,
            'live' => 0, 'monthly_cents' => 0, 'leak_cents' => 0, 'todo' => 0,
            'owned_cents' => 0, 'unpriced' => 0,
        ];

        foreach ($rows as $r) {
            $n = (int) $r->n;
            $cost = (int) $r->cost;
            $out['total'] += $n;
            $out[$r->link_state] = ($out[$r->link_state] ?? 0) + $n;

            if (in_array($r->link_state, InfraAsset::BILLABLE_STATES, true)) {
                $out['live'] += $n;
                $out['monthly_cents'] += $cost;
            }

            if (in_array($r->link_state, InfraAsset::LEAKING_STATES, true)) {
                if (in_array($r->role, InfraAsset::OWNED_ROLES, true)) {
                    $out['owned_cents'] += $cost;
                } else {
                    $out['leak_cents'] += $cost;

                    if ($r->unseen) {
                        $out['todo'] += $n;
                    }
                }
            }
        }

        // ماشین‌هایی که بهایشان را نمی‌دانیم — عددِ نشتی به همان اندازه کمتر از
        // واقع است. پنهان‌کردنِ این عدد یعنی گزارشِ خوش‌بینانه.
        $out['unpriced'] = InfraAsset::whereIn('link_state', InfraAsset::BILLABLE_STATES)
            ->where('cost_eur_cents', 0)->count();

        // پولی که تا امروز بابتِ ماشین‌های رهاشده داده‌ایم
        $out['wasted_cents'] = (int) InfraAsset::query()
            ->whereIn('link_state', InfraAsset::LEAKING_STATES)
            ->whereNotIn('role', InfraAsset::OWNED_ROLES)
            ->whereNotNull('unlinked_since')
            ->get(['cost_eur_cents', 'unlinked_since'])
            ->sum(fn (InfraAsset $a) => $a->wastedEurCents());

        return $out;
    }

    /** شکستِ ناوگان به تفکیکِ زیرساخت — «کجا چند تا داریم و چقدر می‌دهیم» */
    private function byProvider(): array
    {
        return InfraAsset::query()
            ->selectRaw('provider, count(*) as n, sum(cost_eur_cents) as cost')
            ->selectRaw("sum(case when link_state in ('orphan','zombie') then 1 else 0 end) as leaking")
            ->whereIn('link_state', InfraAsset::BILLABLE_STATES)
            ->groupBy('provider')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'label'    => $this->manager->realLabel($r->provider),
                'n'        => (int) $r->n,
                'leaking'  => (int) $r->leaking,
                'cost'     => (int) $r->cost,
            ])->all();
    }

    /** @return array<string,string> slug → نامِ واقعی */
    private function providerOptions(): array
    {
        $out = [];

        foreach (array_keys(CloudManager::DRIVERS) as $slug) {
            $out[$slug] = $this->manager->realLabel($slug);
        }

        return $out;
    }

    /**
     * نرخِ یورو برای نمایشِ تومانی — **بی‌هیچ تماسِ شبکه‌ای**.
     *
     * 🔴 چرا `cloud_eur_rate()` نه: زنجیره‌اش به `ExchangeRate::toToman()`
     * می‌رسد و آن روی کشِ سرد **خودش می‌رود اینترنت**. یعنی صفحه‌ای که کلِ
     * ادعایش «از دفتر می‌خوانَد پس سریع است» می‌شد، گاهی پشتِ یک اسکرپِ زنده
     * می‌ماند — و اگر منبعِ نرخ پایین بود، پشتِ تایم‌اوتش.
     *
     * `current()` فقط کش و نرخِ پایدارِ ذخیره‌شده را می‌خوانَد. اگر هیچ‌کدام
     * نبود صفر برمی‌گردد و ویو فقط یورو نشان می‌دهد — که صادقانه است، چون
     * عددِ تومانیِ ساخته‌شده با نرخِ ناموجود از نبودش بدتر است.
     */
    private function eurRate(): int
    {
        $override = (int) \App\Models\Setting::get('pricing_rate_override', '0');

        if ($override > 0) {
            return $override;
        }

        try {
            return (int) (app(\App\Services\ExchangeRate::class)->current('EUR')['rate_toman'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function isStale(): bool
    {
        $last = FleetScanner::lastScan();

        if ($last === null) {
            return true;
        }

        try {
            return \Illuminate\Support\Carbon::parse($last['at'])->lt(now()->subMinutes(self::STALE_MINUTES));
        } catch (\Throwable) {
            return true;
        }
    }

    /** نامِ سرویس‌ها و مشتری‌های همین صفحه — یک پرس‌وجو، نه یکی به‌ازای هر ردیف */
    private function serviceIndex($assets)
    {
        return Service::query()
            ->whereIn('id', $assets->pluck('service_id')->filter()->unique())
            ->get(['id', 'name', 'status', 'price', 'currency_code', 'next_due_at'])
            ->keyBy('id');
    }

    private function customerIndex($assets)
    {
        return Customer::query()
            ->whereIn('id', $assets->pluck('customer_id')->filter()->unique())
            ->get(['id', 'code', 'email', 'phone'])
            ->keyBy('id');
    }

    // ───────────────────────── کنش‌ها ─────────────────────────

    /**
     * اسکنِ زنده. عمداً POST و دستی: هر اجرا چند تماسِ صفحه‌بندی‌شده با همهٔ
     * زیرساخت‌هاست و اگر روی هر بازکردنِ صفحه می‌دوید، یک بار رفرشِ پیاپیِ مدیر
     * می‌توانست ما را به سقفِ نرخِ API برساند.
     */
    public function scan(Request $request): RedirectResponse
    {
        if (! Schema::hasTable('infra_assets')) {
            return back()->withErrors('جدولِ ناوگان هنوز ساخته نشده؛ اول مهاجرت‌ها را اجرا کنید.');
        }

        $res = $this->scanner->scan();

        $msg = 'اسکن انجام شد: '.fa_num($res['seen']).' ماشین دیده شد ('
            .fa_num($res['counts'][InfraAsset::STATE_ORPHAN] ?? 0).' بی‌صاحب، '
            .fa_num($res['counts'][InfraAsset::STATE_ZOMBIE] ?? 0).' سرویسِ بسته، '
            .fa_num($res['counts'][InfraAsset::STATE_GHOST] ?? 0).' ناپدید).';

        if ($res['errors'] !== []) {
            $names = array_map(fn ($s) => $this->manager->realLabel($s), array_keys($res['errors']));

            return back()->with('ok', $msg)->withErrors(
                'این زیرساخت‌ها پاسخ ندادند و ردیف‌هایشان **دست‌نخورده** ماند: '
                .implode('، ', $names).' — تا رفعِ این خطا، «بی‌صاحب ندارید» را باور نکنید.'
            );
        }

        return back()->with('ok', $msg);
    }

    /**
     * طبقه‌بندی و یادداشتِ مدیر روی یک ماشین.
     *
     * ⚠️ اعتبارسنجیِ صریح و نه `$request->validate()`: مسیرهای `/admin` در این
     * پروژه JSON برنمی‌گردانند و خطای اعتبارسنجی به‌صورتِ ریدایرکتِ HTML درمی‌آید؛
     * پس پیام‌ها باید خودمان ساخته و برگردانده شوند.
     */
    public function annotate(Request $request, int $asset): RedirectResponse
    {
        $row = InfraAsset::findOrFail($asset);

        $role = (string) $request->input('role', $row->role);

        if (! isset(InfraAsset::ROLES[$role])) {
            return back()->withErrors('نقشِ نامعتبر.');
        }

        $row->role = $role;
        $row->note = mb_substr(trim((string) $request->input('note', '')), 0, 500) ?: null;

        if ($request->boolean('ack')) {
            $row->acknowledged_at = now();
            $row->acknowledged_by = $request->user()?->id;
        } elseif ($request->has('ack')) {
            $row->acknowledged_at = null;
            $row->acknowledged_by = null;
        }

        $row->save();

        return back()->with('ok', 'ثبت شد.');
    }

    /**
     * حذفِ واقعیِ ماشین نزدِ زیرساخت.
     *
     * 🔴 برگشت‌ناپذیر و پاک‌کنندهٔ داده. سه قفل دارد و هیچ‌کدام تشریفاتی نیست:
     *
     *  ۱. فقط ماشینِ بی‌درآمد (`orphan`/`zombie`). ماشینِ متصل به سرویسِ زنده از
     *     این‌جا حذف نمی‌شود؛ راهِ درستش خاتمهٔ سرویس است که صورت‌حساب را هم
     *     می‌بندد.
     *  ۲. مدیر باید **نامِ دقیقِ** ماشین را تایپ کند. تأییدِ «بله/خیر» روی صفحه‌ای
     *     که ۸۰ ردیف دارد، دیر یا زود روی ردیفِ اشتباه زده می‌شود.
     *  ۳. اگر ردیفِ `cloud_instances`ای به این ماشین وصل باشد، حذف رد می‌شود —
     *     یعنی هنوز جایی در سامانه ادعایش می‌کند و باید اول آن‌جا بسته شود.
     *
     * بعد از حذف، ردیف همان‌جا **پاک نمی‌شود** بلکه به `ghost` می‌رود تا مدیر
     * نتیجهٔ کلیکش را ببیند. ولی این ماندن موقتی است: اسکنِ بعدی ردیفی را که نه
     * نزدِ زیرساخت است و نه سرویسی ادعایش می‌کند از دفتر بیرون می‌برد — و درست
     * هم همین است، چون دفتر یعنی «بابتِ چه چیزهایی پول می‌دهیم».
     *
     * 🔴 پس ردِ ماندگارِ حذف، `ActivityLog` است نه این ردیف. اگر روزی کسی
     * پرسید «این ماشین کجا رفت؟»، جوابش آن‌جاست.
     */
    public function release(Request $request, int $asset): RedirectResponse
    {
        $row = InfraAsset::findOrFail($asset);

        if (! in_array($row->link_state, InfraAsset::LEAKING_STATES, true)) {
            return back()->withErrors(
                'فقط ماشینِ بی‌صاحب یا ماشینی که سرویسش بسته شده از این‌جا حذف می‌شود. '
                .'برای ماشینِ متصل، سرویسش را خاتمه دهید تا صورت‌حساب هم بسته شود.'
            );
        }

        $typed = trim((string) $request->input('confirm', ''));

        if ($typed === '' || $typed !== (string) $row->name) {
            return back()->withErrors(
                'برای حذف باید نامِ دقیقِ ماشین را تایپ کنید: «'.$row->name.'»'
            );
        }

        $claimed = CloudInstance::where('provider', $row->provider)
            ->where('provider_ref', $row->provider_ref)
            ->first();

        if ($claimed !== null && $claimed->service?->isDead() === false) {
            return back()->withErrors(
                'این ماشین هنوز به سرویسِ زندهٔ شمارهٔ '.$claimed->service_id.' وصل است.'
            );
        }

        $driver = $this->manager->driver((string) $row->provider);

        if ($driver === null || ! $driver->isConfigured()) {
            return back()->withErrors('توکنِ این زیرساخت تنظیم نیست؛ حذف ممکن نیست.');
        }

        $res = $driver->deleteServer((string) $row->provider_ref);

        if (! ($res['ok'] ?? false)) {
            return back()->withErrors('زیرساخت حذف را نپذیرفت: '.($res['message'] ?? 'پاسخی نیامد'));
        }

        $row->link_state = InfraAsset::STATE_GHOST;
        $row->provider_status = 'deleted';
        $row->missing_since = now();
        $row->acknowledged_at = now();
        $row->acknowledged_by = $request->user()?->id;
        $row->note = trim((string) $row->note."\n".'حذف‌شده توسط مدیر در '.sdate(now(), true));
        $row->save();

        ActivityLog::record(
            $row->customer_id,
            'cloud_delete',
            'ماشینِ «'.$row->name.'» ('.($row->ipv4 ?: 'بی‌آی‌پی').') نزدِ '
                .$this->manager->realLabel($row->provider).' از صفحهٔ ناوگان توسط مدیر حذف شد. '
                .'پیش از حذف: '.$row->stateLabel().'، '.fa_num((int) $row->idleDays()).' روز بی‌صاحب.',
            $request,
            'staff',
            $row->service_id,
        );

        return back()->with('ok', 'ماشین نزدِ زیرساخت حذف شد. از این پس هزینه‌ای بابتش نمی‌دهیم.');
    }

    /**
     * خروجیِ CSV از **همان فیلتری** که روی صفحه است.
     *
     * ⚠️ BOM لازم است: بی‌آن، اکسلِ ویندوز فارسی را مربع نشان می‌دهد و فایل
     * عملاً بی‌استفاده است.
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless(Schema::hasTable('infra_assets'), 404);

        $filters = $this->filters($request);
        $query = $this->applyFilters(InfraAsset::query(), $filters);
        $this->applyOrder($query, $filters);
        $query->orderBy('id');

        $name = 'fleet-'.now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'زیرساخت', 'شناسه نزد زیرساخت', 'نام ماشین', 'IPv4', 'IPv6', 'پلن', 'مکان',
                'وضعیت ماشین', 'وضعیت اتصال', 'سرویس', 'وضعیت سرویس', 'مشتری',
                'هزینه ماهانه (یورو)', 'مبنای هزینه', 'روزهای بی‌صاحبی', 'ضرر تا امروز (یورو)',
                'نقش', 'تأییدشده', 'یادداشت', 'ساخت نزد زیرساخت', 'آخرین رؤیت',
            ]);

            $query->chunk(300, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $this->manager->realLabel($a->provider),
                        $a->provider_ref, $a->name, $a->ipv4, $a->ipv6, $a->plan_ref, $a->location_ref,
                        $a->provider_status, $a->stateLabel(),
                        $a->service_id, $a->service_status, $a->customer_id,
                        number_format($a->cost_eur_cents / 100, 2), $a->cost_source,
                        $a->idleDays(), number_format($a->wastedEurCents() / 100, 2),
                        InfraAsset::ROLES[$a->role] ?? $a->role,
                        $a->acknowledged_at ? 'بله' : 'خیر',
                        str_replace(["\r", "\n"], ' ', (string) $a->note),
                        $a->provider_created_at?->toDateString(),
                        $a->last_seen_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
