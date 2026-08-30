<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Domain\OpenProviderClient;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

/**
 * قراردادِ امضانشدهٔ رجیستری را **پیش از** فروش پیدا کن، نه بعد از شکستِ ثبت.
 *
 * ═══ رخدادی که این فرمان از آن آمد (مرداد ۱۴۰۵) ═══
 *
 * مشتری `partolastik.com` را خرید، پول رفت، و ثبت شکست خورد چون قراردادِ
 * رجیستریِ `.com` در حسابِ ما امضا نشده بود. تنها راهِ فهمیدن، همان شکست بود.
 *
 * `TldGate` جلوی **مشتریِ دوم** را می‌گیرد، ولی هنوز یک نفر باید اول ضرر کند.
 * این فرمان آن یک نفر را هم حذف می‌کند: روزی یک بار می‌پرسد کدام قرارداد
 * امضا نشده و اگر چیزی عوض شد خبر می‌دهد.
 *
 * ═══ سه محدودیتِ عمدی ═══
 *
 * ۱) **امضا نمی‌کند.** اسپکِ رسمیِ OpenProvider هیچ مسیرِ نوشتنی برای قرارداد
 *    ندارد؛ امضا یک کنشِ حقوقی در پنلِ خودشان است. این فرمان فقط می‌خوانَد.
 *
 * ۲) **پسوند حدس نمی‌زند.** ساختارِ دقیقِ ردیف‌ها را در پاسخِ واقعی ندیده‌ایم،
 *    پس عنوانِ هر قرارداد **عیناً** گزارش می‌شود و هیچ‌چیز خودکار بسته
 *    نمی‌شود. حدسِ غلط یعنی خواباندنِ فروشِ پسوندی که قراردادش سالم است —
 *    ضرری بزرگ‌تر از خودِ مشکل. بستن فقط از راهِ شکستِ **واقعیِ** ثبت انجام
 *    می‌شود (`TldGate` + `DomainRegistrar::CONTRACT_CODES`).
 *
 * ۳) **فقط روی تغییرِ وضعیت اعلان می‌دهد.** روزی یک پیامِ تکراری یعنی از هفتهٔ
 *    دوم خوانده نمی‌شود — همان قاعدهٔ ثبت‌شدهٔ `SystemHealth` در CLAUDE.md.
 *    امضای وضعیت در `Setting` می‌نشیند و فقط اختلاف خبر می‌سازد.
 *
 * ⚠️ **روزی یک تماس، نه بیشتر.** حسابِ ما یک بار به‌خاطرِ تماسِ زیاد از آی‌پیِ
 * ایران علامت خورده؛ این فرمان عمداً کم‌بسامد است و هرگز در حلقه صدا زده
 * نمی‌شود.
 */
class CheckDomainContracts extends Command
{
    protected $signature = 'domains:check-contracts {--force : اعلان را حتی بدونِ تغییر بفرست}';

    protected $description = 'قراردادهای امضانشدهٔ رجیسترار را می‌خوانَد و روی تغییر به مدیر خبر می‌دهد';

    /** امضای آخرین وضعیت — تا پیامِ تکراری نرود */
    private const STATE_KEY = 'domain_contracts_unsigned';

    public function handle(OpenProviderClient $op, AdminNotifier $notifier): int
    {
        if (! $op->enabled()) {
            $this->warn('اتصالِ رجیسترار پیکربندی نشده — رد شد.');

            return self::SUCCESS;      // نبودِ پیکربندی خطا نیست، فقط کارِ امروز نیست
        }

        $res = $op->resellerSettings();

        /*
        | ⚠️ شکستِ خواندن **هرگز** «همه‌چیز امضا شده» تفسیر نمی‌شود.
        |
        | یک توکنِ منقضی یا قطعیِ گذرا آرایهٔ خالی می‌دهد، و آرایهٔ خالیِ
        | نادیده‌گرفته یعنی امضای وضعیت پاک می‌شود و دفعهٔ بعد که واقعاً
        | قراردادی امضا نشده باشد، «تغییری رخ نداد» و هیچ خبری نمی‌رود.
        | همان تلهٔ `CloudInventory` که فهرستِ خالیِ زیرساخت را شبح می‌خواند.
        */
        if (! $res['ok']) {
            ErrorTracker::noteOnce('domain', 'خواندنِ قراردادهای رجیسترار شکست خورد', 21600, [
                'code'    => $res['code'],
                'message' => mb_substr($res['message'], 0, 160),
            ]);

            $this->error('خوانده نشد: کد '.$res['code'].' — '.$res['message']);

            return self::FAILURE;
        }

        $unsigned = [];

        foreach ($res['contracts'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            // `is_signed` ممکن است بولین یا ۰/۱ بیاید — هر دو را بپذیر
            if (filter_var($row['is_signed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $unsigned[] = trim((string) ($row['title'] ?? $row['type'] ?? '؟'));
        }

        sort($unsigned);      // ترتیبِ پایدار ⇒ امضای پایدار

        $this->info($unsigned === []
            ? 'همهٔ قراردادها امضا شده‌اند.'
            : count($unsigned).' قراردادِ امضانشده: '.implode('، ', $unsigned));

        $signature = md5(implode('|', $unsigned));
        $changed   = Setting::get(self::STATE_KEY) !== $signature;

        Setting::put(self::STATE_KEY, $signature);

        if (! $changed && ! $this->option('force')) {
            return self::SUCCESS;
        }

        if ($unsigned === []) {
            // خبرِ خوب هم خبر است — ولی فقط یک بار، در لحظهٔ تغییر
            if ($changed) {
                $notifier->event('همهٔ قراردادهای رجیستری امضا شده‌اند', [], null, '✅');
            }

            return self::SUCCESS;
        }

        $notifier->event(
            'قراردادِ رجیستریِ امضانشده — پیش از فروش رسیدگی کنید',
            [
                'تعداد'    => count($unsigned),
                'قراردادها' => mb_substr(implode('، ', $unsigned), 0, 300),
                'چه کنم'   => 'پنلِ رجیسترار ← Account ← Contracts، امضا کنید',
            ],
            \App\Services\Domain\DomainRegistrar::CONTRACTS_URL,
            '📜',
        );

        return self::SUCCESS;
    }
}
