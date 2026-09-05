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
        $feePct = $pricing->fxFeePctFor('hetzner');

        $this->line('مکان: '.$location.' · حاشیهٔ سود: '.$pricing->marginPct().'٪'
            .' · سربارِ ارزیِ هتزنر: '.$feePct.'٪ · نرخِ یورو: '
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
        $costBook = [];

        foreach ($types as $t) {
            $costCents = $this->monthlyCostCents($t, $location);

            if ($costCents === null) {
                $rows[] = [$t['name'] ?? '?', $this->humanSize($t['size'] ?? 0), '—', '—', 'قیمتی برای این مکان ندارد', '', '', ''];

                continue;
            }

            /*
            | 🔴 بهای واقعی = قیمتِ هتزنر + سربارِ رساندنِ پول به او.
            |
            | نسخهٔ اولِ همین فرمان این را جا انداخته بود و جدولی چاپ کرد که
            | بهای تمام‌شده را ۱۰٪ کمتر نشان می‌داد — عددی که آدم بر اساسش
            | قیمت می‌گذارد و ضرر را تا ماه‌ها نمی‌بیند. `HetznerClient` برای
            | سرورِ مجازی از همان اول درست می‌زدش؛ این‌جا جا افتاده بود.
            */
            /*
            | بهای **خام** در کش می‌نشیند، نه بهای با سربار: درصدِ سربار در
            | تنظیمات ویرایش‌پذیر است و اگر این‌جا پخته شود، تغییرش تا اجرای
            | بعدیِ همین فرمان بی‌اثر می‌مانَد.
            */
            $costBook[(string) ($t['name'] ?? '?')] = $costCents;

            $landedCents = (int) ceil($pricing->costWithFee($costCents, 'hetzner'));
            $p = $pricing->priceFor($landedCents);

            $rows[] = [
                (string) ($t['name'] ?? '?'),
                $this->humanSize($t['size'] ?? 0),
                (string) ($t['subaccounts_limit'] ?? '—'),
                $this->limit($t['snapshot_limit'] ?? null).' / '.$this->limit($t['automatic_snapshot_limit'] ?? null),
                number_format($costCents / 100, 2).' €',
                number_format($landedCents / 100, 2).' €',
                number_format($p['eur_cents'] / 100, 2).' €',
                $p['irt'] > 0 ? number_format($p['irt']).' ت' : '—',
            ];
        }

        $this->table(['نوع', 'اندازه', 'زیرحساب', 'اسنپ‌شات دستی/خودکار', 'بهای هتزنر', '+سربار', 'فروشِ یورویی', 'فروشِ تومانی'], $rows);

        /*
        | 🔴 بی‌این ذخیره، کفِ حاشیه در `Product::priceForCycle()` منبعی برای
        | بهای تمام‌شده ندارد و بی‌صدا بی‌اثر می‌شود — یعنی همان ضررِ سالانه
        | برمی‌گردد بی‌آنکه کسی متوجه شود.
        */
        \App\Services\Provisioning\HetznerStorageCosts::remember($costBook, $location);
        $this->info('بهای '.count($costBook).' نوع در کش ذخیره شد — کفِ حاشیه از همین می‌خواند.');

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

    /** سقفِ اسنپ‌شات — «نامحدود» وقتی API چیزی نگفته، نه صفر */
    private function limit($v): string
    {
        return $v === null ? '∞' : (string) $v;
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
