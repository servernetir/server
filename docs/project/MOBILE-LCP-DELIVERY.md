# Mobile critical rendering and public catalogue work

Source change prepared 2026-11-02 against `develop` commit `93cf0cb6`. Installation and measured live results are separate from the source design below; see the dated mobile LCP release record when available.

## Scope

`PublicPageStyles` covers home, GPU, hourly VPS, Philippines/UAE VPS, Linux hosting and the PHP upgrade guide, in fa/en/tr. Account, checkout, payment, other countries and other content retain their existing asset delivery. The older city landing-page path retains `LandingPageStyles` and its existing font subset.

The service reads the currently served CSS and font files through `public_asset_path`, with a request-local cache only. It does not ship a generated CSS subset or overwrite public assets. A later request sees later asset changes. The selected `site.css`/`site.min.css` still follows the existing timestamp rule. Shared styles keep their original order; VPS table and chat styles stay at their original partial positions.

The regular and bold IRANSans WOFF2 files are embedded byte-for-byte in their original font faces. Family, weight, glyphs and WOFF fallbacks remain intact. `font-display:block` on these two already embedded faces prevents an early fallback-font paint; the other faces and the home hero font retain their existing behavior. No JavaScript hides content or waits for fonts. Successful embedding removes only the redundant regular/bold external preloads.

Missing, empty or unsafe CSS, unknown relative asset URLs, unexpected font faces, or invalid/missing WOFF2 files cause the complete group to use the original linked styles and preloads. New font descriptors need review before this optimization can apply. Embedding increases HTML size and reduces cross-page reuse of these bytes; measure actual repeat and cold visits before expanding the path allowlist.

## Backend work

Country catalogue rows are narrowed to their existing canonical-city set **after** the unchanged global representative-offer selection and before dominance pruning/row construction. Global slug grouping, cross-location aliases, Iran rules and pricing/sale eligibility must remain unchanged. Do not move this filter into the global offer query without proving the same representative choice.

The GPU public catalogue shares two image catalogue reads within one render. Its per-offer SQL slug/provider/snapshot eligibility check and the existing deliverability predicate remain intact. Image reads change from `2N` to two, while the `N` provider queries remain. The original single-offer `imageKeysFor` method used by other paths is untouched. There is no persistent image/pricing cache.

## Validation and operations

Behavioral coverage lives in `PublicPageStylesTest`, `PublicVpsCountryScopeTest` and `GpuImageCatalogueBatchTest`, together with existing catalogue, schema, analytics and navigation tests. Tests use isolated synthetic data, not production purchases. Whole ordered offer rows/cards and app choices should match the unchanged reference behavior in three languages.

Use current live file hashes and scoped hunks for deployment. Live CloudStore/layout overlays can differ from Git and must be preserved. Install the new service before consumers, CloudStore before GPU, and roll back in reverse dependency order. Keep backups and the helper outside the webroot; do not flush shared caches or overwrite the whole application as a routine deployment step.

Verify HTTP, real rendered IRANSans, mobile layout, catalogue controls, the buying path up to login and analytics delivery separately from file installation. Compare repeated Lighthouse runs with the same throttling method and retain outliers. A local static fixture excludes production response time and application scripts; it is not a live performance result. Search Console/CrUX field LCP and real paid purchases require their own evidence.
