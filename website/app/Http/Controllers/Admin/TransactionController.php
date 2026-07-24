<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * تراکنش‌ها و اعتبار — دیدِ دقیقِ حسابداری برای صاحب.
 *
 * داشبورد مالی (FinanceController) سود و زیانِ کسب‌وکار را نشان می‌دهد، ولی
 * «افزایش اعتبار» عمداً آن‌جا نمی‌آید چون درآمد نیست، بلکه بدهیِ ما به مشتری
 * است. این صفحه همان تراکنش‌های ریز و اعتبارِ مشتریان را کامل نشان می‌دهد:
 * هر پرداخت با جزئیاتش، دفترِ اعتبار، و مجموعِ اعتباری که همین حالا به
 * مشتریان بدهکاریم.
 */
class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $ready = Schema::hasTable('payments') && Schema::hasTable('credit_ledger');

        if (! $ready) {
            return view('admin.transactions', [
                'ready'            => false,
                'kpis'             => ['credit' => 0, 'topups' => 0, 'paidSum' => 0, 'creditCustomers' => 0],
                'creditByCurrency' => collect(),
                'topCredit'        => collect(),
                'credit'           => collect(),
                'payments'         => collect(),
                'status'           => 'all',
                'gateway'          => 'all',
            ]);
        }

        // فیلترهای فهرستِ پرداخت
        $status  = $request->string('status', 'all')->toString();
        $gateway = $request->string('gateway', 'all')->toString();

        $payQ = Payment::query()->with('customer')->latest('id');
        if (in_array($status, ['paid', 'pending', 'redirected', 'failed', 'canceled', 'expired'], true)) {
            $payQ->where('status', $status);
        }
        if (in_array($gateway, ['zarinpal', 'bale', 'bank_transfer'], true)) {
            $payQ->where('gateway', $gateway);
        }
        $payments = $payQ->limit(150)->get();

        // دفترِ اعتبار (ریز)
        $credit = CreditEntry::with('customer')->latest('id')->limit(150)->get();

        // اعتبارِ کلِ مشتریان به تفکیکِ ارز — این «بدهیِ» شرکت است
        $creditByCurrency = CreditEntry::selectRaw('currency_code, SUM(amount) as bal')
            ->groupBy('currency_code')->pluck('bal', 'currency_code');

        // مشتریانِ دارای اعتبارِ مثبت — تومان (ارزِ اصلی) اول، بعد به‌ترتیبِ مبلغ.
        // (مرتب‌سازیِ خام روی مبلغ بینِ ارزها بی‌معنی است چون واحدِ فرعیِ EUR و
        // IRT هم‌مقیاس نیست.)
        $topCredit = CreditEntry::selectRaw('customer_id, currency_code, SUM(amount) as bal')
            ->groupBy('customer_id', 'currency_code')
            ->having('bal', '>', 0)
            ->orderByRaw("(currency_code = 'IRT') desc")
            ->orderByDesc('bal')->limit(50)->get();

        // تعدادِ مشتریانِ متمایزِ دارای اعتبار — بدونِ سقفِ ۵۰ و بدونِ شمارشِ
        // مضاعفِ زوجِ (مشتری، ارز)
        $creditCustomerCount = CreditEntry::selectRaw('customer_id, SUM(amount) as bal')
            ->groupBy('customer_id')
            ->having('bal', '>', 0)
            ->get()->count();

        $custMap = Customer::whereIn('id', $topCredit->pluck('customer_id')->unique()->all())
            ->get()->keyBy('id');
        foreach ($topCredit as $row) {
            $row->setRelation('customer', $custMap->get($row->customer_id));
        }

        $kpis = [
            'credit'          => (int) ($creditByCurrency['IRT'] ?? 0),                                   // بدهیِ اعتبار (تومان)
            'topups'          => (int) Invoice::where('kind', 'topup')->where('status', 'paid')->sum('total'),
            'paidSum'         => (int) Payment::where('status', 'paid')->where('currency_code', 'IRT')->sum('amount'),
            'creditCustomers' => $creditCustomerCount,
        ];

        return view('admin.transactions', [
            'ready'            => true,
            'kpis'             => $kpis,
            'creditByCurrency' => $creditByCurrency,
            'topCredit'        => $topCredit,
            'credit'           => $credit,
            'payments'         => $payments,
            'status'           => $status,
            'gateway'          => $gateway,
        ]);
    }
}
