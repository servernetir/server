# SEO and mobile performance — 2026-10-10

The mobile hourly VPS and GPU pages now run GTM/GA4 in Partytown 0.15.0. Event queuing, currency conversion, existing consent, desktop/account/order loading and fallback behavior are retained. The hourly page stylesheet is pushed into the existing head stack so it is available before content paint. Homepage font subsetting, CSS minification with a source fallback, lighter mobile decorations and curated internal links accompany the performance changes.

PR review found that waiting for load/idle or the first pointer event could lose fast-navigation events. The reviewed branch now starts the container immediately while the document is parsing, retaining the mobile worker scope. The Node checks include loading-state hourly, homepage and other-product paths. The immediate-start correction merged via PR32 is now selectively deployed on production. Ten fresh local Lighthouse mobile runs had TBT below 200 ms and CLS 0; two of five runs per page exceeded 2.5 s LCP. PSI returned 403, so these runs are not directly comparable to earlier PSI measurements. Worker page_view/scroll transport and purchase navigation to login were verified; GA4 receipt and payment were not. The temporary noindex fixture was moved to private storage and public 404 was verified. Roll back this stage before the older 14-file hourly/worker rollback. See [deployment record](../SEO-ANALYTICS-START-DEPLOY-2026-10-10.md) and [all ten measurements](measurements/analytics-start-2026-10-10.json).

Review validation: 108 distinct relevant PHP tests / 2,897 assertions passed on the initial PR checkout; the affected five analytics tests were rerun after the loader correction and passed with 40 assertions. The Node loader suite now covers 13 paths. GitHub had no configured checks/runs reported for this head. See [the PR review record](../PR32-REVIEW-2026-10-10.md) for scope and evidence.

Production changes have already been deployed selectively with fresh snapshots, backups and hash guards. This branch reconciles those SEO changes with `develop`; it preserves upstream application changes. Do not upload whole templates from this checkout over production: production also contains changes from other work. Guide text edits live in the CMS/database; the dated reports document them. Unrelated billing, account, infrastructure and consent changes from the shared local checkout are excluded.

Earlier PSI measurements before the immediate-start deployment: five serial Moto G Power / Slow 4G hourly runs, TBT 0 ms, LCP 1.654–1.664 s and CLS 0. GPU had five runs at TBT 0–3 ms and a later control at 1 ms / LCP 1.503 s / CLS 0. These are lab results; origin CrUX still reports LCP 2.6 s and INP 185 ms over 28 days. A historical hourly CLS 0.240 is retained in the reports. Analytics transport was observed in a temporary fixture that was removed; no live purchase or synthetic purchase event was sent.

From repository root:

```sh
node scripts/seo-tbt-worker-test.cjs
node scripts/seo-products-site-test.cjs
node scripts/seo-analytics-worker-health-test.cjs
```

Worker dependencies are locked in `tools/seo-analytics`. Run `npm ci --ignore-scripts` there, then `python tools/seo-analytics/build.py` from root to check a byte-identical rebuild. `--write` intentionally replaces generated worker files. The local changes to upstream are queue restoration during fallback and a small health reporter; that reporter retains only public event names, kind/status and timing in bounded browser memory. It captures native messaging before initialization and catches reporting failures. Review these patches when upgrading Partytown. Preserve its MIT license.

Eight changed Blade templates compiled and passed PHP lint. Five isolated analytics PHP tests passed with 36 assertions. Legacy hourly page tests against the latest production snapshot have identical failures before/after the stylesheet move; they are not claimed as passing acceptance tests. Details are in [the final hourly report](../SEO-HOURLY-HEAD-2026-10-10.md).

The daily monitor reads [WORK-QUEUE.md](WORK-QUEUE.md). Do not duplicate it or redeploy historical experiments. Do not activate the rejected async critical CSS or worker diagnostic v2. Current deployment, rollback order and scope: [worker report](../SEO-TBT-STABILITY-2026-10-10.md), [hourly completion](../SEO-HOURLY-HEAD-2026-10-10.md), and [infrastructure index](../NETWORK-CURRENT.md). Production rollback must use live hash guards; it cannot be replaced by reverting Git alone.
