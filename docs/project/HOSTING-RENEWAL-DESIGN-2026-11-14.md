# Hosting renewal workspace design — 14 November 2026

Base develop: `37ad17bcea7363e38ac1c501c4def8322b2fdbf2`.

The owner requested the renewal period page to match the existing `/account/ai`
and `/account/api` pages. This change composes the existing `account-api.css`
workspace primitives with a scoped `hosting-renewal.css`; neither reference page
nor its shared styles are changed.

The new layout has a gradient hero/calendar illustration, service identity,
current-period/due-date overview, four responsive period cards, prominent final
amounts with an explicit currency, actual savings relative to the comparable
monthly quote, one consolidated pricing-basis disclosure and a three-step
explanation. Existing POST forms, CSRF tokens, quote hashes, period values,
ownership and blocked/empty states remain intact. The screen does not run
JavaScript or change billing, pricing, service status or invoice creation rules.

All text is translated in `lang/{fa,en,tr}/renewal.php`. Savings badges use actual
quoted savings after catalog rounding/floors, rather than promising an
undelivered configured discount. Contract-vs-catalog basis remains visible.
CSS uses the established panel variables, logical properties, clear focus states,
reduced-motion support and a one-column mobile layout.

## Validation

- Isolated PHP 8.4.24 and locked Composer dependencies; SQLite in memory, fake
  mail and HTTP stray-request protection: 47 tests / 384 assertions passed across
  renewal cycles, billing anchors and hosting upgrades. The later presentation
  pass of the renewal suite passed 12 tests / 145 assertions.
- Actual Laravel fixtures render catalog, contract, blocked and empty states in
  all three languages. No live data, invoices or payments are used.
- Native Chromium headless browser: 72 cases / 2,556 checks passed across all
  four states, fa/en/tr, dark/light and 390/768/1600px widths. Checks include
  horizontal overflow, readable amounts, resolved translations, protected forms,
  original POST/CSRF/signature/locale contracts, keyboard submission and touch
  targets. Local desktop and mobile renders were visually inspected.
- Translation lint, Python release-script compilation and Git whitespace checks
  passed. The hosting CI matrix also runs the new browser suite.

## Deployment state and rollback

**Prepared and tested; production installation is pending browser reconnection.**
The connected browser timed out before source inspection; source templates were
read directly from the requested develop revision. The server session is not
assumed available. No production write is claimed for this design revision.

`scripts/renewal-workspace-release-20261114.py` prepares a bounded release from an
immutable Git SHA, preserves production overlays with a three-way merge, lints
PHP and compiled Blade, and installs under the existing three release locks with
private backups and expected-before/after hashes. Its five sources map to six
destinations because the CSS must reach both app/public and public_html.

Sources: the renewal Blade, three new language files and the scoped CSS. There
are no routes, controllers, migrations, cron entries, dependencies or financial
data changes. Only the affected view is compiled. Roll back only matching
installed hashes using the private before copies, retaining newer edits and all
financial records. Do not restore the whole app or database.
