<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessEntry;
use App\Services\Finance\BusinessLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * داشبورد مالی کسب‌وکار — سمت صاحب.
 *
 * «چقدر سرمایه گذاشتم، چقدر سود کردم، چقدر مالیات گرفتم و دادم» — همه از
 * دفتر مالی واقعی (BusinessLedger)، نه عدد دستی.
 */
class FinanceController extends Controller
{
    public function __construct(private BusinessLedger $ledger) {}

    public function index(Request $request): View
    {
        // بازهٔ زمانی — پیش‌فرض «از ابتدا». دکمه‌های ماه/سال/کل داشبورد را عوض می‌کنند
        $range = $request->string('range', 'all')->toString();
        [$from, $to] = $this->range($range);

        return view('admin.finance', [
            'ready'   => $this->ledger->ready(),
            'range'   => $range,
            'summary' => $this->ledger->summary($from, $to),
            'trend'   => $this->ledger->monthlyTrend(6),
            'recent'  => $this->ledger->ready()
                ? BusinessEntry::orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get()
                : collect(),
            'categories' => BusinessLedger::EXPENSE_CATEGORIES,
        ]);
    }

    /**
     * ثبت دستی — سرمایه، هزینه، برداشت، پرداخت مالیات.
     *
     * مبلغ به تومان گرفته می‌شود (همان که صاحب در ذهن دارد) و چون واحد پایه
     * تومان است، بدون تبدیل ذخیره می‌شود.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind'        => ['required', 'in:capital,expense,withdrawal,tax_paid'],
            'amount'      => ['required', 'integer', 'min:1'],
            'category'    => ['nullable', 'in:'.implode(',', BusinessLedger::EXPENSE_CATEGORIES)],
            'occurred_at' => ['nullable', 'date'],
            'note'        => ['nullable', 'string', 'max:255'],
        ], [], [
            'amount' => 'مبلغ',
            'kind'   => 'نوع',
        ]);

        // دسته فقط برای هزینه معنی دارد
        $category = $data['kind'] === 'expense' ? ($data['category'] ?? 'other') : null;

        $entry = $this->ledger->manual(
            $data['kind'],
            (int) $data['amount'],
            $category,
            isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
            $data['note'] ?? null,
            $request->user()->id,
        );

        if ($entry === null) {
            return back()->withInput()->withErrors(['amount' => 'ثبت انجام نشد — شاید جدول دفتر مالی هنوز ساخته نشده است.']);
        }

        return back()->with('ok', 'ثبت شد.');
    }

    /** بازهٔ تاریخ برای فیلتر داشبورد */
    private function range(string $range): array
    {
        return match ($range) {
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year'  => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],   // کل تاریخچه
        };
    }

    public function destroy(BusinessEntry $entry): RedirectResponse
    {
        // فقط ردیف دستی قابل حذف است — ردیف خودکار (درآمد) به پرداخت واقعی
        // وصل است و حذفش یعنی ناسازگاری با دفتر پرداخت
        if ($entry->isAuto()) {
            return back()->withErrors(['entry' => 'ردیف خودکار (درآمد/مالیات) حذف نمی‌شود؛ به پرداخت واقعی وصل است.']);
        }

        $entry->delete();

        return back()->with('ok', 'ردیف حذف شد.');
    }
}
