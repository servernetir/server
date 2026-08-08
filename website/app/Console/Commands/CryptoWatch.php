<?php

namespace App\Console\Commands;

use App\Services\Payment\CryptoReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * پایشِ زنجیره — تنها «کارگرِ» ما.
 *
 * ⚠️ هیچ worker و صفی روی این میزبان نیست؛ کرونِ یک‌دقیقه‌ای همه‌کاره است.
 * برای همین این کامند باید **سریع و بی‌سروصدا** باشد و هرگز استثنا بیرون
 * ندهد: یک خطای این‌جا، کلِ دقیقهٔ `schedule:run` را می‌کشد و تحویلِ سرور و
 * ثبتِ دامنه هم با آن می‌ایستد — همان زنجیره‌ای که یک بار اتفاق افتاد.
 */
class CryptoWatch extends Command
{
    protected $signature = 'crypto:watch';

    protected $description = 'بررسی واریزی‌های رمزارز و تسویهٔ فاکتورهای پرداخت‌شده';

    public function handle(CryptoReconciler $rec): int
    {
        if (! Schema::hasTable('crypto_payments')) {
            return self::SUCCESS;   // مهاجرت هنوز اجرا نشده — بی‌صدا رد شو
        }

        try {
            $s = $rec->sweep();
        } catch (\Throwable $e) {
            report($e);

            return self::SUCCESS;   // ⚠️ عمداً SUCCESS: کرون نباید بمیرد
        }

        if (array_sum($s) > 0) {
            $this->line(sprintf(
                'بررسی: %d · تأیید: %d · منقضی: %d · نیازمند بازبینی: %d',
                $s['checked'], $s['confirmed'], $s['expired'], $s['unmatched']
            ));
        }

        return self::SUCCESS;
    }
}
