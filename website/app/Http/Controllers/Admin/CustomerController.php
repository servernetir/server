<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مدیریت مشتریان — سمت کارکنان (جایگزین این بخش از WHMCS).
 *
 * روی گارد «web» می‌نشیند. این‌جا مدیر همهٔ مشتریان را می‌بیند، پروندهٔ کامل
 * هرکدام (هویت، بانک، فاکتور، پرداخت، اعتبار، تیکت) را باز می‌کند و وضعیت
 * حساب را عوض می‌کند. هیچ داده‌ی حساسی (کد ملی، شمارهٔ کامل کارت) این‌جا خام
 * نشان داده نمی‌شود — همان سیاست ذخیره‌سازیِ رمزنگاری‌شده.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        // نگهبان: تا جدول customers روی سرور ساخته نشده، پنل نباید ۵۰۰ شود
        if (! Schema::hasTable('customers')) {
            return view('admin.customers', [
                'customers' => collect()->paginate(30),
                'q'         => '',
                'status'    => 'all',
                'counts'    => ['all' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0],
                'notReady'  => true,
            ]);
        }

        $q      = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $query = Customer::query()
            ->withCount(['invoices', 'tickets'])
            ->with('identityVerification')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhereHas('identityVerification', function ($iv) use ($q) {
                        $iv->where('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%");
                    });
            });
        }

        if (in_array($status, ['active', 'pending', 'suspended', 'closed'], true)) {
            $query->where('status', $status);
        }

        return view('admin.customers', [
            'customers' => $query->paginate(30)->withQueryString(),
            'q'         => $q,
            'status'    => $status,
            'counts'    => [
                'all'       => Customer::count(),
                'active'    => Customer::where('status', 'active')->count(),
                'pending'   => Customer::where('status', 'pending')->count(),
                'suspended' => Customer::where('status', 'suspended')->count(),
            ],
            'notReady'  => false,
        ]);
    }

    public function show(Customer $customer): View
    {
        $load = [
            'identityVerification',
            'bankAccounts',
            'profiles',
            'ipRules',
            'invoices'      => fn ($q) => $q->orderByDesc('id')->limit(50),
            'payments'      => fn ($q) => $q->orderByDesc('id')->limit(50),
            'creditEntries' => fn ($q) => $q->orderByDesc('id')->limit(50),
            'tickets'       => fn ($q) => $q->orderByDesc('last_reply_at')->limit(50),
        ];

        // نگهبان: جدول services تازه اضافه شده؛ روی سروری که هنوز مهاجرت
        // نکرده نباید پرونده ۵۰۰ شود
        if (Schema::hasTable('services')) {
            $load['services'] = fn ($q) => $q->orderByDesc('id');
        }

        $customer->load($load);

        return view('admin.customer', [
            'c'             => $customer,
            'creditBalance' => $customer->creditBalance(),
            'services'      => $customer->relationLoaded('services') ? $customer->services : collect(),
            'activity'      => Schema::hasTable('activity_logs')
                ? \App\Models\ActivityLog::where('customer_id', $customer->id)->latest('id')->limit(20)->get()
                : collect(),
            'invoiceTotals' => [
                'count'  => $customer->invoices->count(),
                'unpaid' => $customer->invoices->whereIn('status', ['unpaid', 'partial', 'overdue'])->count(),
                'paid'   => $customer->invoices->where('status', 'paid')->sum('total'),
            ],
        ]);
    }

    /**
     * تغییر وضعیت حساب مشتری — فعال / معلق / بسته.
     *
     * معلق‌سازی سرویس را قطع نمی‌کند (آن جای دیگری است)، فقط دسترسیِ ورود و
     * خریدِ تازه را می‌بندد. عمل برگشت‌پذیر است، پس تأیید کافی است نه بیشتر.
     */
    public function status(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,pending,suspended,closed'],
        ]);

        $customer->status = $data['status'];
        $customer->save();

        $labels = [
            'active' => 'فعال', 'pending' => 'در انتظار',
            'suspended' => 'معلق', 'closed' => 'بسته',
        ];

        return back()->with('ok', 'وضعیت مشتری به «'.$labels[$data['status']].'» تغییر کرد.');
    }

    /**
     * تغییر رمز عبور مشتری توسط مدیر.
     *
     * رمز به‌صورت متن وارد فرم می‌شود ولی هرگز خام ذخیره نمی‌شود — cast مدل
     * (password => hashed) آن را هش می‌کند. مشتری با پیامک و بله خبردار می‌شود
     * که رمزش عوض شده؛ اگر کار خودش نبوده، فوراً می‌فهمد.
     */
    public function password(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ], [], ['password' => 'رمز عبور']);

        $customer->password = $data['password'];   // cast خودش hash می‌کند
        $customer->save();

        try {
            app(\App\Services\Notify\CustomerNotifier::class)->event(
                $customer,
                'password_changed',
                [],
                'رمز عبور حساب سرورنت شما توسط پشتیبانی تغییر کرد. اگر این کار را درخواست نکرده‌اید، فوراً با ما تماس بگیرید.',
            );
        } catch (\Throwable) {
            // اعلان نباید تغییر رمز را بشکند
        }

        \App\Models\ActivityLog::record($customer->id, 'password',
            'رمز عبور توسط پشتیبانی تغییر کرد', $request, 'staff');

        return back()->with('ok', 'رمز عبور مشتری تغییر کرد و به او اطلاع داده شد.');
    }
}
