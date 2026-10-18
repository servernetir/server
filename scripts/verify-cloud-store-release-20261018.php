<?php
declare(strict_types=1);

/**
 * Private, CLI-only acceptance of an already installed cloud-store release.
 *
 * Copy beside its installed manifest in an owner-controlled 0700 release dir:
 * php verify-cloud-store-release-20261018.php --manifest=/private/manifest.json
 * Optional: --home=/home/servernetcloud --report=verify-cli-2.json --public-only
 * Public-only checks the follow-up public quotes without repeating the full
 * location/image render sweep; retain the preceding full acceptance separately.
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

/** CLI liveness only: fixed aggregate counters, never identities or prices. */
function probeProgress(string $stage, array $counts, float $startedAt): void
{
    $progress = ['probe' => 'cloud_store_acceptance', 'stage' => $stage,
        'elapsed_seconds' => (int) (microtime(true) - $startedAt)];
    foreach (['locations', 'target_foreign_locations', 'public_slugs', 'cycle_entries', 'hourly_entries', 'rendered_pages'] as $key) {
        $progress[$key] = (int) ($counts[$key] ?? 0);
    }
    fwrite(STDERR, json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    fflush(STDERR);
}

/** Restrict only this probe's compiled Blade tree; never traverse a link. */
function probePrivateBladePermissions(string $privateDir, int $uid): array
{
    $root = $privateDir.'/blade-cache';
    clearstatcache(true);
    probeCheck(realpath($privateDir) === $privateDir && ! is_link($privateDir)
        && fileowner($privateDir) === $uid && (fileperms($privateDir) & 0777) === 0700,
        'unsafe_private_blade_parent');
    $inspect = function (string $path) use ($root, $uid): array {
        clearstatcache(true, $path);
        $stat = lstat($path);
        probeCheck($stat !== false && probeWithin($path, $root) && ! is_link($path)
            && realpath($path) === $path && (int) $stat['uid'] === $uid, 'unsafe_private_blade_entry');
        $kind = $stat['mode'] & 0170000;
        // Hard-linked files could otherwise alter permissions outside this tree.
        probeCheck($kind === 0040000 || ($kind === 0100000 && (int) $stat['nlink'] === 1),
            'unsafe_private_blade_entry_type');
        return $stat;
    };
    $rootStat = $inspect($root);
    probeCheck(($rootStat['mode'] & 0170000) === 0040000, 'private_blade_cache_not_directory');
    $entries = [$root => $rootStat];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    // Validate the entire tree before changing any mode. FOLLOW_SYMLINKS is absent.
    foreach ($iterator as $entry) $entries[$entry->getPathname()] = $inspect($entry->getPathname());
    $counts = ['files' => 0, 'directories' => 0, 'files_restricted' => 0, 'directories_restricted' => 0];
    foreach ($entries as $path => $before) {
        $current = $inspect($path);
        probeCheck($current['dev'] === $before['dev'] && $current['ino'] === $before['ino']
            && ($current['mode'] & 0170000) === ($before['mode'] & 0170000), 'private_blade_entry_changed');
        $isDirectory = ($current['mode'] & 0170000) === 0040000;
        $mode = $isDirectory ? 0700 : 0600;
        $key = $isDirectory ? 'directories' : 'files';
        $counts[$key]++;
        if (($current['mode'] & 0777) !== $mode) $counts[$key.'_restricted']++;
        probeCheck(chmod($path, $mode), 'private_blade_permissions_failed');
        clearstatcache(true, $path);
        probeCheck((fileperms($path) & 0777) === $mode, 'private_blade_permissions_not_applied');
    }
    return $counts;
}

ini_set('display_errors', '0');
umask(0077);
$stage = 'private_context';
$reportPath = null;
$privateBladeCacheInitialized = false;
$connection = null;
$result = ['utc' => gmdate('c'), 'scope' => 'installed_code_and_read_only_production_acceptance',
    'paid_transaction_tested' => false, 'vm_creation_tested' => false, 'csrf_middleware_tested' => false];
$effects = ['http_attempts' => 0, 'notifier_attempts' => 0, 'sms_attempts' => 0, 'sql_write_attempts' => 0];

try {
    probeCheck(PHP_SAPI === 'cli' && DIRECTORY_SEPARATOR === '/' && function_exists('posix_geteuid'), 'cli_linux_required');
    $options = getopt('', ['manifest:', 'home:', 'report:', 'public-only']);
    probeCheck(is_array($options) && isset($options['manifest']) && is_string($options['manifest']), 'manifest_argument_required');
    $publicOnly = array_key_exists('public-only', $options);
    $result['acceptance_mode'] = $publicOnly ? 'public_only' : 'full_catalogue';
    $result['full_catalogue_render_sweep_executed'] = ! $publicOnly;
    if ($publicOnly) $result['scope'] = 'installed_code_and_read_only_public_quote_acceptance';
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
        'app/Services/Customer/IranPurchaseReadiness.php', 'app/Services/Cloud/CloudAddons.php',
        'app/Http/Controllers/Account/CloudStoreController.php',
        'resources/views/account/cloud-store.blade.php', 'public/assets/js/cloud-store-experience.js',
        'public/assets/js/cloud-store-globe.js'] as $required) {
        probeCheck(isset($installed['servernet_app/'.$required]), 'required_release_file_missing_from_manifest');
    }
    if ($publicOnly) {
        foreach (['app/Services/Cloud/CloudPublicQuote.php', 'app/Http/Controllers/SiteController.php',
            'app/Http/Controllers/CatalogController.php', 'resources/views/pages/home.blade.php'] as $required) {
            probeCheck(isset($installed['servernet_app/'.$required]), 'required_public_release_file_missing_from_manifest');
        }
    }
    $result['installed_files_verified'] = count($installed);
    $result['manifest_sha256'] = hash_file('sha256', $manifestPath);

    require $appPath.'/vendor/autoload.php';
    $app = require $appPath.'/bootstrap/app.php';
    $stage = 'protected_bootstrap';
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        function ($app) use ($here, &$connection, &$effects, &$privateBladeCacheInitialized): void {
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
            $privateBladeCacheInitialized = true;
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
    $probeStartedAt = microtime(true);
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
    $requestFor = function (?App\Models\Customer $customer, string $locale, string $method = 'GET', array $inputs = [],
        ?string $publicRoute = null, array $routeParameters = []) use ($app, $session): Illuminate\Http\Request {
        $session->flush();
        $session->regenerateToken();
        $app->setLocale($locale);
        $routeName = ($locale === 'fa' ? '' : $locale.'.').($publicRoute
            ?? 'account.cloud.store'.($method === 'POST' ? '.place' : ''));
        $path = $publicRoute === null ? ($locale === 'fa' ? '' : '/'.$locale).'/account/cloud-store'
            : route($routeName, $routeParameters, false);
        $host = $publicRoute === null ? 'console.servernet.cloud' : 'servernet.cloud';
        $request = Illuminate\Http\Request::create('https://'.$host.$path, $method, $inputs,
            server: ['HTTP_REFERER' => 'https://'.$host.$path]);
        $request->setLaravelSession($session);
        $app->instance('request', $request);
        Illuminate\Support\Facades\Request::clearResolvedInstance('request');
        $app['url']->setRequest($request);
        $route = $app['router']->getRoutes()->getByName($routeName);
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
    $addonSvc = app(App\Services\Cloud\CloudAddons::class);
    $expectedAddonTotals = [];
    $result['addon_quote_checks'] = ['cycles' => 0, 'quantities' => 0, 'available' => $addonSvc->extraIpMonthlyToman() > 0];
    foreach ($controller::cycles() as $cycle) {
        $result['addon_quote_checks']['cycles']++;
        foreach (range(0, App\Services\Cloud\CloudAddons::MAX_EXTRA_IP) as $count) {
            $selection = ['extra_ipv4' => $count];
            $total = $addonSvc->forCycle($selection, $cycle);
            $lines = $addonSvc->lines($selection, $cycle);
            $expectedAddonTotals[$cycle][$count] = $total;
            if ($count === 0 || ! $result['addon_quote_checks']['available']) {
                probeCheck($total === 0 && $lines === [], 'unavailable_or_empty_addon_quote_revived');
            } else {
                $floor = $addonSvc->extraIpCostCents() / 100 * $eurRate * App\Models\Service::monthsIn($cycle) * $count;
                probeCheck($total > 0 && $total + 1 >= $floor, 'addon_quote_below_stored_supplier_cost', [
                    'cycle' => $cycle, 'quantity' => $count, 'computed_addon_total_irt' => $total,
                    'stored_unit_cost_eur_cents' => $addonSvc->extraIpCostCents(),
                    'current_eur_toman' => $eurRate, 'stored_supplier_addon_cycle_cost_irt' => $floor]);
                probeCheck(count($lines) === 1 && $lines[0]['total'] === $total && $lines[0]['qty'] === $count,
                    'addon_invoice_line_total_mismatch');
            }
            $result['addon_quote_checks']['quantities']++;
        }
    }
    $result['quote_checks'] = ['locations' => 0, 'public_slugs' => 0, 'cycle_entries' => 0, 'hourly_entries' => 0,
        'image_keys' => 0, 'duplicate_public_slugs' => 0, 'rendered_pages' => 0];
    $hourlyOffersByLocation = [];
    $imageKeysByOffer = [];

    $checkView = function (Illuminate\View\View $view, bool $render) use ($controller, $eurRate, $expectedAddonTotals,
        $probeStartedAt, &$hourlyOffersByLocation, &$imageKeysByOffer, &$result): void {
        $data = $view->getData();
        probeCheck(($data['addonTotals'] ?? null) === $expectedAddonTotals, 'public_addon_total_map_mismatch');
        $code = (string) ($data['locCode'] ?? '');
        $offers = App\Models\CloudPlan::offers($code);
        // This entire probe holds one READ ONLY transaction. These catalogue
        // results cannot change between slugs, locales or identity cohorts.
        if (! array_key_exists($code, $hourlyOffersByLocation)) {
            $hourlyOffersByLocation[$code] = App\Models\CloudPlan::offers($code, true);
        }
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
            $hourlyOffer = $hourlyOffersByLocation[$code]->get($slug);
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
                $offerId = (int) $offer->getKey();
                if (! isset($imageKeysByOffer[$offerId][$kind])) {
                    $imageKeysByOffer[$offerId][$kind] = $controller::imageKeysFor($offer, $kind);
                }
                $allowed = $imageKeysByOffer[$offerId][$kind];
                $shown = $data['imageMap'][$slug][$kind] ?? [];
                probeCheck(array_diff($shown, $allowed) === [] && array_diff($allowed, $shown) === [], 'image_compatibility_map_mismatch');
                $result['quote_checks']['image_keys'] += count($shown);
            }
            if ($result['quote_checks']['public_slugs'] % 50 === 0) {
                probeProgress('quotes_checked', $result['quote_checks'], $probeStartedAt);
            }
        }
        if (! $render || $slugs === []) return;
        $html = $view->render();
        probeCheck(preg_match('/\bvar D = (\{[^\r\n]*\});/', $html, $match) === 1, 'rendered_inline_data_missing');
        $inline = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
        probeCheck($inline['prices'] === $data['priceMap'] && $inline['images'] === $data['imageMap']
            && ($inline['addonTotals'] ?? null) === $data['addonTotals']
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

    if (! ($publicOnly ?? false)) {
    $allCodes = [];
    foreach ($controller->locationGroups() as $group) foreach ($group['locations'] as $location) $allCodes[] = (string) $location->code;
    probeCheck($allCodes !== [], 'no_live_foreign_locations');
    $allCodes = array_values(array_unique($allCodes));
    $result['quote_checks']['target_foreign_locations'] = count($allCodes);
    foreach ($allCodes as $code) {
        probeProgress('foreign_location_start', $result['quote_checks'], $probeStartedAt);
        $request = $requestFor($customers['unverified'], 'fa', inputs: ['location' => $code]);
        $checkView($controller->index($request), false);
        probeProgress('foreign_location_checked', $result['quote_checks'], $probeStartedAt);
    }
    // Render actual Blade in three languages, for real permitted cohorts. No HTML is retained.
    foreach (['unverified', 'manual_profile_only', 'shahkar'] as $name) {
        if ($customers[$name] === null) continue;
        foreach (['fa', 'en', 'tr'] as $locale) {
            probeProgress('cohort_render_start', $result['quote_checks'], $probeStartedAt);
            $request = $requestFor($customers[$name], $locale, inputs: ['location' => $allCodes[0]]);
            $checkView($controller->index($request), true);
            probeProgress('cohort_render_checked', $result['quote_checks'], $probeStartedAt);
        }
    }
    if ($customers['shahkar'] !== null) {
        foreach ($rawIranCodes as $code) {
            if (App\Models\CloudPlan::offers($code)->isEmpty()) continue;
            probeProgress('iran_location_start', $result['quote_checks'], $probeStartedAt);
            $request = $requestFor($customers['shahkar'], 'fa', inputs: ['location' => $code]);
            $checkView($controller->index($request), true);
            probeProgress('iran_location_checked', $result['quote_checks'], $probeStartedAt);
        }
    }
    probeCheck($result['quote_checks']['public_slugs'] > 0 && $result['quote_checks']['cycle_entries'] > 0
        && $result['quote_checks']['rendered_pages'] >= 3, 'insufficient_live_quote_coverage');
    } else {
        $stage = 'public_quote_followup';
        $result['acceptance_mode'] = 'public_only';
        $result['full_catalogue_render_sweep_executed'] = false;
        $result['public_quote_checks'] = ['foreign_offers' => 0, 'cycle_entries' => 0,
            'home_pages' => 0, 'home_plan_quotes' => 0, 'product_quotes' => 0,
            'catalogue_pages' => 0, 'catalogue_plan_quotes' => 0, 'product_destinations' => 0];
        $allOffers = App\Models\CloudPlan::offers();
        $offersById = $allOffers->keyBy(fn ($offer) => (int) $offer->getKey());
        $locationCountries = App\Models\CloudLocation::pluck('country', 'code');
        $isIranOffer = fn ($offer) => App\Services\Customer\IranSalesGate::isIranTarget(
            (string) ($locationCountries->get($offer->location_code) ?: $offer->location_code))
            || App\Services\Customer\IranSalesGate::isIranTarget((string) $offer->location_code);
        $foreignOffers = $allOffers->reject($isIranOffer);
        probeCheck($foreignOffers->isNotEmpty(), 'no_live_foreign_public_offers');
        // These are complete-cycle checkout quotes, not reconstructed discounts.
        $expectedPublicQuote = function (?App\Models\CloudPlan $offer) use ($controller): array {
            if ($offer === null) return ['contact' => true, 'quote' => true];
            $monthly = $controller::priceForCycle($offer, 'monthly');
            $annual = $controller::priceForCycle($offer, 'yearly');
            $months = App\Models\Service::monthsIn('yearly');
            if ($monthly <= 0 || $annual <= 0 || $months <= 0) return ['contact' => true, 'quote' => true];
            $undiscounted = $monthly * $months;
            return ['irt' => $monthly, 'yearly_irt' => (int) ceil($annual / $months),
                'yearly_savings_pct' => $undiscounted > $annual
                    ? (int) floor(($undiscounted - $annual) * 100 / $undiscounted) : 0,
                'final_irt' => true];
        };
        $checkPublicQuote = function (array $shown, ?App\Models\CloudPlan $offer) use ($expectedPublicQuote): void {
            $expected = $expectedPublicQuote($offer);
            foreach ($expected as $key => $value) {
                probeCheck(array_key_exists($key, $shown) && $shown[$key] === $value, 'public_checkout_quote_mismatch');
            }
            if ($offer === null) probeCheck(! array_key_exists('irt', $shown) && ! array_key_exists('yearly_irt', $shown),
                'unavailable_public_quote_has_price');
        };
        foreach ($foreignOffers as $offer) {
            $checkPublicQuote(app(App\Services\Cloud\CloudPublicQuote::class)->quote($offer), $offer);
            foreach ($controller::cycles() as $cycle) {
                $price = $controller::priceForCycle($offer, $cycle);
                $months = App\Models\Service::monthsIn($cycle);
                $cost = (int) $offer->cost_eur_cents / 100 * $eurRate * $months;
                probeCheck($price > 0 && $price + 1 >= $cost, 'public_cycle_below_stored_supplier_cost', [
                    'public_slug' => (string) $offer->slug, 'provider' => (string) $offer->provider,
                    'cycle' => $cycle, 'computed_cycle_price_irt' => $price, 'stored_supplier_cycle_cost_irt' => $cost]);
                if (in_array($offer->provider, ['salad', 'lightnode'], true)) {
                    $hours = $offer->provider === 'lightnode'
                        ? App\Services\Cloud\LightNodePublicPricing::CALENDAR_MONTH_RESERVE_HOURS
                        : App\Services\Cloud\SaladClient::HOURS_PER_MONTH;
                    $floor = $offer->hourlyCostFloorEurMicro() / 1000000 * $eurRate * $hours * $months;
                    probeCheck($price + 1 >= $floor, 'public_cycle_below_hourly_reserve_floor');
                }
                $result['public_quote_checks']['cycle_entries']++;
            }
            $result['public_quote_checks']['foreign_offers']++;
        }
        probeProgress('public_foreign_quotes_checked', [
            'public_slugs' => $result['public_quote_checks']['foreign_offers'],
            'cycle_entries' => $result['public_quote_checks']['cycle_entries']], $probeStartedAt);
        $parseDom = function (string $html): DOMXPath {
            $dom = new DOMDocument();
            $previousErrors = libxml_use_internal_errors(true);
            try { probeCheck($dom->loadHTML('<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'public_rendered_dom_invalid'); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
            return new DOMXPath($dom);
        };
        // Real configured pages, rendered with each actual available identity cohort.
        // No cloud-store index, image catalogue hydration or outbound quote occurs.
        foreach (['guest', 'unverified', 'shahkar'] as $name) {
            $customer = $customers[$name];
            if ($name !== 'guest' && $customer === null) continue;
            foreach (['fa', 'en', 'tr'] as $locale) {
                $requestFor($customer, $locale, publicRoute: 'home');
                $permitted = App\Services\Customer\IranSalesVisibility::restricted() ? $foreignOffers : $allOffers;
                $homeView = app(App\Http\Controllers\SiteController::class)->home();
                $homeData = $homeView->getData();
                $homePlans = (array) ($homeData['plans'] ?? []);
                probeCheck(array_column($homePlans, 'pid') === $permitted->values()->take(4)->pluck('id')->all(),
                    'home_public_offer_identity_or_eligibility_mismatch');
                foreach ($homePlans as $shown) {
                    $offer = $offersById->get((int) ($shown['pid'] ?? 0));
                    probeCheck($offer !== null && (! App\Services\Customer\IranSalesVisibility::restricted()
                        || ! $isIranOffer($offer)), 'home_iran_offer_leaked');
                    $checkPublicQuote($shown, $offer);
                    $result['public_quote_checks']['home_plan_quotes']++;
                }
                $sorted = $permitted->sortBy(fn ($offer) => $controller::priceForCycle($offer, 'monthly'));
                foreach ([2 => $sorted->first(fn ($offer) => ! $offer->isMetal()),
                    3 => $sorted->first(fn ($offer) => $offer->isMetal()), 4 => $sorted->first()] as $index => $offer) {
                    probeCheck(isset($homeData['products'][$index]), 'home_product_quote_missing');
                    $checkPublicQuote($homeData['products'][$index], $offer);
                    $result['public_quote_checks']['product_quotes']++;
                }
                $xpath = $parseDom($homeView->render());
                $links = $xpath->query('//*[@id="products"]//a[contains(concat(" ",normalize-space(@class)," ")," pcard-link ")]');
                probeCheck($links->length === count($homeData['products']), 'home_product_destination_count_mismatch');
                foreach ($homeData['products'] as $index => $product) {
                    $configured = isset($product['link']) ? lroute($product['link'][0], $product['link'][1] ?? [])
                        : buy_url($product['pid']);
                    $expectedHref = $configured;
                    if (! App\Services\Customer\IranSalesVisibility::allowsUrl($configured)) {
                        $routeName = $product['link'][0] ?? '';
                        $params = $product['link'][1] ?? [];
                        $category = $routeName === 'hosting' ? 'hosting'
                            : ($routeName === 'catalog' && is_array($params) ? ($params['category'] ?? '') : '');
                        $expectedHref = match ($category) {
                            'dedicated' => lroute('catalog', ['category' => 'dedicated', 'slug' => 'germany']),
                            'hosting' => lroute('hosting', 'linux'),
                            default => lroute('cloud.index'),
                        };
                    }
                    $href = $links->item($index)->getAttribute('href');
                    probeCheck($href === $expectedHref && App\Services\Customer\IranSalesVisibility::allowsUrl($href),
                        'home_product_destination_invalid_or_wrong_category');
                    $result['public_quote_checks']['product_destinations']++;
                }
                $savings = (int) (collect($homePlans)->filter(fn ($row) => (int) ($row['irt'] ?? 0) > 0)
                    ->min('yearly_savings_pct') ?? 0);
                $badge = $xpath->query('//*[@id="pricing"]//button[@data-bill="yearly"]//span[contains(concat(" ",normalize-space(@class)," ")," save ")]');
                probeCheck($badge->length === ($savings > 0 ? 1 : 0), 'home_annual_saving_badge_overpromises');
                if ($savings > 0) probeCheck(trim($badge->item(0)->textContent) === __('ui.bill_save',
                    ['percent' => $locale === 'fa' ? fa_num($savings) : $savings]), 'home_annual_saving_badge_mismatch');
                $annualRows = $xpath->query('//*[@id="plans"]//span[contains(concat(" ",normalize-space(@class)," ")," pr-y ")]');
                probeCheck($annualRows->length === count($homePlans), 'home_annual_price_count_mismatch');
                foreach ($homePlans as $index => $shown) {
                    $row = $annualRows->item($index);
                    probeCheck($xpath->query('./s', $row)->length === ((int) $shown['yearly_savings_pct'] > 0 ? 1 : 0),
                        'home_annual_strikeout_without_saving');
                    probeCheck(trim($xpath->query('./b', $row)->item(0)->textContent) === site_price_yearly($shown),
                        'home_annual_rendered_price_mismatch');
                }
                $monthlyRows = $xpath->query('//*[@id="plans"]//span[contains(concat(" ",normalize-space(@class)," ")," pr-m ")]/b');
                probeCheck($monthlyRows->length === count($homePlans), 'home_monthly_price_count_mismatch');
                foreach ($homePlans as $index => $shown) probeCheck(trim($monthlyRows->item($index)->textContent) === site_price($shown),
                    'home_monthly_rendered_price_mismatch');
                $result['public_quote_checks']['home_pages']++;
                foreach (['vps', 'dedicated'] as $category) {
                    $requestFor($customer, $locale, publicRoute: 'catalog',
                        routeParameters: ['category' => $category, 'slug' => 'germany']);
                    $catalogueView = app(App\Http\Controllers\CatalogController::class)->show($category, 'germany');
                    probeCheck($catalogueView instanceof Illuminate\View\View, 'public_catalogue_not_view');
                    $data = $catalogueView->getData();
                    foreach ((array) ($data['product']['plans'] ?? []) as $index => $shown) {
                        if (! ($data['liveStock'] ?? false)) {
                            probeCheck(($shown['contact'] ?? false) === true && ! isset($shown['irt']),
                                'unavailable_catalogue_has_numeric_price');
                            continue;
                        }
                        $href = (string) ($data['planHrefs'][$index] ?? '');
                        $query = [];
                        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
                        $offer = $allOffers->get((string) ($query['plan'] ?? ''));
                        probeCheck($offer !== null && ! $isIranOffer($offer)
                            && strtoupper((string) $locationCountries->get($offer->location_code)) === 'DE'
                            && (string) ($query['location'] ?? '') === (string) $offer->location_code
                            && (string) ($shown['row']['loc_code'] ?? '') === (string) $offer->location_code
                            && App\Services\Customer\IranSalesVisibility::allowsUrl($href),
                            'public_catalogue_offer_or_purchase_destination_mismatch');
                        $checkPublicQuote($shown, $offer);
                        $monthly = $controller::priceForCycle($offer, 'monthly');
                        probeCheck(($shown['row']['price_n'] ?? null) === $monthly
                            && count($shown['row']['picker'] ?? []) === 1
                            && ($shown['row']['picker'][0]['irt'] ?? null) === $monthly
                            && ($shown['row']['picker'][0]['href'] ?? null) === $href,
                            'public_catalogue_picker_price_or_destination_mismatch');
                        $result['public_quote_checks']['catalogue_plan_quotes']++;
                    }
                    probeCheck($catalogueView->render() !== '', 'public_catalogue_render_empty');
                    $result['public_quote_checks']['catalogue_pages']++;
                }
                probeProgress('public_cohort_pages_checked', ['rendered_pages' => $result['public_quote_checks']['home_pages']
                    + $result['public_quote_checks']['catalogue_pages']], $probeStartedAt);
            }
        }
        probeCheck($result['public_quote_checks']['home_pages'] >= 6
            && $result['public_quote_checks']['catalogue_pages'] >= 12
            && $result['public_quote_checks']['home_plan_quotes'] > 0
            && $result['public_quote_checks']['catalogue_plan_quotes'] > 0, 'insufficient_public_quote_coverage');
    }

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
    if ($publicOnly ?? false) $result['limitations'][] = 'Public-only follow-up does not repeat the full cloud-store location/image render sweep; retain the preceding full report for unchanged installed checkout and identity-policy hashes.';
    $stage = 'private_blade_cache_permissions';
    $result['private_blade_cache_permissions'] = probePrivateBladePermissions($here, posix_geteuid());
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
    if ($privateBladeCacheInitialized) {
        try { $privateFailure['private_blade_cache_permissions'] = probePrivateBladePermissions($here, posix_geteuid()); }
        catch (Throwable) { $privateFailure['private_blade_cache_permissions_failed'] = true; }
    }
    if ($reportPath !== null && ! file_exists($reportPath) && ! is_link($reportPath)) {
        try { probeJson($reportPath, $privateFailure); } catch (Throwable) { /* Preserve failure without exposing diagnostics. */ }
    }
    echo json_encode($failure, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
