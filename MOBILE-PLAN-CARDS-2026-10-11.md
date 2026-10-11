# Mobile plan-card spacing — 2026-10-11, published

The owner requested compact mobile plan cards, with the price and purchase button close to the resources, testing, production publication and registration on GitHub develop.

The semantic footer inside each `.cvl-plan` inherited the site's global `footer` margin-top110px and bottom padding30px. At390px the Manila cards were413.15625px tall and the measured gap from the `dl` box to the footer box was124px. The global footer itself was not changed.

Only `website/resources/views/pages/cloud-location.blade.php` CSS was edited. The card footer explicitly resets its margin and padding, wraps safely and removes the inherited surface. The purchase button has a44px minimum touch height. Below640px the cards use one natural-height column,16px padding,12px inter-card gaps, a non-growing specification list and wrapping for long disk/network values. The desktop grid remains automatic; its price footer can wrap rather than overflow. No fixed card height or text truncation was introduced.

## Validation and publication

The18-case local preview (FA/EN/TR×320/360/390/640/768/1366) passed bounds and control checks. Its fallback fonts produced244px sample cards; this is **not the final production measurement**. The initial full-document preview capture was incomplete and replaced with a focused, private snapshot of the real plan section. Final acceptance uses the full live site and its actual fonts.

The related Laravel batch passed89tests/1325assertions: public cloud/country pages, country cards/SEO, plan tables/city selection, country-link sweep, hourly page, CSS variables and fixed-header offset. No new test that merely mirrors the CSS was added; browser checks validate the actual geometry and wrapping.

A source guard initially stopped before any write because the production Blade usesCRLF. The live template was inspected and matched the Git base after newline normalization. The reviewed v2 installer preserved those endings and all non-CSS template content. It compiled Blade, checked syntax with production short_open_tag enabled, backed up the actual file, checked its hash again, installed atomically, and invalidated only that template's compiled cache. Installation completed at10:25:21UTC. The normalized runtime source matches the Git candidate exactly.

All3703financial/catalog records (provider, slug, cost, sale prices, active/stock and network terms) were unchanged during deployment; digest`6d0d0b0525441be3aa5fa003ca00aeb67dc6640de6288044eca914b3048710de`. There was no catalog/settings write, guest lifecycle action, purchase, invoice or customer-service change.

The full live-site18-case matrix passed for all11Manila cards in each case: four resource rows retained, correct country/plan purchase links, minimum44px button height, no child/document horizontal overflow, zero card-footer margin, and12px measured specification-list-to-footer gap on mobile (14px above640). At360/390/640, live cards were263.15625px tall—150px shorter than the390px baseline, about36% reduction. At320px they naturally expand to315.15625–336.59375px for wrapped content and controls; no fixed height hides it. At768 they were273.15625px; desktop1366 maximum348.59375px inFA and327.15625px inEN/TR. The separate site footer still had110px margin throughout. Light mode also had zero card-footer margin and no document overflow; dark appearance was restored. Temporary viewport overrides were cleared and the private localhost preview process stopped.

Safe evidence: `output/mobile-plan-cards-20261011/related-tests.xml`, `before.json`, `preview-results.json`, `live-results.json`, `completed.json` and `live-mobile-390.png`. The proof shows resources followed directly by price and purchase controls, with several cards visible on the phone.

## Persistence, limits and rollback

Affected host: productioncoreLaravel frontend. One view file persists on disk; its own compiled Blade is rebuilt normally. Routes, NAT/firewall, DNS, VPN, cron, periodic routing/port-forward state agents, infrastructure guests, customer services, prices and available OS models were untouched. Concurrent FX/SEO and other source changes were preserved. This is presentation acceptance; no new paid order or lifecycle test was needed or run.

Private0700backup/receipt folder: `/home/servernetcloud/mobile-plan-cards-20261011-01`, outsidewebroot. Installed raw SHA256`80cee047d95eb45ee9f6b5823d0e2a1952bbc70c50cfcf7c1754525bb4932deb`; normalized SHA256`c129eb103487d4b641d26308e2b5551e35721cac9e4fb3622d6348d1f89c7e9a`. Rollback must first match that installed hash, then restore only `cloud-location.before.blade.php` atomically and clear only the corresponding compiled Blade. If another edit has intervened, reverse just the reviewed CSS changes on a fresh snapshot. Do not restore the entire site, DB or shared global stylesheet. Completed deployment helpers must not be rerun.
