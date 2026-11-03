# SnappPay branding correction — 2026-11-03

Base: `3aeac46c146becbb069e9a0b07c4cc1b1ca162ea` (`develop`).

The invoice payment card still displayed the old Pay! icon. Replace both historical SVG asset names with the unmodified SVG currently served by the official SnappPay homepage:

- Homepage: https://snapppay.ir/
- Asset: https://snapppay.ir/wp-content/uploads/2025/10/snapppay-logo.svg
- SHA-256: `1cff8dbe9b689243b23c66e7609b79e8b948d6c733a17bbe7f5b23f39f100e1d`

The invoice uses a 40px square image, preserves its aspect ratio and official color, and adds a content revision to bypass cached copies of the old asset. The surrounding white badge is 48px. SnappPay eligibility and dynamic title/description remain unchanged.

Scope: logo assets and their invoice display. Financial payload changes, approval of the logo by SnappPay, and the requested two-update/cancel demo are separate acceptance items. No payment, refund, customer invoice, or gateway configuration is modified by this release.

Production installation must preserve the current invoice template by applying only the four SnappPay display replacements, with before/after hashes and private backups. Do not overwrite the complete production template with the repository snapshot.

## Validation and production result

- Both SVG aliases match the official file byte for byte; XML inspection found no scripts or linked resources.
- Python compilation and `git diff --check` passed.
- Production preview compiled the current invoice template successfully; the resulting PHP passed lint.
- Installed and verified four SVG destinations, the preserved invoice source, and its one matching compiled view. Other template differences remain untouched.
- Both `servernet.cloud` and `console.servernet.cloud` returned HTTP 200, `image/svg+xml`, 6,622 bytes, and the exact official SHA-256 for the versioned asset. The live asset was also inspected in Chrome.
- Customer invoice browser acceptance was not performed because the customer session is signed out. No login, payment, order update/cancel, refund, eligibility flag, tax/category payload, or configuration change was performed.
- Private backups and the guarded `rollback` command remain available to revert only this release.
- Local source checkpoint installed: `3cad7f5d5564b031ab6213a4fbf6191394095f4d`. The owner explicitly authorized public GitHub publication on 2026-11-03; canonical publication is recorded in repository history.

Machine-readable file hashes: [release receipt](snapppay-branding-release-20261103.json).
