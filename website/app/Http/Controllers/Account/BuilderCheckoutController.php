<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\Domain\DomainSearch;
use App\Services\Provisioning\BuilderSitePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * تسویهٔ سایت‌ساز — «استقرار در دیتاسنتر» بدونِ WHMCS.
 *
 * ورودی: مرجعِ سایتِ ساخته‌شده (SB-XXXXXX) + پکیجِ هاست + دامنهٔ دلخواه.
 * خروجی: یک Service (هاست) + یک Domain (ثبت) + **یک** فاکتور با هر دو شناسه.
 *
 * چرا یک فاکتور: PaymentService::applyPaid دو شاخهٔ مستقل دارد —
 * `service_id` (→ awaiting_provision → provision:run) و `domain_id`
 * (→ provision_status=pending → domains:register). پس یک پرداخت، هر دو زنجیره
 * را می‌اندازد و هیچ تغییری در مسیرِ پول لازم نیست.
 *
 * پس از تحویلِ هاست، ProvisioningService خودش BuilderSitePublisher را صدا
 * می‌زند و index.html در public_html نوشته می‌شود — صفر کارِ دستی.
 */
class BuilderCheckoutController extends Controller
{
    /** صفحهٔ تأیید: خلاصهٔ پلن + استعلامِ زندهٔ دامنه */
    public function show(Request $request, DomainSearch $search): View|RedirectResponse
    {
        $data = $request->validate([
            'ref'    => ['required', 'string', 'max:20', 'regex:/^SB-[A-Z0-9]{4,10}$/i'],
            'plan'   => ['required', 'string', 'max:60'],
            'domain' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ]);

        $ref = strtoupper($data['ref']);
        $product = $this->productFor($data['plan']);

        if ($product === null) {
            return redirect(lroute('account.services'))
                ->with('err', __('ui.bcf_pkg_missing'));
        }

        // سایتِ ساخته‌شده باید واقعاً موجود باشد — وگرنه بعد از پرداخت چیزی
        // برای انتشار نیست. پیش از پول، نه بعدش.
        if (BuilderSitePublisher::htmlFor($ref) === null) {
            return redirect(lroute('account.services'))
                ->with('err', __('ui.bcf_export_expired'));
        }

        [$check, $err] = $this->quoteDomain($search, strtolower(trim($data['domain'])));

        return view('account.builder-checkout', AccountController::shell('') + [
            'product' => $product,
            'ref'     => $ref,
            'domain'  => strtolower(trim($data['domain'])),
            'check'   => $check,   // ردیفِ استعلام (quote_id, price_toman…) یا null
            'quoteErr' => $err,
            'taxPct'  => CloudStoreController::taxPercent(),
        ]);
    }

    /** ثبتِ سفارش: سرویس + دامنه + یک فاکتور */
    public function order(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ref'      => ['required', 'string', 'max:20', 'regex:/^SB-[A-Z0-9]{4,10}$/i'],
            'plan'     => ['required', 'string', 'max:60'],
            'quote_id' => ['required', 'integer'],
        ]);

        $ref = strtoupper($data['ref']);
        $product = $this->productFor($data['plan']);

        if ($product === null) {
            return back()->withErrors(__('ui.stf_pkg_na'));
        }

        if (BuilderSitePublisher::htmlFor($ref) === null) {
            return back()->withErrors(__('ui.bcf_export_expired'));
        }

        $quote = \App\Models\DomainQuote::find($data['quote_id']);

        if ($quote === null) {
            return back()->withErrors(__('ui.bcf_quote_missing'));
        }

        // مالکیتِ استعلام — همان گاردِ مسیرِ دامنه (شناسهٔ ترتیبی قابلِ پیمایش است)
        if (! $quote->claimFor((int) Auth::guard('customer')->id())) {
            return back()->withErrors(__('ui.bcf_quote_missing'));
        }

        // همان سه گاردِ مسیرِ دامنه — پیش از گرفتنِ پول، نه ساعت‌ها بعد در صف
        if ($quote->honour_until !== null && $quote->honour_until->isPast()) {
            return back()->withErrors(__('ui.bcf_quote_expired'));
        }

        if ((int) $quote->sell_toman <= 0) {
            return back()->withErrors(__('ui.dm_no_price'));
        }

        [$sld, $tld] = Domain::splitFqdn((string) $quote->domain);

        if (! DomainSearch::sells($tld)) {
            return back()->withErrors(__('ui.bcf_tld_na'));
        }

        if (\App\Services\Domain\TldGate::isBlocked($tld)) {
            return back()->withErrors(__('ui.bcf_tld_blocked', ['tld' => $tld]));
        }

        $existing = Domain::where('domain', $quote->domain)->where('registrar', 'openprovider')->first();

        if ($existing !== null && ! $existing->isDead()) {
            return back()->withErrors(__('ui.dm_already'));
        }

        $customer = Auth::guard('customer')->user();

        // سرورِ تحویل: پکیج اگر مکان دارد از ظرفیتِ زنده، وگرنه سرورِ خودِ پکیج
        $countries = $product->availableCountries();
        $server = null;

        if ($countries !== []) {
            foreach ($countries as $c) {
                if ($server = \App\Models\Server::pickForCountry($c)) {
                    break;
                }
            }

            if ($server === null) {
                return back()->withErrors(__('ui.bcf_cap_full'));
            }
        }

        $cyclePrice = $product->priceForCycle('monthly');
        $taxPct = CloudStoreController::taxPercent();

        $invoice = null;

        DB::transaction(function () use ($customer, $product, $quote, $existing, $server, $cyclePrice, $taxPct, $sld, $tld, $ref, &$invoice) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $product->name,
                'description'   => 'سفارشِ سایت‌ساز — مرجعِ '.$ref,
                'currency_code' => $product->currency_code,
                'price'         => $cyclePrice,
                'tax_percent'   => $product->tax_percent,
                'cycle'         => 'monthly',
                'status'        => 'pending',
                'server_id'     => $server?->id ?? $product->server_id,
                'plan'          => $product->plan,
                'is_reseller'   => false,
                'domain'        => $quote->domain,
                // مرجعِ سایتِ ساخته‌شده — ProvisioningService پس از تحویل همین را
                // می‌خوانَد و از بازنویسیِ metaی درایور هم محافظت شده است.
                'provision_meta' => ['builder_ref' => $ref],
            ]);

            $domain = $existing;
            $ns = Domain::defaultNameServers();

            if ($domain === null) {
                $domain = Domain::create([
                    'customer_id'      => $customer->id,
                    'domain'           => $quote->domain,
                    'sld'              => $sld,
                    'tld'              => $tld,
                    'registrar'        => 'openprovider',
                    'status'           => 'pending',
                    // `none` نه `pending`: تا پرداخت نشده کرون برش ندارد؛
                    // PaymentService بعد از تسویه پرچم را pending می‌کند.
                    'provision_status' => 'none',
                    'period_years'     => 1,
                    'price_toman'      => (int) $quote->sell_toman,
                    'renew_toman'      => (int) ($quote->renew_toman ?: $quote->sell_toman),
                    'cost_amount'      => (int) $quote->cost_amount,
                    'cost_currency'    => (string) $quote->cost_currency,
                    'quote_id'         => $quote->id,
                    'name_servers'     => $ns,
                ]);
            } else {
                $domain->forceFill([
                    'customer_id' => $customer->id, 'status' => 'pending',
                    'provision_status' => 'none', 'period_years' => 1,
                    'price_toman' => (int) $quote->sell_toman, 'quote_id' => $quote->id,
                    'name_servers' => $ns,
                ])->save();
            }

            // فاکتورِ هاست از همان تعریفِ یگانهٔ فروشگاه/تلفن — بعد ردیفِ دامنه
            // به همان فاکتور اضافه و جمع‌ها بازمحاسبه می‌شود.
            $invoice = app(\App\Services\Billing\ProductInvoiceIssuer::class)->issue($service, $product);

            $domainPrice = (int) $quote->sell_toman;
            $domainTax = (int) round($domainPrice * $taxPct / 100);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'ثبتِ دامنهٔ '.$quote->domain,
                'description' => '۱ سال — نیم‌سرورهای سرورنت',
                'quantity'    => 1,
                'unit_price'  => $domainPrice,
                'line_total'  => $domainPrice,
                'tax_rate_bp' => (int) ($taxPct * 100),
                'tax_amount'  => $domainTax,
            ]);

            $invoice->forceFill([
                'domain_id' => $domain->id,
                'subtotal'  => $invoice->subtotal + $domainPrice,
                'tax'       => $invoice->tax + $domainTax,
                'total'     => $invoice->total + $domainPrice + $domainTax,
                'note'      => trim(($invoice->note ?? '')."\n".'سایت‌ساز '.$ref.' + ثبتِ دامنهٔ '.$quote->domain),
            ])->save();
        });

        \App\Models\ActivityLog::record($customer->id, 'purchase',
            __('ui.act_order_builder', ['name' => $product->name, 'domain' => $quote->domain, 'ref' => $ref]),
            $request, 'customer', $invoice->service_id);

        app(\App\Services\Notify\AdminNotifier::class)->event('سفارشِ سایت‌ساز (در انتظارِ پرداخت)', [
            'مشتری' => $customer->displayName().' ('.$customer->code.')',
            'پکیج'  => $product->name,
            'دامنه' => $quote->domain,
            'مرجع'  => $ref,
            'مبلغ'  => fa_num(number_format((int) $invoice->total)).' تومان',
        ], null, '🚀', [[
            ['text' => '👤 پروفایلِ مشتری', 'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'c:'.$customer->id],
            ['text' => '🧾 فاکتور', 'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'i:'.$invoice->id],
        ]]);

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', __('ui.bcf_order_ok'));
    }

    // ───────────────────────── کمکی ─────────────────────────

    /** پکیجِ سایت‌ساز از روی slug — فقط گروهِ site-builder پذیرفته می‌شود */
    private function productFor(string $slug): ?Product
    {
        $p = Product::where('slug', $slug)->where('is_active', true)->first();

        // گروه عمداً قفل است: این تسویه قیمتِ دامنه را به فاکتورِ پکیج اضافه
        // می‌کند و نباید بشود هر پکیجی را از این در خرید.
        return ($p !== null && $p->group === 'site-builder') ? $p : null;
    }

    /**
     * استعلامِ زندهٔ همان دامنه — همان خطِ لوله‌ای که /account/domains می‌زند.
     *
     * @return array{0:?array,1:?string} [ردیفِ استعلام، پیامِ خطا]
     */
    private function quoteDomain(DomainSearch $search, string $fqdn): array
    {
        [$sld, $tld] = Domain::splitFqdn($fqdn);

        if ($sld === '' || $tld === '') {
            return [null, 'دامنه را کامل وارد کنید (مثلاً mysite.com).'];
        }

        if (! DomainSearch::sells($tld)) {
            return [null, 'پسوندِ «.'.$tld.'» از سایت‌ساز قابلِ ثبت نیست؛ پسوندِ بین‌المللی مثل .com انتخاب کنید.'];
        }

        try {
            $rows = $search->search($sld, [$tld]);
        } catch (\Throwable) {
            return [null, 'استعلامِ دامنه در این لحظه ممکن نیست؛ کمی بعد دوباره تلاش کنید.'];
        }

        foreach ($rows as $row) {
            if (strtolower((string) ($row['domain'] ?? '')) !== $fqdn) {
                continue;
            }

            if (! ($row['available'] ?? false)) {
                return [null, ($row['state'] ?? '') === DomainSearch::STATE_UNCHECKED
                    ? 'استعلامِ دامنه در این لحظه ممکن نیست؛ کمی بعد دوباره تلاش کنید.'
                    : 'این دامنه قبلاً ثبت شده است؛ نامِ دیگری انتخاب کنید.'];
            }

            if (blank($row['quote_id'] ?? null)) {
                return [null, 'برای این دامنه قیمتِ قابلِ اتکایی نداریم؛ با پشتیبانی تماس بگیرید.'];
            }

            return [$row, null];
        }

        return [null, 'استعلامِ دامنه در این لحظه ممکن نیست؛ کمی بعد دوباره تلاش کنید.'];
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
