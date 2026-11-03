# ServerNet UI modernization — initial preview

Date: 2026-11-03. Source base: `develop` at `31a311e904741f8b58f462824a83c9745731b23f`.
Preview branch: `preview/full-width-ui-20261103`.

This is the requested Phase 0 audit, Phase 1 design system and first Phase 2 customer dashboard/hosting prototype. **Review boundary: do not merge into develop, deploy to ServerNet, or begin the remaining modules until the user approves this initial design.** The user's brief explicitly says “Stop after delivering the initial preview.”

## Findings and evidence

The active application is Laravel/Blade under `website/`, with shared manually served CSS/JavaScript. Existing routing, controllers, permissions, billing, wallets, provisioning, lifecycle integrations and database schemas remain the integration contracts. A framework migration would add risk without solving the current layout problem.

The route inventory reconstructed 682 source declarations into 1,228 expanded source registrations plus the framework health route. It inspected 130 handler classes and mapped 173 existing Blade views. These **1,229 records are source registrations, not a runtime route count**; duplicate registrations and dynamic resolution caveats are retained in the evidence. See [complete route/layout audit](route-inventory.md), [CSV](route-inventory.csv), and [JSON](route-inventory.json).

The customer shell inherits the 1200px base container, then consumes space with its sidebar and nested cards. The supplied reference demonstrates a narrow hosting card within another large panel, leaving much of the available desktop area unused. Public marketing pages already override the outer width through Atlas; admin also has a flexible shell. The redesign must distinguish those families rather than remove every max-width indiscriminately. Long text still needs a readable line length.

The repository's ServerNet identity is navy, blue and cyan with its existing IRANSans font. The prototype uses these assets, an original inline server icon built from simple SVG geometry, and existing `site-select.css/js` copied unchanged for the smooth select behavior. No external icon, font, analytics or component service is loaded.

Six anonymous public HTTP checks found reachable home/product/robots/sitemap endpoints and 5,454 unique main-host sitemap URLs. Public pages sampled have one H1, canonical/hreflang and parseable JSON-LD. Console robots disallows crawling. Source/live robots query-rule drift and product shipping-schema use warrant separate review. These are observations and a roadmap, **not SEO fixes or a ranking improvement claim**. See [SEO and performance evidence](seo-performance.md) and [HTTP observations](seo-public-observations.json). Core Web Vitals and real-user mobile LCP remain unmeasured here.

## Implemented preview

Sources are under `preview/full-width-20261103/`, outside the Laravel public/app tree. No active application file, route, schema, integration or deployment manifest is changed. `preview/.openai/hosting.json` is a local static QA configuration with no project ID or production target.

| Screen | Implementation |
|---|---|
| Overview | Full viewport shell, four summary cards, service resource rows, recent activity, wallet summary, attention items and support access |
| Hosting list | Two service cards, separate service/DNS states, text search, active/attention filters, created-date and renewal/name sorting, empty state |
| Hosting management | Linux/cPanel and Windows/Plesk fixtures, four resource meters, service metadata, copy actions, renewal summary, DNS guide and sample checks |
| Shared shell | Expandable desktop sidebar, mobile drawer and bottom navigation, three languages, RTL/LTR, light/dark, safe-area spacing, focus styles and reduced-motion support |
| Interaction components | Existing smooth select/listbox, native modal dialog, toast, disclosure guide, keyboard navigation and clipboard fallback |

All customer names, domains, IPs, credentials, balances and events are fictional. `.example` domains and RFC 5737 documentation IPs are used. The customer screenshot is an internal visual reference and is excluded from the repository and preview. The sample reference date is **2026-11-03**; renewal dates and relative-day labels are intentionally pinned. All demo money is explicitly denominated in IRT/toman in every locale; language switching performs no currency conversion.

The 208-key offline catalogue includes Persian, English and Turkish. Language changes update `lang`, `dir`, visible copy, select labels and accessible names. Preference persistence is best-effort and works without requiring cookies or a server. The HTML export embeds its own fonts, styles and scripts for opening locally.

Actions outside the three screens open explanatory demo dialogs. Control-panel login, purchases, payments, support submission, file changes and provider actions are simulated. DNS checks retain the fixture's pending/connected state and clearly identify the result as a sample; they do not claim to query a real domain. The connected fixture does not imply website, SSL or mail health.

## Reusable design and integration architecture

| Layer | Prototype contract | Later Blade integration |
|---|---|---|
| Tokens | CSS semantic colors, surfaces, spacing, radii, typography, state colors and sidebar width; dark token overrides | Scoped panel token stylesheet; retain public/admin tokens until their phases |
| Shell | Logical spacing; available width minus 230px/76px sidebar; <=800px drawer; viewport safe areas | Update the customer layout only after prototype approval and route-family acceptance |
| Page primitives | Heading/actions, panel, stat card, hosting card, resource meter, metadata list, notice, empty state | Small reusable Blade components receiving existing view models |
| Navigation | `data-view`, service fixture ID, active page, destination heading focus | Existing named Laravel routes, permissions, locale prefixes, authorization and CSRF unchanged |
| Fields | Real select owns its value; shared `ServerNetSelects` owns appearance | Preserve existing form names, validation, native behavior and backend payloads |
| Service state | OS/control-panel assignment, active status and DNS state are independent fixture fields | Read real existing model fields; never infer panel choice from product display name |
| Client behavior | Scoped, offline, demo-only `app.js`; no request APIs or application data | Replace fixtures with escaped server-rendered data while preserving existing guarded action handlers |
| Locale | Keyed fa/en/tr catalogue, text and aria attributes, logical layout | Map approved copy into established locale files; preserve the site's money and URL policies |

The CSS establishes the visual contract; it is not yet a drop-in production stylesheet. It must be extracted into scoped components during integration, with visual comparisons and existing authenticated action tests. Public content preserves readable inner columns while product tables and dashboards can use the available viewport.

## Prioritized roadmap and approval gates

| Phase | Work | Exit evidence / gate |
|---|---|---|
| 0 — delivered | Source route/layout inventory, constraints, shared components, SEO/performance audit and risks | Static inventory with runtime caveats, no destructive route changes |
| 1 — initial preview | Tokens, typography, desktop/mobile shell and interaction primitives | Working independent preview, source checks, desktop/mobile visual review |
| 2 — initial preview | Overview, hosting list/detail, resource and DNS presentation | User reviews these three screens; stop here for approval |
| 2 — integration, after approval | Convert approved components into existing Blade customer dashboard/hosting views | Auth/session/locale/permission checks, real data states, action regressions, mobile/desktop screenshots; no backend redesign |
| 3 — customer modules | Servers, domains/DNS, invoices, wallet/refunds, support, identity/API and AI | Each family reviewed in a preview; preserve financial/provider contracts and existing test suites |
| 4 — public website | Public product hierarchy, conversion paths and content treatment | Preserve route/status, canonical/hreflang/robots, headings/schema and analytics events; compare measured performance |
| 5 — administration | Refund/finance and other admin workflows separately | Density, filters, keyboard flow, permissions and audit fields reviewed; no automatic financial changes |
| 6 — acceptance | Browser/accessibility, RTL/LTR, responsive and SEO/performance regression checks | Supported-device matrix, comparable measurements, unresolved issues documented |
| 7 — release, explicit approval | Review final diff, test, deploy, verify and monitor | User approval plus repository deployment gates; final refs and rollback evidence |

## Risks and guardrails

- Customer CSS is shared widely. Integrate one route family at a time and scope changes to avoid affecting billing/forms, public marketing or admin.
- Desktop width must use logical offsets that move with RTL/LTR; remove margin transitions during direction changes. Collapsed navigation still needs accessible labels.
- Mobile should reflow content rather than shrink important text. The preview uses larger controls, a focus-managed drawer and independent sidebar scrolling.
- Hosting lifecycle state, DNS setup, control-panel access and billing state are separate concepts. Keep explicit source-of-truth fields and existing authorization.
- The source inventory identifies overlapping registrations and dynamic throttle expressions. It does not authorize route cleanup; validate runtime routes in the established isolated application test environment before later integration.
- Private pages stay nonindexable. Preserve authentication/session-safe cache behavior, CSRF replacement and cookie variance. Public SEO work must not expose console/account pages.
- Production pricing, balances and currencies must come from current application data. No preview fixture or currency conversion belongs in the live billing model.
- Browser checks here cover a Chromium preview and responsive iframe viewports, not real iOS/Safari, Android, every browser or real-user field metrics.

## Running and reviewing

Open the independent HTML deliverable in a browser, or use the managed local preview from `preview/`. `review.html` supplies 320/390/768/900/1440px iframe widths for deterministic responsive review; these are CSS viewport checks, not hardware-device emulation. Main application actions are not connected.

Focused validation:

```sh
python preview/full-width-20261103/check-preview.py
node --check preview/full-width-20261103/app.js
node --check preview/full-width-20261103/locales.js
node --check preview/full-width-20261103/assets/site-select.js
```

Catalogue generation: `python preview/full-width-20261103/build-locales.py`.
Export: `python preview/full-width-20261103/export-preview.py --output /absolute/path/servernet-preview.html`.

See [source review](preview-checks.md) and [browser verification](browser-verification.md) for tested scope, results and limitations. No PHPUnit, provider API, cPanel action or live customer operation is needed for this isolated visual prototype.

## Delivery state

Source changes are limited to the new audit and preview directories. The current production interface remains unchanged. No merge to `develop`, ServerNet deployment, database migration or later redesign phase is performed. The next action is the user's review of this initial design.
