<?php

namespace App\Services\Calendar;

use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * رجیستریِ لایه‌های تقویم: ثبت، ادغام، یکتاسازی، مرتب‌سازی.
 *
 * providerها از `config/calendar.php` خوانده می‌شوند، پس افزودنِ لایه هیچ
 * تغییری در این کلاس لازم ندارد.
 */
class CalendarService
{
    /** @var array<string, CalendarEventProvider> */
    private array $providers = [];

    /**
     * لایه‌هایی که در این اجرا از کار افتادند.
     *
     * ⚠️ فهرستِ خطاها **برگردانده** می‌شود و رابط کاربری نشانش می‌دهد. اگر
     * فقط `catch` می‌کردیم و رد می‌شدیم، لایه‌ای که به‌خاطرِ یک باگ خالی
     * برمی‌گردد از «لایه‌ای که واقعاً رویداد ندارد» قابلِ تشخیص نبود — و مدیر
     * یک تقویمِ آرام می‌دید در حالی که سررسیدها را از دست می‌داد.
     *
     * @var array<string,string>
     */
    private array $failures = [];

    /** آیا سقفِ هر لایه خورد؟ @var list<string> */
    private array $truncated = [];

    public function __construct()
    {
        $this->registerFromConfig();
    }

    public function register(string $layer, CalendarEventProvider $provider): static
    {
        $this->providers[$layer] = $provider;

        return $this;
    }

    /** @return array<string, CalendarEventProvider> */
    public function providers(): array
    {
        return $this->providers;
    }

    /** @return array<string,string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function truncatedLayers(): array
    {
        return $this->truncated;
    }

    /**
     * رویدادهای بازه، از لایه‌های خواسته‌شده.
     *
     * ⚠️ `null` و `[]` عمداً **یکی نیستند**: `null` یعنی «همهٔ لایه‌ها» (هیچ
     * ترجیحی داده نشده) و `[]` یعنی «هیچ لایه‌ای» (کاربر همهٔ چیپ‌ها را خاموش
     * کرده). اگر یکی گرفته می‌شدند، خاموش‌کردنِ همهٔ چیپ‌ها ناگهان همه‌چیز را
     * نشان می‌داد — دقیقاً برعکسِ چیزی که کاربر خواسته.
     *
     * @param  list<string>|null  $layers
     * @return Collection<int, CalendarItem>
     */
    public function events(Carbon $from, Carbon $to, ?array $layers = null): Collection
    {
        $this->failures = [];
        $this->truncated = [];

        $wanted = $layers ?? array_keys($this->providers);
        $max = (int) config('calendar.max_events_per_layer', 300);

        /** @var Collection<int, CalendarItem> $all */
        $all = collect();

        foreach ($this->providers as $layer => $provider) {
            /*
             * 🔴 چیپ = **نوعِ رویداد**، نه «کدام provider اجرا شود».
             *
             * `ManualEventProvider` تنها منبعی است که رویدادِ **هر نوعی**
             * می‌سازد: مدیر می‌تواند یادآوریِ دستی با نوعِ «سررسید پرداخت»
             * بسازد (اجارهٔ دفتر). اگر فقط بر اساسِ کلیدِ لایه اجرا می‌شد، آن
             * اجاره زیرِ چیپِ «یادآوری و کار» قایم می‌شد در حالی که رنگ و
             * آیکونش «سررسید پرداخت» است — یعنی خاموش‌کردنِ چیپِ پرداخت آن را
             * پنهان نمی‌کرد و کاربر دنبالِ چیپی می‌گشت که کار نمی‌کند.
             *
             * پس منبعِ چندنوعی هر وقت **دستِ‌کم یک لایه** خواسته شده اجرا
             * می‌شود، و فیلترِ واقعی پایین روی `type` اعمال می‌شود.
             */
            $emitsAnyType = config('calendar.layers.'.$layer.'.emits') === 'any';
            $wantedThis = in_array($layer, $wanted, true);

            if (! $wantedThis && ! ($emitsAnyType && $wanted !== [])) {
                continue;
            }

            $items = $this->safely($layer, $provider, $from, $to);

            if ($items->count() > $max) {
                $this->truncated[] = $layer;
                $items = $items->take($max);
            }

            $all = $all->concat($items);
        }

        // فیلترِ نهایی بر اساسِ نوع — همان چیزی که کاربر روی چیپ می‌بیند
        if ($layers !== null) {
            $all = $all->filter(fn (CalendarItem $i) => in_array($i->type, $wanted, true));
        }

        /*
         * 🔴 `sort()` با یک مقایسه‌گر، نه `sortBy()` با آرایه‌ای از دسترس‌گرها.
         *
         * `Collection::sortBy(array $comparisons)` آرایه را **مقایسه‌گرِ
         * دوآرگومانی** می‌فهمد (`fn($a,$b) => …`), نه دسترس‌گرِ تک‌آرگومانی.
         * نسخهٔ قبلی `fn(CalendarItem $i) => $i->dateKey()` می‌داد؛ PHP آرگومانِ
         * دومِ اضافه را برای closure بی‌صدا نادیده می‌گیرد، پس رشتهٔ `2026-08-11`
         * به‌جای نتیجهٔ مقایسه برمی‌گشت و `usort` آن را عددِ مثبت می‌خواند.
         *
         * نتیجه: تقویم رویدادها را **بی‌ترتیب** می‌داد (۲۰ مرداد، ۲۷ مرداد،
         * ۱۲ مرداد …) — و چون شبکهٔ ماه هر رویداد را بر اساسِ تاریخش در خانهٔ
         * درست می‌گذارد، در نمای ماه اصلاً دیده نمی‌شد. فقط نمای فهرست و ستونِ
         * «پیش‌رو» غلط بودند، یعنی دقیقاً جاهایی که ترتیب تنها معنایشان است.
         * هیچ خطایی، هیچ لاگی.
         *
         * `<=>` روی آرایه عنصربه‌عنصر مقایسه می‌کند، پس ترتیبِ کلیدها همان
         * ترتیبِ اولویت است.
         */
        return $this->dedupe($all)
            ->sort(fn (CalendarItem $a, CalendarItem $b) => [
                $a->dateKey(), $a->at->getTimestamp(), $a->type, $a->title,
            ] <=> [
                $b->dateKey(), $b->at->getTimestamp(), $b->type, $b->title,
            ])
            ->values();
    }

    /**
     * رویدادهای «پیش‌رو» — از امروز تا N روزِ آینده، به ترتیبِ زمانی.
     *
     * @param  list<string>|null  $layers
     * @return Collection<int, CalendarItem>
     */
    public function upcoming(?array $layers = null, ?int $days = null): Collection
    {
        $days = $days ?? (int) config('calendar.upcoming_days', 7);
        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');

        $from = Carbon::now($tz)->startOfDay();
        $to = $from->copy()->addDays(max(1, $days) - 1)->endOfDay();

        // رویدادِ لغوشده «پیش‌رو» نیست — کاری برای انجام ندارد
        return $this->events($from, $to, $layers)
            ->reject(fn (CalendarItem $i) => $i->status === 'cancelled')
            ->values();
    }

    /**
     * یک provider را طوری صدا می‌زند که خرابی‌اش کلِ تقویم را نکشد.
     *
     * @return Collection<int, CalendarItem>
     */
    private function safely(string $layer, CalendarEventProvider $provider, Carbon $from, Carbon $to): Collection
    {
        try {
            return $provider->getEvents($from, $to);
        } catch (\Throwable $e) {
            $this->failures[$layer] = $e->getMessage();

            /*
             * `noteOnce` و نه `note`: این متد در هر بار باز کردنِ تقویم و هر
             * ناوبریِ ماه صدا زده می‌شود، و پنجرهٔ ردیاب ۴۰۰ خط است — یک لایهٔ
             * خرابِ دائمی می‌توانست روزی صدها خط بنویسد و خطاهای گران‌قیمت را
             * بیرون بیندازد (همان سیلِ ۴۰۴ در CLAUDE.md).
             *
             * ⚠️ متنِ پیام شاملِ **نامِ لایه** است، و `noteOnce` روی هشِ همان متن
             * گلوگاه می‌گذارد. بی‌آن، خرابیِ لایهٔ دوم پشتِ گلوگاهِ لایهٔ اول
             * پنهان می‌مانْد.
             */
            ErrorTracker::noteOnce('calendar', 'لایهٔ تقویم «'.$layer.'» خطا داد: '.$e->getMessage(), 3600);

            return collect();
        }
    }

    /**
     * یکتاسازی بر اساسِ `uniqueKey()`.
     *
     * ⚠️ **اولین ردیف برنده است** و ترتیبِ ثبتِ لایه‌ها در config همان ترتیب
     * است. پس اگر روزی یک رویدادِ واقعاً یکسان از دو منبع بیاید، نسخه‌ای
     * می‌مانَد که لایه‌اش در config بالاتر است — یک تصمیمِ صریح، نه تصادف.
     *
     * @param  Collection<int, CalendarItem>  $items
     * @return Collection<int, CalendarItem>
     */
    private function dedupe(Collection $items): Collection
    {
        return $items->unique(fn (CalendarItem $item) => $item->uniqueKey());
    }

    /**
     * ساختِ providerها از config.
     *
     * ⚠️ کلاسِ نبود **رد** می‌شود نه اینکه استثنا بدهد: یک تایپو در config
     * نباید کلِ صفحه را بکشد، ولی باید در فهرستِ خرابی‌ها دیده شود.
     */
    private function registerFromConfig(): void
    {
        foreach ((array) config('calendar.layers', []) as $layer => $meta) {
            $class = $meta['provider'] ?? null;

            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                continue;
            }

            try {
                $provider = app($class);
            } catch (\Throwable $e) {
                $this->failures[$layer] = $e->getMessage();

                continue;
            }

            if ($provider instanceof CalendarEventProvider) {
                $this->register((string) $layer, $provider);
            }
        }
    }
}
