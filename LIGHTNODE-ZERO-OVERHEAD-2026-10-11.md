# LightNode provider overhead change — 2026-10-11

Owner instruction: set only LightNode funding overhead to zero, retain 5% profit, refresh and test every LightNode plan, publish to the website and `develop`, and preserve other providers' prices.

## Scope and persistence

The existing production implementation supports an explicit zero override (PR45). The confirmed persistent setting is `pricing_fx_fee_pct_lightnode=0`; the global `pricing_fx_fee_pct=21` and `cloud_margin_pct=5` remain unchanged. No new runtime deployment is required for this configuration change. Subsequent LightNode catalog and purchase quotes read the provider override through `CloudPricing::fxFeePctFor()`.

The LightNode supplier settles in USD and currently charges no VAT/sales tax. Zero overhead is the owner's pricing decision; it is not evidence that all possible funding/payment charges are zero. Customer invoice tax and other providers' funding settings are outside this change.

## Refresh and isolation

Only official OpenAPI resource/catalog reads and the existing anonymous official public calculator transport are used. Fixed-bandwidth `PayByBandwidth` offers remain the sole enabled network model. All valid package/series/disk/network matches and Windows/Linux price parity are revalidated by the production adapter; no manual price fallback or undocumented account-panel endpoint is used.

The first private execution (`lightnode-zero-overhead-20261011-01`) validated the regional catalog but stopped before any financial mutation because FX changed during the long fetch. The revised execution captures original USD calculator amounts in process, then converts every accepted plan with the same fresh USD/EUR snapshot at publication. The validated same-run calculator snapshot is consumed within one hour; purchase-time quotes remain fresh. Four previously active supplier offers had inconsistent subsequent quote availability. Direct re-reads briefly passed, then bounded retries failed again; those four offers are excluded by the existing importer rather than priced from old rounded costs. The full regional snapshot supplied 396 valid offers, including 11 new supplier variants. This avoids mixing conversion dates or estimating new prices by dividing old rounded costs by 1.21.

The final private execution directory is `/home/servernetcloud/lightnode-zero-overhead-20261011-05`, outside the web root and mode0700. Before mutation, a private snapshot preserves LightNode rows and the prior provider setting. A short DB transaction guards settings and FX, imports only LightNode plans through the existing catalog importer, verifies every cost/sale/stock readback, and compares every other provider's financial row before and after. It avoids the global reprice command, other-provider synchronization and financial notifications. Settings, menu and country caches are invalidated after commit.

Hourly pricing retains the existing upward rounding and 5% profit on actual hourly supplier cost. Rolling monthly commitments retain the existing 744-hour reserve; the supplier's 672-hour calendar-month cap cannot be assumed for every rolling customer month. This reserve and compatible-image rules are unchanged.

## Validation

The regression batch passed **226 tests / 1723 assertions**. The added integration case exercises the actual LightNode catalog adapter and importer with fresh calculator fixtures: global21% → explicit LightNode0%, profit5%, lower hourly price, unchanged complete competing-provider row, unchanged global settings, and no lifecycle mutation. Existing tests cover explicit-zero fallback semantics, period floors, stock/image gates, store and hourly pages, and delivery/lifecycle safety.

The live transaction completed at **09:26:04 UTC**: **396 verified in-stock plans / 40 regions / 37 countries**, 385 existing valid plans repriced and 11 new variants imported. Four unverified old supplier offers were deactivated; their historical records and purchased-service rates remain intact. All **3303** competing-provider financial rows matched exactly before and after. Comparison SHA256: `e122ecfa6162c74b0f4df1da465cd8b95a7d7591057175459abe38f5f257916b`. Global21% and profit5% were unchanged. The new zero provider setting was read back through the actual production pricing service.

Publication FX was **EUR299600 IRT / USD267500 IRT**. Example Manila Linux1CPU/2GB/50GB boot/2Mbps: **4900 IRT/hour**, saved rolling monthly price **3660000 IRT** (previous saved monthly4420000). Receipt-proven Windows-compatible2CPU/4GB/50GB boot+100GB data/5Mbps: **16400 IRT/hour**, saved monthly **12200000 IRT** (previous14700000). The old stored hourly costs evaluated at publication FX yielded6000/19900; the earlier public snapshot showed5900 for the lowest Manila plan. These are timestamped observations, not frozen FX/rates.

The live hourly page now shows **4900 IRT/hour** for the Philippines and all40 country rows. Its720-hour usage illustration is3528000 IRT, separate from a reserved rolling monthly commitment. Mobile390px still has no horizontal overflow. At **09:29:32 UTC**, independent production readback reverified every396 active plan's saved price and hourly5% floor, plus **1584 cycle quotes** against supplier cost/profit. All3303 competing-provider rows matched the pre-change snapshot again. Six isolated empty-session production controller renders (Linux1CPU/2GB and Windows-compatible2CPU/4GB ×FA/EN/TR) verified selected plan, hourly price, Linux availability, Windows compatibility and absence of raw supplier references. Fresh independent calculator quotes returned **USD0.017418/hour** and **USD0.058306/hour**; Windows/Linux quote parity passed for the supported2CPU/4GB package. Windows remained unavailable on1CPU/2GB.

Evidence: `output/lightnode-zero-overhead-20261011/{completed.json,verified.json,related-tests.xml,live-price-390.png}`. The private controller renders contain no customer session or order; CSRF values were removed. The previous real Linux SSH/Windows RDP acceptance remains documented in LIGHTNODE-INTEGRATION-2026-10-10.md; no new real guest test or financial transaction was performed for this setting change.

## Impact and rollback

Affected machine: `core`, existing Laravel application DB (`settings` and LightNode `cloud_plans`) plus application caches. No existing guest, purchased service rate, wallet, invoice, route, NAT/firewall, DNS, cron, provider credential or periodic country-routing/port-forward source is modified. No paid test guest is necessary for a price-setting change.

For rollback, inspect the current provider setting, fresh catalog/FX and the private pre-change receipt first. Restore the previous provider value (null, inheriting21%) through `Setting::put`, then validate and import only a fresh LightNode catalog at that fee. Verify all competing-provider price rows again and invalidate the application caches. Alternatively, restore only backed-up LightNode financial fields when their current values exactly match the installation receipt and FX is still applicable. Preserve subsequent catalog/admin changes and existing customer rates; never restore the entire DB or blindly rerun a completed helper. Reverting a Git documentation/test commit does not roll back a live DB setting.
