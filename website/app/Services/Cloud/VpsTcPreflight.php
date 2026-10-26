<?php

namespace App\Services\Cloud;

use Brick\Math\BigDecimal;

/** Read-only discovery. Native observations never authorize import, sales or paid lifecycle calls. */
class VpsTcPreflight
{
    private const MAX_CATEGORY_NODES = 64;
    private const MAX_CATEGORY_DEPTH = 6;
    private const MAX_PRODUCT_READS = 16;
    private const MAX_PRODUCT_ROWS = 512;
    private const MAX_PREVIEWS = 128;
    private const MAX_ORDER_READS = 3;

    public function __construct(private VpsTcApi $api) {}

    public function inspect(?string $productId = null, ?string $categoryId = null): array
    {
        $report = [
            'ok' => false, 'connection_ok' => false, 'sales_ready' => false,
            'checked_at' => now()->toIso8601String(), 'currency' => null,
            'categories_seen' => null, 'leaf_categories_seen' => 0, 'categories_inspected' => 0,
            'products_seen' => 0, 'monthly_offers_seen' => 0, 'fixed_monthly_candidates_seen' => 0,
            'metered_candidates_seen' => 0, 'unknown_paytype_candidates_seen' => 0,
            'order_form_checked' => false, 'order_forms_checked' => 0,
            'payment_methods_seen' => 0, 'payment_fees_verified' => false,
            'catalog_complete' => false, 'discovery_complete' => false, 'truncated' => false,
            'categories' => [], 'candidates' => [],
            'limits' => ['category_nodes' => self::MAX_CATEGORY_NODES, 'category_depth' => self::MAX_CATEGORY_DEPTH,
                'product_reads' => self::MAX_PRODUCT_READS, 'product_rows' => self::MAX_PRODUCT_ROWS,
                'previews' => self::MAX_PREVIEWS, 'order_form_reads' => self::MAX_ORDER_READS],
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
        if (is_string($currency) && preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            $report['currency'] = $currency;
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
        if ($productId !== null) {
            if (! isset($records[$productId])) {
                $records[$productId] = $this->candidate(['id' => $productId], $categoryId, '');
                $records[$productId]['issues'][] = 'product_list_match_not_found';
                $this->block($report, 'product_list_match_not_found');
            }
            $orderIds = [$productId];
        } else {
            $priority = array_values($records);
            usort($priority, fn ($a, $b) => $this->vpsPriority($a) <=> $this->vpsPriority($b));
            $seenCategories = [];
            foreach ($priority as $candidate) {
                if ($candidate['monthly_price_raw'] === null || $candidate['paytype'] !== 'Regular'
                    || isset($seenCategories[$candidate['category_id']])) { continue; }
                $seenCategories[$candidate['category_id']] = true;
                $orderIds[] = $candidate['id'];
                if (count($orderIds) >= self::MAX_ORDER_READS) { break; }
            }
        }
        foreach ($orderIds as $id) {
            $candidate = $records[$id];
            $form = $this->api->productOrderForm($id);
            $product = $form['data']['product'] ?? null;
            if (! ($form['ok'] ?? false) || ! is_array($product) || ! is_array($product['config']['forms'] ?? null)) {
                $complete = false; $this->block($report, 'order_form_schema_not_verified');
                $candidate['issues'][] = 'order_form_schema_not_verified';
            } else {
                $report['order_form_checked'] = true; $report['order_forms_checked']++;
                $candidate['order_checked'] = true;
                $this->compareOrder($candidate, $product, $report);
            }
            $records[$id] = $candidate;
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
            'fixed_monthly_candidates_seen' => 0, 'metered_candidates_seen' => 0];
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
