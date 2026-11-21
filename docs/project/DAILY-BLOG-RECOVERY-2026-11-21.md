# Daily blog recovery and popularity evidence — 21 November 2026

Base: develop `0414e49d`. Active application: `website/`. This report distinguishes prepared code from production installation.

## Observed production problem

The authenticated Growth SEO page showed the 16 and 17 November daily-insight bundles published in fa/en/tr. The 18, 19 and 20 November bundles existed in all three locales but remained drafts. The corresponding completed provider receipts demonstrate generation, not publication. The general GrowthAutopilot review-backlog notice belongs to a separate program and is not evidence that the native daily publisher stopped running.

Read-only inspection of the complete saved 20 November bundle reproduced the failure locally: `GrowthClaims::findings` returned `sla` for Turkish text containing `kıyaslama testleri, 64 bayt ve 256...` and `kıyaslama testlerinde, 2-4...`. The old case-insensitive SLA expression matched the substring `sla` in `kıyaslama`. The Persian and English versions passed. After the word-boundary correction, all three original locale documents pass the article quality checks without another model request. No conclusion about the exact 18/19 November failure is claimed; their saved receipts must still be inspected.

Raw drafts, account data and source snapshots are not committed.

## Changes

- Require a standalone SLA/uptime token, including compact numeric forms such as SLA99; retain the existing percentages, price, traffic, delivery and coverage checks. Normal Turkish/English words no longer trigger SLA claims.
- Persist a specific failing locale and reason, and show recent native daily receipts in the authenticated SEO/automation page. Source body snapshots are excluded from status output.
- Revalidate a historical completed bundle with `growth:daily-blog --resume=YYYY-MM-DD --actor=ID`. It never calls the model, checks the current delegation, completed jobs and unchanged draft versions, retains the original daily URL date and publishes all three translations atomically. It refuses partial, active or operator-edited bundles. Repeated resume of a published receipt is idempotent.
- Future runs may queue one corrective request per failing locale. Passing locales and original paid receipts are preserved. Every repair is quoted and reserved under the existing daily/monthly caps, has the same revocable program authority, and cannot retry a failed/uncertain provider call. A second quality failure remains visible and terminal. Disable `daily_blog_quality_repair` to `0` to restore no-repair behavior; budgets are not raised.
- Supplement freshness/relevance ranking of at most eight official-source candidates with a bounded public Hacker News/Algolia lookup. Count only exact URL matches and discussions no older than seven days, retain observed points/comments/evidence URL and checked time, and cache for 15 minutes. Three-second lookup limits and fail-open-to-freshness behavior avoid making publication depend on an optional signal. Missing popularity evidence is displayed explicitly. This measures one community's interest; it is not a claim of globally highest trends, Google Trends data, or guaranteed same-day source publication.

## Google discovery dependency

PR322 is already in develop but its documented production installation is pending. `GET /admin/ai-growth/google-discovery` displayed a 404 during inspection. No successful automatic sitemap submission receipt has been observed.

Deploy the existing discovery feature with its own scoped preimages and hash guards: `SearchConsoleClient`, `GoogleDiscovery`, `GoogleDiscoverBlog`, `SearchConsoleController`, the Calendar callback dispatch, `routes/ai-growth.php`, the discovery view and fa/en/tr strings, plus the publisher hook. Reconcile shared files against current production rather than copying all of develop. See [original discovery deployment requirements](GOOGLE-BLOG-DISCOVERY-2026-11-16.md).

The owner must grant the separate Search Console write permission once. This release does not copy, repurpose or broaden the existing read-only reporting, Calendar or Drive grant. Google accepts a sitemap PUT; URL Inspection reads real indexing state. It is not a direct article Indexing API request and does not guarantee indexing.

## Validation

Local PHP 8.4.24 and copied locked dependencies, isolated `.env`-free worktrees, SQLite memory, fake providers only:

- Candidate combined DailyBlog, GrowthClaimsBoundary, GrowthCenter, GrowthArticlePublishing, GrowthAutopilot and GoogleDiscovery suites: 93 tests, 89 passing, 847 assertions; four failures.
- Three of the failures were reproduced in the unchanged base DailyBlog suite (null cost assertions and the reviewed-retry publication expectation). The fourth was reproduced in the unchanged base GrowthAutopilot suite (null cost assertion). No new failure is attributed to this change. This is not an all-green suite claim.
- Focused new-behavior suite: nine tests / 45 assertions passed before the compact-SLA follow-up; the final combined suite includes that additional passing boundary assertion.
- Added behavior checks cover selective one-locale repair, terminal repair failure, budget refusal, idempotent no-inference historical resumption, operator-edit refusal, snapshot redaction/admin receipts, observed exact-URL popularity and SLA word boundaries.
- The real saved 20 November texts were checked locally without paid inference; all three pass after the SLA correction.

## Production acceptance and rollback — pending

Production code, cron and database state have not been changed in this work. Automatic browser review rejected opening the signed-in cPanel origin, citing the owner's earlier explicit prohibition. No workaround was attempted. Explicit owner authorization to enter cPanel is required before continuing live deployment.

After authorization:

1. Confirm account, live baselines and shared release locks; preserve concurrent registration/security/SEO changes. Build a file manifest with live before/after SHA256, private backups and targeted PHP lint/view compilation. Do not flush shared caches or overwrite the application.
2. Install the scoped native recovery files and the existing Google discovery feature. No migration or credentials change is part of the file install.
3. Read `growth:daily-blog --status`, then resume 20 November using the delegated actor. For 18/19 November, revalidate saved bundles and inspect the exact failure before deciding on any bounded repair; do not regenerate completed passing locales or erase receipts.
4. Preserve the existing native 15-minute cron and its one-per-Tehran-day gate. Install exactly one marked five-minute Google discovery cron after confirming it is absent; preserve all other entries.
5. Enable discovery and complete the one-time owner consent. Queue existing eligible published articles, observe the real accepted sitemap receipt, then verify all three public URLs and inspection outcomes. Never equate accepted sitemap with indexing.
6. Observe the next natural daily run. Report selected source date, popularity evidence or fallback, each locale's final stage, public URLs and Google receipt separately.

Rollback: pause native generation or disable only repair before restoring preimages with current-hash checks. Pause Google discovery before removing its marked cron entry; retain paid jobs, drafts, published posts and discovery receipts. Do not revoke a shared Google OAuth client or roll back unrelated files.

## Live scoped installation — 21 November 2026

The owner explicitly authorized installation/publication in the next turn. Routine cPanel login with the existing saved account succeeded; the account identity `servernetcloud`, application root and PHP 8.4.28 were verified. The existing marked 15-minute native cron was present; no Google-discovery cron was present initially.

Twenty-two reviewed runtime sources were staged from pinned commit `e2350091d7dc18617a410f9025d053c836f776a7`, independently hash-checked, backed up privately and installed under three shared release locks plus the Growth worker lock. Nine existing files matched the known base, twelve were absent, and one route-file overlay was reconciled: only the four discovery routes were added; the live Hermes action allow-list was preserved. PHP and targeted Blade lint passed; only the three Growth views were compiled. No migration, environment change or shared cache flush was performed. The first preflight's standalone Blade compiler failed before any live file write; the application-aware compiler succeeded. All 22 after-hashes were verified. The Google discovery UI is now reachable and its policy was enabled.

Native no-inference resumption published the saved 18 November bundle as Post 580 and the 20 November bundle as Post 581, each with three translations. The 19 November bundle still contained price assertions in fa/en, and the 21 November Persian provider response was truncated/invalid JSON; en/tr were complete. No new inference was used for this diagnosis or initial recovery.

### Bounded saved-locale correction

A follow-up adds `--resume=YYYY-MM-DD --repair-saved --actor=ID`. The default `--resume` remains inference-free, but now records malformed JSON as a locale validation failure instead of aborting the entire inspection. The explicit repair path uses the existing one-per-locale quoted repair and existing budget caps; passing locales and paid receipts remain unchanged. It processes the saved daily key, including historical dates, and retains original URL dates. Repair instructions omit numeric prices/promises rather than weakening the commercial-claim filter. No automatic historical backfill is added to the ordinary cron.

Regression coverage includes truncated fa JSON with passing en/tr and an idempotent historical correction. The follow-up must pass CI before live installation and any corrective provider call. Subsequent publication, cron, authorization and sitemap acceptance results are recorded separately; reaching the connection UI does not prove Google authorization or submission.

Private installation receipts and rollback preimages: `deploy-backups/blog-20261121/`. Temporary source staging is outside the account root. The small preview/install tools are retained in `scripts/daily-blog-release-20261121/`; they never contain credentials or live data. The original installed manifest must be retained even when a guarded follow-up changes two of its files.
