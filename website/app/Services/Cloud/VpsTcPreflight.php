<?php

namespace App\Services\Cloud;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Read-only discovery. Native observations never authorize import, sales or paid lifecycle calls. */
class VpsTcPreflight
{
    private const MAX_CATEGORY_NODES = 64;
    private const MAX_CATEGORY_DEPTH = 6;
    private const MAX_PRODUCT_READS = 16;
    private const MAX_PRODUCT_ROWS = 512;
    private const MAX_PREVIEWS = 128;
    private const MAX_ORDER_READS = 3;
    private const MAX_CATEGORY_ORDER_READS = 32;
    private const MAX_CATEGORY_QUOTE_READS = 16;

    public function __construct(private VpsTcApi $api) {}

    public function inspect(?string $productId = null, ?string $categoryId = null): array
    {
        $scope = $productId !== null ? 'product' : ($categoryId !== null ? 'category' : 'representative_catalog');
        $formLimit = $productId !== null ? 1 : ($categoryId !== null ? self::MAX_CATEGORY_ORDER_READS : self::MAX_ORDER_READS);
        $quoteLimit = $productId !== null ? 1 : ($categoryId !== null ? self::MAX_CATEGORY_QUOTE_READS : 0);
        $report = [
            'ok' => false, 'connection_ok' => false, 'sales_ready' => false,
            'checked_at' => now()->toIso8601String(), 'currency' => null,
            'categories_seen' => null, 'leaf_categories_seen' => 0, 'categories_inspected' => 0,
            'products_seen' => 0, 'monthly_offers_seen' => 0, 'fixed_monthly_candidates_seen' => 0,
            'metered_candidates_seen' => 0, 'unknown_paytype_candidates_seen' => 0,
            'order_form_checked' => false, 'order_forms_checked' => 0,
            'scope' => $scope, 'scope_product_id' => $productId, 'scope_category_id' => $categoryId,
            'order_forms_scope' => $productId !== null ? 'explicit_product'
                : ($categoryId !== null ? 'all_fixed_monthly_in_category' : 'representative_per_category'),
            'order_forms_eligible' => 0, 'order_forms_expected' => 0, 'order_forms_attempted' => 0,
            'order_forms_complete' => false,
            'quotes_expected' => 0, 'quotes_attempted' => 0, 'quotes_checked' => 0,
            'quotes_arithmetic_verified' => 0, 'quote_diagnostics_complete' => false,
            'money_quotes_verified' => 0, 'currency_source' => null,
            'quote_diagnostics_scope' => $quoteLimit > 0 ? 'checked_prepaid_vps_forms' : 'not_requested',
            'raw_unit_verified' => false,
            'credit_payment_method_id' => null,
            'payment_methods_seen' => 0, 'payment_fees_verified' => false,
            'catalog_complete' => false, 'discovery_complete' => false, 'truncated' => false,
            'categories' => [], 'candidates' => [],
            'limits' => ['category_nodes' => self::MAX_CATEGORY_NODES, 'category_depth' => self::MAX_CATEGORY_DEPTH,
                'product_reads' => self::MAX_PRODUCT_READS, 'product_rows' => self::MAX_PRODUCT_ROWS,
                'previews' => self::MAX_PREVIEWS, 'order_form_reads' => $formLimit, 'quote_reads' => $quoteLimit],
            'blockers' => ['catalog_mapping_not_verified', 'traffic_cost_control_not_verified',
                'live_provisioning_not_verified', 'renewal_and_billing_cancellation_not_verified'],
            'message' => 'Read-only discovery has not completed; customer sales remain unavailable.',
        ];
        foreach ([$productId, $categoryId] as $id) {
            if ($id !== null && ! $this->id($id)) {
                $this->block($report, 'invalid_resource_id');
                return $report;
            }
        }
        if (! $this->api->isConfigured()) {
            $this->block($report, 'credentials_not_configured');
            return $report;
        }
        $balance = $this->api->balance();
        $details = $balance['data']['details'] ?? null;
        if (! ($balance['ok'] ?? false) || ! is_array($details)
            || $this->decimal($details['acc_credit'] ?? null, true) === null
            || $this->decimal($details['acc_balance'] ?? null, true) === null) {
            $this->block($report, 'account_read_failed');
            return $report;
        }
        $report['connection_ok'] = true;
        $currency = $details['currency'] ?? null;
        $money = class_exists(VpsTcMoneyContract::class) ? new VpsTcMoneyContract($this->api) : null;
        if (is_string($currency) && preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            $report['currency'] = $currency;
            $report['currency_source'] = 'api_balance';
        } elseif ($currency === '' && $money !== null && ($resolved = $money->resolveCurrency($balance)) !== null) {
            // A literal blank is resolved only against a freshly re-read,
            // account-bound reviewed invoice/service anchor. Symbols and null
            // never inherit that currency by fallback.
            $report['currency'] = $resolved;
            $report['currency_source'] = 'reviewed_account_contract';
        } else {
            $this->block($report, 'account_currency_not_verified');
        }

        $categories = $this->api->categories();
        $items = $categories['data']['categories'] ?? null;
        if (! ($categories['ok'] ?? false) || ! is_array($items)) {
            $this->block($report, 'category_read_failed');
            return $report;
        }
        $complete = ! $this->partial($categories['data'] ?? [], count($items));
        if (! $complete) { $this->block($report, 'category_list_incomplete'); }
        $tree = []; $nodes = 0;
        $this->walkCategories($items, null, 0, $tree, $nodes, $complete, $report);
        $report['categories_seen'] = count($tree);
        $report['leaf_categories_seen'] = count(array_filter($tree, fn ($row) => $row['is_leaf']));
        $selected = $categoryId !== null
            ? [$categoryId => $tree[$categoryId] ?? $this->category($categoryId, '', null, 0, true)]
            : array_filter($tree, fn ($row) => $row['is_leaf']);
        $records = []; $rowsSeen = 0; $reads = 0;
        foreach ($selected as $id => $category) {
            $id = (string) $id;
            if ($reads >= self::MAX_PRODUCT_READS) {
                $this->truncate($report, $complete, 'product_read_limit_reached');
                break;
            }
            $reads++;
            $products = $this->api->products($id);
            $rows = $products['data']['products'] ?? null;
            if (! ($products['ok'] ?? false) || ! is_array($rows)) {
                $complete = false; $this->block($report, 'product_read_failed');
                continue;
            }
            $report['categories_inspected']++;
            if (! $this->partial($products['data'] ?? [], count($rows))) {
                $category['product_read'] = true;
            } else {
                $complete = false; $this->block($report, 'product_list_incomplete');
            }
            foreach ($rows as $row) {
                if ($rowsSeen >= self::MAX_PRODUCT_ROWS) {
                    $this->truncate($report, $complete, 'product_row_limit_reached');
                    break;
                }
                $rowsSeen++;
                if (! is_array($row) || ! $this->id($row['id'] ?? null)) {
                    $complete = false; $this->block($report, 'product_schema_not_verified');
                    continue;
                }
                $pid = (string) $row['id'];
                if (isset($records[$pid])) {
                    $complete = false; $this->block($report, 'duplicate_product_id');
                    continue;
                }
                $candidate = $this->candidate($row, $id, $category['name']);
                if (isset($row['category_id']) && (! $this->id($row['category_id']) || (string) $row['category_id'] !== $id)) {
                    $candidate['issues'][] = 'product_category_mismatch';
                    $this->block($report, 'product_category_mismatch');
                }
                $records[$pid] = $candidate;
                $category['products_seen']++;
            }
            $tree[$id] = $category;
        }
        $report['products_seen'] = count($records);

        $payments = $this->api->paymentMethods();
        if (($payments['ok'] ?? false) && is_array($payments['data']['payments'] ?? null)) {
            $report['payment_methods_seen'] = count($payments['data']['payments']);
            $methods = $this->creditMethods($payments['data']['payments']);
            if (count($methods) === 1) { $report['credit_payment_method_id'] = $methods[0]; }
            else { $this->block($report, $methods === [] ? 'credit_payment_method_not_verified' : 'credit_payment_method_ambiguous'); }
        } else {
            $complete = false; $this->block($report, 'payment_method_read_failed');
        }
        $fees = $this->api->paymentFees();
        if (! ($fees['ok'] ?? false) || ! is_array($fees['data']['payments'] ?? null)) {
            $complete = false; $this->block($report, 'payment_fee_read_failed');
        } else {
            $verified = $fees['data']['payments'] !== [];
            foreach ($fees['data']['payments'] as $fee) {
                if (! is_array($fee) || ! $this->id($fee['id'] ?? null)) {
                    $verified = false; $this->block($report, 'payment_fee_schema_not_verified');
                    continue;
                }
                foreach (['fixed_fee', 'percent_fee'] as $field) {
                    if (($fee[$field] ?? null) === 'Array') {
                        $verified = false; $this->block($report, 'payment_fees_literal_array');
                    } elseif ($this->decimal($fee[$field] ?? null, true) === null) {
                        $verified = false; $this->block($report, 'payment_fee_amount_not_verified');
                    }
                }
            }
            $report['payment_fees_verified'] = $verified;
            if (! $verified) { $this->block($report, 'payment_fees_not_verified'); }
        }

        $orderIds = [];
        $eligible = array_values(array_filter($records, fn ($candidate) =>
            $candidate['monthly_price_raw'] !== null && $candidate['paytype'] === 'Regular'));
        $report['order_forms_eligible'] = count($eligible);
        if ($productId !== null) {
            if (! isset($records[$productId])) {
                $records[$productId] = $this->candidate(['id' => $productId], $categoryId, '');
                $records[$productId]['issues'][] = 'product_list_match_not_found';
                $this->block($report, 'product_list_match_not_found');
            }
            $orderIds = [$productId];
            $report['order_forms_expected'] = 1;
        } elseif ($categoryId !== null) {
            $report['order_forms_expected'] = count($eligible);
            $orderIds = array_column(array_slice($eligible, 0, $formLimit), 'id');
            if (count($eligible) > $formLimit) {
                $this->truncate($report, $complete, 'order_form_read_limit_reached');
            }
        } else {
            $priority = array_values($records);
            usort($priority, fn ($a, $b) => $this->vpsPriority($a) <=> $this->vpsPriority($b));
            $seenCategories = [];
            foreach ($priority as $candidate) {
                if ($candidate['monthly_price_raw'] === null || $candidate['paytype'] !== 'Regular'
                    || isset($seenCategories[$candidate['category_id']])) { continue; }
                $seenCategories[$candidate['category_id']] = true;
                $orderIds[] = $candidate['id'];
                if (count($orderIds) >= $formLimit) { break; }
            }
            $report['order_forms_expected'] = count($orderIds);
        }
        foreach ($categoryId !== null && $productId === null ? array_column($eligible, 'id') : $orderIds as $id) {
            $cid = $records[$id]['category_id'];
            if (isset($tree[$cid])) { $tree[$cid]['order_forms_expected']++; }
        }
        foreach ($orderIds as $id) {
            $candidate = $records[$id];
            $report['order_forms_attempted']++;
            if (isset($tree[$candidate['category_id']])) { $tree[$candidate['category_id']]['order_forms_attempted']++; }
            $form = $this->api->productOrderForm($id);
            $product = $form['data']['product'] ?? null;
            if (! ($form['ok'] ?? false) || ! is_array($product) || ! is_array($product['config']['forms'] ?? null)) {
                $complete = false; $this->block($report, 'order_form_schema_not_verified');
                $candidate['issues'][] = 'order_form_schema_not_verified';
            } else {
                $report['order_form_checked'] = true; $report['order_forms_checked']++;
                if (isset($tree[$candidate['category_id']])) { $tree[$candidate['category_id']]['order_forms_checked']++; }
                $candidate['order_checked'] = true;
                $this->compareOrder($candidate, $product, $report);
                $candidate['issues'][] = 'raw_price_units_not_verified';
                $this->block($report, 'raw_price_units_not_verified');
            }
            $records[$id] = $candidate;
        }
        $report['order_forms_complete'] = $report['order_forms_expected'] === $report['order_forms_checked'];
        $quoteIds = $quoteLimit > 0 ? array_values(array_filter($orderIds, fn ($id) => $this->quoteEligible($records[$id]))) : [];
        $report['quotes_expected'] = count($quoteIds);
        if (count($quoteIds) > $quoteLimit) {
            $this->truncate($report, $complete, 'quote_read_limit_reached');
            $quoteIds = array_slice($quoteIds, 0, $quoteLimit);
        }
        foreach ($quoteIds as $id) {
            if ($report['credit_payment_method_id'] === null) {
                $records[$id]['issues'][] = 'quote_credit_payment_not_verified';
                $complete = false;
                continue;
            }
            $report['quotes_attempted']++;
            $quote = $this->api->quote((string) $id, $report['credit_payment_method_id']);
            $diagnostics = ($quote['ok'] ?? false) ? $this->quoteDiagnostics($quote['data'] ?? [], (string) $id) : null;
            if ($diagnostics === null) {
                $complete = false;
                $records[$id]['issues'][] = 'quote_schema_not_verified';
                $this->block($report, 'quote_schema_not_verified');
                continue;
            }
            $report['quotes_checked']++;
            $records[$id]['quote_checked'] = true;
            $records[$id]['quote_diagnostics'] = $diagnostics;
            if ($diagnostics['arithmetic_match']) { $report['quotes_arithmetic_verified']++; }
            else {
                $records[$id]['issues'][] = 'quote_arithmetic_mismatch';
                $this->block($report, 'quote_arithmetic_mismatch');
            }
            $records[$id]['issues'][] = 'quote_raw_unit_not_verified';
            $this->block($report, 'quote_raw_unit_not_verified');
            $evidence = $this->moneyEvidence($money, $records[$id], $report['credit_payment_method_id'],
                $quote['data'] ?? [], $report['currency']);
            if ($evidence !== null) {
                $records[$id]['money_evidence'] = $evidence;
                $records[$id]['price_normalized_match'] = true;
                $records[$id]['raw_unit_verified'] = true;
                $records[$id]['catalog_raw_unit'] = $evidence['currency'];
                $records[$id]['order_raw_unit'] = $evidence['base_currency'];
                $records[$id]['quote_diagnostics']['unit'] = $evidence['base_currency'];
                $records[$id]['quote_diagnostics']['currency'] = $evidence['base_currency'];
                $records[$id]['quote_diagnostics']['unit_verified'] = true;
                $records[$id]['quote_diagnostics']['configuration_verified'] = $evidence['configuration_verified'];
                $records[$id]['issues'] = array_values(array_diff($records[$id]['issues'],
                    ['raw_price_units_not_verified', 'quote_raw_unit_not_verified']));
                if (in_array('monthly_price_mismatch', $records[$id]['issues'], true)) {
                    $records[$id]['resolved_issues'][] = 'monthly_price_mismatch';
                }
                $report['money_quotes_verified']++;
            }
        }
        $report['quote_diagnostics_complete'] = $report['quotes_expected'] === $report['quotes_checked'];
        foreach (['monthly_price_mismatch', 'raw_price_units_not_verified', 'quote_raw_unit_not_verified'] as $code) {
            $unresolved = collect($records)->contains(fn ($candidate) => in_array($code, $candidate['issues'], true)
                && empty($candidate['money_evidence']['unit_verified']));
            if (! $unresolved) { $report['blockers'] = array_values(array_diff($report['blockers'], [$code])); }
        }
        if ($productId !== null && isset($records[$productId])) {
            $records = [$productId => $records[$productId]] + $records;
        }
        foreach ($records as $candidate) {
            $cid = $candidate['category_id'];
            if ($candidate['monthly_price_raw'] !== null) {
                $report['monthly_offers_seen']++;
                if (isset($tree[$cid])) { $tree[$cid]['monthly_offers_seen']++; }
                $effective = $candidate['order_paytype'] ?? $candidate['paytype'];
                if ($effective === 'Regular') {
                    $report['fixed_monthly_candidates_seen']++;
                    if (isset($tree[$cid])) { $tree[$cid]['fixed_monthly_candidates_seen']++; }
                } elseif ($effective === 'Metered') {
                    $report['metered_candidates_seen']++;
                    if (isset($tree[$cid])) { $tree[$cid]['metered_candidates_seen']++; }
                } else {
                    $report['unknown_paytype_candidates_seen']++;
                }
            }
            if (count($report['candidates']) < self::MAX_PREVIEWS) {
                $report['candidates'][] = $candidate;
            } else {
                $report['truncated'] = true;
                $this->block($report, 'candidate_preview_limit_reached');
            }
        }
        $report['categories'] = array_values($tree);
        $report['discovery_complete'] = $complete;
        $report['ok'] = $complete;
        $report['message'] = $complete
            ? 'Read-only discovery completed within its limits. Observed monthly prices are not verified sellable offers; currency, fees and lifecycle blockers remain separate.'
            : 'Read-only discovery is partial or contains unsupported shapes. Counts describe only the observations returned; customer sales remain unavailable.';
        return $report;
    }

    private function walkCategories(array $items, ?string $parent, int $depth, array &$tree, int &$nodes, bool &$complete, array &$report): void
    {
        foreach ($items as $row) {
            if ($nodes >= self::MAX_CATEGORY_NODES || $depth > self::MAX_CATEGORY_DEPTH) {
                $this->truncate($report, $complete, 'category_traversal_limit_reached');
                return;
            }
            $nodes++;
            if (! is_array($row) || ! $this->id($row['id'] ?? null)) {
                $complete = false; $this->block($report, 'category_schema_not_verified');
                continue;
            }
            $id = (string) $row['id'];
            if (isset($tree[$id])) {
                $complete = false; $this->block($report, 'duplicate_category_id');
                continue;
            }
            $children = $row['subcategories'] ?? [];
            if (! is_array($children)) {
                $complete = false; $this->block($report, 'category_schema_not_verified');
                $children = [];
                $leaf = false;
            } else {
                $leaf = $children === [];
            }
            $tree[$id] = $this->category($id, $this->text($row['name'] ?? ''), $parent, $depth, $leaf);
            if ($children !== []) { $this->walkCategories($children, $id, $depth + 1, $tree, $nodes, $complete, $report); }
        }
    }

    private function category(string $id, string $name, ?string $parent, int $depth, bool $leaf): array
    {
        return ['id' => $id, 'name' => $name, 'parent_id' => $parent, 'depth' => $depth, 'is_leaf' => $leaf,
            'product_read' => false, 'products_seen' => 0, 'monthly_offers_seen' => 0,
            'fixed_monthly_candidates_seen' => 0, 'metered_candidates_seen' => 0,
            'order_forms_expected' => 0, 'order_forms_attempted' => 0, 'order_forms_checked' => 0];
    }

    private function candidate(array $row, ?string $categoryId, string $categoryName): array
    {
        $monthly = []; $issues = [];
        if (is_array($row['periods'] ?? null)) {
            foreach ($row['periods'] as $period) {
                if (is_array($period) && ($period['value'] ?? null) === 'm') { $monthly[] = $period; }
            }
        } elseif (isset($row['periods'])) {
            $issues[] = 'period_schema_not_verified';
        }
        if (count($monthly) > 1) { $issues[] = 'monthly_period_ambiguous'; }
        $period = count($monthly) === 1 ? $monthly[0] : [];
        $raw = $this->decimal($period['price'] ?? null, false);
        if ($period !== [] && $raw === null) { $issues[] = 'monthly_price_not_verified'; }
        $paytype = in_array($row['paytype'] ?? null, ['Regular', 'Metered'], true) ? $row['paytype'] : null;
        return ['id' => (string) $row['id'], 'category_id' => $categoryId, 'category_name' => $categoryName,
            'name' => $this->text($row['name'] ?? ''), 'paytype' => $paytype,
            'monthly_price_raw' => $raw, 'monthly_price_formatted' => $this->text($period['formatted'] ?? ''),
            'monthly_setup_raw' => $this->decimal($period['setup'] ?? null, true),
            'order_checked' => false, 'order_paytype' => null, 'order_billingtype' => null,
            'order_monthly_raw' => null, 'order_monthly_formatted' => '', 'order_setup_raw' => null,
            'raw_unit_verified' => false, 'catalog_raw_unit' => null, 'order_raw_unit' => null,
            'price_normalized_match' => null, 'money_evidence' => null, 'resolved_issues' => [],
            'catalog_to_order_ratio' => null, 'quote_checked' => false, 'quote_diagnostics' => null,
            'price_match' => null, 'setup_match' => null, 'issues' => $issues];
    }

    private function compareOrder(array &$candidate, array $product, array &$report): void
    {
        if (isset($product['id']) && (! $this->id($product['id']) || (string) $product['id'] !== $candidate['id'])) {
            $candidate['issues'][] = 'order_product_mismatch'; $this->block($report, 'order_product_mismatch');
        }
        if (isset($product['category_id']) && $candidate['category_id'] !== null
            && (! $this->id($product['category_id']) || (string) $product['category_id'] !== $candidate['category_id'])) {
            $candidate['issues'][] = 'product_category_mismatch'; $this->block($report, 'product_category_mismatch');
        }
        $candidate['order_paytype'] = in_array($product['paytype'] ?? null, ['Regular', 'Metered'], true) ? $product['paytype'] : null;
        // Unknown order evidence cannot inherit a fixed-monthly classification.
        if ($candidate['order_paytype'] === null) { $candidate['order_paytype'] = 'Unknown'; }
        $candidate['order_billingtype'] = $this->text($product['billingtype'] ?? '', 40);
        $periods = [];
        if (is_array($product['config']['product'] ?? null)) {
            foreach ($product['config']['product'] as $field) {
                if (is_array($field) && ($field['id'] ?? null) === 'cycle' && is_array($field['items'] ?? null)) {
                    foreach ($field['items'] as $period) {
                        if (is_array($period) && ($period['value'] ?? null) === 'm') { $periods[] = $period; }
                    }
                }
            }
        }
        if (count($periods) !== 1 || ($raw = $this->decimal($periods[0]['price'] ?? null, false)) === null) {
            $candidate['issues'][] = 'order_monthly_price_not_verified'; $this->block($report, 'order_monthly_price_not_verified');
            return;
        }
        $candidate['order_monthly_raw'] = $raw;
        $candidate['order_monthly_formatted'] = $this->text($periods[0]['formatted'] ?? '');
        $candidate['order_setup_raw'] = $this->decimal($periods[0]['setup'] ?? null, true);
        if ($candidate['monthly_price_raw'] !== null) {
            $candidate['price_match'] = BigDecimal::of($candidate['monthly_price_raw'])->isEqualTo($raw);
            try {
                $candidate['catalog_to_order_ratio'] = (string) BigDecimal::of($candidate['monthly_price_raw'])
                    ->dividedBy($raw, 8, RoundingMode::Unnecessary)->strippedOfTrailingZeros();
            } catch (\Throwable) {
                // A rounded ratio is not evidence of a currency conversion.
                $candidate['catalog_to_order_ratio'] = null;
            }
            if (! $candidate['price_match']) {
                $candidate['issues'][] = 'monthly_price_mismatch'; $this->block($report, 'monthly_price_mismatch');
            }
        }
        if ($candidate['monthly_setup_raw'] !== null && $candidate['order_setup_raw'] !== null) {
            $candidate['setup_match'] = BigDecimal::of($candidate['monthly_setup_raw'])->isEqualTo($candidate['order_setup_raw']);
            if (! $candidate['setup_match']) {
                $candidate['issues'][] = 'monthly_setup_mismatch'; $this->block($report, 'monthly_setup_mismatch');
            }
        } else {
            $candidate['issues'][] = 'monthly_setup_not_verified'; $this->block($report, 'monthly_setup_not_verified');
        }
        if ($candidate['order_paytype'] !== 'Regular') {
            $candidate['issues'][] = $candidate['order_paytype'] === 'Metered' ? 'metered_not_fixed_monthly' : 'order_paytype_not_verified';
            $this->block($report, $candidate['order_paytype'] === 'Metered' ? 'metered_not_fixed_monthly' : 'order_paytype_not_verified');
        }
    }

    private function creditMethods(array $payments): array
    {
        $matches = [];
        foreach ($payments as $key => $row) {
            $id = is_array($row) ? ($row['id'] ?? $key) : $key;
            $name = is_array($row) ? ($row['name'] ?? null) : $row;
            if ($this->id($id) && is_string($name) && strtolower(trim($name)) === 'credit balance') {
                $matches[] = (string) $id;
            }
        }
        return array_values(array_unique($matches));
    }

    private function quoteEligible(array $candidate): bool
    {
        return $candidate['order_checked'] === true && $candidate['paytype'] === 'Regular'
            && $candidate['order_paytype'] === 'Regular' && $candidate['order_billingtype'] === 'PrePay'
            && $candidate['order_monthly_raw'] !== null
            && preg_match('/\b(?:vps|vds)\b/i', $candidate['category_name'])
            && ! preg_match('/\b(?:vdc|dedicated)\b|virtual\s+data\s*center/i', $candidate['category_name'])
            && array_intersect($candidate['issues'], ['order_product_mismatch', 'product_category_mismatch',
                'product_list_match_not_found', 'order_form_schema_not_verified']) === [];
    }

    /** Preserve native calculation observations; never infer their unit or a sellable cost. */
    private function quoteDiagnostics(array $data, string $productId): ?array
    {
        try {
            $summary = $data['summary'] ?? null; $items = $data['items'] ?? null;
            if (! is_array($summary) || array_is_list($summary) || ! is_array($items)
                || ! array_is_list($items) || count($items) !== 1 || ! is_array($items[0])) { return null; }
            foreach ([$data, $summary, $items[0]] as $row) {
                if (($row['valid'] ?? true) !== true || ($row['success'] ?? true) === false
                    || ($row['status'] ?? true) === false) { return null; }
                foreach (['error', 'errors'] as $key) {
                    if (isset($row[$key]) && ! in_array($row[$key], [null, false, '', [], 0], true)) { return null; }
                }
            }
            $identities = [];
            if (array_key_exists('product_id', $items[0])) { $identities[] = $items[0]['product_id']; }
            if (array_key_exists('product', $items[0])) {
                if (! is_array($items[0]['product']) || ! array_key_exists('id', $items[0]['product'])) { return null; }
                $identities[] = $items[0]['product']['id'];
            }
            if ($identities === []) { return null; }
            foreach ($identities as $identity) {
                if (! $this->id($identity) || (string) $identity !== $productId) { return null; }
            }
            $subtotal = $this->quoteDecimal($summary['subtotal'] ?? null);
            $total = $this->quoteDecimal($summary['total'] ?? null);
            $credit = $this->quoteDecimal($summary['credit'] ?? null);
            if ($subtotal === null || $total === null || $credit === null || ! $subtotal->isPositive()) { return null; }
            $subtotalArithmetic = $this->quoteArithmeticDecimal($summary['subtotal'], $subtotal);
            $totalArithmetic = $this->quoteArithmeticDecimal($summary['total'], $total);
            $creditArithmetic = $this->quoteArithmeticDecimal($summary['credit'], $credit);
            $gross = $total->plus($credit);
            if (! $gross->isPositive()) { return null; }
            $taxes = []; $sums = ['tax' => BigDecimal::zero(), 'tax2' => BigDecimal::zero()];
            $arithmeticSums = ['tax' => BigDecimal::zero(), 'tax2' => BigDecimal::zero()];
            $normalized = ! $subtotal->isEqualTo($subtotalArithmetic) || ! $total->isEqualTo($totalArithmetic)
                || ! $credit->isEqualTo($creditArithmetic);
            if (! array_key_exists('tax', $summary)) { return null; }
            foreach (['tax', 'tax2'] as $group) {
                if (! array_key_exists($group, $summary)) { continue; }
                if (is_array($summary[$group])) {
                    if (count($summary[$group]) > 16) { return null; }
                    foreach ($summary[$group] as $component) {
                        if (! is_array($component)) { return null; }
                        $amount = $this->quoteDecimal($component['tax'] ?? null);
                        $rate = $this->quoteDecimal($component['value'] ?? null);
                        if ($amount === null || $rate === null || $rate->isGreaterThan(100)) { return null; }
                        $sums[$group] = $sums[$group]->plus($amount);
                        $arithmeticAmount = $this->quoteArithmeticDecimal($component['tax'], $amount);
                        $arithmeticSums[$group] = $arithmeticSums[$group]->plus($arithmeticAmount);
                        $normalized = $normalized || ! $amount->isEqualTo($arithmeticAmount);
                        $taxes[] = ['name' => $this->text($component['name'] ?? '', 60), 'group' => $group,
                            'rate_raw' => (string) $rate->strippedOfTrailingZeros(),
                            'amount_raw' => (string) $amount->strippedOfTrailingZeros(),
                            'amount_arithmetic' => (string) $arithmeticAmount->strippedOfTrailingZeros(),
                            'roundoff_normalized' => ! $amount->isEqualTo($arithmeticAmount)];
                    }
                } else {
                    $amount = $this->quoteDecimal($summary[$group]);
                    $rateKey = $group === 'tax' ? 'taxrate' : 'taxrate2';
                    $rate = isset($summary[$rateKey]) ? $this->quoteDecimal($summary[$rateKey]) : null;
                    if ($amount === null || (isset($summary[$rateKey]) && ($rate === null || $rate->isGreaterThan(100)))) { return null; }
                    $sums[$group] = $amount;
                    $arithmeticSums[$group] = $this->quoteArithmeticDecimal($summary[$group], $amount);
                    $normalized = $normalized || ! $amount->isEqualTo($arithmeticSums[$group]);
                    $taxes[] = ['name' => '', 'group' => $group,
                        'rate_raw' => $rate === null ? null : (string) $rate->strippedOfTrailingZeros(),
                        'amount_raw' => (string) $amount->strippedOfTrailingZeros(),
                        'amount_arithmetic' => (string) $arithmeticSums[$group]->strippedOfTrailingZeros(),
                        'roundoff_normalized' => ! $amount->isEqualTo($arithmeticSums[$group])];
                }
            }
            $parts = $subtotal->plus($sums['tax'])->plus($sums['tax2']);
            $reportedGrand = isset($summary['grandtotal']) ? $this->quoteDecimal($summary['grandtotal']) : null;
            if (isset($summary['grandtotal']) && $reportedGrand === null) { return null; }
            $reportedGrandArithmetic = $reportedGrand === null ? null
                : $this->quoteArithmeticDecimal($summary['grandtotal'], $reportedGrand);
            $normalized = $normalized || ($reportedGrand !== null && ! $reportedGrand->isEqualTo($reportedGrandArithmetic));
            $grossArithmetic = $totalArithmetic->plus($creditArithmetic);
            $partsArithmetic = $subtotalArithmetic->plus($arithmeticSums['tax'])->plus($arithmeticSums['tax2']);
            $arithmeticMatch = $grossArithmetic->isEqualTo($partsArithmetic)
                && ($reportedGrandArithmetic === null || $reportedGrandArithmetic->isEqualTo($grossArithmetic));
            return ['subtotal_raw' => (string) $subtotal->strippedOfTrailingZeros(),
                'tax_raw' => (string) $sums['tax']->strippedOfTrailingZeros(),
                'tax2_raw' => (string) $sums['tax2']->strippedOfTrailingZeros(), 'tax_components' => $taxes,
                'taxrates_raw' => array_values(array_filter(array_column($taxes, 'rate_raw'), fn ($value) => $value !== null)),
                'total_raw' => (string) $total->strippedOfTrailingZeros(),
                'credit_raw' => (string) $credit->strippedOfTrailingZeros(),
                'gross_raw' => (string) $gross->strippedOfTrailingZeros(),
                'component_sum_raw' => (string) $parts->strippedOfTrailingZeros(),
                'reported_grand_total_raw' => $reportedGrand === null ? null : (string) $reportedGrand->strippedOfTrailingZeros(),
                'raw_arithmetic_match' => $gross->isEqualTo($parts) && ($reportedGrand === null || $reportedGrand->isEqualTo($gross)),
                'arithmetic_match' => $arithmeticMatch, 'normalized_arithmetic_match' => $arithmeticMatch,
                'normalization_applied' => $normalized, 'roundoff_limit' => '0.00000001',
                'subtotal_arithmetic' => (string) $subtotalArithmetic->strippedOfTrailingZeros(),
                'tax_arithmetic' => (string) $arithmeticSums['tax']->strippedOfTrailingZeros(),
                'tax2_arithmetic' => (string) $arithmeticSums['tax2']->strippedOfTrailingZeros(),
                'total_arithmetic' => (string) $totalArithmetic->strippedOfTrailingZeros(),
                'credit_arithmetic' => (string) $creditArithmetic->strippedOfTrailingZeros(),
                'gross_arithmetic' => (string) $grossArithmetic->strippedOfTrailingZeros(),
                'component_sum_arithmetic' => (string) $partsArithmetic->strippedOfTrailingZeros(),
                'payment_coverage' => $total->isZero() && $credit->isPositive() ? 'covered_by_credit'
                    : ($credit->isPositive() ? 'partly_covered_by_credit' : 'amount_due'),
                'positive_cost' => true, 'unit' => null, 'currency' => null, 'unit_verified' => false,
                'product_id' => $productId, 'configuration_scope' => 'provider_defaults', 'configuration_verified' => false,
                'global_payment_fees_proven' => false];
        } catch (\Throwable) { return null; }
    }

    private function quoteDecimal(mixed $value): ?BigDecimal
    {
        if (is_float($value)) {
            if (! is_finite($value)) { return null; }
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,18})?\z/D', (string) $value)) { return null; }
        return BigDecimal::of((string) $value);
    }

    /** Decimal strings are exact. Only native floats may shed their binary representation tail. */
    private function quoteArithmeticDecimal(mixed $value, BigDecimal $raw): BigDecimal
    {
        if (! is_float($value)) { return $raw; }
        $rounded = $raw->toScale(8, RoundingMode::HalfUp);
        return $raw->minus($rounded)->abs()->isLessThanOrEqualTo('0.00000001') ? $rounded : $raw;
    }

    /** Optional unit evidence does not configure prices, import plans or verify every option. */
    private function moneyEvidence(?VpsTcMoneyContract $money, array $candidate, string $paymentMethod,
        array $calculated, ?string $accountCurrency): ?array
    {
        if ($money === null || $candidate['monthly_price_raw'] === null || $candidate['order_monthly_raw'] === null) { return null; }
        try {
            $evidence = $money->normalizeQuote($candidate['id'], $paymentMethod,
                ['price' => $candidate['order_monthly_raw'], 'setup' => $candidate['order_setup_raw']],
                ['price' => $candidate['monthly_price_raw'], 'setup' => $candidate['monthly_setup_raw']],
                $calculated, null);
            if (! is_array($evidence) || ($evidence['currency'] ?? null) !== 'TRY'
                || $accountCurrency !== $evidence['currency']
                || ! is_bool($evidence['configuration_verified'] ?? null)
                || ! preg_match('/\A[0-9a-f]{64}\z/D', $evidence['money_contract'] ?? '')
                || ! preg_match('/\A[0-9a-f]{64}\z/D', $evidence['calculation_sha256'] ?? '')
                || ! hash_equals($evidence['calculation_sha256'], hash('sha256', json_encode($calculated, JSON_THROW_ON_ERROR)))) { return null; }
            $amounts = [];
            foreach (['monthly_amount', 'setup_amount', 'total_amount', 'renewal_total_amount', 'multiplier', 'tax_percent'] as $key) {
                $number = $this->quoteDecimal($evidence[$key] ?? null);
                if ($number === null || ($key !== 'setup_amount' && $key !== 'tax_percent' && ! $number->isPositive())) { return null; }
                $amounts[$key] = (string) $number->strippedOfTrailingZeros();
            }
            if (BigDecimal::of($amounts['tax_percent'])->isGreaterThan(100)) { return null; }
            // This helper's reviewed contract explicitly binds USD API units
            // to a TRY account. A balance label alone cannot create this proof.
            return ['unit_verified' => true, 'currency' => 'TRY', 'base_currency' => 'USD', ...$amounts,
                'configuration_verified' => $evidence['configuration_verified'],
                'configuration_scope' => 'provider_defaults', 'applies_only_to_calculated_configuration' => true,
                'calculation_zero_extras_verified' => true, 'global_payment_fees_verified' => false,
                'funding_costs_verified' => false, 'all_os_prices_verified' => false,
                'contract_digest' => $evidence['money_contract'], 'calculation_sha256' => $evidence['calculation_sha256']];
        } catch (\Throwable) { return null; }
    }

    private function partial(array $data, int $count): bool
    {
        foreach (['next', 'next_page', 'has_more'] as $key) {
            if (isset($data[$key]) && ! in_array($data[$key], [false, 0, '0', ''], true)) { return true; }
        }
        if (isset($data['total'])) {
            return (! is_int($data['total']) && ! (is_string($data['total']) && ctype_digit($data['total'])))
                || (int) $data['total'] !== $count;
        }
        return false;
    }

    private function vpsPriority(array $candidate): int
    {
        return preg_match('/\b(?:vps|vds)\b/i', $candidate['category_name']) ? 0 : 1;
    }

    private function id(mixed $id): bool
    {
        return (is_string($id) || is_int($id)) && (bool) preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $id);
    }

    private function decimal(mixed $value, bool $zero): ?string
    {
        if ((! is_string($value) && ! is_int($value) && ! (is_float($value) && is_finite($value)))
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?\z/D', (string) $value)) { return null; }
        $number = BigDecimal::of((string) $value);
        return $zero || $number->isGreaterThan(0) ? (string) $number->strippedOfTrailingZeros() : null;
    }

    private function text(mixed $value, int $max = 160): string
    {
        return is_string($value) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', strip_tags($value)) ?? '', 0, $max) : '';
    }

    private function block(array &$report, string $code): void
    {
        if (! in_array($code, $report['blockers'], true)) { $report['blockers'][] = $code; }
    }

    private function truncate(array &$report, bool &$complete, string $code): void
    {
        $report['truncated'] = true; $complete = false; $this->block($report, $code);
    }
}
