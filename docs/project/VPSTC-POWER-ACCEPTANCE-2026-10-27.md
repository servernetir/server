# VPS.tc retained server: power and financial acceptance

27 October 2026. Scope: test the existing retained Turkey server without deletion, cancellation, rebuild, paid renewal, a second purchase, or paid add-ons. This is an operational acceptance record and a test-fixture correction; no application runtime files or sales flags were changed in this follow-up.

## Observed production results

| Check | Observed result | Limit |
|---|---|---|
| Graceful shutdown | The fixed SDK request was acknowledged and the supplier UI subsequently showed Off; the next SDK precondition read showed stopped. | The parent subscription remains active; power-off is not cancellation. |
| Startup | Submitted once from the confirmed stopped state; later SDK read showed running, built and unlocked. | No additional VM was created. |
| Reboot | A controlled reboot was acknowledged; subsequent SSH succeeded with exit code zero and a different guest boot ID from the captured pre-reboot baseline. | Hypervisor VM uptime alone is not a reliable guest reboot witness. |
| Guest after power operations | The fixed read-only SSH checks verified Ubuntu, CPU, memory, block-device and network observations. The final guest uptime was approximately 37 seconds. | No application workload, disk benchmark or high-volume network test was run. |
| Billing | Available credit remained unchanged throughout the observed checks; invoice inventory remained four Paid entries with no new invoice observed. | This is an immediate observation, not proof that delayed charges or every future operation cost zero. Exact account amounts and identifiers are private. |
| Retained subscription renewal | The native Service Auto Renewal page still selected Off after the operations. | This does not establish that future API-created services default to Off or can be changed through the API. With Off, renewal must be requested before the due date to preserve the service. |
| Price calculation after account credit-policy change | Product 351 order form and non-ordering quote passed the current account money reconciliation; gross supplier price matched the paid reference invoice. | This does not publish a retail plan or validate other configurations. |

Power requests were journalled privately before submission, bound to the original encrypted purchase/account receipt, fixed parent and VM, and never automatically replayed. The first reboot acknowledgement was followed by a separate controlled reboot with a guest boot-ID baseline, rather than claiming that an acknowledgement proves the outcome. Raw replies, credentials, host/IP, account balance, invoice bindings and SSH transcripts remain outside Git in private account storage.

## Isolated regression validation and correction

Baseline: `81af1da0d74805e21c881c5d51bab8698f22cfc3` on `origin/develop`. An isolated copy of the earlier working snapshot passed 231 VPS.tc tests/1,863 assertions. Repeating against the freshly fetched develop files exposed one stale fixture: a Paid supplier invoice contained only `total`, while the production adapter now requires the gross components and consumed credit to reconcile independently.

`VpsTcPowerCostTest` now models a credit-paid invoice with zero remaining total, explicit credit, subtotal and tax. A new negative case retains the old incomplete invoice and verifies that it cannot submit a stop operation or create a power-operation record. No financial validation was weakened and no application code changed.

Final command, in the isolated develop worktree with fake HTTP and SQLite:

```sh
php -d memory_limit=768M vendor/bin/phpunit --filter VpsTc --log-junit <private-local-results.xml>
```

PHP 8.4.27: **232 tests passed, 1,866 assertions**, no failed tests. PHP lint of the changed test and `git diff --check` passed. The first low-memory local attempt exhausted the default 128 MiB limit; the recorded successful runs used 768 MiB. These tests cover synthetic lifecycle paths, including deletion rejection/handling; no live deletion was performed. They do not establish retail production checkout-to-delivery acceptance or native cancellation/renewal acceptance.

## Remaining release blockers

- The [existing endpoint matrix](VPSTC-RETAINED-TURKEY-ACCEPTANCE-2026-10-27.md) has unavailable traffic/usage/task/snapshot/backup routes for this service. They were not converted into empty or zero-valued customer features by this follow-up.
- No verified service auto-renew read/write API contract or account-bound default-Off ordering contract has been established. A manual UI change on one retained subscription is insufficient for automatic customer provisioning.
- The [traffic review](VPSTC-TRANSFER-COST-CHECK-2026-10-27.md) still lacks a verified overage price or hard cutoff contract. Light power/access checks cannot prove that exceeding the included quota is free.
- Console brokerage, reinstall, password changes, IP/resize/snapshot/backup purchase, paid renewal and production retail delivery remain unaccepted. Credentials/cookies of the supplier parent account must not be shared with customers. No destructive or paid operation was run to bypass these gaps.

Public VPS.tc sales remain off. The retained server is running and retained for use. Release of the supported subset requires resolving the renewal and traffic contract gaps, completing the account-bound catalog mapping and verifying the retail delivery path. The owner's exclusion of deletion remains in force; do not delete this resource to obtain cancellation evidence.

## Persistence and rollback

Only this record, project index linkage and the test fixture/regression case belong in Git. There is no production application deployment, migration, scheduler change or source rollback for this follow-up. Supplier power changes have been returned to the intended running state. Reverting a test or document must not re-enable supplier auto-renew, auto-apply credit, sales, or delete/cancel the retained server.
