<?php

namespace App\Console\Commands;

use App\Services\Sms\IppanelSender;
use App\Services\Sms\SmsSender;
use Illuminate\Console\Command;

/**
 * بررسی اتصال پیامک بدون طی کردن کل جریان ثبت‌نام.
 *
 *   php artisan sms:test                     ← فقط پیکربندی را گزارش می‌کند
 *   php artisan sms:test 09121234567         ← یک پیام آزمایشی می‌فرستد
 *   php artisan sms:test 09121234567 --otp   ← از مسیر الگو (همان مسیر کد ورود)
 *
 * بدون شماره هیچ پیامکی نمی‌رود و هیچ هزینه‌ای ندارد.
 */
class SmsTest extends Command
{
    protected $signature = 'sms:test {mobile? : شمارهٔ گیرنده} {--otp : ارسال از مسیر الگو}';

    protected $description = 'بررسی پیکربندی و اتصال سرویس پیامک';

    public function handle(SmsSender $sms): int
    {
        $driver = (string) config('services.sms.driver');

        $this->line('');
        $this->line('  درایور تنظیم‌شده در .env : '.($driver ?: '—'));
        $this->line('  درایور فعال              : '.$sms->name());

        if ($driver === 'ippanel') {
            $this->reportIppanel();
        }

        // درایور فعال با آنچه خواسته شده فرق دارد یعنی پیکربندی ناقص است
        if ($driver !== '' && $driver !== 'log' && $sms->name() === 'log') {
            $this->line('');
            $this->error('  پیکربندی ناقص است — پیامک واقعی فرستاده نمی‌شود، فقط در لاگ می‌نشیند.');

            return self::FAILURE;
        }

        $mobile = $this->argument('mobile');

        if ($mobile === null) {
            $this->line('');
            $this->info('  پیکربندی سالم است. برای ارسال واقعی، شماره را بدهید:');
            $this->line('      php artisan sms:test 09121234567 --otp');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  در حال ارسال به '.$mobile.' …');

        $ok = $this->option('otp')
            ? $sms->sendOtp($mobile, '123456')
            : $sms->send($mobile, 'پیام آزمایشی سرورنت. اگر این را دریافت کردید، اتصال درست است.');

        $this->line('');

        if ($ok) {
            $this->info('  ✓ به ارائه‌دهنده تحویل شد. حالا گوشی را بررسی کنید.');
            $this->line('    (تحویل به ارائه‌دهنده یعنی پذیرفته شد، نه اینکه حتماً رسید.)');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  ✗ ارسال نشد. جزئیات خطا در storage/logs نوشته شد.');
        $this->line('');

        return self::FAILURE;
    }

    private function reportIppanel(): void
    {
        $cfg = config('services.sms.ippanel');

        $rows = [
            ['IPPANEL_KEY',          $this->mask($cfg['token'] ?? null)],
            ['IPPANEL_FROM',         $cfg['from'] ?: '— (لازم است)'],
            ['IPPANEL_PATTERN_OTP',  $cfg['patterns']['otp'] ?? null ?: '— (بدون آن کد از مسیر آزاد می‌رود و ممکن است دیر برسد)'],
            ['متغیر الگو',          $cfg['variable'] ?: 'code'],
            ['الگوهای تعریف‌شده',    implode('، ', array_keys(array_filter((array) ($cfg['patterns'] ?? [])))) ?: '— هیچ‌کدام'],
        ];

        $this->line('');
        $this->table(['تنظیم', 'مقدار'], $rows);
    }

    /** توکن هرگز کامل چاپ نمی‌شود — خروجی دستور ممکن است در گزارش کپی شود */
    private function mask(?string $v): string
    {
        if (blank($v)) {
            return '— (لازم است)';
        }

        return mb_substr($v, 0, 4).str_repeat('•', 8).mb_substr($v, -3).'  ('.mb_strlen($v).' نویسه)';
    }
}
