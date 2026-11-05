# Arvan traffic minimum and monitoring alarms — 5 November 2026

## Behavior

Traffic settlement batches the tax-inclusive unpaid total per customer across the validated supplier cycles. Below **5,000 IRT (toman)**, there is no invoice, payment or wallet debit and paid checkpoints do not advance. At or above the minimum, the whole eligible amount settles once in one invoice, with separate service/period items and the existing locks, exclusions, tax accounting and credit-stop protection preserved. Existing financial history is not rewritten.

A deferred cycle remains eligible for supplier fetching beyond the normal seven-day tail. Its pin clears when the complete report has no remaining deferred or previously pending unresolved held amount. A previously pinned cycle with unrelated unresolved held rows is retained conservatively until reconciliation.

The independent `cloud:watch-arvan-monitoring` command runs every minute. It reports missing enrollment, stale/missing observations, counter reset/unknown evidence and changed instance ownership/identity for live Arvan services through the existing private owner Bale channel. Stable incidents are coalesced for 30 minutes; changed issues alert immediately. Recovery requires fresh verified observations and at least one checked target. Powered-off targets are excluded, and unknown/transition states do not count as recovery. The saved state records notification **attempts**, not confirmed user receipt.

## Financial and coverage limits

Node Exporter observations remain separate from supplier financial settlement. This release does not convert guest counters into a second debit source: billing uses the existing verified supplier-finance deltas. Tariff/direction/allowance validation and reconciliation are required before any guest-meter-based charging is introduced, to prevent charging the same traffic twice.

Live preflight found four running instance rows without approved enrollment in the current observation registry. This release exposes that gap through alarms; it does not claim full fleet enrollment or install agents inside customer guests. Existing exclusions and held/unallocated supplier costs remain subject to reconciliation.

## Validation and release

- Base develop: `e133689778abfb3abbd8579965ecd938216a181c`.
- Tested runtime candidate: `a490ea9af3cf39b09d91a00d579df8203e293d6c`.
- Isolated PHP 8.4.27 / SQLite: **101 tests, 778 assertions** passed, including small-amount carry, exact 5,000, replay, cross-cycle single-invoice settlement, unchanged paid checkpoints, manual-charge races, credit holds, stale observations and notification coalescing/recovery.
- GitHub Actions run `42017267635`: finance regressions passed on **SQLite and MariaDB**, including the existing negative controls for locking/current-read revalidation. All nine candidate workflows passed.
- Independent review findings on sticky cycle pins and false recovery for unknown instances were fixed and re-reviewed.
- Five CLI runtime files installed with private verified backups, source/plan hash checks, the existing source-release/meter locks, staged PHP lint, limited atomic file replacements and post-install hash verification. The live console schedule was appended to rather than replaced with the repository version, preserving concurrent production changes.
- Private release directory: `~/traffic-batch-release-20261105`; plan SHA-256 `524dd966bf7c78e4346a2a7b17dbb3f83f5490fafaf84e034ce8ddd1e58a137f`.
- No migration, guest installation, power operation, manual live debit, cache flush, provider creation or scheduler run was used as a test. Fresh CLI processes activate these files; no FPM reset was required.
- Natural watcher execution produced its attempt state on 5 November at 11:20:23 UTC.

## Rollback

Use only this release's `before-N.php` backups and manifest, after acquiring the same locks and proving every current target still matches its recorded `after` hash. Restore the schedule first, then meter/source, then remove only this release's two new watcher files after confirming no newer dependencies. Do not restore financial/database snapshots: normal transactions after installation must survive. Financial history and existing paid checkpoints must not be reset. The release tool has guarded partial-install rollback; a later operational rollback requires the same explicit current-hash checks.
