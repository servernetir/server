# Billing renewal and suspension safeguards

## Due dates and payment

- A recurring service's date-only `next_due_at` covers the entire due day in `billing.timezone` (Asia/Tehran). Renewal invoice `due_at` is that day's end converted to UTC. Initial order invoices retain the configured payment window.
- Domain renewal invoices use the registered domain's expiry timestamp. They remain payable during the existing registrar grace period and are excluded from stale-order cancellation while the domain is active.
- Renewal generation runs every 15 minutes. Lifecycle enforcement runs every 5 minutes, including restoration after settlement. Both use the same service row lock when checking/creating an outstanding invoice.
- Draft, unpaid, partial and overdue invoices with a remaining balance prevent duplicate renewal invoices. Overdue services are still checked when an invoice-generation run was missed.
- A payment advances one billing period for a continuously active service. Skipping a non-delivered interval requires a recorded, confirmed suspension before the next period. Payment does not claim that a suspended resource is running; restoration must succeed separately.

## Suspension and restoration

- Failed provider operations do not become confirmed service status changes. Monthly lifecycle failures retry after 15 minutes. Cloud power requests are confirmed by querying the actual provider state; an accepted asynchronous request alone is insufficient.
- Hourly credit exhaustion stops billing immediately. The retention countdown starts after shutdown confirmation; failures retry and remain visible. A funded customer's server is not deleted merely because restoration failed, and the first resumed hour is charged only after power-on confirmation.
- Existing hourly hold reserves, hold costs, interruption accounting and the customer's existing credit-exhaustion policy are preserved.
- Manual providers create a persistent work item. The administrator must perform the supplier action and acknowledge completion. Pending work remains visible even after local cancellation. Acknowledgement cannot revive a closed service.
- Monthly expiry does not automatically delete customer resources. The existing 30-day retention notice remains an administrator decision.

## Internal infrastructure and owner exemption

- `billing.internal_service_ids = [20]` excludes service 20 from customer collection, hourly metering, suspension and release. Guards exist both in the jobs and in provisioning entry points.
- The separately deployed owner-account complimentary renewal policy is preserved. Its matching account can advance the renewal date without creating debt or debiting credit, including the internal service.

## Visibility and repair

- Calendar payment/domain queries convert local date ranges to UTC. The calendar also shows an outstanding-payment queue independent of the selected month, including invoices without a date. The queue shows up to 100 rows and the full count.
- Renewal, lifecycle and hourly billing record completion, error and pending-operation counts in the file cache, independent of the database-backed cache. System health warns about missing, stale or incomplete runs. Missing service dates are reported rather than guessed.
- `php artisan billing:repair-dates` previews repairs. `--apply` updates only outstanding invoices with no deadline or the recognizable old default of issue time plus the configured hold window. Explicit staff deadlines, settled invoices and internal infrastructure invoices remain unchanged. Each repair has an activity entry.
- A service with no renewal date requires examination of its delivered/paid period; a guessed future date can forgive debt. The repair command reports those service IDs without inventing dates.

## Deployment and rollback

Use a fresh production source snapshot. Production may include hotfixes absent from the Git branch. Reconcile before testing and enforce pre-deployment hashes for every replaced file. Back up the exact files and the invoice dates affected by repair. Install new helper classes before their callers and rebuild configuration and view caches. Do not run a broad deletion command as a smoke test.

Rollback restores the backed-up files and caches. Restore repaired invoice dates only if their current deadline still matches the repair result and the invoice remains unpaid; retain financial and activity history. No migration or payment-ledger rewrite is required by this repair.
