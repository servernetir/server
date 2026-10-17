# Arvan customer console generalization — 17 October 2026

## Status: implementation prepared; NOT installed or accepted in production

Owner authorized repair for all Arvan customers, testing only the already purchased owner service, keeping sales open, and committing/pushing develop. No new VM, deletion, reboot, password change, financial change or sales-setting change is authorized by this release.

Live read-only inspection identified service 501 / instance 315 as active/running and belonging to the owner. The real customer page showed the no-console message and missing Windows-password warning. The installed Arvan driver advertises `console=false`; the broker activation setting is `0`. The installed broker remains bound to the deleted acceptance fixture. The clean develop base `0662461f` differs: it advertised console=true and returned a direct WebSocket URL, inconsistent with the broker's HTTPS provider-console contract. Do not overwrite the production driver or controller wholesale from develop.

## Prepared changes

- Replace fixed service/customer/instance/test deadline with active-service checks and binding each 90-second single-use grant to service, current owner, instance ID and provider reference.
- Recheck ownership/binding/lifecycle both when redeeming and claiming. Preserve HMAC validation, atomic claim locks, encrypted upstream URL and no-store responses.
- Preserve controller ownership, writable-state, CSRF and rate-limit checks; require enabled/eligible broker before requesting a console. Remove direct provider redirect fallback.
- Make Arvan console capability depend on the independent broker activation, not sales settings. Normalize the driver response to the official HTTPS console contract. No provider API/account credential is delivered to the browser.
- Support validated matching regional socket paths on the fixed console host. Existing observed socket suffix remains an explicit setting; other regions' actual connectivity is not proven by unit tests.
- Preserve a controller's stricter no-referrer policy through security middleware.
- Retain Worker font, branding, cookie restrictions, fixed origins and existing secret interface. No new secret or DNS route is required by the code change.

## Validation and remaining deployment work

Post-integration correction: develop advanced concurrently to `3acf65f9` (Navasan pricing). After rebasing the console change, the combined run produced 91 passes / 11 failures / 3 errors out of 105 tests (297 assertions). Running the same files on the unmodified `3acf65f9` base reproduced exactly the same 11 failures and 3 errors (83 passes out of 97); failures concern currency/catalog/provisioning fixtures. The focused broker and customer-row suites on console commit `ce2ad234` passed **22 tests / 54 assertions**. Thus the 105/105 result below applies to the original `0662461f` base, not the rebased final branch. No full-green-suite or production-acceptance claim is made; the independent baseline failures were preserved, not skipped or fixed as unrelated work.

Executed isolated PHP tests for ArvanConsoleBrokerTest, CloudServiceRowTest, CloudConsoleLocaleTest and CloudArvanTest: **105 passed, 327 assertions** using the existing local PHP 8.4 runtime/config. Worker Node suite: **13 passed**. An initial runtime lacked mbstring; the complete existing runtime was used without modifying production. Initial regressions exposed the changed capability/driver expectations and middleware overwriting no-referrer; fixes and corresponding test updates were rerun successfully. Test cases cover a second customer/non-fixture service, wrong owner route denial, changed owner/instance, deletion, expiry, replay, HMAC, concurrent claims, regional mismatch, and actual controller-to-driver-to-broker redirect contract with fake provider HTTP. These do not prove a real desktop connection.

The owner confirmed expansion of the existing console relay to each authenticated customer's own machine. Cloudflare login was verified: servernet-vnc production version 85f6f3ed, route vnc.servernet.cloud/*; the editor remained in Loading after navigation. cPanel then disconnected and reconnect failed; reload returned to Login (around 16:21 Tehran). **Publication is blocked on restored cPanel access and an operable Worker editor/API.** No runtime files or settings were written. Do not report this Git change as deployed or tested against the guest.

After confirmation: inspect current Worker code/settings and live web files; back up privately and apply only reviewed deltas with hash guards and a release lock. Publish compatible Worker code first while the broker remains disabled, preserve bindings/secrets/routes, then install broker/controller/driver/middleware deltas and activate the broker. Use the existing authenticated web OPcache mechanism; do not treat CLI OPcache reset as web activation. Test the owner's existing service through the actual customer button: image, harmless input, fresh ticket/reconnect, expired ticket rejection. Do not enter/change the Windows password or reboot/delete/rebuild the guest. Keep sales unchanged.

Rollback: disable only the console broker setting to stop new sessions; restore the exact backed-up file deltas and previous Worker version while preserving newer unrelated edits. Already-open WebSocket sessions are not revoked by a database setting alone; this change validates access at connection time. No route/NAT/firewall, scheduler, provider resource or invoice changes are part of this release. Password automation and final test-cost reconciliation remain separate unresolved work.
