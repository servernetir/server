# Arvan pending traffic reserves: release state, 5 November 2026

## Verified implementation

PR [266](https://github.com/servernetir/server/pull/266), merged as `d95bbcf0bbe31b532ffabcc4e72bfca4be987257`, adds encrypted per-customer pending-traffic reserves. Below the 5,000 IRT invoice minimum, eligible supplier traffic reduces spendable credit without a small invoice, payment or ledger debit. Exact paid components clear atomically on settlement; missing/excluded/held evidence does not release reserves. Ownership anchors and retained cycle pins prevent reassignment or missing-report double charging. Pending credit exhaustion records a typed hold for the existing worker.

The activation key is `arvan_traffic_exposure_enabled_v1`. New deferred reserves and their stop intents require literal value `1`. Preserve the exposure-aware Wallet and settlement reader during rollback after any reserves exist. Flag off does not release existing reserves or cancel existing holds.

Guest counters remain nonfinancial evidence. This does not enable node-counter charging or duplicate the existing supplier-finance meter.

## Tests and review

- Candidate `70402800682f6636ff9a7d7741857ae5de2369f4`.
- Isolated PHP 8.4.27 / SQLite host suite: 114 tests, 876 assertions passed.
- GitHub finance run 42040394593: SQLite and disposable MariaDB passed; MariaDB 119 tests, 1,050 assertions.
- MariaDB includes actual ordinary Wallet debit after an old REPEATABLE READ snapshot and a second connection commits a new encrypted exposure plus another ledger debit. An unaffordable debit fails, an exactly fundable debit succeeds, the reserve survives.
- Existing negative controls reproduce failure when locking/current reads are removed. All seven candidate PR workflows passed.
- Independent review found the three previous blockers fixed: regressed time/quantity/repricing, first-baseline ownership transfer, and losing a missing cycle pin when another cycle settles.

## Actual source installation; activation NOT complete

Four production files were installed in order: Exposure, Wallet, CreditGuard, Meter. The source release takes the existing release and meter locks, checks the exact old source against develop `9c3d8732336f4c2ae6c7c016818dd5c1b975a511`, saves private backups, lints staged PHP, performs limited atomic replacements and verifies all four installed hashes against candidate704028.

- Private source release: `/home/servernetcloud/traffic-exposure-release-20261105`.
- Source plan SHA-256: `ef286f7029c201b69117a443cd0866b90aab149abfdcf25c573dda04706277df`.
- Apply returned `installed_sources_activation_pending`; separate source verification returned `verified`, four files.
- Pre-install application read: flag absent, zero exposure rows; Composer classmap-authoritative false.
- No migration, artificial live invoice/debit, scheduler invocation, guest mutation or power operation was used as a test.
- Native FPM activation has NOT passed. The account-private primary was rejected by the selected FPM listener with “Unable to open primary script ... (No such file or directory)”. This is not a successful activation receipt.
- A temporary denied primary was staged at `/home/servernetcloud/public_html/_traffic_exposure_20261105/activate.php`, parent0700, PHP0600, .htaccess0600 with exact bytes `Require all denied\n`. External browser reads reached the site's 404 page on both domains; this does NOT establish the required Apache403 denial and activation must not proceed on that evidence.
- Public-primary canonical one-newline PHP SHA-256 is `5e67bfc65a1c8da4b6b272a71c000127b95cfd4e7ae7a5e6e99e34f1048723b0`. The private attempted primary hash is `95667090422b1410e4f88cafd890aefb7b1303ddf4432b8e6e623ed38a85e49b`.
- At the end of this session cPanel terminal disconnected, reconnect reported a failed WebSocket handshake, and loading cPanel itself returned ERR_CONNECTION_CLOSED. The final public-primary observe result could not be obtained. No activation or new exposure enablement is claimed.
- Temporary-primary cleanup remains required once access returns. Remove only the exact owned hash/mode/listing-verified primary and .htaccess, then its empty directory; retain private plans/backups/receipts. No broad recursive removal.
- The open WHM at core.servernet.ir has no servernetcloud account and was not used to modify FPM or application files. Do not infer it is the application host because both shells say core.

## Live monitoring findings

All four running services444,530,544,613 lacked current transport/enrollment/status records in the new application observation registry. They were created before the Nov3 Linux first-boot bootstrap. Attaching a bootstrap to new orders does not enroll existing guests.

Service613:
- Authenticated read-only guest inventory confirmed root access, Ubuntu/systemd, default deviceeth0.
- Legacy servernet-node-exporter is running, bound only to guest loopback9100; it is not the newer managed19100 installation.
- Through the already authenticated Proxmox UI and qemu guest agent, VM127's servernet-prometheus and servernet-arvan-613-tunnel were confirmed active.
- The legacy arvan-node target reports up=1 every60seconds; RX/TX and netdev success are present through the existing loopback tunnel.
- Legacy target labels/job are incompatible with the new servernet-arvan/snet_* enrollment contract, and node_boot_time_seconds is absent. Do not fabricate boot evidence or label this as full new consumer coverage.
- Existing exporter/tunnel/Prometheus configuration was read, not replaced. No new SSH key/user, listener, firewall rule or private transport was created.

Services444/530: bounded authenticated-inventory attempts did not obtain a shell and answered zero password prompts. This does not prove exporter absence. Service544 still needs a fresh authenticated path; no password/key reset was attempted.

## Remaining acceptance

1. Restore access to the correct application cPanel. Recheck installed four hashes, current flag, exposure rows and concurrent deployment changes before any continuation.
2. Complete native activation and a separate verify request through each actual serving FPM pool; do not guess a listener from the host header. Resolve public-root/FPM visibility and prove HTTP denial before using any temporary public primary. Clean up only verified own temporary files afterward.
3. Enable deferred reserves only after runtime and current data acceptance. Observe a natural meter run; no fabricated live financial test.
4. Complete secure central query-only transport, real target identity/labels/device and enrollment, then two strictly newer coherent samples. Keep613's working legacy tunnel until replacement is accepted.
5. Obtain legitimate existing guest access for444/530/544, verify/install monitoring and enroll them. Do not reset credentials or claim they have no exporter from a failed SSH attempt.
6. Delivery gating and monitoring-gap enforcement are not implemented by PR266. Central failure must remain unknown and must not cause a fleet-wide stop. Any individual hold policy requires fresh identity-bound evidence, a guarded grace period and protected-service exclusions.
7. Continue supplier-cost reconciliation and preserve prior manual-charge/excluded-account decisions. No zero-loss or fleet-coverage guarantee has been established.
