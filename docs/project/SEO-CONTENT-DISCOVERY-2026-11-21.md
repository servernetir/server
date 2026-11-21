# Localized content discovery and relevant product links — 21 November 2026

Base: `develop@2841351a3fe9e10b8bee998bbde5bca15ebadaf7`. Active application: `website/`.

## Scope

Published knowledge-base articles had the same sitemap entry in every locale, even when their requested translation did not exist. The visitor-facing fallback in `Post::tr()` remains available, but it should not be advertised as a translated search landing page.

- Build both full and sectional KB sitemap entries from the actual published translations in configured documentation sections. Preserve the locale-specific publication/edit date.
- Exclude missing translations from the locale's documentation navigation. Direct fallback URLs remain readable, with `noindex,follow`.
- Use only existing translations for reciprocal hreflang; preserve the requested page's self-canonical when it has a translation, and use the first existing locale as x-default only when Persian is absent.
- Map the GPU-selection guides to the localized GPU product rather than the generic category product. No product price, capacity, checkout, eligibility or provider changes.

Actual publication, robots restrictions for non-public pages, and the previous sitemap and site-language route contracts remain unchanged. No data migration or external API call is required.

## Tests and rollout

Regression fixtures cover partially translated and English-only KB articles, drafts, full/sectioned maps, reciprocal hreflang, noindex fallback, localized documentation navigation, restoring discovery after a new translation, and the localized GPU article CTA with generic cloud fallback preserved.

PHP and Composer are not available in this task's isolated runtime; these tests have not been executed locally. The existing Commercial SEO growth workflow is expected to exercise both feature test files on the pull request. Verify all reported CI results, review the latest develop diff, and only then assess a bounded production deployment. A Git commit or sitemap submission is not evidence of indexing.

Roll back at the reviewed file/hunk level with the usual preimage/hash guards; preserve independent live changes. Verify the full sitemap and section union, representative translated and fallback articles in every language, and localized GPU CTA. Do not trigger Google issue validation until the corresponding public pages have been verified.

No private Search Console exports, customer data or credentials are included.
