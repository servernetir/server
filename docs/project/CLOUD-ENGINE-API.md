# Cloud Engine customer API

Software interface, 2026-11-03. This document describes local implementation;
it does not establish production deployment or real hardware acceptance.

Use the existing customer API token issuance page. Server permissions require
explicit selection; legacy `read` and `*` tokens do not acquire these controls.
Tokens retain existing expiry, revocation, IP restrictions and active-account
checks. Authentication is `Authorization: Bearer <customer-token>` and does not
use browser sessions or CSRF cookies.

| Permission | Access |
| --- | --- |
| `servers:read` | List/show servers, operation status, metrics and traffic |
| `servers:write` | Bind an existing confirmed-paid order to delivery; includes server read |
| `servers:manage` | Power, rebuild, password reset, hostname, console and deletion; includes server read |

Write and manage permissions do not imply each other. Every server and operation
lookup checks the bearer customer's ownership independently of browser login.
Server identifiers are opaque UUIDs. Infrastructure node IDs, task receipts,
credentials and raw metadata are excluded from customer responses.

## Requests

All paths below start with `/api/v1`. JSON bodies are limited to 16 KiB. Lists
have 50 rows per page and accept a positive integer `page`.

| Method/path | JSON body or query | Result |
| --- | --- | --- |
| `GET /servers` | `?page=1` | Owned servers and pagination |
| `GET /servers/{uuid}` | — | Customer server DTO |
| `POST /servers` | `{"service_id":123}` | Existing delivery operation |
| `GET /operations/{id}` | — | Owned operation status |
| `POST /servers/{uuid}/power` | `{"action":"on"}` | Queued operation; also `off`, `reboot`, `reset`, `shutdown` |
| `POST /servers/{uuid}/rebuild` | `{"image":"ubuntu-24.04","confirm":true}` | Queued destructive operation; same reserved VM identity and IPv4 |
| `POST /servers/{uuid}/reset-password` | `{"confirm":true}` | Queued guest credential reset |
| `POST /servers/{uuid}/hostname` | `{"hostname":"server.example.net"}` | Queued verified guest hostname change |
| `DELETE /servers/{uuid}` | `{"confirm":true}` | Queued deletion and existing service cancellation |
| `GET /servers/{uuid}/metrics` | `?window=24h` (`1h`, `24h`, `7d`, `30d`) | Sanitized monitoring series, never a billing counter |
| `GET /servers/{uuid}/traffic` | `?month=2026-11` | Durable byte totals with coverage/gap information |
| `POST /servers/{uuid}/console` | — | Short-lived one-use opaque gateway grant |

Traffic `in_bytes` and `out_bytes` are exact decimal strings, preserving values
beyond JavaScript's integer precision. GB/GiB unit factors remain explicit.

Every mutation except console-grant issuance requires `Idempotency-Key` of
8–128 ASCII letters, digits, `.`, `_`, `:`, or `-`. Repeating the same key and
validated request returns the original operation. Reusing it for a different
server, action or payload returns `409 idempotency_conflict`. Keys are scoped
to the customer and stored only as hashes. Do not put secrets in a key.

Accepted mutations return HTTP 202 with `{"ok":true,"operation":{...}}` and a
`Location` header pointing to operation status. Acceptance means queued work;
only observed worker completion changes it to `succeeded`. Ambiguous remote
outcomes remain under review and do not free reservations.

`POST /servers` creates no invoice, wallet debit or parallel order. First use the
existing checkout/payment flow. The service must belong to this customer, use an
engine plan, have existing confirmed invoice payment evidence,
and remain eligible for delivery. Initial engine sales are monthly only; hourly
service records are rejected even when historical wallet debit evidence exists.
Monthly invoices may still be paid from the existing wallet. Fraud review and instance lifecycle remain in
the existing provisioner. A review hold returns `409 provisioning_pending_review`
and preserves that hold; it does not pretend that a server was delivered.

Deleting an existing server requires explicit confirmation and stops the existing
service's billing state before infrastructure release completes. Refund decisions
use the existing refund service. An active or uncertain infrastructure intent
may return `409 operation_in_progress`; it must be reconciled through the existing
journal before another destructive operation can proceed. A timeout is not proof
that a clone never happened.

Rebuild requires an accepted compatible image on the reserved node. Password and
hostname changes require explicit verified guest-agent image capability;
unsupported operations return `422 unsupported_operation`. Passwords remain in
the existing encrypted, explicitly requested customer access mechanism and never appear in
operation or list responses. Rescue, snapshots and migration are not claimed by
this interface.

Console issuance returns only opaque gateway material, with one-use and expiry
metadata. Production use additionally requires deployment and real acceptance of
the authenticated gateway. Issuing a grant alone does not establish a working
console connection.

Read requests are throttled to 60/minute, create to 10/minute and management to
20/minute, in addition to existing authentication restrictions. Responses are
private and `no-store`. Errors use public codes such as `confirmed_payment_required`,
`service_not_available`, `server_not_ready`, `unsupported_operation`,
`operation_in_progress`, `idempotency_conflict` and `temporarily_unavailable`.
Raw infrastructure exceptions and credentials are never API error messages.

## Customer browser panel

The existing localized `/account/cloud/{service}` page displays engine operation
status and exact current-month inbound/outbound bytes. A month selector uses UTC
accounting periods. Coverage and stale-reading notices distinguish observed
totals from missing data. Decimal byte strings retain exact 64-bit values in the
browser; monitoring rate estimates never substitute for the traffic ledger.
Observed uptime and an explicitly estimated last-boot time come from the latest
verified monitoring observation, displayed in the customer's configured timezone.
They are not replaced by the VM creation timestamp or claimed as a historical
boot-event log; guest filesystem usage remains unknown where no verified guest
measurement exists.

Power, typed-confirmation rebuild, guest password reset, guest hostname change and
console issuance are available through the existing customer session, IP rules
and CSRF protection. Controls depend on current service state and accepted image
capabilities. Requests reuse the same operation admission/idempotency mechanism
as the API and show a queued message. Stored passwords and observed server state
do not change merely because an HTTP request was accepted. Console redirects
contain only an expiring opaque gateway grant, with `no-store` and `no-referrer`.

## Local verification

From the existing isolated checkout's `website/` directory, with prepared PHP
tools activated:

```bash
php artisan test --filter='CloudEngineApiTest|ResellerPanelPagesTest|SecurityPageTest'
php artisan test --filter=EngineCustomerPanelTest
node tests/js/cloud-engine-account.test.cjs
```

These tests use local SQLite and fake resources, including a simulated existing
delivery pipeline. Their success does not prove MariaDB concurrency behavior,
real guest initialization, production TLS/network access or hardware acceptance.
