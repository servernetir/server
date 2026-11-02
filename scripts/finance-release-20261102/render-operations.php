<?php
declare(strict_types=1);

use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRefundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;

// Synthetic identifiers use the same generators/prefixes as the reviewed UI tests.
function financeRenderCard(string $prefix): string
{
    for ($digit = 0; $digit <= 9; $digit++) {
        $number = $prefix . $digit;
        $sum = 0;
        foreach (str_split(strrev($number)) as $index => $char) {
            $value = (int) $char * ($index % 2 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }
        if ($sum % 10 === 0) {
            return $number;
        }
    }
    throw new RuntimeException('render_synthetic_card');
}

function financeRenderIban(string $country, string $bban): string
{
    $digits = '';
    foreach (str_split($country) as $char) {
        $digits .= (string) (ord($char) - 55);
    }
    $remainder = 0;
    foreach (str_split($bban . $digits . '00') as $digit) {
        $remainder = ($remainder * 10 + (int) $digit) % 97;
    }
    return $country . str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT) . $bban;
}

function financeRenderFixtures(): array
{
    $now = new Carbon\CarbonImmutable('2026-10-31 10:00:00', 'Asia/Tehran');
    $accounts = [];
    $identifiers = [];
    foreach ([
        [501, 'Synthetic company bank', 'bank', 'IRR', 'Synthetic legal entity', 'Synthetic company bank'],
        [502, 'Synthetic partner rial', 'partner', 'IRR', str_repeat('Synthetic partner owner ', 8) . '<script>owner-xss</script>', 'Synthetic rial bank'],
        [503, 'Synthetic partner lira', 'partner', 'TRY', 'Synthetic Partner ' . str_repeat('long-owner-', 10), 'Synthetic lira bank'],
    ] as $index => [$id, $name, $type, $currency, $owner, $bank]) {
        $card = financeRenderCard('99999900000000' . ($index + 1));
        $iban = $currency === 'TRY' ? financeRenderIban('TR', '999990' . str_repeat('9', 16))
            : financeRenderIban('IR', '999' . str_pad((string) ($index + 1), 19, '0', STR_PAD_LEFT));
        $identifiers[] = $card;
        $identifiers[] = $iban;
        $accounts[] = (new FinanceAccount)->forceFill([
            'id' => $id, 'name' => $name, 'type' => $type, 'currency_code' => $currency,
            'owner_name' => $owner, 'partner_key' => $type === 'partner' ? 'SYNTHETIC-PARTNER-01' : null,
            'bank_details' => ['bank_name' => $bank, 'card_number' => $card, 'iban' => $iban],
        ]);
    }
    $accounts[] = (new FinanceAccount)->forceFill(['id' => 504, 'name' => 'Synthetic prepaid provider',
        'type' => 'vendor', 'currency_code' => 'USD', 'owner_name' => null]);
    $collection = collect($accounts);
    $report = [
        'summary' => ['revenue' => 0, 'expense' => 0, 'net_profit' => 0, 'other_income' => 0],
        'profile' => null, 'eligible_costs' => 0, 'pending_costs' => 0, 'input_vat' => 0,
        'taxable_income' => null, 'income_tax_estimate' => null, 'income_tax_due' => null,
        'vat_output_reviewed' => null, 'vat_movement' => 0, 'vat_due' => null, 'vat_credit' => null,
        'partner_receivables_irt' => 0, 'partner_payables_irt' => 0,
        'payments' => ['external_receipts_irt' => 0, 'wallet_spend_irt' => 0, 'topups_irt' => 0],
        'coverage' => [], 'unconverted' => [], 'services' => [],
        'accounts' => $collection->map(static fn (FinanceAccount $account): array => [
            'account' => $account, 'balance' => ['native' => 0, 'irt' => 0],
        ]),
    ];
    $page = static fn (int $size, string $name) => new Illuminate\Pagination\LengthAwarePaginator(
        collect(), 0, $size, 1, ['path' => 'https://console.servernet.cloud/admin/accounting', 'pageName' => $name]);
    $accounting = ['ready' => true, 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        'report' => $report, 'accounts' => $collection, 'closedThrough' => null,
        'vouchers' => $page(30, 'page'), 'assets' => $page(20, 'assets_page'), 'errors' => new ViewErrorBag];
    $voucher = (new FinanceVoucher)->forceFill([
        'id' => -21001, 'type' => 'vendor_topup', 'occurred_at' => '2026-10-31', 'service_line' => 'hosting',
        'vendor' => 'Synthetic prepaid provider', 'invoice_number' => 'SYNTHETIC-INV-01',
        'bank_reference' => 'SYNTHETIC-FUNDING-REF', 'fx_basis' => 'Synthetic documented conversion',
        'note' => 'Synthetic funding evidence', 'tax_status' => 'pending', 'tax_reference' => null,
        'business_entry_id' => null, 'reversal_of' => null, 'amount_irt' => 120000,
        'original_currency' => 'USD', 'original_minor' => 10000, 'settlement_currency' => null,
        'settlement_minor' => null, 'input_vat_irt' => 0,
    ]);
    $document = (new FinanceDocument)->forceFill(['id' => -22001, 'voucher_id' => -21001,
        'name' => 'synthetic-receipt.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'sha256' => str_repeat('a', 64)]);
    $voucherData = ['voucher' => $voucher,
        'postedDetails' => ['funding_mode' => 'advance'],
        'accountSnapshots' => ['funding' => $accounts[2]->maskedBankIdentity(), 'target' => $accounts[3]->maskedBankIdentity()],
        'currentAccountIdentities' => [],
        'relatedVoucher' => (new FinanceVoucher)->forceFill(['id' => -21000, 'type' => 'partner_advance_transfer']),
        'lines' => collect([
            (new FinanceLine)->forceFill(['code' => 'prepaid_vendor', 'account_id' => 504, 'debit_irt' => 120000, 'credit_irt' => 0]),
            (new FinanceLine)->forceFill(['code' => 'partner_current', 'account_id' => 503, 'debit_irt' => 0, 'credit_irt' => 120000]),
        ]), 'documents' => collect([$document]), 'reviews' => collect(), 'reversal' => null, 'errors' => new ViewErrorBag];
    $customer = (new Customer)->forceFill(['id' => 0, 'email' => 'synthetic-refund@example.test', 'status' => 'active', 'locale' => 'fa']);
    $refunds = [];
    foreach (['approved', 'paid'] as $status) {
        $refund = (new WalletRefundRequest)->forceFill([
            'id' => -42001, 'public_id' => '30000000-0000-4000-8000-000000000042', 'customer_id' => 0,
            'amount_irt' => 40000, 'status' => $status,
            'bank_snapshot' => ['iban' => 'IR880100000000000000000001', 'owner_name' => str_repeat('Synthetic destination owner ', 8), 'bank_name' => 'Synthetic destination bank'],
            'customer_note' => str_repeat('Synthetic customer explanation ', 15) . '<script>note-xss</script>',
            'review_note' => 'Synthetic review decision', 'source_note' => 'Synthetic incoming payment evidence reviewed',
            'created_at' => $now->subDays(2), 'reviewed_at' => $now->subDay(),
            'payout_date' => $status === 'paid' ? '2026-10-31' : null,
            'paid_at' => $status === 'paid' ? $now : null, 'bank_reference' => 'SYNTHETIC-' . str_repeat('LONG-REFERENCE-', 10),
            'voucher_id' => $status === 'paid' ? -21001 : null, 'document_id' => $status === 'paid' ? -22001 : null,
            'source_payment_id' => -801, 'reviewed_by' => 0, 'paid_by' => $status === 'paid' ? 0 : null,
        ])->setRelation('customer', $customer);
        $snapshot = ['id' => 502, 'name' => 'Synthetic original paying bank', 'type' => 'partner',
            'currency_code' => 'IRR', 'owner_name' => 'Synthetic original payer'];
        $paidEvent = $status === 'paid' ? (object) ['id' => 2, 'action' => 'paid', 'actor_name' => 'Synthetic payout recorder',
            'created_at' => $now, 'details' => json_encode(['funding_account_id' => 502,
                'bank_reference' => $refund->bank_reference, 'document_sha256' => str_repeat('a', 64),
                'funding_account_snapshot' => $snapshot], JSON_THROW_ON_ERROR)] : null;
        $events = collect([(object) ['id' => 1, 'action' => 'requested', 'actor_name' => 'Synthetic customer',
            'created_at' => $now->subDays(2), 'details' => json_encode(['note' => 'Synthetic audit <script>audit-xss</script>'], JSON_THROW_ON_ERROR)]]);
        if ($paidEvent !== null) {
            $events->push($paidEvent);
        }
        $refunds[$status] = ['refund' => $refund, 'events' => $events, 'accounts' => collect([$accounts[0], $accounts[1]]),
            'payments' => collect([(new Payment)->forceFill(['id' => -801, 'amount' => 90000, 'currency_code' => 'IRT', 'gateway' => 'mellat'])]),
            'fundingSnapshot' => $status === 'paid' ? $snapshot : null,
            'fundingAccount' => $status === 'paid' ? (new FinanceAccount)->forceFill(['id' => 502,
                'name' => 'Synthetic renamed live account', 'type' => 'partner', 'currency_code' => 'IRR', 'owner_name' => 'Synthetic current owner']) : null,
            'fundingLegacyFallback' => false, 'paidEvent' => $paidEvent,
            'receiptDocument' => $status === 'paid' ? $document : null, 'errors' => new ViewErrorBag];
    }
    return ['identifiers' => $identifiers, 'cases' => [
        'accounting' => ['view' => 'admin.accounting', 'path' => '/admin/accounting', 'data' => $accounting],
        'voucher' => ['view' => 'admin.accounting-voucher', 'path' => '/admin/accounting/vouchers/-21001', 'data' => $voucherData],
        'refund-approved' => ['view' => 'admin.wallet-refund', 'path' => '/admin/wallet-refunds/30000000-0000-4000-8000-000000000042', 'data' => $refunds['approved']],
        'refund-paid' => ['view' => 'admin.wallet-refund', 'path' => '/admin/wallet-refunds/30000000-0000-4000-8000-000000000042', 'data' => $refunds['paid']],
    ]];
}

function financeRenderFlags(string $html, string $locale, string $case, array $identifiers): array
{
    $old = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument;
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            throw new RuntimeException('render_html_parse');
        }
        $xpath = new DOMXPath($dom);
        $language = $xpath->query('/html')->item(0);
        $csrf = true;
        foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) {
            if ($xpath->query('.//input[@name="_token" and @value="synthetic-finance-release-csrf"]', $form)->length !== 1) {
                $csrf = false;
            }
        }
        $private = true;
        foreach ($identifiers as $identifier) {
            $private = $private && !str_contains($html, $identifier);
        }
        $fields = true;
        foreach ($xpath->query('//input[@name="iban" or @name="card_number"]') as $field) {
            $fields = $fields && $field->getAttribute('value') === '';
        }
        $flags = ['has_html' => $language !== null,
            'locale_and_direction' => $language !== null && $language->getAttribute('lang') === $locale
                && $language->getAttribute('dir') === ($locale === 'fa' ? 'rtl' : 'ltr'),
            'post_forms_have_synthetic_csrf' => $csrf,
            'private_bank_identifiers_absent' => $private, 'private_inputs_not_prefilled' => $fields,
            'no_raw_finance_refund_keys' => !preg_match('/\b(?:finance|refund)\.[a-z_]+/', $dom->textContent),
            'fixture_scripts_escaped' => !str_contains($html, '<script>note-xss</script>')
                && !str_contains($html, '<script>owner-xss</script>') && !str_contains($html, '<script>audit-xss</script>'),
            'shared_select_assets' => str_contains($html, 'assets/css/site-select.css') && str_contains($html, 'assets/js/site-select.js'),
        ];
        if ($case === 'accounting') {
            $flags['account_create_and_supplier_forms'] = $xpath->query('//form[@data-account-create]')->length === 1
                && $xpath->query('//form[@data-supplier-debit]')->length === 1;
            $flags['synthetic_accounts_and_funding_modes'] = str_contains($html, 'Synthetic company bank')
                && str_contains($html, 'Synthetic partner lira') && str_contains($html, 'SYNTHETIC-PARTNER-01')
                && $xpath->query('//select[@name="funding_mode"]/option[@value="advance" and @selected]')->length === 1;
            $flags['specific_css'] = str_contains($html, 'assets/css/finance-funding.css');
        } elseif ($case === 'voucher') {
            $flags['funding_and_target_snapshots'] = $xpath->query('//*[@data-voucher-account and @data-account-evidence="snapshot"]')->length === 2;
            $flags['specific_css'] = str_contains($html, 'assets/css/finance-funding.css');
        } else {
            $status = $case === 'refund-paid' ? 'paid' : 'approved';
            $flags['refund_status'] = $xpath->query('//*[@data-refund-status="' . $status . '"]')->length === 1;
            $flags['payout_form_state'] = $xpath->query('//form[@data-refund-form="paid"]')->length === ($status === 'approved' ? 1 : 0);
            $flags['specific_css'] = str_contains($html, 'assets/css/admin-wallet-refund.css');
            if ($status === 'paid') {
                $flags['historical_payer_and_receipt'] = $xpath->query('//*[@data-funding-evidence="snapshot"]')->length === 1
                    && str_contains($html, 'Synthetic original paying bank') && str_contains($html, str_repeat('a', 64))
                    && !str_contains($html, 'Synthetic renamed live account');
            }
        }
        return $flags;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
}

function financeRender($app, string $home, string $stage, array $scope, array $plan): array
{
    $context = financeReleaseContext($app, $home, $stage, $scope, $plan);
    $state = json_decode(file_get_contents(financeReleasePrivatePath($stage, 'install-state.json')), true, 32, JSON_THROW_ON_ERROR);
    financeReleaseAfter($app, $home, $stage, $scope, $plan, $context, $state);
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('render_dom_required');
    }
    $database = $app->make('db')->connection();
    if ($database->transactionLevel() !== 0 || !method_exists($database, 'beforeExecuting')) {
        throw new RuntimeException('render_database_scope');
    }
    $old = ['locale' => $app->getLocale(), 'request' => $app->make('request'), 'view' => $app->make('view'),
        'blade.compiler' => $app->make('blade.compiler'), 'auth' => $app->make('auth'),
        'session' => $app->make('session'), 'session.store' => $app->make('session.store'),
        'cache.store' => $app->make('cache.store'), 'url' => $app->make('url'),
        'compiled' => config('view.compiled'), 'guard' => config('auth.defaults.guard'), 'cache' => config('cache.default'),
        'translator_locale' => $app->make('translator')->getLocale()];
    $http = Http::getFacadeRoot();
    $prevented = $http->preventingStrayRequests();
    $mask = umask(0077);
    $active = false;
    $transaction = false;
    $cache = null;
    $results = [];
    try {
        $cacheRelative = 'render-cache-' . bin2hex(random_bytes(8));
        $cache = financeReleaseDirectory($stage, $cacheRelative);
        financeReleaseDirectory($stage, 'rendered-views');
        $http->preventStrayRequests(true);
        config(['view.compiled' => $cache, 'auth.defaults.guard' => 'web', 'cache.default' => 'array']);
        $app->instance('cache.store', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore));
        $session = new Illuminate\Session\Store('finance-release-render', new Illuminate\Session\ArraySessionHandler(5));
        $session->start();
        $session->put('_token', 'synthetic-finance-release-csrf');
        $app->instance('session', $session);
        $app->instance('session.store', $session);
        $auth = new Illuminate\Auth\AuthManager($app);
        $app->instance('auth', $auth);
        $compiler = clone $old['blade.compiler'];
        $property = new ReflectionProperty(Illuminate\View\Compilers\Compiler::class, 'cachePath');
        $property->setValue($compiler, $cache);
        $app->instance('blade.compiler', $compiler);
        $engines = clone $old['view']->getEngineResolver();
        $engines->register('blade', static fn () => new Illuminate\View\Engines\CompilerEngine($compiler, $app->make('files')));
        $views = new Illuminate\View\Factory($engines, clone $old['view']->getFinder(), $old['view']->getDispatcher());
        $views->setContainer($app);
        $shared = $old['view']->getShared();
        unset($shared['__env']);
        $views->share($shared);
        $views->share('app', $app);
        $views->share('errors', new ViewErrorBag);
        $app->instance('view', $views);
        foreach (['auth', 'session', 'view', 'blade.compiler', 'cache.store'] as $facade) {
            Facade::clearResolvedInstance($facade);
        }
        // This affects only the next transaction, not persistent session defaults.
        $database->getPdo()->exec('SET TRANSACTION READ ONLY');
        $database->beginTransaction();
        $transaction = true;
        $active = true;
        $database->beforeExecuting(static function (string $query) use (&$active): void {
            if ($active && (!preg_match('/\A\s*(?:SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query)
                || preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b|\bFOR\s+UPDATE\b/i', $query))) {
                throw new RuntimeException('render_readonly_query');
            }
        });
        $user = (new User)->forceFill(['id' => 0, 'name' => 'Synthetic finance renderer',
            'email' => 'synthetic-finance-render@example.test', 'role' => 'admin']);
        $guard = $auth->guard('web');
        // SessionGuard::setUser dispatches Authenticated. The fixture must not
        // run application login listeners, notifications or user-preference work.
        $guard->setDispatcher(new Illuminate\Events\Dispatcher($app));
        $guard->setUser($user);
        $fixtures = financeRenderFixtures();
        foreach (['fa', 'en', 'tr'] as $locale) {
            // No LocaleUpdated listeners or user-preference writes are needed.
            config(['app.locale' => $locale]);
            $app->make('translator')->setLocale($locale);
            foreach ($fixtures['cases'] as $name => $fixture) {
                $session->forget('_old_input');
                if ($name === 'accounting') {
                    $session->put('_old_input', ['funding_mode' => 'advance', 'related_voucher_id' => '741']);
                }
                $request = Request::create('https://console.servernet.cloud' . $fixture['path'], 'GET');
                $request->setLaravelSession($session);
                $request->setUserResolver(static fn () => $user);
                $app->instance('request', $request);
                $old['url']->setRequest($request);
                Facade::clearResolvedInstance('request');
                $html = $views->make($fixture['view'], $fixture['data'])->render();
                $flags = financeRenderFlags($html, $locale, $name, $fixtures['identifiers']);
                financeReleaseWrite($stage, 'rendered-views/' . $locale . '-' . $name . '.html', $html);
                $results[$locale . '-' . $name] = ['bytes' => strlen($html), 'sha256' => hash('sha256', $html), 'flags' => $flags];
                unset($html);
            }
        }
        if ($database->transactionLevel() !== 1) {
            throw new RuntimeException('render_transaction_changed');
        }
        $database->rollBack();
        $transaction = false;
    } finally {
        $active = false;
        try {
            if ($transaction && $database->transactionLevel() > 0) {
                $database->rollBack(0);
            }
        } finally {
            try {
                config(['view.compiled' => $old['compiled'], 'auth.defaults.guard' => $old['guard'],
                    'cache.default' => $old['cache'], 'app.locale' => $old['locale']]);
                foreach (['request', 'view', 'blade.compiler', 'auth', 'session', 'session.store', 'cache.store'] as $binding) {
                    $app->instance($binding, $old[$binding]);
                    Facade::clearResolvedInstance($binding);
                }
                $old['url']->setRequest($old['request']);
                $app->make('translator')->setLocale($old['translator_locale']);
                $http->preventStrayRequests($prevented);
            } finally {
                umask($mask);
                // A fresh private flat cache only; never touch the production Blade cache.
                if ($cache !== null) {
                    if (is_link($cache) || realpath($cache) !== $cache || dirname($cache) !== $stage) {
                        throw new RuntimeException('render_cache_cleanup_path');
                    }
                    foreach (scandir($cache) as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        if (!preg_match('/\A[a-f0-9]+(?:\.blade)?\.php\z/D', $file)
                            || is_link($cache . '/' . $file) || !is_file($cache . '/' . $file) || !unlink($cache . '/' . $file)) {
                            throw new RuntimeException('render_cache_cleanup_file');
                        }
                    }
                    if (!rmdir($cache)) {
                        throw new RuntimeException('render_cache_cleanup_directory');
                    }
                }
            }
        }
    }
    $ok = count($results) === 12;
    foreach ($results as $result) {
        $ok = $ok && !in_array(false, $result['flags'], true);
    }
    $receipt = ['ok' => $ok, 'action' => 'render', 'cases' => $results,
        'readonly_transaction_rolled_back' => true, 'synthetic_page_models_only' => true,
        'layout_aggregates_read_from_production' => true, 'auth_and_session_persisted' => false,
        'private_html_only' => true, 'visual_or_browser_acceptance' => false];
    financeReleaseJson($stage, 'render-state.json', $receipt);
    return $receipt;
}
