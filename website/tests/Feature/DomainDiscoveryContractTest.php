<?php

namespace Tests\Feature;

use App\Services\Domain\DomainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainDiscoveryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_similar_names_are_bounded_valid_and_never_claim_to_be_available(): void
    {
        Http::preventStrayRequests();
        $search = app(DomainSearch::class);
        foreach (['Example.COM', 'brand.co.uk', 'فروشگاه.com', str_repeat('a', 63).'.com', 'x', '', 'xn--bcher-kva.com'] as $term) {
            $ideas = $search->similarNames($term);
            $this->assertLessThanOrEqual(6, count($ideas));
            $this->assertSame(count($ideas), count(array_unique(array_column($ideas, 'name'))));
            foreach ($ideas as $idea) {
                $this->assertSame(['name'], array_keys($idea));
                $this->assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.[a-z0-9.-]+$/D', $idea['name']);
                $this->assertNotSame(strtolower($term), $idea['name']);
            }
        }
        $this->assertStringEndsWith('.com', $search->similarNames('example.ir')[0]['name']);
        Http::assertNothingSent();
    }

    private function fakeSearch(array $rows): void
    {
        Http::preventStrayRequests();
        $search = \Mockery::mock(DomainSearch::class)->makePartial();
        $search->shouldReceive('search')->once()->andReturn($rows);
        $search->shouldReceive('lookupOk')->andReturn(true);
        $search->shouldReceive('lookupReason')->andReturn(null);
        $this->app->instance(DomainSearch::class, $search);
    }

    private function offer(array $extra = []): array
    {
        return $extra + ['domain' => 'example.com', 'tld' => 'com', 'available' => true,
            'state' => 'free', 'status' => 'available', 'orderable' => true,
            'is_premium' => false, 'quote_id' => 42, 'price_toman' => 1000000, 'period_years' => 1,
            'is_promotion' => true, 'regular_price_toman' => 2000000, 'discount_percent' => 50,
            'promotion_ends_at' => now()->addHour()->toIso8601String()];
    }

    public function test_home_free_result_keeps_exact_quote_and_provides_discovery_link_and_verified_discount(): void
    {
        $this->fakeSearch([$this->offer()]);
        $json = $this->postJson(route('domain.check'), ['domain' => 'example.com'])->assertOk()->json();
        $this->assertTrue($json['result']['is_promotion']);
        $this->assertNotNull($json['result']['regular_price']);
        $this->assertSame(50, $json['result']['discount_percent']);
        $this->assertStringContainsString('/account/domains/checkout/42', $json['result']['cart_url']);
        $this->assertStringContainsString('q=example.com', $json['more_url']);
        $this->assertCount(6, $json['suggestion_names']);
        Http::assertNothingSent();
    }

    public function test_home_expired_reference_is_not_a_discount(): void
    {
        $this->fakeSearch([$this->offer(['promotion_ends_at' => now()->subSecond()->toIso8601String()])]);
        $json = $this->postJson(route('domain.check'), ['domain' => 'example.com'])->assertOk()->json();
        $this->assertFalse($json['result']['is_promotion']);
        $this->assertNull($json['result']['regular_price']);
        $this->assertNull($json['result']['discount_percent']);
        Http::assertNothingSent();
    }

    public function test_public_search_returns_unchecked_naming_ideas_alongside_the_exact_primary(): void
    {
        $this->fakeSearch([$this->offer()]);
        $json = $this->postJson('/api/domains/search', ['q' => 'example.com', 'tlds' => ['com']])->assertOk()->json();
        $this->assertTrue($json['results'][0]['primary']);
        $this->assertCount(6, $json['suggestion_names']);
        $this->assertArrayNotHasKey('available', $json['suggestion_names'][0]);
        Http::assertNothingSent();
    }

    public function test_supplier_search_projects_only_public_retail_promotion_fields(): void
    {
        Http::preventStrayRequests();
        $pricing = \Mockery::mock(\App\Services\Domain\DomainProcurement::class);
        $pricing->shouldReceive('enabled')->andReturn(true);
        $pricing->shouldReceive('withSearchReads')->once()->andReturnUsing(fn ($callback) => $callback());
        $quote = new \App\Models\DomainQuote(['id' => 42, 'is_premium' => false, 'sell_toman' => 1000000,
            'period_months' => 12, 'raw' => ['supplier_quote' => ['is_promotion' => true,
                'regular_price_toman' => 2000000, 'discount_percent' => 50,
                'promotion_ends_at' => now()->addHour()->toIso8601String(),
                'provider' => 'private-provider', 'supplier_account_id' => 99, 'purchase_minor' => 500,
                'secret' => 'synthetic-private-secret']]]);
        $pricing->shouldReceive('quote')->once()->with('example.com', 'create', 12, [], null, null)->andReturn($quote);
        $this->app->instance(\App\Services\Domain\DomainProcurement::class, $pricing);
        $rows = app(DomainSearch::class)->search('example', ['com']);
        $this->assertTrue($rows[0]['is_promotion']);
        $this->assertSame(2000000, $rows[0]['regular_price_toman']);
        $this->assertSame(50, $rows[0]['discount_percent']);
        $this->assertSame(12, $rows[0]['period_months']);
        $this->assertStringNotContainsString('private', json_encode($rows));
        $this->assertArrayNotHasKey('supplier_account_id', $rows[0]);
        Http::assertNothingSent();
    }
}
