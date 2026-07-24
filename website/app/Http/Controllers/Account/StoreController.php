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
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * فروشگاهِ پنلِ مشتری — خریدِ آنلاینِ پکیج‌ها.
 *
 * مشتری یک پکیج را می‌خرد → سرویسِ «منتظر پرداخت» + پیش‌فاکتور ساخته می‌شود →
 * پس از پرداخت، همان موتورِ تحویل (PaymentService + provision:run) خودکار
 * حساب را می‌سازد و در پنلِ مشتری می‌افتد.
 */
class StoreController extends Controller
{
    public function index(): View
    {
        $products = Schema::hasTable('products')
            ? Product::where('is_active', true)->orderBy('category')->orderBy('sort')->orderBy('price')->get()
            : collect();

        return view('account.store', AccountController::shell('store') + [
            'byCategory' => $products->groupBy('category'),
        ]);
    }

    /** ثبتِ سفارش: سرویس + پیش‌فاکتور می‌سازد و به صفحهٔ پرداخت می‌برد */
    public function order(Request $request, Product $product): RedirectResponse
    {
        if (! $product->is_active) {
            return back()->withErrors('این پکیج در دسترس نیست.');
        }

        $rules = [];
        if ($product->requires_domain) {
            $rules['domain'] = ['required', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'];
        }
        $data = $request->validate($rules, [], ['domain' => 'دامنه']);

        $customer = Auth::guard('customer')->user();

        $invoice = DB::transaction(function () use ($customer, $product, $data) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $product->name,
                'description'   => $product->description,
                'currency_code' => $product->currency_code,
                'price'         => $product->price,
                'tax_percent'   => $product->tax_percent,
                'cycle'         => $product->cycle,
                'status'        => 'pending',
                'server_id'     => $product->server_id,
                'plan'          => $product->plan,
                'domain'        => $data['domain'] ?? null,
            ]);

            return $this->issueOrderInvoice($service, $product);
        });

        \App\Models\ActivityLog::record($customer->id, 'service',
            'سفارشِ آنلاینِ پکیج «'.$product->name.'» ثبت شد', $request, 'customer');

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', 'سفارش ثبت شد. برای فعال‌سازی، پیش‌فاکتور را پرداخت کنید.');
    }

    /** پیش‌فاکتورِ اولین دوره — شاملِ هزینهٔ راه‌اندازی (اگر باشد) */
    private function issueOrderInvoice(Service $service, Product $product): Invoice
    {
        $lineTax = fn (int $amount) => (int) round($amount * $service->tax_percent / 100);

        $subtotal = $product->price + $product->setup_fee;
        $tax      = $lineTax($product->price) + $lineTax($product->setup_fee);

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
            'description' => $product->description,
            'quantity'    => 1,
            'unit_price'  => $product->price,
            'line_total'  => $product->price,
            'tax_rate_bp' => $service->tax_percent * 100,
            'tax_amount'  => $lineTax($product->price),
        ]);

        if ($product->setup_fee > 0) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'هزینهٔ راه‌اندازی — '.$product->name,
                'quantity'    => 1,
                'unit_price'  => $product->setup_fee,
                'line_total'  => $product->setup_fee,
                'tax_rate_bp' => $service->tax_percent * 100,
                'tax_amount'  => $lineTax($product->setup_fee),
            ]);
        }

        return $invoice;
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
