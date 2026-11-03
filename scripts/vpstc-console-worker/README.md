# VPS console relay

Install locked build dependencies with `npm ci --ignore-scripts`, then run
`npm run build` and `npm test`. The build uses only this repository's vendored
noVNC and Pako sources and preserves their license/source notices. It generates
the committed `novnc-source.mjs` and a single Cloudflare module at
`dist/worker.mjs`. Use that built module for a dashboard upload; the source
`worker.mjs` has a local import and must not be pasted by itself.

The browser loads one content-addressed noVNC module served directly by the
Worker. This avoids the slow dependency waterfall through the application host.
Both relay sockets explicitly use ArrayBuffer before `accept()` for current
Cloudflare compatibility dates. No console credentials enter static assets.

`ENABLED` defaults to `0`. Keep the existing Arvan Worker, bindings and routes
unchanged. Use only the dedicated `vps-vnc.servernet.cloud` domain and its exact
route, since the account also has a broader wildcard route for another app.

The Laravel owner pilot and customer rollout are separate persisted flags.
`vpstc_console_enabled=1` permits only active customers with eligible, receipt-bound
services; ownership, supplier account, state and single-use grants are still
checked. Disable that flag and the owner pilot before disabling the Worker.

`build-release.py ABSOLUTE_NEW_PRIVATE_DIRECTORY` prepares the initial six-file
Laravel package. `--headers-only` prepares the narrow browser-policy follow-up.
`--broker-only` promotes the installed owner pilot to the separately gated
customer policy, preserving all other live files and keeping rollout flags unchanged.
`--protocol-only` applies the separate Istanbul location guard to the installed
protocol client. Unaccepted or unknown regions cannot use the Istanbul gateway.
The private CLI helper prepares exact-byte backups, then separately accepts
`--apply` after review. It acquires both shared account release locks and stops
on source drift, existing class files, a route cache or a previous apply attempt.
It does not activate flags, run migrations or flush application caches.
