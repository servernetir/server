<?php

namespace App\Services\ObjectStorage;

use App\Models\Product;
use App\Models\Setting;
use App\Services\Provisioning\ObjectGatewayBootstrapper;
use App\Services\Cloud\CloudPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class HetznerObjectCosts
{
    public const SETTING = 'hetzner_object_pricebook';
    public const STATUS_SETTING = 'hetzner_object_price_sync_status';
    public const CAPACITIES = [1, 2, 5, 10, 20, 50, 100];
    public const LOCATIONS = ['fsn1', 'nbg1', 'hel1'];

    public function __construct(private readonly CloudPricing $pricing) {}

    public function isCatalogPlan(string $plan): bool
    {
        return str_starts_with($plan, 'sn_object_');
    }

    public function metadataForPlan(string $plan): ?array
    {
        if (! preg_match('/\Asn_object_(1|2|5|10|20|50|100)tb_(fsn1|nbg1|hel1)\z/', $plan, $match)) {
            return null;
        }
        $book = $this->pricebook();

        return [
            'capacity_tb' => (int) $match[1],
            'egress_tb' => 1,
            'location' => $match[2],
            'country' => $match[2] === 'hel1' ? 'FI' : 'DE',
            'endpoint' => 'https://'.$match[2].'.your-objectstorage.com',
            'server_id' => $book['server_id'] ?? null,
            'at' => $book['quoted_at'] ?? null,
            'currency' => 'EUR',
        ];
    }

    public function pricebook(): ?array
    {
        try {
            $status = json_decode((string) Setting::get(self::STATUS_SETTING), true);
            if (is_array($status) && ($status['ok'] ?? null) === false) {
                return null;
            }
            $book = json_decode((string) Setting::get(self::SETTING), true);
            return is_array($book) && $this->validBook($book) ? $book : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function configurationError(Product $product): ?string
    {
        if (! $this->isCatalogPlan((string) $product->plan) && $product->group !== 'object-storage') {
            return null;
        }
        $book = $this->pricebook();
        $meta = $this->metadataForPlan((string) $product->plan);
        if (! $book || ! $meta) {
            return 'تعرفه معتبر و به‌روز فضای آبجکت در دسترس نیست؛ فروش موقتاً متوقف است.';
        }
        if (! $product->server || $product->server->type !== 'hetzner_object_gateway'
            || (! $product->server->canAcceptNew() && ! app(ObjectGatewayBootstrapper::class)->coldStartReady($product->server))
            || (int) $product->server_id !== (int) ($book['server_id'] ?? 0)) {
            return 'پروژه تحویل این فضای آبجکت آماده نیست.';
        }
        if ($product->requires_domain || $product->cycle !== 'monthly'
            || $product->category !== 'other' || $product->group !== 'object-storage') {
            return 'پیکربندی محصول فضای آبجکت نیاز به بررسی دارد.';
        }
        if ($this->quoteForPlan((string) $product->plan) === null) {
            return 'نرخ تبدیل معتبر برای قیمت‌گذاری فضای آبجکت موجود نیست.';
        }

        return null;
    }

    public function quoteForPlan(string $plan, int $months = 1): ?array
    {
        $meta = $this->metadataForPlan($plan);
        $book = $this->pricebook();

        return $meta && $book ? $this->quoteFromBook($book, $meta['capacity_tb'], $meta['egress_tb'], $months) : null;
    }

    public function quoteToman(int $capacity, int $egress = 1, int $months = 1): int
    {
        $book = $this->pricebook();

        return $book ? ($this->quoteFromBook($book, $capacity, $egress, $months)['irt'] ?? 0) : 0;
    }

    /**
     * Reseller budget, not an upstream fixed-capacity plan or a quota promise.
     * Provider free allowance is account-wide: do not subtract it for each sale.
     * Use a full 31-day (744-hour) incremental storage budget plus egress, with
     * the upstream monthly base as a floor. Every sale also carries the complete
     * shared Gateway + IPv4 monthly cost, so even the first tenant is profitable;
     * customer VAT is separate.
     */
    public function quoteFromBook(array $book, int $capacity, int $egress = 1, int $months = 1): ?array
    {
        if (! $this->validBook($book) || ! in_array($capacity, self::CAPACITIES, true)
            || $egress < 0 || $egress > 1000 || $months < 1 || $months > 36
            || $this->pricing->eurToToman() <= 0) {
            return null;
        }
        try {
            $incremental = BigDecimal::of($book['storage_tb_hour_eur'])->multipliedBy(744 * $capacity)
                ->plus(BigDecimal::of($book['egress_tb_eur'])->multipliedBy($egress));
            $base = BigDecimal::of($book['base_monthly_eur']);
            $objectNet = ($incremental->isGreaterThan($base) ? $incremental : $base)->multipliedBy($months);
            $gatewayNet = BigDecimal::of($book['gateway_monthly_net_eur'])->multipliedBy($months);
            $net = $objectNet->plus($gatewayNet);
            $fee = BigDecimal::of((string) $this->pricing->fxFeePctFor('hetzner'))->multipliedBy('0.01');
            $landedCents = $net->multipliedBy(BigDecimal::of(1)->plus($fee))->multipliedBy(100)
                ->toScale(0, self::up())->toInt();
            // Read the existing settings, but keep step rounding exact. Binary
            // floating point turns EUR 1.10 × 100,000 / 10,000 into
            // 11.000000000000002 and would charge a spurious extra 10,000 toman.
            $margin = BigDecimal::of((string) $this->pricing->marginPct('hetzner'))->multipliedBy('0.01');
            $eur = BigDecimal::of($landedCents)->multipliedBy(BigDecimal::of(1)->plus($margin))
                ->multipliedBy('0.1')->toScale(0, self::up())->toInt() * 10;
            $toman = BigDecimal::of($eur)->multipliedBy($this->pricing->eurToToman())
                ->multipliedBy('0.000001')->toScale(0, self::up())->toInt() * 10000;
            $quote = ['eur_cents' => $eur, 'irt' => $toman];
            if ($quote['irt'] <= 0 || $quote['eur_cents'] <= 0) {
                return null;
            }

            return $quote + [
                'setup_irt' => 0, 'setup_eur_cents' => 0,
                'net_budget_eur' => (string) $net,
                'object_budget_eur' => (string) $objectNet,
                'gateway_budget_eur' => (string) $gatewayNet,
                'landed_cost_eur_cents' => $landedCents,
                'budget_hours' => 744 * $months,
                'capacity_tb' => $capacity, 'egress_tb' => $egress,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function validBook(array $book): bool
    {
        if (($book['currency'] ?? null) !== 'EUR' || ($book['tax_included'] ?? null) !== false
            || ($book['source_url'] ?? null) !== HetznerObjectPriceFeed::SOURCE_URL) {
            return false;
        }
        foreach (['base_monthly_eur', 'base_hourly_eur', 'storage_tb_hour_eur', 'egress_tb_eur', 'gateway_monthly_net_eur'] as $key) {
            $value = $book[$key] ?? null;
            if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,8})?\z/', $value)
                || trim(str_replace('.', '', $value), '0') === '') {
                return false;
            }
        }
        foreach (['quoted_at', 'fetched_at'] as $key) {
            $value = $book[$key] ?? null;
            if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T/', $value)) {
                return false;
            }
            try {
                $date = CarbonImmutable::parse($value);
                if ($date->gt(now()->addMinutes(5)) || $date->lt(now()->subDay())) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Compatible with the deployed Brick Math API and the newer test runtime. */
    private static function up(): mixed
    {
        return constant(RoundingMode::class.(defined(RoundingMode::class.'::Up') ? '::Up' : '::UP'));
    }
}
