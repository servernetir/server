# VPS.TC existing paid services and catalog expansion — 31 October 2026

Installed and verified in production. Source prepared on develop base `6d465debff5b3617a3849c1ff14019155b566fc9`. The owner explicitly authorized installation, expanding monthly offers, attaching an already-paid customer's guest and attaching the owner's existing internal relay. Private identity, addresses, native service/VM IDs, credentials and ownership receipts remain outside this public repository. Installation and business acceptance are recorded separately below.

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

PHP 8.4.27, locked dependencies, isolated SQLite and fake provider HTTP: **295 tests / 2,238 assertions passed** across VPS.TC, attachment, internal deletion and complimentary-service suites. Eight adoption and two expansion tests cover wrong identity/IP/resources/date/price, masked credentials, duplicate import, changed ownership, original access, regular admin route, customer denial, owner marker, internal deletion/invoice protection and changed catalog contracts. GitHub Actions [run 41176758290](https://github.com/servernetir/server/actions/runs/41176758290) passed: 260 VPS.TC tests / 2,089 assertions on SQLite and 43 attachment/protection tests / 206 assertions on MariaDB 11.4.13. The [financial regression matrix](https://github.com/servernetir/server/actions/runs/41176758091) also passed on SQLite and MariaDB against its immutable baseline.

The related `BillingRenewalSafetyTest` has the same three errors and two risky tests on this change and a clean worktree at the exact base. They concern hourly mock expectations and a domain price fixture. No provider safeguards were disabled to mask them. The earlier catalog-table baseline failures are recorded in the preceding ticket release.

## Release and rollback

The seven runtime files are listed in [manifest](vpstc-attach-20261031/release-manifest.json). Deploy only that reviewed delta with shared release locks, private backups, fresh before/after hash checks and PHP/Blade verification. Preserve live OVH guards in `CloudPlan`; do not replace the entire model from Git. No schema migration, scheduler run, global cache flush or production payment test is needed.

For rollback, restore only unchanged installed runtime destinations from this release's private backups. Preserve attached services and encrypted journals; a source rollback is not permission to remove the customer's guest or reset billing. Reconcile the reviewed catalog mapping and normal price snapshot separately if reverting offers. Do not restore the whole settings table or replay a supplier mutation.

## Production acceptance

PR [#163](https://github.com/servernetir/server/pull/163), source `40dff78a922d4856ed58873ade7ee0fb1d623a4e`, merged at `7425ecf9e639d3a4f231d1f6f6ded46c35ec113f`. The seven-file release installed successfully under both shared web locks with verified before/after hashes and private backups. The new `CloudPlan` hash is `e14462acacf92969863d5413710a9f3473deae40d0b0808229c24511f0fa890e`; pre-existing OVH guards remain intact. No schema or environment changes were made.

All sixteen candidate quotes ultimately passed the current native checks. An earlier read-only planning pass rejected several transient/inconclusive reads; it performed no mapping or price writes. The final apply re-read the complete catalog, committed sixteen active/in-stock priced plans and sixteen explicitly selected Linux images in two locations, and published a current pricing snapshot. Eight Istanbul and eight New York offers are visible with working monthly purchase links; hourly purchase remains disabled for these offers. Margin 40%, supplier tax 20%, funding fee 21% and the central Navasan FX source were retained. The global synchronizer also reported a pre-existing cross-provider sanity warning for another supplier; no unrelated supplier prices were changed.

The public [Istanbul page](https://servernet.cloud/cloud/tr-istanbul) shows eight monthly offers in fa/en/tr, including reference combined traffic and its throttle. The old Istanbul-II entry redirects to the canonical Istanbul page. The public [New York page](https://servernet.cloud/cloud/us-new-york) shows all eight new offers and honest unconfirmed-traffic labels, with no supplier identity leakage.

Both requested existing guests are attached to their authoritative customer profiles. The current retail customer's renewal base equals the live website monthly price (1,480,000 IRT plus normal 10% retail tax), with the actual supplier due anchor; the first period is recorded as already paid externally and no new invoice/payment was fabricated. The owner's relay has zero retail price and the owner-bound internal-infrastructure protection. Encrypted access and receipts, unique native references, original OS/hostname, matching dates and enabled included-power entitlement were verified privately. Both customer views show the assigned guest, IP, SSH command and real due date. No password was exposed in a public artifact, no welcome message was sent, and no power/rebuild/renewal/payment/cancellation/deletion was used as an acceptance test.

The customer's supplier Auto Renewal was On and was set to Off through its existing portal control; the owner's reference service was already Off. Auto Renewal Off does not reserve provider funds or preserve expired guests indefinitely. Funding checks remain in the real purchase path; larger offers still require enough supplier credit at order time. Browser impersonation was stopped after the profile checks. Console SSO, API snapshots/backups/password reset and measured usage remain unsupported.

The private release and ownership receipts remain outside webroot. Final state: **installed and verified in production**. This is read-only/attachment/catalog acceptance, not a new paid lifecycle test.
