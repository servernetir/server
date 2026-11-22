# Email marketing: bulk approval and sending

Base: `develop` at `0765e213a79585914b98f12a943ca7cb7e5a2095`.

The account email-preferences menu entry is removed. Its localized GET redirects to the profile; POST returns 410. Existing public unsubscribe links continue working.

The admin email dashboard now approves selected evidence records or all pending records across pages using one actual written document reference. Decisions retain the administrator, reference and time. The historical document-linking endpoint remains compatible and separate from the new bulk approval endpoint.

Active registered customers with a valid unique email are included by the owner's default registration policy, without fabricating a customer opt-in event. Withdrawals, suppression, inactive accounts, changed addresses, ambiguous ownership and unavailable historical identity keys remain excluded. External contacts require a verified latest evidence decision and a matching address.

Old external evidence stored only HMAC digests. The original email addresses cannot be reconstructed. A bounded CSV upload with an `email` column restores only addresses matching existing evidence; stored addresses are encrypted. New evidence imports retain the encrypted address. Importing addresses does not approve evidence or remove optouts.

The composer stores an escaped plain-text draft and shows a preview. A separate acknowledged action snapshots the eligible audience into a unique per-recipient queue. The existing configured CRM mailer and sender are reused. The minute worker sends batches of 25, checks eligibility immediately before sending, and records provider acceptance, skips or unknown results. Interrupted/uncertain attempts are not automatically resent. Signed unsubscribe links support a side-effect-free GET and idempotent POST, preserve private response headers, and propagate withdrawal to the shared CRM suppression list.

## Validation

- PHP 8.4 isolated checkout with SQLite: **99 tests, 1,989 assertions passed**, including all 664 pending records across pages, stale selection rollback, shared-reference audit, CSV restoration, registration defaults, suppression, missing historical keys, deduplication, explicit queueing, provider receipts, no retry after uncertain outcome, public withdrawal and profile rendering in fa/en/tr.
- All 28 changed non-Blade PHP files passed lint; `git diff --check` passed.
- CI extends the existing SQLite/MariaDB workflow to the campaign tests.
- Live SMTP authentication succeeded without sending a message. No customer campaign or bulk approval was performed during verification.

## Deployment and rollback

The scoped installer `scripts/deploy-email-marketing-20261122.php` fetches exact files from a pinned merged commit, verifies source and live precondition hashes, saves private backups and runs only the new additive migration creating three email tables. It preserves unrelated live changes in AccountController and the scheduler using exact transforms, invalidates only changed Blade templates, and verifies installed hashes.

File rollback restores only this release's unchanged installed files. New tables and consent/delivery history are retained; no schema drop or data rollback is part of production recovery. The installer does not create/queue a campaign or send messages. Production acceptance is recorded separately after installation.
