<?php

namespace App\Console\Commands;

use App\Models\CloudPlan;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `cloud:reopen` — پلن‌هایی که **قرنطینهٔ خودکار** بسته بود را دوباره باز می‌کند.
 *
 * ═══ 🔴 چرا این فرمان هست و چرا هرگز خودکار اجرا نمی‌شود ═══
 *
 * وقتی سفارشِ یک زیرساخت با خطای **ساختاری** شکست بخورد (توکن، دسترسی،
 * موجودیِ حساب، یا — همان چیزی که واقعاً افتاد — شکلِ غلطِ درخواست که گیت‌وی
 * ۵۰۰ می‌داد)، `CloudProvisioner::quarantineProvider()` **همهٔ** پلن‌های آن
 * زیرساخت را از فروش برمی‌دارد. قاعدهٔ کارفرما: «یا حتماً تحویل شود، یا اصلاً
 * برای فروش موجود نباشد.»
 *
 * روی حسابِ واقعی این یک‌بار **۲۲۱ پلن** را بست و راهِ برگرداندنشان فقط زدنِ
 * دکمهٔ «باز کن» در `/admin/cloud` بود — ۲۲۱ بار، ردیف‌به‌ردیف، با صفحه‌ای که
 * سقفِ ۴۰۰ ردیف دارد. عملاً یعنی راهی نبود.
 *
 * ⚠️ **عمداً هیچ‌جا خودکار صدا زده نمی‌شود** و در `routes/console.php` نیست.
 * «سفارش دیگر ۵۰۰ نمی‌دهد» را فقط یک سفارشِ **واقعیِ موفق** ثابت می‌کند، و آن
 * تصمیم — با پولِ واقعی و بدونِ سندباکس — مالِ کارفراست نه مالِ کد. اگر این
 * فرمان خودکار می‌دوید، هر بار که علتِ ریشه‌ای برطرف نشده بود، فروشگاه دوباره
 * باز می‌شد و مشتریِ بعدی همان تجربه را می‌گرفت: پول رفته، سرور نیامده.
 *
 * ═══ چه چیزی را باز می‌کند و چه چیزی را **نه** ═══
 *
 * فقط ردیف‌هایی که یادداشتشان با `CloudProvisioner::QUARANTINE_PREFIX` شروع
 * می‌شود. پلنی که **مدیر آگاهانه** بسته (مثلاً چون تحویلش دستی است یا قیمتش
 * درست نیست) یادداشتِ دیگری دارد و دست نمی‌خورد — وگرنه این فرمان به‌جای
 * «واگردانیِ یک اشتباهِ خودکار»، تصمیم‌های انسانی را هم پاک می‌کرد.
 *
 * ═══ استفاده ═══
 *
 *   php artisan cloud:reopen --dry-run        فقط بگو چند تا و کدام‌ها
 *   php artisan cloud:reopen aeza             فقط زیرساختِ دوم
 *   php artisan cloud:reopen                  همهٔ زیرساخت‌ها (با تأیید)
 *   php artisan cloud:reopen --all --force    شاملِ بسته‌های دستیِ مدیر (نادر)
 */
class ReopenCloudPlans extends Command
{
    protected $signature = 'cloud:reopen
                            {provider? : اسلاگِ زیرساخت (hetzner|aeza|arvan|ovh) — خالی یعنی همه}
                            {--dry-run : فقط گزارش بده، چیزی را عوض نکن}
                            {--all : بسته‌های دستیِ مدیر را هم باز کن (پیش‌فرض: فقط قرنطینهٔ خودکار)}
                            {--force : بی‌پرسش اجرا کن}';

    protected $description = 'بازکردنِ پلن‌هایی که قرنطینهٔ خودکارِ تحویل بسته بود';

    public function handle(): int
    {
        if (! Schema::hasTable('cloud_plans')) {
            $this->warn('جدولِ cloud_plans ساخته نشده — اول مهاجرت را بزنید.');

            return self::SUCCESS;
        }

        $provider = $this->argument('provider');
        $all = (bool) $this->option('all');

        $q = CloudPlan::query()->where('admin_disabled', true);

        if (filled($provider)) {
            $q->where('provider', $provider);
        }

        if (! $all) {
            // ⚠️ فقط قرنطینهٔ خودکار. `admin_note`ِ نال هم کنار می‌رود: نمی‌دانیم
            // چه کسی بسته، و «نمی‌دانم» دلیلِ کافی برای بازکردنِ فروش نیست.
            $q->where('admin_note', 'like', CloudProvisioner::QUARANTINE_PREFIX.'%');
        }

        $rows = $q->get(['id', 'provider', 'public_name', 'location_code', 'admin_note']);

        if ($rows->isEmpty()) {
            $this->info($all
                ? 'هیچ پلنِ بسته‌ای پیدا نشد.'
                : 'هیچ پلنی با قرنطینهٔ خودکار پیدا نشد. (بسته‌های دستیِ مدیر با --all دیده می‌شوند.)');

            return self::SUCCESS;
        }

        // گزارشِ تفکیکی: مدیر باید **پیش از** بازکردن ببیند چه چیزی باز می‌شود
        $this->line('');

        foreach ($rows->groupBy('provider') as $slug => $group) {
            $this->line(sprintf('  %-10s %d پلن', $slug, $group->count()));
        }

        $this->line('');
        $this->line('نمونهٔ یادداشتِ بسته‌شدن: '.mb_substr((string) $rows->first()->admin_note, 0, 160));
        $this->line('');

        if ($this->option('dry-run')) {
            $this->warn(sprintf('حالتِ آزمایشی — %d پلن باز **نشد**.', $rows->count()));

            return self::SUCCESS;
        }

        // 🔴 این فرمان فروشگاه را باز می‌کند. اگر علتِ ریشه‌ای هنوز برطرف نشده
        // باشد، مشتریِ بعدی پول می‌دهد و سرور نمی‌گیرد — پس تأیید می‌گیریم.
        if (! $this->option('force') && ! $this->confirm(
            sprintf('%d پلن دوباره برای فروش باز شود؟ (یک سفارشِ واقعیِ موفق را قبلاً دیده‌اید؟)', $rows->count()),
            false
        )) {
            $this->warn('لغو شد — هیچ‌چیز عوض نشد.');

            return self::SUCCESS;
        }

        $n = CloudPlan::whereIn('id', $rows->pluck('id'))
            ->update(['admin_disabled' => false, 'admin_note' => null]);

        $this->info(sprintf('%d پلن دوباره باز شد.', $n));
        $this->line('⚠️ فقط پلنی واقعاً فروخته می‌شود که `sellable()` باشد — یعنی فعال، موجود و قیمت‌دار.');

        \App\Support\ErrorTracker::note('cloud',
            'بازکردنِ دستیِ '.$n.' پلنِ قرنطینه‌شده با فرمانِ cloud:reopen'
            .(filled($provider) ? ' (زیرساخت: '.$provider.')' : ''));

        return self::SUCCESS;
    }
}
