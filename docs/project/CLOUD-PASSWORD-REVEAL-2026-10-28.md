# Repeatable cloud-server password reveal — 28 October 2026

## Behavior

Customers who forgot or did not receive the initial reveal response can request the stored server password again from the service page. A prior `password_seen` value no longer disables the reveal button or POST action. The stored credential is not reset or rotated.

The existing authenticated owner check, CSRF protection, six-per-minute route throttle and LightNode delivery-readiness checks remain. Ordinary page loads do not decrypt the credential. An explicit POST places the value in transient session storage scoped to the cloud instance, redirects to that service's localized page and consumes it on display. The page and successful reveal response carry `Cache-Control: no-store, private`. Existing masked input, show/hide and copy controls remain.

The legacy seen marker remains for provisioning compatibility. A successful request records a secret-free audit event; impersonating staff are attributed as staff and use the existing staff-only event category. An audit request proves that the reveal action succeeded, not that the customer read the password or logged into Windows.

Panel guidance and delivery-email guidance are consistent in Persian, English and Turkish. Delivery emails continue to exclude the password.

## Scope and persistence

- Base: `develop` at `a1fc3933eb72ddca81e2d20add749ee35d0be7d0`.
- Application changes: account cloud controller, cloud-server Blade view and three locale files; regression coverage in the related feature tests.
- No schema migration, scheduled task or new infrastructure dependency is required. Passwords retain the existing encrypted storage.
- Affected component: web application serving the customer console. No customer guest, route, NAT, firewall, reconciler state or provider resource is changed.
- Git registration was explicitly requested separately from the earlier production installation. No deployment is performed by this commit operation.

## Validation and delivery state

The repeatable-reveal controller, panel and translations were installed earlier on 28 October at 10:44:39 UTC. That candidate passed 70 isolated regression checks using synthetic credentials, an in-memory SQLite database and blocked outgoing HTTP. Production browser acceptance confirmed repeated masked reveal, refresh returning to the reveal button, and staff audit attribution. Private customer evidence and deployment artifacts are deliberately excluded from this repository.

At Git registration, the additional delivery-email wording correction had not been deployed. The owner subsequently authorized its production installation and testing; that follow-up is recorded below. The Git source was reconciled onto current `develop` without incorporating unrelated local edits.

Local validation used PHP 8.4.26 in an isolated worktree, matching `composer.lock` dependencies, SQLite in memory and synthetic credentials. All eight changed PHP/Blade files passed syntax checks, and `git diff --check` passed.

```text
php vendor/phpunit/phpunit/phpunit tests/Feature/CloudRootPasswordRevealTest.php --no-progress
11 tests, 78 assertions, passed

php vendor/phpunit/phpunit/phpunit tests/Feature/CloudProvisionTest.php tests/Feature/LightNodeClientTest.php tests/Feature/CloudDeliveryReadinessTest.php --filter 'test_root_password_is_revealed_on_repeatable_explicit_requests|test_customer_cannot_reveal_lightnode_password_until_delivery_is_confirmed|test_cloud_email' --no-progress
6 tests, 25 assertions, passed
```

The full application suite was not run. These local tests do not establish MariaDB behavior, email delivery or an actual remote-desktop login. No provider purchase, password reset or customer message was performed for Git validation.

### Delivery-email production follow-up

Status: **installed and verified in production** for the email wording and server-side render. Source/base Git revision: `a23af6a1d14258272d9f5159905be8795abeabd2` on `develop`. Installation completed on 28 October 2026 at **11:51:30 UTC**.

Only `email_service_pass_panel` changed in each of `website/lang/fa/ui.php`, `website/lang/en/ui.php` and `website/lang/tr/ui.php`, mapped to the corresponding live application language files. The release started from fresh live files, preserving every other translation rather than replacing those files from the checkout. PHP 8.4.27 lint and full-array comparison after removing the selected key passed for all three candidates. Private backups, before/after SHA-256 preconditions, a release lock, permission-preserving staging and per-file atomic rename guarded installation. All three installed hashes matched the reviewed candidates.

The actual installed `ServiceReadyMail::render()` and full email layout passed **31 checks** with synthetic service data across Persian, English and Turkish. Checks included the exact new guidance, removal of the once-only promise, service-page link, recipient locale, layout direction, locale restoration and exclusion of a supplied synthetic password. Tests used a read-only in-memory SQLite connection, unavailable production database connections, array mail/cache/session, blocked outgoing HTTP and private compiled views. No email was sent and no production database write occurred. The candidate passed the same 31 checks before installation; an initial candidate-harness path error was corrected to retain production translation support files, without changing the application.

After installation, public `/up` checks returned HTTP 200 on both the main site and customer console. These are bootstrap health checks, not evidence of an SMTP delivery. The delivery path sends this mailable synchronously; the naturally scheduled cloud synchronization process loads fresh language files. No queue restart, broad cache flush, migration, cron invocation, provider operation, guest change or network change was performed. PHP-FPM cache state and actual mailbox receipt were not separately tested by this CLI render acceptance.

Private release identifier: `password-reveal-email-20261028`. The backup and manifest remain outside the webroot. To roll back this follow-up, restore only the three email-key changes after checking for newer edits; the guarded helper refuses unknown live hashes. No rollback was required. Reverting this text correction does not roll back repeatable password reveal.

## Limits and rollback

The stored password may no longer match the guest if somebody changed it directly in the operating system. This feature does not read the guest's current password. Delivery-email receipt, customer reading and a successful RDP login are not established by reveal events. Concurrent requests may consume or expire a transient session value; the customer can request it again.

Rollback uses the inverse of this commit's scoped changes after checking intervening edits. Reverting the code restores the previous one-time restriction; it does not change the stored credential or revert valid audit history. Production rollback is a separate, reviewed deployment of the affected application hunks, not a whole-file replacement from an older checkout. No network or guest rollback is needed.
