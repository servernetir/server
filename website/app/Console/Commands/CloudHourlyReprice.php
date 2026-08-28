<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Console\Command;

/**
 * اصلاحِ نرخِ قفل‌شدهٔ سرورهای ساعتی به قیمتِ امروزِ همان پلن.
 *
 * ═══ قاعده‌ها (تصمیمِ کارفرما، ۶ شهریور ۱۴۰۵: «من نباید ضرر کنم») ═══
 *
 * · نرخِ تازه از ردیفِ (اسلاگِ خریداری‌شده + زیرساختِ ماشینِ تحویل‌شده)
 *   می‌آید — همان ردیفی که هزینهٔ واقعی از آن است؛ در نبودش ردیفِ خرید.
 * · فقط **بالا** می‌بریم. پایین‌آوردنِ خودکار تصمیمِ تجاری است، نه کارِ کد
 *   (همان قاعدهٔ renewFloor نمایندگی: «قیمت فقط بالا می‌رود»).
 * · بدونِ --apply هیچ‌چیز نوشته نمی‌شود — پیش‌نمایشِ بی‌عارضه (قاعدهٔ --dry).
 * · با --apply: نرخِ IRT و EUR هر دو، لاگِ فعالیت به زبانِ مشتری، و اعلان به
 *   مشتری — تغییرِ قیمتِ بی‌خبر اعتماد را می‌سوزاند.
 */
class CloudHourlyReprice extends Command
{
    protected $signature = 'cloud:hourly-reprice
        {--apply : واقعاً بنویس (بدونش فقط پیش‌نمایش)}
        {--service=* : فقط این شناسه‌ها}';

    protected $description = 'نرخِ ساعتیِ قفل‌شدهٔ زیرِ قیمتِ روز را به قیمتِ امروز برساند (فقط افزایش)';

    public function handle(): int
    {
        $q = Service::query()
            ->whereIn('status', ['active', 'awaiting_provision', 'suspended'])
            ->where('billing_mode', 'hourly')
            ->whereNotNull('hourly_rate_irt')
            ->where('hourly_rate_irt', '>', 0);

        $only = array_filter(array_map('intval', (array) $this->option('service')));

        if ($only !== []) {
            $q->whereIn('id', $only);
        }

        $apply = (bool) $this->option('apply');
        $changed = 0;

        foreach ($q->orderBy('id')->get() as $s) {
            $row = $this->currentRowFor($s);

            if ($row === null) {
                $this->warn("#{$s->id}: ردیفِ پلنِ امروزش پیدا نشد — دست‌نخورده.");

                continue;
            }

            $newIrt = $row->hourlyIrt();
            $newEur = $row->hourlyEurCents();
            $oldIrt = (int) $s->hourly_rate_irt;
            $oldEur = (int) ($s->hourly_rate_eur ?? 0);

            if ($newIrt <= 0 || $newIrt <= $oldIrt) {
                $this->line("#{$s->id}: قفل‌شده ".number_format($oldIrt).' ≥ روز '.number_format($newIrt).' — نیازی نیست.');

                continue;
            }

            $this->line(sprintf('#%d: %s → %s تومان/ساعت (€%s → €%s)  [%s/%s]',
                $s->id, number_format($oldIrt), number_format($newIrt),
                number_format($oldEur / 100, 4), number_format($newEur / 100, 4),
                $row->provider, $row->slug));

            if (! $apply) {
                continue;
            }

            $s->forceFill(['hourly_rate_irt' => $newIrt, 'hourly_rate_eur' => $newEur])->save();
            $changed++;

            $loc = $s->customer?->locale ?: 'fa';

            try {
                ActivityLog::forService($s, 'renew',
                    __('ui.act_hourly_reprice', ['old' => $this->money($oldIrt, $oldEur, $loc), 'new' => $this->money($newIrt, $newEur, $loc)], $loc),
                    'staff');
            } catch (\Throwable) {
            }

            try {
                if ($s->customer !== null) {
                    app(CustomerNotifier::class)->templated($s->customer, 'hourly_reprice', [
                        'service' => (string) $s->name,
                        'rate'    => $this->money($newIrt, $newEur, $loc),
                    ], __('ui.ntf_hourly_reprice_b', [
                        'service' => (string) $s->name,
                        'rate'    => $this->money($newIrt, $newEur, $loc),
                    ], $loc));
                }
            } catch (\Throwable) {
            }
        }

        $this->newLine();
        $this->info($apply ? "اعمال شد: {$changed} سرویس." : 'پیش‌نمایش بود — برای اعمال --apply.');

        return self::SUCCESS;
    }

    /** همان منطقِ cloud:hourly-audit — ردیفِ زیرساختِ تحویل‌شده مقدم است. */
    private function currentRowFor(Service $s): ?CloudPlan
    {
        $bought = $s->cloud_plan_id ? CloudPlan::find($s->cloud_plan_id) : null;
        $provider = CloudInstance::where('service_id', $s->id)->latest('id')->first()?->provider;

        if ($bought !== null && $provider !== null && $provider !== $bought->provider) {
            $delivered = CloudPlan::where('slug', $bought->slug)->where('provider', $provider)
                ->orderByDesc('id')->first();

            if ($delivered !== null) {
                return $delivered;
            }
        }

        return $bought;
    }

    /** «۱٬۲۰۰ تومان (€0.01)» برای فارسی، «€0.01» برای بقیه */
    private function money(int $irt, int $eurCents, string $loc): string
    {
        return $loc === 'fa'
            ? fa_num(number_format($irt)).' تومان در ساعت'
            : '€'.number_format($eurCents / 100, 4).'/h';
    }
}
