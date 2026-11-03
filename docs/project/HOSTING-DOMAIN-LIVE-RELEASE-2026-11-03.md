# Hosting domain connection — production acceptance, 3 November 2026

**Status: installed, verified in production, and temporary public launcher retired.** This is a supplemental receipt for the observations independently made in this session; the [primary installation record](HOSTING-DOMAIN-CONNECTION-2026-11-02.md) also records separate acceptance checks.

Runtime source: `09b7efdc25021b1d53fa4e17bc75858d1ade426f`, included in develop merge `3ea104337c7efea4aa7f05a5aa6b4398fe8b3461` ([PR232](https://github.com/servernetir/server/pull/232)). Fresh develop checkout matched the reviewed runtime and release helper. This record supplements the current [primary installation report](HOSTING-DOMAIN-CONNECTION-2026-11-02.md) and its separately recorded acceptance; it does not replace that report.

## Installed scope and evidence

The [fourteen-destination manifest](hosting-domain-live-release-20261103.json) records exact before/after SHA-256 and preserved numeric modes without file contents, credentials or customer data. Source and public asset copies were both installed. Shared routes, Product and StoreController used narrow feature hunks on the actual live sources so existing live-only guards and unrelated routes remained intact. Existing private card/StoreController modes remained 0600; other source and public assets retained their documented modes.

The private engine was `93bb9973f50ee3f1dc485c279253a47c2a4b816eeb6b2178291e1a36d6a2564a`; the pinned payload was `c4c7a3bc45be55d7ba55c567eebff56baf398ac95a3d3fb7785273e42fcafca0`. Its private final state was `verified` at **2026-11-03 05:04:39 UTC / 08:34:39 Tehran**. A subsequent authenticated admin browser inventory matched all fourteen installed after hashes. A lost browser response during apply was reconciled against the saved final state and inventory; installation was not blindly retried.

The release helper retained three cross-release locks, private before-file backups, exact mode/hash guards, non-symlink path confinement, compiled-view checks and per-file OPcache activation. It rejected cached configuration/routes and checked the new controller route in a fresh read-only PHP process. No global cache flush, public page-cache invalidation, migration, billing change, schedule execution or customer DNS modification was performed by this release. The [reviewable helper](hosting-domain-release-20261102/README.md) documents rollback: restore and activate the old card source and compiled bytes before removing its new partial dependency, then restore remaining release files while refusing newer drift.

## Behavior accepted in production

- The actual signed-in customer hosting card displayed nameservers from its assigned hosting server, the setup guide and the DNS connection component.
- The real customer-scoped DNS check returned `connected`; the repeat check also succeeded. This confirms DNS resolution to the recorded hosting address, not application, SSL or email health.
- The actual installed Blade component rendered in fa/en/tr through the admin-only synthetic preview. Copy control succeeded and the guide opened. The synthetic DNS result was visibly identified as a fixture and was not used as evidence of the real domain's status.
- No browser console errors were observed during the real customer-card acceptance.
- This session did not independently repeat a successful narrow-viewport check: its viewport request returned while the observed DOM stayed wide. The [primary installation record](HOSTING-DOMAIN-CONNECTION-2026-11-02.md), updated separately in develop `5604166e`, records actual mobile acceptance at 390px with scroll width 375px, together with a real Turkish `connected` result. Those separate observations are not presented as measurements made in this session.

The customer ticket was answered after acceptance and the response was read back successfully. It remains in the answered state rather than closed. The response requests application-stack and existing-content information before any correction of the existing hosting assignment.

## Temporary entrypoint closeout

The authenticated cPanel move completed successfully: `public_html/servernet-hosting-domain-release-20261103.php` was moved to private `hosting-domain-release-20261102/launcher-20261103-retired.php`. A subsequent unauthenticated request to the former public URL returned **HTTP 404**. Private engine, pinned payload, state and rollback backups remain outside webroot; the temporary public entrypoint is no longer exposed.

## Root cause and remaining customer action

The recorded purchase was a Windows hosting product; the previous country-only server selection could assign WHM/Linux because Windows catalog groups historically shared the shared/reseller category. This was a system routing defect, not evidence that the customer selected the wrong product. The installed Product and StoreController guards now select compatible panel type, country, active status and capacity before creating a new service/invoice. Manual Plesk delivery remains manual; storage and nonhosting behavior remains unchanged.

The pre-existing misassigned hosting account was **not moved, deleted or re-provisioned**. A correction still requires checking the intended application stack, existing files/databases and target capacity, then preserving data and selecting a migration plan. The DNS UI acceptance does not resolve or conceal that separate customer-service issue.

## Validation inherited from the reviewed source

[CI41503897168](https://github.com/servernetir/server/actions/runs/41503897168) on PHP8.4.27 passed **43 Laravel tests / 256 assertions**, **17 offline DNS scenarios**, **43 native release checks** and **7 JavaScript tests**, including actual Blade renders in all three languages. These CI results are separate from the live acceptance above. The StoreOrder comparison retained **six pre-existing failures on both the current and unchanged-base checkouts, with zero new failures**; those pricing fixtures were not silently changed or reported green.
