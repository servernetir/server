# Growth plan review — 8 November 2026

Source: https://claude.ai/artifact/Vg9rhmfGQAnGGzU5UKjTha

Scope: implement current, reversible website improvements after checking the plan against code and current Google reports. The owner completed sitemap changes separately; this package does not edit sitemap generation, sitemap submissions or robots.txt. Reference branch: `develop`.

The owner's sitemap PR #284 (`87550ff`) was incorporated as the final baseline without modifying its files.

## Decisions and implementation

| Plan area | Finding and disposition |
|---|---|
| Baseline and positioning | Direct Search Console shows commercial hourly VPS and GPU traffic. Ahrefs estimates in the plan do not represent total first-party traffic. Concentrate on existing commercial pages; avoid unverified country counts, delivery guarantees and ping figures. |
| Backlinks | Manual Actions reports no issues detected. A third-party SPAM label alone is insufficient for a blanket disavow upload. No links disavowed, purchased, exchanged or published. |
| Performance | A real GET already returns application-cache HIT. HEAD is intentionally bypassed and is not evidence that GET caching is broken. This package lets strictly anonymous sessions reuse HTML while preserving private headers, current-session CSRF, both authentication guards, flash/error and unknown-session-state bypasses. |
| Public edge caching | Deferred: HTML contains per-session CSRF data. Do not set public/s-maxage or a Cloudflare cache-everything rule on this HTML. A separate sessionless rendering design and form/auth acceptance are prerequisites. |
| Indexing | Order handoff pages now emit noindex,follow in fa/en/tr. Product pages carry search discovery. Existing search/filter exclusions are retained. No blanket noindex of hardware or AI model pages without demand/equivalence evidence. |
| Sitemap | Owner-managed, excluded from this release. The repository's existing sitemap assertions are not treated as acceptance of the owner's separate changes. |
| Product discovery | Country pages gain workload guidance and quick links to prices, selection advice and FAQs. The hourly calculator link requires a current hourly checkout path. Turkish country anchors are localized. Existing factual pricing and stock logic is preserved. |
| Content and internal links | Article-generation link inventory now includes relevant hourly VPS/GPU destinations. Localization, route resolution and unpublished-content guards are preserved. No bulk generation, deletion or rescheduling. |
| Free tools | Contextual, translated product links after the useful tool content, with status/SLA links. The click event carries only a fixed tool slug and product family; user inputs are excluded. |
| Schema/AEO | Organization sameAs and tool application schemas already exist. Merchant error examples are old country-page Product items; the existing correction is under Google validation. No fabricated ratings, reviews, images, stock, shipping or ping added to suppress warnings. |
| International markets | Preserve existing localized flows. No unverified claims about payment availability, new payment contract or gateway registration. |
| Funnel | Signup, catalog, checkout, invoice and payment events already exist. Delivery and asynchronous settlement coverage remain measurement work; a browser flash event is not an authoritative financial ledger. This release does not alter billing or delivery. |
| Retention and referrals | Free credits, referral payouts, annual discounts and automated customer outreach need a separate cost/eligibility/message specification. No credit allocation or outbound message is performed. |
| Growth loops | Improve owned links now. Publishing plugins to outside registries, media outreach and paid campaigns are separate deliverables with account/budget requirements. No incentivized ranking links. |
| KPIs | Preserve first-party GSC as traffic source and settled accounting as revenue source. Plan multipliers remain hypotheses, not promised results. Private baseline figures are omitted from the public repository. |
| Roadmap | Today's implementation covers the immediate code changes above. The twelve-month content, partnership and product roadmap is not reported as completed. |
| Tools and accounts | GSC is accessible directly. GSC Wizard currently requires a renewed subscription; no purchase or account creation is performed. |

## Security and acceptance

Anonymous-cache eligibility accepts only Laravel CSRF/navigation bookkeeping and empty flash generations. Authorization headers, staff/customer authentication, any unknown session key, errors, flash messages and request-dependent excluded routes bypass. A second guard after rendering prevents a newly personalized response from entering cache or being replaced by stale HTML. Cache responses remain `private` and vary by Cookie. `PAGE_CACHE_GUEST_SESSIONS=false` restores the previous cookie-bypass behavior.

The tool CTA does not gate or change a free tool. All destinations use localized routes. Country workload content includes no price, capacity or measured-network assertion.

Related checks: PageCache, analytics session consumption, country/hourly/GPU rendering, localized tool CTAs, order indexing and commercial link resolution. Run the explicit test-file list in `.github/workflows/seo-commercial-growth.yml`; avoiding whole-suite discovery also avoids unrelated fixture-loading failures. The broader Audit7 suite has existing cloud-city redirect/sitemap expectations outside this package; do not rewrite those sitemap expectations as part of this task.

Local validation on that final baseline: PHP 8.4.12, explicit CI test list **87 tests / 6,127 assertions passed**, including a real encrypted response-cookie/session roundtrip and distinct visitor CSRF tokens on cache HIT. The fixed-metadata CTA Node test and workflow YAML parsing passed. The focused legacy order/auth handoff run passed 16 tests / 71 assertions. All checks use synthetic local data and HTTP fakes.

Eighteen actual Laravel-rendered HTML fixtures were prepared for fa/en/tr. A headless Chromium visual run was attempted but the execution host denied its required socket; responsive screenshot/overflow acceptance is therefore not claimed and remains part of live acceptance.

## Release boundary and rollback

Git changes, production installation and live acceptance are separate states. Production access was blocked by automatic browser approval review; no cPanel files or production settings have been changed by this task.

After authorized access: use DEPLOYMENT.md, a private backup and before/after hashes for only changed application files and the one CSS asset. Preserve live sitemap edits and other concurrent work. No migration, seeder, queue, cron, account import or financial/provider operation is required. Activate only the relevant compiled views/application page-cache generation and changed PHP OPcache entries; do not flush unrelated stores.

Acceptance: real GETs with/without a guest cookie, current CSRF values, authenticated/flash bypasses, localized tool/product links and responsive rendering; current order noindex,follow. CrUX is a rolling field report, so a laboratory render cannot establish a field LCP improvement.

Rollback the exact changed files from the private release backup after checking for later drift; refresh only affected views/page-cache generation. The guest-session switch can disable that cache improvement independently.

## Primary references

- https://developers.google.com/search/help/office-hours/2023/january
- https://developers.google.com/search/docs/essentials/spam-policies
- https://developers.google.com/search/docs/crawling-indexing/block-indexing
- https://developers.google.com/search/docs/appearance/structured-data/product
