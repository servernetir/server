<?php

namespace App\Console\Commands;

use App\Models\CryptoPayment;
use App\Services\Payment\TronWatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `crypto:audit` — کدام پرداختِ رمزارز با واریزیِ **قدیمی** تسویه شده؟
 *
 * ═══ رخدادی که این فرمان از آن آمد (۸ شهریور ۱۴۰۵) ═══
 *
 * مشتریِ تازه سرور خرید، فاکتورش «پرداخت‌شده» شد و سرویس فعال — بی‌آنکه پولی
 * رسیده باشد. `txid`ِ ثبت‌شده، تراکنشی از هفتهٔ پیش بود: واریزیِ قدیمیِ همان
 * آدرس (که در جدولِ ما ثبت نشده بود) روی پرداختِ تازه نشسته بود.
 *
 * علتِ ریشه‌ای در `CryptoReconciler` بسته شد، ولی **پرونده‌های گذشته** خودشان
 * بسته نمی‌شوند. این فرمان آن‌ها را پیدا می‌کند: برای هر پرداختِ تأییدشده،
 * زمانِ واقعیِ تراکنش را از زنجیره می‌پرسد و با زمانِ ساختِ پرداخت می‌سنجد.
 *
 * ⚠️ **فقط می‌خواند.** هیچ فاکتوری را برنمی‌گرداند و هیچ سرویسی را نمی‌بندد؛
 * آن تصمیم مالی است و با مدیر. خروجی: فهرستِ مشکوک با شمارهٔ فاکتور و مشتری.
 *
 *   php artisan crypto:audit                 همهٔ تأییدشده‌ها
 *   php artisan crypto:audit --days=60       فقط دو ماهِ اخیر
 */
class CryptoAudit extends Command
{
    protected $signature = 'crypto:audit {--days=90 : بازهٔ بررسی بر حسبِ روز}';

    protected $description = 'یافتنِ پرداخت‌های رمزارز که با واریزیِ قدیمی‌تر از خودشان تسویه شده‌اند';

    public function handle(TronWatcher $tron): int
    {
        if (! Schema::hasTable('crypto_payments')) {
            $this->warn('جدولِ crypto_payments نیست.');

            return self::SUCCESS;
        }

        $rows = CryptoPayment::where('status', 'confirmed')
            ->whereNotNull('txid')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('پرداختِ تأییدشده‌ای در این بازه نیست.');

            return self::SUCCESS;
        }

        $this->line('بررسیِ '.$rows->count().' پرداختِ تأییدشده…');
        $this->line('');

        /*
        | هر آدرس **یک بار** از زنجیره خوانده می‌شود، نه یک بار به‌ازای هر
        | پرداخت: چند پرداخت روی یک آدرسِ بازاستفاده‌شده رایج است و سهمیهٔ
        | TronGrid محدود.
        */
        $cache = [];
        $bad = [];

        foreach ($rows as $cp) {
            $key = $cp->address.'|'.$cp->asset;

            if (! isset($cache[$key])) {
                $cache[$key] = collect($tron->deposits($cp->address, $cp->asset))
                    ->keyBy('txid')->all();
                usleep(250000);          // مهربان با سهمیهٔ عمومیِ TronGrid
            }

            $dep = $cache[$key][$cp->txid] ?? null;

            if ($dep === null) {
                // ⚠️ «پیدا نشد» قطعاً تقلب نیست: TronGrid فقط ۵۰ تراکنشِ آخر را
                //    می‌دهد و تراکنشِ قدیمی از پنجره بیرون می‌افتد.
                $this->line(sprintf('  … #%d  فاکتور %s — تراکنش در ۵۰ موردِ اخیرِ آدرس نبود (نامعلوم)',
                    $cp->id, $cp->invoice?->number ?? '—'));

                continue;
            }

            $lagSeconds = (int) $cp->created_at->timestamp - (int) $dep['timestamp'];

            if ($lagSeconds > 120) {
                $bad[] = [$cp, $dep, $lagSeconds];
                $this->error(sprintf('  🔴 #%d  فاکتور %s  مشتری %s — واریزی %s پیش از ساختِ پرداخت رخ داده',
                    $cp->id,
                    $cp->invoice?->number ?? '—',
                    $cp->invoice?->customer?->code ?? '—',
                    $this->human($lagSeconds),
                ));
                $this->line('       txid: '.$cp->txid);
            }
        }

        $this->line('');

        if ($bad === []) {
            $this->info('✅ هیچ پرداختی با واریزیِ قدیمی تسویه نشده است.');

            return self::SUCCESS;
        }

        $this->error('🔴 '.count($bad).' پرداختِ مشکوک پیدا شد.');
        $this->line('این‌ها فاکتورهایی‌اند که «پرداخت‌شده» شده‌اند ولی پولشان ممکن است هرگز نرسیده باشد.');
        $this->line('تصمیمِ برگرداندن یا بستنِ سرویس با شماست — این فرمان چیزی را عوض نمی‌کند.');

        return self::SUCCESS;
    }

    private function human(int $s): string
    {
        if ($s >= 86400) {
            return round($s / 86400).' روز';
        }

        return $s >= 3600 ? round($s / 3600).' ساعت' : round($s / 60).' دقیقه';
    }
}
