# Country VPS catalogue and unavailable-choice UX — 2026-10-31

Base: `335e0573b219adb689f9c5f008c87ece7d23b60f` (`develop`), after the initial `6f1dd7e` inspection. Active application: `website/`.

## Problem and resulting behavior

The live public quote required a supported annual quote before returning a valid monthly quote. Monthly-only offers therefore lost `irt`, and the old country table formatted its missing value as zero. `CloudPublicQuote` now returns the positive complete-cycle monthly checkout price independently; annual fields exist only for a supported annual cycle. Invalid monthly quotes remain unpriced.

Every supported VPS country has one canonical `/vps/{country}` page, retaining existing slugs such as `usa`, `england` and `germany`. Active city URLs redirect in one hop to their country, including the Istanbul alias and all three locales. Non-VPS cloud products and GPU routes retain their own behavior. The sitemap advertises country URLs once instead of redirecting city URLs.

Country and hourly pages share a table with city, CPU/architecture, RAM, storage, network terms, monthly and actual hourly prices. Search, country/city/resource/billing filters, sorting and progressive 50-row disclosure keep large inventories usable. Without JavaScript all rows remain readable. The offer representatives, contract-aware Pareto policy and Iran sales restriction are retained; pruning cannot remove a different city. Physical servers are not advertised as VPS. Infrastructure identities remain private.

The hourly page includes monthly-only offers, with an honest unavailable-hourly label and a monthly order link. Native EUR hourly quotes retain their currency; no artificial hourly rate is derived from monthly prices. Setup charges are shown separately where applicable.

Unsupported billing periods, OS families and versions stay gray and non-selectable. Explanations are in an accessible body-level tooltip, avoiding clipped card popovers and permanent paragraphs. Hover, focus, touch, Escape and plan changes are covered. Source input disabling and stale-popup checks remain enforced. The native order payload and backend availability/payment rules are unchanged.

## Validation

- PHP 8.4 isolated SQLite: **79 tests / 1170 assertions passed** across country consolidation, public prices, sitemap/redirects, hourly, VPS.TC sales, offer equivalence, dominance and country SEO.
- JavaScript/jsdom 26.1.0: **19 tests passed**, covering original image keys, unavailable versions, keyboard/touch tooltips, stale plan selection, filtering/sorting and pagination.
- Deployment boundary: **4 Python tests passed** for the pinned shared core, both destination roots, symlink/path rejection and snapshot/live drift refusal.
- Existing broader UI suite was compared against an isolated checkout at the same base. Its old fixtures and CSS checks already fail (including pricing-snapshot prerequisites and unrelated invoice CSS); the after run introduced no new failing test names. This is not a claim that the full repository suite passes.
- External supplier calls were prevented/faked in local tests. No production purchase or power action is part of this release.

## Production release

Status: prepared and locally tested; production installation and browser acceptance pending.

`scripts/deploy-vps-catalog.py` has an explicit runtime manifest and uses the reviewed deployment core (SHA-256 `00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de`). Planning makes private backups, merges source deltas into live overlays, lints PHP and emits before/after hashes. Apply requires the exact plan hash under both shared release locks. Rollback refuses to overwrite a later edit.

The separate `public_html/assets` root is in the same guarded plan as application sources/assets. Two inspected baselines require explicit reconciliation: the live-only `CloudPublicQuote` and the application's stale image-card asset; the served public asset already matched the source base. Account-store layout, current billing-savings behavior and eligibility overlays must survive the narrow patch.

Activation is limited to the public page cache generation and changed-file OPcache handling if required. There is no route/schema change, full cache flush, migration or scheduler execution. Private release receipts contain detailed paths/hashes; no production secrets or customer records belong in Git.
