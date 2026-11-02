# Hosting domain connection — 2 November 2026

Status: publication explicitly approved; CI validated; private staging uploaded. **Not installed or verified in production: the authenticated browser connection repeatedly timed out.**
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

## Latest validation and root cause (supersedes initial blocked-access status)

The owner explicitly approved publication to the site and `servernetir/server` develop. Production source reads and private staging uploads then succeeded. No live application source has been changed. Repeated local-browser timeouts prevented authenticated preview/apply/verify; the transient public entrypoint is removed before handoff, and private staged artifacts are retained. Do not present staging as installation.

Service history confirmed an online **Windows** product order, followed by an assigned WHM hosting server. This is a system routing defect, not evidence of a wrong customer selection: Windows catalog groups historically use category=shared/reseller, and the previous country-only server helper selected WHM. Product::hostingServerType now resolves stable Windows groups to Plesk, explicit panel categories to their panel, and Linux shared/reseller to WHM. Country availability and actual selection both apply panel/status/capacity/location constraints. StoreController rejects missing compatible capacity before service/invoice creation. Storage/nonhosting contracts and manual Plesk remain unchanged. The existing misassigned customer account was not moved or deleted; correcting it requires a separate content/backup/target-plan assessment before setup instructions are sent.

[PR232](https://github.com/servernetir/server/pull/232), runtime commit `09b7efdc25021b1d53fa4e17bc75858d1ade426f`: [CI41503897168](https://github.com/servernetir/server/actions/runs/41503897168) succeeded on PHP8.4.27: **43 Laravel tests / 256 assertions**, **17 offline DNS scenarios**, **43 native release checks**, **7 JavaScript tests**. This includes the five connection tests and thirteen panel-selection regressions, with real Blade renders for fa/en/tr. StoreOrder comparison ran the current suite and unchanged base in separate checkouts: **six existing failures on each side, zero new failures**. These obsolete pricing fixtures remain reported, not silently passed or covered by production pricing changes.

Live shared-file candidates use exact feature hunks on freshly read production files; unrelated live differences are preserved. Raw Fileman reads must set update_html_document_encoding=0: the default can insert/modify meta charset and is unsuitable for before hashes. Account uid1020, private stage0700, existing card and StoreController0600, Product/routes0644 were observed. Fourteen destinations include two extra files for the routing fix (Product and StoreController), replacing the original ten-source/twelve-destination scope. The engine independently verifies raw file hashes and modes before any application write.
