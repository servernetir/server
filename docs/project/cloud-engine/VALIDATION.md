# Validation and delivery status

Implementation began on 2026-11-03 from develop
`dddea6407d52c44bd0c0853a1bb148235ea11e17`; delivery resumed on 2026-11-05
after the managed execution environment restarted. This report concerns local
software validation, not a production deployment or hardware acceptance.

## Completed checks

| Check | Final validated result |
|---|---|
| New Engine suites, SQLite | 104 tests, 855 assertions; no failures, errors or skips |
| Same suites, MariaDB 11.8.6 | 104 tests, 855 assertions; no failures, errors or skips |
| Six new MariaDB migrations | Up/down/up passed; business tables preserved |
| File-SQLite concurrent allocation | Three synchronized two-process races; 18 invariants passed |
| InnoDB concurrent allocation | Three overlapping two-process races; 18 invariants passed; actual lock contention observed |
| Console gateway and collector Python | 11 passed; fixtures and real local socketpair protocol checks |
| Onboarding, image and economics Python | 10 + 8 + 7 passed |
| Customer UI JavaScript | Four jsdom interaction tests passed |
| Existing OTP and relay JavaScript | 19 + 28 + 14 checks passed |
| Existing GPU proxy JavaScript | 11 tests passed |
| Website functional requests | Health, fa/en/tr home, login and 12 actual CSS/JS assets passed |
| Reusable environment installation | Executed twice successfully, including local migrations and pinned dependencies |

The Engine billing tests exercise the existing PaymentService and
ProvisioningService paths, including duplicate confirmed renewal callbacks,
asynchronous suspension, paid restoration and no duplicate wallet charge.
Provider HTTP responses are fake; these tests never purchased a server.

The final existing-regression baseline was independently exported from develop
`65b425d7571d74910c466906461e9749c035da63`. It ran 1,680 cases with 9,626
assertions: 1,546 passed, 108 failures, 22 XML errors and four skipped. The
integrated feature selection ran 1,699 cases with 9,798 assertions: the same
1,680 existing cases have identical outcomes, and all 19 additional matched
feature cases passed. There are **zero new existing-test regressions**. See
[the machine-readable comparison](BASELINE-COMPARISON.json). Existing baseline
failures were preserved and remain purchase-gate blockers, rather than being
hidden or converted to passes. Earlier results against the original implementation
base are retained in local evidence, not silently reused for the final integration.

After the environment restart and integration, all 104 new Engine tests again
passed on SQLite with 855 assertions; the same final suite was also executed on
MariaDB. Local website smoke requests again passed. No dependency lockfile or
historical migration was rewritten.

Raw JUnit/logs and independent baseline sources are retained locally under
`/workspace/.servernet-env/`; raw diagnostic logs are not committed.

## Repeating the Engine checks

```bash
source /workspace/.servernet-env/activate.sh
cd /workspace/server/website
php artisan test --compact --filter='(EngineOperationsTest|EnginePlacementTest|EngineIpamTest|EngineAdminTest|EngineTelemetryTest|EngineBillingLifecycleTest|EngineCustomerPanelTest|CloudEngineApiTest)'
php tests/standalone/cloud-engine-placement-race.php
node tests/js/cloud-engine-account.test.cjs
cd /workspace/server
/workspace/.servernet-env/gateway-venv/bin/python -m unittest discover -s infra/cloud-engine -p test_telemetry.py
python3 -m unittest discover -s infra/cloud-engine/onboarding -p 'test_*.py'
python3 -m unittest discover -s infra/cloud-engine/images -p 'test_*.py'
python3 -m unittest discover -s infra/cloud-engine/calculators -p 'test_*.py'
```

The MariaDB race runner accepts only an explicitly selected disposable local
socket-only server; it creates and removes generated test schemas, never uses a
production URL, and requires PDO MySQL. The prepared environment also retains
separate local MariaDB startup/test helpers and PHP configuration.

## Open acceptance gates

No hardware was purchased or activated, no production migration/scheduler was
run, and no live customer service was changed. The new provider defaults disabled
and supports monthly VPS billing only; existing providers remain available.

Outstanding items include current official API/schema review, pinned verified OS
artifacts, licensed Windows provenance and native firstboot execution, current
supplier/SKU/subnet quotations, real Proxmox/browser console interoperability,
public routing and guest isolation, generated per-VM TAP traffic across restarts,
and off-node backup restoration. This executor lacks network capabilities and
`/dev/net/tun`; socket tests cannot substitute for VM/TAP acceptance. The
one-working-day onboarding target remains unverified.

Resize, extra public IPs, provider-side rDNS, IP migration, snapshots and rescue
are not advertised as implemented Engine capabilities. Guest filesystem usage
is explicitly unknown; allocated disk is separate. Traffic records do not
automatically charge overages or shut down guests.

The image catalog deliberately contains disabled evaluation candidates without
invented checksum pins. The cost example is synthetic, not a supplier quotation.
These and the failing existing baseline keep the purchase gate closed. See
[acceptance](ACCEPTANCE.md), [security](SECURITY-REVIEW.md),
[node operations](NODE-RUNBOOK.md) and [economics](HARDWARE-AND-ECONOMICS.md).
Saving environment settings does not publish a snapshot or deploy the system.


## Git and environment delivery

The feature is published on `feat/servernet-cloud-engine`, targeting `develop`.
Draft PR creation was attempted but the runtime denied the GitHub GraphQL API at
`api.github.com`. The network addition is saved in the environment configuration
draft; it has not been applied to the running instance. No PR number or successful
PR creation is claimed. Review and apply the saved network setting to unblock it.

Reusable install/start instructions are saved through the environment draft,
including PHP/Composer/SQLite, locked JS dependencies, pinned gateway test
requirements and restart instructions. Saving a draft is distinct from publishing
an environment snapshot or validating restoration in a new task.
