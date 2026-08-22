<?php

namespace App\Console\Commands;

use App\Models\CloudInstance;
use App\Support\TunnelProfile;
use Illuminate\Console\Command;

/**
 * تنظیمِ «پروفایلِ تونلِ TCP» روی یک سرورِ ابری.
 *
 * تا وقتی این پروفایل تنظیم نشده، بخشِ «اکانت‌های اتصال» در پنلِ مشتری اصلاً
 * رندر نمی‌شود. یعنی فیچر به‌صورتِ پیش‌فرض خاموش است و فقط روی سرورهایی روشن
 * می‌شود که واقعاً چنین تونلی رویشان ساخته شده — همان الگوی محافظه‌کارانه‌ای که
 * `exitCapable` دارد.
 *
 *   php artisan exit:tunnel 12 --show
 *   php artisan exit:tunnel 12 --set=/path/profile.json
 *   php artisan exit:tunnel 12 --clear
 *
 * قالبِ فایلِ ورودی (کلیدهای اجباری):
 *
 *   {
 *     "enabled": true,
 *     "host": "203.0.113.10", "port": 8443,
 *     "uuid": "…", "sni": "www.example.com", "pbk": "…", "sid": "…",
 *     "wg_pub": "…", "wg_host": "172.20.0.1", "wg_port": 13231,
 *     "iface": "wg-tcp", "subnet": "10.77.0.0/24",
 *     "reserved": ["10.77.0.11"]
 *   }
 *
 * ⚠️ `--set` فهرستِ اکانت‌های صادرشده (`peers`) را حفظ می‌کند مگر خودِ فایل
 * `peers` داشته باشد؛ پس ویرایشِ پارامترها اکانت‌های موجود را پاک نمی‌کند.
 */
class ExitTunnelProfile extends Command
{
    protected $signature = 'exit:tunnel
        {instance : شناسهٔ CloudInstance}
        {--set= : مسیرِ فایلِ JSONِ پروفایل}
        {--show : نمایشِ پروفایلِ فعلی}
        {--clear : حذفِ پروفایل و خاموش‌کردنِ فیچر برای این سرور}';

    protected $description = 'تنظیم/نمایش/حذفِ پروفایلِ تونلِ TCP یک سرورِ ابری';

    public function handle(): int
    {
        $instance = CloudInstance::find((int) $this->argument('instance'));

        if ($instance === null) {
            $this->error('چنین نمونه‌ای وجود ندارد.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear($instance);
        }

        if ($path = $this->option('set')) {
            return $this->set($instance, (string) $path);
        }

        return $this->show($instance);
    }

    private function show(CloudInstance $instance): int
    {
        $raw = ($instance->meta ?? [])['tunnel'] ?? null;

        if (! is_array($raw)) {
            $this->line('پروفایلی تنظیم نشده است.');

            return self::SUCCESS;
        }

        $profile = TunnelProfile::fromArray($raw);
        $missing = $profile->missingKeys();

        $this->line('سرور   : '.$profile->str('host').':'.$profile->int('port'));
        $this->line('SNI    : '.$profile->str('sni'));
        $this->line('اینترفیس: '.$profile->str('iface').'  ('.$profile->str('subnet').')');
        $this->line('وضعیت  : '.(($raw['enabled'] ?? false) === true ? 'فعال' : 'غیرفعال'));
        $this->line('اکانت‌ها: '.count($profile->peers()).'  — آدرسِ بعدی: '.($profile->nextIp() ?? '—'));

        if ($missing !== []) {
            $this->warn('کلیدهای ناقص: '.implode(', ', $missing).' — تا تکمیل نشوند بخش در پنل نمایش داده نمی‌شود.');
        }

        foreach ($profile->peers() as $p) {
            $this->line('  · '.str_pad($p['name'], 16).' '.$p['ip']);
        }

        return self::SUCCESS;
    }

    private function set(CloudInstance $instance, string $path): int
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('فایل خوانده نشد: '.$path);

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('محتوای فایل JSONِ معتبر نیست.');

            return self::FAILURE;
        }

        $meta = $instance->meta ?? [];
        $current = is_array($meta['tunnel'] ?? null) ? $meta['tunnel'] : [];

        // اکانت‌های صادرشده حفظ می‌شوند مگر فایل صراحتاً peers بدهد.
        if (! array_key_exists('peers', $decoded) && isset($current['peers'])) {
            $decoded['peers'] = $current['peers'];
        }

        $decoded['enabled'] = (bool) ($decoded['enabled'] ?? true);

        $profile = TunnelProfile::fromArray($decoded);
        $missing = $profile->missingKeys();

        if ($missing !== []) {
            $this->error('کلیدهای اجباریِ نداشته: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $meta['tunnel'] = $decoded;
        $instance->meta = $meta;
        $instance->save();

        $this->info('پروفایل ذخیره شد. بخشِ «اکانت‌های اتصال» حالا در پنلِ مشتری دیده می‌شود.');

        return $this->show($instance->fresh());
    }

    private function clear(CloudInstance $instance): int
    {
        $meta = $instance->meta ?? [];

        if (! isset($meta['tunnel'])) {
            $this->line('چیزی برای حذف نبود.');

            return self::SUCCESS;
        }

        unset($meta['tunnel']);
        $instance->meta = $meta;
        $instance->save();

        $this->info('پروفایل حذف شد؛ بخش در پنلِ مشتری دیگر نمایش داده نمی‌شود.');
        $this->warn('توجه: peerهای روی خودِ روتر دست‌نخورده‌اند و باید دستی حذف شوند.');

        return self::SUCCESS;
    }
}
