# Arvan owner-only console and initial credential retry —18October2026

Publication update: the prepared two-file console package was installed and
activated on20October, with public0/pilot1 and sales preserved. The dedicated
Worker's cross-tab race was also fixed and published. See the
[20October release and acceptance limits](ARVAN-CONSOLE-OWNER-RELEASE-2026-10-20.md).
The pending-publication passages below describe the original18October checkpoint,
not current production. Windows automatic password delivery remains unresolved.

## Requested boundary and live restriction

The owner superseded the earlier public-console approval: keep sales open,
restrict Arvan web consoles to Jafar Ebrahimi's own account until acceptance,
repair loading/credential delivery, and request a fresh quote approval before
any paid test. Do not change DNS or the portal Worker, contact Arvan support,
or create/delete a guest as part of this release.

At12:34:37UTC, a read-only production check confirmed customer1/codeSN-457136
is active, public console1, owner-pilot unset and the old501 window0. At
12:35:58UTC, `Setting::put('arvan_console_worker_enabled', '0')` closed new public
console access and invalidated the settings cache. An audit event
`arvan_console_public_restricted` records the owner request and before/after
values, without credentials. Hourly sales remains1 and the Windows-image
allowlist SHA256 remains
`cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845`.

The same live check found **zero active Arvan services owned by customer1**.
Historical501/516 must not be recreated or replaced with another customer's
guest for acceptance. Existing service/invoice records and all guests remain
untouched. No chargeable test was run in this change.

## Code prepared for owner-only access

`arvan_console_owner_pilot_enabled` is a separate, default-off persistent
setting. With public0/pilot1 and a valid existing broker key, the policy checks
fresh persisted customer ID1/codeSN-457136/active status and the service's
current customer1 ownership/active status. Normal authentication, eligibility,
rate limiting, short-lived single-use tickets and HMAC remain in place.
Display names, staff login and cached relationships do not grant access.
The obsolete hard-coded deleted-service501 time-window bypass is removed.

The controller now captures customer ID, instance ID and exact provider ref
**before** obtaining a provider console URL. The broker reloads both records
and matches all three before issuing a ticket. Replacement, same-region ref
change, reassignment and closure during the provider request fail closed.
Missing snapshots return403, including a mixed-version deployment; they do not
silently bind an old guest URL to a replacement instance.

Restriction is enforced at issue, redeem and claim, not only by hiding a button.
An already-open service page may retain its old button until reload, but its
new request is denied. Already-connected WebSockets are not actively terminated
by this broker policy; no forced session termination is claimed. A request
already in flight can also retain its request-local settings snapshot.

Both console PHP files must be installed and activated before enabling pilot1.
Keep public0 throughout. Do not re-enable public access as a rollback shortcut.

## Proven local Windows delivery defect

A regression test reproduced this sequence: a valid initial create password is
encrypted and saved on the instance, final service save fails, and retry leaves
`service.password` empty despite retaining the accepted VM identity. This gap
remains unresolved. An attempted repair from the retained encrypted receipt was
withdrawn after independent review identified missing guest-generation proof.

The narrowed defensive patch permits only a nonempty password argument supplied
by the **current create call**, equal to the fresh locked instance credential,
to populate an empty service credential for unfinished initial delivery with
matching ownership/ref/image. Existing raw service ciphertext is preserved even
if unreadable. The durable instance/ref save remains outside finalization so a
rollback cannot discard the only initial receipt. No provider GET recovery,
password reset, guest mutation or schema change is introduced. This defensive
patch is Git-only, excluded from the two-file production console package.

The reported same-image rebuild flaw was reproduced through the real controller
route with a mocked provider returning no password: the retained secret and all
timestamp/ref guards survived. The new negative test failed before correction.
Unsafe retry copying was removed; final combined suites pass52 tests/253
assertions and independent review accepted the narrowed Git change. Retry now
deliberately leaves an empty service password empty. The pre-existing retained
instance credential/reveal behavior after rebuild is not fixed here. Do not
promote a potentially stale password merely to fill an empty field.

This is not a solution for an image/provider that supplies no initial password.
Untouched Windows first boot, automatic guest configuration and real RDP remain
**unaccepted**. The previous516 diagnostic, lost initial response, empty later
GET and forced initial console password change do not establish whether an image
agent is present. See the earlier Windows diagnostic/bootstrap records; another
paid experiment needs a fresh quote and approval, with no support contact.

## Browser and transport evidence

The dedicated Worker remains versionc2ab2dc2-eb0e-41ff-8d2e-534c232e8dad from
the earlier scoped loader release. At approximately12:43UTC a fresh token-free
IAB navigation loaded the actual branded shell and displayed the expected
instruction to open a fresh console from the service page. Captured browser
warning/error logs were empty. The screenshot is outside Git:
`output/arvan-console-loading-20261018/owner-tokenfree-root.png`.
No ticket, broker grant, provider session or guest control was exercised.

This succeeds after earlier pre-HTML connection resets; it does not prove those
intermittent resets are permanently fixed. Fresh direct HTTPS/HTTP2 reads worked,
while an explicit existing loopback proxy timed out. The system proxy was off,
no TUN/default-route override was present, and no network setting was changed.
An additional public HTTP200 response contained Cloudflare Insights beacon
injection, but no challenge or Rocket Loader. That difference is not evidence
that the beacon caused a pre-HTML reset; no zone analytics/security was changed.

## Publication gate, persistence and rollback

The public0 setting is already live. The new owner-pilot PHP policy and local
credential safeguards are still **prepared, not activated** at this record's
initial checkpoint. The standard authenticated activation path also clears
shared application cache, which can invalidate unused console tickets. Fresh
owner approval for that brief effect is pending; no shared-cache activation has
been performed for this change yet.

Live broker content differs from the Git baseline, so whole-file replacement is
not allowed. Publication must preserve unrelated drift with exact contextual
patches, private before/after snapshots, expected hashes, lint and a release lock.
The two intended production files are ArvanConsoleBroker and
CloudServerController; CloudProvisioner is intentionally excluded from this release.
Do not run a broad deployment,
seeder/migration, credential repair over existing customers or provider call.

At12:51:56UTC the guarded helper prepared both files successfully in
`/home/servernetcloud/servernet_app/storage/app/arvan-owner-console-20261018-62630c2cff1e`.
Both PHP stages passed lint; manifest reports `prepared:true, installed:false`.
The live broker drift was inspected: only single-line versus multiline
formatting of the existing redacted diagnostics logger differs from Git. That
live formatting is preserved. A first invocation used a misread directory digit
and returned input-file-not-found; a read-only listing corrected it. The real
prepare succeeded once; no `--apply`, web activation or pilot setting change was
executed. Never rerun prepare over its existing backups/manifest.

Payload SHA256:
`b264250d3ae3ca49416e8337d426dd1e6bee523a84cb8830efab359cc76982e1`.
Built helper SHA256:
`379fb733944dca0eff284fd65c8727f12102430c099db5c5d67c7c4306f4eec3`.
The deterministic builder has3 offline tests; production prepare is verified,
but live installation/rollback has not been exercised. At12:52:43UTC the final
readback again confirmed public0, hourly1 and the unchanged Windows allowlist
hash. Owner-pilot remains unset/off. Existing authenticated customer sessions
were not removed and the shared cache was not flushed.

Rollback must first set pilot0 while keeping public0 and sales unchanged, then
restore only this release's verified files if their installed hashes still match.
Activate using the approved existing mechanism. A concurrent modification is a
stop condition, not permission to overwrite it. No DNS, route, NAT/firewall,
proxy, periodic routing-agent source, Worker portal, financial or guest-state
rollback is applicable because none was changed.
