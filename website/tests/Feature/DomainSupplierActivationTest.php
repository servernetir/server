<?php

namespace Tests\Feature;

use App\Models\{Currency, DomainSupplierAccount, Setting, User};
use App\Services\Domain\{DomainSupplierCatalogue, DomainSupplierProbe, TldPriceBook};
use App\Services\Domain\Providers\{DomainProvider, DomainProviderFactory};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Storage};
use Tests\TestCase;

/**
 * What has to be true before DOMAIN_MULTI_PROVIDER_ENABLED can be turned on
 * without the site losing every domain price: a usable default capability
 * profile, a health window wider than one probe interval, and an owner-only
 * way to see and refresh the supplier catalogue first.
 */
class DomainSupplierActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Storage::fake('local');
        config(['domain_suppliers.enabled' => true, 'domain_reseller.min_margin_pct' => 0,
            'domain_suppliers.transfer_account_id' => 0]);
        Setting::put('domain_margin_pct', '1');
        foreach (['IRT' => 0, 'USD' => 2] as $code => $exponent) {
            Currency::updateOrCreate(['code' => $code], ['exponent' => $exponent, 'rounding_step' => $code === 'IRT' ? 1000 : 1,
                'is_active' => true, 'is_base' => $code === 'IRT']);
        }
        DomainSupplierAccount::query()->update(['active' => false]);
        $fx = \Mockery::mock(\App\Services\ExchangeRate::class);
        $fx->shouldReceive('current')->andReturnUsing(fn ($code) => ['currency' => $code, 'rate_toman' => 100000,
            'at' => now()->toIso8601String(), 'source_at' => now()->toIso8601String(), 'source' => 'synthetic']);
        $this->app->instance(\App\Services\ExchangeRate::class, $fx);
    }

    private function account(string $provider, array $capabilities, array $extra = []): DomainSupplierAccount
    {
        return DomainSupplierAccount::create($extra + ['provider' => $provider, 'environment' => 'production',
            'label' => ucfirst($provider).' synthetic', 'active' => true, 'sales_enabled' => true,
            'contract_verified' => true, 'identity_verified' => true, 'pricing_verified' => true,
            'connection_status' => 'healthy', 'health_checked_at' => now(), 'balance_minor' => 100000,
            'balance_currency' => 'USD', 'credentials' => ['api_key' => 'synthetic-'.$provider],
            'capabilities' => $capabilities]);
    }

    private function offer(string $tld, int $amount, string $operation = 'create', int $months = 12): array
    {
        return ['tld' => $tld, 'operation' => $operation, 'period_months' => $months, 'currency' => 'USD',
            'amount_minor' => $amount, 'provider_debit_minor' => $amount, 'premium' => false, 'tax_included' => true,
            'valid_until' => now()->addDay()->toIso8601String(), 'components' => []];
    }

    /** @param array<int, array|false> $catalogues */
    private function factory(array $catalogues): void
    {
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->andReturnUsing(function ($a) use ($catalogues) {
            $p = \Mockery::mock(DomainProvider::class);
            if ($catalogues[$a->id] === false) $p->shouldReceive('catalogue')->andThrow(new \RuntimeException('synthetic'));
            else $p->shouldReceive('catalogue')->andReturn(['offers' => $catalogues[$a->id],
                'suffixes' => array_values(array_unique(array_column($catalogues[$a->id], 'tld'))), 'source' => 'synthetic']);
            $p->shouldNotReceive('submit'); $p->shouldNotReceive('manage'); $p->shouldNotReceive('quote');
            return $p;
        });
        $this->app->instance(DomainProviderFactory::class, $factory);
    }

    public function test_an_empty_profile_sells_nothing_which_is_why_the_flag_alone_wipes_prices(): void
    {
        $a = $this->account('joker', []);
        $this->factory([$a->id => [$this->offer('com', 1000), $this->offer('xyz', 300)]]);
        app(TldPriceBook::class)->refreshAll();
        $this->assertSame([], app(TldPriceBook::class)->catalogTlds());
    }

    public function test_standard_profile_lets_every_suffix_the_account_prices_compete(): void
    {
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        $this->factory([$a->id => [$this->offer('com', 1000), $this->offer('xyz', 300), $this->offer('com', 1100, 'renew'),
            $this->offer('com', 1900, 'create', 24)]]);
        app(TldPriceBook::class)->refreshAll();
        $rows = app(TldPriceBook::class)->cachedFullForTlds(['com', 'xyz']);
        $this->assertSame(['com', 'xyz'], app(TldPriceBook::class)->catalogTlds());
        $this->assertSame(1010000, $rows['com']['register']);
        $this->assertSame(1111000, $rows['com']['renew']);
        $this->assertSame(2, $rows['com']['max_years']);
        $this->assertSame(303000, $rows['xyz']['register']);
        Http::assertNothingSent();
    }

    public function test_an_explicit_suffix_replaces_the_default_and_exclude_removes_it(): void
    {
        $narrow = ['operations' => ['renew'], 'period_months' => [12], 'privacy' => ['none'],
            'management' => ['nameservers', 'lock', 'contacts']];
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE,
            'tlds' => ['xyz' => $narrow], 'exclude' => ['org']]);
        $this->factory([$a->id => [$this->offer('com', 1000), $this->offer('xyz', 300), $this->offer('org', 900)]]);
        app(TldPriceBook::class)->refreshAll();
        // xyz: the owner's narrower statement (renew only) is never widened by the default.
        $this->assertSame(['com'], app(TldPriceBook::class)->catalogTlds());
        $this->assertSame($narrow, $a->fresh()->tldCapabilities('xyz'));
        $this->assertSame([], $a->fresh()->tldCapabilities('org'));
        $this->assertSame(DomainSupplierAccount::STANDARD_PROFILE, $a->fresh()->tldCapabilities('.COM'));
    }

    public function test_the_standard_profile_never_authorizes_transfer_or_premium(): void
    {
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        config(['domain_suppliers.transfer_account_id' => $a->id]);
        $this->factory([$a->id => [$this->offer('com', 1000), $this->offer('com', 800, 'transfer')]]);
        app(TldPriceBook::class)->refreshAll();
        $this->assertNull(app(TldPriceBook::class)->cachedFullForTlds(['com'])['com']['transfer']);
        $this->assertNotContains('transfer', DomainSupplierAccount::STANDARD_PROFILE['operations']);
        $this->assertFalse(DomainSupplierAccount::STANDARD_PROFILE['premium']);
        $this->assertFalse(DomainSupplierAccount::STANDARD_PROFILE['idn']);
    }

    public function test_capability_evidence_has_one_reader(): void
    {
        // A second hand-written reader of capabilities['tlds'] would ignore the default and the
        // exclude list, and that path would silently disagree with the price the site shows.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'DomainSupplierAccount.php') continue;
            if (preg_match("/capabilities\\[\\s*'tlds'\\s*\\]/", file_get_contents($file->getPathname()))) $offenders[] = $file->getFilename();
        }
        $this->assertSame([], $offenders);
    }

    public function test_health_window_survives_a_missed_probe_run(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertMatchesRegularExpression("/'domains:suppliers-probe'\\)->everyFiveMinutes\\(\\)/", $schedule);
        // One interval was the old window: every account went stale for a moment each cycle.
        $this->assertGreaterThanOrEqual(3 * 300, (int) config('domain_suppliers.health_max_age_seconds'));
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE],
            ['health_checked_at' => now()->subMinutes(11)]);
        $this->assertTrue($a->saleEligible());
        $this->assertSame([$a->id], DomainSupplierAccount::eligibleForSales()->pluck('id')->all());
    }

    public function test_comparison_shows_every_account_even_before_it_may_sell(): void
    {
        config(['domain_suppliers.enabled' => false]);
        $caps = ['default' => DomainSupplierAccount::STANDARD_PROFILE];
        $joker = $this->account('joker', $caps, ['sales_enabled' => false]);
        $op = $this->account('openprovider', [], ['sales_enabled' => false, 'pricing_verified' => false]);
        $this->factory([$joker->id => [$this->offer('com', 1000), $this->offer('com', 1500, 'renew')],
            $op->id => [$this->offer('com', 1200), $this->offer('org', 900), $this->offer('ir', 1)]]);
        app(DomainSupplierCatalogue::class)->refresh();
        $cmp = app(DomainSupplierCatalogue::class)->comparison();

        $this->assertSame(['com', 'org'], array_keys($cmp['rows']), 'unsold suffixes stay out, even for the owner');
        $com = $cmp['rows']['com'];
        $this->assertSame($joker->id, $com['winner']);
        $this->assertSame(1000000, $com['offers'][$joker->id]['wholesale_toman']);
        $this->assertSame(1200000, $com['offers'][$op->id]['wholesale_toman']);
        $this->assertSame(200000, $com['spread_toman']);
        $this->assertSame('10.00 USD', $com['offers'][$joker->id]['amount']);
        $this->assertTrue($com['offers'][$joker->id]['covered']);
        $this->assertFalse($com['offers'][$op->id]['covered'], 'no profile means it would not compete');
        $this->assertNull($cmp['rows']['org']['spread_toman']);
        $this->assertSame(1, $cmp['summary']['compared']);
        $this->assertSame([$joker->id => 1, $op->id => 0], $cmp['summary']['wins']);
        $this->assertSame([$joker->id => 0, $op->id => 1], $cmp['summary']['only']);
        $this->assertTrue($cmp['accounts'][$joker->id]['current']);
        // Owner visibility is not public sale: nothing here is eligible yet.
        $this->assertSame([], app(DomainSupplierCatalogue::class)->prices());

        $joker->update(['capabilities' => []]);
        $this->assertFalse(app(DomainSupplierCatalogue::class)->comparison()['accounts'][$joker->id]['current']);
        Http::assertNothingSent();
    }

    public function test_comparison_is_null_until_a_catalogue_exists(): void
    {
        $this->assertNull(app(DomainSupplierCatalogue::class)->comparison());
    }

    public function test_admin_page_renders_the_comparison_and_the_refresh_button(): void
    {
        $joker = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        $op = $this->account('openprovider', []);
        $this->factory([$joker->id => [$this->offer('com', 1000)], $op->id => [$this->offer('com', 1200)]]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/domain-suppliers')->assertOk()
            ->assertSee(__('domain-suppliers.comparison_empty'))
            ->assertSee('/admin/domain-suppliers/catalogue/refresh', false);

        $this->actingAs($admin)->from('/admin/domain-suppliers')->post('/admin/domain-suppliers/catalogue/refresh')
            ->assertRedirect('/admin/domain-suppliers')->assertSessionHas('ok');
        $page = $this->actingAs($admin)->get('/admin/domain-suppliers')->assertOk();
        $page->assertSee('.com')->assertSee('1,000,000')->assertSee('1,200,000')->assertSee('200,000')
            ->assertSee(__('domain-suppliers.not_covered'))->assertSee('id="supplier-comparison"', false);
        Http::assertNothingSent();
    }

    public function test_failed_refresh_keeps_the_previous_snapshot_and_says_so(): void
    {
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        $this->factory([$a->id => [$this->offer('com', 1000)]]);
        app(DomainSupplierCatalogue::class)->refresh();
        $before = Storage::disk('local')->get(DomainSupplierCatalogue::PATH);

        $this->factory([$a->id => false]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/admin/domain-suppliers')->post('/admin/domain-suppliers/catalogue/refresh')
            ->assertRedirect('/admin/domain-suppliers')->assertSessionHas('err');
        $this->assertSame($before, Storage::disk('local')->get(DomainSupplierCatalogue::PATH));
    }

    public function test_refresh_purges_the_page_cache_so_new_prices_show_at_once(): void
    {
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        $this->factory([$a->id => [$this->offer('com', 1000)]]);
        \Illuminate\Support\Facades\Cache::put('page:gen', 7);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/admin/domain-suppliers')->post('/admin/domain-suppliers/catalogue/refresh');
        $this->assertSame(8, (int) \Illuminate\Support\Facades\Cache::get('page:gen'));
    }

    public function test_with_the_flag_off_the_button_also_refreshes_the_legacy_book_for_rollback(): void
    {
        // While the flag is on nothing refreshes the OpenProvider book; after a day it is stale,
        // so turning the flag back off would leave the site without prices until fx:dollar.
        config(['domain_suppliers.enabled' => false]);
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        $this->factory([$a->id => [$this->offer('com', 1000)]]);
        $book = \Mockery::mock(TldPriceBook::class);
        $book->shouldReceive('refreshAll')->once()->andReturn(['count' => 412, 'total' => 450, 'excluded' => 38,
            'fetched_at' => now()->toIso8601String()]);
        $this->app->instance(TldPriceBook::class, $book);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/admin/domain-suppliers')->post('/admin/domain-suppliers/catalogue/refresh')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, '412'))->assertSessionMissing('err');
        $this->assertTrue(Storage::disk('local')->exists(DomainSupplierCatalogue::PATH));
    }

    public function test_with_the_flag_on_the_legacy_book_is_left_alone(): void
    {
        $a = $this->account('joker', ['default' => DomainSupplierAccount::STANDARD_PROFILE]);
        $this->factory([$a->id => [$this->offer('com', 1000)]]);
        $book = \Mockery::mock(TldPriceBook::class);
        $book->shouldNotReceive('refreshAll');
        $this->app->instance(TldPriceBook::class, $book);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/admin/domain-suppliers')->post('/admin/domain-suppliers/catalogue/refresh')
            ->assertSessionHas('ok');
    }

    public function test_refresh_is_admin_only(): void
    {
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author)->post('/admin/domain-suppliers/catalogue/refresh')->assertForbidden();
        $this->assertFalse(Storage::disk('local')->exists(DomainSupplierCatalogue::PATH));
    }

    public function test_standard_profile_checkbox_writes_keeps_and_removes_only_its_own_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->account('joker', []);
        $form = ['provider' => 'joker', 'environment' => 'production', 'label' => 'Joker synthetic', 'active' => 1,
            'contract_verified' => 1, 'identity_verified' => 1, 'pricing_verified' => 1];

        $this->actingAs($admin)->post('/admin/domain-suppliers/'.$a->id, $form + ['capabilities' => '',
            'standard_profile' => 1])->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertTrue($a->usesStandardProfile());
        // A capability edit is a change: sales stop and a fresh probe is required.
        $this->assertFalse($a->sales_enabled);
        $this->assertNull($a->health_checked_at);

        // Unticked with its own default still in the JSON: the checkbox removes what it wrote.
        $this->actingAs($admin)->post('/admin/domain-suppliers/'.$a->id, $form + ['capabilities' => json_encode($a->capabilities)])
            ->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('default', (array) $a->fresh()->capabilities);

        // A hand-written default is the owner's, not the checkbox's.
        $custom = ['default' => ['operations' => ['create'], 'period_months' => [12], 'privacy' => ['none'],
            'management' => ['nameservers', 'lock', 'contacts']], 'exclude' => [' .US ', 'co.uk', 'us']];
        $this->actingAs($admin)->post('/admin/domain-suppliers/'.$a->id, $form + ['capabilities' => json_encode($custom)])
            ->assertSessionHasNoErrors();
        $this->assertSame($custom['default'], $a->fresh()->capabilities['default']);
        $this->assertSame(['co.uk', 'us'], $a->fresh()->capabilities['exclude']);
        $this->assertSame([], $a->fresh()->tldCapabilities('us'));

        foreach ([['default' => ['create']], ['exclude' => 'us'], ['exclude' => ['bad tld']]] as $bad) {
            $this->actingAs($admin)->post('/admin/domain-suppliers/'.$a->id, $form + ['capabilities' => json_encode($bad)])
                ->assertSessionHasErrors('capabilities');
        }
        $this->assertSame($custom['default'], $a->fresh()->capabilities['default']);
    }

    public function test_standard_profile_survives_a_reordered_json_column(): void
    {
        $reordered = array_reverse(DomainSupplierAccount::STANDARD_PROFILE, true);
        $a = $this->account('joker', ['default' => $reordered]);
        $this->assertTrue($a->fresh()->usesStandardProfile());
    }

    public function test_failed_probe_message_reaches_the_page(): void
    {
        $a = $this->account('joker', []);
        $probe = \Mockery::mock(DomainSupplierProbe::class);
        $probe->shouldReceive('check')->once()->andReturn(false);
        $this->app->instance(DomainSupplierProbe::class, $probe);
        $admin = User::factory()->create(['role' => 'admin']);
        // The layout prints `err`; the old `error` key made the failure vanish without a word.
        $this->actingAs($admin)->from('/admin/domain-suppliers')->post('/admin/domain-suppliers/'.$a->id.'/probe')
            ->assertSessionHas('err', __('domain-suppliers.probe_failed'));
        $this->actingAs($admin)->get('/admin/domain-suppliers')->assertSee(__('domain-suppliers.probe_failed'));
    }
}
