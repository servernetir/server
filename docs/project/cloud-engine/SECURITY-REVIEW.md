# Cloud Engine security review record

This records the threat model and required evidence for this change. It is not
a completed penetration test, live firewall review or production approval.
Final commit/test results and independent review belong in the release evidence.

| Boundary | Threat | Required control and validation |
|---|---|---|
| Customer API | Cross-customer VM/control/credentials | Existing scoped bearer token checks plus explicit Customer ownership on each request; sanitized DTOs; negative owner/scopes/expiry/replay tests |
| Payment → allocation | Unpaid provisioning, duplicate purchase | Existing invoice/Wallet settlement proof; deterministic service identity, input fingerprint and idempotent journal; no new charging ledger |
| Placement/IPAM | Concurrent oversell, duplicate IP or VMID | Stable transaction/row locks, DB unique constraints, bounded IP import, reservations retained across ambiguous responses; MariaDB race tests as well as SQLite |
| Provider transport | MITM, credential leak, arbitrary proxy | Verified HTTPS, private CA support, no redirects or TLS bypass, protected admin endpoint configuration, redacted errors; node/token never returned to customer |
| Mutation journal | Lost response, stale worker, confused identity | Persist intent/receipt/phase before remote mutation, bounded lease, exact VM identity marker, UPID exitstatus check; no blind retry creating another VM |
| Delete/IP release | Paid-service destruction or address reassignment | Ownership/payment/lifecycle gates, terminal task plus authoritative absence/network/session reconciliation; uncertain nodes quarantine capacity and IP |
| Guest console | Stolen/replayed grant, cross-VM socket | Expiring opaque token, hash-at-rest, atomic one-use claim and ownership revalidation at connect; fixed gateway upstream, Origin/TLS/expiry tests |
| Host networking | Spoofed public IP, lateral traffic, management access | Separate management ingress, per-VM MAC/IP filter, guest firewall and denied tenant management/lateral access; real packet tests before sale |
| Images | Supply-chain tampering, shared credentials, licensing | Vendor-provenance checksum verification, immutable versions, template sanitization, accepted per-image initialization record, Windows entitlement gate |
| Traffic ingestion | Forged/reset/replayed counters | Node-specific ingestion authentication, integer monotonic observations, epoch/sequence deduplication, one interface observation point, coverage gaps explicit |
| Secret storage | API/root password exposure | Existing app encryption, hidden fields, secure secret entry and 0600 service files, no values in logs/docs/browser; separate least-privilege principals |
| Recovery | Restored duplicate VM/IP or unusable backup | Independent encrypted backups, isolated restore, DB/journal reconciliation before reconnect, escrowed keys and measured RPO/RTO |

Application encryption uses the existing APP_KEY trust boundary. Rotating it
requires a tested re-encryption/recovery procedure; copying it into a public
artifact is prohibited. Backup encryption keys and Proxmox API tokens remain
separate. Check structured errors/logging for provider brand/internal node
details as well as credentials before customer-visible deployment.

Operator/admin node endpoint changes are privileged operations. Review DNS
rebinding/SSRF implications in the actual production network and restrict egress
to approved management endpoints. API input must never select arbitrary node
URLs, Proxmox VMIDs, storage paths, websocket destinations or trusted CA files.
Management firewall and outbound network controls must enforce that boundary.

Outstanding real evidence includes independent TLS validation, least-privilege
token capability tests, tenant source-spoofing/lateral isolation tests, real
browser console expiry/cross-tenant tests, signed image acceptance, generated
traffic/reset/restart accuracy and isolated backup restore. OS reinstall,
credential reset, live migration and rescue are advertised only when the adapter
has a verified supported implementation and per-image real acceptance. Explicit
unsupported capabilities must remain disabled rather than showing success.
