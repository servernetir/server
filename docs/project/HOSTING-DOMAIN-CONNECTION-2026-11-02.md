# Hosting domain connection — 2 November 2026

Status as of 3 November 2026: **installed and verified in production**. The nameserver/DNS component and panel-compatible order selection are live. Existing misassigned hosting remains unchanged and requires separate remediation; see the dated acceptance receipt below.
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


## Production installation and acceptance — 3 November 2026

This section supersedes the historical access/installation blockers above. The owner supplied a fresh authenticated Chrome/cPanel session and had already explicitly approved site and develop publication.

- PR232 was merged into develop at `3ea104337c7efea4aa7f05a5aa6b4398fe8b3461`. The installed fixed payload identifies runtime source `09b7efdc25021b1d53fa4e17bc75858d1ade426f`.
- Fresh account-side checks confirmed uid1020, no prior release state, all fourteen destination before-hashes unchanged, and the private engine byte-identical to the reviewed repository version (SHA-256 `93bb9973f50ee3f1dc485c279253a47c2a4b816eeb6b2178291e1a36d6a2564a`). The staged launcher matched its reviewed template after normalizing only its two checksum pins; both real pins matched the private files.
- Authenticated preview at 05:04 UTC succeeded: fourteen files, ten PHP/compiled lint checks, two actual Blade compiled views. The engine created private candidates, backups and state under `/home/servernetcloud/hosting-domain-release-20261102`.
- Authenticated apply returned `ok=true`, `state=applied`, fourteen source writes and two compiled writes. The guarded engine acquired the three shared release locks, preserved existing modes and required exact destination/candidate hashes. It invalidated only the relevant OPcache scripts; public PageCache keys invalidated: zero.
- A separate verify returned `ok=true`, `state=verified`, fourteen exact destinations, two exact compiled views and successful fresh-process controller-bound GET route verification. No schema/configuration change or supplier operation occurred. No customer DNS, billing or service allocation was changed.
- In an owner-authorized customer view, the actual hosting card displayed its assigned server's nameservers, location, copy controls, guide and retry. The actual customer-scoped JSON endpoint returned `connected` with a fresh timestamp and observed delegation. This establishes DNS pointing to the currently assigned hosting IP, not website/SSL/email health or Windows delivery correctness.
- Live fa/en/tr rendering and 390 CSS-pixel responsive acceptance succeeded; measured page scroll width was 375 at viewport width390. The actual Turkish card retained its connected state; no browser console errors were observed. Copy and guide controls were exercised on the synthetic preview; retry was exercised on the actual customer card. The synthetic needs-setup state remains explicitly a fixture.
- The admin-to-customer view was exited back to the same customer's admin page. Cross-customer ownership denial remains covered by the recorded isolated feature suite; no live cross-customer probe or paid provisioning test was repeated in this installation.
- The transient public launcher was moved back into the private stage, and its former URL rendered the application's not-found page. Private backups, candidates, payload, compiled hashes and verified state remain for guarded rollback. No public helper is left active.

The earlier 43 Laravel/256 assertion, 17 DNS, 43 native-release and seven JS results above belong to the pinned CI run; they were not rerun or relabeled as new tests today. Today's evidence is the actual guarded preview/apply/verify and live browser acceptance.

**Remaining support incident:** the pre-existing Windows-product/WHM allocation still needs content/backup/compatible-Plesk-plan assessment and a separate safe correction. It was not moved, deleted, repriced or silently relabeled by this release, and the customer ticket was not marked resolved. Do not instruct this customer to replace currently working DNS merely because the displayed default pair differs from their existing delegation. The installed system selection fix prevents the historical country-only routing path for new orders.

Rollback: use the same private fixed engine/payload and verified state, with the documented guarded rollback phase. It rejects newer source/compiled drift, restores the card before its partial dependency, and preserves private evidence; do not replace the whole application or restore a database.

Supplemental acceptance and public-helper cleanup: [3 November live receipt](HOSTING-DOMAIN-LIVE-RELEASE-2026-11-03.md), including the fourteen before/after hashes and the separately verified ticket-response follow-up.


## Strict delegation status follow-up — 3 November 2026

PR235 merged at `31a311e904741f8b58f462824a83c9745731b23f`. It supersedes the earlier IP-only connected rule: green now requires both the assigned NS set and exclusive matching address records at both resolvers. NS order/case/trailing dots/duplicates are normalized, and the endpoint uses scoped v2 cache keys to avoid old green results. Focused hosting CI passed, including the new delegation cases and old-cache regression; historical StoreOrder baseline comparison also passed.

Production follow-up used a separate private `hosting-domain-release-20261103-dns` stage, preserving the first installation backup. Preview reported fourteen checked destinations and ten PHP/compiled lint checks; apply wrote two PHP sources and one compiled view, and verify passed exact source/compiled hashes and fresh routes. Four unrelated protected files were hash guarded; no global cache flush or supplier operation occurred. The temporary launcher was retired to the private stage. This is installed/verified file state; post-patch customer browser acceptance still needs a fresh read.

Plesk readiness is separate: the current server-wide DNS template was observed to use `ns1windows.servernet.cloud` and `ns2windows.servernet.cloud`, both resolving to its public Windows IP. An existing live subscription instead retains older domain-specific NS; do not copy a customer's nameserver names to another customer or equate an old zone with the current template.

The priority service was not recreated, moved, deleted or relabeled by this follow-up. Its Linux account was inspected read-only: two web files, a directory, no databases and no actual mailboxes (the initial basic mail listing included the system entry). The correct 10GB Plesk plan exists, but Plesk automatic provisioning remains off. The stored API authentication did not establish working access (REST read returned401; XML ownership lookup was unknown). Do not enable the existing incomplete Plesk driver or dispatch an unverified create.

Pending safety cleanup: only the Plesk customer-recipient checkboxes for customer creation and site creation were temporarily disabled for a quiet, owner-requested correction; a fresh settings read confirmed them off. Customer creation then remained blocked by automatic browser approval review over an unsaved form. **Restoration of these two checkboxes has not been confirmed.** A later attempt to observe the Plesk page was also rejected for the same unsaved-state reason. Preserve the open form and obtain user resolution/authorization before resuming; do not bypass the denial through another browser or transport. No customer response or successful delivery is claimed.
