<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\Setting;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\TldPriceBook;
use App\Services\Domain\Reseller\ResellerPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllTldPresentationTest extends TestCase
{
    use RefreshDatabase;

    private array $prices = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::put('pricing_rate_override', '200000');
        $book = $this->mock(TldPriceBook::class);
        $book->shouldReceive('cachedFullForTlds')->andReturnUsing(function (array $tlds) {
            $keys = array_map(fn ($tld) => strtolower(ltrim($tld, '.')), $tlds);
            return array_intersect_key($this->prices, array_flip($keys));
        });
        $book->shouldReceive('catalogTlds')->andReturnUsing(fn () => array_keys($this->prices));
        $book->shouldNotReceive('forTlds');
        $book->shouldNotReceive('fullForTlds');
    }

    private function price(int $years = 1, array $extra = []): array
    {
        return $extra + ['register' => 3000000, 'renew' => 4000000, 'transfer' => 2000000,
            'min_years' => $years, 'max_years' => 10];
    }

    public static function locales(): array
    {
        return [['fa', '', '۲ ساله'], ['en', '/en', '2 years'], ['tr', '/tr', '2 yıl']];
    }

    #[DataProvider('locales')]
    public function test_minimum_term_total_is_labeled_on_chips_cards_and_schema(string $locale, string $prefix, string $term): void
    {
        $this->prices = ['ai' => $this->price(2)];
        config(['servernet.featured_tlds' => ['.ai', '.tech'], 'servernet.tlds' => [
            ['tld' => '.tech', 'irt' => 987654321],
        ]]);
        $plan = config('catalog.domain.popular-tlds.plans.0');
        $plan['name'] = '.ai';
        $plan['tld'] = 'ai';
        config(['catalog.domain.popular-tlds.plans' => [$plan]]);

        foreach ([$prefix ?: '/', $prefix.'/domain/popular-tlds'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~data-tld="\.ai".*?'.preg_quote($term, '~').'~u', $html);
            $this->assertStringNotContainsString('data-tld=".tech"', $html);
            $this->assertStringNotContainsString('987654321', $html);
        }
        $response = $this->get($prefix.'/domain/popular-tlds')->assertOk();
        $response->assertViewHas('product', fn (array $product) =>
            $product['plans'][0]['irt'] === 3000000 && $product['plans'][0]['min_years'] === 2);
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), $term));
        if ($locale === 'fa') {
            $this->assertMatchesRegularExpression('~"price"\s*:\s*"?30000000"?~', $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_missing_catalog_price_uses_quote_ui_instead_of_config_amount(): void
    {
        $plan = config('catalog.domain.popular-tlds.plans.0');
        $plan['name'] = '.tech';
        $plan['tld'] = 'tech';
        $plan['irt'] = 987654321;
        config(['catalog.domain.popular-tlds.plans' => [$plan]]);

        $response = $this->get('/domain/popular-tlds')->assertOk();
        $response->assertViewHas('product', fn (array $product) =>
            ! isset($product['plans'][0]['irt']) && $product['plans'][0]['quote'] === true);
        $this->assertStringContainsString(__('ui.dsr_quote_price'), $response->getContent());
        Http::assertNothingSent();
    }

    private function reseller(): Customer
    {
        $customer = Customer::create(['email' => 'catalog@example.test', 'password' => bcrypt('x'),
            'status' => 'active', 'locale' => 'fa']);
        $customer->forceFill(['is_reseller' => true, 'reseller_level' => 'gold'])->save();
        return $customer;
    }

    private function api(string $query = '')
    {
        [, $token] = CustomerApiToken::issue($this->reseller()->id, 'catalog', ['domains:read']);
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/tlds'.$query);
    }

    public function test_api_defaults_to_the_complete_catalog_beyond_sixty_rows(): void
    {
        for ($i = 0; $i < 75; $i++) {
            $this->prices['catalog'.chr(97 + intdiv($i, 26)).chr(97 + $i % 26)] = $this->price();
        }
        $this->prices['ai'] = $this->price(2);
        $response = $this->api()->assertOk()->assertJsonCount(76, 'data');
        $ai = collect($response->json('data'))->firstWhere('tld', 'ai');
        $this->assertSame(2, $ai['min_years']);
        $this->assertSame(10, $ai['max_years']);
        $this->assertSame(3000000, $ai['retail']['register']);
        Http::assertNothingSent();
    }

    public function test_api_preserves_explicit_subset_nullable_operations_and_free_transfer(): void
    {
        $this->prices = ['com' => $this->price(), 'ai' => $this->price(2),
            'free' => $this->price(1, ['transfer' => 0]),
            'missing' => $this->price(1, ['renew' => null]),
            'com.kw' => $this->price(1, ['transfer' => null]),
            'badperiod' => $this->price(0), 'ir' => $this->price()];
        $query = '?'.http_build_query(['tlds' => ['ai', 'free', 'missing', 'com.kw', 'badperiod', 'ir']]);
        $rows = $this->api($query)->assertOk()->assertJsonCount(4, 'data')->json('data');
        $this->assertSame(['ai', 'free', 'missing', 'com.kw'], array_column($rows, 'tld'));
        $this->assertSame(0, $rows[1]['transfer']);
        $this->assertSame(0, $rows[1]['retail']['transfer']);
        $this->assertNull($rows[2]['renew']);
        $this->assertNull($rows[2]['retail']['renew']);
        $this->assertNull($rows[3]['transfer']);
        $this->assertNull($rows[3]['retail']['transfer']);
        Http::assertNothingSent();
    }

    public function test_search_uses_cached_actual_tld_with_minimum_term_label(): void
    {
        $this->prices = ['ai' => $this->price(2)];
        $search = $this->mock(DomainSearch::class);
        $search->shouldReceive('search')->once()->withArgs(fn ($name, $tlds) =>
            $name === 'example' && in_array('ai', $tlds, true))->andReturn([
                ['domain' => 'example.ai', 'tld' => 'ai', 'state' => 'free', 'available' => true,
                    'is_premium' => false, 'price_toman' => 0],
            ]);
        $search->shouldReceive('lookupOk')->andReturnTrue();
        $this->postJson(route('domain.check'), ['domain' => 'example.ai'])->assertOk()
            ->assertJsonPath('result.price', '۳,۰۰۰,۰۰۰ تومان')
            ->assertJsonPath('result.period_label', '۲ ساله');
        Http::assertNothingSent();
    }

    public function test_live_premium_name_price_is_not_replaced_by_base_catalog_total(): void
    {
        $this->prices = ['ai' => $this->price(2)];
        $search = $this->mock(DomainSearch::class);
        $search->shouldReceive('search')->once()->andReturn([
            ['domain' => 'premium.ai', 'tld' => 'ai', 'state' => 'premium', 'available' => true,
                'is_premium' => true, 'price_toman' => 90000000],
        ]);
        $search->shouldReceive('lookupOk')->andReturnTrue();
        $this->postJson(route('domain.check'), ['domain' => 'premium.ai'])->assertOk()
            ->assertJsonPath('result.price', '۹۰,۰۰۰,۰۰۰ تومان');
        Http::assertNothingSent();
    }

    public function test_reseller_rounding_step_is_read_once_per_pricing_instance(): void
    {
        $customer = $this->reseller();
        config(['domain_reseller.levels' => [['key' => 'gold', 'discount_pct' => 10]]]);
        $queries = 0;
        DB::listen(function ($event) use (&$queries) {
            if (str_contains(strtolower($event->sql), 'currencies')) { $queries++; }
        });
        $pricing = app(ResellerPricing::class);
        for ($i = 0; $i < 12; $i++) { $this->assertSame(900000, $pricing->price(1000000, null, $customer)['price']); }
        $this->assertSame(1, $queries);
        $this->assertSame(0, $pricing->price(0, null, $customer)['price']);
    }
}
