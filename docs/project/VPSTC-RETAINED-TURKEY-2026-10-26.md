# VPS.tc — one retained Turkey server

Owner scope dated 26 October; execution status updated 27 October 2026.

## Current owner instruction

This instruction supersedes the earlier proposed New York creation/renewal/cancellation test. The owner requests **one cheapest suitable Turkey server**, native hourly billing if actually available and cheaper for the intended retained use, otherwise monthly. **Keep the server for use. Do not delete or cancel it after testing.** No extra server, manual renewal, paid add-on or destructive test is authorized by this instruction.

The retained server is Istanbul product **351**, 1 vCPU / 1 GB RAM / 20 GB disk, Ubuntu 24.04. Its Regular/PrePay VPS contract is monthly. The accepted quote and paid invoice reconcile **TRY 125.55 net + 20% tax = TRY 150.66 for one month**, with no extra storage/backups, the included two-snapshot configuration and zero setup. The public 2 TB traffic advertisement does not establish a hard cap or a no-overage contract; see the [public cost check](VPSTC-TRANSFER-COST-CHECK-2026-10-27.md).

The separate Metered Cloud product is not automatically a cheap hourly equivalent. Its native billing unit, resource configuration, setup/minimum charges and continuing retained cost need a fresh complete quote. Do not invent an hourly price by dividing the VPS monthly price, or choose Metered solely from an extremely small raw amount.

## Execution state

**Exactly one supplier purchase completed; retain the server.** Creation was observed on 27 October at 05:24 UTC; by 05:31 UTC the parent was Active/Monthly and its single VM was running, built and unlocked. The supplier due date is **27 November 2026**. The invoice is Paid with zero remaining due and TRY 150.66 consumed credit. Supplier identifiers, IPs, hostname, access secrets, account balance and raw responses are kept out of public repository evidence.

This verifies the supplier SDK purchase and native read-back. **The ServerNet customer retail checkout-to-delivery flow has not been tested, and public VPS.tc sales remain off.** No sales flag or owner pricing preference was changed. The implementation and earlier currency evidence remain in [money-contract acceptance](VPSTC-MONEY-CONTRACT-2026-10-26.md); current results are in [27 October retained-server acceptance](VPSTC-RETAINED-TURKEY-ACCEPTANCE-2026-10-27.md). A read-only SSH command was submitted, but its result remains unconfirmed after Chrome lost the originating context; read the existing private result before any further command.

## Safeguards for the completed single purchase

1. Bind the exact configuration, current quote, reviewed account units and gross ceiling in the private journal; do not change owner financial settings or public sales flags.
2. Preserve the immutable request and accepted receipt. `/api/order/{id}` can consume credit immediately; the completed order must not be submitted again.
3. Reconcile the original parent, invoice and single VM. A timeout, later missing response or failed optional endpoint never authorizes another purchase.
4. Keep native Paid status and full gross (`total + credit`) as separate payment evidence. Zero remaining due does not mean zero procurement cost; do not apply credit again to the paid invoice.
5. Keep exact identifiers and encrypted access details private. Preserve the resource after all checks. Its continued monthly rental is expected; no test cleanup may cancel it.

## Permitted live checks on that server

- Service Active/Monthly status, invoice currency/tax/gross, immutable due anchor and exact parent/VM relationship.
- VM count/identity, purchased CPU/RAM/disk, IP addresses and selected Ubuntu template. Optional SSH commands read OS/version/architecture/resources/network identity only; no package installation, benchmark or traffic load is required.
- Exact-parent GET endpoints for resources, permitted methods, templates, usage, traffic, networks/IPs, tasks and snapshot/backup/schedule lists. Validate units, period, scope and capability errors; unknown or unsupported data is not displayed as zero.
- Power actions remain untested because an included-price contract has not been established. Do not infer that a listed method is free.

The app's `serverStatus()` is not assumed to be purely observational: its payment reconciliation can apply credit to an unpaid invoice. Establish native Paid status with direct invoice GET before using a poller that may reconcile payment.

## Excluded live checks

No parent cancellation, VM deletion, rebuild/reinstall, password reset, boot/media change, disk formatting/deletion, extra VM/IP/disk, snapshot/backup creation or scheduling, paid resize/upgrade, or renewal purchase. Exercise destructive and paid failure/retry paths only with fake HTTP and isolated test data. API documentation coverage and simulated tests are not represented as live acceptance of those operations.

Domain registration/transfer/EPP/synchronization, tickets, contacts, payment-method changes and other unrelated account operations are outside this server test. Existing supplier/customer services remain outside the mutation scope.

## Documentation coverage

The saved official Swagger `4.20261019` was classified across all **129 paths / 166 operations**. This is documentation review, not 166 executed live tests. Of 82 GET operations, 81 are observations; domain synchronization is a state-changing exception. Remaining groups cover authentication/account/ticket writes, quote/lookup calculations, financial orders/renewals/credit/funding, domain/DNS changes and 35 VM operations. An upgrade request is only an estimate when `send:false` is explicit. Console issues a short-lived browser grant and requires supplier session context; it is not a reusable public console link.

Native method permission does not prove an action has zero cost. New resources, snapshots/backups, disk/IP changes, restores, rebuilds and financial operations retain their separate price/data safeguards. In this retained test, destructive cases remain simulated and unimplemented endpoint families remain documented as unverified; no broad loop may execute all Swagger operations.

## Remaining acceptance

The retained purchase, paid gross, Active/Monthly parent, single running VM and scoped 14-GET matrix are now recorded. The submitted read-only SSH command has an unconfirmed result; no SSH pass/failure is claimed and no blind retry is authorized. Seven GET responses were successfully observed; seven routes returned 404 for this service. Two successful responses use native root JSON lists and require endpoint-specific parsing before future application exposure; the generic financial decoder was not broadened.

General VPS.tc sales, the untested customer retail flow, traffic overage policy and optional operation costs retain their independent acceptance gates. No renewal purchase, cancellation or deletion is authorized by this acceptance.
