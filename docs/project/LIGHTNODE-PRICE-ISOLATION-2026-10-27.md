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

**Historical preparation state (superseded by the production continuation below): prepared/tested, not installed at that stage.** The Browser connector returned “Browser access requires the originating browser context”; a direct unauthenticated cPanel connectivity attempt also timed out. No live LightNode price, active offer count, sale enablement or new VPS.tc supplier assurance is claimed. No live setting, Proxmox, OVH, service, invoice or wallet mutation occurred in this work.

Deployment must preserve current production overlays and before hashes. Install compatible CloudPricingSnapshot/LightNode classes/CloudPlan before the repricer that publishes the new field. Under the shared release/catalog locks, capture current margin/FX fee, valid Navasan rates, supplier flags and snapshot; invoke the isolated LightNode refresh. Read back actual verified versus quarantined offer counts and displayed customer prices, check the canonical Istanbul page and checkout selection without creating an order. Enable the existing supplier sales switch only after at least one correctly priced in-stock offer is verified and configuration permits sales. Keep Proxmox closed and defer OVH.

Rollback must not restore an older CloudPlan selector while a partially verified supplier is enabled: that older selector ignores `excluded_plans`. First close LightNode sales or exclude it from the snapshot, then restore this release's source backups with concurrency checks. Preserve all original products, services and operation journals; do not reset production data or cancel resources.

## Production continuation — 27 October, 11:19 UTC

The owner explicitly authorized trying alternative connection methods. Direct cPanel API authentication succeeded using the previously authorized account token. Fresh exact-byte reads matched the five runtime files at base `21cec07`; both catalog-lock dependencies already matched. No concurrent source overlays were overwritten.

The five runtime files from develop `fe88498f198b91cf7770bd339f2ae91e792dc5cf` were installed at 11:19:01 UTC under both shared release locks, with expected-before and after SHA-256 checks, private backups, PHP lint and atomic replacement. No schema, migration, supplier credential or public execution endpoint was introduced. A private CLI-only, one-shot guarded task was started through the existing cPanel Cron API; its temporary cron entry was removed and absence verified after start. Existing unrelated cron entries were preserved.

Fresh preflight: LightNode account connection succeeded (38 regions), 381 active plans, zero sellable plans, globally current FX snapshot excluding only LightNode. A native exact existing-product quote succeeded. Configured LightNode margin is **5%**, provider funding fee **3%**; VPS.tc's separately configured 40% margin was not copied globally. Proxmox and OVH remain administratively disabled.

Provider-only price refresh started at 11:20:02 UTC. This refresh completed successfully at **11:26:33 UTC**; acceptance was read back at **11:28 UTC**. No purchase, renewal, wallet payment, VM creation or deletion was submitted.

### Production acceptance result

- **341 LightNode plans are sellable across 34 stored city codes**, with a current provider FX snapshot. 341 existing plan prices were updated atomically; 40 unverified plan IDs remain quarantined. The supplier is no longer globally excluded. Existing supplier/configuration flags already permitted sales; no bypass was added.
- SHA-256 of all other suppliers' IDs/costs/retail prices/active/stock fields is unchanged before versus after this provider-only refresh. VPS.tc retains one sellable monthly plan; Proxmox and OVH retain zero.
- Quarantined rows: Bogota, Tokyo, Seoul II and Istanbul II, ten each. An exact Istanbul retry returned zero quotes and two controlled `linux_region_unavailable` diagnostics. This is not evidence of a valid current Istanbul LightNode offer; do not invent its cost or force it in stock. The shared canonical Istanbul selection currently contains the VPS.tc monthly offer at 1,460,000 toman; no separate LightNode Istanbul page was introduced.
- A fresh account read succeeded and the existing infrastructure connection evidence was recorded: LightNode admin status is `active` / “متصل و فعال”. This proves current connection/pricing availability, not a newly paid VM delivery.
- The public Buenos Aires page was opened in the cloud browser and showed ten plans with monthly/hourly purchase links and fresh prices. Example 1 vCPU / 2 GB RAM / 50 GB boot + 50 GB data / fixed 2 Mbps: 6,260,000 toman/month and 8,400 toman/hour. Cheapest stored verified LightNode offer is Manila, 1 vCPU / 2 GB RAM / 50 GB disk, 3,910,000 toman/month; applicable checkout taxes remain separate. A screenshot of actual public cards was captured.
- No new test suite run was necessary for this deployment of the unchanged, previously validated tree: the 322 tests / 2,702 assertions above remain the recorded executed validation. No new paid provisioning, renewal, cancellation, credit payment or customer session mutation was performed.
- All three temporary guarded-task cron entries (audit, installer, repricer) were removed after start; the final acceptance entry was also removed and absence checked. Existing unrelated cron entries were preserved. Private CLI-only scripts/receipts and source backups remain outside the web root.

Rollback remains as described above: close/exclude LightNode before restoring an older selector that ignores per-plan quarantine. Preserve service and operation records.
