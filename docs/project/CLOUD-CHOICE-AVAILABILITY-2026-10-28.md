# Visible unavailable cloud choices — 28 October 2026

Base develop: `a5fc69e3394efb1676e57f4b3d6d12b1931699ee`. Owner requests that unsupported hourly locations, OS versions and resource-incompatible choices remain gray and explain why.

## Behavior

- Known OS catalog options remain visible in the store. Existing image eligibility maps and server-side order guards remain authoritative. Disabled source radios, enhanced family cards and version options cannot select or submit an unavailable image. Switching plans updates both disabled state and explanation and clears an unavailable checked image.
- Explanations are localized in fa/en/tr: actual RAM minimum, boot disk minimum (a separate data disk does not satisfy it), CPU minimum, architecture mismatch or unavailable image for the provider/region. Resource explanations only use matching provider/regional candidates; no supplier identity or raw API error is shown. An unsupported image from another region is not mislabeled as insufficient RAM.
- Unsupported hourly and billing-period choices stay visible and disabled. In hourly mode, countries lacking a currently sellable hourly offer are gray with a reason. Monthly options stay independently available.
- The public hourly country table retains monthly-only countries as disabled rows with an explicit reason and no false hourly quote or purchase link. The same reason appears next to a disabled hourly action on monthly-only public city plan cards. Active country totals/calculators remain hourly-only. Known plans still follow existing administrative visibility and sales gates; no intentionally hidden infrastructure is reopened.
- The hourly landing page now selects eligible hourly rows before choosing the retail representative. A monthly fallback division is never treated as proof of hourly sale capability; bare-metal plans are excluded from hourly offers.

## Validation

Isolated SQLite/fake HTTP: **130 PHP tests / 670 assertions passed**, covering the new availability cases, actual invalid-image/hourly POST rejection with zero services/invoices/HTTP requests, hourly pages, catalog and offer selection/dominance, VPS.tc sales and renewal. **11 JavaScript tests passed** for grouped cards, disabled versions/reasons, switching and stale popup guards, keyboard behavior and original form payload. PHP lint and whitespace validation passed.

The older `CloudStoreTest` baseline has **22 failures / 4 errors** reproduced unchanged at the base SHA (outdated fixture/gate assumptions). The old hide-unavailable-image expectation is intentionally updated to the requested visible-disabled contract; this is not a claim that the full historical suite passes.

## Production and rollback

**Installed and verified in production at 08:54 UTC (12:24 Tehran).** Feature source was published to develop as `23d355a06d09dc9b32416ffa538a29ac1eb998ba`, preserving intervening develop changes; the follow-up also records the regional explanation guard. Fresh production reads have unrelated overlays (including a split location partial and additional translations/styles). Deploy only this change using exact before hashes, private backups, both shared release locks and merged overlays; do not replace those files wholesale from Git. The live-only split location partial receives only the new hourly availability attributes and explanation. It is not copied into Git as unrelated source.

No schema, migration, supplier sale flag, tariff, paid operation, cron schedule for normal business work or customer record changes are required. Rollback restores this release's exact file backups and invalidates the public page-cache generation; financial and service records stay untouched.

## Production receipt

Twelve runtime files changed: the new `CloudChoiceAvailability` display-only service; `CloudStoreController` and `HourlyVpsController`; fa/en/tr `ui.php`; `panel.css` and `cloud-image-cards.js`; the account store, public city and hourly templates; and the existing live-only split location partial. Both shared release locks, staged PHP lint, native before/after SHA-256 checks, private backups and atomic replacement were used. Public page cache generation was invalidated.

Earlier installation attempts stopped during validation before any runtime write. Native reads established that cPanel `Fileman/get_file_content` injects `<meta charset="utf-8">` after a `<header>` in the two public templates: this response is not an exact-byte source snapshot. Private CLI base64 reads confirmed the actual files; the injected tags were never deployed. A subsequent language-file update from concurrent work was preserved. Final installation applies uniquely matched, tested change contexts to the current files while holding both release locks; it captures fresh native hashes under the lock, refuses ambiguous contexts or changed translation values, preserves unrelated content, and rechecks every file before replacement. Six isolated strict-patch checks passed, including unrelated content preservation, idempotence and ambiguity/value-change rejection.

A final explanation refinement restricts image/resource candidates to the selected canonical city. Its four availability tests / 35 assertions passed after the main 130-test run. No API quote or purchasing eligibility rule is relaxed.

Private production acceptance used an unsaved synthetic customer with ID 0 and an array session. The two store views (Istanbul and Manila) rendered in fa/en/tr: all six renders retained disabled radios, Windows choices and localized reasons without raw translation keys. The three hourly page renders had 35 active country rows and disabled Turkey. No customer was created or signed into, and no order, invoice, payment or supplier mutation was submitted.

Cloud-browser acceptance independently verified the Istanbul monthly purchase link alongside a native disabled hourly button and reason; the hourly table retained Turkey with no hourly price/buy link. Searching for Turkey left exactly one visible row, with the unavailable explanation. Screenshots captured the actual public results. Authenticated customer mouse/keyboard interaction was not performed; grouped-card/disabled-version interaction is covered by the eleven executed JavaScript tests.

All temporary, once-guarded installer/native-read/acceptance cron entries were removed with fresh-inventory checks; unrelated cron entries were preserved. Backups and receipts are private outside the web root. Rollback must restore only this release's files after checking for newer changes; do not reset settings or customer/service data.
