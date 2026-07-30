<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فروش و مدیریت سرویس‌های مشتری — سمت کارکنان.
 *
 * جریان کارفرما: در پروندهٔ مشتری یک سرویس می‌سازد (نام، توضیحات، مبلغ،
 * دوره). سیستم همان لحظه یک **پیش‌فاکتور** می‌سازد؛ سرویس در حالت «منتظر
 * پرداخت» می‌ماند تا مشتری پرداخت کند و آن‌گاه خودکار فعال می‌شود
 * (در PaymentService، هنگام تسویه).
 */
class ServiceController extends Controller
{
    /**
     * فروش سرویس به یک مشتری + ساخت پیش‌فاکتور، همه در یک تراکنش.
     */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price'       => ['required', 'integer', 'min:0', 'max:100000000000'],
            'tax_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'cycle'       => ['required', \Illuminate\Validation\Rule::in(\App\Models\Service::cycles())],
            // تحویلِ خودکار (اختیاری): اگر سروری انتخاب شود، پس از پرداخت خودکار
            // روی آن ساخته می‌شود. نام‌کاربری/رمز اگر خالی باشند خودکار ساخته می‌شوند.
            'server_id'   => ['nullable', 'integer', 'exists:servers,id'],
            'plan'        => ['nullable', 'string', 'max:80'],
            'username'    => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'domain'      => ['nullable', 'string', 'max:190'],
        ], [], [
            'name' => 'نام سرویس', 'price' => 'مبلغ', 'cycle' => 'دوره',
            'username' => 'نام‌کاربری', 'domain' => 'دامنه',
        ]);

        $taxPct = (int) ($data['tax_percent'] ?? 0);

        $service = DB::transaction(function () use ($customer, $data, $taxPct, $request) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $data['name'],
                'description'   => $data['description'] ?? null,
                'currency_code' => 'IRT',
                'price'         => (int) $data['price'],
                'tax_percent'   => $taxPct,
                'cycle'         => $data['cycle'],
                'status'        => 'pending',
                'created_by'    => $request->user()?->id,
                'server_id'     => $data['server_id'] ?? null,
                'plan'          => $data['plan'] ?? null,
                'username'      => $data['username'] ?? null,
                'domain'        => $data['domain'] ?? null,
            ]);

            $this->issueInvoice($service);

            return $service;
        });

        \App\Models\ActivityLog::forService($service, 'purchase',
            'سرویس «'.$service->name.'» توسط مدیر ('.($request->user()?->name ?: 'مدیر').') فروخته و پیش‌فاکتور صادر شد',
            'staff', $request);

        return redirect("/admin/customers/{$customer->id}")
            ->with('ok', 'سرویس «'.$service->name.'» ساخته شد و پیش‌فاکتور صادر گردید. پس از پرداخت مشتری، خودکار فعال می‌شود.');
    }

    /**
     * صدور یک فاکتور برای یک دورهٔ سرویس (اولین صدور یا تمدید).
     *
     * public و static-مانند تا فرمان تمدیدِ دوره‌ای هم بتواند از همین منطق
     * استفاده کند — یک جای واحد برای «فاکتور یک سرویس چه شکلی است».
     */
    public function issueInvoice(Service $service): Invoice
    {
        $subtotal = $service->price;
        $tax      = $service->taxAmount();
        $total    = $subtotal + $tax;

        $invoice = Invoice::create([
            'customer_id'   => $service->customer_id,
            'service_id'    => $service->id,
            'kind'          => 'service',
            'currency_code' => $service->currency_code,
            'subtotal'      => $subtotal,
            'tax'           => $tax,
            'total'         => $total,
            'paid'          => 0,
            'status'        => 'unpaid',
            'issued_at'     => now(),
            'note'          => $service->name,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'title'       => $service->name.' ('.$service->cycleLabel().')',
            'description' => $service->description,
            'quantity'    => 1,
            'unit_price'  => $subtotal,
            'line_total'  => $subtotal,
            'tax_rate_bp' => $service->tax_percent * 100,   // درصد → basis-points
            'tax_amount'  => $tax,
        ]);

        return $invoice;
    }

    /**
     * تغییر وضعیت سرویس — تعلیق، فعال‌سازی دستی، لغو.
     */
    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,cancelled'],
        ]);

        $service->status = $data['status'];
        if ($data['status'] === 'cancelled') {
            $service->cancelled_at = now();
        }
        if ($data['status'] === 'active' && $service->activated_at === null) {
            $service->activated_at = now();
        }
        $service->save();

        \App\Models\ActivityLog::forService($service,
            match ($data['status']) { 'suspended' => 'suspend', 'cancelled' => 'terminate', default => 'reactivate' },
            'وضعیت سرویس به «'.$data['status'].'» تغییر کرد — توسط '.($request->user()?->name ?: 'مدیر'),
            'staff', $request);

        return back()->with('ok', 'وضعیت سرویس به‌روزرسانی شد.');
    }

    /** صدور دستیِ فاکتور تمدید برای یک سرویس (وقتی کارفرما زودتر می‌خواهد) */
    public function renew(Service $service): RedirectResponse
    {
        if (! $service->isRecurring()) {
            return back()->withErrors('این سرویس دوره‌ای نیست و تمدید ندارد.');
        }

        $this->issueInvoice($service);

        \App\Models\ActivityLog::forService($service, 'renew',
            'فاکتور تمدید توسط مدیر ('.(request()->user()?->name ?: 'مدیر').') صادر شد', 'staff');

        return back()->with('ok', 'فاکتور تمدید صادر شد؛ پس از پرداخت، سررسید سرویس یک دوره جلو می‌رود.');
    }

    /** ساختِ فوری/تلاشِ دوبارهٔ تحویل روی سرور (بدونِ صبر برای کرون) */
    public function provision(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        // اگر سرویس هنوز سرور/پلن/دامنه ندارد، همین‌جا تعیین می‌شود (رفعِ سفارشی
        // که بدونِ سرور خریداری شده)
        $data = $request->validate([
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'plan'      => ['nullable', 'string', 'max:80'],
            'domain'    => ['nullable', 'string', 'max:190'],
        ]);
        $assign = array_filter([
            'server_id' => $data['server_id'] ?? null,
            'plan'      => $data['plan'] ?? null,
            'domain'    => $data['domain'] ?? null,
        ], fn ($v) => filled($v));
        if ($assign) {
            $service->update($assign);
            $service->refresh();
        }

        // ⚠️ سرورِ ابری هرگز `server_id` ندارد (پیش از خرید وجود ندارد). بی‌این
        // استثنا، تحویلِ شکست‌خوردهٔ ابری **هیچ راهِ بازیابی** نداشت: کرون فقط
        // `pending` را برمی‌دارد و `failed` را نمی‌بیند، و دکمهٔ «تلاش دوباره»ی
        // ادمین هم با پیامِ «اول یک سرورِ تحویل انتخاب کنید» بیرون می‌زد. یعنی
        // مشتری پول داده، سرور ندارد، و تنها راه ویرایشِ دستیِ دیتابیس بود.
        $isCloud = \App\Services\Cloud\CloudProvisioner::handles($service);

        if (! $isCloud && ! $service->server_id) {
            return back()->withErrors('اول یک سرورِ تحویل انتخاب کنید.');
        }

        // شکست‌خورده/آماده را دوباره در صف بگذار، بعد همین حالا اجرا کن.
        // 'running' هم برمی‌گردد: اگر پروسه بینِ قفل و پایانِ ساخت کشته شود
        // (دپلوی، ری‌استارتِ FPM، تایم‌اوت)، سرویس تا ابد در 'running' گیر
        // می‌کرد و هیچ مسیری بیرونش نمی‌آورد.
        if (in_array($service->provision_status, [null, 'failed', 'manual', 'running'], true)) {
            $service->update(['provision_status' => 'pending']);
        }

        $ok = app(\App\Services\Provisioning\ProvisioningService::class)->provision($service->fresh());

        if ($ok) {
            \App\Models\ActivityLog::forService($service, 'provision',
                'تحویلِ دستیِ روی سرور توسط مدیر ('.($request->user()?->name ?: 'مدیر').')', 'staff', $request);
        }

        return $ok
            ? back()->with('ok', 'سرویس روی سرور ساخته و تحویل شد.')
            : back()->withErrors('تحویل انجام نشد: '.($service->fresh()->provision_error ?: 'روی این سرور تحویلِ خودکار نیست یا خطا رخ داد.'));
    }

    public function suspend(Request $request, Service $service): RedirectResponse
    {
        $r = app(\App\Services\Provisioning\ProvisioningService::class)->suspend($service);

        if ($r->ok || $r->manual) {
            \App\Models\ActivityLog::forService($service, 'suspend',
                'سرویس توسط مدیر ('.($request->user()?->name ?: 'مدیر').') معلق شد', 'staff', $request);
        }

        return ($r->ok || $r->manual)
            ? back()->with('ok', 'سرویس معلق شد'.($r->manual ? ' (تعلیقِ سرور را دستی انجام دهید).' : ' و روی سرور غیرفعال شد.'))
            : back()->withErrors('تعلیق ناموفق: '.$r->error);
    }

    public function unsuspend(Request $request, Service $service): RedirectResponse
    {
        $r = app(\App\Services\Provisioning\ProvisioningService::class)->unsuspend($service);

        if ($r->ok || $r->manual) {
            \App\Models\ActivityLog::forService($service, 'reactivate',
                'سرویس توسط مدیر ('.($request->user()?->name ?: 'مدیر').') از تعلیق درآمد', 'staff', $request);
        }

        return ($r->ok || $r->manual)
            ? back()->with('ok', 'سرویس فعال شد.')
            : back()->withErrors('رفعِ تعلیق ناموفق: '.$r->error);
    }

    /**
     * تاریخچهٔ مالکیتِ یک سرویس — خواستهٔ کارفرما: «باید بدانم این سرور در فلان
     * زمان دستِ کی بود». همهٔ رویدادهای service-محور به‌ترتیبِ زمان.
     */
    public function history(Service $service): \Illuminate\View\View
    {
        $service->load('customer');

        $logs = \App\Models\ActivityLog::ofService($service->id)->limit(300)->get();

        return view('admin.service-history', compact('service', 'logs'));
    }

    public function terminate(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $r = app(\App\Services\Provisioning\ProvisioningService::class)->terminate($service);

        if ($r->ok || $r->manual) {
            \App\Models\ActivityLog::forService($service, 'terminate',
                'سرویس توسط مدیر ('.($request->user()?->name ?: 'مدیر').') لغو و از سرور حذف شد', 'staff', $request);
        }

        return ($r->ok || $r->manual)
            ? back()->with('ok', 'سرویس لغو شد'.($r->manual ? ' (حذفِ سرور را دستی انجام دهید).' : ' و حساب از سرور حذف شد.'))
            : back()->withErrors('حذف ناموفق: '.$r->error);
    }
}
