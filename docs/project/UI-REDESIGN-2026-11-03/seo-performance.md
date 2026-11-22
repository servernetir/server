# SEO and performance — Phase 0, 3 November 2026

Status: **documented; preview only**. Source baseline: `develop` at `31a311e904741f8b58f462824a83c9745731b23f`, working branch `preview/full-width-ui-20261103`. This audit changes no application code, robots policy, metadata, analytics, content, cache, or production service. Production changes remain blocked until the owner approves the preview and separately authorizes release.

## Findings that shape the redesign

The public site already has a fluid Atlas treatment: `website/public/assets/css/atlas-public.css:4` removes the container maximum for `body.sn-public`. The shared site layout applies that public scope while excluding account, admin, auth and transaction paths (`website/resources/views/layouts/site.blade.php:17`, `:146`, `:235`). The older customer shell still uses the base `.container` with `max-width:1200px` (`website/public/assets/css/site.css:49`; `website/resources/views/panel/layout.blade.php:20`). Do not describe the whole public website as boxed, or remove useful reading-width constraints from paragraphs, legal text and documentation to obtain a fluid shell.

The current public head already contains canonical, multilingual alternates, social metadata, real organization data, guarded font loading and optional critical styles. A shell replacement should preserve those contracts; SEO discovery does not require rewriting the head.

### Anonymous public observations

Normal unauthenticated GETs began at **2026-11-03 05:33:01 UTC**. No cookies or credentials were supplied; no account pages, search submissions, purchases or provider APIs were requested. Redirects were followed normally. Only allowlisted response headers and extracted public metadata were retained. See [seo-public-observations.json](seo-public-observations.json).

| Public URL | Observed result | Head and heading findings |
|---|---|---|
| [Home](https://servernet.cloud/) | 200; `X-Cache: HIT`; 219,907 HTML bytes | `lang=fa`, RTL; one H1; canonical `https://servernet.cloud`; fa/en/tr/x-default; nonempty page title/description; no noindex; parsed WebPage, Organization and WebSite JSON-LD |
| [Domains](https://servernet.cloud/domains) | 200; `X-Cache: MISS`; 193,021 HTML bytes | `lang=fa`, RTL; one H1; self-canonical; fa/en/tr/x-default; nonempty page title/description; no noindex; parsed Organization and WebSite JSON-LD |
| [Hosting entry](https://servernet.cloud/hosting) | Final 200 at `/hosting/linux`; `X-Cache: MISS`; 171,112 HTML bytes | `lang=fa`, RTL; one H1; canonical/OG URL target `/hosting/linux`; corresponding fa/en/tr/x-default; nonempty title/description; no noindex; parsed Product/Offer, FAQPage and BreadcrumbList JSON-LD |
| [Public robots](https://servernet.cloud/robots.txt) | 200, text/plain | Public crawl allowed with private route exclusions; main sitemap advertised; additional query exclusions differ from this source baseline |
| [Main sitemap](https://servernet.cloud/sitemap.xml) | 200, application/xml; `X-Cache: BYPASS` | 5,454 unique URLs; all on `servernet.cloud`; zero exact private route prefixes in the bounded classifier |
| [Console robots](https://console.servernet.cloud/robots.txt) | 200, text/plain | `User-agent: *`, `Disallow: /`; no sitemap declaration |

All three sampled HTML responses carried `Cache-Control: max-age=60, private, stale-while-revalidate=600`, `Vary: Cookie,Accept-Encoding`, `nosniff`, `SAMEORIGIN`, a referrer policy, HSTS and CSP. Server-Timing app durations were 179/212/318 ms respectively. These are single requests from one client, with different cache states; **they are not TTFB benchmarks, LCP, INP, CLS, CrUX, or proof of stable speed**. The `/hosting` intermediate status was not retained, so only its observed final destination and final 200 are asserted. No HTTP failure occurred in these six requests. A second GET of the sitemap confirmed hostname and exact route-prefix classification; substring matches such as `/docs/payment-methods-guide` are public documentation, not leaked payment routes.

English/Turkish live pages, authenticated screens, console HTML headers, full H2–H6 order, downloaded asset responses, rich-result eligibility and behavior under browser execution were not verified in this HTTP sample. Current source supplies the multilingual head contract; the GET sample proves its Persian output only.

### Existing contracts to preserve

| Contract | Source evidence at the baseline | Redesign acceptance |
|---|---|---|
| Page identity and localization | `website/resources/views/layouts/site.blade.php:20–93` | Keep per-route title/description; one canonical and matching OG URL; fa/en/tr plus x-default; controller `$altUrls` retains meaningful pagination/filter query state. Preserve `faOnly` and noindex exceptions; error pages must not gain canonical/alternates. |
| Crawlable content and navigation | `website/resources/views/layouts/site.blade.php:238–250`; `website/resources/views/pages/hosting.blade.php:149` | Keep visible server-rendered page H1, logical H2 sections, real anchor URLs and the skip link/main landmark. A new sidebar or hero must not duplicate H1 or move essential copy behind JS-only rendering. |
| Main/console separation | `website/app/Http/Middleware/ConsoleHost.php:126–199`; `website/public/.htaccess:32–45`; `website/public/robots-console.txt:17` | Preserve public-to-console account redirects, public content consolidation, console `X-Robots-Tag: noindex, nofollow` and console crawl block. The console header is source-verified, not revalidated against authenticated live pages. Robots is crawl control, not authentication or a substitute for indexing/access controls. |
| Session-safe HTML cache | `website/app/Http/Middleware/PageCache.php:99–108`, `:139–154`, `:157–265`, `:289–304`; `website/config/pagecache.php:48–79` | Keep query/session/auth bypass, private/no-store exclusions, CSRF replacement on HIT and stored per-page noindex/CSP. Do not turn token-bearing HTML into a shared public/edge cache as a speed shortcut. Keep account/admin/preview paths excluded. |
| Scoped public/private styles | `website/resources/views/layouts/site.blade.php:17`, `:146–148`; `website/resources/views/panel/layout.blade.php:6–10`; `website/resources/views/admin/layout.blade.php:1–16` | The public shell is already fluid. Extend customer/admin shells deliberately with CSS scoped to their surfaces; marketing styles must not leak into forms, invoices or auth screens. |
| Existing font and critical path | `website/resources/views/layouts/site.blade.php:97–148`; `website/app/Services/PublicPageStyles.php:10–27`, `:45–86`, `:105–146`; `website/app/Services/Cloud/LandingPageStyles.php:10–45` | Preserve IRANSans and glyph metrics. Source/minified freshness selection, exact font signatures, hero-text hashes and cloud dependency hashes can select a fallback after a UI edit. Test both guarded fast path and safe fallback. Regenerate/audit relevant artifacts together later; do not suppress guards. Services were read from the Git object because this checkout is sparse. |
| Fixed header and stable geometry | `website/CLAUDE.md:131–163`; `website/public/assets/css/atlas-public.css:18`, `:37`, `:45–47`; `website/resources/views/pages/home.blade.php:309` | Reserve header space once through `--header-h`; keep matching scroll margins; reserve image/art/widget dimensions; keep reduced-motion behavior. Fluid width must not create horizontal overflow or shift content after fonts/widgets appear. |
| Security and external dependencies | `website/app/Http/Middleware/SecurityHeaders.php:22–114`; `website/bootstrap/app.php:26–43` | Preserve CSP, frame/form restrictions and middleware order. Prefer local existing assets. No external font/CDN or new permissive policy is needed for the preview. |
| Analytics and form contracts | `website/resources/views/partials/gtm-head.blade.php:24–75`, `:77–115`; `website/resources/views/layouts/site.blade.php:230–236` | Preserve event names, currency conversion, one-time session consumption, immediate container start, worker/fallback and private-report exclusions. Preserve form actions, methods, names, CSRF and real order state. Preview fixtures must emit no production events or purchases. |
| Sitemap/content eligibility and schema | `website/app/Http/Controllers/SiteController.php:319–395`, `:461–483`, `:589–619`; `website/app/Http/Controllers/CountryVpsController.php:25–29` | Keep canonical public routes, real lastmod, Persian-only exceptions, active catalog/model eligibility and country Service/Offer semantics. Some public order summaries are intentionally indexable; `/order` is not automatically a private account route. Preserve physical product schema; add no invented image/rating/stock/shipping claims. |

## SEO issues to track without folding them into the visual preview

1. **Source/live robots drift — high release priority.** Live public robots contains `/*?attachment_id=`, `/parts/*?`, `/en/parts/*?`, `/tr/parts/*?`, `/*?tag=`, `/*&tag=`, `/*?q=` and `/*&q=` exclusions. They are absent from checked-in `website/public/robots.txt` and `website/config/seo.php:37–47`. A later release must reconcile the authoritative policy and generated file; copying the older file could reopen low-value query crawling. No policy or generator was changed here.
2. **Hosting structured-data semantics — separate review.** The sampled Linux hosting output still emits Product plus shipping-related Offer types; `website/resources/views/pages/hosting.blade.php:89–135` explains the source. The November 2 country-VPS correction was deliberately narrower. Review whether this service page's current schema accurately represents its offer before modifying it; parsed JSON-LD is not a Google rich-result validation result. Do not alter physical-goods schema as a side effect of visual work.
3. **Historical Google outcomes remain open.** `SEO-SEARCH-GROWTH-2026-11-02.md:28–34` records a 23 October indexing snapshot and a 31 October CWV snapshot: 154 mobile URLs needing LCP improvement, group LCP 3.1 s, 154 desktop good, and no INP issue group. Those are dated report evidence, not newly measured values for this redesign or for every URL. The same report leaves Merchant validation completion, Iran visibility policy and actual GA4 purchase/revenue acceptance open (`:31–34`, `:58`). This audit neither enters Search Console nor starts validations/indexing requests.

Google documents that a crawler must be able to fetch a noindex directive for it to take effect; `Disallow` can prevent that fetch. Keep the current console access boundary and do not claim its robots file proves search removal. Any policy adjustment is a separate SEO decision ([Google noindex documentation](https://developers.google.com/search/docs/crawling-indexing/block-indexing), read 3 November 2026).

## Performance evidence and proposed budgets

No Lighthouse, CrUX, RUM or browser trace was run in this Phase 0 audit. A bounded source search found no dedicated web-vitals collection in the sampled view/first-party JS files; this does not prove GTM or all backend integrations lack it. Do not report a score, speed gain or field pass from the static preview.

The baseline already carries substantial shared CSS. These are **local source sizes**; gzip figures are reproducible estimates from Python gzip level 9, not observed network transfer sizes:

| Baseline asset | Raw bytes | gzip estimate bytes |
|---|---:|---:|
| `site.css` | 291,688 | 63,211 |
| `site.min.css` | 224,755 | 38,994 |
| `atlas-public.min.css` | 27,419 | 5,629 |
| `site.js` | 36,573 | 11,310 |
| `site-select.js` | 16,417 | 4,478 |
| IRANSans regular / bold WOFF2 | 32,272 / 32,092 | No meaningful extra gzip saving |

The source can inline complete public CSS and two font faces rather than only a tiny critical subset (`PublicPageStyles.php:58–79`, `:105–146`). The live home/hosting GETs instead exposed stylesheet links and font preloads. This is an observed difference in output path, **not proof of its cause or a production regression**; inspect the exact deployed files and guard results during an authorized release. New redesign CSS should avoid another growing global override layer.

Proposed engineering budgets below apply to the later implemented UI. They are targets to approve and measure, not current results:

| Budget | Target / gate |
|---|---|
| New shared shell CSS | At most 20 KiB gzip added during transition; aim to replace superseded rules so final route CSS is no larger than its comparable baseline. No framework-wide CSS import solely for the shell. |
| New initial shell JavaScript | At most 15 KiB gzip; deferred/module loading; no new third-party runtime for navigation, layout or icons. Keep essential navigation/content usable before JS. |
| Fonts | Keep current IRANSans identity; at most two necessary initial WOFF2 faces, at most 70 KiB combined unless a measured route-specific subset is approved. Do not preload unused faces or add remote font families. |
| Above-fold visuals | No blocking autoplay video or full-resolution raster hero. Give media dimensions/aspect ratio; defer below-fold images; lazy-load heavy optional globe/chart code without delaying text/CTA. |
| Layout | No viewport-level horizontal scrolling at 360/390/768/1024/1440/1920 px in fa/en/tr; content/table scroll stays local. Header/font/widget insertion must not visibly displace primary content. |
| Lab regression gate | Compare the same route, language, device/throttle, cache state and runtime in at least three runs before/after. Investigate median LCP/TBT regressions above 10% rather than calling a static preview a performance improvement. Target lab CLS at most 0.05 and TBT at most 200 ms; TBT is not INP. |
| Field outcome target | After an authorized real release and a valid observation window, target p75 LCP at most 2.5 s, INP at most 200 ms and CLS at most 0.1, separating mobile/desktop. Require actual field data before claiming success. |

Field targets follow [Google's Core Web Vitals guidance](https://developers.google.com/search/docs/appearance/core-web-vitals) and [web.dev's measurement guidance](https://web.dev/articles/vitals), read 3 November 2026. Passing those thresholds does not guarantee ranking or revenue improvement.

## Later UI phases and acceptance sequence

1. **Preview phase:** deliver the visual shell with synthetic data and inert submissions, local assets, working presentation-only navigation, fa/en/tr direction handling and viewport screenshots. Preserve text/heading identity where pages are represented. The preview is separate from the production crawl surface and carries no live analytics.
2. **Implementation after preview approval:** keep routing/controller/business logic stable; implement shell components with scoped CSS; retain head/schema/analytics partials and transaction forms. Record baseline and changed asset sizes. Inspect all guarded CSS/font paths affected by the shared layout edit before choosing a release bundle.
3. **Isolated acceptance:** render actual Laravel routes with fabricated data; compare title/description/canonical/OG/hreflang/noindex, H1/heading order, JSON-LD, anchor URLs, form methods/input names and cache semantics. Exercise JS navigation/menus/search presentation at mobile and desktop in all three languages. Verify private screens remain private and uncacheable. Relevant existing suites include `SeoMetadataAndEntityTest`, `BlogListingCanonicalTest`, `CanonicalNeverCompetesTest`, `ConsoleHostTest`, `ConsoleRobotsTest`, `RobotsPolicyTest`, `PageCacheTest`, `FixedHeaderOffsetTest`, `LandingPageStylesTest`, `PublicPageStylesTest`, `CountrySitemapEligibilityTest` and `AtlasPublicPagesTest`; none were run for this documentation-only audit.
4. **Separate approved production release:** reconcile live/source drift, create a narrow reviewed manifest and rollback, then perform authorized public HTTP/render/performance acceptance. No release, cache flush, infrastructure operation, analytics configuration, provider call or indexing action is authorized by this audit. Measure field outcomes later without attributing historical gains to the redesign.

Validation performed here: read-only source inspection, six anonymous public GETs plus one sitemap classification GET, JSON/XML parsing and local asset-byte calculation. Documentation and machine observations only; no application test, build, browser, deployment or authenticated inspection was executed.
