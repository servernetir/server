<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CdnEvent;
use App\Models\CdnZone;
use App\Services\Dns\DelegationCheck;
use App\Services\Dns\ZoneImporter;
use App\Services\Dns\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * پنلِ مشتری — DNSِ مدیریت‌شده.
 *
 * ⚠️ هر متدی که به یک zone دست می‌زند از `zoneOf()` می‌گذرد. شرطِ مالکیت
 * **یک جا** است؛ تکرارش در ده متد یعنی روزی یکی جا می‌مانَد و آن یکی
 * رکوردهای DNSِ مشتریِ دیگر را ویرایش‌پذیر می‌کند.
 *
 * ⚠️ کنترلر نازک است: همهٔ قاعده‌ها در `ZoneService` و `RecordValidator`اند
 * تا پنلِ مدیریت، کرون و (بعداً) API یک رفتار داشته باشند.
 */
class DnsController extends Controller
{
    public function __construct(private readonly ZoneService $zones) {}

    public function index(): View
    {
        $customer = Auth::guard('customer')->user();
        // «آماده» یعنی هم جدول هست و هم سرورِ DNS واقعاً وصل است — CdnZone::serviceOpen()
        $ready = Schema::hasTable('cdn_zones') && CdnZone::serviceOpen();

        // 🔴 روی سرورِ مهاجرت‌نخورده صفحه باید بالا بیاید و علت را بگوید، نه ۵۰۰.
        $list = $ready
            ? CdnZone::forCustomer($customer->id)->withCount('records')->orderByDesc('id')->get()
            : collect();

        $claim = session('dns_claim');

        return view('account.dns', AccountController::shell('dns') + [
            'zones'       => $list,
            'ready'       => $ready,
            'nameservers' => CdnZone::ourNameservers(),
            'claim'       => $claim ? $this->zones->claimRecord($customer, $claim) + ['domain' => $claim] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(CdnZone::serviceOpen(), 404);

        $request->validate(['domain' => ['required', 'string', 'max:253']]);

        $res = $this->zones->create(Auth::guard('customer')->user(), (string) $request->input('domain'));

        if ($res['claim']) {
            /*
             * دامنه در انتظارِ حسابِ دیگری است (بی‌اثباتِ مالکیت). به‌جای
             * «ثبت شده»ِ بن‌بست، راهِ اثبات را نشان می‌دهیم: TXT روی DNSِ فعلی.
             */
            return redirect()->to(lroute('account.dns'))
                ->with('dns_claim', CdnZone::normalizeDomain((string) $request->input('domain')))
                ->withInput();
        }

        if (! $res['ok']) {
            return back()->withErrors(['domain' => $res['error']])->withInput();
        }

        return redirect()->to(lroute('account.dns.zone', $res['zone']->id).'?import=1')
            ->with('ok', 'دامنه اضافه شد. رکوردهای فعلی‌اش را بررسی و وارد کنید، سپس نیم‌سرورها را عوض کنید.');
    }

    public function claim(Request $request): RedirectResponse
    {
        abort_unless(CdnZone::serviceOpen(), 404);

        $request->validate(['domain' => ['required', 'string', 'max:253']]);
        $domain = (string) $request->input('domain');

        $res = $this->zones->claim(Auth::guard('customer')->user(), $domain);

        if (! $res['ok']) {
            return redirect()->to(lroute('account.dns'))
                ->with('dns_claim', CdnZone::normalizeDomain($domain))
                ->withErrors(['claim' => $res['error']]);
        }

        return redirect()->to(lroute('account.dns.zone', $res['zone']->id).'?import=1')
            ->with('ok', 'مالکیت تأیید شد و دامنه به حسابِ شما منتقل شد.');
    }

    public function show(Request $request, int $zone): View
    {
        $z = $this->zoneOf($zone);
        $records = $z->records()->orderByRaw("CASE type WHEN 'A' THEN 1 WHEN 'AAAA' THEN 2 WHEN 'CNAME' THEN 3 WHEN 'MX' THEN 4 WHEN 'TXT' THEN 5 WHEN 'SRV' THEN 6 WHEN 'CAA' THEN 7 ELSE 8 END")
            ->orderBy('name')->orderBy('prio')->get();

        /*
         * پیش‌نمایشِ واردکردن فقط وقتی کاربر خواسته (`?import=1`). گرفتنِ
         * خودکارش روی هر بازدید یعنی ۳۰+ استعلامِ DNS در هر رفرش — صفحه‌ای
         * که چند ثانیه می‌ایستد بی‌آنکه کاربر کاری خواسته باشد.
         */
        $import = null;
        if ($request->boolean('import') && ! $z->verified_at) {
            $import = app(ZoneImporter::class)->preview($z->domain);

            // آنچه از قبل هست پیشنهاد نمی‌شود
            $have = $records->map(fn ($r) => $r->type.'|'.$r->shortName($z->domain).'|'.mb_strtolower($r->content))->all();
            $import['records'] = array_values(array_filter(
                $import['records'] ?? [],
                fn ($r) => ! in_array($r['type'].'|'.$r['name'].'|'.mb_strtolower((string) $r['content']), $have, true)
            ));
        }

        return view('account.dns-zone', AccountController::shell('dns') + [
            'zone'    => $z,
            'records' => $records,
            'import'  => $import,
            'events'  => CdnEvent::where('cdn_zone_id', $z->id)->orderByDesc('id')->limit(15)->get(),
            'types'   => (array) config('edge.record_types', []),
            'ttls'    => (array) config('edge.ttl_choices', []),
        ]);
    }

    /* ───────────────────────── تأیید و ورود ───────────────────────── */

    public function verify(int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $res = $this->zones->verify($z);

        if ($res['ok']) {
            return back()->with('ok', 'نیم‌سرورها درست تنظیم شده‌اند. دامنه فعال است و از سرورهای DNSِ سرورنت پاسخ می‌گیرد.');
        }

        return back()->withErrors(['verify' => DelegationCheck::explain($res['reason'])]);
    }

    public function import(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);

        // فقط ردیف‌های تیک‌خورده؛ `pick` کلیدِ ردیف است و `rows` دادهٔ دیده‌شده
        $rows = (array) $request->input('rows', []);
        $picked = array_intersect_key($rows, array_flip((array) $request->input('pick', [])));

        if ($picked === []) {
            return redirect()->to(lroute('account.dns.zone', $z->id))->withErrors(['import' => 'هیچ رکوردی انتخاب نشده بود.']);
        }

        $res = $this->zones->import($z, array_values($picked));

        $msg = fa_num($res['added']).' رکورد وارد شد.';
        if ($res['skipped'] !== []) {
            $msg .= ' '.fa_num(count($res['skipped'])).' ردیف نامعتبر یا تکراری بود و وارد نشد.';
        }

        return redirect()->to(lroute('account.dns.zone', $z->id))->with('ok', $msg);
    }

    /* ───────────────────────── رکوردها ───────────────────────── */

    public function storeRecord(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $res = $this->zones->addRecord($z, $this->recordInput($request));

        if (! $res['ok']) {
            return back()->withErrors($res['errors'])->withInput()->with('dns_form', 'new');
        }

        return back()->with('ok', $this->savedMessage('رکورد اضافه شد.', $res['sync']));
    }

    public function updateRecord(Request $request, int $zone, int $record): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $rec = $z->records()->whereKey($record)->firstOrFail();

        $res = $this->zones->updateRecord($z, $rec, $this->recordInput($request, $rec->type));

        if (! $res['ok']) {
            return back()->withErrors($res['errors'])->withInput()->with('dns_form', 'edit-'.$rec->id);
        }

        return back()->with('ok', $this->savedMessage('رکورد به‌روز شد.', $res['sync']));
    }

    public function deleteRecord(int $zone, int $record): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $rec = $z->records()->whereKey($record)->firstOrFail();

        $res = $this->zones->deleteRecord($z, $rec);

        return back()->with('ok', $this->savedMessage('رکورد حذف شد.', $res['sync']));
    }

    public function toggleRecord(int $zone, int $record): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $rec = $z->records()->whereKey($record)->firstOrFail();

        $res = $this->zones->toggleRecord($z, $rec);

        return back()->with('ok', $this->savedMessage($rec->disabled ? 'رکورد غیرفعال شد.' : 'رکورد فعال شد.', $res['sync']));
    }

    /* ───────────────────────── خروجی و حذف ───────────────────────── */

    public function export(int $zone): Response
    {
        $z = $this->zoneOf($zone);

        return response($this->zones->exportBind($z), 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$z->domain.'.zone"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function destroy(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);

        /*
         * 🔴 تأییدِ تایپی، نه فقط confirm()ِ جاوااسکریپت. حذفِ zoneِ فعال یعنی
         * سایت و ایمیلِ مشتری همان لحظه SERVFAIL می‌گیرند — اگر نیم‌سرورها
         * هنوز به ما اشاره کنند. یک کلیکِ اشتباه نباید بتواند این کار را بکند.
         */
        if (CdnZone::normalizeDomain((string) $request->input('confirm')) !== $z->domain) {
            return back()->withErrors(['delete' => 'برای حذف، نامِ دامنه را دقیقاً وارد کنید.']);
        }

        $res = $this->zones->remove($z);

        if (! $res['ok']) {
            return back()->withErrors(['delete' => $res['reason']]);
        }

        return redirect()->to(lroute('account.dns'))->with('ok', 'دامنهٔ '.$z->domain.' و همهٔ رکوردهایش حذف شد.');
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    /** مالکیت — تنها جایی که این شرط نوشته می‌شود */
    private function zoneOf(int $id): CdnZone
    {
        abort_unless(Schema::hasTable('cdn_zones'), 404);

        return CdnZone::forCustomer(Auth::guard('customer')->id())->where('mode', 'ns')->findOrFail($id);
    }

    private function recordInput(Request $request, ?string $type = null): array
    {
        $in = $request->only(['type', 'name', 'content', 'ttl', 'prio', 'weight', 'port', 'target', 'flags', 'tag', 'value', 'comment']);

        // TXT فیلدِ جدای خودش را دارد (textarea) — partialِ فرم را ببین
        if (strtoupper((string) ($type ?? $in['type'] ?? '')) === 'TXT') {
            $in['content'] = (string) $request->input('content_txt', $in['content'] ?? '');
        }

        return $in;
    }

    /**
     * پیامِ ذخیره — صادق دربارهٔ همگام‌سازی.
     *
     * ⚠️ «ذخیره شد» وقتی سرورِ DNS نگرفته دروغ است. رکورد در دیتابیس هست و
     * `dns:reconcile` بعداً می‌رساندش، ولی مشتری باید بداند چرا `dig` هنوز
     * مقدارِ قبلی را می‌دهد.
     */
    private function savedMessage(string $base, ?array $sync): string
    {
        if ($sync !== null && ! $sync['ok']) {
            return $base.' (انتشار روی سرورهای DNS با تأخیر انجام می‌شود؛ تا چند دقیقهٔ دیگر خودکار دوباره تلاش می‌کنیم.)';
        }

        return $base;
    }
}
