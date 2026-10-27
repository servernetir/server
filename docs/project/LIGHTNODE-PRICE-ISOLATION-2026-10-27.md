# LightNode verified-price publication — 27 October 2026

Base develop: `21cec0747a2b4f20f1e1dbd679e091bbdf8a7f5f`. Owner explicitly requested current LightNode prices with existing profit settings and reopening sales; Proxmox is out of sales scope, OVH follows completion of VPS.tc. No approval for additional paid test resources is inferred.

## Confirmed application causes

The current repricer excludes the whole LightNode supplier if even one active historical product is absent from both the fresh catalog and exact retry. That rejects independently verified sibling quotes. The prior production investigation found 381 active rows, valid account authentication and a LightNode-excluded FX snapshot; its global refresh was stopped after 14 minutes without an observed publication. Missing authentication/complete pricing is not proof of an upstream tariff change. The exact live rejected SKU/reason remains unverified.

## Implementation

- A verified LightNode quote can now publish independently. Missing quotes retain every previous product/cost/stock/admin attribute but their IDs enter the snapshot's `excluded_plans` list. `CloudPlan::sellable`, default public offers and checkout reject those IDs; the shelf marks them as waiting for price. A missing cheaper row cannot win a shared slug or hide a verified sibling. If no active LightNode price is verified, the supplier remains excluded.
- A successful later refresh clears recovered exclusions. Snapshot publication by another supplier (including VPS.tc margin changes) preserves unrelated plan exclusions by default. Invalid exclusion schema fails the global snapshot closed. Legacy snapshots without the field remain compatible.
- `CloudCatalogRepricer::runForProvider('lightnode')` obtains the shared catalog lock and refreshes only LightNode. It requires a current global FX snapshot, preserves other suppliers' stored prices/exclusions and valid VPS.tc input proof, and retains settings/rates/product concurrency checks and transactional publication. Existing global refresh paths remain supported. This method neither enables an administratively disabled supplier nor purchases any resource.
- Procurement quotes continue through the official public calculator. Stored/manual tariff fixtures are never promoted into production pricing. Existing `CloudPricing` applies configured funding costs and `cloud_margin_pct`; no new hardcoded margin, exchange rate or fee is introduced.
- The same calculator instance is reused for a client batch. Configuration trees share an absolute maximum 60-second cache lifetime across memory/shared cache. Monetary quotes are never cached; checkout/purchase calls bypass configuration caching.
- Rejected quotes expose only controlled internal reason codes (region, CPU/RAM, disks, network, bootstrap, response, transport). Catalog diagnostics count failures without copying vendor bodies, credentials or exception messages. Linux/Windows price parity, package/resource identity and fixed-bandwidth constraints remain enforced.

## Istanbul policy

The existing `CloudCountry`/`CloudDominance` path used by Germany compares customer-facing prices and compatible contracts. The prior Istanbul release applies that shared selection to the public city page and preserves supplier identities for provisioning. Both stored city codes are queried together; `/cloud/tr-istanbul-ii` redirects to `/cloud/tr-istanbul` in fa/en/tr. Customer city selection and canonical sitemap links are unified. Distinct advertised resources/contracts are not silently represented as equivalent; the cheapest verified equivalent is shown. Existing customer services and bookmarked plan identities remain valid.

## Validation

Isolated PHP 8.4/SQLite, fake HTTP, no live credentials:

```
--filter 'LightNodePartialRepricing|LightNodePublicPricing|VpsTc|CloudCatalogRepricing|CloudOfferSelection|CloudDominance|InfrastructureSettingsUi|CatalogRefreshRequests'
```

**322 tests passed, 2,702 assertions.** Includes partial/exact retry failures, recovery, checkout/shelf quarantine, provider-only preservation, malformed IDs, calculator reason codes, uncached purchase pricing, VPS.tc and Istanbul regression coverage. PHP lint and whitespace checks passed.

A broader historical LightNode suite has **20 failures and 9 errors** reproduced in a separate untouched 21cec07 baseline (including outdated fixture/gate/driver-order expectations). Those are not counted as successful acceptance or silently skipped.

## Deployment status and remaining acceptance

**Prepared/tested; not installed by this change.** The Browser connector returned “Browser access requires the originating browser context”; a direct unauthenticated cPanel connectivity attempt also timed out. No live LightNode price, active offer count, sale enablement or new VPS.tc supplier assurance is claimed. No live setting, Proxmox, OVH, service, invoice or wallet mutation occurred in this work.

Deployment must preserve current production overlays and before hashes. Install compatible CloudPricingSnapshot/LightNode classes/CloudPlan before the repricer that publishes the new field. Under the shared release/catalog locks, capture current margin/FX fee, valid Navasan rates, supplier flags and snapshot; invoke the isolated LightNode refresh. Read back actual verified versus quarantined offer counts and displayed customer prices, check the canonical Istanbul page and checkout selection without creating an order. Enable the existing supplier sales switch only after at least one correctly priced in-stock offer is verified and configuration permits sales. Keep Proxmox closed and defer OVH.

Rollback must not restore an older CloudPlan selector while a partially verified supplier is enabled: that older selector ignores `excluded_plans`. First close LightNode sales or exclude it from the snapshot, then restore this release's source backups with concurrency checks. Preserve all original products, services and operation journals; do not reset production data or cancel resources.
