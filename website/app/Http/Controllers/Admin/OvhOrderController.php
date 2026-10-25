<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{ActivityLog, OvhOrder, Service, Setting};
use App\Services\Cloud\{CloudProvisioner, OvhClient, OvhOrderHold, OvhOrders};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OvhOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $ready = Schema::hasTable('ovh_orders');
        $phase = (string) $request->query('phase', '');
        $rows = $ready ? OvhOrder::with('service')->when($phase !== '', fn ($q) => $q->where('phase', $phase))->latest()->paginate(30) : null;
        $selected = $ready && ctype_digit((string) $request->query('order', ''))
            ? OvhOrder::with(['service', 'events' => fn ($q) => $q->latest('id')->limit(100)])->find((int) $request->query('order')) : null;
        return view('admin.ovh-orders', compact('ready', 'rows', 'selected', 'phase') + [
            'enabled' => OvhOrders::enabled(), 'deploymentGate' => config('ovh.purchase_enabled') === true,
            'configured' => app(OvhClient::class)->isConfigured(),
            'connection' => Setting::get('ovh_connection_status', 'unchecked'),
            'checkedAt' => Setting::get('ovh_connection_checked_at'),
            'legacy' => Service::whereHas('cloudPlan', fn ($q) => $q->where('provider', 'ovh'))
                ->where('provision_status', '!=', 'done')->latest()->limit(20)->get(),
        ]);
    }

    public function check(Request $request, OvhClient $client)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        // Read-only: no cart, payment, key change or order creation.
        $r = $client->orderApi('GET', '/me');
        $payment = $client->orderApi('GET', '/me/payment/method', ['default' => 'true']);
        $servers = $client->orderApi('GET', '/vps');
        Setting::put('ovh_connection_status', $r['ok'] && $servers['ok'] ? 'connected' : 'failed');
        Setting::put('ovh_connection_checked_at', now()->toIso8601String());
        Setting::put('ovh_default_payment_present', $payment['ok'] && count((array) $payment['body']) === 1 ? '1' : '0');
        return back()->with('ok', 'بررسی خواندنی ثبت شد؛ موجودی قابل‌خرج یا تحویل واقعی از وجود روش پرداخت نتیجه گرفته نمی‌شود.');
    }

    public function catalogue(Request $request, \App\Services\Cloud\CloudCatalogSync $sync)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $report = $sync->sync('ovh');
        $r = $report['providers']['ovh'] ?? ['ok' => false, 'message' => 'کلیدهای زیرساخت تنظیم نشده‌اند.'];
        return $r['ok'] ? back()->with('ok', 'کاتالوگ و قیمت همین زیرساخت تازه شد؛ هیچ Checkout یا پرداختی انجام نشد.')
            : back()->withErrors('همگام‌سازی کاتالوگ تأیید نشد: '.($r['message'] ?? 'خطای نامشخص'));
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:300'],
            'max_order_minor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'margin_pct' => ['required', 'numeric', 'min:0', 'max:500'],
            'purchase_enabled' => ['nullable', 'boolean'], 'previous_checked' => ['nullable', 'boolean'],
        ]);
        $enabled = $request->boolean('purchase_enabled');
        if ($enabled && (! $request->boolean('previous_checked') || ! config('ovh.purchase_enabled')
            || Setting::get('ovh_connection_status') !== 'connected'
            || Setting::get('ovh_catalogue_pricing_hash') !== OvhOrders::cataloguePricingHash())) {
            return back()->withErrors('برای فعال‌سازی، دروازهٔ انتشار، اتصال خواندنی موفق و بررسی سفارش آزمایشی قبلی لازم است.');
        }
        if ($enabled && Schema::hasTable('ovh_orders') && OvhOrder::whereIn('phase', ['checkout_unknown', 'payment_unknown', 'install_unknown'])->exists()) {
            return back()->withErrors('ابتدا سفارش‌های نامشخص با زیرساخت تطبیق داده شوند.');
        }
        Setting::put('ovh_max_order_minor', (string) $data['max_order_minor']);
        Setting::put('cloud_margin_pct_ovh', (string) $data['margin_pct']);
        Setting::put('ovh_previous_order_checked', $request->boolean('previous_checked') ? '1' : '0');
        Setting::put('ovh_purchase_enabled', $enabled ? '1' : '0');
        ActivityLog::record(null, 'ovh_settings', 'OVH purchase gate '.($enabled ? 'enabled' : 'disabled').'; admin #'.$request->user()->id.'; '.$data['reason'], $request, 'staff');
        return back()->with('ok', 'تنظیمات و دلیل تغییر ثبت شدند.');
    }

    public function action(Request $request, OvhOrder $order, OvhClient $client, OvhOrders $orders)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate(['action' => ['required', 'in:reconcile,retry,resume,pause,link,reject_refund'],
            'reason' => ['required', 'string', 'min:10', 'max:300'], 'external_order_id' => ['nullable', 'integer', 'min:1'],
            'verified_cart' => ['nullable', 'accepted'], 'verified_no_external_order' => ['nullable', 'accepted']]);
        try {
            if ($data['action'] === 'reconcile') {
                $orders->provision($client, ['service_id' => $order->service_id], false, true);
                $orders->event($order->fresh(), 'admin_reconcile', $data['reason'], $request->user()->id);
                if ($order->fresh()->phase === 'ready' && $order->service && ! $order->service->isDead()) {
                    app(CloudProvisioner::class)->provision($order->service);
                }
            } else {
                if ($data['action'] === 'link' && ! $request->boolean('verified_cart')) {
                    return back()->withErrors('تأیید تطبیق دستیِ همین سبد با شماره سفارش خارجی الزامی است.');
                }
                $orders->adminAction($client, $order, $data['action'], $data['reason'], $request->user()->id,
                    isset($data['external_order_id']) ? (int) $data['external_order_id'] : null, $request->boolean('verified_no_external_order'));
            }
        } catch (OvhOrderHold $e) { return back()->withErrors($e->getMessage()); }
        return back()->with('ok', 'عملیات ثبت شد. پیگیری خواندنی، خرید جدید یا پرداخت را تکرار نمی‌کند.');
    }
}
