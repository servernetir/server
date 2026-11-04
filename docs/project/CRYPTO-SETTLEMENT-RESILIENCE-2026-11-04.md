# Crypto settlement resilience — 4 November 2026

Status: PR #261 merged; three approved files and the targeted migration installed
and checked in production on 4 November 2026. A new real-payment settlement was
not exercised: no eligible checkout existed during observation.
Base: develop 60e21fb9b76d381d035e91957e3e9ca6d5580000.
Reviewed head: 588d48db1ae92ed67607ae8703d42296c1c8c8f6.
Installed merge: 0c5e7f1443afb5d5d3ab26e182db70bad5277445.

## Incident and scope

The production error tracker recorded repeated crypto:watch failures at
PaymentService::applyPaid: a complete 66-character BSC transaction hash did not fit
payments.ref_id, declared as VARCHAR(64). The normal transaction rolled back before
wallet credit. A settlement exception could abort the rest of that sweep.
The visible retained errors were for a different transfer than the support case;
direct transaction-specific attribution is not asserted without its historical log.
No customer identity, real address, hash, raw SQL or credentials are recorded here.

## Repair

- A new additive migration widens ref_id to 128, preserving complete references.
  The original migration is unchanged. Down refuses truncation when long values exist.
- A settlement failure rolls back its deposit claim and wallet/payment writes. It is
  reported with non-secret identifiers, retained for retry, and excluded from expiry
  in that sweep. Other payments continue. RPC failures still fail closed by chain.
- Claim uniqueness remains enforced by UNIQUE(chain, txid), with transactional row
  locks and the canonical PaymentService settlement path. Legacy hash case variations
  are compared case-insensitively before a new claim.
- Atomic-unit tolerance uses integer arithmetic, including near the integer limit;
  an overflowing deposit total is retained for review without credit.
- Locked amount, currency, customer and remaining invoice balance must still match.
  Canceled or changed invoices require review, not resurrection or underfunded payment.
- Legacy positive wallet entries that mention the complete transfer hash block
  automatic credit and require review. New operational manual resolutions must keep
  that complete audit reference. This is a fail-closed compatibility guard, not
  proof that arbitrary unannotated historic adjustments can be identified.
- Recently expired checkouts remain observable for two hours after expiry. Detected
  funds go to manual review with their transaction and amount preserved; expiry is
  never permission to settle an old invoice automatically. This bounded window is
  not an unlimited historical-chain audit.
- Expiry, review and successful settlement release only a wallet still owned by
  that checkout. Orphan release rechecks the current reservation under a lock.

## Verification

The focused suite includes 41 tests, including twelve new resilience tests: complete
BSC reference settlement; replay; rollback after a simulated failure following a
wallet write; progress for the next customer; retry; manual-credit exclusion;
expired/canceled invoices; quote changes; reservation ownership; cross-invoice
claim uniqueness; preservation of existing references and refusal of lossy rollback.

The workflow runs PHP 8.4 against SQLite and MariaDB 11.4. MariaDB is essential:
SQLite does not reproduce the original VARCHAR length failure.

Broader legacy checks were compared with the exact PR base. Their existing
failures remain visible and are not relabeled as successful tests. The comparison
rejects new failures, new skips, changed testcase identities, empty/missing reports
and startup failures. One original BSC failure was reproduced on the base and fixed
on the repair in MariaDB.

Final PR run: https://github.com/servernetir/server/actions/runs/41826273662 .
PHP 8.4.27: 41 focused tests / 221 assertions passed on each of SQLite and
MariaDB 11.4. The separate 47-test legacy comparison retained six existing SQLite
failures and seven MariaDB failures, with the original eighth MariaDB failure
fixed. No new failure or skip was introduced. Four local Python comparison
scenarios passed. PHP lint passed in CI and again for the staged production files
and installer. All ancillary PR checks also passed before merge.

## Production installation and evidence

An earlier automatic review denied cPanel access; no alternative access path was
used. After the owner explicitly reapproved the concrete source/log/schema read,
installation and named migration, the origin gate accepted the cPanel Terminal.

The production receipt records installation at **2026-11-04 12:23:20 UTC**, using
PHP 8.4.27 and the existing application directory. Both runtime source files
matched the exact reviewed baseline before replacement; the new migration was
absent. The unchanged PaymentService was checked, not replaced.

- Both existing release file locks were held. The existing crypto:watch event's
  five-minute CacheEventMutex was acquired only for the installation and released
  in finally; no new cron or scheduler execution was created.
- Private source backups, the payments table definition and hashed references
  were saved outside webroot under crypto-resilience-0c5e7f1443af/before.
- Only migration 2026_11_04_000100_widen_payment_reference_for_crypto was run,
  with --path and --force. Live ref_id changed from nullable VARCHAR(64) to
  nullable VARCHAR(128), and exactly one migration record exists.
- All **1,012 pre-existing payment references** matched their pre-migration
  digests. All three installed file hashes matched the reviewed bundle.
- A session/admin/CSRF-protected temporary helper checked the two runtime hashes
  and performed scoped bytecode activation at **12:25:24 UTC**. Neither file was
  cached in that web pool; validate_timestamps=1 and revalidate_freq=2. No global
  cache was cleared. The exact helper was hash-checked and removed afterward.
- At 12:26:52 UTC the existing cron heartbeat had advanced to 12:26:03, the crypto
  mutex was free, and the crypto rows remained 13 confirmed / 40 expired / 3 manual.
  The separately resolved support credit remained a single entry, its balance
  unchanged and its original unpaid invoice canceled.
- At 12:27:45 UTC, 332 retained crypto error records were reviewed through
  sanitized counts only; none was new since installation. The last retained
  crypto error was 09:41 UTC. This observation is bounded by log retention.

There were zero watchable checkouts and zero existing 66-character crypto payment
references during acceptance. Thus live schema preservation, installation and
unchanged business state are verified; successful new BSC settlement is supported
by the MariaDB behavioral tests, not a fabricated live transfer or heartbeat.
No money command, provider purchase, manual scheduler execution, global cache
clear or historical backfill was performed. The earlier owner-approved support
credit is a separate completed operation. No .env, customer identity, transfer
hash, raw SQL or raw customer log was exported into this report.

## Rollback and historical boundary

The private manifest and original source backups support a hash-guarded rollback
of these two runtime classes only. Stop if a subsequent edit changed a file.
Keep the expanded column and migration history: shrinking it could truncate a
later real reference. Scoped bytecode activation is required after any rollback;
the temporary helper is removed and must not be assumed to remain installed.
Restoring code does not reverse financial effects. Every money correction requires
its own reconciled ledger entry and authorization.

Historical checkouts outside the two-hour observation window are not replayed.
Any historical recovery must first reconcile the transfer, original invoice,
claim and existing credits, including canceled/manually credited cases. The repair
does not assert that all older transfers were audited or recovered.

## Related knowledge

See SUPPORT-KNOWLEDGE-2026-11-04.md for the approved, retrieval-tested support
runbooks. They do not grant Hermes money-moving or infrastructure-changing authority.
