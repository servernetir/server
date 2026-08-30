<?php

namespace App\Services\Domain\Reseller;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;

/**
 * سطح‌بندیِ نمایندگانِ دامنه — تعریف، اندازه‌گیری، و اعمال.
 *
 * ═══ چرا سطح یک ستونِ ذخیره‌شده است و نه یک محاسبهٔ لحظه‌ای ═══
 *
 * قیمتی که API به نماینده می‌دهد باید **قابلِ توضیح** باشد. اگر سطح در لحظهٔ
 * هر درخواست از روی جدولِ فاکتورها حساب شود، دو چیزِ بد هم‌زمان رخ می‌دهد:
 * قیمتِ صبح با قیمتِ ظهر فرق می‌کند (چون یک فاکتور از پنجرهٔ ۱۲ ماهه بیرون
 * افتاده)، و هر استعلامِ قیمت یک `sum()` روی کلِ تاریخچه می‌زند.
 *
 * پس سطح یک **عکس** است: `domains:reseller-tiers` روزانه به‌روزش می‌کند و
 * API و پنل و فاکتور همه از همان یک ستون می‌خوانند.
 */
class ResellerProgram
{
    /**
     * همهٔ پله‌ها، مرتب از پایین به بالا.
     *
     * ⚠️ مرتب‌سازی این‌جا انجام می‌شود و نه به ترتیبِ نوشتنِ config: یک ردیفِ
     * جابه‌جا در فایلِ تنظیمات نباید نردبان را وارونه کند.
     *
     * @return array<int,array<string,mixed>>
     */
    public function levels(): array
    {
        $levels = array_values(array_filter(
            (array) config('domain_reseller.levels', []),
            fn ($l) => is_array($l) && isset($l['key'])
        ));

        usort($levels, fn ($a, $b) => ($a['min_spend_irt'] ?? 0) <=> ($b['min_spend_irt'] ?? 0));

        return $levels;
    }

    /** پلهٔ پایه — کسی که هنوز هیچ نفروخته */
    public function baseLevel(): array
    {
        return $this->levels()[0] ?? [
            'key' => 'starter', 'name' => ['fa' => 'آغازین'],
            'min_spend_irt' => 0, 'min_active_domains' => 0, 'discount_pct' => 0,
        ];
    }

    /** یک پله را با کلیدش پیدا کن؛ کلیدِ ناشناخته → پلهٔ پایه */
    public function levelByKey(?string $key): array
    {
        foreach ($this->levels() as $l) {
            if ($l['key'] === $key) {
                return $l;
            }
        }

        /*
        | 🔴 کلیدِ ناشناخته به **پایین‌ترین** پله می‌افتد، نه به بالاترین.
        |
        | این حالت وقتی پیش می‌آید که مدیر پله‌ای را از config بردارد در حالی
        | که نماینده‌ای رویش نشسته. سقوط به پایه یک تخفیفِ ازدست‌رفته است و
        | نماینده شکایت می‌کند و ما می‌فهمیم؛ افتادن به بالاترین پله یعنی
        | تخفیفِ ناخواسته که **هیچ‌کس شکایتی ازش نمی‌کند** و ماه‌ها ادامه
        | می‌یابد. جهتِ خطا به سمتِ چیزی باشد که خودش را نشان می‌دهد.
        */
        return $this->baseLevel();
    }

    /** پلهٔ فعلیِ یک نماینده — همان چیزی که قیمت‌گذاری از آن می‌خواند */
    public function currentLevel(Customer $customer): array
    {
        return $this->levelByKey($customer->reseller_level);
    }

    /**
     * درصدِ تخفیفِ مؤثر: تخفیفِ سطح + پاداشِ دستیِ مدیر.
     *
     * ⚠️ سقفِ ۹۰٪ فقط یک محافظِ عقل است؛ محافظِ **واقعی** کفِ حاشیه در
     * `ResellerPricing` است. این‌جا صرفاً جلوی یک عددِ فاجعه‌بار در دیتابیس
     * (مثلاً پاداشِ ۹۹۹) گرفته می‌شود.
     */
    public function discountPct(Customer $customer): float
    {
        if (! $this->isReseller($customer)) {
            return 0.0;
        }

        $level = (float) ($this->currentLevel($customer)['discount_pct'] ?? 0);
        $bonus = (float) ($customer->reseller_bonus_pct ?? 0);

        return min(90.0, max(0.0, $level + $bonus));
    }

    public function isReseller(?Customer $customer): bool
    {
        return $customer !== null
            && (bool) config('domain_reseller.enabled', true)
            && (bool) ($customer->is_reseller ?? false);
    }

    // ═══════════════════════ اندازه‌گیری ═══════════════════════

    /**
     * حجمِ پنجره: چقدر پرداخت کرده و چند دامنهٔ زنده دارد.
     *
     * 🔴 معیار «پرداخت‌شده» است نه «سفارش‌شده». فاکتورِ پرداخت‌نشده هیچ پولی
     * برای ما نساخته؛ اگر بشمارد، هر کسی با هزار سفارشِ پرداخت‌نشده به
     * بالاترین سطح می‌رسد و بعد با تخفیفِ طلایی خرید می‌کند.
     *
     * ⚠️ فقط فاکتورِ **تومانی** شمرده می‌شود. جمع‌زدنِ IRT و EUR در یک عدد
     * یعنی یک فاکتورِ ۵۰ یورویی مثلِ ۵۰ تومان بشمارد.
     *
     * @return array{spend:int, active_domains:int, since:\Illuminate\Support\Carbon}
     */
    public function measure(Customer $customer): array
    {
        $since = now()->subMonths((int) config('domain_reseller.window_months', 12));

        $spend = Schema::hasColumn('invoices', 'domain_id')
            ? (int) Invoice::where('customer_id', $customer->id)
                ->where('kind', 'domain')
                ->where('status', 'paid')
                ->where('currency_code', 'IRT')
                ->where('issued_at', '>=', $since)
                ->sum('total')
            : 0;

        $active = (int) Domain::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->count();

        return ['spend' => $spend, 'active_domains' => $active, 'since' => $since];
    }

    /**
     * پله‌ای که این حجم **مستحقش** است.
     *
     * هر دو شرط لازم‌اند: مبلغ و تعدادِ دامنهٔ فعال. دومی یک **دروازه** است نه
     * معیار — بی‌آن، یک خریدِ بزرگِ یک‌باره (چند دامنهٔ گران) سطحی می‌سازد که
     * رفتارِ پایدارِ نمایندگی پشتش نیست.
     *
     * @param  array{spend:int, active_domains:int}  $m
     */
    public function levelFor(array $m): array
    {
        $earned = $this->baseLevel();

        foreach ($this->levels() as $l) {
            if ($m['spend'] >= (int) ($l['min_spend_irt'] ?? 0)
                && $m['active_domains'] >= (int) ($l['min_active_domains'] ?? 0)) {
                $earned = $l;
            }
        }

        return $earned;
    }

    /** جایگاهِ یک پله در نردبان (۰ = پایین‌ترین) */
    public function indexOf(string $key): int
    {
        foreach ($this->levels() as $i => $l) {
            if ($l['key'] === $key) {
                return $i;
            }
        }

        return 0;
    }

    // ═══════════════════════ اعمال ═══════════════════════

    /**
     * بازبینیِ سطحِ یک نماینده و نوشتنش.
     *
     * ═══ پاداشِ فوری، جریمهٔ کُند — و چرا عدم‌تقارن عمدی است ═══
     *
     * ارتقا همان لحظه اعمال می‌شود تا رفتار را شکل دهد: نماینده‌ای که امروز
     * به پلهٔ بعد رسیده باید **همین امروز** قیمتِ بهتر ببیند، وگرنه رابطهٔ
     * علت و معلول را حس نمی‌کند و برنامه فقط یک جدول روی کاغذ می‌مانَد.
     *
     * تنزل اما با مهلت است و حداکثر یک پله. بی‌آن، نماینده‌ای که یک ماهِ
     * کم‌فروش داشته صبح از دست می‌دهد چیزی را که یک سال ساخته — و همان روز
     * سراغِ رقیب می‌رود. برنامهٔ وفاداری‌ای که وفاداری را مجازات کند، برنامهٔ
     * وفاداری نیست.
     *
     * @return array{changed:bool, from:?string, to:string, reason:string}
     */
    public function review(Customer $customer, bool $save = true): array
    {
        $m = $this->measure($customer);
        $earned = $this->levelFor($m);

        $currentKey = $customer->reseller_level ?: $this->baseLevel()['key'];
        $currentIdx = $this->indexOf($currentKey);
        $earnedIdx = $this->indexOf($earned['key']);

        $targetKey = $currentKey;
        $reason = 'unchanged';

        if ($earnedIdx > $currentIdx) {
            // ارتقا — فوری و بی‌قیدوشرط
            $targetKey = $earned['key'];
            $reason = 'promoted';
        } elseif ($earnedIdx < $currentIdx) {
            $lockedUntil = $customer->reseller_level_locked_until;

            if ($lockedUntil === null) {
                /*
                | اولین باری که افت دیده می‌شود، **چیزی عوض نمی‌شود** — فقط
                | ساعتِ مهلت روشن می‌شود. یعنی نماینده یک ماه فرصت دارد و
                | خودش هم در پنل می‌بیند که مهلتی در جریان است.
                */
                $targetKey = $currentKey;
                $reason = 'grace_started';

                if ($save) {
                    $customer->forceFill([
                        'reseller_level_locked_until' => now()
                            ->addDays((int) config('domain_reseller.demote_grace_days', 30)),
                    ])->save();
                }
            } elseif ($lockedUntil->isFuture()) {
                $targetKey = $currentKey;
                $reason = 'grace_running';
            } else {
                // مهلت تمام شد — حداکثر یک پله پایین
                $steps = (int) config('domain_reseller.demote_max_steps', 1);
                $newIdx = max($earnedIdx, $currentIdx - max(1, $steps));
                $targetKey = $this->levels()[$newIdx]['key'] ?? $this->baseLevel()['key'];
                $reason = 'demoted';
            }
        }

        $changed = $targetKey !== $currentKey;

        if ($save) {
            $customer->forceFill([
                'reseller_level'             => $targetKey,
                'reseller_volume'            => $m['spend'],
                'reseller_level_reviewed_at' => now(),
                // مهلت فقط وقتی پاک می‌شود که دیگر افتی در کار نباشد
                'reseller_level_locked_until' => in_array($reason, ['promoted', 'unchanged', 'demoted'], true)
                    ? null
                    : $customer->reseller_level_locked_until,
            ])->save();
        }

        return ['changed' => $changed, 'from' => $currentKey, 'to' => $targetKey, 'reason' => $reason];
    }

    /**
     * دادهٔ نمایشیِ «کجای نردبانم» برای پنلِ نماینده.
     *
     * ⚠️ هم مبلغ و هم تعدادِ دامنه برمی‌گردد، چون هر کدام می‌توانند گلوگاه
     * باشند. نشان‌دادنِ فقط یکی یعنی نماینده‌ای که مبلغش رسیده ولی دامنهٔ
     * فعالش کم است، نوارِ پیشرفتِ پُر می‌بیند و ارتقا نمی‌گیرد — و ما را
     * بدقول می‌داند.
     *
     * @return array<string,mixed>
     */
    public function progress(Customer $customer): array
    {
        $m = $this->measure($customer);
        $current = $this->currentLevel($customer);
        $levels = $this->levels();
        $next = $levels[$this->indexOf($current['key']) + 1] ?? null;

        $pct = 0.0;

        if ($next !== null) {
            $needSpend = max(1, (int) ($next['min_spend_irt'] ?? 0));
            $needDoms = max(1, (int) ($next['min_active_domains'] ?? 0));

            // کمترینِ دو نسبت — یعنی نوار همان چیزی را نشان می‌دهد که واقعاً مانع است
            $pct = min(100.0, 100.0 * min(
                $m['spend'] / $needSpend,
                $m['active_domains'] / $needDoms,
            ));
        }

        return [
            'level'          => $current,
            'next'           => $next,
            'spend'          => $m['spend'],
            'active_domains' => $m['active_domains'],
            'since'          => $m['since'],
            'percent'        => round($pct, 1),
            'discount_pct'   => $this->discountPct($customer),
            'bonus_pct'      => (float) ($customer->reseller_bonus_pct ?? 0),
            'grace_until'    => $customer->reseller_level_locked_until,
        ];
    }
}
