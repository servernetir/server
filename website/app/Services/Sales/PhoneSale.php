<?php

namespace App\Services\Sales;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Services\Billing\ProductInvoiceIssuer;
use Illuminate\Support\Facades\DB;

/**
 * فروشِ تلفنی — کارفرما پشتِ تلفن است و مشتری خودش نمی‌تواند سفارش بدهد.
 *
 * ═══ 🔴 قاعدهٔ اولِ این کلاس: قیمت **تایپ نمی‌شود** ═══
 *
 * `services.price` همان عددی است که `services:renew-due` تا ابد، هر دوره،
 * صورت‌حساب می‌کند. بازهٔ مجازش در پنل صفر تا صد میلیارد است و هیچ بررسیِ
 * منطقی ندارد — یعنی یک صفرِ اضافه روی صفحه‌کلیدِ گوشی، مشتری را برای همیشه
 * ده برابر شارژ می‌کند و هیچ چیزی جلویش را نمی‌گیرد.
 *
 * پس قیمت فقط از کاتالوگ می‌آید و از **همان** متدی که فروشگاهِ آنلاین
 * استفاده می‌کند (`Product::priceForCycle`) — با تخفیفِ دوره و ضریبِ مکان.
 * نسخهٔ دومِ این محاسبه یعنی همان پکیج از دو راه دو قیمت داشته باشد.
 *
 * ═══ چه چیزی این‌جا **نیست** ═══
 *
 * تخفیفِ درصدی. در پنل هست و این‌جا عمداً نیست: تخفیف روی `services.price`
 * می‌نشیند و مثلِ خودِ قیمت، تا ابد تکرار می‌شود. تصمیمی که تا ابد اثر دارد
 * جای صفحهٔ پنل است، نه یک تپ در چت.
 */
class PhoneSale
{
    /**
     * @return array{ok:bool,invoice:?Invoice,service:?Service,message:string}
     */
    public function sell(
        Customer $customer,
        Product $product,
        string $cycle,
        ?string $country = null,
        ?string $domain = null,
        ?int $actorId = null,
        ?string $actorName = null,
    ): array {
        if (! $product->is_active) {
            return $this->fail('این پکیج غیرفعال است.');
        }

        if (! in_array($cycle, Service::cycles(), true)) {
            return $this->fail('دورهٔ پرداخت معتبر نیست.');
        }

        /*
        | 🔴 مکان **در همین لحظه** سنجیده می‌شود، نه وقتی دکمه ساخته شد.
        |
        | بینِ نمایشِ دکمه‌ها و تپِ کارفرما ممکن است دقایقی بگذرد و ظرفیتِ آن
        | مکان پر شود. فروشِ مکانی که سرورِ آماده ندارد یعنی پولِ گرفته‌شده و
        | سرویسی که روی هوا می‌مانَد — همان چیزی که فروشگاهِ آنلاین هم
        | جلویش را می‌گیرد.
        */
        $server = null;

        if ($country !== null && $country !== '') {
            $server = Server::pickForCountry($country);

            if ($server === null) {
                return $this->fail('ظرفیتِ این مکان همین حالا پر شد؛ مکانِ دیگری را انتخاب کنید.');
            }
        }

        if ($product->requires_domain && ($domain === null || $domain === '')) {
            return $this->fail('این پکیج بدونِ دامنه تحویل نمی‌شود.');
        }

        $price = $product->priceForCycle($cycle, $country);

        if ($price <= 0) {
            /*
            | ⚠️ قیمتِ صفر یعنی کاتالوگ هنوز قیمت ندارد (مثلاً پلنِ ابری بی‌نرخِ
            | یورو). فروشش یعنی سروری که هزینه‌اش پای ماست و هرگز پولی برایش
            | نمی‌آید — و چون فاکتورِ صفر بی‌درنگ «پرداخت‌شده» رفتار می‌کند،
            | تحویل هم واقعاً انجام می‌شود.
            */
            return $this->fail('این پکیج قیمتِ معتبر ندارد و فروخته نمی‌شود.');
        }

        $locNote = $country
            ? 'محلِ سرور: '.trim((config('billing.locations.'.$country.'.flag') ?? '')
                .' '.(config('billing.locations.'.$country.'.label.fa') ?? $country))
            : '';

        try {
            $result = DB::transaction(function () use (
                $customer, $product, $cycle, $price, $server, $domain, $locNote, $actorId
            ) {
                $service = Service::create([
                    'customer_id'   => $customer->id,
                    'name'          => $product->name,
                    'description'   => trim(implode("\n", array_filter([
                        $product->description, $locNote, 'فروشِ تلفنی',
                    ]))),
                    'currency_code' => $product->currency_code,
                    'price'         => $price,
                    'tax_percent'   => (int) $product->tax_percent,
                    'cycle'         => $cycle,
                    'status'        => 'pending',
                    'server_id'     => $server?->id ?? $product->server_id,
                    'plan'          => $product->plan,
                    'is_reseller'   => $product->isReseller(),
                    'domain'        => $domain ?: null,
                    'created_by'    => $actorId,
                ]);

                return [$service, app(ProductInvoiceIssuer::class)->issue($service, $product)];
            });
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('sales', $e, [
                'step' => 'phone-sale', 'customer' => $customer->id, 'product' => $product->id,
            ]);

            return $this->fail('ثبتِ فروش انجام نشد.');
        }

        [$service, $invoice] = $result;

        ActivityLog::forService($service, 'purchase',
            'فروشِ تلفنیِ پکیج «'.$product->name.'» — '.Service::labelFor($cycle)
            .($country ? ' · '.$country : '')
            .' توسط مدیر ('.($actorName ?: 'مدیر').') ثبت و پیش‌فاکتور صادر شد',
            'staff');

        return ['ok' => true, 'invoice' => $invoice, 'service' => $service,
                'message' => 'پیش‌فاکتور صادر شد.'];
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'invoice' => null, 'service' => null, 'message' => $message];
    }
}
