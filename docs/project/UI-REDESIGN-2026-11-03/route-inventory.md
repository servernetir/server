# Phase 0 — static route and layout inventory

Base: `develop` at `31a311e904741f8b58f462824a83c9745731b23f`. Date: 2026-11-03. Status: documented for preview; no application changes, Laravel boot, provider requests, production access, deployment or merge.

The complete route-level inventory is in [route-inventory.csv](route-inventory.csv) and [route-inventory.json](route-inventory.json). Each row includes methods, path pattern, composed name, host-policy context, inherited route middleware and exclusions, locale, access class, module, actual view/layout ancestry, source line, handler, constraints, SEO class, preview phase and width policy. JSON also includes view-source evidence, unresolved expressions and collision diagnostics.

## What these counts mean

This is a static reconstruction, **not `php artisan route:list`**. It expands the shared closures and literal route-generating loops without executing PHP. Counts describe source registrations; they are not unique runtime routes, page URLs, purchasable products or live operational health. Config/database slugs, IDs and content branches remain route parameters.

| Measure | Count |
|---|---:|
| HTTP declarations in seven source route files | 682 |
| Expanded HTTP source registrations | 1,228 |
| Framework `/up` health registration | 1 |
| Total inventory rows | 1,229 |
| Distinct declared method-set/path/host-policy signatures | 1,220 |
| Overlapping method/path registration pairs | 15 |
| Action classes read, including the service-class broker handler | 130 |
| Actual Blade views mapped to route handlers | 173 |
| Inventory rows mapping to HTML views | 396 |
| GET/HEAD rows mapping to HTML views | 392 |
| Blade source files, including fragments/email/templates | 394 |

Every mapped view exists. A view count is not a page count: a view can serve many parameter values, methods and locales. Other view files include partials, email, errors and unused/indirect templates.

## Complete registration-source coverage

| Source | Source declarations | Expanded rows |
|---|---:|---:|
| `website/routes/web.php` | 630 | 1128 |
| `website/routes/identity-api.php` | 11 | 23 |
| `website/routes/urmia-auth.php` | 2 | 2 |
| `website/routes/identity-site.php` | 4 | 12 |
| `website/routes/ai-growth.php` | 24 | 24 |
| `website/routes/madar-admin.php` | 5 | 9 |
| `website/routes/madar-account.php` | 6 | 30 |
| `website/bootstrap/app.php` | framework | 1 |

`bootstrap/app.php:9–12` loads `routes/web.php`, the command routes and `/up`. The tracked active app has no `routes/api.php`; the provider scan found no additional HTTP route loading. `routes/console.php` is command/schedule configuration and contributes no HTTP rows. The root legacy app is outside the active `website/` application.

## Locale and generated-group handling

| Source construct | Handling |
|---|---|
| `web.php:41–745`, invoked at 748–750 | Shared `$site` closure expanded for fa root, `/en`, `/tr`, with name prefixes and locale middleware. Public, auth and customer console routes live together here. |
| `identity-site.php` and `madar-account.php` | Included under the same locale/group context; account authentication, active-customer, IP and birthday middleware retained. |
| `identity-api.php:27–38` | Literal prefix/name map expanded into three localized admin families. API endpoints remain unprefixed by locale. |
| `madar-account.php:13`, `madar-admin.php:12` | Five literal action values expanded; action-specific path/name/handler is recorded. |
| `web.php:825–835`,1010–1013 | Seven legacy redirects and six sitemap redirects expanded from literal maps/lists. |
| `web.php:765–817` | Persian Urmia content remains; en/tr Urmia closures return 410 and are intentionally unnamed. The nearby historical comment calling all Urmia pages multilingual is superseded by actual registrations. |

Locale totals are 265 fa, 265 en, 265 tr and 434 unprefixed/default. Matching counts do not mean all three languages render the same content: Urmia en/tr is 410; controller feature gates and content availability also vary. Source ordering is retained in file/line evidence; row IDs identify inventory records and are **not runtime registration order**.

## Width and layout audit

| Family | Current source behavior | Redesign implication |
|---|---|
| Customer console | `panel/layout.blade.php:6,20–36` extends site. `site.css:49` caps base container at 1200px; `panel.css:16–17,44–49` consumes 248px sidebar plus 22px gap. | Highest-priority real desktop boxing constraint. Dashboard preview should use the available viewport while preserving readable card/table widths. |
| Public marketing/product | `layouts/site.blade.php:19,147,235` adds `sn-public` and Atlas assets. `atlas-public.css:4` overrides public containers and GPU wrap to `max-width:none;width:100%`, with 20–56px responsive gutters. | Public pages already have a fluid outer shell. Audit inner grids and content hierarchy; do not claim every `.container` is boxed. |
| Hosting | `pages/hosting.blade.php:143,171` uses shared config-driven product hero, horizon/stage fragments and pricing. | Hosting preview should refine an already-fluid public shell, preserve catalog index/slug/buy links, and keep provider/pricing logic unchanged. |
| Admin | `admin/layout.blade.php:2,9`; `admin.css:61,89` uses 250px sidebar and flexible main. | Full viewport shell already; prioritize dense workflow/table consistency after customer families. |
| Auth | `auth/shell.blade.php:22–26,38–39`; `auth.css:45` caps shell at 900px and provides noindex. | Retain focused forms while updating shared visual language and locale/access states. |
| Invoice/receipt | `account/invoice-print.blade.php:10,20,25,95` uses 800px print layout; payment result 620px. | Purpose-driven content widths should remain appropriate to print/payment context. |

Remaining public component caps include AI `.ai-wrap` 1240px (`ai-workspace.css:12`, superseding 1160px at 2), legal content 820px (`site.css:1200`), tools SEO content 820px (`site.css:2111`), and domain search 760px/result 960px (`pages/domain-search.blade.php:355,390`). Domain `.dsx-wrap` 1060px at 340 can be superseded when the same element carries `.container`; computed CSS needs browser verification. FAQ and reading widths are intentional until evaluated visually.

Direct Blade inheritance: 61 site, 36 panel, 79 admin, 7 auth, 5 email; 206 files are standalone/fragments. Folder counts include pages 70, account 63, admin 115, auth 9, panel 3, errors 4 and partials 65, and should not be used as route totals.

## Preview and subsequent coverage

All rows carry a phase tag so the full inventory remains visible while the first design stays bounded. These are planning buckets, not authorization for implementation or production.

| Phase | Source-registration rows | Coverage |
|---|---:|---|
| P0 dashboard preview | 3 | `/account` and localized equivalents; one representative customer overview with synthetic content. |
| P0 hosting preview | 6 | `/hosting/{slug}` and `/hosting` redirect across three locales. Only the content template needs visual preview. |
| P1 customer console | 411 | Service rooms/all services; managed DNS; cloud/store/configuration; domains; reseller; identity API; Madar; profile/security; bank/wallet/refund/invoice/payment; tickets and attachments; action endpoints retained for regression. |
| P1 authentication | 60 | Customer registration, OTP, identity/finish, Google flow, login and 2FA/logout; three-language and guest/session states. |
| P2 public families | 179 | Home; product/catalog/cloud/GPU/hourly; domains/hardware/parts; AI; solutions; blog/docs/knowledge; tools/lookup; contact/legal/SLA/status; identity storefront; reports/Urmia/gone content where applicable. |
| P3 admin/operations | 357 | Dashboard/content; support/customer verification; services/cloud/fleet/network; finance/refunds/reconciliation/accounting; AI/growth/SEO; marketing; Madar; mail/calendar/templates/settings; authentication and actions. |
| P4 utility/API/redirect regression | 213 | Customer/domain/identity/AI APIs, internal callbacks, SMS/agents, health/sitemaps/llms, operational token endpoints, legacy redirects and non-view responses. |

Initial preview should cover fa RTL and en/tr LTR, desktop/mobile, navigation/sidebar, loading/empty/error content, theme, readable contrast and keyboard focus. Subsequent approved integration must preserve methods, names, parameters, middleware/exclusions, CSRF/session, price/currency formatting, billing cycles, provider masking, form behavior, dynamic catalog ordering and existing result states.

## Public SEO and private noindex

Public marketing remains an SEO scope: preserve title/description, canonical identity, hreflang/locale links, structured data, semantic headings, internal links, status codes and accessible content. `layouts/site.blade.php:50–78` conditionally emits noindex instead of canonical/hreflang; `faOnly` removes false en/tr alternates. Do not apply a blanket noindex to public marketing.

Customer/admin/auth/payment/system/API families are classified separately. `ConsoleHost.php:16–17,165–190` defines the main/console split and adds `X-Robots-Tag:noindex,nofollow` to returned console responses. Panel/admin templates do not all have local noindex meta; actual host redirects and response headers must be verified in isolated runtime acceptance. Authentication, print invoices and payment results have explicit noindex; errors 404/429/500/503 also do. Robots disallow is not authentication.

Conditional noindex exists for filtered/empty AI, server/parts listings, blog search/tag or untranslated posts, empty VPS-country/cloud-location inventory and custom comparisons. Reports/unsubscribe and custom parts compare also need their existing non-indexable behavior preserved. Inventory `seo_class` values are triage classifications, not a claim that every runtime response was crawled.

| Static SEO triage class | Rows |
|---|---:|
| private/operational; noindex required via console host/meta/header; excluded from public SEO | 957 |
| public SEO candidate; preserve canonical/hreflang/schema and conditional noindex | 141 |
| noindex or conditional noindex from view; inspect condition before SEO changes | 39 |
| redirect/gone; preserve status and target; no SEO content redesign | 55 |
| utility/API/non-view or unresolved render; inspect response; exclude from public SEO by default | 37 |

## Collisions and unresolved runtime facts

15 pairs share at least one HTTP method and path: six root legacy aliases (`/privacy-policy`, `/home`, `/cart`, `/services`, `/marketing`, `/servernet`) overlap later `Route::redirect` registrations; `/report/{token}` and its unsubscribe path are declared twice in all three locales; `/account/reseller` is declared twice in all three locales. Nine pairs have the same complete declared method set; six involve broad-method redirects overlapping GET/HEAD. No route was removed or altered. Runtime effective-route behavior must be checked before an approved route refactor, which is outside this preview.

No unresolved path, name or action variables remain after literal expansion. Remaining dynamic/configuration or runtime issues:

- Three throttle expression forms retain the configured read/check/write limits and their 120/60/20 per-minute fallback strings; these are marked `EXPR:` rather than assigned invented effective values.
- `SectionController::room($view)` is mapped through the literal hosting/server/other caller arguments. `Admin/MarketingController::show` records both ternary view branches and retains its expression. Data-dependent visibility and runtime branches were not executed.
- Controller-level bearer/HMAC/deploy-token checks, customer ownership/policies, authorization outcomes and feature gates require runtime verification. The access column records source intent, not security acceptance.
- No explicit `Route::domain` groups were found. Domain context comes from global `ConsoleHost`/`LegacyDomain` middleware; the inventory does not claim each route is registered to a fixed domain.
- GET includes implied HEAD; redirect/any declarations list the broad method set. Automatic OPTIONS, framework health behavior, route-name collision resolution and actual host/status/header results remain unbooted.
- Config/database parameter values were not enumerated: `/hosting/{slug}`, `/{category}/{slug}`, hardware, content, AI models and customer resource IDs can serve multiple URLs per pattern.

## Frontend implementation contract

The live application architecture is Blade with direct assets under `public/assets`, shared layout/partials and page-specific CSS/JS. `CLAUDE.md:188–194` states Vite is configured but not used by real page templates. The actual layout (`layouts/site.blade.php:97–143`) chooses minified/source site CSS and may extract inline public/LCP styles through `LandingPageStyles`/`PublicPageStyles`; a future shared CSS change must account for those paths. Vite build alone would not demonstrate an integrated UI change.

Preserve `lroute()` URLs, `lc()` localized data, aligned fa/en/tr UI keys, safe `@json()` JavaScript values and the shared fixed-header reservation (`CLAUDE.md:131–170`). New visual pages should not add a second manual header offset.

## Verification actually performed

- Reviewed AGENTS, project index, ONBOARDING, TEAM and frontend/locale/security sections of CLAUDE, then matched claims to current source.
- Lexically scanned every active HTTP route source, explicitly expanded locale/literal loops and inherited group attributes; scanned bootstrap/provider registration evidence.
- Read all 130 action-class sources; resolved literal views, returned render helpers, three SectionController caller arguments and conditional marketing branches; followed Blade layout inheritance.
- Confirmed all 173 mapped view paths exist; validated JSON/CSV row parity, declaration totals, phase sums, locale prefixes, madar loop expansion and collision evidence.
- No PHP/Laravel runtime, migration, seeder, provider/API call, browser request to production, installation, deployment or merge was run. Computed layout, form/session interactions and actual HTTP/security outcomes remain acceptance work for an explicitly approved integration.

Rollback for this documentation-only inventory is removal/reversion of these three files. It has no runtime effect.
