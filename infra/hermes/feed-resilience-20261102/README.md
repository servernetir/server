# Hermes official-feed resilience — 2 November 2026

A single unavailable optional feed previously caused both a source incident and an hourly scheduler failure, despite successful updates from the other feeds. The captured command already isolated each source and preserved prior dated articles. This release adds bounded retries and separates completed degraded refreshes from failures of the refresh machinery.

## Runtime mapping and baseline

| Repository source | Runtime destination relative to application |
|---|---|
| `HermesKnowledge.php` | `app/Services/Hermes/HermesKnowledge.php` |
| `HermesKnowledgeRefresh.php` | `app/Console/Commands/HermesKnowledgeRefresh.php` |

Candidates are based on the exact production blobs `f0c7120d5f4e16ad5d9cdd77699eaad982c35173` and `aa5528838da2de0c5078ba89e768b7450a41fc0e`. Root must recheck current production SHA256 values and preserve any intervening changes before installation. These are source snapshots; do not upload another checkout's older canonical files over production.

## Behavior

- Consecutive failed observations wait 1, 2, 4, then at most 6 scheduled hours. Existing hourly scheduling and its protection against within-hour timing jitter remain unchanged. A success resets the counter and restores the six-hour successful cadence.
- A failed or skipped request never advances `last_success_at`. Prior articles retain their original dates and normal retention limit. Backoff receipts include `next_attempt_at`; owner context exposes source failure, retry count, stale age and overall feed health.
- A refresh is `complete` only when all configured feeds have successful, fresh observations. It is `degraded` when any source is unavailable or stale but at least one validated observation is no more than 12 hours old. A future timestamp is not usable.
- Exit zero for a degraded refresh requires the internal observation, each attempted source receipt and final summary to have persisted successfully. It means bookkeeping completed with usable verified observations, not that every source is healthy. No usable observation, internal/store failures and unexpected exceptions still return failure.
- An unchanged cause is recorded at most every six hours. A changed safe failure signature is immediate. Recovery records a single event and resets the gate so a later relapse is visible.
- Fixed source URLs, strict TLS, no redirects, request budgets, streaming limit, XML/entity protections, source trust and private-reference rules are unchanged. There is no immediate HTTP retry loop, paid model call, customer message or operational action.

## TLS finding and limits

The owner investigation observed a self-signed default certificate from the production host for both assigned IPv4 destinations of docs.cpanel.net. Strict verification from VM127 and Windows accepted valid certificates for the same destinations; no IPv6 DNS records were found. This localizes the discrepancy to the production egress path or host-specific termination. It does not prove which intermediary causes it.

This release does not repair that egress defect, replace the feed, weaken certificate verification or label cPanel healthy. The unavailable source remains visible while other sources update.

## Verification and deployment

Run PHP lint on both candidates, `php scripts/test-hermes-feed-resilience-20261102.php`, and the feature filter `HermesBackgroundReliabilityTest`. Feature tests use an isolated encrypted operations store, dependency doubles and fake HTTP; no production state or network is used. They cover retained data, other-feed progress, skipped degraded visibility, all-unavailable failure, recovery, incident gating, persistence failure and existing internal/busy/disabled contracts.

The two runtime files were installed with guarded hashes and lint at 08:05:03 UTC and their opcodes activated on both web domains. At 08:08 UTC the real refresh returned exit 0 with explicit degraded status: five verified sources and cPanel unavailable. Combined CI passed 59 Hermes tests/708 assertions and 42 pure feed checks; the expanded core suites also passed on SQLite and MariaDB. See [production evidence and remaining boundaries](https://github.com/servernetir/server/blob/develop/docs/project/INCIDENT-REVIEW-2026-11-02.md). Observe the next natural scheduled run and compare its persisted source receipt and explicit summary; a source backoff skip is not a fresh connectivity test.

Only two runtime files are in deployment scope. No VM, route, NAT/firewall, DNS, cron, credential, wallet, invoice, customer lifecycle or reconciler source-of-state changes are required.

## Rollback

After checking fresh hashes, restore only the matching two runtime file backups. Preserve the encrypted knowledge, source receipts, owner memory, references and operational state. Older readers ignore added receipt fields; do not restore old state or replay uncertain tasks. Keep existing deployment locks and exact opcode-cache invalidation for the changed PHP paths.
