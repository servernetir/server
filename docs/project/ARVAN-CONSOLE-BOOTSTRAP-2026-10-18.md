# Console loading follow-up — 18 October 2026

Base: `ec4d83eecc0ff3c1806b8b0185056965cd616677`, branch target `develop`.
Status: **locally tested code, not yet published to Cloudflare; no new guest acceptance**.

The owner requests a complete Windows-password/white-label-console repair,
approval before another paid test, continued sales/public consoles, no Arvan
support contact, and a push to develop. The prior diagnostic516 is deleted;
its budget does not authorize recreation. No customer guest is substituted.

## Demonstrated code defect and repair

The previous modern client statically imported the full RFB module graph before
its own error handling ran. The legacy document loaded nine parser-blocking
scripts before console.js. A failed/stalled dependency could prevent both ticket
handling and the visible failure message. A public page without a ticket also
loaded these unused dependencies. This is a reproducible code defect, **not proof
that it caused every historical pre-header browser failure**.

Only the dedicated Worker and its two test files change:

- The branded shell, inline styles and static guidance render without waiting
  for a stylesheet or VNC library. IranSans stays same-origin with swap fallback.
- One deferred bootstrap removes/validates the fragment before loading VNC.
  Missing/invalid tickets request neither VNC assets nor the broker.
- Both client loaders have a15-second dependency deadline. Legacy files start
  downloading in parallel, with `async=false` retaining insertion execution order.
  A serial implementation was rejected in review because nine healthy2-second
  downloads would exceed the deadline.
- Asset failure/timeout becomes a fixed, non-secret visible error. Late
  completion cannot redeem a ticket. Existing launch/RFB deadlines remain.
- HMAC, fixed upstream allowlist, owner checks, one-use grants, HttpOnly cookie,
  per-tab binding and rejection of credential prompts are unchanged.

The shell cannot repair a connection that never delivers HTML or bootstrap JS.
An actual guest image, mouse/key input, reconnect and owner-isolation check are
still required for a live end-to-end acceptance claim.

## Verification actually performed

At12:00:49UTC, read-only production PHP confirmed
`arvan_console_worker_enabled=1`, `arvan_hourly_sales_enabled=1`, and unchanged
Windows-ready allowlist SHA256
`cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845`.
No flag was written. Cloudflare dashboard showed active version
`122a4414-14f1-4aac-9e80-a736ca3c78ab`; Edit code remained Loading/Deploy disabled.
A new one-hour Individual Workers Editor credential for only servernet-vnc was
requested from the owner; none created in this checkpoint.

Local checks, Node22.17.0:

```text
node --test scripts/arvan-console-worker/worker.test.mjs scripts/arvan-console-worker/client.test.mjs
47 passed, 0 failed
node --check scripts/arvan-console-worker/worker.mjs
git diff --check
```

Independent review found no authorization regression. The synthetic tests cover
no-ticket behavior, failure/deadlines, late completions, parallel/ordered legacy
loads, single redemption, multi-tab binding, HMAC and upstream restrictions.
They do not prove real guest connectivity.

Additional real-browser **local-only** validation used loopback preview servers
with the actual patched responses, nine freshly downloaded public legacy source
files, and52 local modern module files. No real ticket, cookie, provider call,
broker or WebSocket was used. Both clients reached exactly one deliberately410
local launch fixture after assets loaded, displayed the failure message, removed
the synthetic fragment, and produced no captured JS warnings/errors. The
no-ticket legacy page also displayed guidance. This verifies actual browser
script-loading compatibility, not remote Windows control. Preview fonts were
intentionally unavailable; this was not a new font acceptance test.

Worker source SHA256:
`5c8dfbd825ff422dd65f331a76cfa3f5153518b15abd5957bbcd2a0f56d5ae31`.
Local screenshots are retained outside Git under the task output directory;
they are previews and must not be presented as live remote desktops.

## Transport observations, not a network change

The workstation still had an enabled loopback HTTP proxy and xray_tun adapter.
An initial default-resolver Node probe timed out at9seconds without enough
phase evidence to identify its cause. Later explicit-address public-root probes
(normal TLS verification and hostname retained) returned HTTP200 on HTTP1 and
HTTP2 in128–307ms, both default and Ethernet-bound. Ordinary curl HTTP1 returned
200 in269ms and the existing proxy in253ms. Later proxy-connect failure was
observed, while direct-address public legacy-asset reads all succeeded in
0.83–1.25s. These mixed observations do not establish global health or a single
root cause for the browser path.

The token-free IAB navigation did not complete within20seconds. An old error
document could not be observed under browser URL policy; it was not accessed
through an alternate mechanism. No authenticated console ticket was replayed.
Do not equate these public network checks with an accepted console session.

No persistent DNS/route/proxy/adapter, NAT/firewall, portal Worker, broker,
country-routing/port-forward agent state, sales, invoice or VM/disk was changed.
No paid resource was created or removed, and no support message was sent.

## Windows password: remaining technical gate

See [the completed diagnostic](ARVAN-WINDOWS-DIAGNOSTIC-2026-10-18.md) and
[bootstrap plan](ARVAN-WINDOWS-BOOTSTRAP-2026-10-18.md). The lost initial POST
response and later empty GET password do not prove absence of an image agent.
The current create payload never sent `init_script`; that hypothesis remains
untested. Automatic password setup/delivery is **not fixed or accepted** here.

The reconciliation audit also found that the existing-resource mapping drops
an optional provider password. Simply adding it to status/adoption is unsafe:
manual console changes do not necessarily update local credential columns, and
the code has no immutable credential-generation marker for a same-image rebuild.
Status is customer-cacheable, and finalization can prefer an incoming nonempty
password over a newer stored credential. No blanket GET recovery or overwrite
was introduced. A future initial-create-only recovery must bind the exact
attempt/owner/ref/image generation, exclude published or previously credentialed
services, recheck under locks, and retain accepted VM identity even when credential
recovery fails. It would still not fix an empty GET or configure the guest.

A separate, inert [marker generator](../../scripts/arvan-windows-bootstrap/MARKER-PAYLOAD-README.md)
is prepared for a future separately approved disposable guest. It produces only
a fixed `#ps1_sysnative` script with a validated non-secret generation marker;
no password, callback, network, provisioning or API execution is included.
The generated guest text was never executed.58 offline assertions passed in
PowerShell5.1 and7.6.5. It is not wired into production or any old create helper.
Before another paid test, recheck the full quote and obtain a new ceiling.

## Publication and rollback

Git and production are separate. Until a scoped credential/editor is available,
leave the deployed Worker unchanged. On later authorization, inspect/back up
its exact source/settings, refuse concurrent drift, upload only servernet-vnc,
preserve all existing bindings and route, then verify exact source/version.
Do not edit DNS, the portal Worker or sales switches. Public HTML/asset tests
must precede any authorized guest acceptance test.

If publication regresses loading, restore only the recorded previous dedicated
Worker version122a4414, preserving bindings/routes. Do not roll back public
console activation or password guards. The marker preparation has no production
persistence or operational rollback; a scoped Git revert removes only those
new preparation files. Stop the loopback preview processes after verification.
