# Hermes chat and owner digest repair — 2026-10-31

`POST console.servernet.cloud/v1/chat/completions` was redirected by ConsoleHost
with 301 to the main hostname. A redirect-following client changed POST to GET
and reproduced the owner's exact 405. Add `v1/*` to the existing machine API
exceptions; keep authentication, admission and marketing redirects unchanged.

The native operations digest showed the first eight alert labels without their
provider or resource identity. `HermesAlertSummary` now groups by code/provider,
shows counts and up to three exact identifiers, bounds output, names omitted
groups, and explains that unmatched inventory is not proof of waste or authority
to delete. It neither acknowledges alerts nor changes classification or billing.
The Operations overlay changes only the formatter call from the sealed Oct 30
runtime; do not reinstall older historical overlays over it.

Runtime targets and before/after hashes are in `release-manifest.json`. The source
tree is staged privately outside webroot. `native-release.php deploy` takes the
native review/operations cache locks, then `deploy.py` takes shared release locks,
checks hashes, backs up, lints, installs the formatter before its consumer and
verifies all three targets. It preserves the existing crontab and file modes and
stops on drift. It is not a web endpoint or scheduled command.

Rollback: run the same private caller with `rollback`; validate the entire set
before writing, restore only this release's existing files and remove only its
exact new formatter. Later edits stop rollback. No memory, task, financial or
provider state is rolled back. No credentials or live inventory belong in Git.

Tests: `python3 infra/hermes/chat-reliability-20261031/test_deploy.py` and
`php website/tests/standalone/hermes-chat-reliability.php VENDOR_AUTOLOAD SOURCE_ROOT`.
The latter loads Composer only and uses synthetic owner/store/sender boundaries.
Run `ConsoleHostTest.php` by explicit filename in an isolated application checkout.
See the dated project report for actual execution and production acceptance.
