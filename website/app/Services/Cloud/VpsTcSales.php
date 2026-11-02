<?php

namespace App\Services\Cloud;

use App\Models\Setting;

/** Cheap local sales gate; hiding new offers must never stop existing-service reconciliation. */
class VpsTcSales
{
    private const MANIFEST_MEMO = 'cloud.vpstc.reviewed-sales-manifest';

    public function enabled(): bool
    {
        $api = app(VpsTcApi::class);
        if (Setting::get('vpstc_sales_enabled') !== '1'
            || ! $api->isConfigured() || ! app(VpsTcPricing::class)->configured()) {
            return false;
        }

        // Every displayed row reaches this gate. Rechecking the complete
        // reviewed catalogue (including every approval hash) per row made the
        // storefront exceed its execution budget. Keep one local result per
        // request, bound to the exact manifest and account. Settings edits
        // invalidate it immediately; fresh procurement quotes are not cached.
        $raw = (string) Setting::get('vpstc_catalog_manifest', '');
        $account = $api->accountFingerprint();
        $app = app();
        $memo = $app->bound(self::MANIFEST_MEMO) ? $app->make(self::MANIFEST_MEMO) : null;
        if (is_array($memo) && ($memo['raw'] ?? null) === $raw
            && ($memo['account'] ?? null) === $account) {
            return $memo['valid'] === true;
        }

        $valid = app(VpsTcCatalog::class)->manifest() !== null;
        $app->instance(self::MANIFEST_MEMO, ['raw' => $raw, 'account' => $account, 'valid' => $valid]);

        return $valid;
    }
}
