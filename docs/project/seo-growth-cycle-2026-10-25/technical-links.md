# Product reading paths — 25 October 2026

Status at handoff: **implemented and tested locally; production publication is recorded by the release owner separately.** Base: `f396d1ebf7116df11000deeecaf9ebd83560bd71` on `develop`.

## Observed problem

Fresh public GETs on 25 October showed `/hosting/python` recommending “What is a client”, “Iranian Discord servers”, and “Diagnosing intermittent downtime” in its three product guide cards. The helper selected posts by a deterministic rotation within a broad category. This distributed links but did not ensure that the reading path matched the product. Public `/cloud/object-storage` and `/cloud/block-storage` each returned HTTP 200 but contained **no article links in their main content**, including no link to the existing Object/Block comparison.

Google's [link best practices](https://developers.google.com/search/docs/crawling-indexing/links-crawlable), checked on 25 October, recommend crawlable links, descriptive anchors relevant to both pages, and contextual links to useful related resources. This change is an application of that guidance; it is not a prediction of ranking, traffic, or conversion gains.

## Implemented behavior

| Product paths, in fa/en/tr | Preferred articles, in display order |
|---|---|
| `/hosting/python` | `diagnosing-slow-website`, `configuring-dns-for-new-site`, `measuring-real-user-performance` |
| `/hosting/linux`, `/hosting/wordpress` | `php-version-upgrade-guide`, `cpanel-vs-directadmin`, `diagnosing-slow-website` |
| `/cloud/object-storage`, `/cloud/block-storage` | `object-storage-vs-block-storage` |

These are existing article URLs. Python's path covers deployment prerequisites and operation—DNS verification, response diagnosis and measurement. It does **not** claim to provide a Django/Flask installation tutorial; a product-specific verified deployment guide remains a separate content opportunity.

The three Python candidates had substantive corrections in the previously published technical content. Their live body, source links and localized destinations were read again for relevance in this pass; see [guide-destinations.json](guide-destinations.json) for nine current HTTP/language/canonical receipts. This is a bounded review, not a fresh execution of every command or independent reproduction of the historical performance measurements quoted in those articles.

For mapped products, `blog_guides()` reads the existing `BlogRepository` index and returns only selected published blog entries whose translation matches the current locale. Missing articles, drafts, knowledge-base entries and absent translations do not create links. Duplicates are removed, the requested limit is respected, and unused slots are not filled with unrelated posts. Unmapped products retain the previous deterministic category rotation and fallback.

The two refreshed articles also have direct product overrides in both existing related-service placements:

- `object-storage-vs-block-storage` → `/cloud/object-storage`. Its body separately explains both services. The helper uses the live storage page's localized title and introduction, not obsolete claims in `config/catalog/cloud.php`.
- `php-version-upgrade-guide` → `/hosting/linux`.

No price, stock, sales readiness, provider configuration, canonical, hreflang, redirect or indexing policy is changed. The storage view includes its reading path after the existing conditions section. Storage Box's page remains outside this path.

## Actual validation

Runtime: PHP 8.4.27, existing locked dependencies, isolated SQLite fixtures, no live credentials or paid provider calls. `Http::preventStrayRequests()` is enabled in the new tests.

```text
php artisan test --filter='ProductGuideRelevanceTest|PythonHostingSeoTest|Audit4RegressionTest|StorageSalesSafetyTest::test_public_storage_pages_are_available_but_object_storage_has_no_buy_link' --compact
13 passed, 158 assertions
```

The seven new behavioral tests account for 100 assertions. They render Python, Linux/WordPress, Object/Block and both refreshed article pages across fa/en/tr; verify actual anchor destinations/order and current localized storage copy; reject drafts, missing translations, wrong content type and duplicate candidates; preserve the unmapped fallback; and retain the unavailable Object Storage sales state. PHP lint of both changed PHP sources and `git diff --check` passed.

Live publication acceptance should verify the 15 mapped product-language URLs and six article-language URLs after the release owner applies the reviewed content packages and invalidates the relevant caches. No live acceptance or Google-selected-canonical result is asserted by these fixture tests.

## Deferred findings for the recurring work queue

`git-basics-for-deployment` contains an oversimplified public `deploy.php` shell execution example; an IP list is declared but not enforced, followed by a query-token fallback. `staging-environment-setup` contains outdated action examples, a claim about a built-in ServerNet staging service that was not verified, and wording that treats `robots.txt` as an indexing prohibition. Those articles were deliberately excluded from the new product reading paths. They need separate source-backed editorial refreshes at their existing URLs, preserving publication dates and requiring CMS snapshots before writes.

Do not infer that all existing blog articles were audited or that the three performance guides are a substitute for a framework-specific hosting tutorial. The next useful Python article should be grounded in the product's actual permitted runtime, entry point, dependency installation, logs, restart behavior, limits and rollback process.

## Publication follow-through: truthful blog sitemap modification dates

The release review found that `SiteController::sitemap()` always used a blog post's original publication date for `lastmod`, even after substantial translation edits. That would leave the two revised articles reporting July/August dates. The narrow controller change now selects the later of the original publication date (creation date when publication is absent) and **that URL's own translation `updated_at`**. It does not use unrelated `posts.updated_at`, alter publication data, add missing EN/TR translations or change existing sitemap URL eligibility. Other sitemap groups are unchanged.

Google's [sitemap documentation](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap), checked on 25 October, says modification dates should reflect genuine significant changes and be consistently accurate. No date is set to the current time merely because a sitemap is generated. Existing blog URLs can now report their actual historical edit date; this is not a claim that all those articles were rewritten in this release.

`BlogSitemapLastmodTest`: **4 tests / 27 assertions passed**, with actual XML responses. An English body edit advances only the English URL; Persian/Turkish dates and publication remain unchanged. Tests also verify that edits before publication cannot move `lastmod` backwards, unrelated post metadata does not refresh it, missing translations/drafts stay absent, and legacy rows retain their creation-date fallback. Controller PHP lint and `git diff --check` passed. Live sitemap/cache acceptance remains the release owner's step.
