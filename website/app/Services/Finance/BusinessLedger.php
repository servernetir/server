<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر مالی کسب‌وکار — ثبت و محاسبه در یک جا.
 *
 * هر عددی که داشبورد نشان می‌دهد از همین کلاس می‌آید، و هر عدد جمعِ ردیف‌های
 * واقعی است. هیچ‌جای دیگر برنامه حق ندارد مستقیم در جدول بنویسد؛ همه از این
 * درگاه رد می‌شوند تا قاعده‌ها (idempotency، جهت درست، ارز پایه) یک‌جا باشند.
 */
class BusinessLedger
{
    /** جهتِ هر نوع — تا کنترلر مجبور نباشد یادش باشد */
    private const DIRECTION = [
        'capital'       => 'in',
        'revenue'       => 'in',
        'tax_collected' => 'in',
        'expense'       => 'out',
        'tax_paid'      => 'out',
        'withdrawal'    => 'out',
        'refund'        => 'out',
    ];

    /** دسته‌های هزینه — کلید ثابت، برچسب در زبان */
    public const EXPENSE_CATEGORIES = [
        'server', 'api_kyc', 'api_sms', 'domain_wholesale',
        'payment_fee', 'salary', 'marketing', 'other',
    ];

    /**
     * تا وقتی جدول ساخته نشده (مثلاً روی سروری که هنوز مهاجرت نکرده)، هیچ
     * چیز نباید بشکند. این نگهبان همه‌جا مقدم است.
     */
    public function ready(): bool
    {
        return Schema::hasTable('business_ledger');
    }

    // ─────────────────────────────── ثبت ───────────────────────────────

    /**
     * ثبت خودکار درآمد از یک پرداختِ موفق.
     *
     * مبنا دار: از فاکتور واقعی خوانده می‌شود. بخش خدمت (subtotal) درآمد است
     * و بخش مالیات جدا، چون مالیات پول ما نیست.
     *
     * idempotent: unique روی (source, kind) در دیتابیس جلوی ثبت دوباره را
     * می‌گیرد، پس اگر پرداخت دو بار settle شود، درآمد دو بار ثبت نمی‌شود.
     */
    public function recordPayment(Payment $payment): void
    {
        if (! $this->ready() || ! $payment->isPaid()) {
            return;
        }

        $invoice = $payment->invoice;

        if ($invoice === null) {
            return;
        }

        // افزایش اعتبار درآمد نیست — بدهی است تا وقتی مشتری خرجش کند. درآمد
        // موقع مصرف اعتبار روی فاکتور خدمت شناسایی می‌شود، نه اینجا.
        if ($invoice->kind === 'topup') {
            return;
        }

        // سهم این پرداخت از فاکتور (ممکن است پرداخت جزئی باشد)
        $paid  = $payment->amount;
        $total = max(1, $invoice->total);

        // نسبت مالیات همان نسبت فاکتور است
        $taxPortion     = intdiv($invoice->tax * $paid, $total);
        $revenuePortion = $paid - $taxPortion;

        if ($revenuePortion > 0) {
            $this->post('revenue', $revenuePortion, occurredAt: $payment->paid_at, source: $payment,
                note: 'درآمد فاکتور '.$invoice->number, currency: $invoice->currency_code);
        }

        if ($taxPortion > 0) {
            // نوعِ متفاوت (tax_collected) خودش این ردیف را از revenue جدا
            // می‌کند، پس هر دو می‌توانند به یک پرداخت وصل شوند
            $this->post('tax_collected', $taxPortion, occurredAt: $payment->paid_at, source: $payment,
                note: 'مالیات فاکتور '.$invoice->number, currency: $invoice->currency_code);
        }
    }

    /**
     * ثبت خودکار هزینهٔ یک تماس API (زحل، پیامک، درگاه).
     *
     * این همان چیزی است که هزینه‌ها را «مبنا دار» می‌کند: هر استعلام زحل که
     * پول واقعی خرج می‌کند، همان لحظه به‌عنوان هزینه ثبت می‌شود، نه اینکه
     * صاحب کسب‌وکار آخر ماه حدس بزند چقدر خرج کرده.
     *
     * مبلغِ هر تماس را صاحب کسب‌وکار در «هزینه‌های سرویس‌ها»ی پنل می‌نویسد
     * (جدول service_costs)؛ اگر ننوشته باشد به config برمی‌گردد. تا وقتی عددی
     * وارد نشده و صفر است، هیچ ردیفی ثبت نمی‌شود — یعنی حدسِ اشتباه وارد گزارش
     * نمی‌شود، نه اینکه عددِ ساختگی جا بگیرد.
     *
     * @param  string  $ref  توضیح این تماس برای ردِ بازرسی (نه idempotency —
     *                       هر تماس یک خرج واقعی است، حتی تلاش دوباره)
     */
    public function recordApiCost(string $category, string $service, string $ref): void
    {
        if (! $this->ready()) {
            return;
        }

        $amount = \App\Models\ServiceCost::amountFor($service);

        if ($amount <= 0) {
            return;
        }

        // هر تماس API یک خرج واقعی است — حتی تلاش دوباره پول جدا می‌گیرد. پس
        // بدون dedup، یک ردیف به ازای هر تماس. سوءاستفاده را rate-limit بالادست
        // می‌گیرد، نه این‌جا.
        $this->post('expense', $amount, category: $category, occurredAt: now(),
            note: 'هزینهٔ '.$service.' — '.$ref);
    }

    /** ثبت خودکار بازگشت وجه به مشتری */
    public function recordRefund(Payment $payment, int $amount, ?string $note = null): void
    {
        if (! $this->ready() || $amount <= 0) {
            return;
        }

        $this->post('refund', $amount, occurredAt: now(), source: $payment,
            note: $note ?? ('بازگشت وجه پرداخت '.$payment->id),
            currency: $payment->currency_code);
    }

    /**
     * ثبت دستی توسط صاحب کسب‌وکار — سرمایه، هزینه، برداشت، پرداخت مالیات.
     */
    public function manual(string $kind, int $amount, ?string $category, ?Carbon $occurredAt, ?string $note, ?int $userId): ?BusinessEntry
    {
        if (! $this->ready() || $amount <= 0 || ! isset(self::DIRECTION[$kind])) {
            return null;
        }

        return $this->post($kind, $amount, category: $category, occurredAt: $occurredAt,
            note: $note, userId: $userId);
    }

    private function post(
        string $kind,
        int $amount,
        ?string $category = null,
        ?Carbon $occurredAt = null,
        $source = null,
        ?string $note = null,
        ?int $userId = null,
        string $currency = 'IRT',
    ): ?BusinessEntry {
        $values = [
            'currency_code' => $currency,
            'direction'     => self::DIRECTION[$kind],
            'category'      => $category,
            'amount'        => $amount,
            'occurred_at'   => ($occurredAt ?? now())->toDateString(),
            'note'          => $note,
            'created_by'    => $userId,
        ];

        // ردیفِ وصل به یک منبع (پرداخت) باید idempotent باشد: settle دوباره
        // نباید درآمد را دو بار ثبت کند. firstOrCreate روی کلید طبیعی
        // (منبع + نوع) این را تضمین می‌کند. ردیف دستی منبع ندارد، پس هر بار
        // یک ردیف تازه است — درست، چون سرمایه‌گذاری دوباره واقعاً رویداد نو است.
        if ($source !== null) {
            return BusinessEntry::firstOrCreate(
                ['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'kind' => $kind],
                $values,
            );
        }

        return BusinessEntry::create($values + ['kind' => $kind]);
    }

    // ────────────────────────────── محاسبه ──────────────────────────────

    /**
     * همهٔ اعداد داشبورد، از دفتر واقعی. یک بار پرس‌وجو، بقیه در PHP — تا
     * هر عدد از همان مجموعه ردیف بیاید و ناسازگاری پیش نیاید.
     *
     * @return array<string,int|float|array>
     */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        if (! $this->ready()) {
            return $this->empty();
        }

        $q = BusinessEntry::query()->where('currency_code', 'IRT');

        if ($from) {
            $q->whereDate('occurred_at', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('occurred_at', '<=', $to->toDateString());
        }

        // جمع به تفکیک نوع — یک پرس‌وجو
        $byKind = (clone $q)->selectRaw('kind, sum(amount) as s, count(*) as n')
            ->groupBy('kind')->get()->keyBy('kind');

        $sum = fn (string $k) => (int) ($byKind[$k]->s ?? 0);
        $cnt = fn (string $k) => (int) ($byKind[$k]->n ?? 0);

        $capital       = $sum('capital');
        $withdrawal    = $sum('withdrawal');
        $revenue       = $sum('revenue');
        $taxCollected  = $sum('tax_collected');
        $taxPaid       = $sum('tax_paid');
        $expense       = $sum('expense');
        $refund        = $sum('refund');

        $netCapital   = $capital - $withdrawal;
        $netProfit    = $revenue - $expense;              // مالیات پاس‌ترو است، در سود نمی‌آید
        $taxLiability = $taxCollected - $taxPaid;          // بدهی مالیاتی به دولت
        // نقدینگی = هر چه وارد شد منهای هر چه خارج شد
        $cash = $capital + $revenue + $taxCollected - $expense - $taxPaid - $withdrawal - $refund;

        // هزینه به تفکیک دسته
        $byCategory = (clone $q)->where('kind', 'expense')
            ->selectRaw('category, sum(amount) as s')
            ->groupBy('category')->pluck('s', 'category')
            ->map(fn ($v) => (int) $v)->toArray();

        return [
            'capital'        => $capital,
            'withdrawal'     => $withdrawal,
            'net_capital'    => $netCapital,
            'revenue'        => $revenue,
            'revenue_count'  => $cnt('revenue'),
            'expense'        => $expense,
            'expense_count'  => $cnt('expense'),
            'net_profit'     => $netProfit,
            'margin'         => $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0.0,
            'roi'            => $netCapital > 0 ? round($netProfit / $netCapital * 100, 1) : 0.0,
            'tax_collected'  => $taxCollected,
            'tax_paid'       => $taxPaid,
            'tax_liability'  => $taxLiability,
            'refund'         => $refund,
            'cash'           => $cash,
            'by_category'    => $byCategory,
        ];
    }

    /**
     * روند ماهانه — درآمد و هزینه و سود هر ماه، برای نمودار.
     *
     * @return array<int,array{label:string,revenue:int,expense:int,profit:int}>
     */
    public function monthlyTrend(int $months = 6): array
    {
        if (! $this->ready()) {
            return [];
        }

        // تابع استخراج ماه در SQLite و MariaDB فرق دارد — درایور را از قبل
        // تشخیص می‌دهیم، نه با تلاش‌وخطا (که روی MySQL خطا می‌دهد نه خالی)
        $ymExpr = $this->isMysql()
            ? "date_format(occurred_at, '%Y-%m')"
            : "strftime('%Y-%m', occurred_at)";

        $rows = BusinessEntry::query()
            ->where('currency_code', 'IRT')
            ->whereIn('kind', ['revenue', 'expense'])
            ->whereDate('occurred_at', '>=', now()->subMonthsNoOverflow($months - 1)->startOfMonth()->toDateString())
            ->selectRaw("{$ymExpr} as ym, kind, sum(amount) as s")
            ->groupBy('ym', 'kind')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->ym][$r->kind] = (int) $r->s;
        }

        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m   = now()->subMonthsNoOverflow($i);
            $ym  = $m->format('Y-m');
            $rev = $map[$ym]['revenue'] ?? 0;
            $exp = $map[$ym]['expense'] ?? 0;
            $out[] = [
                'label'   => $m->format('Y/m'),
                'revenue' => $rev,
                'expense' => $exp,
                'profit'  => $rev - $exp,
            ];
        }

        return $out;
    }

    private function isMysql(): bool
    {
        return in_array(BusinessEntry::query()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function empty(): array
    {
        return [
            'capital' => 0, 'withdrawal' => 0, 'net_capital' => 0,
            'revenue' => 0, 'revenue_count' => 0, 'expense' => 0, 'expense_count' => 0,
            'net_profit' => 0, 'margin' => 0.0, 'roi' => 0.0,
            'tax_collected' => 0, 'tax_paid' => 0, 'tax_liability' => 0,
            'refund' => 0, 'cash' => 0, 'by_category' => [],
        ];
    }
}
