<?php

namespace App\Services\Domain\Reseller;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerApiLog;
use App\Services\Domain\DomainRegistrar;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\TldGate;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * سفارشِ دامنه از راهِ APIِ نمایندگی — تنها جایی که پولِ نماینده خرج می‌شود.
 *
 * ═══ سه قاعده‌ای که این فایل را شکل داده‌اند ═══
 *
 * **۱) هر چیزی که ثبت را قطعاً شکست می‌دهد، پیش از گرفتنِ پول بررسی می‌شود.**
 * درسِ `zhina.shop`: پسوندی که قراردادش امضا نشده، پروفایلی که ناقص است،
 * دامنه‌ای که از قبل زنده است — همه‌شان اگر بعد از کسرِ اعتبار کشف شوند،
 * نماینده پولش رفته و دامنه‌ای ندارد و ما باید دستی برش گردانیم.
 *
 * **۲) کسرِ اعتبار اتمی است.** `credit_ledger` افزایشی است و موجودی
 * `SUM(amount)` است؛ بدونِ قفلِ ردیفِ مشتری، دو درخواستِ هم‌زمانِ WHMCS یک
 * موجودی را دو بار خرج می‌کنند و اعتبار منفی می‌شود.
 *
 * **۳) تلاشِ دوبارهٔ نماینده نباید دامنهٔ دوم بخرد.** قفلِ اتمیِ
 * `DomainRegistrar::register()` فقط از **همان ردیف** محافظت می‌کند؛ اگر
 * درخواستِ دوم یک ردیفِ `Domain` تازه بسازد، قفل بی‌اثر است و `findDomain()`
 * چون خودمان مالکیم «found» می‌دهد و ثبتِ دوم **موفق** اعلام می‌شود — دو
 * فاکتور، دو کسر، کدِ ۲۰۰، خرابیِ کاملاً خاموش. پس `Idempotency-Key` روی
 * ایندکسِ **یکتای دیتابیس** می‌نشیند، نه روی یک `if` کوئری‌محور که بینِ
 * خواندن و نوشتن پنجرهٔ رقابت دارد.
 */
class ResellerOrderService
{
    public function __construct(
        private DomainSearch $search,
        private DomainRegistrar $registrar,
        private ResellerPricing $pricing,
        private ResellerProgram $program,
    ) {}

    /** نتیجهٔ استاندارد — هر سه حالت ماشین‌خوان‌اند */
    private function fail(string $code, string $message, int $status = 422): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message, 'status' => $status];
    }

    // ═══════════════════════ ثبت ═══════════════════════

    /**
     * @param  array<int,string>  $nameServers
     * @return array<string,mixed>
     */
    public function register(Customer $customer, string $fqdn, int $years, array $nameServers = []): array
    {
        $fqdn = strtolower(trim($fqdn, ". \t\n\r"));
        [$sld, $tld] = Domain::splitFqdn($fqdn);

        if ($sld === '' || $tld === '') {
            return $this->fail('invalid_domain', 'نامِ دامنه معتبر نیست.');
        }

        $years = max(1, min($years, (int) config('domain_reseller.limits.max_years', 10)));

        // ── گیت ۱: پسوندی که می‌دانیم ثبت نمی‌شود را نفروش ──
        if (! DomainSearch::sells($tld)) {
            return $this->fail('tld_not_sold',
                'پسوندِ «.'.$tld.'» از این مسیر فروخته نمی‌شود.');
        }

        if (TldGate::isBlocked($tld)) {
            return $this->fail('tld_blocked',
                'ثبتِ پسوندِ «.'.$tld.'» موقتاً از سمتِ ما مقدور نیست. پولی کسر نشد.');
        }

        /*
        | ── گیت ۲: دامنهٔ زنده را دوباره نفروش ──
        |
        | 🔴 این فقط «تکراری نباشد» نیست. `scopeAwaitingRegistration` روی
        | `status='pending'` و `scopeAwaitingRenewal` روی `status='active'`
        | می‌نشینند و هر دو همان ستونِ `provision_status` را می‌خوانند. اگر
        | برای دامنه‌ای که از قبل `active` است ردیفِ ثبت بسازیم، **یک تمدید
        | به‌جای ثبت پردازش می‌شود** — همان فاجعه‌ای که `Domain::scopeAwaitingRenewal`
        | صریح دربارهٔ آن هشدار می‌دهد.
        */
        $existing = Domain::where('domain', $fqdn)->first();

        if ($existing !== null && ! $existing->isDead()) {
            return $this->fail(
                (int) $existing->customer_id === (int) $customer->id ? 'already_yours' : 'already_registered',
                'این دامنه در سامانهٔ ما فعال است.',
                409
            );
        }

        // ── گیت ۳: مشخصاتِ مالک، پیش از پول ──
        $profile = $customer->defaultProfile();

        if ($this->registrar->companyRegistrant() === null
            && ($profile === null || $this->registrar->profileToCustomer($profile) === null)) {
            return $this->fail('registrant_incomplete',
                'مشخصاتِ مالک در حسابِ نمایندگی کامل نیست (نام، نشانی، شهر، کدپستی، تلفن). '
                .'از پنل تکمیلش کنید؛ تا آن‌وقت هیچ ثبتی ممکن نیست.');
        }

        // ── قیمت: استعلامِ تازه، نه عددِ ورودی ──
        $quote = $this->freshQuote($fqdn, $tld);

        if (! $quote['ok']) {
            return $this->fail($quote['error'], $quote['message'], $quote['status'] ?? 422);
        }

        /** @var DomainQuote $q */
        $q = $quote['quote'];

        /*
        | سالِ اول قیمتِ خودش، سال‌های بعد قیمتِ تمدید.
        |
        | ⚠️ همان فرمولِ `Account\DomainController::order()` و نه یک محاسبهٔ
        | موازی. `sell_toman * $years` روی `.shop` (ثبت ۱۹۰ هزار، تمدید ۱٫۴۹
        | میلیون) یک ثبتِ سه‌ساله را با ~۲٫۶ میلیون تومان ضرر می‌فروخت.
        */
        $first = $this->pricing->forQuote($q, $customer, 'register');
        $renew = $this->pricing->forQuote($q, $customer, 'renew');

        $unit = $first['price'] + ($renew['price'] * max(0, $years - 1));

        if ($unit <= 0) {
            return $this->fail('no_price', 'برای این دامنه قیمتِ قابلِ اتکایی نداریم.');
        }

        return $this->charge(
            customer: $customer,
            fqdn: $fqdn,
            amount: $unit,
            title: 'ثبتِ دامنهٔ '.$fqdn,
            description: $years.' سال',
            build: function () use ($customer, $q, $fqdn, $sld, $tld, $years, $nameServers, $first, $renew, $existing) {
                $ns = $this->normalizeNs($nameServers);

                $domain = $existing ?? new Domain;

                $domain->forceFill([
                    'customer_id'      => $customer->id,
                    'domain'           => $fqdn,
                    'sld'              => $sld,
                    'tld'              => $tld,
                    'registrar'        => 'openprovider',
                    'status'           => 'pending',
                    // پرداخت همین حالا از اعتبار انجام شد ⇒ مستقیم به صفِ ثبت.
                    // (مسیرِ وب `none` می‌گذارد چون آن‌جا فاکتور هنوز پرداخت نشده.)
                    'provision_status' => 'pending',
                    'period_years'     => $years,
                    'price_toman'      => $first['price'],
                    'renew_toman'      => $renew['price'],
                    'cost_amount'      => (int) $q->cost_amount,
                    'cost_currency'    => (string) $q->cost_currency,
                    'quote_id'         => $q->id,
                    'name_servers'     => $ns,
                ])->save();

                $domain->putMeta([
                    'source'         => 'reseller_api',
                    'reseller_level' => $customer->reseller_level,
                    'retail_toman'   => $first['retail'],
                    'floored'        => $first['floored'],
                ]);

                return $domain;
            },
            pricing: $first,
        );
    }

    // ═══════════════════════ تمدید ═══════════════════════

    /**
     * @return array<string,mixed>
     */
    public function renew(Customer $customer, Domain $domain, int $years): array
    {
        $years = max(1, min($years, (int) config('domain_reseller.limits.max_years', 10)));

        if ((int) $domain->customer_id !== (int) $customer->id) {
            return $this->fail('not_found', 'دامنه پیدا نشد.', 404);
        }

        if (! $domain->isActive()) {
            return $this->fail('not_active', 'فقط دامنهٔ فعال تمدید می‌شود.', 409);
        }

        /*
        | 🔴 تمدیدِ در جریان را دوباره نفروش.
        |
        | ⚠️ این‌جا جدی‌تر از ثبت است: رجیستری ثبتِ تکراری را **رد** می‌کند
        | (دامنه از قبل مالِ ماست)، ولی تمدیدِ تکراری را **می‌پذیرد** و یک سالِ
        | دیگر پول می‌گیرد. یعنی تنها محافظِ تمدیدِ دوباره همین شرط و
        | `Idempotency-Key` است، نه هیچ رفتارِ طبیعیِ رجیستری.
        */
        if (in_array($domain->provision_status, ['pending', 'running'], true)) {
            return $this->fail('renewal_in_progress',
                'یک تمدید برای این دامنه در جریان است.', 409);
        }

        // قیمتِ تمدیدِ ذخیره‌شده مبناست، نه استعلامِ زنده — همان قاعدهٔ
        // `domains:lifecycle`: استعلامِ زنده روی هر تمدید یعنی صدها تماس با
        // حسابی که قبلاً به‌خاطرِ تماسِ زیاد علامت خورده.
        $retailPerYear = (int) ($domain->renew_toman ?: $domain->price_toman);

        if ($retailPerYear <= 0) {
            return $this->fail('no_price', 'قیمتِ تمدیدِ این دامنه ثبت نشده است.');
        }

        /*
        | ⚠️ `renew_toman` از قبل قیمتِ **نماینده** است (هنگام ثبت با تخفیف
        | ذخیره شده). پس دوباره تخفیف نمی‌خورد، وگرنه تخفیف روی تخفیف اعمال
        | می‌شد و هر تمدید ارزان‌تر از قبلی می‌شد تا زیرِ قیمتِ خرید برسد.
        */
        $storedPerYear = $retailPerYear;

        /*
        |----------------------------------------------------------------------
        | 🔴 محافظِ جهشِ ارز — گران‌ترین ضررِ خاموشِ این سامانه
        |----------------------------------------------------------------------
        |
        | `renew_toman` **در لحظهٔ ثبت** ذخیره شده، یعنی با نرخِ ارزِ آن روز.
        | تمدید یک سال بعد اتفاق می‌افتد و ما همان عدد را می‌گیریم — ولی به
        | رجیسترار نرخِ **امروز** می‌دهیم. در بازاری که نرخ در یک سال ده‌ها
        | درصد جابه‌جا می‌شود، این یعنی روی هر تمدید ضرر، روی **همهٔ** دامنه‌ها،
        | بی‌هیچ خطایی و بی‌هیچ ردی. و چون تمدید سالانه تکرار می‌شود، ضرر
        | انباشته است نه یک‌باره.
        |
        | ⚠️ چرا استعلامِ زنده راه‌حل نیست: `domains:lifecycle` روزانه روی همهٔ
        | دامنه‌ها می‌دود و استعلامِ زنده یعنی صدها تماس با حسابی که یک بار
        | به‌خاطرِ تماسِ زیاد علامت خورده. برای همین قیمت ذخیره می‌شود.
        |
        | راهِ درست هیچ تماسی لازم ندارد: بهایِ تمام‌شده در `cost_amount` و
        | `cost_currency` **همان‌جا روی ردیفِ دامنه** است. کافی است با نرخِ
        | امروز به تومان تبدیل شود و کفِ حاشیه رویش بنشیند. صفر تماسِ اضافه،
        | و ضررِ ساختاری بسته می‌شود.
        |
        | ⚠️ قیمت فقط **بالا** می‌رود، هرگز پایین نمی‌آید: اگر نرخ ارزان شده
        | باشد، تخفیفِ اضافه دادن تصمیمِ مالیِ کارفراست نه کارِ خودکارِ کد.
        */
        $floorPerYear = $this->renewFloor($domain);

        if ($floorPerYear > $retailPerYear) {
            $retailPerYear = $floorPerYear;

            ErrorTracker::noteOnce('domain', 'reseller renewal repriced to the cost floor', 3600, [
                'domain' => $domain->domain,
                'stored' => $storedPerYear,
                'floor'  => $floorPerYear,
            ]);
        }

        $amount = $retailPerYear * $years;

        return $this->charge(
            customer: $customer,
            fqdn: (string) $domain->domain,
            amount: $amount,
            title: 'تمدیدِ دامنهٔ '.$domain->domain,
            description: $years.' سال',
            build: function () use ($domain, $years) {
                $domain->putMeta(['renew_years' => $years]);
                $domain->forceFill(['provision_status' => 'pending'])->save();

                return $domain;
            },
            pricing: ['retail' => $amount, 'price' => $amount, 'floored' => false, 'discount_pct' => 0.0],
            kind: 'renew',
        );
    }

    /**
     * کفِ قیمتِ تمدید به **نرخِ امروز** — بی‌هیچ تماسِ شبکه‌ای.
     *
     * بهایِ تمام‌شده روی خودِ ردیفِ دامنه ذخیره است (`cost_amount` در واحدِ
     * فرعیِ ارزِ مبدأ، `cost_currency`). فقط تبدیلِ ارز لازم است، که از همان
     * منبعِ یگانهٔ نرخِ سایت می‌آید.
     *
     * ⚠️ اگر نرخ در دسترس نباشد **صفر** برمی‌گردد، یعنی محافظ خاموش می‌شود و
     * قیمتِ ذخیره‌شده می‌مانَد. این عمدی است: بستنِ تمدید به‌خاطرِ نبودِ نرخ
     * یعنی دامنهٔ مشتری منقضی شود — ضررِ قطعی در برابرِ ضررِ محتمل. ولی همان
     * حالت در ردیاب ثبت می‌شود، چون اگر نرخ **مدتی** نباشد، تمدیدها بی‌محافظ
     * رد می‌شوند و کسی نمی‌فهمد.
     */
    private function renewFloor(Domain $domain): int
    {
        $minor = (int) $domain->cost_amount;
        $currency = (string) $domain->cost_currency;

        if ($minor <= 0 || $currency === '') {
            return 0;
        }

        $rate = $this->search->rateFor($currency);

        if ($rate === null || $rate <= 0) {
            ErrorTracker::noteOnce('domain', 'renewal cost floor skipped — no FX rate', 3600, [
                'currency' => $currency,
            ]);

            return 0;
        }

        $costToman = ($minor / 100) * $rate;
        $minMargin = max(0.0, (float) config('domain_reseller.min_margin_pct', 8));

        $step = \App\Models\Currency::find('IRT')?->rounding_step ?: 1000;

        return (int) (ceil($costToman * (1 + $minMargin / 100) / $step) * $step);
    }

    // ═══════════════════════ پول ═══════════════════════

    /**
     * کسرِ اعتبار + فاکتورِ پرداخت‌شده + ردیفِ دامنه — همه در یک تراکنش.
     *
     * @param  callable():Domain  $build
     * @param  array<string,mixed>  $pricing
     * @return array<string,mixed>
     */
    private function charge(
        Customer $customer,
        string $fqdn,
        int $amount,
        string $title,
        string $description,
        callable $build,
        array $pricing,
        string $kind = 'register',
    ): array {
        $taxPct = (float) \App\Http\Controllers\Account\CloudStoreController::taxPercent();
        $tax = (int) round($amount * $taxPct / 100);
        $total = $amount + $tax;

        $result = null;

        try {
            DB::transaction(function () use (
                $customer, $fqdn, $amount, $tax, $total, $title, $description,
                $build, $taxPct, $kind, $pricing, &$result
            ) {
                /*
                | 🔴 قفلِ ردیفِ مشتری — بدونِ آن، دو درخواستِ هم‌زمان یک موجودی
                | را دو بار خرج می‌کنند. WHMCS خودش روی timeout درخواست را
                | دوباره می‌فرستد، پس این حالت فرضی نیست.
                */
                $fresh = Customer::whereKey($customer->id)->lockForUpdate()->first();

                if ($fresh === null) {
                    $result = $this->fail('account_inactive', 'حساب در دسترس نیست.', 403);

                    return;
                }

                $balance = $fresh->creditBalance('IRT');

                if ($balance < $total) {
                    $result = [
                        'ok'      => false,
                        'error'   => 'insufficient_credit',
                        'message' => 'اعتبارِ حساب کافی نیست.',
                        'status'  => 402,
                        'data'    => ['required' => $total, 'balance' => $balance, 'currency' => 'IRT'],
                    ];

                    return;
                }

                // سقفِ روزانه — محافظِ «توکن لو رفت»
                $cap = (int) ($fresh->reseller_daily_cap_irt ?: config('domain_reseller.limits.daily_spend_irt', 0));

                if ($cap > 0 && (ResellerApiLog::spentToday($fresh->id) + $total) > $cap) {
                    $result = [
                        'ok'      => false,
                        'error'   => 'daily_cap_reached',
                        'message' => 'سقفِ خرجِ روزانهٔ API پر شده است. فردا یا از پنل ادامه دهید.',
                        'status'  => 429,
                        'data'    => ['cap' => $cap, 'spent' => ResellerApiLog::spentToday($fresh->id)],
                    ];

                    return;
                }

                /** @var Domain $domain */
                $domain = $build();

                $invoice = Invoice::create([
                    'customer_id'   => $fresh->id,
                    'domain_id'     => $domain->id,
                    'kind'          => 'domain',
                    'currency_code' => 'IRT',
                    'subtotal'      => $amount,
                    'tax'           => $tax,
                    'total'         => $total,
                    'paid'          => $total,
                    // پرداخت‌شده از همان لحظه: پول واقعاً از اعتبار رفت. فاکتورِ
                    // «پرداخت‌نشده» این‌جا یعنی `services:lifecycle` روزی آن را
                    // معوق ببیند و سرویسِ سالم را تعلیق کند.
                    'status'        => 'paid',
                    'issued_at'     => now(),
                    'note'          => $title.' — API نمایندگی',
                ]);

                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'title'       => $title,
                    'description' => $description,
                    'quantity'    => 1,
                    'unit_price'  => $amount,
                    'line_total'  => $amount,
                    'tax_rate_bp' => (int) ($taxPct * 100),
                    'tax_amount'  => $tax,
                ]);

                /*
                | ⚠️ کلیدِ منبع `Domain` است نه `Customer` — همان درسِ سرورِ
                | ساعتی: مسیرِ برگشتِ وجه (`domains:resolve-stuck`) ردیف‌های
                | برگشتی را با `source_type`/`source_id` جمع می‌زند، و کلیدِ
                | اشتباه یعنی پولی که هرگز برنمی‌گردد.
                */
                CreditEntry::create([
                    'customer_id'   => $fresh->id,
                    'currency_code' => 'IRT',
                    'amount'        => -$total,
                    'balance_after' => $balance - $total,
                    'reason'        => 'domain_'.$kind.'_api',
                    'source_type'   => Domain::class,
                    'source_id'     => $domain->id,
                    'note'          => $title,
                ]);

                $result = [
                    'ok'      => true,
                    'domain'  => $domain,
                    'invoice' => $invoice,
                    'charged' => $total,
                    'pricing' => $pricing,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('reseller order failed', ['domain' => $fqdn, 'err' => $e->getMessage()]);
            ErrorTracker::note('domain', $e, ['area' => 'reseller-api', 'domain' => $fqdn]);

            return $this->fail('order_failed', 'سفارش انجام نشد. اعتبارِ شما کسر نشده است.', 500);
        }

        /*
        | ارتقای فوری — همان لحظه که تراکنش نشست، نه فردا صبح.
        |
        | نماینده‌ای که با همین خرید به پلهٔ بعد رسیده باید در **همین پاسخ**
        | قیمتِ بهتر را ببیند، وگرنه رابطهٔ علت و معلول را حس نمی‌کند و برنامه
        | فقط یک جدول روی کاغذ می‌مانَد.
        |
        | ⚠️ بیرونِ تراکنش و در `try`: بازبینیِ سطح یک کارِ **جانبی** است و
        | خطایش نباید سفارشِ پرداخت‌شده را برگردانَد. تنزل هم این‌جا رخ نمی‌دهد
        | (`review()` مهلت را رعایت می‌کند)، پس بدترین حالتش این است که ارتقا
        | تا اجرای کرونِ فردا صبح عقب بیفتد.
        */
        if (($result['ok'] ?? false) && config('domain_reseller.promote_instantly', true)) {
            try {
                $this->program->review($customer->refresh());
            } catch (\Throwable $e) {
                Log::info('reseller tier review skipped', ['err' => $e->getMessage()]);
            }
        }

        return $result ?? $this->fail('order_failed', 'سفارش انجام نشد.', 500);
    }

    // ═══════════════════════ تحویل ═══════════════════════

    /**
     * تلاشِ همزمان برای ثبت، با تورِ ایمنیِ کرون.
     *
     * ═══ چرا هم inline و هم صف، نه یکی از دو ═══
     *
     * WHMCS انتظار دارد `RegisterDomain` همان‌جا جواب بدهد؛ اگر همیشه
     * «pending» بگیریم، نماینده هر بار باید منتظرِ Sync بماند و تجربه‌اش
     * بدتر از رقباست. ولی تکیهٔ کامل به inline هم غلط است: تماس با رجیسترار
     * می‌تواند از بودجهٔ درخواست بلندتر شود.
     *
     * 🔴 و مهم‌ترین نکته: **شکستِ قفل «شکست» نیست.**
     * `DomainRegistrar::register()` وقتی اجرای دیگری (کرونِ هر-دقیقه) دامنه را
     * برداشته باشد `ok:false` می‌دهد — دقیقاً همان شکلی که شکستِ واقعی دارد.
     * اگر ماژولِ WHMCS آن را «ثبت ناموفق» بخواند، به نماینده می‌گوییم نشد در
     * حالی که ثبت **همان لحظه دارد موفق انجام می‌شود**، و او سفارش را لغو
     * می‌کند یا دوباره می‌فرستد. پس این حالت صریح `pending` است.
     */
    public function deliver(Domain $domain): string
    {
        try {
            $res = $this->registrar->register($domain);

            if ($res['ok'] ?? false) {
                return 'registered';
            }

            // قفل دستِ اجرای دیگری است ⇒ در جریان، نه شکست
            if (($res['manual'] ?? false) === false && str_contains((string) ($res['message'] ?? ''), 'اجرای دیگری')) {
                return 'pending';
            }

            return ($res['manual'] ?? false) ? 'manual' : 'failed';
        } catch (\Throwable $e) {
            /*
            | ⚠️ استثنا **هرگز** «شکست» خوانده نمی‌شود. تایم‌اوتِ وسطِ تماس
            | یعنی «نشنیدیم»، نه «رجیسترار نه گفت» — همان تمایزی که یک بار
            | حسابِ `zhina.shop` را روی WHM ساخت و ما به مشتری گفتیم نشد.
            | کرون با `findDomain()` واقعیت را کشف می‌کند.
            */
            Log::warning('reseller inline register did not finish', [
                'domain' => $domain->domain, 'err' => $e->getMessage(),
            ]);

            return 'pending';
        }
    }

    // ═══════════════════════ کمکی ═══════════════════════

    /**
     * استعلامِ تازه برای یک دامنه.
     *
     * 🔴 قیمت هرگز از ورودیِ نماینده نمی‌آید. اگر مبلغ را از بدنهٔ درخواست
     * بگیریم، هر نماینده‌ای می‌تواند دامنهٔ ده‌میلیونی را به هزار تومان سفارش
     * دهد — و برخلافِ مسیرِ وب، این‌جا هیچ انسانی صفحه را نمی‌بیند.
     *
     * @return array<string,mixed>
     */
    private function freshQuote(string $fqdn, string $tld): array
    {
        $rows = $this->search->search($fqdn, [$tld]);
        $row = null;

        foreach ($rows as $r) {
            if (strtolower((string) ($r['domain'] ?? '')) === $fqdn) {
                $row = $r;
                break;
            }
        }

        if ($row === null) {
            return ['ok' => false, 'error' => 'lookup_failed', 'message' => 'استعلامِ این دامنه ممکن نشد.', 'status' => 503];
        }

        $state = (string) ($row['state'] ?? DomainSearch::STATE_UNCHECKED);

        // ⚠️ «نمی‌دانم» هرگز «آزاد» خوانده نمی‌شود: فروشِ دامنهٔ گرفته‌شده یعنی
        // پولِ گرفته‌شده و ثبتِ شکست‌خورده.
        $mapped = match ($state) {
            DomainSearch::STATE_TAKEN       => ['taken', 'این دامنه ثبت شده است.', 409],
            DomainSearch::STATE_UNCHECKED   => ['lookup_failed', 'استعلامِ این دامنه ممکن نشد.', 503],
            DomainSearch::STATE_UNSUPPORTED => ['tld_not_sold', 'این پسوند فروخته نمی‌شود.', 422],
            DomainSearch::STATE_NO_PRICE    => ['no_price', 'قیمتِ قابلِ اتکایی برای این دامنه نداریم.', 422],
            default                         => null,
        };

        if ($mapped !== null) {
            return ['ok' => false, 'error' => $mapped[0], 'message' => $mapped[1], 'status' => $mapped[2]];
        }

        $quote = DomainQuote::find($row['quote_id'] ?? 0);

        if ($quote === null) {
            return ['ok' => false, 'error' => 'no_price', 'message' => 'استعلام ساخته نشد.', 'status' => 422];
        }

        return ['ok' => true, 'quote' => $quote, 'row' => $row];
    }

    /**
     * ⚠️ کمتر از دو نام‌سرور **جایگزین** می‌شود، نه رد.
     *
     * ثبت با یک نام‌سرور یعنی دامنه‌ای که به هیچ‌جا اشاره نمی‌کند: نماینده پول
     * داده، دامنه «فعال» است، و سایتِ مشتریِ او بالا نمی‌آید — و علتش هیچ‌جا
     * نوشته نشده.
     *
     * @param  array<int,string>  $ns
     * @return array<int,string>
     */
    private function normalizeNs(array $ns): array
    {
        $clean = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v, " \t.")),
            $ns
        ), fn ($v) => $v !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $v) === 1));

        return count($clean) >= 2 ? array_slice($clean, 0, 5) : Domain::defaultNameServers();
    }
}
