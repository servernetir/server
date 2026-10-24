# Customer account moderation

Status: implementation validated; production publication pending.

Administrators can block or reopen a customer from **Customers → customer profile → Block / unblock account**. Every status change requires a reason (3–2,000 characters) and records the previous/new status, administrator identity and time in an append-only history. Staff can read that history; only administrators can change account access. The last 25 entries are displayed and older entries remain stored.

Existing statuses are retained: active, pending, suspended and closed. Suspended and closed accounts cannot start customer sessions or use customer APIs, including AI admission. Each actual status change increments a session version and rotates the remember token. Previously issued sessions remain invalid after reopening; a fresh login is required. Customer OTP, Google, two-factor and registration logins all bind their session to the current version.

The operation does not delete the account, wallet, invoices, tickets or services. Running services are not automatically stopped. A service suspension is a separate operator action; closing an account alone does not stop provider charges or hourly infrastructure costs. This feature does not prevent the same person from creating a different account.

The status and full audit record are saved in one database transaction. If the audit cannot be saved, the status change fails. A stale form cannot overwrite another administrator's newer status change. Moderation reasons are internal; customer-facing login errors are generic and localized in Persian, English and Turkish.

## Validation

- 13 dedicated tests cover ban/unban, mandatory reason, stale forms, role boundaries, old sessions after reopening, remember-token rotation, API denial, localized redirects, escaped history and transaction rollback.
- 173 related tests passed across customer administration, support roles, impersonation, OTP, two-factor, Google login, security and AI admission. One unrelated existing impersonation-banner string assertion fails identically on the unmodified baseline.
- The exact production-merged candidate passed 20 account-moderation/customer-administration tests after including the production-only Hermes route dependencies in the isolated test copy.
- PHP syntax checks passed for every runtime candidate.
- No real customer was banned or unbanned during acceptance.

## Deployment and rollback

Apply only migration `2026_10_24_180000_add_customer_account_moderation.php` before switching runtime files. It adds `customers.session_version` and `customer_status_changes`. Preserve existing production changes using a fresh exact-byte snapshot, expected-hash guards and a private backup. New model/middleware files must exist before the provider/routes are switched. Invalidate changed opcodes and clear route/view caches.

Rollback only the files from this release while checking their installed hashes. Preserve moderation audit records and session versions. Do not automatically roll back the schema or erase moderation history. If any account has already been blocked, assess how reverting continuous session enforcement affects that account before rollback.
