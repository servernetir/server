<?php

namespace App\Services\Provisioning;

use App\Models\Product;
use App\Models\Setting;
use App\Services\Cloud\CloudPricing;
use Carbon\CarbonImmutable;

class HetznerVolumeCosts
{
    public const SETTING = 'hetzner_volume_catalog';
    public const SIZES = [100, 500, 1000];
    public const LOCATIONS = ['fsn1', 'nbg1', 'hel1'];

    public function metadataForPlan(string $plan): ?array
    {
        if (! preg_match('/\Asn_volume_(100|500|1000)gb_(fsn1|nbg1|hel1)\z/', $plan, $m)) return null;
        $book = $this->book();
        return $book ? [
            'size_gb' => (int) $m[1], 'location' => $m[2],
            'country' => $m[2] === 'hel1' ? 'FI' : 'DE',
            'server_id' => $book['server_id'], 'at' => $book['at'], 'currency' => 'EUR',
        ] : null;
    }

    public function quoteForPlan(string $plan): ?array
    {
        $meta = $this->metadataForPlan($plan); $book = $this->book();
        if (! $meta || ! $book) return null;
        $pricing = app(CloudPricing::class); $rate = $pricing->eurToToman();
        if ($rate <= 0) return null;
        $netCents = (int) ceil((float) $book['net_eur_per_gb_month'] * 100 * $meta['size_gb'] - 0.0000001);
        $landed = (int) ceil($pricing->costWithFee($netCents, 'hetzner') - 0.0000001);
        $eur = (int) (ceil($landed * (1 + max(5, $pricing->storageMarginPct()) / 100) / 10 - 0.0000001) * 10);
        $irt = intdiv($eur * $rate + 999999, 1000000) * 10000;
        return $eur > 0 && $irt > 0 ? ['eur_cents' => $eur, 'irt' => $irt, 'setup_irt' => 0, 'setup_eur_cents' => 0] : null;
    }

    public function configurationError(Product $product): ?string
    {
        $meta = $this->metadataForPlan((string) $product->plan);
        if (! $meta || ! $this->book()) return 'تعرفهٔ به‌روز Block Storage در دسترس نیست.';
        if (! $product->server || $product->server->type !== 'hetzner_volume' || ! $product->server->canAcceptNew()
            || (int) $product->server_id !== (int) $meta['server_id']) return 'اتصال تحویل Block Storage آماده نیست.';
        if ($product->group !== 'block-storage' || $product->cycle !== 'monthly' || $product->requires_domain) return 'پیکربندی محصول Block Storage معتبر نیست.';
        return $this->quoteForPlan((string) $product->plan) ? null : 'نرخ تبدیل معتبر برای قیمت‌گذاری موجود نیست.';
    }

    public static function remember(string $netEurPerGb, int $serverId): void
    {
        Setting::put(self::SETTING, json_encode(['at' => now()->utc()->toIso8601String(), 'server_id' => $serverId,
            'currency' => 'EUR', 'net_eur_per_gb_month' => $netEurPerGb], JSON_THROW_ON_ERROR));
    }

    private function book(): ?array
    {
        $b = json_decode((string) Setting::get(self::SETTING), true);
        if (! is_array($b) || ($b['currency'] ?? null) !== 'EUR' || ! is_numeric($b['net_eur_per_gb_month'] ?? null)
            || (float) $b['net_eur_per_gb_month'] <= 0 || (int) ($b['server_id'] ?? 0) <= 0) return null;
        try { if (CarbonImmutable::parse($b['at'] ?? '')->lt(now()->subDay())) return null; } catch (\Throwable) { return null; }
        return $b;
    }
}
