<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * وضعیت ارائه‌دهنده‌های هوش مصنوعی: کدام کلید تنظیم است، هر کار به کجا می‌رود،
 * و آیا درگاه از این سرور واقعاً در دسترس است.
 *
 *   php artisan ai:status
 */
class AiStatus extends Command
{
    protected $signature = 'ai:status {--ping : یک درخواست واقعی هم بزن}';

    protected $description = 'بررسی پیکربندی و دسترسی ارائه‌دهنده‌های هوش مصنوعی';

    public function handle(): int
    {
        $this->line('');
        $this->line('ارائه‌دهنده‌ها:');
        foreach (['gapgpt', 'deepseek'] as $name) {
            $cfg = config('services.'.$name, []);
            $key = (string) ($cfg['key'] ?? '');
            $this->line(sprintf(
                '  %-10s کلید: %-12s مدل: %-18s %s',
                $name,
                $key === '' ? 'تنظیم نشده' : 'دارد ('.substr($key, 0, 6).'…)',
                $cfg['model'] ?? '—',
                $cfg['base'] ?? '—'
            ));
        }

        $this->line('');
        $this->line('مسیریابی کارها:');
        foreach ((array) config('services.ai_routing') as $purpose => $want) {
            $cfg = config('services.'.$want, []);
            $actual = empty($cfg['key']) ? 'gapgpt (بازگشت خودکار)' : $want;
            $this->line(sprintf('  %-10s → %s', $purpose, $actual));
        }

        if (! $this->option('ping')) {
            $this->line('');
            $this->comment('برای تست اتصال واقعی: php artisan ai:status --ping');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('تست اتصال:');
        foreach (['gapgpt', 'deepseek'] as $name) {
            $cfg = config('services.'.$name, []);
            if (empty($cfg['key'])) {
                $this->line("  {$name}: رد شد (کلید ندارد)");

                continue;
            }
            [$ok, $detail] = $this->ping($cfg);
            $this->line("  {$name}: ".($ok ? '✓ در دسترس' : '✗ '.$detail));
        }

        return self::SUCCESS;
    }

    /** یک درخواست خیلی کوچک برای سنجش دسترسی و اعتبار */
    private function ping(array $cfg): array
    {
        $ch = curl_init(rtrim($cfg['base'], '/').'/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$cfg['key']],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => $cfg['model'],
                'messages'   => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [false, 'اتصال برقرار نشد: '.$err];
        }
        if ($code === 200) {
            return [true, ''];
        }

        $j = json_decode((string) $raw, true);
        $msg = $j['error']['message'] ?? mb_substr((string) $raw, 0, 120);

        return [false, "HTTP {$code} — ".$msg];
    }
}
