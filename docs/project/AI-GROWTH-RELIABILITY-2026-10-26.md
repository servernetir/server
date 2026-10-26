# Growth reliability — partial production release, 26 October 2026

## Verified work

The owner asked to continue improving automatic Growth operation within the previously approved daily 50,000 IRT and monthly 1,000,000 IRT ceilings. Public publication still requires the existing human approval process.

Code was committed to develop as 3264dda8f93b1fc245f1c27bb34652c11fb3190b. The isolated PHP suite passed 52 tests / 260 assertions with fake upstream suppliers. Nine runtime PHP files passed production syntax checks and were installed with matching hashes at 2026-10-26T06:49:15Z (10:19:15 Asia/Tehran), preserving their exact live preconditions. No migration or host-cron change was required.

Installed features:
- Flag concrete prices, unlimited traffic, uptime/SLA, delivery-time and country-count assertions before autonomous image generation or automatic review-ready status. Show the same checks on the existing review screen. This is a conservative screen, not proof of factual correctness or a new automatic publication permission.
- Instruct autonomous generation to avoid commercial figures/promises and internal verification checklists, and to link to current official product conditions instead.
- Retry only an unavailable public source, after at least one hour and at most three times per planning date. No content/job is created before successful source retrieval. A paid, failed or uncertain inference is not retried by this recovery path.
- Expand the maintained educational topic pool from 15 to 30 locale/topic slots. This remains a finite editorial seed pool with exact-title deduplication, not search-demand analysis or semantic deduplication.
- Record a real running/completed/paused/failed/circuit-open scheduler heartbeat in one existing automation-run row, and display its timestamp/staleness, current stop reason, remaining topics and next eligible time. Main pause does not erase the configured autopilot switch.

Runtime scope:
app/Services/Growth/GrowthClaims.php (new)
app/Services/Growth/GrowthAutopilot.php
app/Console/Commands/RunGrowthCenter.php
config/ai_growth.php
lang/fa/growth.php
lang/en/growth.php
lang/tr/growth.php
resources/views/admin/growth/index.blade.php
resources/views/admin/growth/content.blade.php

## Interrupted completion — do not mark fully accepted

Immediately after the file installation, the selected execution environment became unavailable. The following action was rejected before its JavaScript ran: prepare a single-use FPM activation helper, invalidate the nine packaged paths/two affected compiled views, and apply an editorial correction to content 1. That helper was not created by this attempted call, and the article correction was not executed.

Content 1 was last observed during staging as version 4 with status approved. Earlier it had been review; the approval change was preserved. Its existing text still contains commercial assertions needing correction/verification. A replacement of 4,394 characters was prepared and passed the new local assertion screen, but was not saved to production. Do not claim that version 5 exists or carry approval to a changed body. On resumption, reread current text/version/status, preserve any subsequent operator edits, and apply only a guarded correction. The private content-before.json snapshot preserves the observed version/approval state.

FPM activation, read-only production render acceptance, visual acceptance, the first new natural heartbeat, and final post-install hashes/state were not checked after the disconnection. The preceding release observed web validate_timestamps=1; that is not a substitute for acceptance of this update.

Temporary SSH public key growth_harden_1792996311077 was authorized for this continuation and has not been deauthorized/deleted or independently checked since the environment disappeared. No key material or API token is included here. Its temporary local private file also remains pending cleanup.

The .servernet-web-release.lock and servernet-release.lock advisory locks were held over an SSH channel at disconnection. Their release is unverified. Inspect the existing connection/lock state on resumption; do not remove other deployments' locks or blindly rerun the installer.

Private backups, before/after files, journal, manifest, installed.json and content-before.json are under the matching /home/servernetcloud/deploy-backups/growth-reliability-20261026-* directory. Identify the exact directory by its installed.json code_sha and timestamp above; do not guess a destructive target or rerun a deployment helper because the environment was interrupted.

## Cost and infrastructure limits

No paid model request, customer message or public publication was submitted by this continuation. The last pre-install inspection (06:31:59Z) still showed the prior three completed jobs with one attempt each and 985 IRT total charges. The owner token/project remained at the approved caps. A post-disconnection cost/state readback is not available.

No Google or Hermes client integration was connected here. No VM, guest service, route, NAT/firewall, DNS, n8n flow, host crontab or routing-agent source/state was modified.

## Resume and rollback

First recover the authorized environment, inspect/release only this deployment's locks and deauthorize/delete only the named temporary key with positive authorized_keys verification. Reestablish fresh scoped access if necessary. Read installed receipts/current hashes and scheduler state before continuing.

Then finish targeted FPM activation if needed, accept the new screens with a before-query SQL-write guard, observe a natural heartbeat and verify no extra jobs/charges. Apply the article correction only against its current guarded version, leaving any changed body unapproved for human review. Record final cleanup and replace this partial-state notice with verified results.

For rollback, pause only Growth, reconcile any in-flight paid receipts, and restore only the nine hash-matched runtime files from this release; remove the new helper class only after restoring its callers. Preserve module tables, article revisions, media, jobs and financial receipts. Do not restore the whole database/wallet, requeue uncertain inference or change unrelated services.
