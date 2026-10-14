# GPU queue log audit and automatic execution — 14 October 2026

## Production correction

At **06:19:15 UTC**, a guarded three-file release on `core` fixed GPU intent registration, added a private command audit, and restored the missing scheduler event. Independent verification at **06:24:52 UTC** matched all **19 protected source files** and **61 scheduled events**. The other 60 events, including the object-gateway lifecycle, retained their cadence, overlap and background settings. Existing PHP 8.4 host cron was unchanged.

The log audit since the previous 13 October 13:47:30 release found **31 SQLSTATE 22001 / MariaDB 1406 checkout failures**, from 13 October 16:04:48 through 14 October 00:45:55. `services.provision_status` is `VARCHAR(16)`, but `gpu_capacity_wait` was 17 characters. The shared waiting constant is now `gpu_wait` (8). Panel, credit and delivery-watch consumers already use the constant. There were no persistent GPU intents requiring migration. No schema change was needed.

The first apply attempt stopped before source mutation because the console hash had drifted. At **06:02:58 UTC**, the live schedule had reverted from SHA256 `26bf2311feb8c31e240c2e4424b1ce4c18fd3b62b6f6e368cd5e509ee03c6530` to `8085372bffddf59f4988972dc18c2c47526f86c85282391b207547d15543f425`. Semantic comparison showed exactly the GPU event removed and no other event changed. The revised installer restored exactly that event with the two class fixes. The source of the intervening overwrite has not been established; no attribution is implied.

`AdvanceGpuCapacityOrders` now writes a daily `storage/logs/gpu-capacity-YYYY-MM-DD.log`, mode **0600**, retained for seven days. Each record includes start/finish, elapsed milliseconds, process ID, bounded batch limit, candidate count, outcome counts and success/failure. It contains no customer/order identifiers, credentials or exception messages. A failed item does not stop the rest of the batch; scan, item or audit failure returns a failing command status. Error reporting is preserved.

## Validation without a paid server

- The production MariaDB column metadata was queried. A connection-local **TEMPORARY** table reproduced rejection of the old 17-character marker and acceptance of the new marker, then was dropped.
- A real `GpuCapacityOrders::register` and real `candidates(50)` query ran inside an outer, rollback-only production transaction with model observers disabled, HTTP stray requests prohibited and mail/queue/notification fakes. Insertion and candidate discovery succeeded. No invoice, debit or cloud instance appeared; no HTTP request was sent. Rollback was confirmed and the unique proof intent was absent afterward. An auto-increment sequence gap is possible; no order was committed.
- The new checkout regression test enforces the production length limit with an SQLite trigger, since SQLite normally ignores `VARCHAR` lengths. The old source failed this test as expected; the corrected source passed.
- **90 tests / 891 assertions** passed with zero failures, errors or skips on isolated production and develop source sets. The same suite passed again after merging develop `94c9fc4f`, including object-storage and GPU-copy changes. Counts overlap and are not additive. Tests cover actual schedule registration/discovery, overlap/expiry, empty run, safe audit, failed-item continuation, capacity/price/credit, intent replay, readiness billing, cancellation/timeout, download/waiting pages, OS compatibility, controls and OTP termination. Provider calls were mocked. The external-console test explicitly supplies a supporting capability; a second test covers an unsupported console without changing the separate Arvan safety gate.
- Natural host-cron runs at **06:20:04**, **06:21:03** and **06:22:03 UTC** completed successfully with different process IDs, zero candidates and no GPU errors after release. At 06:22:39 the log permission was 0600 and the scheduler heartbeat was fresh. No live advance command or `schedule:run` was invoked manually.
- At 06:24:53, the sanitized Laravel/tracker audit contained **no new warning/error or tracker entry after 06:19:15**. Billing monitors at 06:20/06:21 showed zero errors and pending actions. This is an observation window, not a promise that providers never fail.
- Services 412, 416, 417 and 418 remained `cancelled / none / deleted`. No paid VM, wallet adjustment, price change, migration, guest modification, network route, NAT/firewall, DNS, VPN, host cron or agent state change was made by this work.

## Other historical errors and coordination

The initial 39 Laravel error entries also included five `cloud:meter`, one `services:lifecycle`, one `fleet:scan` and one `site:gate` exit failure. Current meter/lifecycle runs recovered without errors. The older fleet result identified Arvan before its separate driver repair. The gate reported an obsolete cloud page budget (59 pages versus 21) and missing localized Storage Box sitemap entries. These are being reconciled with the separate root-error repair in PR #57; this release does not claim to have deployed that PR or completed a new paid-provider acceptance test.

Two additional pre-release diagnostic SQL errors are preserved in the audit rather than erased: a read-only metadata syntax probe at 05:46:19 (corrected to `information_schema`) and a 06:09:55 missing-column error without application frames, still requiring attribution. Neither occurred after this release.

The owner authorized coordination with six active chats: GPU support, homepage redesign, root-error repair, SEO, Object Storage and Windows console. They were told to preserve the new marker/audit and exact schedule, use fresh live guards, and reconcile with latest develop. The Object Storage chat confirmed no overlapping live/schedule edits. Whole-tree publication from the dirty shared checkout is not an approved release source.

## Evidence, source hashes and rollback

Git publication: [PR #59](https://github.com/servernetir/server/pull/59) merged into develop as `3744a83af555811b334eb0adb56cb8553406a2c2`; the merge was fetched and its ancestry verified. A subsequent comment-only console alignment makes its checkout bytes match the production hash below. It changes no scheduled behavior.

Post-merge readback at **06:35:54 UTC** confirmed **16 natural successful runs**, one per minute from 06:20 through 06:35, with 16 distinct process IDs, no failed GPU run, all three release hashes intact, exact metadata for all 61 events, zero candidates, mode 0600 and unchanged deleted tests. Six other source files had coordinated changes (the three UI translations, CloudProvisioner, web routes and server detail view), and were recorded as drift rather than reverted. The full-site gate running in the root-error chat exposed an additional template error (one new Laravel error/two tracker records by this checkpoint); it is under that chat's repair. The earlier zero-new-error observation is limited to 06:24:53 and is not extended over this later finding.

Private journal: `/home/servernetcloud/gpu-log-audit-20261014` (0700), outside webroot. Local sanitized receipts: `output/gpu-audit-20261014/receipts-0625/`, plus `develop-synced-tests.xml`. Baseline, original baseline, semantic drift, before sources, after manifest, applied/verified receipts, register proof, observation and test XML are retained. The installer refuses repeated apply. Do not rerun a completed deployment helper.

| Production file | SHA256 after release |
| --- | --- |
| `routes/console.php` | `26bf2311feb8c31e240c2e4424b1ce4c18fd3b62b6f6e368cd5e509ee03c6530` |
| `app/Console/Commands/AdvanceGpuCapacityOrders.php` | `f84fb52dec3da8ad9e6148b2048fa353483305b02ee123d3c7fc22732f27ca9f` |
| `app/Services/Cloud/GpuCapacityOrders.php` | `b9c3ef0bd7a702d08943115e4cd5bf5879e025925c08e20dcdb4b4c7735dc310` |

Restore only a specifically verified file from the private before snapshot after checking fresh live hashes and coordinating other publishers. **Do not restore the 17-character marker**: concurrent or later valid intents can use `gpu_wait`, so any acceptance failure after publication requires forward reconciliation. Do not restore an old entire console that removes the GPU event, or a database/financial snapshot. The logger can be revised independently while preserving the working marker and scheduler. Runtime acceptance here proves real database compatibility, safe isolated workflows and natural empty-queue execution; it does not claim a newly allocated CUDA/Jupyter workload.
