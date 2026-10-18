<?php
declare(strict_types=1);

/**
 * Private, CLI-only acceptance of an already installed cloud-store release.
 *
 * Copy beside its installed manifest in an owner-controlled 0700 release dir:
 * php verify-cloud-store-release-20261018.php --manifest=/private/manifest.json
 * Optional: --home=/home/servernetcloud --report=verify-cli-2.json
 *
 * No HTTP kernel, purchase success path, identity edit, quote refresh, provider
 * lifecycle call, migration or queue/scheduler execution is permitted here.
 * Database enforcement is READ ONLY, rather than relying on a later rollback.
 * The report contains aggregate counts/booleans; rendered customer HTML stays in
 * memory. It does not prove a paid transaction, VM delivery or CSRF middleware.
 */

final class CloudStoreProbeFailure extends RuntimeException
{
    public function __construct(public readonly string $check, public readonly array $context = [])
    {
        parent::__construct($check);
    }
}

function probeCheck(bool $condition, string $check, array $context = []): void
{
    if (! $condition) {
        throw new CloudStoreProbeFailure($check, $context);
    }
}

function probeWithin(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, $root.'/');
}

function probeJson(string $path, array $value): void
{
    $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    $handle = fopen($path, 'x');
    probeCheck($handle !== false, 'report_create_failed');
    try {
        probeCheck(fwrite($handle, $bytes) === strlen($bytes), 'report_write_failed');
    } finally {
        fclose($handle);
    }
    chmod($path, 0600);
}

ini_set('display_errors', '0');
umask(0077);
$stage = 'private_context';
$reportPath = null;
$connection = null;
$result = ['utc' => gmdate('c'), 'scope' => 'installed_code_and_read_only_production_acceptance',
    'paid_transaction_tested' => false, 'vm_creation_tested' => false, 'csrf_middleware_tested' => false];
$effects = ['http_attempts' => 0, 'notifier_attempts' => 0, 'sms_attempts' => 0, 'sql_write_attempts' => 0];

try {
    probeCheck(PHP_SAPI === 'cli' && DIRECTORY_SEPARATOR === '/' && function_exists('posix_geteuid'), 'cli_linux_required');
    $options = getopt('', ['manifest:', 'home:', 'report:']);
    probeCheck(is_array($options) && isset($options['manifest']) && is_string($options['manifest']), 'manifest_argument_required');
    $home = realpath((string) ($options['home'] ?? '/home/servernetcloud'));
    $here = realpath(__DIR__);
    $manifestPath = realpath($options['manifest']);
    probeCheck(is_string($home) && is_string($here) && is_string($manifestPath), 'private_paths_missing');
    probeCheck($home === '/home/servernetcloud' && probeWithin($here, $home) && $here !== $home
        && dirname($manifestPath) === $here && ! is_link(__DIR__) && ! is_link($options['manifest'])
        && ! probeWithin($here, $home.'/public_html') && ! probeWithin($here, $home.'/servernet_app/public')
        && (fileperms($here) & 0777) === 0700 && fileowner($here) === posix_geteuid(), 'unsafe_private_directory');
    $appPath = realpath($home.'/servernet_app');
    probeCheck($appPath === $home.'/servernet_app' && realpath(getcwd()) === $appPath, 'wrong_application_cwd');
    $reportName = $options['report'] ?? 'verify-cli.json';
    probeCheck(is_string($reportName) && preg_match('/\Averify-cli(?:-[A-Za-z0-9-]+)?\.json\z/D', $reportName) === 1,
        'unsafe_report_name');
    $reportPath = $here.'/'.$reportName;
    probeCheck(! file_exists($reportPath) && ! is_link($reportPath), 'report_already_exists_inspect_instead');

    $stage = 'installed_manifest';
    $manifest = json_decode(file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    $entries = $manifest['files'] ?? $manifest;
    probeCheck(is_array($entries) && $entries !== [], 'empty_manifest');
    $installed = [];
    foreach ($entries as $key => $entry) {
        probeCheck(is_array($entry), 'invalid_manifest_entry');
        $relative = $entry['path'] ?? (is_string($key) ? $key : null);
        $after = $entry['after'] ?? null;
        probeCheck(is_string($relative) && preg_match('~\A(?:servernet_app|public_html)/[A-Za-z0-9_./-]+\z~D', $relative) === 1
            && ! str_contains($relative, '..') && is_string($after) && preg_match('/\A[a-f0-9]{64}\z/D', $after) === 1,
            'unsafe_manifest_path_or_hash');
        probeCheck(! isset($installed[$relative]), 'duplicate_manifest_path');
        $target = $home.'/'.$relative;
        probeCheck(is_file($target) && ! is_link($target) && realpath($target) === $target
            && hash_equals($after, (string) hash_file('sha256', $target)), 'installed_manifest_hash_mismatch');
        $installed[$relative] = $after;
    }
    foreach (['app/Services/Customer/IranSalesGate.php', 'app/Services/Customer/IranSalesVisibility.php',
        'app/Services/Customer/IranPurchaseReadiness.php', 'app/Http/Controllers/Account/CloudStoreController.php',
        'resources/views/account/cloud-store.blade.php', 'public/assets/js/cloud-store-experience.js',
        'public/assets/js/cloud-store-globe.js'] as $required) {
        probeCheck(isset($installed['servernet_app/'.$required]), 'required_release_file_missing_from_manifest');
    }
    $result['installed_files_verified'] = count($installed);
    $result['manifest_sha256'] = hash_file('sha256', $manifestPath);

    require $appPath.'/vendor/autoload.php';
    $app = require $appPath.'/bootstrap/app.php';
    $stage = 'protected_bootstrap';
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        function ($app) use ($here, &$connection, &$effects): void {
            $connection = Illuminate\Support\Facades\DB::connection();
            probeCheck(in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
                && $connection->transactionLevel() === 0, 'live_mariadb_connection_required');
            // This changes this short-lived CLI connection only, never global DB state.
            $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
            $connection->beginTransaction();
            $connection->beforeExecuting(function (string $sql) use (&$effects): void {
                if (preg_match('/\A\s*(?:select|show|describe|explain)\b/i', $sql) !== 1) {
                    $effects['sql_write_attempts']++;
                    throw new CloudStoreProbeFailure('unexpected_sql_write');
                }
            });
            $readOnly = $connection->selectOne('SELECT @@tx_read_only AS probe_read_only');
            probeCheck((int) $readOnly->probe_read_only === 1, 'database_read_only_not_enforced');

            // Preserve the same cached FX evidence the web process currently sees.
            // Everything constructed after this point uses a private array store.
            $liveCache = Illuminate\Support\Facades\Cache::store();
            $cached = [];
            foreach (['fx.navasan.rates', 'fx.usd_irt', 'fx.eur_irt', 'fx.usdt_irt', 'settings.all',
                'site.menu.mega', 'site.menu.overrides', 'cloud.countries.served'] as $key) {
                $cached[$key] = $liveCache->get($key);
            }
            config(['cache.default' => 'array', 'cache.limiter' => 'array', 'session.driver' => 'array',
                'mail.default' => 'array', 'logging.default' => 'cloud_store_probe',
                'logging.channels.cloud_store_probe' => ['driver' => 'single', 'path' => $here.'/private.log'],
                'view.compiled' => $here.'/blade-cache']);
            probeCheck(! is_link($here.'/blade-cache'), 'unsafe_blade_cache');
            if (! is_dir($here.'/blade-cache')) {
                probeCheck(mkdir($here.'/blade-cache', 0700), 'private_blade_cache_create_failed');
            }
            Illuminate\Support\Facades\Cache::setDefaultDriver('array');
            foreach ($cached as $key => $value) {
                if ($value !== null) Illuminate\Support\Facades\Cache::store('array')->forever($key, $value);
            }
            $app->instance(Illuminate\Cache\RateLimiter::class,
                new Illuminate\Cache\RateLimiter(Illuminate\Support\Facades\Cache::store('array')));
            App\Support\ErrorTracker::useDirectory($here.'/tracker');
            Illuminate\Support\Facades\Http::preventStrayRequests();
            Illuminate\Support\Facades\Http::fake(function () use (&$effects): never {
                $effects['http_attempts']++;
                throw new CloudStoreProbeFailure('unexpected_external_http');
            });
            Illuminate\Support\Facades\Mail::fake();
            $app->instance(App\Services\Sms\SmsSender::class, new class($effects) implements App\Services\Sms\SmsSender {
                private array $effects;
                public function __construct(array &$effects) { $this->effects = &$effects; }
                public function enabled(): bool { return true; }
                public function name(): string { return 'private-acceptance'; }
                public function send(string $mobile, string $text): bool { return $this->blocked(); }
                public function sendOtp(string $mobile, string $code): bool { return $this->blocked(); }
                private function blocked(): never {
                    $this->effects['sms_attempts']++;
                    throw new CloudStoreProbeFailure('unexpected_sms');
                }
            });
            $app->instance(App\Services\Notify\Notifier::class, new class($effects) extends App\Services\Notify\Notifier {
                private array $effects;
                public function __construct(array &$effects) { $this->effects = &$effects; }
                public function fire(string $key, ?App\Models\Customer $customer, array $vars, string $text,
                    array $adminRows = [], ?string $url = null, string $emoji = '🔔', array $buttons = []): void {
                    $this->effects['notifier_attempts']++;
                    throw new CloudStoreProbeFailure('unexpected_notification');
                }
            });
        });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    probeCheck($connection !== null && $connection->transactionLevel() === 1, 'protected_bootstrap_incomplete');
    $result['database_read_only_enforced'] = true;

    $stage = 'schema_and_baseline';
    $requiredColumns = [
        'identity_verifications' => ['customer_id', 'status', 'shahkar_matched'],
        'services' => ['customer_id', 'provision_status', 'provisioned_at', 'provision_meta', 'cloud_plan_id'],
        'cloud_instances' => ['provider_ref', 'status'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        probeCheck(Illuminate\Support\Facades\Schema::hasColumns($table, $columns), 'required_schema_missing');
    }
    $fingerprint = function () use ($connection): array {
        $counts = [];
        foreach (['services', 'invoices', 'invoice_items', 'payments', 'cloud_instances', 'cloud_ssh_keys', 'credit_ledger',
            'identity_verifications', 'customer_profiles'] as $table) {
            $counts[$table] = $connection->table($table)->count();
        }
        $financial = [];
        foreach (['credit_ledger' => ['amount', 'balance_after'], 'invoices' => ['subtotal', 'total', 'paid'],
            'payments' => ['amount'], 'services' => ['price']] as $table => $columns) {
            foreach ($columns as $column) $financial[$table.'.'.$column] = (string) $connection->table($table)->sum($column);
        }
        return ['counts' => $counts, 'financial_sha256' => hash('sha256', json_encode($financial, JSON_THROW_ON_ERROR))];
    };
    $before = $fingerprint();
    $arvanFlags = $connection->table('settings')->whereIn('key', ['arvan_console_worker_enabled',
        'arvan_hourly_sales_enabled', 'arvan_windows_ready_image_refs'])->pluck('value', 'key');
    $windowsAllowlist = (string) ($arvanFlags['arvan_windows_ready_image_refs'] ?? '');
    $result['preserved_arvan_baseline'] = [
        'public_console' => $arvanFlags['arvan_console_worker_enabled'] ?? null,
        'hourly_sales' => $arvanFlags['arvan_hourly_sales_enabled'] ?? null,
        'windows_allowlist_nonempty' => trim($windowsAllowlist) !== '',
        'windows_allowlist_sha256' => hash('sha256', $windowsAllowlist),
        'windows_allowlist_matches_previous_baseline' => hash('sha256', $windowsAllowlist)
            === 'cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845',
    ];
    $session = $app['session']->driver('array');
    probeCheck($session->getHandler() instanceof Illuminate\Session\ArraySessionHandler, 'array_session_not_enforced');
    $session->start();
    $app->instance('session.store', $session);
    $app['view']->share('errors', new Illuminate\Support\ViewErrorBag());
    $requestFor = function (?App\Models\Customer $customer, string $locale, string $method = 'GET', array $inputs = []) use ($app, $session): Illuminate\Http\Request {
        $session->flush();
        $session->regenerateToken();
        $app->setLocale($locale);
        $path = ($locale === 'fa' ? '' : '/'.$locale).'/account/cloud-store';
        $request = Illuminate\Http\Request::create('https://console.servernet.cloud'.$path, $method, $inputs,
            server: ['HTTP_REFERER' => 'https://console.servernet.cloud'.$path]);
        $request->setLaravelSession($session);
        $app->instance('request', $request);
        Illuminate\Support\Facades\Request::clearResolvedInstance('request');
        $app['url']->setRequest($request);
        $route = $app['router']->getRoutes()->getByName(($locale === 'fa' ? '' : $locale.'.')
            .'account.cloud.store'.($method === 'POST' ? '.place' : ''));
        probeCheck($route !== null, 'cloud_store_route_missing');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $guard = Illuminate\Support\Facades\Auth::guard('customer');
        $guard->forgetUser();
        if ($customer !== null) $guard->setUser($customer);
        return $request;
    };

    $stage = 'real_identity_cohorts';
    $matched = fn ($query) => $query->where('status', 'verified')->where('shahkar_matched', true);
    $cohortQueries = [
        'shahkar' => App\Models\Customer::where('status', 'active')->whereHas('identityVerification', $matched),
        'manual_profile_only' => App\Models\Customer::where('status', 'active')->whereHas('profiles', fn ($q) => $q->where('status', 'verified'))
            ->whereDoesntHave('identityVerification', $matched),
        'unverified' => App\Models\Customer::where('status', 'active')->whereDoesntHave('identityVerification', $matched),
    ];
    $customers = ['guest' => null];
    foreach ($cohortQueries as $name => $query) {
        $result['identity_cohort_counts'][$name] = (clone $query)->count();
        $customers[$name] = (clone $query)->orderBy('id')->first();
    }
    probeCheck($customers['unverified'] !== null, 'real_non_shahkar_cohort_unavailable');
    $result['unavailable_cohorts'] = array_values(array_keys(array_filter($customers,
        fn ($customer, $name) => $name !== 'guest' && $customer === null, ARRAY_FILTER_USE_BOTH)));
    $controller = app(App\Http\Controllers\Account\CloudStoreController::class);
    $isIranLocation = fn ($location) => App\Services\Customer\IranSalesGate::isIranTarget((string) $location->country)
        || App\Services\Customer\IranSalesGate::isIranTarget((string) $location->code);
    $rawIranCodes = App\Models\CloudLocation::where('is_active', true)->get()->filter($isIranLocation)->pluck('code')->all();
    $disabled = App\Models\CloudPlan::disabledProviders();
    $expectedIranShelfCodes = App\Models\CloudPlan::where('is_active', true)->where('admin_disabled', false)
        ->when($disabled !== [], fn ($q) => $q->whereNotIn('provider', $disabled))
        ->whereIn('location_code', $rawIranCodes)->distinct()->pluck('location_code')->all();
    $expectedIranOpenCodes = App\Models\CloudPlan::query()->sellable()->whereIn('location_code', $rawIranCodes)
        ->distinct()->pluck('location_code')->all();
    $result['active_iran_location_count'] = count($rawIranCodes);
    $result['cohort_menu_checks'] = 0;
    foreach (['fa', 'en', 'tr'] as $locale) {
        // Guest -> approved -> manual -> unverified -> guest reuses the warm raw cache.
        foreach (['guest', 'shahkar', 'manual_profile_only', 'unverified', 'guest'] as $name) {
            $customer = $customers[$name];
            if ($name !== 'guest' && $customer === null) continue;
            $requestFor($customer, $locale);
            $blocked = $name !== 'shahkar';
            probeCheck(App\Services\Customer\IranSalesGate::blocksIranFor($customer) === $blocked, 'identity_gate_mismatch');
            probeCheck(App\Services\Customer\IranSalesVisibility::restricted() === $blocked, 'request_visibility_mismatch');
            probeCheck(App\Services\Customer\IranSalesVisibility::allowsCountry('IR') === ! $blocked
                && App\Services\Customer\IranSalesVisibility::allowsCountry('DE'), 'country_visibility_mismatch');
            $raw = app(App\Services\SiteMenu::class)->mega();
            $rawHash = hash('sha256', serialize($raw));
            $menu = app(App\Services\MenuManager::class)->mega($raw);
            probeCheck(hash('sha256', serialize($raw)) === $rawHash, 'raw_menu_mutated');
            foreach ($menu as $scope => $section) foreach ($section['groups'] ?? [] as $group) foreach ($group['items'] ?? [] as $item) {
                probeCheck(App\Services\Customer\IranSalesVisibility::allowsItem($item, (string) $scope), 'restricted_menu_item_leaked');
            }
            $servers = app(App\Services\ServerMenu::class)->build($menu['vps'] ?? []);
            if ($blocked) {
                probeCheck(! isset($servers['cloud']['IR']) && ! isset($servers['dedicated']['IR']), 'iran_server_menu_leaked');
            }
            foreach ([$controller->locationGroups(), $controller->shelfLocationGroups()] as $groupIndex => $groups) {
                $codes = [];
                foreach ($groups as $group) foreach ($group['locations'] as $location) {
                    probeCheck(! $blocked || ! $isIranLocation($location), 'iran_store_location_leaked');
                    $codes[] = (string) $location->code;
                }
                if (! $blocked) {
                    $expected = $groupIndex === 0 ? $expectedIranOpenCodes : $expectedIranShelfCodes;
                    probeCheck(array_diff($expected, $codes) === [], 'verified_iran_locations_missing');
                    foreach ($expected as $code) {
                        probeCheck(! App\Services\Customer\IranSalesGate::blocks($customer, $code), 'verified_iran_access_blocked');
                    }
                }
            }
            $result['cohort_menu_checks']++;
        }
    }

    $stage = 'forged_iran_order_rejection';
    $result['forged_iran_orders_rejected'] = 0;
    $irCode = collect($rawIranCodes)->first(fn ($code) => App\Services\Customer\IranSalesGate::isIranTarget((string) $code))
        ?? 'ir-private-acceptance';
    $irOffer = App\Models\CloudPlan::offers($irCode)->first();
    $irImage = $irOffer ? array_merge($controller::imageKeysFor($irOffer, 'os'), $controller::imageKeysFor($irOffer, 'app'))[0] ?? null : null;
    foreach (['unverified', 'manual_profile_only'] as $name) {
        if ($customers[$name] === null) continue;
        foreach (['cycle', 'hourly'] as $mode) {
            $request = $requestFor($customers[$name], 'fa', 'POST', [
                'location' => $irCode, 'plan' => $irOffer?->slug ?? 'private-acceptance-plan',
                'image' => $irImage ?? 'private-acceptance-image', 'cycle' => 'monthly',
                'billing_mode' => $mode, 'extra_ipv4' => 0,
            ]);
            $response = $controller->order($request);
            $errors = $session->get('errors');
            probeCheck($response->isRedirect() && $errors instanceof Illuminate\Support\ViewErrorBag
                && $errors->getBag('default')->keys() === ['location']
                && $errors->getBag('default')->first('location') === App\Services\Customer\IranSalesGate::message(),
                'forged_iran_not_rejected_by_exact_gate');
            $result['forged_iran_orders_rejected']++;
        }
    }
    $result['forged_iran_used_live_offer'] = $irOffer !== null && $irImage !== null;

    $stage = 'live_stored_quotes';
    $requestFor($customers['unverified'], 'fa');
    $fx = app(App\Services\ExchangeRate::class);
    foreach (App\Services\ExchangeRate::SOURCE_ITEMS as $currency => $item) {
        $rate = $fx->current($currency);
        probeCheck(is_array($rate) && ($rate['source'] ?? null) === 'navasan.net'
            && ($rate['source_item'] ?? null) === $item && (int) ($rate['rate_toman'] ?? 0) > 0, 'stored_fx_not_current');
        $result['stored_fx'][$currency] = ['source' => 'navasan.net', 'source_item' => $item,
            'source_at' => $rate['source_at'] ?? null, 'fetched_at' => $rate['at'] ?? null];
    }
    probeCheck(app(App\Services\Cloud\CloudPricingSnapshot::class)->current(), 'cloud_quote_snapshot_not_current');
    $eurRate = app(App\Services\Cloud\CloudPricing::class)->eurToToman();
    $result['quote_checks'] = ['locations' => 0, 'public_slugs' => 0, 'cycle_entries' => 0, 'hourly_entries' => 0,
        'image_keys' => 0, 'duplicate_public_slugs' => 0, 'rendered_pages' => 0];

    $checkView = function (Illuminate\View\View $view, bool $render) use ($controller, $eurRate, &$result): void {
        $data = $view->getData();
        $code = (string) ($data['locCode'] ?? '');
        $offers = App\Models\CloudPlan::offers($code);
        $slugs = array_column($data['planCards'], 'slug');
        probeCheck(count($slugs) === count(array_unique($slugs)), 'duplicate_public_slug_rendered');
        $result['quote_checks']['locations']++;
        foreach ($slugs as $slug) {
            $offer = $offers->get($slug);
            probeCheck($offer !== null && (string) $offer->location_code === $code, 'rendered_offer_not_orderable_at_location');
            $result['quote_checks']['public_slugs']++;
            foreach ($data['priceMap'][$slug] ?? [] as $cycle => $quote) {
                $price = $controller::priceForCycle($offer, $cycle);
                $setup = $offer->setupIrt();
                $first = $price + $setup + (int) round(($price + $setup) * $data['taxPct'] / 100);
                probeCheck($price > 0 && (int) $quote['cycle'] === $price && (int) $quote['setup'] === $setup
                    && (int) $quote['per'] === $controller::monthlyEquivalent($offer, $cycle)
                    && (int) $quote['first'] === $first, 'displayed_cycle_quote_mismatch');
                $months = App\Models\Service::monthsIn($cycle);
                $cost = (int) $offer->cost_eur_cents / 100 * $eurRate * $months;
                // Public product identity and procurement arithmetic only. This
                // diagnostic is written to the private report, never stdout.
                $pricingContext = ['public_slug' => (string) $slug, 'location_code' => $code,
                    'provider' => (string) $offer->provider, 'cycle' => (string) $cycle, 'months' => $months,
                    'configured_cycle_discount_pct' => (int) config('billing.cycles.'.$cycle.'.discount_pct', 0),
                    'effective_cycle_discount_pct' => (int) $quote['save'],
                    'configured_margin_pct' => app(App\Services\Cloud\CloudPricing::class)->marginPct($offer->provider),
                    'stored_monthly_price_irt' => (int) $offer->price_irt, 'computed_cycle_price_irt' => $price,
                    'stored_monthly_cost_eur_cents' => (int) $offer->cost_eur_cents,
                    'current_eur_toman' => $eurRate, 'stored_supplier_cycle_cost_irt' => $cost,
                    'computed_cycle_shortfall_irt' => max(0, $cost - $price)];
                probeCheck($price + 1 >= $cost, 'cycle_quote_below_stored_supplier_cost', $pricingContext);
                if (in_array($offer->provider, ['salad', 'lightnode'], true)) {
                    probeCheck((int) $quote['save'] === 0, 'unsupported_provider_cycle_discount');
                    $hours = $offer->provider === 'lightnode'
                        ? App\Services\Cloud\LightNodePublicPricing::CALENDAR_MONTH_RESERVE_HOURS
                        : App\Services\Cloud\SaladClient::HOURS_PER_MONTH;
                    $floor = $offer->hourlyCostFloorEurMicro() / 1000000 * $eurRate * $hours * $months;
                    probeCheck($price + 1 >= $floor, 'cycle_quote_below_hourly_reserve_floor', $pricingContext + [
                        'reserve_hours_per_month' => $hours, 'hourly_reserve_floor_irt' => $floor,
                        'hourly_margin_floor_eur_micro' => $offer->hourlyCostFloorEurMicro()]);
                }
                $result['quote_checks']['cycle_entries']++;
            }
            $hourly = $data['hourlyMap'][$slug] ?? [];
            $hourlyOffer = App\Models\CloudPlan::offers($code, true)->get($slug);
            $expectedRate = $hourlyOffer?->hourlyIrt() ?? 0;
            probeCheck((int) ($hourly['rate'] ?? -1) === $expectedRate, 'displayed_hourly_quote_mismatch');
            if ($hourlyOffer !== null) {
                $floor = $hourlyOffer->hourlyCostEurMicro() / 1000000 * $eurRate;
                probeCheck($expectedRate > 0 && $expectedRate + 1 >= $floor, 'hourly_quote_below_stored_supplier_cost', [
                    'public_slug' => (string) $slug, 'location_code' => $code, 'provider' => (string) $hourlyOffer->provider,
                    'computed_hourly_price_irt' => $expectedRate, 'stored_hourly_cost_eur_micro' => $hourlyOffer->hourlyCostEurMicro(),
                    'current_eur_toman' => $eurRate, 'stored_supplier_hourly_cost_irt' => $floor]);
                probeCheck((int) $hourly['min'] === $hourlyOffer->hourlyStartMinIrt() + (int) $hourly['existing'],
                    'displayed_hourly_start_minimum_mismatch');
                $result['quote_checks']['hourly_entries']++;
            }
            foreach (['os', 'app'] as $kind) {
                $allowed = $controller::imageKeysFor($offer, $kind);
                $shown = $data['imageMap'][$slug][$kind] ?? [];
                probeCheck(array_diff($shown, $allowed) === [] && array_diff($allowed, $shown) === [], 'image_compatibility_map_mismatch');
                $result['quote_checks']['image_keys'] += count($shown);
            }
        }
        if (! $render || $slugs === []) return;
        $html = $view->render();
        probeCheck(preg_match('/\bvar D = (\{[^\r\n]*\});/', $html, $match) === 1, 'rendered_inline_data_missing');
        $inline = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
        probeCheck($inline['prices'] === $data['priceMap'] && $inline['images'] === $data['imageMap']
            && $inline['hourly'] === $data['hourlyMap'], 'rendered_inline_quote_or_image_map_mismatch');
        $dom = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        try { probeCheck($dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'rendered_dom_invalid'); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
        $xpath = new DOMXPath($dom);
        probeCheck($xpath->query('//form[@id="cvb-form"]//input[@name="label"]')->length === 1, 'server_name_input_not_unique');
        probeCheck($xpath->query('//form[@id="cvb-form"]//*[@data-step="5"]')->length === 0, 'obsolete_name_stage_present');
        $token = $xpath->query('//form[@id="cvb-form"]//input[@name="_token"]')->item(0);
        probeCheck($token instanceof DOMElement && strlen($token->getAttribute('value')) === 40, 'csrf_form_field_missing');
        $radioKeys = [];
        foreach ($xpath->query('//form[@id="cvb-form"]//input[@name="image"]') as $input) {
            $radioKeys[] = $input->getAttribute('value');
            if ($input->hasAttribute('checked')) {
                probeCheck(in_array($input->getAttribute('value'), array_merge($data['imageMap'][$data['selectedSlug']]['os'] ?? [],
                    $data['imageMap'][$data['selectedSlug']]['app'] ?? []), true), 'selected_image_not_compatible');
            }
        }
        $expectedKeys = $data['osCatalog']->concat($data['appCatalog'])->pluck('key')->all();
        probeCheck(array_diff($radioKeys, $expectedKeys) === [] && array_diff($expectedKeys, $radioKeys) === [], 'rendered_image_radios_mismatch');
        probeCheck(str_contains($html, 'cloud-store-experience.js') && str_contains($html, 'cloud-store-globe.js')
            && str_contains($html, 'cloud-image-cards.js'), 'required_ui_asset_missing');
        $result['quote_checks']['rendered_pages']++;
    };

    $allCodes = [];
    foreach ($controller->locationGroups() as $group) foreach ($group['locations'] as $location) $allCodes[] = (string) $location->code;
    probeCheck($allCodes !== [], 'no_live_foreign_locations');
    foreach (array_unique($allCodes) as $code) {
        $request = $requestFor($customers['unverified'], 'fa', inputs: ['location' => $code]);
        $checkView($controller->index($request), false);
    }
    // Render actual Blade in three languages, for real permitted cohorts. No HTML is retained.
    foreach (['unverified', 'manual_profile_only', 'shahkar'] as $name) {
        if ($customers[$name] === null) continue;
        foreach (['fa', 'en', 'tr'] as $locale) {
            $request = $requestFor($customers[$name], $locale, inputs: ['location' => $allCodes[0]]);
            $checkView($controller->index($request), true);
        }
    }
    if ($customers['shahkar'] !== null) {
        foreach ($rawIranCodes as $code) {
            if (App\Models\CloudPlan::offers($code)->isEmpty()) continue;
            $request = $requestFor($customers['shahkar'], 'fa', inputs: ['location' => $code]);
            $checkView($controller->index($request), true);
        }
    }
    probeCheck($result['quote_checks']['public_slugs'] > 0 && $result['quote_checks']['cycle_entries'] > 0
        && $result['quote_checks']['rendered_pages'] >= 3, 'insufficient_live_quote_coverage');

    $stage = 'unchanged_state_and_effects';
    $after = $fingerprint();
    probeCheck($before === $after, 'customer_or_financial_state_changed');
    $mail = Illuminate\Support\Facades\Mail::getFacadeRoot();
    foreach (['mailables', 'queuedMailables'] as $property) {
        probeCheck((new ReflectionProperty($mail, $property))->getValue($mail) === [], 'unexpected_mail_recorded');
    }
    probeCheck(array_sum($effects) === 0, 'unexpected_effect_attempted');
    $result['counts_before'] = $before['counts'];
    $result['counts_after'] = $after['counts'];
    $result['financial_sums_unchanged'] = true;
    $result['effects'] = $effects;
    $result['actual_orders_created'] = 0;
    $result['identity_rows_changed'] = 0;
    $result['status'] = $result['unavailable_cohorts'] === [] ? 'passed' : 'passed_with_unavailable_cohorts';
    $result['limitations'] = ['Controller probe bypasses HTTP middleware; validate authenticated routing, CSRF and private caching separately.',
        'No gateway initiation, real payment, provider live quote or VM delivery was executed.',
        'Cost floors use the current stored catalog and Navasan snapshot; provider freshness beyond stored quote guards is not independently fetched.',
        'Before/after state is measured in one consistent READ ONLY transaction; concurrent genuine customer orders are outside this probe.'];
    $connection->rollBack();
    probeJson($reportPath, $result);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    if ($connection !== null && $connection->transactionLevel() > 0) {
        try { $connection->rollBack(); } catch (Throwable) { /* Process close releases a read-only transaction. */ }
    }
    // Never print a raw exception, query bindings, customer attributes or HTML.
    $failure = ['utc' => gmdate('c'), 'status' => 'failed', 'stage' => $stage,
        'check' => $error instanceof CloudStoreProbeFailure ? $error->check : 'unexpected_exception',
        'exception_class' => $error::class, 'effects' => $effects, 'paid_transaction_tested' => false, 'vm_creation_tested' => false];
    $privateFailure = $failure;
    if ($error instanceof CloudStoreProbeFailure && $error->context !== []) {
        $privateFailure['diagnostic_context'] = $error->context;
        $failure['private_diagnostic_context_available'] = true;
    }
    if ($reportPath !== null && ! file_exists($reportPath) && ! is_link($reportPath)) {
        try { probeJson($reportPath, $privateFailure); } catch (Throwable) { /* Preserve failure without exposing diagnostics. */ }
    }
    echo json_encode($failure, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
