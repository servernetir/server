<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessEntry;
use App\Models\Service;
use App\Services\Finance\BusinessLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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
            'churn'      => $this->churnReasons(),
        ]);
    }

    /**
     * چرا مشتری‌ها سرورشان را حذف کردند — شمارشِ کدهای پایدار.
     *
     * ═══ چرا این‌جا و نه یک صفحهٔ تازه ═══
     *
     * صفحه‌ای که مدیر باز نمی‌کند، گزارشی است که وجود ندارد. این‌جا کنارِ سود و
     * زیان می‌نشیند، چون همان جنسِ سؤال است: پول از کجا می‌رود.
     *
     * ⚠️ ستون‌ها با مهاجرتِ دستیِ کارفرما ساخته می‌شوند. تا آن لحظه این متد
     * **باید** خالی برگردد و هیچ کوئری‌ای نزند، وگرنه کلِ صفحهٔ مالی ۵۰۰ می‌دهد
     * — یعنی یک بخشِ آماری، داشبوردِ اصلیِ کسب‌وکار را می‌خواباند.
     *
     * ⚠️ «بی‌پاسخ» جدا شمرده می‌شود و در نمودار پنهان نمی‌ماند: اگر نودوپنج
     * درصد چیزی نگویند، درصدهای بقیه بی‌معنی‌اند و مدیر باید همین را ببیند.
     *
     * @return array{total:int,answered:int,silent:int,rows:array<int,array{code:string,label:string,count:int,pct:int}>,notes:\Illuminate\Support\Collection<int,\App\Models\Service>}|array{}
     */
    private function churnReasons(): array
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'terminate_reason')) {
            return [];
        }

        $terminated = Service::whereIn('status', ['terminated', 'cancelled']);

        $counts = (clone $terminated)->whereNotNull('terminate_reason')
            ->selectRaw('terminate_reason, COUNT(*) as n')
            ->groupBy('terminate_reason')
            ->pluck('n', 'terminate_reason');

        $answered = (int) $counts->sum();
        $total = (int) (clone $terminated)->count();

        // ترتیبِ نمایش = ترتیبِ خودِ فهرست، نه ترتیبِ شمارش. با مرتب‌سازی روی
        // عدد، جای گزینه‌ها هر هفته عوض می‌شد و مقایسهٔ چشمیِ دو بازه سخت.
        $rows = [];

        foreach (Service::TERMINATE_REASONS as $code => $label) {
            $n = (int) ($counts[$code] ?? 0);

            if ($n === 0) {
                continue;
            }

            $rows[] = [
                'code'  => $code,
                'label' => $label,
                'count' => $n,
                'pct'   => $answered > 0 ? (int) round($n * 100 / $answered) : 0,
            ];
        }

        return [
            'total'    => $total,
            'answered' => $answered,
            'silent'   => max(0, $total - $answered),
            'rows'     => $rows,
            // متنِ آزاد جداست و عمداً فقط چند تای آخر: این ستون برای شمارش نیست،
            // برای خواندنِ حرفِ مشتری است.
            'notes'    => (clone $terminated)->whereNotNull('terminate_reason_note')
                ->orderByDesc('cancelled_at')->orderByDesc('id')->limit(8)->get(),
        ];
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
        /*
        | ردیفی که به یک **پرداختِ واقعی** وصل است حذف نمی‌شود؛ حذفش یعنی
        | ناسازگاری با دفترِ پرداخت.
        |
        | 🔴 شرط عمداً `source_id` است و نه `isAuto()`.
        |
        | `isAuto()` فقط می‌گوید «کاربر نساخته»، و از مرداد ۱۴۰۵ ردیف‌های
        | خودکارِ دیگری هم داریم که به هیچ پرداختی وصل نیستند: اجارهٔ ماهانهٔ
        | سرور (`servers:post-rent`). با شرطِ قبلی، یک غلطِ تایپی در مبلغِ
        | اجاره **برای همیشه** در دفتر می‌مانْد — نه حذف می‌شد، نه به‌روز
        | (`firstOrCreate` ردیفِ موجود را دست نمی‌زند) و نه روتِ ویرایشی وجود
        | دارد. یعنی سود و مالیات تا ابد غلط می‌ماندند.
        |
        | حذفِ ردیفِ اجاره بی‌خطر است: اجرای بعدیِ همان کرون، همان ماه را با
        | مبلغِ اصلاح‌شده دوباره می‌نشاند.
        */
        if ($entry->source_id !== null) {
            return back()->withErrors(['entry' => 'ردیف خودکار (درآمد/مالیات) حذف نمی‌شود؛ به پرداخت واقعی وصل است.']);
        }

        $entry->delete();

        return back()->with('ok', 'ردیف حذف شد.');
    }
}
