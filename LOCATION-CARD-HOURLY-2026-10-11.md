# Location plan-card hourly prices — 2026-10-11, published

The owner requested the real hourly price beside monthly prices on hourly-capable plan cards, a clear hourly purchase choice, mobile/FA/EN/TR tests, and publication to the website and GitHub develop.

## Behavior and scope

Public location cards at `/cloud/{location}` resolve monthly and hourly offers separately. `CloudPlan::offers(location, hourly:true)` performs one additional sellable collection query, with no query per card. A cheaper monthly-only alias does not supply an invented hourly price. The displayed hourly amount uses the same `hourlyIrt()` / `hourlyEurCents()` model as billing and the hourly landing page, including LightNode's real hourly floor rather than dividing its 31-day monthly price. Hourly controls require positive quotes and exclude bare metal. Inactive, out-of-stock, administrator-disabled and provider-disabled rows remain excluded by the existing sellable scope.

Each supported card has two adjacent price blocks, localised month/hour units, and labelled monthly/hourly purchase buttons. The hourly link retains the exact public slug and location and includes `billing_mode=hourly`; the monthly link retains its existing parameters. Unsupported cards retain monthly purchase only. Supplier identity/cost is not exposed. Monthly JSON-LD, monthly prices, the main catalogue table and other page surfaces are unchanged. Three language keys have no missing translation. CSS keeps natural card heights, resets the nested footer margin, uses two flexible columns and retains minimum44px controls without horizontal overflow.

## Validation

The related public/country/SEO/table/city/link/hourly/CSS/header suite passed93tests/1371assertions. The hourly storefront/term support/store/location/city suite passed86tests/935assertions:179distinct tests/2306assertions in total. Four added regressions cover a cheaper monthly-only alias with a different valid hourly alias in all languages, unavailable/disabled hourly aliases, bare metal, and LightNode's independent hourly rate. An initial bare-metal fixture incorrectly used CPU kind instead of the model's `cbm-` slug classification; it was corrected before the successful final run.

A private preflight on production built candidates from the actual live files using guarded contextual replacements. It preserved concurrent translations, validated PHP syntax and compiled Blade, and rendered public data only. The first preflight stopped before installation because Laravel does not render a view named `.next`; the fresh02 preflight used `.blade.php`. The private renderer was then corrected to load the complete translation group rather than only the two new keys. Final preview18cases passed. These helpers were not blindly rerun.

The installed full-site18case matrix FA/EN/TR×320/360/390/640/768/1366 passed for all11Manila cards: both prices/labels, actual viewport widths, exact hourly-mode links, zero document/card/control horizontal overflow, zero inherited footer margin, and minimum44px controls. The screenshot `output/location-card-hourly-20261011/live-mobile-390.png` shows the actual production result with its font. Preview font/height measurements are not asserted as production measurements. Temporary viewport overrides are cleared after validation and the private loopback preview is stopped.

A separate production CLI probe used an empty in-memory session and no customer authentication. It checked all11Manila hourly labels in each language against the billing model and rendered the real purchase form for FA/EN/TR×hourly/monthly. All six retained the intended location and slug; hourly selected the checked hourly control and monthly left it unchecked. The selected plan's5100IRT/hour exactly matched the card and checkout. No order, payment or paid infrastructure machine was created. This is card/link/form acceptance, not a new paid end-to-end provisioning test.

Evidence: `output/location-card-hourly-20261011/related-tests.xml`, `checkout-tests.xml`, `preview-results.json`, `live-results.json`, `live-viewport-confirmed.json`, `install.json`, `verify.json` and the live screenshot.

## Installation, persistence and rollback

Affected host: productioncoreLaravel app at `/home/servernetcloud/servernet_app`. Five files persist: the catalogue controller, location Blade and FA/EN/TR ui translations. Installed at2026-10-11T10:53:52+00:00. The controller and Blade match the Git candidate after newline normalization. Live ui files contain independent concurrent AI additions, which were preserved; only the two reviewed new translation keys belong to this change. Actual raw hashes:

- `app/Http/Controllers/CloudCatalogController.php`: `b52968b8b7e175e61f2caf652e1ee8e9627df0a15db466d210f7274d8f365762`
- `resources/views/pages/cloud-location.blade.php`: `7edc5a1633fd915e00cc05a1efc7bdb4da13ddb7738dd548f95a83903393370a`
- `lang/fa/ui.php`: `e5b74786a4756e6d5c219ea1d82d9ab2e3e09545a27271d58cc97c0e596d2d10`
- `lang/en/ui.php`: `eb62883b345df2268bec4b42229a7986cf9d41c45123ea0b3f93d0a52e3024ac`
- `lang/tr/ui.php`: `c78a900eeb515117f337c82cbe8b4fd0c35fd1649960765ba75fe88a3d844995`

Private backup/candidates/receipts: `/home/servernetcloud/location-card-hourly-20261011-02` (0700, outsidewebroot). Installation rechecked source hashes, installed atomically and cleared only the location template's compiled Blade cache. All3703financial/catalog rows were unchanged during installation; selected-field digest`968a342fba144081f6c99a029958be62e63b0e644bbe99e9f1402e80f359e005`. No pricing settings, provider cost, margin, exchange-rate rule, invoice/customer data, guest, route, NAT/firewall, DNS, VPN, cron or periodic routing/forward state was modified.

For rollback, first verify each current raw hash against the installed receipt; then restore only its matching `.before` snapshot atomically, preserve file mode, and clear only the location compiled view. If another edit has intervened, reverse just this reviewed controller/view/key change on a fresh live snapshot. Never restore the whole site/DB or discard independent translation changes. Completed helpers must not be repeated. GitHub publication is recorded in the final completion artifact.
