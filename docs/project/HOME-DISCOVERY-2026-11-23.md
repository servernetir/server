# Homepage discovery, AI Studio and performance — 23 November 2026

Base: `788ddf479713178a51ba528d383539a91098bb4b` (`develop`). Owner requested better search visibility, speed and accurate AI-assisted product discovery for the redesigned homepage.

## Findings and changes

- Production FA/EN/TR homepage titles and summaries omitted new services. Add distinct, translated homepage metadata synchronized with its WebPage schema. Keep the approved hero headline, six-slide fade, 6000ms loop, size and proof rail.
- Add a permanent text-first service selection section outside the carousel. Six accurate service summaries explain use cases, billing/access distinctions and canonical localized product links. The same content generates its ItemList/Service JSON-LD and the discovery guide in `llms.txt`. Public status, SLA, documentation, AI API and support are independently linked. Do not claim rankings, rich results, universal GPU/root access, unlimited model use or guaranteed inbox placement.
- The existing three `/studio/download` pages deliberately carry noindex while an official signed native release is unavailable. Do not weaken that policy. Add three public `/ai/studio` pages, with original translated product explanations, model rates, browser use, useful Q&A and the distinction between Studio, gateway APIs and GPU compute. Link from the home directory and AI hub. Advertise the landing only while Studio is configured. Add it to full and AI-section sitemaps, while the existing noindex download guide is not added.
- Explicitly allow Claude search/user retrieval under the existing search-discovery policy; maintain the independent training decision. Preserve every existing private-path exclusion and the live query-duplicate exclusions, and synchronize both sitemap declarations into the deterministic source policy. `llms.txt` is a supplementary factual reference, not a prerequisite or guarantee of AI citations; remove the legacy `/vps/gpu` redirect from it.
- Preserve the live homepage globe implementation in source: recover the exact existing geography module and actual atlas partial, preserve country-visibility rules and camera behavior. Defer the 103,948-byte geography data on mobile until interaction, use the locale-specific static poster, start nonessential desktop data after load/idle, stop the Canvas frame loop when the slide is inactive or offscreen, and pause hidden decorative CSS animations. Reuse the same permission-filtered server menu for both homepage atlas sections, and choose the three existing complete-cycle price minima in one pass without a full sort or changing price calculation/offer gates.

Production baseline, same-account HTTPS requests: first recorded FA/EN/TR homepage responses were 259,268 / 244,947 / 246,358 uncompressed bytes and 1,401 / 1,242 / 1,451 ms to headers; three warm Persian requests were 104, 156 and 97 ms. These are origin-near observations, not field CWV. Both search analytics connectors refused real data due their account plans, so no search traffic or AI-reference metrics are fabricated. Actual before/after verification will be recorded separately.

Reference: [Google AI features guidance](https://developers.google.com/search/docs/appearance/ai-features), [OpenAI crawler documentation](https://developers.openai.com/api/docs/bots), [Anthropic crawler documentation](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler). Search inclusion and assistant recommendations are not guaranteed; published, crawlable, useful HTML is primary.

Release is scoped to 25 public presentation/discovery destinations. Live-only gating and sitemap logic are preserved by unique anchors and pre/post hashes; dependency files install first. No purchase, authentication, pricing rule, private crawl policy, migration, global cache flush or config/route-cache rebuild. Both owned FPM pools must validate public localized routes and compile the affected views. Private exact-byte backup and rollback are required.

## Production acceptance — 23 November 2026

Application source PR #412 (`dc09db258b6e727b4a3ae18fab20d386bc107512`, merged at `2f59ddb3c018eb8684fd8b325aa21dd6e38af391`) passed all 19 PR workflows. Initial deployment exposed the Laravel filesystem bootstrap order: checking `routesAreCached()` before the application kernel initialized the filesystem binding failed activation. The 14 pre-existing destinations were restored to their exact old bytes; FA/EN/TR homepages continued to return 200. Recovery retained the 11 new dependencies until the old runtime was verified, then moved them to the private previous-stage backup. The helper-only corrections in PRs #414 and #416 fixed the activation ordering and passed six isolated release/rollback tests. Final candidate `87ed96bbf1c5e3a2a0080c9c45a868393886a6ba` (merged at `2388babafb6eac1ace8816477dce57e2e92c4e3d`) preserved all 25 tested application source paths. Recovery, preparation, installation and verification of all 25 destinations succeeded. Both FPM pools compiled the eight affected views and confirmed the new public Studio route. Neither perform a global cache flush nor rebuild the route cache.

Local verification included 61 PHP tests/3,451 assertions before the final preservation of query-crawl exclusions, 8 focused PHP tests/421 assertions on final homepage/robots code, 18 JavaScript tests (15 DOM and three globe scheduler), six release tests, and PHP/CSS syntax verification. These are overlapping test suites, not additive test counts.

### Live crawl and product acceptance

- FA/EN/TR home and AI Studio landing pages all return HTTP 200, are indexable by their page directives and use the intended localized titles, canonical URLs and reciprocal hreflang. All six homepage cards and the corresponding six structured Service items were verified, including the WebPage main-entity link and all localized product destinations. The same six slides, 6000ms interval, approved globe size and full-height proof rail remain in place.
- `/ai/studio`, `/en/ai/studio`, `/tr/ai/studio` render the product explanation, browser CTA, rates link and FAQs without a login wall. The three existing desktop download guides return 200 and remain noindex and excluded from sitemaps. Each new landing appears in both the live full sitemap and the AI sitemap, and all three are listed in `llms.txt`; the old GPU redirect is absent.
- The live `robots.txt` hash is `2069ec029ac48dca7f66ff0ef0ffeeb5590d6fe2be42088dcec3663d03e603b8`, including explicit search/user rules for OpenAI, Perplexity, Claude, Google and Bing, unchanged training decisions, private exclusions and existing duplicate-query restrictions. These checks establish technical discovery readiness, not search-engine indexing or assistant recommendations.
- Browser acceptance on a 1348×936 CSS viewport preserved the 626.96875px square globe host; scroll was zero and the statistics rail ended at the viewport bottom. Before the release, the hidden globe's frame counter advanced from 28 to 45 while the GPU slide was visible. After release it remained 25 to 25, with auto-rotation false and background animation paused. The controlled mobile test verifies that the 103,948-byte geography data is deferred until interaction. The actual visible service directory and Studio page were captured: [homepage service guide](home-discovery-2026-11-23/service-directory.jpg), [AI Studio landing](home-discovery-2026-11-23/ai-studio.jpg).

### Response timing

Same-account server-side HTTPS observations (origin-near, first application-cache MISS per locale, milliseconds to response headers):

| Locale | Before | After | HTML bytes before | HTML bytes after |
| --- | ---: | ---: | ---: | ---: |
| FA | 1,401 | 1,067 | 259,268 | 271,743 |
| EN | 1,242 | 1,066 | 244,947 | 255,065 |
| TR | 1,451 | 1,162 | 246,358 | 257,172 |

Mean first-response time in these samples went from 1,365ms to 1,098ms (19.5% lower); three subsequent warm Persian requests took 165/110/99ms versus 104/156/97ms before (similar ranges). HTML grew to include useful visible service content rather than depending entirely on rotating visuals. The new Studio pages responded in 256/265/291ms. These observations are not global device measurements, lab Lighthouse scores or field Core Web Vitals. Analytics connectors remained subscription-limited; we have not asserted traffic gains, new Google indexing, AI referrals or a fresh Search Console sitemap submission. The native submission path is deliberately blog-specific. The public sitemap and crawler rules are deployed and verified.

Rollback from the successful release's private exact-byte backup, if needed:

```sh
python3 /home/servernetcloud/deploy-backups/home-discovery-20261123-87ed96bbf1c5/release.py rollback 87ed96bbf1c5e3a2a0080c9c45a868393886a6ba
```
