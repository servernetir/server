# Hosting domain connection — 2 November 2026

Status: implementation prepared for review; **not installed or verified in production**.
Base: develop `2a2e7452e4fd8aacc3473e64de7a1daa814925b5`.

Hosting cards lacked the assigned server's nameservers and an actionable DNS status. The new fa/en/tr component displays the current assigned server's nameservers, copy controls, hosting location, a collapsible registrar/IRNIC and website setup guide, and a lazy asynchronous DNS check with retry and checked-at time. CSS is scoped, responsive and supports reduced motion. Nameservers are read from `servers.nameservers`; there is no country-based guess or stale delivery snapshot fallback. WHM, Plesk and DirectAdmin retain their own configuration. No customer DNS is changed.

The customer-only GET endpoint checks ownership and delivered hosting type before any DNS lookup. It has a 20/minute throttle and a two-minute result cache keyed by service, domain, server, IP and normalized nameservers. HTTP responses are private/no-store. Each check queries NS, A and AAAA from two fixed public resolvers using the existing bounded DNS wire client (six queries, 1.2 seconds each). There are no requests to customer-controlled HTTP URLs or socket destinations. Results and timestamps disclose no credentials or supplier data.

`connected` requires both resolvers' direct address records to contain only the hosting IP, without an unverified address in the other IP family. Matching NS alone is `propagating`, not proof of website availability. Disagreement is pending; timeout/SERVFAIL/contradictory NXDOMAIN is unknown. A proxy/CNAME is not guessed healthy. A missing domain, fewer than two nameservers or unknown hosting IP is unconfigured. UI text explicitly separates DNS connection from website/SSL/email health and warns existing website/email users to migrate records before replacing NS. Custom DNS users are directed to support first.

## Validation actually performed

- PHP 8.3.6 syntax checks of new PHP files, translations and routes: passed.
- `php website/tests/standalone/hosting-domain-connection.php`: **17 scenarios passed**, no network requests. Includes resolver disagreement, timeout, SERVFAIL, NXDOMAIN, mixed addresses and incomplete configuration.
- From website: `node --test tests/js/hosting-connection.test.cjs`: **7 passed**. Covers lazy check, duplicate clicks, failed fetch/session, unknown payload, origin restriction and clipboard failure.
- `node --check website/public/assets/js/hosting-connection.js` and `git diff --check`: passed.
- Added five Laravel feature tests for ownership, cache invalidation, assigned-server NS, undelivered services and trilingual component rendering. **Not executed**: the local PHP is 8.3.6, while locked Symfony packages require >=8.4.1. Composer stopped at platform validation; dependencies/lock were not changed or bypassed.
- Browser inspected current ticket, customer service assignment and server nameserver settings. **No live UI acceptance or mobile visual QA for the new component has been completed.**

## Deployment and rollback

Ten source files / twelve destinations: the two new PHP classes, route file, card partial, new component and three translation files map below `servernet_app/`; each of the two public assets maps to both `servernet_app/public/` and `public_html/`. The companion manifest records source hashes only; it is **not** a production before/after manifest.

Before installation, read the live versions of the shared card and routes and reconcile concurrent differences. Pin destination before hashes, acquire the existing release lock, back up each destination privately, then stage and lint with PHP 8.4.1+ on an isolated checkout with the production-compatible lock. Run the new feature tests and relevant account panel regression tests with fake resources. Install dependencies/controller/translations/assets and route before enabling the card include. Confirm route/config cache state; do not flush shared caches. Verify hashes and any necessary targeted Blade/OPcache activation.

Acceptance requires customer-scoped JSON, denial for another customer, actual rendered fa/en/tr cards on desktop/mobile, copy/retry behavior, no console error and correct existing DNS evidence. Diagnose the observed Windows-product/WHM assignment separately before sending panel-specific setup instructions; this UI change does not move hosting accounts or correct provisioning.

Rollback the card include first, then the route and new files to their exact backed-up state, guarding against newer changes. No schema, billing, cron, provider purchase or customer DNS changes are included.

Production file access was blocked by automatic approval review because use of a previously supplied cPanel credential for this request was not considered sufficiently authorized. No alternate production-access path was used. Deployment and the customer reply remain pending explicit authorization and acceptance.
