<?php

namespace App\Console\Commands;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Services\Cloud\CloudPricing;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

/**
 * ممیزیِ مالیِ سرورهای ساعتی: هیچ سرویسی نباید زیرِ بهایِ تمام‌شدهٔ امروز شارژ شود.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما دید sn-svc-76 ساعتی €0.02 می‌پردازد در حالی که بهایِ خودِ ما €0.05
 * است — و هیچ‌جای سیستم این را نمی‌دید. علتِ ساختاری: نرخِ ساعتی در لحظهٔ
 * خرید روی `services.hourly_rate_irt` **قفل** می‌شود و مترِ ساعتی همان را
 * برای همیشه کسر می‌کند؛ اگر قیمتِ آن روزِ کاتالوگ خراب بوده باشد (sync
 * ناقص، نرخِ سردِ ارز، ردیفِ ارزان‌ترِ اسلاگِ مشترک) یا بهایِ زیرساخت بعداً
 * بالا برود، ضرر **در جریان و بی‌صدا** است.
 *
 * این فرمان فقط می‌خوانَد و گزارش می‌دهد؛ اصلاح با `cloud:hourly-reprice`
 * است (تصمیمِ مالی = دستِ مدیر).
 */
class CloudHourlyAudit extends Command
{
    protected $signature = 'cloud:hourly-audit {--notify : اعلانِ بله/ایمیل به مدیر اگر ضرری در جریان بود}';

    protected $description = 'مقایسهٔ نرخِ قفل‌شدهٔ هر سرورِ ساعتی با بهایِ تمام‌شدهٔ امروز';

    public function handle(): int
    {
        $services = Service::query()
            ->whereIn('status', ['active', 'awaiting_provision', 'suspended'])
            ->where('billing_mode', 'hourly')
            ->whereNotNull('hourly_rate_irt')
            ->where('hourly_rate_irt', '>', 0)
            ->orderBy('id')
            ->get();

        if ($services->isEmpty()) {
            $this->info('هیچ سرویسِ ساعتیِ فعالی نیست.');

            return self::SUCCESS;
        }

        /*
        | سلامتِ خودِ داده، پیش از قضاوت: اگر ستونِ بهایِ ساعتی نباشد یا خالی
        | باشد، ممیزی دارد با «ماهانه÷۷۲۰» می‌سنجد و «سبز»ش قابلِ اتکا نیست —
        | دقیقاً همین یک‌بار رخ داد (ستون بیرون از $fillable، sync بی‌صدا
        | دورش می‌ریخت و ممیزی سبزِ دروغ می‌داد).
        */
        if (! \Illuminate\Support\Facades\Schema::hasColumn('cloud_plans', 'cost_hour_eur_micro')) {
            $this->error('🔴 ستونِ cost_hour_eur_micro ساخته نشده — مهاجرتِ 000103 نخورده و بها از «ماهانه÷۷۲۰» است.');
        } else {
            $filled = \App\Models\CloudPlan::whereNotNull('cost_hour_eur_micro')->count();
            $this->line('ستونِ بهایِ ساعتی: پرشده روی '.$filled.' پلن'
                .($filled === 0 ? '  🔴 صفر است — cloud:sync هنوز نرخِ ساعتی نگرفته؛ این گزارش قابلِ اتکا نیست' : ''));
        }

        /*
        | حاشیه و سربار چاپ می‌شوند تا «سبز» قابلِ تفسیر باشد: حاشیه تصمیمِ
        | آزادِ مدیر است (حتی ۲٪)، ولی فقط وقتی سودِ واقعی است که سربارِ
        | رساندنِ پول (pricing_fx_fee_pct) در بها نشسته باشد — اگر صفر است و
        | مدیر واقعاً کارمزد/VAT می‌پردازد، همین سطر یادش می‌اندازد.
        */
        $pricing = app(CloudPricing::class);
        $fees = collect(['hetzner', 'aeza', 'salad'])
            ->mapWithKeys(fn ($p) => [$p => $pricing->fxFeePctFor($p)]);
        $this->line('حاشیهٔ فروش: '.$pricing->marginPct().'٪ · سربارِ بها: '
            .$fees->map(fn ($v, $k) => $k.' '.$v.'٪')->implode(' · ')
            .($fees->contains(fn ($v) => $v <= 0)
                ? '  ⚠️ زیرساختِ بی‌سربار داری — اگر برای رساندنِ پول به آن کارمزد/VAT می‌دهی، در /admin/settings (قیمت‌گذاری) واردش کن'
                : ''));

        $eurToman = (int) $pricing->eurToToman();
        $underwater = [];
        $stale = [];

        $this->line(sprintf('%-8s %-10s %-28s %-12s %-12s %-12s %s',
            'svc', 'infra', 'plan', 'locked€/h', 'cost€/h', 'price€/h', 'verdict'));

        foreach ($services as $s) {
            [$row, $providerLabel] = $this->currentRowFor($s);

            $lockedEur = $this->lockedEurPerHour($s, $eurToman);
            // 🔴 بهایِ ساعتیِ واقعی مقدم است: تحویلِ ساعتی با term=hour از نرخِ
            // ساعتیِ زیرساخت خریده می‌شود که می‌تواند ~۳×ِ «ماهانه÷۷۲۰» باشد.
            $hourMicro = (int) ($row->cost_hour_eur_micro ?? 0);
            $costEur = $row
                ? ($hourMicro > 0 ? round($hourMicro / 1_000_000, 4) : round(((int) $row->cost_eur_cents) / 720 / 100, 4))
                : null;
            $priceEur = $row ? round($row->hourlyEurCents() / 100, 4) : null;

            $verdict = 'ok';

            if ($row === null) {
                $verdict = 'no-plan?';
            } elseif ($costEur !== null && $lockedEur !== null && $lockedEur < $costEur) {
                // زیرِ بهایِ تمام‌شده = ضررِ نقد روی هر ساعتِ روشن
                $verdict = '🔴 UNDERWATER';
                $underwater[] = [$s, $lockedEur, $costEur, $providerLabel];
            } elseif ($priceEur !== null && $lockedEur !== null && $lockedEur < $priceEur) {
                // بالای بها ولی زیرِ قیمتِ امروزِ فروش = حاشیهٔ آب‌رفته
                $verdict = '🟡 stale';
                $stale[] = $s;
            }

            $this->line(sprintf('%-8s %-10s %-28s %-12s %-12s %-12s %s',
                '#'.$s->id,
                $providerLabel,
                mb_substr((string) ($row?->slug ?? $s->plan), 0, 27),
                $lockedEur === null ? '—' : number_format($lockedEur, 4),
                $costEur === null ? '—' : number_format($costEur, 4),
                $priceEur === null ? '—' : number_format($priceEur, 4),
                $verdict));
        }

        $this->newLine();
        $this->line('ساعتی: '.count($services).' سرویس · زیرِ بها: '.count($underwater).' · زیرِ قیمتِ روز: '.count($stale));

        foreach ($underwater as [$s, $locked, $cost, $providerLabel]) {
            // نوتِ پایدار در /admin/errors — امضا شاملِ شناسهٔ سرویس تا ردیفِ
            // دوم پشتِ گلوگاهِ ردیفِ اول ساکت نمانَد (قاعدهٔ ثبت‌شده)
            ErrorTracker::noteOnce('cloud',
                "سرورِ ساعتیِ #{$s->id} زیرِ بهایِ تمام‌شده شارژ می‌شود: قفل‌شده €".number_format($locked, 4)
                ."/h، بها €".number_format($cost, 4)."/h ({$providerLabel}). اصلاح: php artisan cloud:hourly-reprice --service={$s->id} --apply",
                21600);
        }

        if ($underwater !== [] && $this->option('notify')) {
            try {
                // پیامِ کاربردی: هر سرویس یک سطرِ کامل + دکمهٔ پروفایلِ مشتری
                $rows = ['اصلاح' => 'php artisan cloud:hourly-reprice --apply'];
                $buttons = [];

                foreach (array_slice($underwater, 0, 4) as [$s, $locked, $cost, $providerLabel]) {
                    $rows['#'.$s->id] =
                        ($s->customer ? $s->customer->displayName().' ('.$s->customer->code.')' : 'مشتری؟')
                        .' · '.((string) ($s->plan ?: $s->name))
                        .' · قفل‌شده €'.number_format($locked, 4).'/h، بها €'.number_format($cost, 4).'/h ('.$providerLabel.')';
                    $buttons[] = [[
                        'text' => '👤 مشتری #'.$s->id,
                        'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'c:'.$s->customer_id,
                    ]];
                }

                if (count($underwater) > 4) {
                    $rows['و'] = fa_num((string) (count($underwater) - 4)).' سرویسِ دیگر (cloud:hourly-audit را بزن)';
                }

                app(AdminNotifier::class)->event('ضررِ در جریان: سرورِ ساعتیِ زیرِ بها', $rows, null, '💸', $buttons);
            } catch (\Throwable) {
            }
        }

        return $underwater === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ردیفِ پلنی که هزینهٔ **واقعی** از آن می‌آید.
     *
     * 🔴 اسلاگِ مشترک بینِ زیرساخت‌هاست و تحویل می‌تواند روی زیرساختِ
     * گران‌ترِ همان اسلاگ رفته باشد؛ پس اول ردیفِ (اسلاگ + زیرساختِ
     * ماشینِ تحویل‌شده) و فقط در نبودش ردیفِ لحظهٔ خرید.
     */
    private function currentRowFor(Service $s): array
    {
        $bought = $s->cloud_plan_id ? CloudPlan::find($s->cloud_plan_id) : null;
        $instance = CloudInstance::where('service_id', $s->id)->latest('id')->first();
        $provider = $instance?->provider;

        if ($bought !== null && $provider !== null && $provider !== $bought->provider) {
            $delivered = CloudPlan::where('slug', $bought->slug)->where('provider', $provider)
                ->orderByDesc('id')->first();

            if ($delivered !== null) {
                return [$delivered, (string) $provider];
            }
        }

        return [$bought, (string) ($provider ?? $bought?->provider ?? '?')];
    }

    /**
     * نرخِ قفل‌شده به یورو در ساعت.
     *
     * ⚠️ منبعِ اول **تومان** است، نه ستونِ یورویی: کسرِ واقعیِ متر تومانی است
     * و `hourly_rate_eur` سنتیِ گردِ رو به بالاست (€0.0106 را €0.02 نشان
     * می‌داد — تقریباً دو برابر). ستونِ یورویی فقط پشتیبانِ نبودِ نرخ است.
     */
    private function lockedEurPerHour(Service $s, int $eurToman): ?float
    {
        if ($eurToman > 0 && (int) $s->hourly_rate_irt > 0) {
            return round(((int) $s->hourly_rate_irt) / $eurToman, 4);
        }

        $cents = (int) ($s->hourly_rate_eur ?? 0);

        return $cents > 0 ? round($cents / 100, 4) : null;
    }
}
