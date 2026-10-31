# Country VPS catalogue and unavailable-choice UX — 2026-10-31

Base: `335e0573b219adb689f9c5f008c87ece7d23b60f` (`develop`), after the initial `6f1dd7e` inspection. Active application: `website/`.

## Problem and resulting behavior

The live public quote required a supported annual quote before returning a valid monthly quote. Monthly-only offers therefore lost `irt`, and the old country table formatted its missing value as zero. `CloudPublicQuote` now returns the positive complete-cycle monthly checkout price independently; annual fields exist only for a supported annual cycle. Invalid monthly quotes remain unpriced.

Every supported VPS country has one canonical `/vps/{country}` page, retaining existing slugs such as `usa`, `england` and `germany`. Active city URLs redirect in one hop to their country, including the Istanbul alias and all three locales. Non-VPS cloud products and GPU routes retain their own behavior. The sitemap advertises country URLs once instead of redirecting city URLs.

Country and hourly pages share a table with city, CPU/architecture, RAM, storage, network terms, monthly and actual hourly prices. Search, country/city/resource/billing filters, sorting and progressive 50-row disclosure keep large inventories usable. Without JavaScript all rows remain readable. The offer representatives, contract-aware Pareto policy and Iran sales restriction are retained; pruning cannot remove a different city. Physical servers are not advertised as VPS. Infrastructure identities remain private.

The hourly page includes monthly-only offers, with an honest unavailable-hourly label and a monthly order link. Native EUR hourly quotes retain their currency; no artificial hourly rate is derived from monthly prices. Setup charges are shown separately where applicable.

Unsupported billing periods, OS families and versions stay gray and non-selectable. Explanations are in an accessible body-level tooltip, avoiding clipped card popovers and permanent paragraphs. Hover, focus, touch, Escape and plan changes are covered. Source input disabling and stale-popup checks remain enforced. The native order payload and backend availability/payment rules are unchanged.

## Validation

- PHP 8.4 isolated SQLite: **80 tests / 1174 assertions passed** across country consolidation, public prices, sitemap/redirects, hourly, VPS.TC sales, offer equivalence, dominance and country SEO.
- Additional VPS.TC suite at the initial runtime revision: **261 tests / 2102 assertions passed**.
- JavaScript/jsdom 26.1.0: **19 tests passed**, covering original image keys, unavailable versions, keyboard/touch tooltips, stale plan selection, filtering/sorting and pagination.
- Deployment boundary: **4 Python tests passed** for the pinned shared core, both destination roots, symlink/path rejection and snapshot/live drift refusal.
- Existing broader UI suite was compared against an isolated checkout at the same base. Its old fixtures and CSS checks already fail (including pricing-snapshot prerequisites and unrelated invoice CSS); the after run introduced no new failing test names. This is not a claim that the full repository suite passes.
- External supplier calls were prevented/faked in local tests. No production purchase or power action is part of this release.

## Production release

Status: **installed and verified in production**, 2026-10-31.

| Release | Source SHA | Merged develop SHA | Installed files |
|---|---|---|---|
| Country consolidation and choice tooltips, PR #172 | `769ebd170e036d0eca2b637a49a4ff325b307081` | `e7aa4c28ccc24b40d978eb579d886d8b30107a1a` | 25 |
| SVG flags, canonical city labels and localized counts, PR #175 | `800203637e344bb0376149bab9435dd914300690` | `14a88e98f85c840fdbef63bdc059549a084a5e74` | 9, within the same 25 destinations |

Both PR CI runs passed. The initial source passed the VPS.TC SQLite/MariaDB preflight and birthday financial checks; the display follow-up passed its applicable birthday financial check. No full-suite-green claim is made.

Initial guarded plan: `9bf49534d15bec8cdb60cd57326d8eab59220dcd818ff3c72557e38d652e4a53`, private release `vpstc-release-769ebd170e03-g4tqoy_h`. Follow-up: `374f8329d9abe73a9e017db0568d67a9a876186433fe0dac32fa7390f5c09615`, private release `vpstc-release-800203637e34-rws2hzfg`. All **25 distinct final destination hashes** were read back successfully after both releases.

The first plan stopped on a context conflict before any application writes. The redirect and asset-inclusion hunks were narrowed to avoid surrounding live-only quote/layout changes; the resulting staged account-store file was verified to contain only the intended asset additions, preserving its complete three-step experience and savings behavior.

Public page generation advanced `79447 → 79448`, then `79528 → 79529`; no other cache clear or service restart was used.

### Production acceptance

- **22 read-only HTTP checks**: country/hourly pages in FA/EN/TR, six one-hop city/alias redirects and the canonical sitemap. The sitemap lists each tested country once and contains no old New York/Istanbul city URL.
- `/vps/usa`: 68 offers, all monthly prices positive. The eight New York offers appear together under its city filter; the first now displays **1,480,000 toman**. Positive IRR/EUR offer schema was also verified in all three languages.
- Verified-account browser hourly view: **708 offers**, including 678 hourly and 30 without hourly billing. Monthly filtering returns those 30 offers with monthly purchase links. The unauthenticated HTTP view has 498 eligible offers under the existing Iran visibility policy; inventory counts are context-dependent.
- Live store: unavailable periods show only their labels, native inputs stay disabled, and hover/focus exposes the explanation. Clicks on quarterly and unsupported Windows keep monthly billing and Ubuntu selected. Native Tab navigation reaches disabled OS cards with their tooltip; Escape dismisses it. No permanent OS-reason paragraphs remain visible.
- Local SVG flags loaded successfully, and the RTL result counter reads `۸ پلن از ۶۸` when New York is selected. A public-only screenshot was captured as visual evidence.
- Desktop interaction was verified. The browser viewport override did not change the actual viewport, so a true mobile-device visual check is **not claimed**; the responsive stylesheet and contained horizontal table are implemented.

Private before/candidate/receipt reports remain outside web roots. No provider mutation, service operation, purchase, customer charge, profile reassignment or schema change was performed for this UI release.

`scripts/deploy-vps-catalog.py` has an explicit runtime manifest and uses the reviewed deployment core (SHA-256 `00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de`). Planning makes private backups, merges source deltas into live overlays, lints PHP and emits before/after hashes. Apply requires the exact plan hash under both shared release locks. Rollback refuses to overwrite a later edit.

The separate `public_html/assets` root is in the same guarded plan as application sources/assets. Two inspected baselines require explicit reconciliation: the live-only `CloudPublicQuote` and the application's stale image-card asset; the served public asset already matched the source base. Account-store layout, current billing-savings behavior and eligibility overlays must survive the narrow patch.

Activation is limited to the public page cache generation and changed-file OPcache handling if required. There is no route/schema change, full cache flush, migration or scheduler execution. Private release receipts contain detailed paths/hashes; no production secrets or customer records belong in Git.


The display follow-up uses `scripts/deploy-vps-catalog-polish.py`, the same deployment core, and source base `769ebd170e036d0eca2b637a49a4ff325b307081`. To revert both releases, first apply the follow-up's guarded rollback, then the initial release's rollback; each refuses later edits. No rollback was required by acceptance.
