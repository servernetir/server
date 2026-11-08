# SEO continuation — 8 November 2026

Status: code prepared and tested; application production installation is still pending. This record supersedes older queue assumptions where stated below. Base: `59ee1fef3978121bace294ff05741c80376c2801` on `develop`.

## Reconciled state

The November 3 commercial schema/guidance package (`9b31f79`, with expanded tests in `baf0a69`) and November 8 guest-cache/discovery package (PR #285, merge `4f15d92`) are in develop. Anonymous public GETs today still show the old hourly Product/shipping schema, no GPU Service in three languages, no new hosting guide, no tool CTA on `/lookup/a`, and no order-page noindex. A guest session bypasses HTML caching after its initial request. These observations establish that the sampled changes are not active on production; Git merge is not installation.

All sampled public pages responded 200 with one H1, correct canonical/hreflang and parseable JSON-LD. The observed sitemap contains order handoffs. A normal 390px browser check of the current hourly page found no document-width overflow; this is a check of the old production page, not visual acceptance of the candidate.

## Fixes in this continuation

1. Remove order handoffs from the shared sitemap entry source, aligning it with PR #285's `noindex,follow`. Product landing pages remain discoverable. The owner's section classification, routes, robots and XML rendering are preserved; an empty orders section naturally leaves the index. The regression failed against the unchanged source because the active SKU was still advertised, then passed after the removal.
2. Correct misleading shared hosting/VPS copy in Persian, English and Turkish: reserved-resource hourly billing, activation/capacity conditions, Windows licensing, supported operating systems/network options, SLA eligibility, Plesk extensions, migration/scaling conditions and unsupported LiteSpeed speed/SEO claims. Prices, product identifiers, resources and availability logic are unchanged.
3. Extend commercial CI to run sitemap regressions and the adjusted Audit7 order-indexing case, and to watch the affected controller/config files.

The copy review is bounded to `config/hosting.php` and `config/catalog/vps.php`. It is not a certification of every older product claim.

## Analytics investigation and draft

An authenticated Tag Manager read found the target container at live version 8 with zero workspace changes. The UI displayed “Data Layer” for purchase, but the public compiled purchase tag lacked an explicit ecommerce-source parameter; a second same-property tag contained it. This matches the earlier diagnosis without assuming that UI defaults are persisted.

The existing purchase tag's source was explicitly selected as Data Layer and saved to its existing workspace as one modified tag. No tag, trigger or variable was added, and the container was not published. Detailed diff verification and Preview/DebugView acceptance must precede publication. No synthetic purchase was sent into production analytics.

Backend regression tests verify the existing transaction payload, wallet-topup separation and one-time session consumption. They do not certify Google receipt or revenue accounting. Tax-exclusive value, discounts, partial payments, overpayments and canceled-invoice wallet credits require one explicit allocation contract. Unsupported revenue currencies and source continuity remain separate acceptance work. No payment or billing logic was changed.

## Validation and limits

Fresh final candidate: PHP 8.4.12, commercial plus sitemap suite **93 tests / 8,156 assertions**; the focused updated Audit7 case **one test / five assertions**; analytics suite **13 tests / 93 assertions**. Seven opportunity-analysis tests and the tool-CTA Node check passed. The offline GTM repair guard passed 17 Node tests. The new sitemap regression reproduced a failure before the source fix. Changed PHP syntax, workflow YAML and whitespace checks passed. Scoped Pint findings match the exact base file fixer lists; legacy formatting was preserved.

Laravel generated **39 isolated HTML fixtures** across three languages, including Linux/Windows VPS. Export acceptance passed one test / 79 assertions; every fixture has one H1, one title and the correct language. A Chromium attempt failed before launch because the executor denied its socket. Candidate screenshot, interaction and responsive acceptance are therefore still open. No workaround or production deployment was attempted to claim that acceptance.

## Next release and measurement

Use the reviewed PR tree and [33-file runtime inventory](evidence/release-inventory-2026-11-08.json); the inventory records candidate hashes, not invented live preconditions, and is not an installer. Do not install an old full snapshot or run a copy-all command. Reconcile live hashes and independent edits, hold the existing shared release lock and create private preimages. Deploy the noindex order view and sitemap exclusion together. Install the earlier schema/guidance/cache files from the reviewed current tree, preserving unrelated work and the owner's sitemap section design. There is no migration, seeder, queue, cron or provider operation in this continuation.

The initial task prohibited direct production deployment; the owner subsequently authorized the limited website and purchase-tag release. This authorization does not replace live hash reconciliation or the acceptance checks below. The earlier cPanel automatic-approval denial was recorded without a workaround; the resumed access checks and their actual outcomes are reported separately below.

After authorized installation: verify all three languages, representative hosting/Linux/Windows VPS pages, real availability and prices, cookie/CSRF cache boundaries, full/index/sectional sitemap exclusion, order noindex, and mobile interactions. Refresh only affected views/page-cache generation and changed PHP entries according to DEPLOYMENT.md. Roll back only guarded preimages of the changed files if acceptance fails.

Measure title/query cohorts after four settled weeks from actual publication. Fresh first-party reporting is available through the existing alternate analytics connection; GSC Wizard and Ahrefs subscription restrictions do not establish a traffic outage. Traffic growth observed before this publication cannot be attributed to this candidate.

## Resumed review after PR #286

GitHub and a fresh fetch confirm PR #286 merged as `f3f6ed21d740751baea267585fe399bed160b0fe`. The follow-up corrects five remaining claim groups in `config/hosting.php` across fa/en/tr: numerical NVMe/failure guarantees, human-only round-the-clock support, universal OS/custom-ISO availability, guaranteed inbox delivery and absolute white-label invisibility. Config keys, plan prices, resources and availability remain unchanged. The complete commercial/sitemap test list was rerun after these edits: **93 tests / 8,156 assertions passed** on PHP 8.4.12. The 33-file candidate inventory was refreshed; it still contains no invented production preconditions.

Fresh country checks in all three languages found Netherlands indexable with twelve public plan rows and present in the main sitemap; its old missing-sitemap concern is resolved for this snapshot. Turkey has no public plans, remains noindex and is absent from the sitemap. Iran remains in the sitemap but noindex for unverified guests: global sitemap eligibility and visitor sales gating deliberately use different rules. This is an unresolved public marketing/indexability design; the verification and sales restrictions were preserved. Production's split-sitemap URLs return 404, so installation of the owner's separate route/robots work cannot be assumed.

Authenticated GTM **JSON Preview** exports established the actual workspace-versus-live-version-8 diff despite the visual comparison saying the versions were identical: tag 15 adds `getEcommerceDataFrom=dataLayer` and an empty monitoring metadata map. No other tag, trigger or variable changed. The purchase custom-event trigger and target measurement ID are unchanged. A real Preview was attempted against `/gpu`, but Tag Assistant could not establish the debug connection. No purchase was generated and the container remains unpublished; revenue receipt is still unverified.

The existing cPanel terminal failed its WebSocket handshake and fresh browser navigation returned `ERR_CONNECTION_CLOSED`. No production file was written. The owner was asked to reopen and sign into cPanel in the connected Chrome. The local candidate visual run was retried and Chromium again failed before page launch with `socket() failed: Operation not permitted`; visual acceptance is still open.

A bounded public performance inspection found about 780 KB of uncompressed hourly HTML, with roughly 525 KB in the full SSR plan table. One app-duration sample is not a speed regression or a long-task diagnosis. Preserve crawlable plan links and obtain a trace after the existing cache/schema release before changing table rendering. Public CSS matched the existing bundle guard assumptions; verify the deployed layout calls and actual runtime asset/font paths rather than weakening the safety fallback.
