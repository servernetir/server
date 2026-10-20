<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CdnEvent;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\DnsOwnershipClaim;
use App\Services\Dns\DnsConfig;
use App\Services\Dns\DomainName;
use App\Services\Dns\OwnershipService;
use App\Services\Dns\TxtCodec;
use App\Services\Dns\ZoneImporter;
use App\Services\Dns\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * پنلِ مشتری — DNSِ مدیریت‌شده (قرارداد §8، §10).
 *
 * ⚠️ مالکیت در یک جا (`zoneOf`/`claimOf`/`recordOf`) و دوباره در ZoneService سنجیده
 * می‌شود. هیچ دادهٔ PowerDNS (نشانی، کلید، نامِ primary، خطای خام) به این صفحه‌ها
 * نمی‌رسد؛ پیام‌ها فقط از کلیدهای `dns.*` ساخته می‌شوند.
 */
class DnsController extends Controller
{
    public function __construct(
        private readonly ZoneService $zones,
        private readonly OwnershipService $ownership,
    ) {}

    /* ═════════════ فهرست و افزودن ═════════════ */

    public function index(): View
    {
        $c = $this->customer();
        $ready = $this->visible();

        return view('account.dns.index', AccountController::shell('dns') + [
            'ready'       => $ready,
            'canAdd'      => $ready && DnsConfig::serviceOpen(),
            'zones'       => $ready ? CdnZone::forCustomer($c->id)->where('mode', 'ns')->withCount('records')->orderByDesc('id')->get() : collect(),
            'claims'      => $ready ? DnsOwnershipClaim::query()->pending()->forCustomer($c->id)->orderByDesc('id')->get() : collect(),
            'nameservers' => DnsConfig::nameserverHosts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->open();
        $request->validate(['domain' => ['required', 'string', 'max:300']]);

        $r = $this->ownership->start($this->customer(), (string) $request->input('domain'), $request->ip());

        return match ($r['code']) {
            'zone_exists', 'zone_created' => redirect()->to(lroute('account.dns.zone', $r['zone']->id))
                ->with('ok', __('dns.start.'.$r['code'])),
            'claim_pending' => redirect()->to(lroute('account.dns.claim', $r['claim']->id)),
            default => back()->withInput()->withErrors(['domain' => __('dns.start.'.$r['code'], $r['params'])]),
        };
    }

    /* ═════════════ اثباتِ مالکیت ═════════════ */

    public function claim(int $claim): View
    {
        $c = $this->claimOf($claim);

        return view('account.dns.claim', AccountController::shell('dns') + [
            'claim'     => $c,
            'challenge' => $this->ownership->challenge($c),
        ]);
    }

    public function verifyClaim(Request $request, int $claim): RedirectResponse
    {
        $this->open();
        $c = $this->claimOf($claim);
        $r = $this->ownership->verify($c, $request->ip());

        if ($r['ok']) {
            return redirect()->to(lroute('account.dns.import', $r['zone']->id))->with('ok', __('dns.claim.verified'));
        }

        return back()->withErrors(['claim' => __('dns.claim.'.$r['code'])]);
    }

    public function cancelClaim(int $claim): RedirectResponse
    {
        $this->ownership->cancel($this->claimOf($claim));

        return redirect()->to(lroute('account.dns'))->with('ok', __('dns.claim.cancelled'));
    }

    /* ═════════════ zone ═════════════ */

    public function show(int $zone): View
    {
        $z = $this->zoneOf($zone);
        $records = $z->records()->orderByRaw("CASE type WHEN 'A' THEN 1 WHEN 'AAAA' THEN 2 WHEN 'CNAME' THEN 3 WHEN 'MX' THEN 4 WHEN 'TXT' THEN 5 WHEN 'SRV' THEN 6 WHEN 'CAA' THEN 7 ELSE 8 END")
            ->orderBy('name')->orderBy('prio')->orderBy('id')->get();

        return view('account.dns.zone', AccountController::shell('dns') + [
            'zone'      => $z,
            'records'   => $records,
            'nodes'     => $this->zones->nodeStates($z),
            'published' => $z->isPublishedEverywhere(),
            'checklist' => $this->zones->checklist($z, probeCurrent: false),
            'hosting'   => $this->zones->hostingContext($z),
            'events'    => CdnEvent::query()->audits()->where('cdn_zone_id', $z->id)->orderByDesc('id')->limit(20)->get(),
            'types'     => \App\Services\Dns\RecordValidator::TYPES,
            'ttls'      => [60, 300, 600, 1800, 3600, 7200, 14400, 43200, 86400],
            'nameservers' => DnsConfig::nameserverHosts(),
        ]);
    }

    /** بررسیِ فوریِ تفویض و نودها (محدودشده با throttle) */
    public function check(int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $r = $this->zones->checkDelegation($z);
        if ($r['state'] !== 'delegated') {
            $this->zones->probeEmailGaps($z->fresh() ?? $z);
        }

        return back()->with('ok', __('dns.delegation.result_'.$r['state']));
    }

    public function reviewed(Request $request, int $zone): RedirectResponse
    {
        $this->zones->markReviewed($this->zoneOf($zone), $this->actor(), $request->ip());

        return back()->with('ok', __('dns.ui.reviewed_saved'));
    }

    public function export(int $zone): Response
    {
        $z = $this->zoneOf($zone);

        return response($this->zones->exportZoneFile($z), 200, [
            'Content-Type'           => 'text/plain; charset=utf-8',
            'Content-Disposition'    => 'attachment; filename="'.$z->domain.'.zone"',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        // نامِ یونیکدیِ دامنه (همان که عنوانِ صفحه نشان می‌دهد) هم پذیرفته است
        if (DomainName::normalize((string) $request->input('confirm')) !== $z->domain) {
            return back()->withErrors(['delete' => __('dns.ui.delete_confirm_mismatch')]);
        }
        $this->zones->requestDelete($z, $this->actor(), $request->ip());

        return redirect()->to(lroute('account.dns'))->with('ok', __('dns.ui.delete_requested', ['domain' => $z->domain]));
    }

    /* ═════════════ رکوردها ═════════════ */

    public function storeRecord(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $r = $this->zones->addRecord($z, $this->recordInput($request), $this->actor(), $request->ip());

        return $this->recordResponse($r, 'new', 'dns.ui.record_added');
    }

    public function updateRecord(Request $request, int $zone, int $record): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $rec = $this->recordOf($z, $record);
        $r = $this->zones->updateRecord($z, $rec, $this->recordInput($request), $this->actor(), $request->ip());

        return $this->recordResponse($r, 'edit-'.$rec->id, 'dns.ui.record_updated');
    }

    public function deleteRecord(Request $request, int $zone, int $record): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $this->zones->deleteRecord($z, $this->recordOf($z, $record), $this->actor(), $request->ip());

        return back()->with('ok', __('dns.ui.record_deleted'));
    }

    public function toggleRecord(Request $request, int $zone, int $record): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $r = $this->zones->toggleRecord($z, $this->recordOf($z, $record), $this->actor(), $request->ip());

        return $r['ok']
            ? back()->with('ok', __('dns.ui.record_toggled'))
            : back()->withErrors(['record' => __('dns.err.'.$r['code'])]);
    }

    /* ═════════════ واردکردن ═════════════ */

    public function import(Request $request, int $zone): View
    {
        $z = $this->zoneOf($zone);

        return view('account.dns.import', AccountController::shell('dns') + [
            'zone'    => $z,
            'preview' => null,
            'source'  => null,
            'text'    => '',
        ]);
    }

    /** پیش‌نمایش: فایل (متن یا آپلود) یا اسکنِ کمکی */
    public function importPreview(Request $request, int $zone): View|RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $source = $request->input('source') === 'scan' ? 'scan' : 'file';
        $text = '';
        $scan = null;

        if ($source === 'scan') {
            $s = app(ZoneImporter::class)->scan($z->domain);
            if (! $s['ok']) {
                return back()->withErrors(['import' => __('dns.import.scan_failed')]);
            }
            $scan = $s['rows'];
            // ردیف‌های اسکن روی سرور می‌مانند؛ اعمال هرگز دادهٔ آماده‌ای از مرورگر نمی‌پذیرد
            session(['dns_scan.'.$z->id => $scan]);
        } else {
            // خطا زیرِ کلیدِ `import` — صفحه فقط همان را نشان می‌دهد؛ با validate()ِ معمولی
            // خطای `zonefile`/`text` جایی دیده نمی‌شد و فرم بی‌پیام برمی‌گشت.
            $v = Validator::make($request->all(), [
                'zonefile' => ['nullable', 'file', 'max:'.(int) ceil(DnsConfig::limit('import_max_bytes') / 1024)],
                'text'     => ['nullable', 'string', 'max:'.DnsConfig::limit('import_max_bytes')],
            ]);
            if ($v->fails()) {
                // تنها خطای واقعیِ این دو قاعده اندازه است (سقفِ ما یا upload_max_filesizeِ PHP)
                return back()->withErrors(['import' => __('dns.import.file_too_large')]);
            }
            $text = $request->hasFile('zonefile') ? (string) file_get_contents($request->file('zonefile')->getRealPath()) : (string) $request->input('text', '');
            if (trim($text) === '') {
                return back()->withErrors(['import' => __('dns.import.empty')]);
            }
        }

        return view('account.dns.import', AccountController::shell('dns') + [
            'zone'    => $z,
            'preview' => $this->zones->importPreview($z, $source === 'file' ? $text : null, $scan),
            'source'  => $source,
            'text'    => $text,
        ]);
    }

    public function importApply(Request $request, int $zone): RedirectResponse
    {
        $z = $this->zoneOf($zone);
        $request->validate(['lines' => ['array'], 'lines.*' => ['integer'], 'text' => ['nullable', 'string', 'max:'.DnsConfig::limit('import_max_bytes')]]);
        $lines = array_map('intval', (array) $request->input('lines', []));
        if ($lines === []) {
            return redirect()->to(lroute('account.dns.import', $z->id))->withErrors(['import' => __('dns.import.none_selected')]);
        }

        $scan = $request->input('source') === 'scan' ? session('dns_scan.'.$z->id) : null;
        if ($request->input('source') === 'scan' && ! is_array($scan)) {
            return redirect()->to(lroute('account.dns.import', $z->id))->withErrors(['import' => __('dns.import.scan_expired')]);
        }

        $r = $this->zones->importApply($z, $scan === null ? (string) $request->input('text', '') : null, $lines, $this->actor(), $request->ip(), $scan);
        session()->forget('dns_scan.'.$z->id);

        return redirect()->to(lroute('account.dns.zone', $z->id))
            ->with('ok', __('dns.import.applied', ['added' => $r['added'], 'skipped' => $r['skipped']]));
    }

    /* ═════════════ داخلی ═════════════ */

    private function customer()
    {
        return Auth::guard('customer')->user();
    }

    private function actor(): string
    {
        return 'customer:'.Auth::guard('customer')->id();
    }

    /**
     * دو دروازه:
     * - `visible()` = جدول‌ها + پرچمِ عرضه. پرچم خاموش ⇒ **همهٔ** مسیرهای مشتری ۴۰۴
     *   (کلیدِ قطعِ اضطراری؛ zoneهای تفویض‌شده روی نیم‌سرورها می‌مانند و حذف نمی‌شوند).
     * - `ready()` = visible + اتصالِ کامل (API و دو نیم‌سرور). فقط پذیرشِ دامنهٔ تازه
     *   (افزودن و اثباتِ مالکیت) به آن نیاز دارد؛ اگر اتصال موقتاً ناقص شد، مشتریِ
     *   موجود هنوز رکوردهایش را می‌بیند و ویرایش می‌کند و ناشر با backoff می‌فرستد.
     */
    private function visible(): bool
    {
        return Schema::hasTable('cdn_zones') && Schema::hasTable('dns_ownership_claims') && DnsConfig::enabled();
    }

    private function ready(): bool
    {
        return $this->visible() && DnsConfig::serviceOpen();
    }

    private function open(): void
    {
        abort_unless($this->ready(), 404);
    }

    /** مالکیت — zone */
    private function zoneOf(int $id): CdnZone
    {
        abort_unless($this->visible(), 404);

        return CdnZone::forCustomer(Auth::guard('customer')->id())->where('mode', 'ns')->where('status', '!=', 'deleting')->findOrFail($id);
    }

    private function claimOf(int $id): DnsOwnershipClaim
    {
        abort_unless($this->visible(), 404);

        return DnsOwnershipClaim::query()->forCustomer(Auth::guard('customer')->id())->findOrFail($id);
    }

    private function recordOf(CdnZone $zone, int $id): CdnRecord
    {
        return $zone->records()->whereKey($id)->firstOrFail();
    }

    private function recordInput(Request $request): array
    {
        $in = $request->only(['type', 'name', 'content', 'ttl', 'prio', 'weight', 'port', 'target', 'flags', 'tag', 'value', 'comment']);
        // TXT فیلدِ جدای خودش را دارد (textarea)؛ دو فیلدِ هم‌نام یعنی PHP آخری را می‌گیرد
        if (strtoupper((string) ($in['type'] ?? '')) === 'TXT') {
            $in['content'] = (string) $request->input('content_txt', $in['content'] ?? '');
        }

        return $in;
    }

    private function recordResponse(array $r, string $formKey, string $okKey): RedirectResponse
    {
        if ($r['ok']) {
            $msg = __($okKey);
            if (($r['ttl_aligned'] ?? 0) > 0) {
                $msg .= ' '.__('dns.ui.ttl_aligned', ['count' => $r['ttl_aligned']]);
            }

            return back()->with('ok', $msg)->with('dns_highlight', $r['record']?->id);
        }

        $errors = [];
        foreach ($r['errors'] as $field => $e) {
            $errors[$field] = __('dns.err.'.$e['code'], array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $e['params'] ?? []));
        }

        return back()->withInput()->withErrors($errors)->with('dns_form', $formKey);
    }

    /** برای Blade: متنِ TXT برای ویرایش (بی‌گیومه وقتی یک رشته است) */
    public static function txtForEdit(string $presentation): string
    {
        return TxtCodec::forEdit($presentation);
    }
}
