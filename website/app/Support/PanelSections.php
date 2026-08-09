<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * «چهار اتاق» — دادهٔ مشترکِ صفحهٔ سرویس‌ها و سه اتاقِ هاست/سرور/خدمات.
 *
 * ═══ چرا یک کلاسِ مشترک و نه منطق در چهار Blade ═══
 *
 * چهار صفحه همان چهار بخش را نشان می‌دهند (نمای «همه» همه‌شان را روی هم
 * می‌چیند). اگر تفکیکِ نوع یا شمارش در هر ویو دوباره نوشته شود، روزی یکی‌شان
 * کهنه می‌شود و مشتری یک سرویس را در دو جا یا در هیچ‌جا می‌بیند.
 *
 * 🔴 دو هزینهٔ پرس‌وجو که این‌جا **یک‌بار** پرداخت می‌شوند:
 *
 *  ۱) `Service::hoursLeft()` پشتِ پرده `creditBalance('IRT')` را صدا می‌زند، و
 *     آن یک `SUM` روی دفترِ اعتبار است. صدا زدنش داخلِ حلقه یعنی N پرس‌وجو
 *     برای N سرورِ ساعتی. این‌جا موجودی یک بار خوانده می‌شود و ساعتِ هر ردیف
 *     از همان مشتق می‌شود.
 *  ۲) فاکتورِ بازِ تمدیدِ دامنه: روی `Invoice` هیچ رابطهٔ `domain()` نیست، پس
 *     eager-load ممکن نیست و یک `whereIn` جمعی می‌گیریمشان — نه یک پرس‌وجو
 *     به ازای هر ردیف.
 */
final class PanelSections
{
    /**
     * @param  \App\Models\Customer|null  $customer
     * @param  Collection<int,Service>  $services  همان مجموعه‌ای که کنترلر گرفته
     * @return array<string,mixed>
     */
    public static function build($customer, Collection $services): array
    {
        $buckets = ['hosting' => collect(), 'server' => collect(), 'other' => collect()];

        foreach ($services as $s) {
            $kind = $s->kind();

            // سطلِ ناشناخته نداریم: `kind()` سه‌مقداری است، ولی اگر روزی
            // مقدارِ چهارمی برگرداند ردیف باید در «خدمات» بیفتد نه اینکه غیب شود.
            $buckets[isset($buckets[$kind]) ? $kind : 'other']->push($s);
        }

        $domains = self::domainsOf($customer);

        return [
            'secBuckets'  => $buckets,
            'secDomains'  => $domains,
            'secRenew'    => self::renewInvoices($domains),
            'secAttached' => self::attachedDomains($services),
            'secBalance'  => self::creditBalance($customer, $services),
            'secCounts'   => [
                'all'     => $services->count() + $domains->count(),
                'hosting' => $buckets['hosting']->count(),
                'server'  => $buckets['server']->count(),
                'domains' => $domains->count(),
                'other'   => $buckets['other']->count(),
            ],
        ];
    }

    /** @return Collection<int,Domain> */
    private static function domainsOf($customer): Collection
    {
        if ($customer === null || ! Schema::hasTable('domains')) {
            return collect();
        }

        /*
         * ترتیب = نزدیک‌ترین انقضا اول، ولی «در انتظارِ ثبت» بالای همه: دامنه‌ای
         * که مشتری پولش را داده و هنوز ثبت نشده تنها ردیفی است که ممکن است
         * کاری از ما بخواهد. (همان ترتیبی که DomainController::index دارد.)
         */
        return Domain::where('customer_id', $customer->id)
            ->alive()
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * فاکتورِ بازِ تمدید، به تفکیکِ دامنه.
     *
     * ⚠️ حتی وقتی «تمدید خودکار» خاموش است هم نشان داده می‌شود:
     * `RunDomainLifecycle` فاکتور را ۲۱ روز پیش از انقضا **بی‌توجه** به آن پرچم
     * صادر می‌کند، پس پنهان کردنش یعنی مشتری فاکتورِ تمدیدش را نمی‌بیند و
     * دامنه‌اش منقضی می‌شود.
     *
     * @param  Collection<int,Domain>  $domains
     * @return array<int,\App\Models\Invoice>
     */
    private static function renewInvoices(Collection $domains): array
    {
        if ($domains->isEmpty()
            || ! Schema::hasTable('invoices')
            || ! Schema::hasColumn('invoices', 'domain_id')) {
            return [];
        }

        return Invoice::whereIn('domain_id', $domains->pluck('id')->all())
            ->where('status', 'unpaid')
            ->get()
            ->keyBy('domain_id')
            ->all();
    }

    /**
     * نامِ دامنه‌هایی که چیزی رویشان سوار است — از همان مجموعهٔ ازقبل‌بارشده،
     * بی‌هیچ پرس‌وجوی تازه. کارتِ دامنهٔ خالی از این استفاده می‌کند تا پیشنهادِ
     * «میزبانی بگیرید» فقط جایی بیاید که واقعاً چیزی وصل نیست.
     *
     * @param  Collection<int,Service>  $services
     * @return array<string,true>
     */
    private static function attachedDomains(Collection $services): array
    {
        $map = [];

        foreach ($services as $s) {
            if (filled($s->domain)) {
                $map[mb_strtolower(trim((string) $s->domain))] = true;
            }
        }

        return $map;
    }

    /** موجودیِ اعتبار — فقط اگر واقعاً سرویسِ ساعتی‌ای در کار باشد */
    private static function creditBalance($customer, Collection $services): int
    {
        if ($customer === null || ! $services->contains(fn (Service $s) => $s->isHourly())) {
            return 0;
        }

        return max(0, $customer->creditBalance('IRT'));
    }

    /** «~N ساعت اعتبار» بدونِ پرس‌وجوی تازه به ازای هر ردیف */
    public static function hoursLeft(Service $service, int $balance): int
    {
        $rate = (int) $service->hourly_rate_irt;

        return $rate > 0 ? intdiv(max(0, $balance), $rate) : 0;
    }

    /**
     * وضعیتِ دامنه — کد ⇒ [کلاسِ قرص، کلیدِ ui، شمارِ اختیاری].
     *
     * 🔴 این تابع برای رفعِ یک **دروغِ زنده** است: `Domain::isActive()` فقط
     * `status === 'active'` را می‌سنجد و چرخهٔ عمر، دامنه را در کلِ ۳۰ روزِ
     * مهلتِ بازیابی روی همان `active` نگه می‌دارد. پس دامنه‌ای که دیروز منقضی
     * شده دقیقاً همان قرصِ سبزِ «فعال»ِ دامنهٔ سالم را می‌گرفت.
     *
     * @return array{0:string,1:string,2:int|null}
     */
    public static function domainState(Domain $d): array
    {
        $left = $d->daysLeft();

        if ($d->isActive() && $left !== null && $left < 0) {
            // مهلتِ بازیابی: هنوز قابلِ نجات، ولی قطعاً «فعال» نیست
            return ['danger', 'dmn_state_grace', max(0, Domain::EXPIRY_GRACE_DAYS + $left)];
        }

        if ($d->isActive() && $left !== null && $left <= 30) {
            return ['warn', 'dmn_state_soon', $left];
        }

        if ($d->isActive()) {
            return ['ok', 'dmn_state_active', null];
        }

        if ($d->provision_status === 'manual') {
            return ['warn', 'dmn_state_manual', null];
        }

        if ($d->isPending()) {
            return ['info', 'dmn_state_pending', null];
        }

        return ['mute', 'dmn_state_other', null];
    }
}
