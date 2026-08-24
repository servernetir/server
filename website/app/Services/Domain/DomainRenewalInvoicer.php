<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

/**
 * صدورِ فاکتورِ تمدیدِ دامنه — منبعِ واحد برای کرون و دکمهٔ «تمدید» مشتری.
 *
 * ═══ چرا سرویسِ جدا ═══
 *
 * تا مرداد ۱۴۰۵ صدورِ فاکتورِ تمدید فقط داخلِ `domains:lifecycle` بود؛ یعنی
 * مشتری **هیچ راهی برای تمدید نداشت** جز اینکه صبر کند کرون در ۲۱ روزِ آخر
 * فاکتور صادر کند: نه تمدیدِ زودتر، نه چندساله — حتی دکمه هم نبود.
 *
 * حالا دو مصرف‌کننده هست (کرون + کنترلرِ پنل). اگر منطق را کپی می‌کردیم، هر
 * اصلاحِ بعدی — مثلاً کفِ ارزی روی قیمتِ تمدید — باید دو جا اعمال می‌شد و
 * یکی‌اش حتماً جا می‌مانْد؛ همان الگوی «دو مسیرِ رفاند، دو مبلغِ متفاوت» که
 * یک بار در مسیرِ انتقال خورده‌ایم.
 *
 * ⚠️ این‌جا هیچ تماسی با رجیسترار نیست. قیمت از `renew_toman`ِ ذخیره‌شده
 * می‌آید (دلیل در `issue()`) و تمدیدِ واقعی پس از پرداخت با `domains:renew`.
 */
class DomainRenewalInvoicer
{
    /**
     * فاکتورِ بازِ همین دامنه — باز یعنی تمدید قبلاً صادر شده و صدورِ دوباره
     * فقط مشتری را دو بار به پرداختِ همان کار می‌کشانَد.
     */
    public function open(Domain $domain): ?Invoice
    {
        return Invoice::where('domain_id', $domain->id)
            ->whereIn('status', ['unpaid', 'draft', 'partial'])
            ->latest('id')
            ->first();
    }

    /**
     * فاکتورِ تمدید — همان شکلِ فاکتورِ ثبت، با مبلغِ `renew_toman`.
     *
     * ⚠️ `renew_toman` در لحظهٔ خرید ذخیره شده و ممکن است کهنه باشد؛ عمداً
     * همان را می‌گیریم و استعلامِ زنده نمی‌زنیم. کرونِ چرخهٔ عمر روزی یک‌بار
     * روی همهٔ دامنه‌ها می‌دود و استعلامِ زنده یعنی صدها تماسِ API در دقیقه به
     * رجیستراری که حسابش قبلاً به‌خاطرِ تماسِ زیاد علامت خورده.
     * اگر قیمت خیلی عقب افتاده باشد، مدیر از `/admin/domains` می‌بیند.
     */
    public function issue(Domain $domain, int $years = 1): Invoice
    {
        $years = max(1, min(10, $years));
        $perYear = (int) ($domain->renew_toman ?: $domain->price_toman);

        /*
        | 🔴 کفِ ارزی — «تمدید هرگز زیرِ بهای تمام‌شده فروخته نمی‌شود».
        |
        | `renew_toman` در روزِ خرید فریز شده و یک سال بعد، با جهشِ ارز، از
        | بهای امروزِ رجیسترار پایین‌تر می‌افتد: تا ممیزیِ شهریور ۱۴۰۵ این کرون
        | خودش فاکتورِ ضررده صادر می‌کرد. مسیرِ نمایندگی همین محافظ را داشت و
        | خرده‌فروشی نه. بدونِ تماسِ رجیسترار — فقط بهای ذخیره‌شده × نرخِ روز.
        */
        $floor = app(DomainCostFloor::class)->renewPerYear($domain);

        if ($floor > $perYear) {
            \App\Support\ErrorTracker::noteOnce('domain', 'retail renewal repriced to the cost floor', 3600, [
                'domain' => $domain->domain,
                'stored' => $perYear,
                'floor'  => $floor,
            ]);

            $perYear = $floor;
        }

        $unit = $perYear * $years;

        $taxPct = \App\Http\Controllers\Account\CloudStoreController::taxPercent();
        $tax = (int) round($unit * $taxPct / 100);

        return DB::transaction(function () use ($domain, $years, $unit, $tax, $taxPct) {
            $invoice = Invoice::create([
                'customer_id'   => $domain->customer_id,
                'domain_id'     => $domain->id,
                'kind'          => 'domain',
                'currency_code' => 'IRT',
                'subtotal'      => $unit,
                'tax'           => $tax,
                'total'         => $unit + $tax,
                'paid'          => 0,
                'status'        => 'unpaid',
                'issued_at'     => now(),
                'note'          => 'تمدیدِ دامنهٔ '.$domain->domain,
            ]);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'تمدیدِ دامنهٔ '.$domain->domain,
                'description' => $years.' سال',
                'quantity'    => 1,
                'unit_price'  => $unit,
                'line_total'  => $unit,
                'tax_rate_bp' => (int) ($taxPct * 100),
                'tax_amount'  => $tax,
            ]);

            // 🔴 چند سال پرداخت شده را همین‌جا ثبت کن. بعد از پرداخت، کرونِ
            //    تمدید باید بداند چند سال بخرد و راهِ دیگری برای دانستنش
            //    نیست — خواندنش از متنِ آیتمِ فاکتور شکننده است.
            //
            // 🔴 شناسهٔ فاکتور هم ثبت می‌شود تا اگر تمدیدِ پرداخت‌شده برای
            //    همیشه شکست خورد، `domains:resolve-stuck` بداند **کدام**
            //    فاکتور را برگرداند — نه «آخرین فاکتورِ پرداخت‌شده» که ممکن
            //    است فاکتورِ ثبتِ سالِ پیش باشد.
            $domain->putMeta(['renew_years' => $years, 'renew_invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
