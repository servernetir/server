# VPS.TC existing paid services and catalog expansion — 31 October 2026

Source prepared on develop base `6d465debff5b3617a3849c1ff14019155b566fc9`. The owner explicitly authorized installation, expanding monthly offers, attaching an already-paid customer's guest and attaching the owner's existing internal relay. Private identity, addresses, native service/VM IDs, credentials and ownership receipts remain outside this public repository. Installation and business acceptance are recorded separately below.

## Existing paid guest

The generic attachment form previously stored only a reference and status. VPS.TC needs its original hostname, verified unique guest, real billing dates, resources, encrypted credentials and a durable account/owner-bound receipt. It must neither fabricate a supplier purchase invoice nor start another procurement when a guest already exists.

`VpsTcAttachment` checks a fresh monthly quote against the published plan, exact regular website price, native product name and net amount, future due date, VM/IP/resources/readiness, Linux image and unmasked access. It records the owner's confirmation that the existing period was paid externally; no retail invoice, gateway payment or supplier mutation is created. The local service uses the regular monthly cycle, current website renewal price and standard retail tax. Normal renewal pricing continues to refresh from the website plan, and supplier renewal remains tied to a future paid retail invoice and the real supplier due anchor.

The encrypted `attach` journal binds account, local service/customer, native service/VM/domain/IP and resources. Status, power entitlement and create retry check that binding. A native reference without a valid receipt prevents a second purchase. The original guest OS is retained; no rebuild/password reset is used to import it.

Only the configured owner (ID and customer code) can mark a guest as internal infrastructure. The flag is also bound to the customer in service metadata. Existing lifecycle, deletion and invoice protections then apply; complimentary renewal must not falsely advance a prepaid supplier period. The relay's supplier due date remains real and requires funded supplier renewal by the owner. The public store and other customers do not receive a free-service exemption.

## Catalog expansion

The private two-phase script `scripts/vpstc-catalog-review-20261031.php` contains explicitly reviewed specifications for eight Istanbul and eight New York monthly products. It rejects changed advertised resources, unknown controls, paid Linux selections or changed included quantities. Storage zero, backup zero and two included snapshots are explicit; Ubuntu 24.04 is selected explicitly instead of relying on the supplier's default. Optional native location selectors are bound to their reviewed item.

Each candidate must pass the existing full native quote, currency/fee/tax normalization and option checks. Failed candidates are excluded; any failure of an existing published offer aborts expansion. The owner commercial approval is account/product/configuration-bound. Only the reference product receives the support-confirmed combined monthly traffic/throttle/no-metered-overage terms and included power entitlement. Other products retain unconfirmed traffic terms; advertised unlimited traffic is not promoted to a billing guarantee. Zero/unknown traffic now has an honest fa/en/tr label.

Apply checks the before-manifest hash, candidate hash and unchanged pricing/FX inputs, privately backs up the old mapping, holds the shared catalog lock and uses normal VPS.TC-only synchronization plus pricing snapshot publication in one database transaction. The mapping itself changes the pricing fingerprint, so the published batch binds the new fingerprint. Existing profit margin, funding fee, supplier tax and FX source are retained. No actual order is submitted.

## Validation

PHP 8.4.27, locked dependencies, isolated SQLite and fake provider HTTP: **295 tests / 2,238 assertions passed** across VPS.TC, attachment, internal deletion and complimentary-service suites. Eight adoption and two expansion tests cover wrong identity/IP/resources/date/price, masked credentials, duplicate import, changed ownership, original access, regular admin route, customer denial, owner marker, internal deletion/invoice protection and changed catalog contracts. MariaDB attachment/protection coverage is added to the existing VPS.TC CI workflow; its result is pending until run.

The related `BillingRenewalSafetyTest` has the same three errors and two risky tests on this change and a clean worktree at the exact base. They concern hourly mock expectations and a domain price fixture. No provider safeguards were disabled to mask them. The earlier catalog-table baseline failures are recorded in the preceding ticket release.

## Release and rollback

The seven runtime files are listed in [manifest](vpstc-attach-20261031/release-manifest.json). Deploy only that reviewed delta with shared release locks, private backups, fresh before/after hash checks and PHP/Blade verification. Preserve live OVH guards in `CloudPlan`; do not replace the entire model from Git. No schema migration, scheduler run, global cache flush or production payment test is needed.

For rollback, restore only unchanged installed runtime destinations from this release's private backups. Preserve attached services and encrypted journals; a source rollback is not permission to remove the customer's guest or reset billing. Reconcile the reviewed catalog mapping and normal price snapshot separately if reverting offers. Do not restore the whole settings table or replay a supplier mutation.

Production installation, expanded purchasable count and two private ownership attachments: **pending**.
