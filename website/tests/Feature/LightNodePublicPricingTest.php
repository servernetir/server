<?php

namespace Tests\Feature;

use App\Services\Cloud\LightNodePublicPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightNodePublicPricingTest extends TestCase
{
    private function node(string $name, mixed $value, array $children = [], array $extra = []): array
    {
        return ['itemName' => $name, 'value' => $value, 'children' => $children] + $extra;
    }

    private function package(): array
    {
        return ['regionCode' => 'ph-test', 'zoneCode' => 'ph-test-a', 'cpu' => 2, 'memory' => 4,
            'systemDiskSize' => 50, 'dataDiskSize' => 100, 'publicIpChargeMode' => 'PayByTraffic', 'freeFlow' => 2000];
    }

    private function quoteComponent(string $id, array $prices): array
    {
        $items = [];
        foreach ($prices as $name => $amount) {
            $items[] = ['itemName' => $name, 'originPrice' => $amount, 'discountPrice' => $amount,
                'settlementType' => 'Hour', 'unitName' => $name === 'actualFlow' ? 'GB' : 'Hour'];
        }
        return ['identifier' => $id, 'itemPrices' => $items, 'sumOriginPrice' => array_sum($prices), 'sumDiscountPrice' => array_sum($prices)];
    }

    private function prices(): array
    {
        return [$this->quoteComponent('LIGHTNODE_ECS', ['instanceSpec' => .017856, 'diskSize' => .00105]),
            $this->quoteComponent('OWN_EIP', ['actualFlow' => 2]), $this->quoteComponent('OWN_DISK', ['diskSize' => .0208])];
    }

    private function fake(bool $soldOut = false, string $series = 's2', bool $transientQuote = false): void
    {
        Cache::flush(); Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        // This is a published-code-shaped synthetic anonymous certificate recipe.
        // No real application marker, key or browser credential is a fixture.
        $source = 'function r(){let e=parseInt(new Date().getTime(),10),t=function(e,t){let a,n="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789".split(""),o=[];t=t||n.length,1;for(a=0;a<e;a++)o[a]=n[0|Math.random()*t];return o.join("")}(12,32);return"Basic "+o()(e+":"+t+":fixture-public")}}';
        $range = [$this->node('diskSize', ['min' => 50, 'max' => 250, 'step' => 50])];
        $compute = [$this->node('netType', 'privateNet', [$this->node('instanceType', $series,
            [$this->node('instanceSpec', 'ecs.u2.m4', [], ['prop' => ['cpu' => 2, 'memory' => 4]])])]),
            $this->node('diskType', 'System', [$this->node('diskCategory', 'local_ssd',
                [$this->node('image', 'linux', $range), $this->node('image', 'windows', $range)])])];
        $network = [$this->node('chargeMode', 'PayByTraffic', [$this->node('bandwidthType', 'BGP',
            [$this->node('defaultBandwidth', 100, [$this->node('cpu', 2, [$this->node('actualFlow', 2000)])])])])];
        $data = [$this->node('diskType', 'Data', [$this->node('diskCategory', 'local_ssd',
            [$this->node('diskSize', ['min' => 10, 'max' => 500])])])];
        Http::fake(function ($r) use ($source, $compute, $network, $data, $soldOut, $transientQuote) {
            static $quoteAttempt = 0;
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/site/sku/getBatchPrice.do' && $transientQuote && $quoteAttempt++ === 0) {
                return Http::response([], 503);
            }
            $rows = match ($path) {
                '/product' => null,
                '/_next/static/chunks/pages/_app-abcd.js' => null,
                '/__internal/site-info' => null,
                '/api/site/sku/getRegionAndZoneDetailInfo.do' => [['regionCode' => 'ph-test',
                    'saleStatus' => $soldOut ? 'SOLD_OUT' : 'IN_SELL', 'zones' => [['zoneCode' => 'ph-test-a', 'saleStatus' => 'IN_SELL']]]],
                '/api/site/sku/getBuyConfigInfo.do' => match ($r['resourceType']) {
                    'LIGHTNODE_ECS' => $compute, 'OWN_EIP' => $network, 'OWN_DISK' => $data,
                },
                '/api/site/sku/getBatchPrice.do' => $this->prices(),
                default => throw new \RuntimeException('Unexpected endpoint'),
            };
            return match ($path) {
                '/product' => Http::response('<script src="/_next/static/chunks/pages/_app-abcd.js"></script>'),
                '/_next/static/chunks/pages/_app-abcd.js' => Http::response($source),
                '/__internal/site-info' => Http::response(['id' => 34]),
                default => Http::response(['success' => true, 'httpStatus' => 202, 'data' => $rows], 202),
            };
        });
    }

    public function test_amd_series_must_exist_in_exact_public_region_configuration(): void
    {
        foreach (['s3', 'g2a'] as $series) {
            $this->fake(false, $series);
            $quote = app(LightNodePublicPricing::class)->quote($this->package(), $series);
            $this->assertTrue($quote['ok']);
            $this->assertSame($series, $quote['series']);
            $this->assertFalse(app(LightNodePublicPricing::class)->quote($this->package(), 's2')['ok']);
            Http::assertSent(fn ($r) => str_contains($r->url(), '/getBatchPrice.do')
                && $r['resources'][0]['data']['instanceType'] === $series);
        }
    }

    public function test_transient_public_quote_uses_a_fresh_anonymous_certificate_on_retry(): void
    {
        $this->fake(false, 's2', true);
        $this->assertTrue(app(LightNodePublicPricing::class)->quote($this->package(), 's2')['ok']);
        $attempts = Http::recorded(fn ($r) => str_contains($r->url(), '/getBatchPrice.do'))->values();
        $this->assertCount(2, $attempts);
        $this->assertNotSame($attempts[0][0]->header('Authorization'), $attempts[1][0]->header('Authorization'));
        Http::assertNotSent(fn ($r) => $r->hasHeader('x-open-token') || $r->hasHeader('Cookie'));
    }

    public function test_catalog_config_is_shared_but_purchase_reloads_config_and_never_reuses_a_price(): void
    {
        $this->fake(); $pricing = app(LightNodePublicPricing::class);
        $this->assertTrue($pricing->quote($this->package(), 's2', 'linux', false)['ok']);
        $this->assertTrue($pricing->quote($this->package(), 's2', 'windows', false)['ok']);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/getRegionAndZoneDetailInfo.do')));
        $this->assertCount(3, Http::recorded(fn ($r) => str_contains($r->url(), '/getBuyConfigInfo.do')));
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), '/getBatchPrice.do')));
        $this->assertTrue($pricing->quote($this->package(), 's2', 'linux')['ok']);
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), '/getRegionAndZoneDetailInfo.do')));
        $this->assertCount(6, Http::recorded(fn ($r) => str_contains($r->url(), '/getBuyConfigInfo.do')));
        $this->assertCount(3, Http::recorded(fn ($r) => str_contains($r->url(), '/getBatchPrice.do')));
    }

    public function test_public_quote_matches_real_component_precision_without_account_authentication(): void
    {
        $this->fake();
        $quote = app(LightNodePublicPricing::class)->quote($this->package(), 's2', 'windows');
        $this->assertTrue($quote['ok']); $this->assertSame('USD', $quote['currency']);
        $this->assertSame('0.042682190476', $quote['hour_usd']);
        $this->assertSame('28.682432000000', $quote['month_usd']);
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://www.lightnode.com/')
            || $r->hasHeader('x-open-token') || $r->hasHeader('Cookie'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'getBatchPrice.do') && $r->method() === 'POST'
            && ! isset($r['userId']) && $r['resources'][0]['data']['image'] === 'windows'
            && $r['resources'][2]['data']['diskSize'] === 100);
        $certificates = Http::recorded(fn ($r) => $r->hasHeader('Authorization'))->map(fn ($pair) => $pair[0]->header('Authorization')[0]);
        $this->assertSame($certificates->count(), $certificates->unique()->count());
    }

    public function test_bandwidth_cost_is_hourly_and_is_not_divided_by_672(): void
    {
        $rows = [$this->quoteComponent('LIGHTNODE_ECS', ['instanceSpec' => .008928, 'diskSize' => .00105]),
            $this->quoteComponent('OWN_EIP', ['bandwidth' => .00744])];
        $quote = app(LightNodePublicPricing::class)->normalize($rows, 'PayByBandwidth', false);
        $this->assertSame('0.017418000000', $quote['hour_usd']);
        $this->assertSame('12.958992000000', $quote['month_usd']);
        $this->assertSame('11.704896000000', $quote['calculator_month_usd']);
    }

    public function test_unavailable_region_never_requests_a_price(): void
    {
        $this->fake(true);
        $this->assertFalse(app(LightNodePublicPricing::class)->quote($this->package(), 's2')['ok']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'getBatchPrice.do'));
    }

    public function test_unknown_series_and_mismatched_resources_never_request_a_price(): void
    {
        $this->fake();
        $this->assertFalse(app(LightNodePublicPricing::class)->quote($this->package(), 'unknown')['ok']);
        $package = $this->package(); $package['memory'] = 8;
        $this->assertFalse(app(LightNodePublicPricing::class)->quote($package, 's2')['ok']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'getBatchPrice.do'));
    }

    public function test_invalid_components_units_duplicates_discounts_and_missing_disks_are_rejected(): void
    {
        $cases = [];
        $rows = $this->prices(); unset($rows[2]); $cases[] = $rows;
        $rows = $this->prices(); $rows[] = $rows[0]; $cases[] = $rows;
        $rows = $this->prices(); $rows[0]['sumOriginPrice'] = .01; $cases[] = $rows;
        $rows = $this->prices(); $rows[1]['itemPrices'][0]['unitName'] = 'Hour'; $cases[] = $rows;
        $rows = $this->prices(); $rows[0]['itemPrices'][0]['discountPrice'] = .0001; $cases[] = $rows;
        foreach ($cases as $rows) {
            try { app(LightNodePublicPricing::class)->normalize($rows, 'PayByTraffic', true); $this->fail('Invalid quote accepted'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }
}
