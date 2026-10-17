<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Setting;
use App\Services\Domain\OpenProviderClient;
use App\Services\Domain\TldPriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class OpenproviderAllTldCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        Storage::fake('local');
        Http::fake();
        config([
            'services.openprovider.username' => 'test-user',
            'services.openprovider.password' => 'test-password',
            'services.openprovider.margin' => ['default' => 25],
            'domain_pricing.supplier_vat_pct' => 0,
            'domain_pricing.supplier_fee_pct' => 0,
            'domain_reseller.min_margin_pct' => 8,
        ]);
        Currency::updateOrCreate(['code' => 'IRT'], ['exponent' => 0, 'rounding_step' => 1000]);
        $this->rates();
    }

    private function rates(array $values = ['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000]): void
    {
        foreach (['USD', 'EUR', 'USDT'] as $currency) {
            Setting::put('fx_last_'.strtolower($currency), null);
        }
        NavasanRates::store($values);
    }

    private function row(string $name = 'com', float $price = 10, string $currency = 'USD'): array
    {
        $pair = ['reseller' => ['price' => $price, 'currency' => $currency],
            'product' => ['price' => 999, 'currency' => 'GBP']];
        return ['name' => $name, 'status' => 'ACT', 'min_period' => 1, 'max_period' => 10,
            'renew_available' => true, 'transfer_available' => true,
            'prices' => ['min_period' => 1, 'max_period' => 10,
                'reseller_price' => $pair, 'create_price' => $pair,
                'renew_price' => $pair, 'transfer_price' => $pair]];
    }

    private function page(array $rows, ?int $total = null): array
    {
        return ['ok' => true, 'code' => 0, 'results' => $rows, 'total' => $total ?? count($rows)];
    }

    private function book(array $pages = [], mixed $vat = 20): TldPriceBook
    {
        $provider = Mockery::mock(OpenProviderClient::class);
        if ($pages === []) {
            $provider->shouldNotReceive('listTlds');
            $provider->shouldNotReceive('resellerInfo');
        } else {
            $offset = 0;
            foreach ($pages as $page) {
                $provider->shouldReceive('listTlds')->once()->with(1000, $offset, true)->andReturn($page)->ordered();
                $offset += count(is_array($page['results'] ?? null) ? $page['results'] : []);
            }
            $provider->shouldReceive('resellerInfo')->andReturn(['ok' => true, 'data' => ['vatperc' => $vat]]);
        }
        $provider->shouldNotReceive('check');
        $provider->shouldNotReceive('domainPrice');
        $this->app->instance(OpenProviderClient::class, $provider);
        return new TldPriceBook($provider);
    }

    private function seedCatalog(): string
    {
        $this->book([$this->page([$this->row('old')])])->refreshAll();
        return Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH);
    }

    public function test_full_pagination_includes_more_than_one_thousand_unique_extensions(): void
    {
        $rows = array_map(fn ($i) => $this->row(sprintf('example%04d', $i)), range(1, 1087));
        $book = $this->book([$this->page(array_slice($rows, 0, 1000), 1087), $this->page(array_slice($rows, 1000), 1087)]);
        $result = $book->refreshAll();

        $this->assertSame(1087, $result['total']);
        $this->assertSame(1087, $result['count']);
        $this->assertCount(1087, $book->catalogTlds());
        $this->assertSame(1500000, $book->cachedForTlds(['example1087'])['example1087']);
        Http::assertNothingSent();
    }

    public static function failedPageCases(): array
    {
        return [['changed_total'], ['duplicate'], ['empty'], ['api_error'], ['oversized_total'], ['invalid_row'], ['missing_price']];
    }

    #[DataProvider('failedPageCases')]
    public function test_failed_refresh_preserves_the_previous_complete_file(string $failure): void
    {
        $old = $this->seedCatalog();
        $first = $this->page([$this->row('new')], 2);
        $second = $this->page([$this->row('next')], 2);
        match ($failure) {
            'changed_total' => $second['total'] = 3,
            'duplicate' => $second['results'] = [$this->row('new')],
            'empty' => $second['results'] = [],
            'api_error' => $second = ['ok' => false, 'code' => 429, 'results' => [], 'total' => 2],
            'oversized_total' => $second['total'] = 10001,
            'invalid_row' => $second['results'] = ['not a row'],
            'missing_price' => $second['results'][0]['prices'] = [],
        };
        try {
            $this->book([$first, $second])->refreshAll();
            $this->fail('An incomplete or invalid catalogue must not be published.');
        } catch (RuntimeException) {
            $this->assertSame($old, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
            $this->assertSame(['old' => 1500000], $this->book()->cachedForTlds(['old', 'new', 'next']));
        }
        Http::assertNothingSent();
    }

    public function test_inactive_and_locally_unsold_rows_are_excluded_without_losing_coverage(): void
    {
        $pre = $this->row('pending');
        $pre['status'] = 'PRE';
        $unknown = $this->row('unknown');
        $unknown['status'] = 'NEW_STATUS';
        $book = $this->book([$this->page([$this->row(), $pre, $unknown, $this->row('ir')])]);
        $result = $book->refreshAll();

        $this->assertSame(4, $result['total']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(3, $result['excluded']);
        $this->assertSame(['com'], $book->catalogTlds());
    }

    public function test_every_cached_reader_accepts_arbitrary_subsets_without_http(): void
    {
        $this->book([$this->page([$this->row('co.example'), $this->row('com')])])->refreshAll();
        $book = $this->book();

        $this->assertSame(['co.example' => 1500000], $book->forTlds([' .CO.EXAMPLE ', 'co.example', 'ir', 'missing']));
        $this->assertSame($book->cachedFullForTlds(['com']), $book->fullForTlds(['com']));
        $this->assertSame([], $book->cachedForTlds([]));
        Http::assertNothingSent();
    }

    public function test_native_currency_per_operation_is_revalued_without_refreshing_source_age(): void
    {
        $row = $this->row();
        $row['prices']['renew_price']['reseller'] = ['price' => 20, 'currency' => 'EUR'];
        $row['prices']['transfer_price']['reseller'] = ['price' => 30, 'currency' => 'USDT'];
        $book = $this->book([$this->page([$row])]);
        $book->refreshAll();
        $raw = Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH);
        $first = $book->cachedFullForTlds(['com'])['com'];
        $this->assertSame([1500000, 6000000, 6750000], [$first['register'], $first['renew'], $first['transfer']]);
        $this->travel(6)->hours();
        $this->rates(['USD' => 200000, 'EUR' => 400000, 'USDT' => 300000]);
        $second = $book->cachedFullForTlds(['com'])['com'];
        $this->assertSame([3000000, 12000000, 13500000], [$second['register'], $second['renew'], $second['transfer']]);
        $this->assertSame($raw, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
        Http::assertNothingSent();
    }

    public function test_missing_unrelated_currency_does_not_hide_a_usd_only_catalogue(): void
    {
        $this->seedCatalog();
        $this->rates(['USD' => 100000]);
        $this->assertSame(['old' => 1500000], $this->book()->cachedForTlds(['old']));
        Http::assertNothingSent();
    }

    public function test_missing_operation_fx_is_null_without_hiding_other_valid_quotes(): void
    {
        $row = $this->row();
        $row['prices']['renew_price']['reseller']['currency'] = 'EUR';
        $this->book([$this->page([$row])])->refreshAll();
        $this->rates(['USD' => 100000]);
        $price = $this->book()->cachedFullForTlds(['com'])['com'];
        $this->assertSame(1500000, $price['register']);
        $this->assertNull($price['renew']);
        $this->assertSame(1500000, $price['transfer']);
    }

    public function test_verified_zero_vat_preserves_existing_reserve_and_ignores_manual_rate(): void
    {
        Setting::put('domain_supplier_vat_pct', '20');
        Setting::put('pricing_rate_override', '300000');
        $book = $this->book([$this->page([$this->row('euro', 10, 'EUR')])], 0);
        $book->refreshAll();
        $this->assertSame(['euro' => 3000000], $book->cachedForTlds(['euro']));
        $this->assertEquals(0, $book->metadata()['supplier_vat_pct']);
    }

    public function test_a_two_year_registration_quote_is_not_multiplied_again(): void
    {
        $row = $this->row('ai', 160);
        $row['min_period'] = $row['prices']['min_period'] = 2;
        $book = $this->book([$this->page([$row])]);
        $book->refreshAll();
        $price = $book->cachedFullForTlds(['ai'])['ai'];
        $this->assertSame(2, $price['min_years']);
        $this->assertSame(10, $price['max_years']);
        $this->assertSame(24000000, $price['register']);
    }

    public function test_unsupported_operations_are_null_and_explicit_free_transfer_stays_zero(): void
    {
        $uk = $this->row('uk');
        $uk['renew_available'] = false;
        $uk['prices']['transfer_price']['reseller']['price'] = 0;
        $missing = $this->row('missing');
        unset($missing['prices']['renew_price']);
        $missing['transfer_available'] = false;
        $badCurrency = $this->row('badcurrency');
        $badCurrency['prices']['transfer_price']['reseller']['currency'] = 'JPY';
        $book = $this->book([$this->page([$uk, $missing, $badCurrency])]);
        $book->refreshAll();
        $prices = $book->cachedFullForTlds(['uk', 'missing', 'badcurrency']);

        $this->assertNull($prices['uk']['renew']);
        $this->assertSame(0, $prices['uk']['transfer']);
        $this->assertNull($prices['missing']['renew']);
        $this->assertNull($prices['missing']['transfer']);
        $this->assertNull($prices['badcurrency']['transfer']);
    }

    public function test_creation_falls_back_to_suppliers_create_price_only_when_reseller_price_absent(): void
    {
        $row = $this->row();
        unset($row['prices']['reseller_price']);
        $book = $this->book([$this->page([$row])]);
        $book->refreshAll();
        $this->assertSame(['com' => 1500000], $book->cachedForTlds(['com']));
    }

    public function test_promotion_is_used_only_inside_its_window_then_regular_price_returns(): void
    {
        $row = $this->row('promo', 5);
        $row['prices']['reseller_price']['promotion_details'] = [
            'start_date' => (string) now()->addHour()->timestamp,
            'end_date' => (string) now()->addHours(2)->timestamp,
            'non_promo_price' => ['reseller' => ['price' => 10, 'currency' => 'USD']],
        ];
        $book = $this->book([$this->page([$row])]);
        $book->refreshAll();
        $this->assertSame(1500000, $book->cachedForTlds(['promo'])['promo']);
        $this->travel(90)->minutes();
        $this->assertSame(750000, $book->cachedForTlds(['promo'])['promo']);
        $this->travel(30)->minutes();
        $this->assertSame(1500000, $book->cachedForTlds(['promo'])['promo']);
        Http::assertNothingSent();
    }

    public function test_live_date_only_promotion_fields_are_accepted(): void
    {
        $row = $this->row('academy', 5);
        $row['prices']['reseller_price']['promotion_details'] = [
            'start_date' => '2026-07-01', 'end_date' => '2026-12-31',
            'non_promo_price' => ['reseller' => ['price' => 42, 'currency' => 'USD']],
        ];
        $book = $this->book([$this->page([$row])]);
        $book->refreshAll();
        $this->assertSame(750000, $book->cachedForTlds(['academy'])['academy']);
    }

    public function test_malformed_promotion_does_not_replace_the_previous_catalogue(): void
    {
        $old = $this->seedCatalog();
        $row = $this->row('promo', 5);
        $row['prices']['reseller_price']['promotion_details'] = [
            'start_date' => 'not-a-date', 'end_date' => '2026-12-31',
            'non_promo_price' => ['reseller' => ['price' => 42, 'currency' => 'USD']],
        ];
        try {
            $this->book([$this->page([$row])])->refreshAll();
            $this->fail('Malformed promotion must not publish.');
        } catch (RuntimeException) {
            $this->assertSame($old, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
        }
    }

    public function test_expired_or_absent_snapshot_never_uses_manual_default_prices(): void
    {
        config(['servernet.tlds' => [['tld' => '.com', 'irt' => 777777]]]);
        $this->assertSame([], $this->book()->forTlds(['com']));
        $this->seedCatalog();
        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        $this->assertSame([], $this->book()->fullForTlds(['old', 'com']));
        $this->assertSame([], $this->book()->catalogTlds());
        Http::assertNothingSent();
    }

    public static function malformedSnapshotCases(): array
    {
        return [['invalid_json'], ['row_scalar'], ['missing_period'], ['negative_amount'],
            ['unknown_currency'], ['invalid_promotion'], ['negative_vat'], ['future_time'], ['empty_time'], ['coverage_mismatch']];
    }

    #[DataProvider('malformedSnapshotCases')]
    public function test_malformed_persistent_snapshot_fails_closed_without_public_exception(string $case): void
    {
        $snapshot = json_decode($this->seedCatalog(), true, 512, JSON_THROW_ON_ERROR);
        match ($case) {
            'row_scalar' => $snapshot['rows']['old'] = 1,
            'missing_period' => $snapshot['rows']['old']['min_years'] = null,
            'negative_amount' => $snapshot['rows']['old']['register']['amount'] = -1,
            'unknown_currency' => $snapshot['rows']['old']['register']['currency'] = 'JPY',
            'invalid_promotion' => $snapshot['rows']['old']['promotion'] = ['start' => 'nonsense'],
            'negative_vat' => $snapshot['supplier_vat_pct'] = -5,
            'future_time' => $snapshot['fetched_at'] = now()->addDay()->toIso8601String(),
            'empty_time' => $snapshot['fetched_at'] = '',
            'coverage_mismatch' => $snapshot['total'] = 2,
            default => null,
        };
        Storage::disk('local')->put(TldPriceBook::SNAPSHOT_PATH,
            $case === 'invalid_json' ? '{' : json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->book()->cachedFullForTlds(['old']));
        $this->assertSame([], $this->book()->catalogTlds());
        Http::assertNothingSent();
    }

    public function test_a_busy_refresh_lock_does_not_contact_provider_or_change_file(): void
    {
        $old = $this->seedCatalog();
        $lock = Cache::lock('tld.catalog.refresh', 900);
        $this->assertTrue($lock->get());
        try {
            $this->book()->refreshAll();
            $this->fail('An active refresh must prevent a concurrent fetch.');
        } catch (RuntimeException) {
            $this->assertSame($old, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_invalid_account_vat_keeps_previous_catalogue_and_releases_lock(): void
    {
        $old = $this->seedCatalog();
        try {
            $this->book([$this->page([$this->row('new')])], 'not-a-rate')->refreshAll();
            $this->fail('Unverified supplier VAT cannot publish prices.');
        } catch (RuntimeException) {
            $this->assertSame($old, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
        }
        $this->assertTrue(Cache::lock('tld.catalog.refresh', 900)->get());
    }

    public function test_lock_release_error_does_not_turn_published_catalogue_into_failure(): void
    {
        $lock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('release failed'));
        $cache = Mockery::mock(Cache::getFacadeRoot());
        $cache->shouldReceive('lock')->once()->with('tld.catalog.refresh', 900)->andReturn($lock);
        Cache::swap($cache);
        $book = $this->book([$this->page([$this->row()])]);
        $this->assertSame(1, $book->refreshAll()['count']);
        $this->assertSame(['com' => 1500000], $book->cachedForTlds(['com']));
    }

    public function test_file_publication_preparation_failure_keeps_previous_catalogue(): void
    {
        $old = $this->seedCatalog();
        File::partialMock()->shouldReceive('ensureDirectoryExists')->once()->andThrow(new RuntimeException('write failed'));
        try {
            $this->book([$this->page([$this->row('new')])])->refreshAll();
            $this->fail('A failed file write must not report a successful refresh.');
        } catch (RuntimeException) {
            $this->assertSame($old, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
        }
    }

    public function test_real_client_uses_textual_query_boolean_and_keeps_raw_pagination_values(): void
    {
        Cache::put('openprovider.token', 'fixture-token', 600);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/tlds*' => Http::response(['code' => 0,
            'data' => ['results' => [$this->row()], 'total' => 2040]])]);
        $result = (new OpenProviderClient())->listTlds(1000, 1000, true);
        $this->assertTrue($result['ok']);
        $this->assertSame(2040, $result['total']);
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return $request->method() === 'GET'
                && ($query['with_price'] ?? null) === 'true'
                && ($query['limit'] ?? null) === '1000'
                && ($query['offset'] ?? null) === '1000';
        });
        Http::assertSentCount(1);
    }

    public function test_real_client_does_not_cast_a_malformed_total_into_valid_coverage(): void
    {
        $old = $this->seedCatalog();
        Cache::put('openprovider.token', 'fixture-token', 600);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/tlds*' => Http::response(['code' => 0,
            'data' => ['results' => [$this->row('new')], 'total' => '1bad']])]);
        try {
            (new TldPriceBook(new OpenProviderClient()))->refreshAll();
            $this->fail('Malformed API total must not become an integer success.');
        } catch (RuntimeException) {
            $this->assertSame($old, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
        }
        Http::assertSent(fn ($request) => str_contains($request->url(), '/tlds'));
    }
}
