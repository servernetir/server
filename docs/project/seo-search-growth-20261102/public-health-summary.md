# Public SEO observation — 2 November 2026

A bounded **25 GET** sample was completed at05:54–05:56UTC: robots, two sitemaps and22 public HTML pages. All22 HTML responses were **200/BYPASS**, with one H1, one nonempty description, a nonempty title and parseable JSON-LD. No requests were retried or redirected automatically. No source, Git, production, account, provider, pricing or stock state was changed. IRANSans references remain present in all22 pages; this is markup evidence, not a browser font-loading test.

## Actionable sitemap inconsistency

`/vps/sweden` is listed in the main XML in all3 locales, while its fetched Persian page returns `noindex,follow`, no canonical/hreflang and the unavailable-offer notice. This is a concrete mismatch between the XML submission and the empty-country indexing guard. `/vps/iran` shows the same literal XML/noindex conflict to this anonymous request, but its emptiness may be intentional deployed geo/sales visibility: `PublicVpsCatalog` honors a live-only `IranSalesVisibility` class that is absent from this checkout. This observation is not a diagnosis of an Iran supplier, stock or pricing failure.

The source's sellable-country loop is followed by the generic config-catalogue loop, which re-adds all11 legacy country slugs. A scoped fix should prevent reintroducing countries rejected by the canonical public eligibility policy. It must preserve the sales/geo guards and should not blacklist countries from this single HTTP sample. `/vps/austria` is **indexable**, has selfcanonical/four hreflang links and monthly order links, so it must not be removed just because it is a legacy slug. `/vps/uae` remains empty/noindex and is correctly absent from the main XML. No new redirect defect was inferred from the intentional city-to-country consolidation.

Exact URLs, response/main hashes, source hashes and XML membership are in [sitemap-indexability-evidence.json](sitemap-indexability-evidence.json). Sweden response SHA256: `4484f462b4b6daac5ff840090672fc16c0def8ed6047ca2e89b58adc05c9c637`; Iran: `dfc5dea41ba85ccd8efdc1bcb0b7aef5367e6cf3c39b9a61fcea4482466c3f5a`.

## Preserved public behavior

Germany and Philippines now have indexable selfcanonical pages, four hreflang links and order destinations in returned HTML. Hourly has order links and its `#plans`/`#rates` targets; GPU has exactly one `#gpu-configure` form posting to the established `/gpu/order`. Their previously observed empty-state fragments are not currently broken. This accords with the separately authorized1November sales recovery; no recovery or repricing was repeated here. Actual capacity, login/checkout completion and paid purchase were not tested.

The AI home/catalogue/Xiaomi model retained **6/10/7** usage-planner links; the model still has one planner target. The Persian Git guide's article bytes still exactly match its reviewed source, with signed-webhook markers and a localized hourly link. Hosting/Linux, WordPress, domains, Storage Box, HPE disk/server, blog index and English PHP guide were also200. The19 indexable pages have selfcanonical URLs and four hreflang entries; the three empty-country noindex pages omit those tags under the current guard. No missing same-page anchor target was found in the22-page sample.

## Sitemap and limits

Main XML has **5,460 unique URLs**, net+78 versus1November's5,382. Hardware remains **2,115**. Both are200, without within-file duplicates or off-host URLs; cross-file overlap remains2,115 and main query URLs remain45. Robots rules match the1November observation. The net membership change was not fully attributed. Root slash membership is URI-normalized to treat `https://servernet.cloud` and `/` as the same URL.

No404/5xx was returned in this sample; this is not a full thousands-of-URLs sitemap scan or proof that every Search Console historical error is fixed. Link checks are limited to returned same-page fragments, sampled CTA/form paths and bounded internal link extraction; account/purchase destinations were not requested. JSON-LD parsing is not rich-result validation. One-request timing is not LCP/TBT/INP or stable mobile performance. No Google indexing, ranking increase, analytics event delivery or conversion claim follows from this audit. No raw HTML, cookies, authentication headers or account data were saved. Next broad audit remains7November.

Evidence: [public-health.json](public-health.json). No rollback was needed because this audit made no application or production changes.
