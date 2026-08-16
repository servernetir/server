<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Domain\Reseller\ResellerProgram;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * بازبینیِ روزانهٔ سطحِ نمایندگان.
 *
 * ⚠️ محاسبه روزانه است ولی **تنزل** فقط بعد از مهلت اعمال می‌شود
 * (`ResellerProgram::review()`). بی‌آن، نماینده هر صبح سطحِ دیگری می‌بیند و
 * برنامه‌ای که قرار بود رفتار بسازد، غیرقابلِ پیش‌بینی می‌شود.
 */
class ReviewResellerTiers extends Command
{
    protected $signature = 'domains:reseller-tiers {--dry-run : فقط گزارش بده، چیزی ننویس}';

    protected $description = 'بازبینی و به‌روزرسانیِ سطحِ نمایندگانِ دامنه';

    public function handle(ResellerProgram $program, CustomerNotifier $notifier): int
    {
        if (! Schema::hasColumn('customers', 'is_reseller')) {
            $this->warn('ستون‌های نمایندگی هنوز روی این دیتابیس ساخته نشده‌اند. مهاجرت را اجرا کنید.');

            return self::SUCCESS;   // نه خطا: نصبِ مهاجرت‌نخورده نباید کرون را قرمز کند
        }

        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $seen = 0;

        Customer::where('is_reseller', true)->chunkById(100, function ($rows) use (
            $program, $notifier, $dry, &$changed, &$seen
        ) {
            foreach ($rows as $customer) {
                $seen++;

                try {
                    $res = $program->review($customer, save: ! $dry);
                } catch (\Throwable $e) {
                    /*
                    | ⚠️ خطای یک نماینده نباید بقیه را بخوابانَد. این کرون روی
                    | کلِ پایگاه می‌دود و یک ردیفِ خرابِ داده (مثلاً کلیدِ سطحی
                    | که از config حذف شده) نباید سطحِ هیچ‌کسِ دیگری را معلق
                    | نگه دارد.
                    */
                    \App\Support\ErrorTracker::note('domain', $e, [
                        'area' => 'reseller-tiers', 'customer' => $customer->code,
                    ]);

                    continue;
                }

                if (! $res['changed']) {
                    continue;
                }

                $changed++;
                $this->line(sprintf('%s: %s → %s (%s)',
                    $customer->code, $res['from'], $res['to'], $res['reason']));

                if ($dry) {
                    continue;
                }

                /*
                | خبردادن **فقط روی تغییرِ واقعی**.
                |
                | ⚠️ همان قاعدهٔ `SystemHealth`: پیامِ روزانهٔ «سطحِ شما هنوز
                | برنز است» یعنی از هفتهٔ دوم کسی بازش نمی‌کند — و آن‌وقت
                | پیامِ ارتقا هم خوانده نمی‌شود.
                */
                try {
                    $level = $program->levelByKey($res['to']);
                    $name = lc($level['name'] ?? []) ?: $res['to'];

                    $notifier->templated($customer, 'reseller_level_changed', [
                        'level'    => (string) $name,
                        'discount' => (string) $program->discountPct($customer),
                    ], $res['reason'] === 'promoted'
                        ? 'تبریک — سطحِ نمایندگیِ شما به «'.$name.'» ارتقا یافت.'
                        : 'سطحِ نمایندگیِ شما به «'.$name.'» تغییر کرد.');
                } catch (\Throwable) {
                    // اعلانِ ناموفق نباید سطحِ نوشته‌شده را برگرداند
                }
            }
        });

        $this->info(($dry ? '[خشک] ' : '').$seen.' نماینده بررسی شد · '.$changed.' تغییرِ سطح.');

        return self::SUCCESS;
    }
}
