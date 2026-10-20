# Arvan console: owner-only activation and tab-race release — 20 October 2026

Follow-up: the owner subsequently authorized a single new diagnostic. Live image,
mouse/keyboard, concurrent sessions and reconnect passed; that exact VM and its
dedicated resources were then removed and independently verified absent. See the
[completed owner test](ARVAN-CONSOLE-OWNER-TEST-2026-10-20.md). The no-active-VM
constraint below describes this release's original checkpoint; automatic Windows
password delivery remains unverified and public consoles stay closed.

## Current result and acceptance boundary

The owner approved the two-file website activation with a short shared-cache
refresh, and a one-hour credential scoped only to `servernet-vnc`.
The website owner-only policy and the Worker race fix are now installed and
verified at the boundaries described below. **Full guest-console acceptance
and automatic Windows password delivery are still pending.**

Production readback at 07:13:44 UTC:

- Public Arvan console setting: `arvan_console_worker_enabled=0`.
- Owner pilot: `arvan_console_owner_pilot_enabled=1`, limited to the existing
  customer ID1/code `SN-457136` and that account's eligible own services.
- Hourly sales: `1`. Windows allowlist SHA256 unchanged:
  `cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845`.
- Both installed PHP hashes matched the release manifest; the pilot audit event
  was present.

The read-only query at 07:00:34 UTC found **zero active Arvan services for the
owner**. Deleted historical services501/516 are not acceptance targets. No
machine or disk was created, deleted, rebooted or modified in this release.
No customer invoice, credential or sales setting was changed. No Arvan support
contact occurred. A new paid diagnostic requires a fresh quote and approval.

## PHP publication and enforcement

Base checkout: `fbe4e318da179adac89406ae5f48653154b09679` on `origin/develop`.
The owner policy was already in Git; only the following two files were deployed:

| Production path below `servernet_app/` | Installed SHA256 |
|---|---|
| `app/Http/Controllers/Account/CloudServerController.php` | `b462f032754ba0827a33ae77f7eff9d4bdb00277fe8f3190498f41d0116595be` |
| `app/Services/Cloud/ArvanConsoleBroker.php` | `7cfe1cf7842b51760cb767e61c6f4d4d059cb2f11056caab022459683acbad73` |

The existing private package is
`/home/servernetcloud/servernet_app/storage/app/arvan-owner-console-20261018-62630c2cff1e`.
Its helper/payload hashes and both live-before hashes were rechecked before
applying. The guarded `--apply` ran once at **07:04:40 UTC**. Before snapshots,
manifest, release lock, lint and atomic expected-hash installation were used;
the live broker's unrelated logger formatting was preserved.
Do not rerun prepare/apply blindly over the completed package.

At **07:05:58 UTC**, the approved authenticated `/system/opcache` activation
returned HTTP200, `reset=true`, and reported all four config/route/view/cache
clear commands in `cleared`. The endpoint does not check individual command
exit statuses; subsequent signed web checks verified the relevant new handler.
The shared-cache refresh can invalidate unused console links. No logout or
session-deletion operation was performed. CloudProvisioner was deliberately
excluded and its live hash stayed
`4b05d5932913b276d169e52efa5cb3c7b4a50a13a12f2818624fe9888b1821ca`.

At **07:06:52 UTC**, a guarded transaction enabled pilot1 only after checking
the installed hashes, public0, pilot previously unset, and the persisted active
owner identity. `Setting::put` invalidated settings cache and the
`arvan_console_owner_pilot_enabled` activity event recorded the scoped approval.

The policy validates fresh owner/service state at issue, redeem and claim.
The controller captures owner ID, instance ID and exact provider ref before
requesting a provider console URL; the broker reloads and matches them before
granting access. Reassignment, closure or replacement during that request fails
closed. Missing snapshots also fail closed during mixed-version deployment.
Authentication, rate limiting, single-use grants and HMAC remain intact.
The obsolete deleted501 bypass is removed.

At **07:08:03 UTC**, the live broker returned public-enabled false and denied
all four sampled active Arvan services belonging to other customers. This was
a read-only policy check, not a provider or guest operation.
Already-connected WebSockets are not forcibly terminated; requests already in
flight may retain their request-local settings snapshot.

## Reproduced Worker defect and scoped publication

An independent regression reproduced a concrete cross-tab race:

1. Tab A consumes its grant and waits for an upstream WebSocket upgrade.
2. Tab B launches and stores its newer short-lived cookie.
3. A's delayed successful101 response unconditionally clears the shared cookie.
4. B then fails authentication despite its grant still being unused.

The fix removes only that cookie deletion from the successful101 response.
The broker claim has already been consumed; retaining the cookie does not
permit replay. Its original90-second lifetime, Secure/HttpOnly/SameSite=Strict,
per-tab binding, exact Origin and single-use checks remain enforced.
No broader cookie lifetime or public access was introduced.

Publication to **only `servernet-vnc`** at **07:10:56.475994 UTC**:

- Version: `05aa6a4e-0c43-469d-921f-64918c9ddd24`.
- Deployment: `e530b006-fadf-43d9-b5e5-a613e6068fe6`.
- Dashboard: **100%, Ready**, API publication.
- Worker source SHA256:
  `0061efa6a79b7626793d7cb423820e6b2d58f3ec12ee88bedd07a7da9e800857`.
- Normalized API-readback module SHA256:
  `9120841db4858446218ec1e17c0fe77812a84ce710a2bd7af4d3b075480c3ee7`.

The fixed-target release helper first backed up live code/settings and verified
that normalized live code matched the Git base. It preserved bindings through
`keep_bindings`; post-deploy source matched and all readable settings compared
equal, with zero changed setting keys. Secret values were not read or logged.
The existing `vnc.servernet.cloud/*` route stayed unchanged.

The account-owned temporary token allowed only Individual Workers Editor for the
specified Worker, with validity07:08–08:08 UTC20October. It had no DNS or other
Worker permission. Its value was never printed or committed; the restricted
local credential file was removed after verification. Server-side early
revocation was not performed or claimed; the configured expiry is08:08 UTC.
Private code/settings backups and receipts remain outside Git.

## Tests and actual browser evidence

- Node22.17.0 Worker/client suites: **48/48 passed**, including delayed successful
  upgrades for both tabs, matching provider references, consumed-grant replay410
  and mismatched-tab409. Upstream and WebSocket101 were synthetic.
- PHP8.4.26/PHPUnit12.5.31, `ArvanConsoleBrokerTest`: **29 tests/90 assertions
  passed** on the base SHA. SQLite memory, array cache/session/mail, synthetic
  credentials and mocked provider calls; no production environment loaded.
  The independent runner blocked actual network execution. An initial extra
  restriction caused six Guzzle-construction failures; correcting the runner,
  not application code, produced the passing result.
- Fresh Chrome and in-app-browser navigations loaded the actual branded
  token-free console and the expected instruction to reopen from the service.
  Captured Chrome warning/error logs were empty.
- A synthetic nonexistent ticket in the in-app browser exercised the real
  loader and Worker-to-broker path, not a guest. Root, JS, two IranSans fonts
  and all nine legacy files returned200 over HTTP2; favicon204 and `/launch`
  returned the expected410. The UI showed fresh-console guidance, cleared the
  fragment, and did not attempt a guest WebSocket.
- Independent redacted broker status logging confirmed410 at07:12:25 UTC for
  that browser path. A separate signed synthetic check at07:08:03 also gave410,
  verifying the newly activated web handler rather than only CLI code.

Worker tests were run from the isolated checkout with
`node --test scripts/arvan-console-worker/worker.test.mjs scripts/arvan-console-worker/client.test.mjs`.
Both changed JS files also passed `node --check`; `git diff --check` passed.

The independent PHP invocation, from the isolated `website/` directory, was:

```powershell
& 'C:\Users\admin\.codex\.tmp\server-menu-php84\php.exe' -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client -r 'function curl_exec($handle) { throw new RuntimeException("Network execution prohibited in isolated review"); } function curl_multi_exec($handle, &$running) { throw new RuntimeException("Network execution prohibited in isolated review"); } require "vendor/bin/phpunit";' -- --configuration phpunit.xml --do-not-cache-result tests/Feature/ArvanConsoleBrokerTest.php
```

Screenshots are private local evidence outside Git:
`output/arvan-console-20261020/{chrome-tokenfree,iab-tokenfree,iab-invalid-ticket,production-verified,worker-deployed}.jpg`.
No real ticket, cookie, token, raw customer logs or guest password is included
in this record.

These checks **do not prove valid grant issuance, live guest image, keyboard,
mouse, reconnect or Windows password delivery**. There is no eligible active
owner VM for those final checks. Do not reopen public console access on the
strength of synthetic tests or HTTP200 alone.

## Network findings, persistence and limitations

An initial fresh in-app navigation still failed before HTML with
`ERR_CONNECTION_RESET`; later fresh navigations succeeded. The cross-tab race
does not explain every pre-HTML network reset, and permanent resolution of the
intermittent transport problem is not claimed.

The read-only20October network check supersedes the18October snapshot:
the system proxy is enabled on `127.0.0.1:10808`, with xray listening; no PAC,
TUN or split default-route override was found, and WinHTTP is direct.
Verified-TLS direct/proxied HTTP1 and HTTP2 reads of the public root all
returned200, approximately0.37–0.75 seconds. This does not establish the cause
of an earlier browser failure or the customer's geographic egress.

No DNS, proxy, route, NAT/firewall, portal Worker, periodic country-routing or
port-forward agent source/state, cron or other customer infrastructure changed.
Persistence is limited to the two PHP files, owner-pilot setting/audit, and the
dedicated Worker's published code. No paid plan or new resource was purchased.
Automatic Windows password setup/delivery and the initial-finalization retry
gap remain unresolved; see the18October owner/Windows records. No password fix
or guest-image acceptance is implied by this console release.

## Rollback and safe continuation

For PHP, first turn pilot0 while keeping public0 and all sales settings intact.
Restore only these two verified before snapshots if the current hashes still
match this release; concurrent drift is a stop condition. Activate through the
approved mechanism, noting the shared-cache effect. Do not replace the whole
application or run migrations/seeders. Live rollback was not exercised.

For the Worker, the previous code version is
`c2ab2dc2-eb0e-41ff-8d2e-534c232e8dad`; use the retained code/settings backup and
the same fixed-target, least-privilege process, preserving bindings and route.
That rollback reintroduces the reproduced race and is not the normal remedy.
Do not restore public console access or modify DNS as a shortcut.

Next acceptance needs a specifically identified active Arvan service belonging
to the approved owner, or a newly priced and expressly approved single test.
Then verify its ownership/ref, valid grant, live image, mouse/keyboard,
reconnect and cross-tab isolation without affecting other services. Until
that succeeds, public console stays closed and sales stay open.
