# OVHcloud automation — recovery checkpoint, 2026-10-25

Status: IMPLEMENTED IN AN ISOLATED LOCAL WORKTREE, NOT COMMITTED OR PUSHED, NOT PRODUCTION-READY.

The selected Windows execution environment became unavailable before final verification and Git publication. This file is a recovery record, not a delivery or deployment claim. Its commit contains documentation only. Do not infer that develop includes the implementation.

## Scope and authorization

The owner authorized implementation, offline tests and publication to develop after successful verification. No paid OVH purchase, production deployment, production migration or activation was authorized. All monetary operations in the new configuration default to off.

The original shared checkout had extensive unrelated modifications and a stale local develop. An isolated branch was created from origin/develop at bfb6d058803a9a894c6d6d2f17d86744b5437c5d, named codex/ovh-automation-20261025. The original checkout was not overwritten. An origin/develop fetch later found 11 incoming commits; these had not yet been merged into the implementation branch.

## Verified root cause

The deployed OvhClient.php was read through the existing cPanel Fileman API. Its createServer() still returned the constant manual=true/ok=false response without submitting an OVH purchase.

The previous provisioning path did not handle manual=true before its failure branch. Retry could not enable the absent purchase implementation.

## Implementation written locally

New files:

- website/config/ovh.php
- website/database/migrations/2026_10_25_163000_create_ovh_orders_table.php
- website/app/Models/OvhOrder.php
- website/app/Models/OvhOrderEvent.php
- website/app/Services/Cloud/OvhOrders.php
- website/app/Services/Cloud/OvhOrderFinance.php
- website/app/Services/Cloud/OvhOrderHold.php
- website/app/Http/Controllers/Admin/OvhOrderController.php
- website/resources/views/admin/ovh-orders.blade.php
- website/app/Console/Commands/OvhOrderStatus.php
- website/tests/Feature/OvhOrderFlowTest.php
- website/tests/Feature/OvhLeaseConcurrencyTest.php
- website/tests/Support/ovh-lease-worker.php

Existing modules modified locally:

- OvhClient: signed order transport reuse, exact account/catalogue/resource/configuration validation, real createServer delegation, public hardware parsing.
- CloudProvisioner: OVH-specific durable order handling, explicit manual-result handling, deferred activation until access readiness, pending/busy race protection.
- RunProvisioning: persisted next-attempt backoff filtering.
- CloudPlan, CloudImage, CloudPricing, CloudCatalogSync, CloudCatalogRepricer: default-off sale gate, supported Linux images, separate margin, catalogue margin fingerprint.
- CloudStoreController: read-only live product/cost review and monthly-only gate before invoice/payment.
- CloudServerController and customer view: localized order states and owner-only, CSRF-protected, single-use SSH private-key download.
- CloudInstance: access-readiness gate and correct SSH username/key filename.
- UndeliveredRefund: no automatic refund while a supplier transaction is uncertain.
- Admin ServiceController, admin cloud view and routes: safe recovery dashboard and generic retry redirect.
- .env.example and fa/en/tr strings.
- OvhClientTest and OvhCatalogTest: prevent stray legacy HTTP calls and replace retired FX override fixtures with valid stored Navasan evidence.

The new journal is additive, keeps one row per local service and unique cart/order/server references, persists monetary intent before POST, and uses a conditional database lease. A two-process test exercises the actual lease method against a shared synthetic SQLite file.

## Implemented workflow

Paid monthly service -> exact plan/account/OS/hardware validation -> persistent cart and item -> required configuration and readback -> final tax-inclusive quote -> paid-invoice/FX/fees/margin/cap/default-payment checks -> persist checkout intent -> checkout -> persist external order -> verify external amount again -> persist payment intent -> pay once -> poll official order status -> resolve the exact server -> install the saved public SSH key on the newly purchased server once -> verify install task/current image/running state/public IPv4 -> activate and notify through the existing service workflow.

Existing customer invoices and wallet accounting are reused. Provisioning does not debit a customer wallet again.

A generated private key is encrypted in the database and never included in JSON, events or notifications. Its download is an explicit owner-only POST that clears the encrypted stored value after first retrieval.

Uncertain checkout/payment/install outcomes never cause a second monetary POST or rebuild. Exact cart recovery is attempted if OVH exposes the order ID for that persisted cart. If the API cannot prove the correlation, review is required. There is an audited EU/CA manual link operation, with amount/configuration checks and explicit cart attestation.

Admin actions include read-only reconciliation, safe pre-purchase retry, bounded-polling resume, pause, and an explicitly attested cancellation/refund path. Refund uses the existing UndeliveredRefund service under locks; a known order/payment/server is excluded from that path.

## Supported and intentionally restricted

- Initial supplier purchase is monthly. Nonmonthly/hourly OVH ordering is rejected before customer payment.
- Standard Ubuntu/Debian/AlmaLinux/Rocky OS images; no Windows or app/addon purchase is advertised as supported.
- EUR and USD financial conversion uses stored valid Navasan rates. Unsupported account currencies stop safely.
- EU/CA order details use extension metadata. US resolution uses order-detail operations.
- Customer rebuild is disabled until a separate key-preserving rebuild flow is verified. Initial installation on a newly purchased VPS is implemented.
- A default payment method does not prove available spendable funds; the UI does not fabricate a balance.
- Profit shown is an estimate from known supplier quote/tax, configured transfer fee and stored FX. A supplier bill/bank settlement was not reconciled, so actual settled profit is not claimed.
- Real-account API compatibility, real delivery, actual SSH login, ongoing supplier renewal policy and MariaDB concurrency still require acceptance checks.

## Tests actually observed

1. An offline OvhClientTest run completed successfully: 18 tests, shell exit 0.
2. An offline new workflow + real two-process lease run completed successfully: 23 tests, 127 assertions.
3. Additional financial, UI, restart, timeout and dry-run tests were added and run with the existing OVH suites. The most recent fully collected combined result was 73 tests, 284 assertions, one failure. That failure was an old missing-FX test still clearing retired manual override settings; its fixture was corrected to clear the actual stored Navasan rate.
4. A subsequent broader provisioning/refund/interface regression run was started, but its final result was not collected before the environment disappeared. It must not be reported as passing.
5. PHP lint and git diff --check had passed at checkpoints. Later changes require a fresh final run.
6. An isolated artisan view:cache build was started. Its completion was not collected.
7. The portable Windows PHP runtime needed its bundled OpenSSL configuration to generate test keys. This was supplied through the test process environment, not hardcoded in production code.
8. No real OVH checkout/payment/rebuild, live customer charge/refund, deployment helper or production migration was performed.

## Live state and previous test order

Existing cPanel authentication allowed read-only source inspection. The deployed constant response was confirmed.

The existing CSRF-protected /system/cloud-status diagnostic returned HTTP 200 after a normal CSRF cookie exchange. Its ten-row queue did not identify the owner's OVH test order. A bounded tracker read did not identify it either.

The exact previous service/invoice, payment/refund status, external OVH order and server allocation therefore remain UNVERIFIED. No absence of an external order or refund is inferred from the stub function alone. The new activation gate requires an explicit recorded check of that prior order before purchases can be enabled.

Native/browser computer control was also unavailable: its helper could not launch. No authentication/session barrier was bypassed, and no new production access key or diagnostic helper was installed.

## Recovery steps

1. Reconnect the previous Windows execution environment and locate the existing codex/ovh-automation-20261025 worktree. Do not rebuild from memory or overwrite the shared original checkout.
2. Collect process/log results if retained; otherwise rerun the corrected OVH and broader provisioning/refund/interface suites offline. No .env or cached production config may be present in the test checkout.
3. Run lint, targeted three-language view rendering and isolated Blade compilation against the final file contents.
4. Review remaining safety edges: exact money amounts, stale-worker CAS, supplier/account identity, cancelled services, unknown monetary outcomes, key ownership/download race, and all default-off gates.
5. Fetch and merge the latest develop without rewriting history; inspect conflicts and rerun affected checks. The shared original checkout must remain untouched.
6. Commit only this implementation and a final operational report; exclude runtime logs, test key material, credentials and temporary results. Publish to develop only after verification is successful.
7. Reconcile the owner's exact previous test order using authorized administrative/DB and OVH read access.
8. Prepare a concrete production release and a non-spending live preflight. Production installation, purchase activation and any real paid test require the owner's final approval and an exact specification/currency/amount.

## Official references reviewed

- [OVH Terraform VPS resource](https://github.com/ovh/terraform-provider-ovh/blob/master/ovh/resource_vps.go)
- [OVH official order workflow and resolution](https://github.com/ovh/terraform-provider-ovh/blob/master/ovh/order.go)
- [OVH order/cart types](https://github.com/ovh/terraform-provider-ovh/blob/master/ovh/types_order_cart.go)
- [OVH order/detail/payment types](https://github.com/ovh/terraform-provider-ovh/blob/master/ovh/types_me_order.go)
- [OVH VPS installation options](https://github.com/ovh/terraform-provider-ovh/blob/master/ovh/resource_vps_gen.go)
- [Official order-route access rules](https://github.com/ovh/terraform-provider-ovh/blob/master/docs/guides/order_consumer_key_access_rules.md)
- [OVH cart examples and configuration flow](https://github.com/ovh/order-cart-examples)

These sources establish the official workflow. Mocked acceptance does not establish that the current production account has all required permissions or payment capacity.
