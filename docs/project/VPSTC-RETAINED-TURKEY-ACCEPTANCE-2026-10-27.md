# VPS.tc retained Turkey server acceptance — 27 October 2026

Later follow-up: [power and SSH acceptance](VPSTC-POWER-ACCEPTANCE-2026-10-27.md) supersedes the SSH-unconfirmed status below. Shutdown, startup, a guest-boot-ID verified reboot and subsequent SSH are now observed; public sales still remain off. The remaining text is the earlier purchase-stage record.

Status: **one supplier SDK purchase and native read-back verified; SSH result unconfirmed**. This is not customer retail checkout-to-delivery acceptance. Public VPS.tc sales remain off; no sales flag or owner pricing preference was changed. The server must be retained for use, with no cancellation or deletion after testing.

## Purchase and running resource

- Exactly one Istanbul product 351 was purchased with Ubuntu 24.04. No Metered Cloud purchase or hourly price was inferred.
- Accepted monthly quote: **TRY 125.55 net + TRY 25.11 supplier tax = TRY 150.66 gross**, zero setup and no extra storage/backup selection.
- Native invoice: Paid, remaining due zero, consumed credit TRY 150.66. The gross is reconciled independently of the zero post-credit total.
- Creation was observed at **05:24 UTC**; read-back at **05:31 UTC** confirmed an Active/Monthly parent with due date **2026-11-27** and exactly one running, built, unlocked VM: **1 core, 1,024 MB RAM, 20 GB disk**, selected Ubuntu 24.04.
- Native resource quota fields include `data_combined=2048`. Their presence does not establish traffic counting/reset rules, hard cutoff or zero overage cost.

Supplier service/VM/invoice identifiers, IP addresses, hostname, balance, credentials and raw responses are deliberately omitted. Private encrypted evidence retains the exact bindings.

## Scoped read-only endpoint matrix

`S` means `/api/service/{parent}` and `V` means `S/vms/{vm}` for the exact retained resource. **Seven responses were observed successfully; seven routes returned 404.** These are observations of this service at this time, not a claim that every advertised API feature works or costs zero.

| GET path | Native result | Accepted observation / limit |
|---|---|---|
| `S/resources` | 200 object | Top keys `limit`, `total`; quota/capacity observations only. |
| `S/methods` | 200 object | `methods`, 36 entries. Permissions do not prove operation price. |
| `S/templates` | 200 object | 26 templates observed; this does not establish every OS licence or rebuild cost. |
| `S/ip` | 200 object | `ips`, 2 entries; addresses omitted. |
| `S/networks` | 200 object | Top key `network`; no network change performed. |
| `V/ips` | 200 root JSON list | 2 entries; valid native JSON. The object-only observer decoder initially rejected the envelope. |
| `V/interfaces` | 200 root JSON list | 1 entry; valid native JSON. The object-only observer decoder initially rejected the envelope. |
| `S/traffic` | 404 | No billing-period traffic result verified. |
| `S/tasks` | 404 | No task-list contract verified for this service. |
| `V/usage` | 404 | No metrics/usage result verified. |
| `V/snapshots` | 404 | No snapshot inventory result verified. |
| `V/snapshots/schedule` | 404 | No snapshot schedule result verified. |
| `V/backups` | 404 | No backup inventory result verified. |
| `V/backups/schedule` | 404 | No backup schedule result verified. |

The two root-list responses were inspected from encrypted captured data; they are not provider-unsupported responses. No generic SDK decoder or application capability was changed. Future exposure needs fixed-endpoint validation of their ownership, fields and units. A 404 does not establish zero usage, an empty inventory, a disabled schedule or free operation pricing.

## Scope still open

One read-only SSH command was submitted through the authorized Chrome session. Browser access lost its originating context while the private result was being read. No SSH outcome was observed: neither success nor failure is claimed, and command submission does not prove the helper reached the login step. Read the existing private report/attempt state before considering any further command; do not replay the purchase or blindly repeat SSH. The [public cost check](VPSTC-TRANSFER-COST-CHECK-2026-10-27.md) leaves traffic overage/hard-cap policy and included power, console and snapshot costs unresolved. No power, console, snapshot/backup creation, resource resize, rebuild, password reset, paid add-on, renewal, cancellation or deletion test is claimed here.

The ServerNet customer retail workflow and general sales require separate acceptance. Keep the existing resource and use its private receipt for subsequent reads; do not create another server to resolve an optional endpoint or access check.

## Validation and final account observation

The temporary fixed-GET observer passed three isolated fake-HTTP tests with 57 assertions. The purchase/reconciliation PHP and SSH Python/PHP snippets passed syntax checks and static review; these checks do not establish SSH success. At 05:35:59 UTC, the account currency was freshly resolved as TRY, public sales were off and local VPS.tc plan/instance/provider-operation counts remained zero. The retained supplier resource is deliberately outside the untested retail create lifecycle. No application runtime source, owner pricing preference or sales flag was changed in this acceptance.
