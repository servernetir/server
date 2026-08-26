<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\CloudPlan;

/**
 * تنها جایی که می‌داند کدام ارائه‌دهنده وجود دارد.
 *
 * هر لایهٔ بالاتر (کنترلر، ویو، کرون) فقط با «پلن» و «نمونه» کار می‌کند و اسمِ
 * هتزنر/آیزا را نمی‌بیند. برای افزودنِ ارائه‌دهندهٔ سوم، فقط یک ردیف به
 * `DRIVERS` اضافه می‌شود.
 */
class CloudManager
{
    /** @var array<string, class-string<CloudProvider>> */
    public const DRIVERS = [
        'hetzner' => HetznerClient::class,
        'aeza'    => AezaClient::class,
        'arvan'   => ArvanClient::class,
        'ovh'     => OvhClient::class,
        'proxmox' => ProxmoxClient::class,
        'salad'   => SaladClient::class,
    ];

    /**
     * نامِ واقعیِ هر زیرساخت — **فقط برای پنلِ مدیریت**.
     *
     * ⚠️ چرا لازم شد: تا امروز همه‌جا «زیرساختِ ۱/۲» می‌نوشتیم تا نامِ واقعی
     * تصادفی به مشتری نرسد. ولی خودِ مدیر هم نمی‌فهمید کدام کدام است و سرِ
     * عیب‌یابی قاطی می‌کرد — یعنی محافظی که برای مشتری گذاشته بودیم، کارِ
     * صاحبِ کار را سخت کرده بود.
     *
     * پس دو واژگان داریم و مرزشان روشن است:
     *   `label()`     → «زیرساختِ ۱» — هر جا که ممکن است مشتری ببیند
     *   `realLabel()` → «Hetzner» — فقط صفحاتِ پشتِ گیتِ مدیر
     */
    public const REAL_NAMES = [
        'hetzner' => 'Hetzner Cloud',
        'aeza'    => 'Aeza',
        'arvan'   => 'ArvanCloud (ابرآروان)',
        'ovh'     => 'OVHcloud',
        'proxmox' => 'Proxmox (Tehran)',
        'salad'   => 'SaladCloud (GPU)',
    ];

    /** @var array<string, CloudProvider> */
    private array $cache = [];

    public function driver(string $provider): ?CloudProvider
    {
        $class = self::DRIVERS[$provider] ?? null;

        if ($class === null) {
            return null;
        }

        return $this->cache[$provider] ??= app($class);
    }

    /** @return array<string, CloudProvider> همهٔ درایورها، تنظیم‌شده یا نه */
    public function all(): array
    {
        $out = [];

        foreach (array_keys(self::DRIVERS) as $slug) {
            $d = $this->driver($slug);

            if ($d !== null) {
                $out[$slug] = $d;
            }
        }

        return $out;
    }

    /** @return array<string, CloudProvider> فقط آن‌هایی که توکن دارند */
    public function configured(): array
    {
        return array_filter($this->all(), fn (CloudProvider $d) => $d->isConfigured());
    }

    public function anyConfigured(): bool
    {
        return $this->configured() !== [];
    }

    /**
     * برچسبِ خنثای یک ارائه‌دهنده: «زیرساختِ ۱».
     *
     * چرا حتی در پنلِ مدیریت: اگر عادتِ نوشتنِ «Hetzner» در ویوها شکل بگیرد،
     * دیر یا زود یکی از همان ویوها در پنلِ مشتری کپی می‌شود. نگه‌داشتنِ نامِ
     * واقعی در **یک لایه** (درایور) ارزانی‌اش همین است.
     */
    public function label(?string $provider): string
    {
        $i = array_search((string) $provider, array_keys(self::DRIVERS), true);

        return $i === false ? '—' : 'زیرساختِ '.fa_num($i + 1);
    }

    /**
     * نامِ واقعیِ زیرساخت با شمارهٔ آشنایش: «زیرساختِ ۱ — Hetzner Cloud».
     *
     * هر دو را با هم می‌دهد چون مدیر در تنظیمات «زیرساختِ ۱» را پر کرده و باید
     * بتواند وصلش کند؛ نامِ تنها، همان سرگردانی را از سمتِ دیگر می‌سازد.
     */
    public function realLabel(?string $provider): string
    {
        $key = (string) $provider;
        $name = self::REAL_NAMES[$key] ?? $key;

        return $name === '' ? '—' : $this->label($key).' — '.$name;
    }

    /**
     * مکان‌هایی که هر زیرساخت واقعاً در آنها پلنِ فعال دارد.
     *
     * برای پنلِ مدیریت: «زیرساختِ ۱ کجاست» یک سؤالِ روزمره است و تا امروز
     * جوابش را باید از دیتابیس درمی‌آورد.
     *
     * @return array<string, array<int, string>>  slug => ['آلمان — فالکن‌اشتاین', …]
     */
    public function locationsByProvider(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('cloud_plans')) {
            return [];
        }

        $out = [];

        foreach (array_keys(self::DRIVERS) as $slug) {
            $codes = CloudPlan::query()
                ->where('provider', $slug)
                ->where('is_active', true)
                ->distinct()
                ->pluck('location_code');

            if ($codes->isEmpty()) {
                continue;
            }

            $out[$slug] = \App\Models\CloudLocation::whereIn('code', $codes)
                ->orderBy('country')->orderBy('city')
                ->get()
                ->map(fn ($l) => $l->flagEmoji().' '.$l->label('fa'))
                ->all();
        }

        return $out;
    }

    public function forPlan(CloudPlan $plan): ?CloudProvider
    {
        return $this->driver((string) $plan->provider);
    }

    public function forInstance(CloudInstance $instance): ?CloudProvider
    {
        return $this->driver((string) $instance->provider);
    }

    /**
     * توانایی‌های زیرساختی که این پلن رویش تحویل می‌شود.
     *
     * برای **پیش از ساخت** لازم است: سرورساز باید بداند آیا این عرضه می‌تواند
     * IP اضافه یا کلیدِ SSH بدهد یا نه، وگرنه چیزی می‌فروشیم که تحویلش ممکن
     * نیست.
     */
    public function capabilitiesForPlan(CloudPlan $plan): array
    {
        return $this->forPlan($plan)?->capabilities() ?? [];
    }

    /**
     * توانایی‌های نمونه — برای اینکه پنلِ مشتری دکمهٔ بی‌فایده نشان ندهد.
     *
     * ⚠️ نکتهٔ سفیدبرچسبی: مشتری می‌تواند از تفاوتِ دکمه‌ها حدس بزند که سرورش
     * روی زیرساختِ دیگری است. برای همین **اشتراکِ** توانایی‌ها را نشان نمی‌دهیم؛
     * ولی هم نمی‌شود دکمه‌ای گذاشت که کار نمی‌کند. راهِ میانه: دکمه هست و اگر
     * ارائه‌دهنده پشتیبانی نکند، پیامِ خنثای «برای این سرور در دسترس نیست»
     * می‌آید — بی‌اشاره به دلیلِ واقعی.
     */
    public function capabilitiesFor(CloudInstance $instance): array
    {
        $d = $this->forInstance($instance);

        return $d?->capabilities() ?? [
            'console' => false, 'rebuild' => false, 'resize' => false,
            'snapshot' => false, 'metrics' => false, 'reset_password' => false,
            'ipv6' => false, 'rescue' => false,
        ];
    }
}
