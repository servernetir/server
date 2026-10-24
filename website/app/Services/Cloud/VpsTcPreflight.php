<?php

namespace App\Services\Cloud;

/** Read-only evidence, deliberately independent of customer provisioning. */
class VpsTcPreflight
{
    public function __construct(private VpsTcApi $api) {}

    public function inspect(?string $productId = null, ?string $categoryId = null): array
    {
        $report = [
            'ok' => false,
            'connection_ok' => false,
            'sales_ready' => false,
            'checked_at' => now()->toIso8601String(),
            'currency' => null,
            'categories_seen' => null,
            'products_seen' => null,
            'monthly_offers_seen' => null,
            'order_form_checked' => false,
            'catalog_complete' => false,
            'blockers' => [
                'provider_lifecycle_adapter_not_implemented',
                'catalog_mapping_not_verified',
                'traffic_cost_control_not_verified',
                'live_provisioning_not_verified',
                'order_reconciliation_not_implemented',
                'renewal_and_billing_cancellation_not_verified',
            ],
            'message' => 'Read-only preflight has not completed; customer sales remain unavailable.',
        ];

        foreach ([$productId, $categoryId] as $id) {
            if ($id !== null && ! preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
                $report['blockers'][] = 'invalid_resource_id';

                return $report;
            }
        }

        if (! $this->api->isConfigured()) {
            $report['blockers'][] = 'credentials_not_configured';

            return $report;
        }

        $balance = $this->api->balance();
        if (! ($balance['ok'] ?? false)) {
            $report['blockers'][] = 'account_read_failed';

            return $report;
        }
        $details = $balance['data']['details'] ?? null;
        if (! is_array($details) || ! is_numeric($details['acc_credit'] ?? null) || ! is_numeric($details['acc_balance'] ?? null)) {
            $report['blockers'][] = 'account_read_failed';

            return $report;
        }
        $report['connection_ok'] = true;
        $currency = $balance['data']['details']['currency'] ?? null;
        if (is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency)) {
            $report['currency'] = $currency;
        } else {
            $report['blockers'][] = 'account_currency_not_verified';
        }

        $categories = $this->api->categories();
        if (! ($categories['ok'] ?? false)) {
            $report['blockers'][] = 'category_read_failed';

            return $report;
        }
        $items = $categories['data']['categories'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            $report['blockers'][] = 'category_schema_not_verified';

            return $report;
        }
        $report['categories_seen'] = count($items);

        if ($categoryId !== null) {
            $products = $this->api->products($categoryId);
            $items = $products['data']['products'] ?? null;
            if (! ($products['ok'] ?? false) || ! is_array($items) || ! array_is_list($items)) {
                $report['blockers'][] = 'product_read_failed';

                return $report;
            }
            $report['products_seen'] = count($items);
            $report['monthly_offers_seen'] = 0;
            foreach ($items as $product) {
                foreach ((array) (is_array($product) ? ($product['periods'] ?? []) : []) as $period) {
                    if (is_array($period) && ($period['value'] ?? null) === 'm' && is_numeric($period['price'] ?? null) && is_finite((float) $period['price']) && (float) $period['price'] > 0) {
                        $report['monthly_offers_seen']++;
                        break;
                    }
                }
            }
        }

        if ($productId !== null) {
            $form = $this->api->productOrderForm($productId);
            $forms = $form['data']['product']['config']['forms'] ?? null;
            if (! ($form['ok'] ?? false) || ! is_array($forms)) {
                $report['blockers'][] = 'order_form_schema_not_verified';

                return $report;
            }
            $report['order_form_checked'] = true;
        }

        $report['ok'] = true;
        $report['message'] = 'Read-only account/catalog requests succeeded. This is not sales or paid lifecycle acceptance.';

        return $report;
    }
}
