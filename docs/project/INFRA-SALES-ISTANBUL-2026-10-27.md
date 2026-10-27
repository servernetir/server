# Infrastructure sales and Istanbul consolidation — 27 October 2026

Base develop: `b109e11`. Owner requested automatic publication after changing VPS.tc profit to 40%, one infrastructure settings surface, genuine provider checks, and one shared Istanbul sales page.

## Changes

- `/admin/cloud/vpstc` redirects to `/admin/settings?tab=infra#infra-vpstc`. POST endpoints keep administrator/CSRF/throttle protection and return to that fixed destination. Existing inline forms remain independent from the general settings form.
- An enabled VPS.tc supplier saves pricing and publishes verified prices in one transaction. A margin-only edit with a current cost snapshot reuses that verified cost without contacting the supplier. Input and snapshot rows are locked and re-read; the shared catalog lock serializes margin publication. Procurement-input edits require fresh catalog evidence. Failure rolls settings and product prices back, clears both settings caches and preserves the previous sales flag. Initial setup still requires catalog review and explicit sales activation.
- Public city alias `tr-istanbul-ii` resolves to `tr-istanbul`; all three languages receive a 301. Public offers and the customer store include both underlying location codes. Provider region IDs, saved service/plan references and old order slugs are preserved. Country links, the city tree and sitemap use the canonical city. Comparable offers use customer prices; distinct OS, licence, network, billing and capability contracts remain distinct.
- Status now distinguishes an authenticated supplier from one excluded by the pricing snapshot. OVH's manual-order implementation and Proxmox delivery prerequisites are explicitly described instead of suggesting that authentication proves automatic sales readiness.

## Validation and production

Isolated PHP 8.4/SQLite with fake external requests: `--filter 'VpsTc|CloudOfferSelection|CloudDominance|InfrastructureSettingsUi|CloudCatalogTest|CloudLocationCity|CloudCatalogRepricing|CatalogRefreshRequests'`: **357 passed, 2,724 assertions**. Includes 40% margin publication with no new HTTP requests, rollback on procurement refresh failure, three-language city redirects and preservation of original supplier identities. PHP lint and whitespace validation passed.

Seven failures in the broader CloudCountryCardTest/CloudStoreLocationTest legacy UI suite were reproduced unchanged in a separate untouched b109e11 baseline. Their markup assertions predate the current storefront and are not counted as passing.

Production: the existing VPS.tc 40% setting was preserved and its verified catalog republished: one monthly in-stock offer, IRT1,460,000 before customer tax. Eleven runtime files were installed under both shared release locks, before/after SHA checks, private backups and staged PHP lint. Existing production overlays in Account/CloudStoreController, CloudCatalogController, SiteController and admin/cloud were preserved with only this change applied. Those unrelated overlays are not imported into this Git change. CatalogRefreshRequests and CatalogRefreshBusy from the base commit were absent on the host; their exact base versions were installed as required dependencies. No migration, new order, renewal, deletion, paid API action or credential change occurred.

Actual account connection reads succeeded for Hetzner, Aeza, Arvan, OVH, Proxmox, Salad, Robot and LightNode. A subsequent VPS.tc connection read also succeeded. Existing sellable counts observed: Hetzner62, Aeza96, Arvan32, Salad54, Robot204, VPS.tc1. Connection evidence is credential-bound and expires after 24 hours.

## Final acceptance

The installed pricing controller was invoked on production with the owner's unchanged 40% values: success flash confirmed publication, no validation errors, current supplier snapshot, one in-stock sellable plan at IRT1,460,000 / EUR4.70. A production application GET confirmed the old Istanbul URL returns 301 to the canonical city. Direct production controller and Blade rendering completed successfully. Browser re-navigation to both public and console hosts later returned network timeouts, so a fresh external browser success is not claimed.

Profiling also found repeated self-comparisons in offer selection. These unnecessarily built OS/capability evidence for every single-row offer; skipping the self-comparison preserves all cross-offer checks and removes that redundant catalog work.

## Remaining provider limitations

- LightNode is authenticated but excluded from the current pricing snapshot. Its 381 saved active offers must not be reported as sellable merely because its region endpoint succeeds. A global read-only reprice was stopped after 14 minutes before publication; no successful price batch was observed. Previous cost/inventory records were preserved. This release does not claim LightNode sales were reopened.
- OVH is administratively disabled and `OvhClient::createServer()` still explicitly returns manual fulfilment. Enabling its switch would not implement automated ordering. This release does not enable it as an automatic supplier.
- Proxmox is an Exit VPS product, administratively disabled. Template9002 was read successfully with `template=1` and cloud-init, but public routing, pool/storage capacity and country-exit delivery remain unverified. No customer VM was cloned or enabled for sale.
- VPS.tc supplier renewal/overage questions remain pending ticket154596; this change does not invent new provider assurances.

Rollback: stop new VPS.tc sales through its existing switch if necessary, then restore only this release's source backups after checking hashes. Preserve all services, operation journals and unrelated overlays; restoring prior pricing settings requires evaluating orders placed after publication. No blanket data restore or resource deletion.
