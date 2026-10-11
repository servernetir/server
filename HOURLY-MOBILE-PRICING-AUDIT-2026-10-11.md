# Hourly VPS mobile and LightNode pricing audit — 2026-10-11

## Scope and observed defects

The owner authorized mobile hourly landing/store improvements, publication and a LightNode price/currency/tax audit. No customer login was impersonated and no order, payment or provider instance was created. The public store requires login; its real controller was rendered privately with an empty array session and no authenticated customer, using the live catalogue. CSRF values were removed from the private HTML exports, form submission was disabled in the local browser fixture, and the fixture was not published as an authentication bypass.

The hourly landing page forced a 560px table inside a 325px mobile container. Store country prices could exceed the country row itself (294px price inside a 289px row), clipping the name and amount. This also affected narrow desktop grid cells. The chat launcher overlapped the fixed purchase dock. OS/sort controls were smaller than a comfortable touch target. These defects were measured in the browser, including nested clipped contents rather than only page scroll width.

## Changes

- All hourly country rows remain in the same page, with local SVG flags and real purchase links. At narrow widths rows become labeled cards; comparison/estimate tables wrap without horizontal scrolling. The 720-hour estimate has no misleading monthly unit suffix.
- Store countries use a separate price row on mobile and desktop; no amount is truncated. All 40 current countries remain present without pagination.
- Mobile OS/sort controls and hourly purchase links have a 44px minimum target, inputs use 16px text, and the support launcher clears the purchase dock.
- A provider fee explicitly set to zero now overrides the shared fee. Previously zero incorrectly fell back to the global setting. Null/blank still inherits the global setting; a positive provider value stays specific to that provider. The admin help now states LightNode's published USD/no-VAT policy.
- An existing SEO test expected an older Iran description prefix after upstream PR43 changed the editorial description. Its assertion now checks the current country SEO description; product copy was not reverted.

## LightNode price findings

The [official payment guide](https://doc.lightnode.com/Financial/Payment.html) says settlement is USD. The [official FAQ](https://doc.lightnode.com/FAQ/FAQ.html) currently states LightNode does not charge VAT/sales tax. The owner independently confirmed no VAT. The supplier's VAT must not be confused with ServerNet's separate customer tax configuration.

Read-only production snapshot at 08:27:52 UTC: USD=266,500 IRT, EUR=298,500 IRT, cloud profit=5%, global funding overhead=21%, LightNode-specific overhead=null. These are a dated observation, not frozen rates. The 21% comes from the pre-existing site setting `pricing_fx_fee_pct`; the integration did not invent it and LightNode does not publish it as tax. No fee, tax, exchange-rate, profit or customer billing setting was changed during this audit. The owner's reply confirmed VAT only; the actual payment/funding fee has not been established, so neither a guessed replacement nor a global discount was applied.

Two fresh anonymous official calculator reads matched the live Manila configurations:

| Configuration | Official USD/hour | Calculator 672-hour estimate | Existing 744-hour period reserve | Live IRT/hour |
| --- | ---: | ---: | ---: | ---: |
| 1 vCPU, 2GB RAM, 50GB boot, no data disk, 2Mbps | 0.017418 | 11.704896 | 12.958992 | 5,900 |
| 1 vCPU, 2GB RAM, 50GB boot +50GB data, 2Mbps | 0.027818 | 18.693696 | 20.696592 | 9,500 |

The first exact USD quote costs approximately 4,641.90 IRT/hour before fees/profit. Existing 21% funding overhead and 5% profit yield approximately 5,897.53 IRT/hour, rounded upward to 5,900. With a **hypothetical explicitly approved zero funding overhead**, the same quote/profit would yield approximately 4,874 IRT/hour, rounded to 4,900. This comparison is not a production setting change. Conversion uses USD/EUR to store EUR cost, then EUR/IRT for sale, rather than treating USD as EUR. Small saved-cost/rounding differences did not change the displayed hourly result.

Only previously authorized fixed-bandwidth offers are sold. Their quote includes network/IP, compute and disk; they are not the advertised lower-cost traffic-quota configuration. The [official calendar billing guide](https://doc.lightnode.com/Financial/resourcebilling.html) caps unchanged resources at 672 hours **per calendar month**. The existing application reserves 744 hours for rolling customer periods that can span two calendar months. This reserve affects period pricing, not the direct hourly quote. The lowest recorded period price was 4,420,000 IRT before the site's separate period customer-tax calculation; that configured tax remained 10%. Hourly presentation does not add that period tax line. No traffic-overage plan was enabled.

## Verification and deployment

Final related application batch: **182 tests /1,580 assertions passed**, covering store selection/compatibility, pricing/fees, LightNode driver/lifecycle safeguards and CSS variables. This is focused coverage, not a new claim that the full 4,470-case suite ran in this audit. The previous full regression is recorded separately in SERVER-MENU-REGRESSION-2026-10-11.md.

Private browser checks: FA/EN/TR ×320/360/390/768 widths verified 40 countries/40 flags, no country or body overflow, no chat/dock overlap, 2CPU/4GB Windows selection and matching summary. Ubuntu 22.04 selection/summary passed in all three languages at320px; Windows was excluded on the smaller incompatible plan. OS target height was44px. Desktop1366 country overflow was measured, fixed and rechecked at zero. No actual purchase was submitted; prior paid Linux/Windows lifecycle acceptance remains historical evidence, not a new paid test here.

The first production packet installed four runtime files under `/home/servernetcloud/hourly-mobile-audit-20261011/release`, with SHA256 verification, live three-way merge, PHP lint, guarded replacement and a0600 private backup. Existing live credit-lifecycle FAQ/notice additions, hourly country-price copy and unrelated source changes were retained. Cache refresh succeeded. Packet SHA256: `61e01cd5c4c618404bef92da71038a273bfc55834a3ae1cabb8962e06255fded`.

Public post-install FA/EN/TR ×320/360/390/768 checks showed all40 countries/flags and zero body/table/rate-text overflow. Small-phone purchase targets were44px; a subsequent separate tablet target adjustment is recorded at completion below.

The tablet layer passed **13 tests /45 assertions** (overlapping the182), then installed one view under `release-touch`, with a separate guarded backup/cache refresh. Packet SHA256: `7ea4aca50eb54f27e8cfb9f93502c41a08066853cee95c686c0b1d82d7db996a`. The final full12 public browser cases all showed40 countries/flags, zero horizontal overflow and44px purchase targets including768px. Browser records are in `output/hourly-mobile-pricing-20261011/browser-checks.json` outside Git. No actual purchase/payment was used for layout validation.

## Rollback and invariants

Each stage retains `before-install.zip`, `before-hashes.json`, `installed-hashes.json` and a private cache log. Reverse the later tablet layer before the four-file layer. Before restoring any file require that it still matches that stage's installed hash, otherwise reconcile newer edits. Refresh compiled views/config and the public page/menu cache. Do not blindly rerun an installer with an existing backup/receipt.

Only the cPanel application/public frontend and the provider-fee interpretation changed. No database migration, live fee setting, credential, customer service, guest, route, NAT/firewall, DNS, system proxy, recurring job or country-routing/port-forward state changed. Existing provider guests and original LightNode acceptance evidence were preserved. The local HTTP preview is temporary and must be stopped after validation; private HTML remains outside Git/public hosting.
