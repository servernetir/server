# Crypto settlement resilience — 4 November 2026

Status: repair prepared in PR #261; not installed or accepted in production.
Base: develop 60e21fb9b76d381d035e91957e3e9ca6d5580000.

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

Broader legacy checks must be compared with the exact PR base. Their existing
failures remain visible and are not relabeled as successful tests. The comparison
rejects new failures, new skips, changed testcase identities, empty/missing reports
and startup failures. One original BSC failure was reproduced on the base and fixed
on the repair in MariaDB. Final run URLs and totals belong in the PR validation.

## Production plan and boundaries

The owner requested the repair and prevention of recurrence. Browser automatic
approval review nevertheless denied the privileged cPanel origin. No alternate
hosting terminal, API, SSH or workflow was used to bypass that denial.
Production installation, the schema migration and historical reconciliation remain
blocked until that access is authorized by the tool.

Once access is available:

1. Read only the relevant current source hashes, actual column definition,
   migration state and sanitized payment failure metadata. Do not read or export .env.
2. Use the existing release locks, exact before/after hashes, a private backup and a
   targeted schema backup. Reconcile concurrent edits instead of copying whole files.
3. Coordinate the existing crypto job so it cannot run between incompatible release
   steps. Do not run schedule:run, provisioning or unrelated financial commands.
4. Install and run only the named new migration, then the reviewed reconciler/model,
   with the verified existing PHP binary. Do not run all outstanding migrations.
5. Resume the existing job and observe natural progress, full reference storage,
   error counts and business state. Bootstrap/HTTP success alone is insufficient.
6. Audit manually credited/canceled cases before any historical replay. Never apply
   a transfer twice. Do not reopen canceled invoices or buy infrastructure to test.
7. Keep the expanded column on code rollback. Restoring old code does not reverse
   financial effects; every money correction requires its own reconciled ledger entry.

No production payment, wallet adjustment, provider purchase, scheduler execution,
schema change, global cache clear or historical backfill was performed by this repair.
The earlier owner-approved manual support credit is a separate completed operation.

## Related knowledge

See SUPPORT-KNOWLEDGE-2026-11-04.md for the approved, retrieval-tested support
runbooks. They do not grant Hermes money-moving or infrastructure-changing authority.
