# Cloud API rate-limit isolation

Base: `19481868e6c505d341622f25a211b0925c757a24` (`develop`).

## Cause and behavior

The deployed cloud routes used unnamed `throttle:120,1` and `throttle:20,1` middleware. Laravel's unnamed signature excludes the route and budget, so status polling and power requests could share one counter. Its middleware priority also moved the limiter before the custom bearer resolver; unauthenticated request identity at that point meant customers sharing a client IP could share the counter. A successful GET with remaining read quota did not prove power quota was available.

Four synthetic HTTP regression tests reproduced polling blocking Start, missing retry metadata, cross-customer interference and exhausted read quota blocking power on the unchanged base. No provider or paid API call was used in these tests.

The dedicated composed middleware runs after the existing bearer/scope checks, then delegates to Laravel's standard limiter with distinct read/power prefixes. It deliberately does not inherit the framework throttle class, so middleware sorting cannot move it ahead of bearer authentication. Limits remain 120 read requests and 20 combined Start/Stop/Reboot requests per minute per authenticated customer. Multiple tokens of the same customer share the appropriate budget; different customers do not.

Quota denials preserve `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` and reset headers, add `ok=false`, `error=rate_limited`, `rate_limit_scope=cloud:read|cloud:power` and integer `retry_after`, and prohibit caching. No operation is dispatched or created on denial. The client should wait the indicated delay, keep one idempotency key for retries of the same logical operation, and inspect the returned operation receipt before dispatching another logical command. A 429 can be returned before an operation exists. No readiness/preflight endpoint is added; quota headers describe only their response's budget.

## Validation

- PHP 8.4.27, isolated checkout, matching dependency lock, synthetic customers and fake providers.
- Before fix: four regression tests failed (287 assertions reached).
- After fix: `CustomerCloudApiTest` passed, 28 tests / 508 assertions.
- Migration and powered-off billing suites passed, 8 tests / 33 assertions; no schema change is included.
- Changed runtime PHP files pass lint and `git diff --check` passes.
- Production installation and web acceptance are recorded below only after execution. No customer power operation is needed for deployment acceptance.

## Runtime deployment and rollback

Only `website/app/Http/Middleware/CustomerCloudApiThrottle.php` (new dependency first) and `website/routes/customer-cloud-api.php` (routes last) are runtime targets. The old route SHA-256 is `ae0e817dfb9be8683ec1b208894ecf27429b8cf8b7f7a55a4e2a65fca787ee13`. Abort on any different source, active route cache, authoritative autoloader or concurrent release. Use the four existing shared web-release locks, private backups under the account's existing `deploy-backups` directory, candidate lint, exact before/after hashes and atomic file replacement. Do not run migrations, composer updates, broad cache clears, scheduler jobs, financial operations or customer Start/Stop.

Rollback restores the backed-up route first, only if it still matches this release's installed hash. Leave the unused middleware file in place to avoid deleting a file used by an in-flight request; remove it only in separately reviewed cleanup. Invalidate only the changed route file's OPcache if required by observed FPM settings. Preserve all concurrent production overlays.
