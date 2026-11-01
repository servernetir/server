# Blog metadata author loading — 2026-11-01

Local implementation on `codex/seo-daily-20261101`, base `891a9235`; no Git commit or production deployment by this agent. The root desktop checkout was read only and remains untouched.

`BlogRepository::index()` loaded translations, then resolved `author` once for every published post while mapping public metadata. The article route also calls this index through `related()`. The one-line change eager-loads only `author:id,name` alongside translations. `find()`, text, prices, routes, eligibility, cache key/TTL and invalidation are unchanged.

The regression test was written and run on the original source first. All six cases failed only at the expected performance assertions; complete expected metadata and warm-cache behavior had already matched. With eight Persian rows there were seven author lookups/nine total SQL queries; English/Turkish had seven rows, six author lookups/eight total queries. For three then fifteen published posts by one author, each locale performed three then fifteen author queries.

After the change, each cold fixture needs one author query and three total queries. The growth fixture remains one author query at both sizes; warm indexes need zero SQL. Exact output checks cover ordering, localized title/excerpt/tags, publication and translation-edit dates, author names, null/deleted-author fallback, omission of untranslated English/Turkish entries and exclusion of draft/knowledge-base posts. The new tests use synthetic data, SQLite memory storage and a fake array cache; HTTP stray requests are refused.

Final combined validation: **31 tests / 124 assertions passed**, containing the six new locale cases and existing related-post clustering, listing canonical and sitemap-lastmod tests. Both changed PHP files lint and `git diff --check` passed. See `blog-author-loading-baseline.txt`, `blog-author-loading-regression.txt` and `blog-author-loading-receipt.json`. The earlier focused after-run (`blog-author-loading-after.txt`) is not added to the final test count; one extra total-query assertion per locale was subsequently included in the combined run.

This removes a reproduced query pattern. It does not establish the cause of the 129-URL mobile LCP group or measure page-speed improvement. Actual cold/warm mobile profiling of the English PHP guide and a live-source comparison remain necessary. MariaDB CI and production acceptance are the parent's release steps. No LCP, purchase or GA4 delivery result is claimed.

Rollback scope is the single eager-load line against a current-source guard. Output and cache identity are unchanged; no data rollback or broad cache flush is needed. Concurrent unrelated changes must be preserved.
