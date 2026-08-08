<?php

namespace App\Console\Commands;

use App\Services\Cloud\CloudProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `cloud:sync-instances` — پی‌گیریِ سرورهایی که هنوز بالا نیامده‌اند.
 *
 * دو کارِ حیاتی:
 *
 * ۱) **بستنِ سفارش‌های نیمه‌کاره.** زیرساختِ دوم دومرحله‌ای است: اول «سفارش»
 *    ثبت می‌شود و شناسهٔ سرویس چند لحظه بعد ظاهر می‌شود. اگر در همان چند ثانیهٔ
 *    تحویل نرسد، `provider_ref` با پیشوندِ `order:` می‌مانَد. بی‌این فرمان، آن
 *    ref هرگز به شناسهٔ واقعی تبدیل نمی‌شود و مشتری سرورِ پول‌داده‌اش را نه IP
 *    دارد نه می‌تواند روشن/خاموش کند — تحویلِ «موفقِ» بی‌فایده.
 *
 * ۲) **گذارِ building → running.** پنل خودش هم با AJAX وضعیت را می‌پرسد، ولی
 *    مشتری‌ای که تب را بسته باید بی‌کلیکِ خودش، ایمیلِ آدرسِ سرور را بگیرد.
 *
 * ۳) **فرستادنِ ایمیلِ تحویلی که بدهی مانده.** ایمیل دیگر لحظهٔ «سفارش پذیرفته
 *    شد» نمی‌رود، چون آن لحظه IP وجود ندارد و مشتری ایمیلی با `IP: —` می‌گرفت.
 *    `CloudProvisioner::deliverOwedNotices()` هر دقیقه سراغِ ردیف‌هایی می‌رود که
 *    `ready_notified_at` نال دارند و حالا هم IP دارند هم زیرساخت می‌گوید بالا
 *    آمده‌اند. قفلِ اتمی تضمین می‌کند هرگز دو بار نرود.
 *
 * هر دقیقه می‌دود مثلِ `provision:run`، ولی سبک است: فقط ردیف‌های `building`/
 * `unknown`/`order:` را می‌بیند، پس روی حسابِ پرِ سرورها هم چند تماس بیشتر نیست.
 */
class SyncCloudInstances extends Command
{
    protected $signature = 'cloud:sync-instances {--limit=40 : سقفِ ردیف در هر اجرا}';

    protected $description = 'پی‌گیریِ سرورهای ابریِ در حالِ آماده‌سازی و بستنِ سفارش‌های نیمه‌کاره';

    public function handle(CloudProvisioner $prov): int
    {
        if (! Schema::hasTable('cloud_instances')) {
            return self::SUCCESS;
        }

        $r = $prov->syncInstances(max(1, (int) $this->option('limit')));

        if (array_sum($r) > 0) {
            $this->info(sprintf(
                'سرورِ ابری: %d سفارشِ بسته‌شده، %d وضعیتِ تازه، %d ناموفق، %d ایمیلِ تحویل.',
                $r['resolved'], $r['refreshed'], $r['failed'], $r['notified']
            ));
        }

        return self::SUCCESS;
    }
}
