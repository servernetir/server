# Bank receipt payment identity and explicit recovery

Base: `88a3adb8e2bc8194cf9bc07d15bc7b6cef0c319e` on `develop`.

## Problem and behavior

When a new bank receipt reused the reference of an older paid payment,
`BankReceiptReviewer` retrieved that older payment with `firstOrCreate`, skipped
settlement because it was already paid, and marked the new receipt approved.
The new invoice remained unpaid and its wallet top-up was never posted.
An unsettled conflicting payment could instead settle the wrong invoice.

Approval now locks and refreshes the receipt and invoice, validates their owner
and amount, and verifies a reused payment's invoice, customer, gateway, currency
and amount before settlement. A conflicting reference remains pending for manual
review. The older payment is never reassigned. The receipt is approved only after
successful settlement and an actually paid target invoice. Exceptions roll back
the approval transaction, and stale approval requests cannot credit twice.

No schema, existing migrations, gateway callbacks, prices or scheduler changed.

## Already approved legacy receipts

`billing:recover-approved-bank-receipt` is a separate, manual CLI tool. It defaults
to a read-only preview and requires exact receipt, customer, invoice and IRT amount.
It supports only an approved, reviewed IRT top-up with zero prior invoice effects,
where the collision belongs to an older paid bank payment for the same customer.
Do not use it to override an uncertain deposit or settle a normal duplicate receipt.

After independently verifying actual funds and the exact approved record:

```text
php artisan billing:recover-approved-bank-receipt RECEIPT --customer=CUSTOMER --invoice=INVOICE --amount=IRT_AMOUNT
```

Only a separately reviewed invocation adds `--apply`. The recovery uses
`PaymentService::settleConfirmed`, preserves the original bank reference in
`ref_id`, uses a deterministic internal recovery key, and records an activity log.
Repeating a completed recovery reports `already_recovered` without another credit.
Existing credit or inconsistent financial effects cause rejection.
There is no new HTTP endpoint or automatic reconciliation job.

## Validation actually executed

- PHP 8.4.27; Composer dependencies installed from the existing lock, without
  install scripts or plugins, in an isolated checkout without production secrets.
- Kernel-level IPv4/IPv6 socket denial verified with `scripts/run-php-tests-offline.py`.
- Original implementation: the paid-reference collision regression fails as
  expected (`true` approval where rejection is required).
- Corrected implementation: 45 tests, 157 assertions passed across
  `BankTransferApproveRetryTest`, `ApprovedBankReceiptRecoveryTest`,
  `PaymentCallbackTest`, `DeliveryRoutingTest`, and `InvoiceOrderExpiryTest`.
- Three production PHP files linted successfully; `git diff --check` passed.
- MariaDB concurrency testing and live recovery have not been performed.
- An initial PHP 8.3 runtime was rejected by locked Symfony requirements; tests
  were run with 8.4.27 instead. The dependency lock was not altered.

## Deployment and rollback

Not installed or applied to production by this change yet. Production source must
first be compared with the reviewed base; preserve concurrent edits and take a
private backup under the normal deployment lock. The PHP manifest consists of:

1. `app/Services/Billing/BankReceiptReviewer.php`
2. `app/Services/Billing/ApprovedBankReceiptRecovery.php`
3. `app/Console/Commands/RecoverApprovedBankReceipt.php`

Observe the actual receipt, invoice, payment and wallet result. A paid invoice or
credited wallet arising from another path is a stop condition, not permission to
post another recovery. Do not run a broad financial queue for acceptance.

Code rollback restores the prior reviewer and removes only newly deployed utility
files whose hashes still match this release. Financial recovery is append-only
and is not undone by code rollback; any financial reversal needs separate review.
