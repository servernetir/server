# Visible unavailable cloud choices — 28 October 2026

Base develop: `a5fc69e3394efb1676e57f4b3d6d12b1931699ee`. Owner requests that unsupported hourly locations, OS versions and resource-incompatible choices remain gray and explain why.

## Behavior

- Known OS catalog options remain visible in the store. Existing image eligibility maps and server-side order guards remain authoritative. Disabled source radios, enhanced family cards and version options cannot select or submit an unavailable image. Switching plans updates both disabled state and explanation and clears an unavailable checked image.
- Explanations are localized in fa/en/tr: actual RAM minimum, boot disk minimum (a separate data disk does not satisfy it), CPU minimum, architecture mismatch or unavailable image for the provider/region. Resource explanations only use matching provider/regional candidates; no supplier identity or raw API error is shown. An unsupported image from another region is not mislabeled as insufficient RAM.
- Unsupported hourly and billing-period choices stay visible and disabled. In hourly mode, countries lacking a currently sellable hourly offer are gray with a reason. Monthly options stay independently available.
- The public hourly country table retains monthly-only countries as disabled rows with an explicit reason and no false hourly quote or purchase link. The same reason appears next to a disabled hourly action on monthly-only public city plan cards. Active country totals/calculators remain hourly-only. Known plans still follow existing administrative visibility and sales gates; no intentionally hidden infrastructure is reopened.
- The hourly landing page now selects eligible hourly rows before choosing the retail representative. A monthly fallback division is never treated as proof of hourly sale capability; bare-metal plans are excluded from hourly offers.

## Validation

Isolated SQLite/fake HTTP: **129 PHP tests / 668 assertions passed**, covering the new availability cases, actual invalid-image/hourly POST rejection with zero services/invoices/HTTP requests, hourly pages, catalog and offer selection/dominance, VPS.tc sales and renewal. **11 JavaScript tests passed** for grouped cards, disabled versions/reasons, switching and stale popup guards, keyboard behavior and original form payload. PHP lint and whitespace validation passed.

The older `CloudStoreTest` baseline has **22 failures / 4 errors** reproduced unchanged at the base SHA (outdated fixture/gate assumptions). The old hide-unavailable-image expectation is intentionally updated to the requested visible-disabled contract; this is not a claim that the full historical suite passes.

## Production and rollback

Prepared and tested; installation receipt follows after runtime verification. Fresh production reads have unrelated overlays (including a split location partial and additional translations/styles). Deploy only this change using exact before hashes, private backups, both shared release locks and merged overlays; do not replace those files wholesale from Git. The live-only split location partial receives only the new hourly availability attributes and explanation. It is not copied into Git as unrelated source.

No schema, migration, supplier sale flag, tariff, paid operation, cron schedule for normal business work or customer record changes are required. Rollback restores this release's exact file backups and invalidates the public page-cache generation; financial and service records stay untouched.
