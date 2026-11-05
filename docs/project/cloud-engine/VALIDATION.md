# Validation and delivery status

Implementation began on 2026-11-03 from develop
`dddea6407d52c44bd0c0853a1bb148235ea11e17`; delivery resumed on 2026-11-05
after the managed execution environment restarted. This report concerns local
software validation, not a production deployment or hardware acceptance.

## Completed checks

| Check | Result before environment restart |
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

The original existing-regression baseline was verified from a separate unchanged
source archive: 1,639 cases, 1,500 passed, 113 failures, 22 XML errors and four
skipped. The runner classifies three XML errors as risky. The feature selection
contained the same existing cases plus 19 new passing cases, with no changed
existing outcomes. See [the comparison](BASELINE-COMPARISON.json). Existing
failures were not hidden or assertions weakened; many reflect older pricing
fixtures against the verified-FX contract. A later develop integration needs its
own recorded baseline; it must not inherit this historical comparison silently.

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
