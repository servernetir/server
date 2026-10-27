# VPS.tc monthly commercial launch — 27 October 2026

Base: `3613a1303e4177f4b53a13e273abee4e2b739b7f` on develop. The owner explicitly instructed launch while supplier ticket 154596 is pending, accepting the interim automatic-renewal/overage commercial risks rather than waiting for supplier clarification. This decision supersedes the earlier closed-sales acceptance requirement; it is not evidence that those supplier policies have been verified.

## Implementation

- `VpsTcCatalog` accepts a separate, account/product/configuration-bound `commercial_launch_approval` for the explicitly pending renewal, cancellation-billing and traffic-overage questions. It requires owner attribution, timestamp, support ticket and a fingerprint of the complete unchanged mapping. Default unknown policies without that approval still fail closed. Invalid resources, options, currency, prices, stock and unexpected fees remain rejected.
- Supplier facts remain unknown: `parent_cancel_stops_billing=null`, `network_terms.charge_mode=unconfirmed`, `extra_charges=null`. No hard-cap or free-overage assertion is fabricated. The customer allowance label is localized in fa/en/tr and requires arranging additional traffic with support.
- `VpsTcApi::serviceBilling()` permits only the exact observed `error=["management_offline"]` advisory alongside a service object for reading billing identity. `VpsTcMoneyContract` still independently checks the paid invoice, tax, multiplier, account and immutable service fingerprint. General `service()` and VM-readiness checks remain strict: an unavailable manager cannot be mistaken for delivered service.
- The administration partial no longer claims every registered catalog has verified supplier traffic terms.
- The existing automatic purchase/payment/reconciliation and customer delivery flow is retained. No manual fulfilment mode, arbitrary financial bypass, new scheduler or schema migration was introduced. Optional console/rebuild/backup/snapshot/resize/IP actions are not enabled by commercial approval. Locally paid renewals continue through the existing deduplicated renewal implementation; independent supplier auto-renew remains an explicitly accepted open question.

## Production activation

Five runtime files were installed with both shared release locks, expected-before SHA-256 checks, private source backups, staged PHP lint and after-hash verification. The only first-launch offer is the previously delivered and SSH-tested Istanbul product 351, 1 vCPU / 1,024 MB / 20 GB NVMe, Ubuntu 24.04, monthly only. Its account-bound form/options and selected configuration were freshly checked by the catalog quote; no second server, paid add-on, renewal, cancellation or deletion was submitted.

The owner approval and exact account/form/image bindings are stored in the catalog setting and a private encrypted launch receipt. Settings backup is private. Do not copy account IDs, fingerprints, credentials or raw supplier responses into Git.

The native admin catalog-sync action succeeded and then the native monthly-sales action succeeded. Independent readback observed `VpsTcSales::enabled()=true`, current VPS.tc pricing snapshot, one active/in-stock offer, and zero local VPS.tc instances/provider-operation rows. No customer checkout or paid production delivery was triggered by this launch verification.

Public route: `/cloud/tr-istanbul` (Turkey has no `/vps/turkey` marketing page in the country mapping). Persian page showed one CV-1-1 offer, 2,048 GB base traffic, the localized extra-traffic-by-arrangement label, and a monthly purchase link preserving the selected location/plan. Customer supplier branding is not exposed. Exact retail prices are live FX outputs rather than constants.

At launch, supplier service details carried `management_offline` despite returning billing metadata. This can delay automatic VM readiness/delivery; success is not inferred from the billing-only read or from an order acknowledgement. Existing strict delivery verification remains in force. The source adapter and fake regression suite do not prove a new paid retail end-to-end delivery during this supplier condition.

## Validation

Isolated PHP 8.4.27/SQLite/fake HTTP on the launch worktree:

```sh
php -d memory_limit=768M vendor/bin/phpunit --filter 'VpsTc|CloudOfferSelection|CloudDominance|InfrastructureSettingsUi' --log-junit <private-local-results.xml>
```

**271 tests passed, 2,337 assertions.** Coverage includes approval scope/account tampering, default unknown-policy rejection, unchanged quote protection, three-language unknown-traffic labels, exact billing-only advisory handling and rejection of mixed errors/failure flags. Existing lifecycle duplicate-order/credit checks continue to pass. PHP lint and whitespace validation passed. No claims of live cancellation, paid renewal, optional capabilities or new paid customer delivery are made.

## Follow-up and rollback

Resolve supplier ticket 154596: default/API auto-renew Off, Credit Balance auto-charge behavior, overage/cap terms, correct optional endpoints, included operation charges and reseller console. Replace commercial unknown-policy metadata only after factual evidence is obtained. Do not enable optional actions by treating the launch decision as operation-cost evidence.

To stop new orders, use the existing admin sales switch first. Preserve all purchased services and operation journals. Before restoring source or the previous catalog/snapshot settings, check hashes and determine whether any customer service was sold after launch; do not restore data wholesale or orphan new service/plan records. Reverting source alone must not delete or cancel supplier resources. Earlier closed-sales records remain historical and are superseded by this explicit launch decision.
