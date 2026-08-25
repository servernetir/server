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
    public function __construct(private TldPriceBook $book) {}

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
     * قیمتِ مؤثرِ یک سال تمدید — «قیمتِ تمدید با قیمتِ ثبت فرق دارد و باید
     * دوباره استعلام شود» (قاعدهٔ کارفرما، ۳ شهریور ۱۴۰۵).
     *
     * سه منبع، بلندترین برنده است:
     *
     *   ۱) `renew_toman`ِ ذخیره‌شده — قیمتِ روزِ خرید (برای پرمیوم تنها
     *      منبعِ درست، چون استعلامِ پسوندی قیمتِ پرمیوم را نمی‌بیند).
     *   ۲) **استعلامِ تازهٔ** قیمتِ تمدیدِ پسوند از `TldPriceBook` — اگر
     *      رجیسترار قیمتِ پسوند را بالا برده باشد، همین می‌گیردش. کشِ
     *      ۶ساعته + پشتِ مدارشکن؛ حجم هم کوچک است (صدور فاکتور یک بار در
     *      سال به‌ازای هر دامنه است، نه گردشِ روزانهٔ کرون) — پس قاعدهٔ
     *      «طوفانِ تماس ممنوع» نقض نمی‌شود.
     *   ۳) کفِ ارزی (`DomainCostFloor`) — پشتیبانِ بی‌تماس برای وقتی
     *      استعلام در دسترس نیست.
     *
     * ⚠️ استعلامِ تازه قیمت را فقط **بالا** می‌برد، پایین نه: پایین‌آوردن
     * برای دامنهٔ پرمیوم یعنی فروشِ زیرِ قیمت (استعلامِ پسوندی پرمیوم را
     * نمی‌بیند)، و برای بقیه «ارزان‌کردن» تصمیمِ مالیِ کارفرماست نه کارِ
     * خودکارِ کد — همان قاعدهٔ ثبت‌شدهٔ کف.
     */
    public function effectivePerYear(Domain $domain): int
    {
        $stored = (int) ($domain->renew_toman ?: $domain->price_toman);

        $tld = strtolower(ltrim((string) $domain->tld, '.'));
        $fresh = 0;

        try {
            $fresh = (int) data_get($this->book->fullForTlds([$tld]), $tld.'.renew', 0);
        } catch (\Throwable) {
            // استعلام‌نشدنی → پشتیبان‌ها (ذخیره + کف) تصمیم می‌گیرند
        }

        $floor = app(DomainCostFloor::class)->renewPerYear($domain);

        $per = max($stored, $fresh, $floor);

        if ($per > $stored && $stored > 0) {
            \App\Support\ErrorTracker::noteOnce('domain', 'renewal repriced above the stored figure', 3600, [
                'domain' => $domain->domain,
                'stored' => $stored,
                'fresh'  => $fresh,
                'floor'  => $floor,
            ]);
        }

        return $per;
    }

    /**
     * فاکتورِ تمدید — همان شکلِ فاکتورِ ثبت، با قیمتِ مؤثرِ روز.
     *
     * قیمت از `effectivePerYear()` می‌آید: ذخیره‌شده + استعلامِ تازهٔ پسوند +
     * کفِ ارزی، هرکدام بلندتر. عددِ نهایی روی خودِ ردیف هم می‌نشیند تا
     * صفحهٔ دامنه و فاکتور همیشه یک حرف بزنند.
     */
    public function issue(Domain $domain, int $years = 1): Invoice
    {
        $years = max(1, min(10, $years));
        $perYear = $this->effectivePerYear($domain);

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

            /*
            | ⚠️ قیمتِ مؤثر روی خودِ ردیف هم می‌نشیند: صفحهٔ دامنه، فهرست و
            | فاکتور باید همیشه یک عدد بگویند، و سالِ بعد هم مبنای تازه از
            | همین‌جا شروع شود — نه از قیمتِ دو سال پیش.
            */
            $per = intdiv((int) $unit, $years);

            if ($per > 0 && $per !== (int) $domain->renew_toman) {
                $domain->forceFill(['renew_toman' => $per])->save();
            }

            return $invoice;
        });
    }
}
