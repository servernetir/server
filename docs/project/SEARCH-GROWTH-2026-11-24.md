# Commercial search growth — 24 November 2026

Base: `12eee2e34a94e4ee44b59c9cac5936538350daf8`, develop. Scope: public discovery, readable service guidance, a reviewed Python deployment article, and bounded rendering performance. Private Search Console click/query data stays out of this public repository.

## Resulting behavior

- GPU selection and hourly/monthly billing articles gain localized, curated links to the relevant product. Existing editorial bodies and publication dates are preserved. The two existing GPU product-card mappings are included in the limited release because they were absent on production.
- AI, GPU, Python hosting and n8n pages gain distinct practical decision and acceptance guidance in Persian, English and Turkish. Guide cards are suppressed when the article or the current-language translation is missing. The AI section does not load the blog index because it has no article cards.
- AI home adds a cURL chat example using an available chat model, an environment-held API key, a small output cap and an idempotency key. A selection placeholder is shown when no ready model is available. Python output is printed explicitly. No inference is sent by these changes.
- The new three-language article `python-hosting-django-flask-deployment` explains virtual environments, WSGI, a small Flask health endpoint, Django checks, deployment acceptance and common errors. It maps to Python hosting. The existing snapshot-guarded `content:apply-growth` command publishes it independently from the web release.
- Existing `PublicPageStyles` hooks are enabled in the production layout, chat and VPS catalogue. Source already contained the hooks; live production omitted them. Complete, guarded CSS/font handling is preserved. Python hosting, AI home and n8n join the explicit public-page set. Small n8n/GPU styles inline only when readable and safe; unknown or missing files retain the external fallback. Private account, login, admin and checkout exclusions remain covered.

## Live evidence and preservation

Fresh PageSpeed mobile baseline for `/gpu`: performance 99, LCP approximately 2.0 s, TBT 0 ms and CLS 0. Public field data: LCP 3.6 s and TTFB 2.5 s over the latest 28 days. These are different measurements; the difference does not establish an improvement or regression. The trace identified the lead paragraph as LCP and four render-blocking CSS files, with estimated potential savings of 1,130 ms. That estimate is not a promised result.

Baseline report: https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/y1v83f2wkxqvs3v3f2rutg?form_factor=mobile

Preflight used the actual document root. A CLI call without `DOCUMENT_ROOT` fell back to `servernet_app/public` and could not find a chat stylesheet. Rerunning with the independently established `public_html` root validated the runtime bundle. No fallback guard was weakened.

Overlays preserve the live GPU capacity script/widget, unrelated layout differences including CRLF, and the live VPS purchase/verification branch. Source and public asset copies differ; this release does not replace stylesheets or copy all of `public/`.

## Validation

Local runtime: isolated PHP 8.4.28, SHA-256-verified official Composer artifact, locked dependencies, no production environment or database. Relevant PHP acceptance: 108 cases, 6,424 assertions, 0 errors/failures, 1 conditional skipped Bria fixture. Thus 107 executed cases passed. The earlier 36-case iteration also passed.

Baseline legacy `GrowthArticlePublishingTest` had three failures and one error before implementation: old quote-only n8n expectations, legacy redirected routes and a removed product view-data assumption. That entire legacy file is not represented as passing. Existing snapshot-guard publishing behavior and the new article are covered by focused acceptance tests.

Seven release tests cover untouched preimages, live-overlay preservation, all-file drift refusal, activation rollback order, refusal to overwrite newer edits, ancestor symlinks, CRLF preservation and helper hashes. All use disposable files.

## Limited release

The manifest pins 17 runtime targets; four use exact preimage overlays. All new dependencies install before callers. Staged Blade templates compile and lint before installation. Activation checks two previously verified, same-account FPM pools and compiles 11 views through a temporary HTTP-denied primary. Only listed page-cache entries are refreshed.

No blanket cache flush, migration, schedule run, provider operation, financial setting, analytics installation or Google-data connection is part of the code release.

Commands take a fixed reviewed develop SHA: `prepare`, `apply`, `verify`, `activate`, `rollback`. `recover` only reconciles an interrupted receipt. An unknown SSH outcome must be inspected, not rerun. Rollback refuses newer installed changes, restores callers and activates them before retiring new dependencies. The independently published CMS article is retained; code rollback does not delete editorial content.

After publication verify three languages, the GPU configurator, AI copy buttons, article links, canonical/hreflang, sitemap inclusion and a fresh PageSpeed comparison. Assess traffic after a comparable full period. Field-data validation and completed indexing require Google's later result.

## Production acceptance

PR435 merged to develop `12e2bc6868524b0eaed40ced57d4a9d47c46c994` after all nine triggered CI workflows succeeded. The pinned 17-file manifest was prepared, installed and verified on production. Both existing FPM pools activated the release, compiled 11 listed views and refreshed only 45 explicit page paths. No general cache flush.

Twelve public pages (AI, GPU, Python and n8n in three locales) returned 200 with the new guidance, correct language, one H1 and self-canonical. GPU capacity refresh and the live purchase widget were preserved. Browser acceptance exercised GPU filtering/selection and recalculated totals without submitting an order, and copied the AI cURL example successfully.

The three-language Python article was independently published. All three public URLs return 200 with matching canonical/hreflang and appear in the current blog sitemap. The native Google-discovery worker recorded sitemap acceptance for the article; this is not a direct indexing request or an indexing guarantee.

The first post-release mobile run was performance 97, LCP 1.9 s and CLS 0.088. A second run after embedding the exact served medium font was performance 96, LCP 2.0 s and CLS 0.089. Render-blocking stylesheet warnings disappeared, but the GPU hero still shifted and the runs did not establish a speed gain.

Accordingly, GPU is removed from the inline-bundle eligibility list. It retains the original external CSS/font-preload path, the independently safe small auxiliary CSS, all published guidance and its configurator. Other explicit eligible public pages retain their guarded complete bundles. This measured fallback is a one-class limited release with live hash checks, targeted GPU page-cache invalidation and both-pool style-contract checks. A final fresh public measurement is recorded separately; the 28-day field result remains a later outcome.

Public routing follow-up: PR436 merged as `a0bfe990f0230a747993e4f59ca5da3c4c5e83a2`, installed two targets and verified both FPM pools. All 25 sampled historical mail-host duplicates redirected 301 to the matching primary URL, whose final response was 200. The canonical validation request in Search Console was observed as started on 24 November. See MAIL-CANONICAL-2026-11-24.md.

Status: main release and canonical follow-up installed and accepted; the bounded GPU rendering fallback at this commit is tested locally and awaits installation. Rankings, full canonical validation and field performance remain later results.
