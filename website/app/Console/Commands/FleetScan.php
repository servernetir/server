<?php

namespace App\Console\Commands;

use App\Models\InfraAsset;
use App\Services\Cloud\FleetScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * اسکنِ دوره‌ایِ ناوگان.
 *
 * چرا کرون لازم است در حالی که دکمهٔ دستی هم هست: سنِ بی‌صاحبی از **فاصلهٔ بینِ
 * دو اسکن** درمی‌آید. اگر فقط وقتی اسکن شود که مدیر صفحه را باز می‌کند، عددِ
 * «۴۰ روز رها بوده» هرگز ساخته نمی‌شود — چون اولین اسکنِ آن ماشین همان روزی است
 * که مدیر رفته دنبالش، و آن روز صفر می‌شود.
 *
 * ⚠️ این فرمان **هیچ چیزی را حذف نمی‌کند** و هیچ سرویسی را نمی‌بندد. هر تصمیمی
 * پای آدم است. خودکارسازیِ حذف یعنی روزی یک اسکریپت، بعد از یک قطعیِ API، سرورِ
 * زندهٔ یک مشتری را پاک می‌کند.
 */
class FleetScan extends Command
{
    protected $signature = 'fleet:scan {--provider=* : فقط این زیرساخت‌ها}';

    protected $description = 'اسکنِ همهٔ زیرساخت‌ها و به‌روزرسانیِ دفترِ ناوگان (بی‌هیچ تغییری نزدِ زیرساخت)';

    public function handle(FleetScanner $scanner): int
    {
        if (! Schema::hasTable('infra_assets')) {
            $this->warn('جدولِ infra_assets نیست — اول مهاجرت‌ها را اجرا کنید.');

            // 🔴 کدِ موفق و نه خطا: کرونِ روزانه روی سروری که هنوز مهاجرت نخورده
            // نباید هر روز ایمیلِ شکست بفرستد. پیام چاپ می‌شود و کار تمام.
            return self::SUCCESS;
        }

        $providers = (array) $this->option('provider');
        $res = $scanner->scan($providers ?: null);

        $this->line(sprintf(
            'دیده‌شده: %d | متصل: %d | بی‌صاحب: %d | سرویسِ بسته: %d | ناپدید: %d | پاک‌شده از دفتر: %d',
            $res['seen'],
            $res['counts'][InfraAsset::STATE_ATTACHED] ?? 0,
            $res['counts'][InfraAsset::STATE_ORPHAN] ?? 0,
            $res['counts'][InfraAsset::STATE_ZOMBIE] ?? 0,
            $res['counts'][InfraAsset::STATE_GHOST] ?? 0,
            $res['removed'],
        ));

        foreach ($res['errors'] as $slug => $msg) {
            $this->warn("زیرساختِ {$slug} پاسخ نداد: {$msg} — ردیف‌هایش دست‌نخورده ماند.");
        }

        // خروجیِ ناموفق فقط وقتی که واقعاً چیزی از دست رفته باشد؛ اینطور خروجیِ
        // کرون فقط وقتی سروصدا می‌کند که آدمی باید نگاه کند.
        return $res['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
