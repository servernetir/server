<?php

namespace App\Services\Cloud;

use App\Console\Commands\CloudMeterHourly;
use App\Http\Controllers\Account\CloudStoreController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\ComplimentaryServices;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تبدیلِ سرورِ ابریِ **ساعتی** به پرداختِ **دوره‌ای** (ماهانه / سه‌ماهه / شش‌ماهه / سالانه).
 *
 * ═══ چرا (مهر ۱۴۰۵، TK-261018-5379) ═══
 *
 * مشتری‌ای که ساعتی خریده بود می‌خواست ماهانه شود و تنها راهش تیکت بود. منطقِ
 * تبدیل از قبل در `CloudMeterHourly::tryConvertToMonthly()` بود — ولی فقط
 * **خودکار**، فقط **وقتی اعتبار تمام شود**، و فقط به **ماهانه**. کارفرما:
 * «زیرساختی حل کنیم که کاربر خودش بتواند تبدیل کند.»
 *
 * ═══ تصمیم‌های کارفرما ═══
 *
 *   قیمت       قیمتِ **امروزِ** پلن با تخفیفِ دوره — `CloudStoreController::priceForCycle()`،
 *              همان منبعِ خریدِ تازه. نه قیمتِ قفل‌شده هنگامِ خریدِ ساعتی: بعد از
 *              جهشِ ارز، آن عدد زیرِ بهای تمام‌شده است و ضررش تا یک سال قفل می‌شد.
 *   پرداخت     از کیف پول؛ اگر کم بود، فاکتورِ **افزایشِ اعتبار** و تبدیل پس از پرداخت.
 *   زیرساخت    یکی‌یکی، با فهرستِ صریحِ `billing.cycle_convert_providers` — اول هتزنر.
 *
 * ═══ 🔴 چرا فاکتورِ «افزایشِ اعتبار» و نه فاکتورِ متصل به سرویس ═══
 *
 * فاکتورِ پرداخت‌شده‌ای که `service_id` دارد در `PaymentService::applyPaid` یعنی
 * **تمدید**: سررسید را از `next_due_at ?? activated_at` یک دوره جلو می‌برد و به
 * `billing_mode` دست نمی‌زند. برای سرورِ ساعتی یعنی سررسیدی در گذشته و صورت‌حسابِ
 * ساعتیِ هم‌زمان — دو بار پول. به‌جای افزودنِ شاخهٔ تازه به حساس‌ترین مسیرِ پول،
 * فاکتورِ این‌جا یک افزایشِ اعتبارِ عادی است (پرآزموده‌ترین مسیر) و تبدیل بعد از
 * نشستنِ پول از **همان** `convert()` اجرا می‌شود. هر اتفاقی وسطِ راه بیفتد (سرور
 * حذف شد، مهلتِ قیمت گذشت، اعتبار با متر خورده شد)، پول در کیفِ خودِ مشتری
 * می‌مانَد — هیچ حالتی نیست که پول گرفته شود و نه تبدیل شود نه در دسترس بماند.
 *
 * ⚠️ `convert()` تنها تعریفِ «تبدیل» است؛ مترِ ساعتی هم (تبدیلِ خودکارِ اتمامِ
 * اعتبار) همین را صدا می‌زند. دو پیاده‌سازی یعنی روزی دو قیمت یا دو سررسید.
 */
final class HourlyCycleConverter
{
    /** کلیدِ درخواستِ در انتظار در `services.provision_meta` */
    public const META = 'cycle_convert';

    /** قیمتِ اعلام‌شده تا این مدت محترم است؛ بعد از آن با قیمتِ روز دوباره درخواست شود */
    public const QUOTE_TTL_HOURS = 24;

    /**
     * فاکتورِ افزایشِ اعتبار به‌اندازهٔ کسری + این‌همه ساعتِ مصرفِ ساعتی.
     *
     * ⚠️ بی‌این حاشیه، متر تا لحظهٔ پرداخت از همان کیف کم می‌کند و مبلغِ دقیقِ کسری
     *    دیگر کافی نیست — مشتری پول داده و تبدیل انجام نمی‌شود. مازاد در کیفِ خودِ
     *    مشتری می‌مانَد.
     */
    public const BUFFER_HOURS = 24;

    public function __construct(private Wallet $wallet) {}

    /** دوره‌های قابلِ انتخاب — از `config/billing.php`، نه فهرستِ سخت‌کد */
    public function cycles(): array
    {
        return array_values(array_filter(
            array_keys((array) config('billing.cycles', [])),
            fn ($c) => Service::monthsIn((string) $c) > 0,
        ));
    }

    /** زیرساختی که تبدیلش برای ما بی‌ضرر سنجیده شده — GPU هرگز */
    public function providerAllowed(?string $provider): bool
    {
        return $provider !== null && $provider !== 'salad'
            && in_array($provider, (array) config('billing.cycle_convert_providers', []), true);
    }

    /**
     * چرا این سرویس **نمی‌تواند** تبدیل شود؛ `null` یعنی می‌تواند.
     *
     * ⚠️ زیرساخت از **نمونهٔ تحویل‌شده** خوانده می‌شود نه از پلن: پلنِ سرویس
     *    نمایندهٔ «عرضه» است و زیرساختِ واقعی در لحظهٔ تحویل انتخاب می‌شود.
     *    سنجشِ هزینه دربارهٔ ماشینی است که واقعاً اجاره‌اش را می‌دهیم.
     */
    public function blocker(Service $service): ?string
    {
        if ($service->billing_mode !== 'hourly') {
            return 'not_hourly';
        }

        if (! $service->isCloud() || $service->cloudPlan === null) {
            return 'not_cloud';
        }

        if ($service->isInternalInfrastructure() || app(ComplimentaryServices::class)->applies($service)) {
            return 'internal';
        }

        if ($service->status !== 'active') {
            return 'not_active';
        }

        $instance = $service->cloudInstance;

        if ($instance === null || ! $instance->isDelivered()) {
            return 'not_delivered';
        }

        if (! $this->providerAllowed((string) $instance->provider)) {
            return 'provider';
        }

        if ($this->quote($service, 'monthly') <= 0) {
            return 'no_price';
        }

        return null;
    }

    /** مبلغِ یک دورهٔ کامل به تومان؛ صفر یعنی «قیمت نداریم» (مثلاً نرخِ یورو نیست) */
    public function quote(Service $service, string $cycle): int
    {
        $plan = $service->cloudPlan;

        if ($plan === null || Service::monthsIn($cycle) <= 0) {
            return 0;
        }

        return CloudStoreController::priceForCycle($plan, $cycle, (array) ($service->cloud_addons ?? []));
    }

    /**
     * جدولِ گزینه‌ها برای صفحه — همان اعدادی که `request()` کسر می‌کند.
     *
     * @return array<string, array{price:int, months:int, discount:int, monthly:int}>
     */
    public function quotes(Service $service): array
    {
        $out = [];

        foreach ($this->cycles() as $cycle) {
            $price = $this->quote($service, $cycle);

            if ($price <= 0) {
                continue;
            }

            $months = Service::monthsIn($cycle);
            $out[$cycle] = [
                'price' => $price,
                'months' => $months,
                'discount' => (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0),
                'monthly' => (int) ceil($price / max(1, $months)),
            ];
        }

        return $out;
    }

    /** پولِ قابلِ خرج برای تبدیل: «در دسترس» + ذخیرهٔ نگهداریِ همین سرور (با تبدیل آزاد می‌شود) */
    public function spendable(Service $service): int
    {
        $own = HourlyHold::enabled() ? (int) $service->hold_reserve_irt : 0;

        return $this->wallet->availableOf((int) $service->customer_id) + $own;
    }

    /** درخواستِ در انتظارِ معتبر (منقضی‌نشده) یا null */
    public function pending(Service $service): ?array
    {
        $intent = (array) (($service->provision_meta ?? [])[self::META] ?? []);

        if ($intent === [] || empty($intent['expires_at'])) {
            return null;
        }

        return Carbon::parse($intent['expires_at'])->isFuture() ? $intent : null;
    }

    /**
     * درخواستِ مشتری: اگر کیف کافی است همین حالا تبدیل؛ وگرنه فاکتورِ افزایشِ اعتبار.
     *
     * @return array{status: 'converted'|'invoice'|'error', reason?: string, invoice?: Invoice}
     */
    public function request(Service $service, string $cycle): array
    {
        if (! in_array($cycle, $this->cycles(), true)) {
            return ['status' => 'error', 'reason' => 'cycle'];
        }

        if (($why = $this->blocker($service)) !== null) {
            return ['status' => 'error', 'reason' => $why];
        }

        $price = $this->quote($service, $cycle);

        if ($price <= 0) {
            return ['status' => 'error', 'reason' => 'no_price'];
        }

        if ($this->convert($service, $cycle, $price, 'customer')) {
            return ['status' => 'converted'];
        }

        $service->refresh();

        /*
        | 🔴 `convert()` هم برای «پول کم است» false می‌دهد هم برای «دیگر ساعتی
        | نیست». دو تب یا دو کلیکِ پشتِ هم: اولی تبدیل می‌کند، دومی نباید
        | با کیفِ تازه‌خالی‌شده یک فاکتورِ تقریباً کلِ دوره بسازد.
        */
        if ($service->billing_mode !== 'hourly') {
            return $service->isDead() ? ['status' => 'error', 'reason' => 'not_active'] : ['status' => 'converted'];
        }

        if ($service->status !== 'active') {
            return ['status' => 'error', 'reason' => 'not_active'];
        }

        // 🔴 دو کلیکِ پشتِ هم نباید دو فاکتور بسازد: درخواستِ معتبرِ همان دوره با
        //    فاکتورِ هنوز پرداخت‌نشده‌اش برگردانده می‌شود.
        $existing = $this->pending($service);

        if ($existing !== null && ($existing['cycle'] ?? null) === $cycle) {
            $inv = Invoice::find($existing['invoice_id'] ?? 0);

            if ($inv !== null && in_array($inv->status, ['unpaid', 'partial'], true)) {
                return ['status' => 'invoice', 'invoice' => $inv];
            }
        }

        $hourly = max(0, (int) $service->hourly_rate_irt);
        $owed = $this->unmeteredHours($service) * $hourly;
        $need = $price + $owed - $this->spendable($service) + $hourly * self::BUFFER_HOURS;
        $amount = Product::roundUpToman(max(10_000, $need));

        $invoice = DB::transaction(function () use ($service, $cycle, $price, $amount, $existing): ?Invoice {
            $fresh = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();

            // همان سنجش زیرِ قفل — بینِ refresh و این‌جا ممکن است تبدیل یا تعلیق شده باشد
            if ($fresh->billing_mode !== 'hourly' || $fresh->status !== 'active') {
                return null;
            }

            // درخواستِ قبلیِ دورهٔ دیگر کنار می‌رود؛ پرداختِ دیرهنگامِ فاکتورش هم
            // (طبقِ `applyPaid`) به اعتبار می‌رود، پس پولی گم نمی‌شود.
            if ($existing !== null && ($old = Invoice::find($existing['invoice_id'] ?? 0)) !== null
                && in_array($old->status, ['unpaid'], true) && (int) $old->paid === 0) {
                $old->forceFill(['status' => 'canceled'])->save();
            }

            $inv = Invoice::create([
                'customer_id' => $fresh->customer_id,
                'kind' => 'topup',
                'currency_code' => 'IRT',
                'subtotal' => $amount,
                'tax' => 0,
                'total' => $amount,
                'status' => 'unpaid',
                'issued_at' => now(),
            ]);

            InvoiceItem::create([
                'invoice_id' => $inv->id,
                'title' => mb_substr(__('panel.cycle_convert_topup_item', [
                    'service' => $fresh->name, 'cycle' => Service::labelFor($cycle),
                ]), 0, 190),
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]);

            $meta = (array) ($fresh->provision_meta ?? []);
            $meta[self::META] = [
                'cycle' => $cycle,
                'price' => $price,
                'invoice_id' => $inv->id,
                'quoted_at' => now()->toIso8601String(),
                'expires_at' => now()->addHours(self::QUOTE_TTL_HOURS)->toIso8601String(),
            ];
            $fresh->forceFill(['provision_meta' => $meta])->save();

            return $inv;
        });

        if ($invoice === null) {
            return $service->fresh()?->billing_mode === 'cycle'
                ? ['status' => 'converted']
                : ['status' => 'error', 'reason' => 'not_active'];
        }

        return ['status' => 'invoice', 'invoice' => $invoice];
    }

    /**
     * 🔴 تنها تعریفِ «تبدیل» — مشتری، فاکتورِ پرداخت‌شده و مترِ ساعتی همه از این‌جا.
     *
     * اتمی: قفلِ مشتری ← قفلِ سرویس ← کسر. اگر سرویس دیگر ساعتی نیست (کلیکِ دوم،
     * اجرای هم‌زمانِ متر) یا پول کافی نیست، **هیچ** چیز عوض نمی‌شود و false.
     *
     * ⚠️ سررسید از **لحظهٔ تبدیل** است: سرورِ ساعتی دوره‌ای نداشته که از پایانش
     *    جلو برود (برخلافِ تمدید که از `next_due_at` جلو می‌رود).
     * ⚠️ `price` روی سرویس قفل می‌شود چون `services:renew-due` تمدیدِ بعدی را از
     *    همین ستون صورت‌حساب می‌کند — مثلِ هر سرورِ دوره‌ایِ دیگر.
     */
    public function convert(Service $service, string $cycle, int $price, string $actor, ?string $note = null, bool $settleHours = true): bool
    {
        $months = Service::monthsIn($cycle);

        if ($months <= 0 || $price <= 0 || $service->customer_id === null) {
            return false;
        }

        try {
            $done = DB::transaction(function () use ($service, $cycle, $price, $settleHours): bool {
                Customer::whereKey($service->customer_id)->lockForUpdate()->first();
                $fresh = Service::whereKey($service->id)->lockForUpdate()->first();

                /*
                | ⚠️ فقط سرورِ **فعال**. سرویسی که بینِ سنجشِ بیرونی و این قفل معلق
                | شد (متر خاموشش کرد یا مدیر بست) نباید با تبدیل دوباره «فعال»
                | نوشته شود: ماشین خاموش است و `status=active` دروغ می‌گفت.
                */
                if ($fresh === null || $fresh->billing_mode !== 'hourly' || $fresh->status !== 'active') {
                    return false;
                }

                $owed = $settleHours ? $this->unmeteredHours($fresh) : 0;
                $owedAmount = $owed * max(0, (int) $fresh->hourly_rate_irt);

                if ($this->spendable($fresh) < $price + $owedAmount) {
                    return false;
                }

                $meta = (array) ($fresh->provision_meta ?? []);
                unset($meta[self::META]);

                // اول ذخیرهٔ نگهداری آزاد، بعد کسر — هر دو در یک تراکنش؛ ردِ کسر همه را برمی‌گرداند
                $fresh->forceFill([
                    'billing_mode' => 'cycle',
                    'cycle' => $cycle,
                    'price' => $price,
                    'next_due_at' => now()->addMonthsNoOverflow(Service::monthsIn($cycle)),
                    'last_metered_at' => now(),
                    'provision_meta' => $meta,
                ] + (HourlyHold::enabled() ? ['hold_rate_irt' => 0, 'hold_reserve_irt' => 0] : []))->save();

                /*
                | ساعت‌هایی که متر اگر **همین الان** اجرا می‌شد کسر می‌کرد. بی‌این،
                | اگر متر عقب افتاده باشد آن ساعت‌ها بخشیده می‌شدند، چون دورهٔ
                | تازه از همین لحظه شروع می‌شود. همان فرمولِ متر: ساعت‌های **کاملِ**
                | سپری‌شده از `max(last_metered_at, ready_notified_at)`؛ کسرِ ساعتِ
                | جاری پیش‌پرداخت است.
                */
                if ($owedAmount > 0) {
                    $this->wallet->debit((int) $fresh->customer_id, 'IRT', $owedAmount, 'cloud_hourly', $fresh,
                        "کسرِ ساعتیِ سرورِ ابری تا لحظهٔ تبدیل — {$owed} ساعت × ".number_format((int) $fresh->hourly_rate_irt).' تومان');
                }

                $this->wallet->debit((int) $fresh->customer_id, 'IRT', $price, 'cloud_hourly_convert', $fresh,
                    'تبدیلِ سرورِ ساعتی به '.Service::labelFor($cycle).' — '.$fresh->name);

                return true;
            });
        } catch (WalletException) {
            return false;   // بینِ سنجش و کسر «در دسترس» عوض شد — چیزی تغییر نکرد
        }

        if (! $done) {
            return false;
        }

        $service->refresh();
        $customer = $service->customer;
        $prev = app()->getLocale();

        try {
            app()->setLocale($customer?->locale ?: 'fa');
            ActivityLog::forService($service, 'renew', $note ?? __('panel.cycle_convert_done_log', [
                'cycle' => Service::labelFor($cycle), 'amount' => invoice_money($price),
            ]), $actor);
        } catch (\Throwable) {
            // لاگ نباید تبدیلِ انجام‌شده را برگرداند
        } finally {
            app()->setLocale($prev);
        }

        return true;
    }

    /**
     * درخواست‌های در انتظار را اجرا می‌کند — پس از پرداختِ فاکتورِ افزایشِ اعتبار
     * و در هر اجرای مترِ ساعتی (اگر پرداخت از مسیرِ دیگری نشسته باشد).
     *
     * ⚠️ هرگز throw نمی‌کند: از داخلِ مسیرِ پرداخت صدا زده می‌شود.
     */
    public function runPending(?int $customerId = null): int
    {
        $done = 0;

        try {
            $rows = Service::query()
                ->where('billing_mode', 'hourly')
                ->whereNotNull('provision_meta')
                ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
                ->with(['customer', 'cloudPlan', 'cloudInstance'])
                ->get()
                ->filter(fn (Service $s) => ! empty(((array) $s->provision_meta)[self::META] ?? null));
        } catch (\Throwable) {
            return 0;
        }

        foreach ($rows as $service) {
            try {
                $intent = $this->pending($service);

                if ($intent === null || $this->blocker($service) !== null) {
                    // منقضی یا دیگر شدنی نیست — پول (اگر پرداخت شده) در کیف می‌مانَد
                    if ($intent === null || in_array($this->blocker($service), ['not_hourly', 'not_cloud', 'internal', 'provider'], true)) {
                        $this->forget($service);
                    }

                    continue;
                }

                if ($this->convert($service, (string) $intent['cycle'], (int) $intent['price'], 'customer')) {
                    $done++;
                }
            } catch (\Throwable $e) {
                ErrorTracker::noteOnce('billing', 'Cycle conversion failed for service #'.$service->id.' ('.$e::class.')', 3600);
            }
        }

        return $done;
    }

    /**
     * ساعت‌های کاملی که متر در اجرای بعدی کسر می‌کرد — **همان فرمولِ** `CloudMeterHourly::meterOne()`.
     *
     * ⚠️ تا ایمیلِ تحویل نرفته (`ready_notified_at` نال) متر هم کسر نمی‌کند، پس صفر.
     */
    public function unmeteredHours(Service $service): int
    {
        $ready = $service->cloudInstance?->ready_notified_at;

        if ($ready === null) {
            return 0;
        }

        $prev = $service->last_metered_at ?? $service->activated_at ?? $service->created_at;
        $prev = $prev instanceof Carbon ? $prev->copy() : Carbon::parse((string) $prev);

        if ($ready->gt($prev)) {
            $prev = $ready->copy();
        }

        return min(max(0, (int) floor($prev->diffInHours(now()))), CloudMeterHourly::CATCHUP_CAP);
    }

    private function forget(Service $service): void
    {
        $meta = (array) ($service->provision_meta ?? []);
        unset($meta[self::META]);
        $service->forceFill(['provision_meta' => $meta])->save();
    }
}
