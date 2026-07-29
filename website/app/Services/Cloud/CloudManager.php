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

    public function forPlan(CloudPlan $plan): ?CloudProvider
    {
        return $this->driver((string) $plan->provider);
    }

    public function forInstance(CloudInstance $instance): ?CloudProvider
    {
        return $this->driver((string) $instance->provider);
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
