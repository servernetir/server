<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Provisioning\WhmClient;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

/**
 * «تحویل ناموفق شد» را از خودِ سرور بپرس، نه از دفترِ خودمان.
 *
 * ═══ رخدادی که این فرمان از آن آمد (مرداد ۱۴۰۵) ═══
 *
 * مشتری هاستِ لینوکسی با دامنهٔ zhina.shop خرید. `createacct` روی نودِ شلوغ از
 * بودجهٔ ۳۰ ثانیه رد شد، ما تایم‌اوت خوردیم، و WHM حساب را **ساخت**. سمتِ ما
 * `provision_status='failed'` نوشته شد و به مشتریِ پول‌داده گفتیم تحویل ناموفق
 * بوده و می‌تواند لغو کند و پولش را پس بگیرد — در حالی که cPanelش زنده روی
 * سرور بود. کارفرما فقط چون خودش WHM را باز کرد فهمید.
 *
 * `WhmProvisioner::create()` حالا بعد از هر شکست خودش یک بار می‌پرسد، ولی آن
 * فقط لحظهٔ شکست را می‌پوشانَد. اگر همان لحظه هم سرور در دسترس نباشد، ردیف در
 * `failed`/`manual` می‌مانَد — و کرونِ `provision:run` عمداً فقط `pending` را
 * برمی‌دارد. یعنی بی‌این فرمان، هنوز یک آدم لازم است.
 *
 * ═══ چرا فرمانِ جدا و نه افزودنِ `failed` به کرونِ اصلی ═══
 *
 * 🔴 آن کرون مسیرِ **ابری** را هم می‌راند، و آن‌جا تلاشِ دوباره یعنی خریدِ یک
 * سرورِ دوم با پولِ واقعی. قاعدهٔ ثبت‌شدهٔ پروژه این است که آن صف هرگز خودکار
 * دوباره تلاش نکند. این فرمان آن قاعده را دست نمی‌زند چون **هیچ‌چیز نمی‌سازد**:
 * تنها تماسش یک `accountsummary`ِ خواندنی است. اگر حساب باشد، ردیف را به همان
 * مسیرِ موفقیتِ موجود می‌سپارد (که خودش با pre-flight می‌پذیردش)؛ اگر نباشد،
 * دست نمی‌زند.
 *
 * ═══ سه محدودیتِ عمدی ═══
 *
 * ۱) **فقط سرورِ WHM.** بی‌این قید، هر سفارشِ تحویلِ دستی (پلسک، VPS، اختصاصی)
 *    که برای همیشه در `manual` می‌نشیند، هر پنج دقیقه یک درخواستِ WHM به
 *    ماشینی می‌خورد که اصلاً WHM نیست.
 * ۲) **قفلِ اتمی پیش از هر نوشتن.** دکمهٔ «تحویلِ دستی»ِ مدیر همین ردیف را
 *    هم‌زمان برمی‌دارد؛ بی‌قفل، مشتری دو ایمیلِ «سرویس آماده شد» و دو رکوردِ
 *    DNS می‌گرفت و ظرفیتِ سرور دو بار شمرده می‌شد.
 * ۳) **سرورِ خاموش، کلِ نوبتش را رها می‌کند.** اگر اولین استعلامِ یک سرور
 *    تایم‌اوت بخورد، بقیهٔ ردیف‌های همان سرور در این دور رها می‌شوند: وگرنه
 *    یک WHMِ خواب، هر اجرا را به حداکثرِ زمان می‌کشانَد و ردیابِ خطا را از
 *    خطاهای دیگر خالی می‌کند.
 */
class VerifyFailedProvisions extends Command
{
    protected $signature = 'provision:verify-failed {--limit=5 : بیشترین ردیف در هر اجرا}';

    protected $description = 'سرویس‌های «تحویل ناموفق» را با خودِ سرورِ WHM تطبیق می‌دهد و اگر حساب ساخته شده باشد تحویلشان می‌کند';

    /** ردیفِ کهنه‌تر از این دیگر خودکار وارسی نمی‌شود (روز) */
    private const MAX_AGE_DAYS = 7;

    public function handle(ProvisioningService $provisioner): int
    {
        $rows = Service::query()
            ->join('servers', 'servers.id', '=', 'services.server_id')
            // ⚠️ فقط WHM: پلسک/VPS/اختصاصی برای همیشه در `manual` می‌مانند و
            // پرسیدنِ WHM از آن‌ها بی‌معنی و پرهزینه است.
            ->where('servers.type', 'whm')
            ->where('servers.status', 'active')
            ->whereIn('services.provision_status', ['failed', 'manual'])
            ->whereNotIn('services.status', Service::DEAD_STATUSES)
            ->whereNotNull('services.username')
            ->where('services.username', '!=', '')
            ->where('services.updated_at', '>', now()->subDays(self::MAX_AGE_DAYS))
            ->orderBy('services.updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->select('services.*')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('ردیفی برای وارسی نیست.');

            return self::SUCCESS;
        }

        $adopted = 0;
        $stillMissing = 0;
        $unreachable = [];      // شناسهٔ سرورهایی که در این دور جواب ندادند

        foreach ($rows as $service) {
            $server = $service->server;

            if (! $server) {
                continue;
            }

            // سروری که همین دور یک بار جواب نداد، دوباره پرسیده نمی‌شود
            if (in_array($server->id, $unreachable, true)) {
                continue;
            }

            $state = (new WhmClient($server))->accountState(
                (string) $service->username,
                (string) $service->domain,
            );

            if ($state === null) {
                $unreachable[] = $server->id;

                // ⚠️ پیام **سرورمحور** است نه سرویس‌محور: `noteOnce` گلوگاهش را
                // روی هشِ متن می‌بندد، پس متنِ یکتا به‌ازای هر سرویس یعنی
                // گلوگاه اصلاً کار نمی‌کند و پنجرهٔ ۴۰۰ خطیِ ردیاب را پر می‌کند.
                ErrorTracker::noteOnce('provision',
                    'سرورِ «'.$server->name.'» برای وارسیِ تحویل پاسخ نداد.', 1800,
                    ['server' => $server->id]);

                continue;
            }

            if ($state === false) {
                $stillMissing++;

                continue;                   // واقعاً ساخته نشده — دست نزن
            }

            /*
            | حساب هست. ردیف را **اتمی** بردار و بعد به مسیرِ موفقیتِ موجود
            | بسپار. `provision()` خودش با pre-flight همین حساب را می‌پذیرد
            | (`reused`)، پس هیچ `createacct`ی زده نمی‌شود.
            |
            | ⚠️ اگر claim صفر ردیف گرفت یعنی همین لحظه کسِ دیگری (مدیر یا کرون)
            | برش داشته — رد شو، وگرنه دو اعلانِ «آماده شد» می‌رود.
            */
            $claimed = Service::whereKey($service->id)
                ->whereIn('provision_status', ['failed', 'manual'])
                ->update(['provision_status' => 'pending']);

            if ($claimed === 0) {
                continue;
            }

            if ($provisioner->provision($service->fresh())) {
                $adopted++;
                $this->line('✅ سرویس #'.$service->id.' — حساب روی سرور بود و تحویل شد.');
            }
        }

        $this->info('وارسی: '.$adopted.' تحویل‌شده · '.$stillMissing.' واقعاً ساخته‌نشده · '
            .count($unreachable).' سرورِ بی‌پاسخ.');

        return self::SUCCESS;
    }
}
