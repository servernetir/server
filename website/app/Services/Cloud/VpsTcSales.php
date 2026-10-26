<?php

namespace App\Services\Cloud;

use App\Models\Setting;

/** Cheap local sales gate; hiding new offers must never stop existing-service reconciliation. */
class VpsTcSales
{
    public function enabled(): bool
    {
        return Setting::get('vpstc_sales_enabled') === '1'
            && app(VpsTcApi::class)->isConfigured()
            && app(VpsTcPricing::class)->configured()
            && app(VpsTcCatalog::class)->manifest() !== null;
    }
}
