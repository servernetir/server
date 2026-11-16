# Search and AI discovery corrections — 16 November 2026

Base: `1f8b8e15399d91132253e6aeda9144662bd97f5c`, branch `develop`.

## Scope

The public `/llms.txt` response advertised SSH on the hourly GPU service even though the product FAQ explicitly excludes SSH/root access. It also linked eleven intentionally noindex order forms and calculated yearly-equivalent prices in the discovery document. Product landing pages are already present and are the appropriate place to check current rates, eligibility and billing terms.

The correction describes the supported GPU applications, temporary storage and application endpoint access; removes the separate checkout discovery section and its product-price query; and qualifies the expiry guidance to Offers that actually carry `priceValidUntil`. It preserves the IRR-to-toman conversion note and the fa/en/tr entry points. It does not change crawler permissions, product eligibility, pricing or order indexability.

The PHP upgrade guide source receives clearer titles and descriptions in fa/en/tr, explicitly naming cPanel and VPS, testing and rollback. Existing bodies, sources, links, tags and URL remain intact. The article body already covers these topics; no version or compatibility claims were added.

## Public validation

A read-only audit fetched all 142 unique links in the live discovery document. Eleven successful responses were noindex order forms; 113 other links returned successfully; 18 requests returned HTTP 503 on their first attempt. A separate follow-up to `/vps/sweden` succeeded. Two URLs appearing in Google's server-error examples, `/ai/models?type=embedding` and `/en/parts/cpu/xeon-e5-2640-v3`, also succeeded on follow-up. This does not establish origin health or the cause of intermittent failures; response-path failure and origin failure must be distinguished with server evidence.

The sectioned `/sitemap-index.xml` is valid XML listing products, blog, docs, tools, hardware, AI and pages. It was submitted in Search Console; the detail page reported **Sitemap index processed successfully** on 16 November. This is acceptance of the index, not proof that every child URL is indexed.

The published GTM JavaScript still contains the expected Google measurement destination and existing purchase/checkout/lead events. Literal `product_cta_click` and `hourly_vps_interaction` names are absent. This is a configuration-inspection finding, not transport validation or proof of all runtime behavior. The existing bounded addition procedure is [SEO-GROWTH-ANALYTICS-2026-10-25.md](SEO-GROWTH-ANALYTICS-2026-10-25.md); it needs a fresh authenticated export, collision checks and Preview before publish.

No customer records, cookies, credentials, screenshots or private analytics exports are included in this document.

## Validation and release status

- `git diff --check`: passed.
- JSON metadata checks: all three locales present; titles 53/54/53 characters, descriptions 133/149/145; article bodies and tags unchanged against the base.
- PHP and Composer are absent from the task runtime. PHP lint and Laravel tests were not executed locally. Existing Commercial SEO growth CI is triggered by the controller change and must be checked before merge/deploy.
- Code publication is independent of production installation. The browser's admin save attempt ended with a connection failure; subsequent public reads still showed the original metadata. Treat the article update as **not confirmed in production**. Inspect the edit form and live metadata before retrying.
- cPanel and Tag Manager navigation also returned connection-closed errors. No source deployment, cache activation or GTM publication occurred.

## Next operational steps

1. Restore authenticated cPanel connectivity. Read free space/inodes, FPM/web errors and proxy/CDN responses around a fresh 503 before choosing a fix. Do not delete files or attribute all failures to disk based on historical errors alone.
2. Reconcile the live SiteController with the exact changed hunk; use the deployment manifest, private backup, lock and before/after hash workflow in [DEPLOYMENT.md](DEPLOYMENT.md). Refresh only relevant caches and verify `/llms.txt` plus unchanged product behavior.
3. Inspect current saved PHP guide metadata. Apply this source's title/excerpt values through the admin editor if still old; preserve the existing article bodies and translations. Verify all three public URLs and rendered metadata.
4. Use current GSC page/query data to prioritize GPU, hourly VPS, the PHP article, relevant hosting/country pages and domain tools. Keep language-specific search intent, real availability, sources and useful internal links. Avoid creating thin country pages or broad redirects for unrelated legacy URLs.
5. Diagnose the reported mobile LCP with a reproducible trace before changing the shared layout. Existing `PublicPageStyles` rollout is incomplete; do not blindly enable it globally or replay historical deploy scripts.
6. Complete the two bounded GTM events using the existing import procedure. Validate consent, one event per action and event parameters. Diagnose revenue reporting with a permitted real transaction receipt; do not send synthetic purchases.

Rollback: restore only the changed controller hunks from the deployment's private preimage, then perform scoped cache activation; restore the previous six title/excerpt values through the editor if required. A Git revert alone does not revert database-backed article metadata or prove the live code changed. No database schema, customer state, financial operation or infrastructure state was changed by this patch.
