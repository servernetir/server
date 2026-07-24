<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * تسویهٔ خریدِ یک پکیج — نه یک فروشگاهِ لیستی.
 *
 * قاعدهٔ کارفرما: مشتری در سایتِ اصلی پکیج را می‌بیند و انتخاب می‌کند؛ دکمهٔ
 * «خرید» او را مستقیم به صفحهٔ تسویهٔ همان پکیج در پنل می‌آورد تا فرایندِ خرید
 * را کامل کند (دامنه دارم/می‌خرم → پیش‌فاکتور → پرداخت → تحویلِ خودکار).
 */
class StoreController extends Controller
{
    /** ورودیِ قدیمیِ فروشگاه دیگر لیست ندارد — به کاتالوگِ سایتِ اصلی می‌فرستد */
    public function index(): RedirectResponse
    {
        return redirect(lroute('home').'#hosting');
    }

    /** صفحهٔ تسویهٔ یک پکیج (از دکمهٔ خریدِ سایت اصلی) */
    public function checkout(Product $product): View|RedirectResponse
    {
        if (! $product->is_active) {
            return redirect(lroute('home'))->withErrors('این پکیج در دسترس نیست.');
        }

        return view('account.checkout', AccountController::shell('') + [
            'product' => $product,
        ]);
    }

    /** ثبتِ سفارش: سرویس + پیش‌فاکتور می‌سازد و به پرداخت می‌برد */
    public function order(Request $request, Product $product): RedirectResponse
    {
        if (! $product->is_active) {
            return back()->withErrors('این پکیج در دسترس نیست.');
        }

        $data = $request->validate([
            'domain_mode' => ['required', 'in:have,buy,subdomain'],
            'domain'      => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'domain_buy'  => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'subdomain'   => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/i'],
        ], [], ['domain' => 'دامنه', 'domain_buy' => 'دامنه', 'subdomain' => 'زیردامنه']);

        // دامنهٔ نهایی بر اساس انتخابِ کاربر
        [$domain, $note] = $this->resolveDomain($data);
        if ($domain === null) {
            return back()->withInput()->withErrors(['domain' => 'دامنه را کامل وارد کنید.']);
        }

        $customer = Auth::guard('customer')->user();

        $invoice = DB::transaction(function () use ($customer, $product, $domain, $note) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $product->name,
                'description'   => trim(($product->description ? $product->description."\n" : '').$note),
                'currency_code' => $product->currency_code,
                'price'         => $product->effectivePrice(),   // قیمت با نرخِ روز، قفل در لحظهٔ سفارش
                'tax_percent'   => $product->tax_percent,
                'cycle'         => $product->cycle,
                'status'        => 'pending',
                'server_id'     => $product->server_id,
                'plan'          => $product->plan,
                'domain'        => $domain,
            ]);

            return $this->issueOrderInvoice($service, $product);
        });

        \App\Models\ActivityLog::record($customer->id, 'service',
            'سفارشِ آنلاینِ پکیج «'.$product->name.'» ('.$domain.') ثبت شد', $request, 'customer');

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', 'سفارش ثبت شد. برای فعال‌سازی، پیش‌فاکتور را پرداخت کنید.');
    }

    /** دامنهٔ نهایی + یادداشت بر اساس حالت (دارم/می‌خرم/زیردامنه) */
    private function resolveDomain(array $data): array
    {
        return match ($data['domain_mode']) {
            'have' => [
                filled($data['domain'] ?? null) ? strtolower(trim($data['domain'])) : null,
                '',
            ],
            'buy' => [
                filled($data['domain_buy'] ?? null) ? strtolower(trim($data['domain_buy'])) : null,
                filled($data['domain_buy'] ?? null) ? '🌐 ثبتِ دامنهٔ '.strtolower(trim($data['domain_buy'])).' درخواست شده (توسط پشتیبانی انجام می‌شود).' : '',
            ],
            'subdomain' => [
                filled($data['subdomain'] ?? null) ? strtolower(trim($data['subdomain'])).'.servernet.cloud' : null,
                'زیردامنهٔ رایگانِ سرورنت',
            ],
            default => [null, ''],
        };
    }

    /** پیش‌فاکتورِ اولین دوره — شاملِ هزینهٔ راه‌اندازی (اگر باشد) */
    private function issueOrderInvoice(Service $service, Product $product): Invoice
    {
        $lineTax = fn (int $amount) => (int) round($amount * $service->tax_percent / 100);

        $unitPrice = (int) $service->price;
        $setupFee  = $product->effectiveSetup();

        $subtotal = $unitPrice + $setupFee;
        $tax      = $lineTax($unitPrice) + $lineTax($setupFee);

        $invoice = Invoice::create([
            'customer_id'   => $service->customer_id,
            'service_id'    => $service->id,
            'kind'          => 'service',
            'currency_code' => $service->currency_code,
            'subtotal'      => $subtotal,
            'tax'           => $tax,
            'total'         => $subtotal + $tax,
            'paid'          => 0,
            'status'        => 'unpaid',
            'issued_at'     => now(),
            'note'          => $product->name,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'title'       => $product->name.' ('.$service->cycleLabel().')',
            'description' => $service->domain,
            'quantity'    => 1,
            'unit_price'  => $unitPrice,
            'line_total'  => $unitPrice,
            'tax_rate_bp' => $service->tax_percent * 100,
            'tax_amount'  => $lineTax($unitPrice),
        ]);

        if ($setupFee > 0) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'هزینهٔ راه‌اندازی — '.$product->name,
                'quantity'    => 1,
                'unit_price'  => $setupFee,
                'line_total'  => $setupFee,
                'tax_rate_bp' => $service->tax_percent * 100,
                'tax_amount'  => $lineTax($setupFee),
            ]);
        }

        return $invoice;
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
