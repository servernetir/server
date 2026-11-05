# One-time crypto resilience release

Audit sources for the owner-approved 4 November 2026 installation of PR #261.
These scripts are not a general deploy system and are not invoked by CI or cron.
The private production receipt and original backups remain outside webroot under
`crypto-resilience-0c5e7f1443af` in the hosting account home.

The installer consumed a private bundle containing the three source files from
merge `0c5e7f1443afb5d5d3ab26e182db70bad5277445`, `manifest.json` (identical to
`manifest.reviewed.json` here), and `install.php`. It acquired the two existing
release locks and the scheduled crypto event mutex, backed up the exact runtime
files and payment schema, ran only the named additive migration, then replaced
the two runtime classes. All four staged PHP files passed production PHP 8.4 lint.

The activation template was separately linted and installed temporarily in the
existing webroot. Its form used the normal admin session and CSRF protection;
it checked and invalidated only the two approved class files. It was hash-checked
and removed after its private activation receipt was verified. It contains no
deployment token and never clears shared caches.

Do not rerun against the installed release: its started-file, source and schema
guards intentionally reject reapplication. No general rollback command is
provided. A rollback must reconcile later edits, restore only matching runtime
files from this release's private backup and activate their bytecode. Keep the
expanded column and migration history; do not replay financial records.

See [the production record](../../docs/project/CRYPTO-SETTLEMENT-RESILIENCE-2026-11-04.md)
for test results, actual acceptance and the untested new-payment boundary.
