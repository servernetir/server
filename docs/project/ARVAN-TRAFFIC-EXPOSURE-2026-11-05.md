# Arvan pending traffic reserves — accepted 7 November 2026

## Current production state

The reserve feature is **enabled**, after native runtime verification and cleanup. This closes the activation blocker recorded on5November; it does not establish full fleet monitoring.

- Financial implementation: PR266, merge `d95bbcf0bbe31b532ffabcc4e72bfca4be987257`; tested candidate `70402800682f6636ff9a7d7741857ae5de2369f4`.
- Four live PHP files matched the pinned installed candidate again on7November: Exposure, Wallet, CreditGuard, Meter. Composer permits PSR-4 discovery.
- Native PHP8.4.27/FPM: both recorded same-account pool sockets completed activate, then separate verify requests; all four classes/source hashes verified in each. No Laravel bootstrap, database/provider operation or global cache reset occurred in these requests.
- Flag `arvan_traffic_exposure_enabled_v1=1` enabled at **2026-11-07T05:34:13Z (09:04:13Tehran)** under the existing source-release/meter locks, with native receipt/source checks and a guarded setting transaction.
- Natural scheduled apply completed **05:35:47UTC (09:05:47Tehran)**: deferred3lines, total3,887IRT, billed0IRT. A separate application read confirmed3reserve accounts, total3,887IRT, enabled1. No artificial live debit/invoice was used as a test.

## Financial behavior

Accrued eligible supplier traffic below5,000IRT reduces spendable credit through encrypted per-customer reserves, without a small invoice, payment or ledger debit. At or above the minimum, the existing tax-inclusive customer batch settles once and clears only its exact paid reserve components in the same transaction. Missing, held or excluded evidence preserves existing reserves and cycle pins.

Ownership anchors reject reassignment before the first paid baseline. Regressed time/quantity, decreased amounts and same-evidence repricing require reconciliation. A valid pending-credit exhaustion records a typed hold for the existing worker without fabricating an invoice. Guest counters remain nonfinancial evidence and do not become a second charging source.

Flag off pauses new deferred reserves/stop intents; it does not release existing reserves, erase checkpoints or cancel existing holds. Preserve the exposure-aware Wallet, Exposure class and settlement reader after any reserves exist.

## Validation

- Isolated host PHP8.4.27/SQLite:114tests/876assertions passed.
- GitHub finance run42040394593 passed SQLite and disposable MariaDB; MariaDB119tests/1,050assertions. All seven candidate PR workflows passed.
- MariaDB covers actual ordinary debit after an old REPEATABLE READ snapshot while another connection commits a new encrypted reserve and ledger debit; an unaffordable debit is refused, an exactly affordable debit succeeds and the reserve survives.
- Independent accounting/source-release/native activation review completed. The three prior blockers were fixed: monotonic evidence, first-baseline ownership binding and retaining a missing cycle's pin while another settles.
- Live preflight preview:3deferred rows/3,887IRT;10held items (one prior owner exclusion and nine historical resources needing a verified baseline), one unallocated item. Those decisions were not overridden or silently treated as paid.

## Source release and native acceptance

Private release directory: `/home/servernetcloud/traffic-exposure-release-20261105`. Plan SHA-256:`ef286f7029c201b69117a443cd0866b90aab149abfdcf25c573dda04706277df`.

The four-file installer used exact old/new hashes, private backups, PHP lint, existing locks and limited atomic replacements. Source-install receipt and a separate four-file hash verification were successful before runtime activation.

The original private FastCGI primary remained invisible to FPM. A temporary primary at `/home/servernetcloud/public_html/_traffic_exposure_20261105/activate.php` was used instead, with canonical directory0700, PHP/.htaccess0600 and exact source pin. The working caller must use matching SCRIPT_NAME/REQUEST_URI `/_traffic_exposure_20261105/activate.php`; the former unrelated virtual script name caused the initial failure.

Canonical primary SHA-256:`5e67bfc65a1c8da4b6b272a71c000127b95cfd4e7ae7a5e6e99e34f1048723b0`.

Do **not** claim Apache403 was proved. A scoped root rewrite-deny block did not yield403 through the current frontend: exact console GET/POST returned301 and site GET/POST404, without redirect following, with TLS verification and no helper payload. Acceptance instead used the reviewed native FastCGI guard before any action:
- missing native parameters, wrong native plan pin and spoofed HTTP headers all returned stopped on both sockets (six native negative controls);
- four external GET/POST requests with correct-looking spoofed HTTP headers returned no helper payload;
- both pools then passed actual activation plus independent verification (four successful requests).

Ordinary HTTP headers become HTTP_SERVERNET_* and cannot satisfy the fixed native SERVERNET_* guard. This was independently reviewed as the isolation boundary, without weakening server configuration. No credential or HTTP activation token was placed in a URL.

Exact hash/mode/owner/listing-guarded cleanup removed the temporary primary, child .htaccess and empty directory. Only the known prepended root deny block was removed, preserving all remaining bytes; private evidence/backups remain. Cleanup receipt:`cleanup-20261107.json`. Native and boundary receipts:`native-acceptance-20261107.json`, `boundary-acceptance-20261107.json`.

## Monitoring findings and remaining work

The four running services444/530/544/613 lacked approved new observation transport/enrollment/status records. They predate the Nov3 bootstrap; new-order first-boot attachment does not retroactively enroll older guests.

Fresh bounded authenticated inventory on7November:
-613: root/Ubuntu/systemd, default deviceeth0, legacy servernet-node-exporter active, guest loopback9100 only; no newer managed19100 manifest. Existing VM127 Prometheus/tunnel had been confirmed healthy under legacy job/labels, with RX/TX and netdev success but no boot-time series. This is not acceptance by the strict new consumer.
-444/530: no authenticated shell, zero password prompts answered. This does not prove exporter absence.
-544: still requires fresh authenticated access; no key/password reset attempted.

Central query-only transport, trusted bindings/enrollment and two fresh coherent samples remain to be accepted. Existing legacy collector/tunnel were not replaced by the financial rollout. Delivery gating, guarded monitoring-gap enforcement, future enrollment and historical supplier reconciliation remain open. A central outage must not become an inferred customer fault or a fleet-wide stop.

## Recovery

Pause new reserves by setting the feature flag to0 under the same deployment/meter locks if necessary; keep readers/settlement compatible with existing reserves. Do not restore financial/database snapshots or remove reserves to make spendable credit look larger. Runtime rollback must account for cached Wallet code before removing Exposure. Recheck all current hashes and concurrent changes before restoring any source from this release's backups.
