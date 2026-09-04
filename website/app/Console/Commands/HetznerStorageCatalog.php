<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Cloud\CloudPricing;
use App\Services\Provisioning\HetznerStorageClient;
use Illuminate\Console\Command;

/**
 * کاتالوگِ Storage Boxِ هتزنر با قیمتِ فروشِ ما.
 *
 * چرا این فرمان لازم است: نگاشتِ `provisioning.hetzner_storage.plans` باید با
 * نامِ **واقعیِ** نوع‌ها پر شود. نامِ نوع را از حافظه نوشتن یعنی یا تحویلِ
 * شکست‌خورده، یا باکسی با اندازه/قیمتِ اشتباه که ماه‌ها دیده نمی‌شود.
 *
 * فقط می‌خوانَد — هیچ باکسی نمی‌سازد و هیچ پولی خرج نمی‌کند.
 */
class HetznerStorageCatalog extends Command
{
    protected $signature = 'hetzner:storage-catalog
                            {--server= : شناسه یا نامِ سرورِ hetzner_storage}
                            {--location= : فقط قیمتِ این مکان (پیش‌فرض از config)}';

    protected $description = 'نوع‌های Storage Boxِ هتزنر را با بهای تمام‌شده و قیمتِ فروشِ تومانی نشان می‌دهد';

    public function handle(CloudPricing $pricing): int
    {
        $server = $this->resolveServer();

        if (! $server) {
            $this->error('سرورِ نوعِ hetzner_storage پیدا نشد. اول در /admin/servers ثبتش کنید (توکن لازم است).');

            return self::FAILURE;
        }

        $location = (string) ($this->option('location') ?: config('provisioning.hetzner_storage.location', 'fsn1'));

        $res = (new HetznerStorageClient($server))->types();

        if (! $res['ok']) {
            $this->error('خواندنِ کاتالوگ ناموفق بود: '.$res['reason']);

            return self::FAILURE;
        }

        $types = $res['data']['storage_box_types'] ?? [];

        if ($types === []) {
            $this->warn('هتزنر هیچ نوعی برنگرداند.');

            return self::FAILURE;
        }

        $rate = $pricing->eurToToman();

        $this->line('مکان: '.$location.' · حاشیهٔ سود: '.$pricing->marginPct().'٪ · نرخِ یورو: '
            .($rate > 0 ? number_format($rate).' تومان' : '<نامعلوم>'));

        /*
        | ⚠️ نرخِ یورو که نباشد، ستونِ تومان صفر می‌شود. این عمدی است و همان
        | قاعدهٔ `scopeSellable` سرورِ ابری: قیمتِ صفر بهتر از فروشِ یک باکسِ
        | ۲۰ یورویی به قیمتِ هیچ.
        */
        if ($rate <= 0) {
            $this->warn('نرخِ یورو در دسترس نیست — ستونِ تومان معنا ندارد.');
        }

        $rows = [];

        foreach ($types as $t) {
            $costCents = $this->monthlyCostCents($t, $location);

            if ($costCents === null) {
                $rows[] = [$t['name'] ?? '?', $this->humanSize($t['size'] ?? 0), '—', 'قیمتی برای این مکان ندارد', '', ''];

                continue;
            }

            $p = $pricing->priceFor($costCents);

            $rows[] = [
                (string) ($t['name'] ?? '?'),
                $this->humanSize($t['size'] ?? 0),
                (string) ($t['subaccounts_limit'] ?? '—'),
                number_format($costCents / 100, 2).' €',
                number_format($p['eur_cents'] / 100, 2).' €',
                $p['irt'] > 0 ? number_format($p['irt']).' ت' : '—',
            ];
        }

        $this->table(['نوع', 'اندازه', 'زیرحساب', 'بهای تمام‌شده', 'فروشِ یورویی', 'فروشِ تومانی'], $rows);

        $this->newLine();
        $this->line('نگاشت را در config/provisioning.php → hetzner_storage.plans بگذارید، مثلاً:');
        $this->line("    'sn_backup_1' => '".($types[0]['name'] ?? 'bx11')."',");

        return self::SUCCESS;
    }

    private function resolveServer(): ?Server
    {
        $opt = (string) $this->option('server');

        if ($opt !== '') {
            return Server::query()
                ->where('type', 'hetzner_storage')
                ->where(fn ($q) => $q->where('id', $opt)->orWhere('name', $opt))
                ->first();
        }

        return Server::query()->where('type', 'hetzner_storage')->orderBy('id')->first();
    }

    /** بهای ماهانهٔ **بدون** مالیات، به سنتِ یورو. `null` = این مکان قیمت ندارد. */
    private function monthlyCostCents(array $type, string $location): ?int
    {
        foreach (($type['prices'] ?? []) as $p) {
            if (($p['location'] ?? null) !== $location) {
                continue;
            }

            /*
            | `net` می‌گیریم نه `gross`: مالیاتِ آلمان بهای ما نیست و مالیاتِ
            | فروشِ ایران جدا و داده‌محور در `tax_rates` حساب می‌شود. جمع‌کردنِ
            | این دو یعنی دوبار مالیات روی یک قلم.
            */
            $net = (string) ($p['price_monthly']['net'] ?? '');

            return $net === '' ? null : (int) round(((float) $net) * 100);
        }

        return null;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $tb = $bytes / (1024 ** 4);

        return $tb >= 1 ? rtrim(rtrim(number_format($tb, 1), '0'), '.').' TB'
            : round($bytes / (1024 ** 3)).' GB';
    }
}
