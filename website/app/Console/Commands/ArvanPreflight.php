<?php

namespace App\Console\Commands;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Services\Cloud\ArvanClient;
use Illuminate\Console\Command;

class ArvanPreflight extends Command
{
    protected $signature = 'cloud:arvan-preflight
                            {--plan= : شناسهٔ ردیف cloud_plans}
                            {--region= : کد region آروان؛ در حالت عادی از plan خوانده می‌شود}
                            {--flavor= : شناسهٔ flavor؛ در حالت عادی از plan خوانده می‌شود}
                            {--image=ubuntu-24.04 : کلید image کاتالوگ}';

    protected $description = 'بررسی فقط‌خواندنی نگاشت‌های Arvan پیش از فعال‌کردن فروش';

    public function handle(ArvanClient $arvan): int
    {
        $plan = filled($this->option('plan'))
            ? CloudPlan::where('provider', 'arvan')->find((int) $this->option('plan'))
            : CloudPlan::where('provider', 'arvan')->where('is_active', true)->orderBy('id')->first();

        $region = trim((string) ($this->option('region') ?: $plan?->provider_location));
        $flavor = trim((string) ($this->option('flavor') ?: $plan?->provider_ref));
        $imageKey = trim((string) $this->option('image'));
        $image = CloudImage::refFor('arvan', $imageKey, $plan?->arch);

        if ($region === '' || $flavor === '' || blank($image)) {
            $this->error('region/flavor/image mapping کامل نیست؛ --plan یا گزینه‌های صریح را بررسی کنید.');

            return self::FAILURE;
        }

        $report = $arvan->preflight($region, $flavor, (string) $image);
        $this->table(['بررسی', 'نتیجه'], collect($report['checks'])->map(
            fn (bool $ok, string $name): array => [$name, $ok ? 'OK' : 'FAIL'],
        )->values()->all());
        $this->line('region: '.$report['selected_region'].' (از '.$report['regions_count'].' منطقه)');
        $this->line('network: '.($report['network_id'] ?: 'NOT FOUND'));
        $this->line('flavor: '.$report['flavor_ref'].' (از '.$report['flavors_count'].' flavor)');
        $this->line('image: '.$report['image_requested'].' → '.($report['image_resolved'] ?: 'NOT FOUND'));
        $this->line('firewall selector: '.$report['firewall_selector']);
        $this->line('firewall resolved: '.(implode(', ', $report['firewall_resolved']) ?: 'NOT FOUND'));
        $this->comment('این فرمان فقط GET اجرا کرد؛ token و پاسخ خام چاپ نشد و هیچ serverی ساخته نشد.');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
