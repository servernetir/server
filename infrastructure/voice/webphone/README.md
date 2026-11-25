# ServerNet Voice browser phone

Native FusionPBX 5.6 application, installed as `/app/servernet_voice/`. It uses the
existing PBX session and requires both `extension_view` and `extension_password`.
It does not add permissions, change billing or connect the ServerNet console.

The page lists only active, numeric, nonvirtual extensions explicitly linked to
the current user in the current active tenant. Even administrators receive no
ownership bypass. User, domain, extension and ownership-link state are checked
again for every Connect request. SIP credentials are returned only by an explicit
same-origin POST with a native body CSRF token. GET HTML contains extension
metadata and a CSRF token; it contains no SIP password. Responses use no-store,
same-origin framing, a fixed WSS connection policy and no runtime external assets.
Successful credential delivery is audited using only user/domain/extension UUIDs.

The browser holds SIP credentials in memory until Disconnect, page unload or
a terminal registration failure. There is no password storage, initial auto-connect or microphone
request on page load/Connect. The user must select Call or Answer before microphone
access. One call is allowed at a time. Extra incoming calls receive Busy. Incoming
calls require this tab to remain open and registered. Microphone and actual call
acceptance remain separate owner actions.

### Connection recovery — 26 November 2026

Transient transport loss preserves the current JsSIP UA and its native 2–30s
reconnection backoff. Both `disconnected` and `unregistered` suspend calling and
display a translated reconnecting state without stopping the UA. Transport loss
ends any current call and closes microphone tracks; recovery never redials,
answers or requests the microphone. Explicit Disconnect and page lifecycle
cleanup cancel recovery, stop the UA and clear its in-memory password.

Registration requests use a 600s lifetime, refresh every 120s while registered,
and refresh when the visible tab or network returns. Temporary 408/5xx/transport
registration failures retry with a single 10/20/30s backoff timer. Authentication
and unknown failures stop and clear credentials. No credential POST is repeated
automatically; the original native permissions/CSRF checks are unchanged. The
initial connection attempt retains its 25s deadline. A browser suspended by its
OS can still miss incoming calls until it resumes.

The 26-case Node suite verifies recovery, resumed incoming-call UI, media cleanup,
retry deduplication, cancellation, stale-UA events and terminal auth failures.
A public, unauthenticated TLS/WSS idle probe remained open for 80s without frames;
it did not reproduce a 60s proxy timeout. No proxy or SIP profile change was made
for this fix. Live acceptance requires reloading the phone and reconnecting.

Provider screenshots distinguish IVR key **101** from SIP internal **110**. The
owner's key-101 test rang the colleague's mobile, while the browser did not ring.
Provider delivery to the colleague's SIP account still needs review; this is
separate from recovery of the browser's registration to this PBX.

## Prerequisites and installation review

- Existing FusionPBX 5.6/PHP8.4 installation, native authentication and PostgreSQL.
- TLS for `https://pbx.servernet.cloud`; reverse proxy
  `wss://pbx.servernet.cloud/sip/` to the intended FreeSWITCH SIP WebSocket profile.
  In the current proxy contract, Nginx terminates browser TLS and forwards to
  FreeSWITCH using plain WS on loopback port 5066. The client sets the supported
  `socket.via_transport = 'WS'` before constructing its UA so the SIP Via header
  matches that backend hop. The browser URL remains WSS with verified HTTPS/TLS.
  Review this setting if the backend changes to native WSS.
- The authenticated domain name becomes the SIP realm; it cannot be supplied in
  an API request. Fixed public origin/WSS constants must be deliberately reviewed
  before using this package on another host.
- An active user-extension link, a strong extension password and directory XML.
- Browser audio codec negotiated with the FreeSWITCH profile; current deployment
  supports PCMU. ICE/media reachability and an answered real call need separate
  validation. Both call directions use the single owned STUN endpoint
  `stun:pbx.servernet.cloud:3478` over UDP 3478. The operator must install and verify
  that listener and its network reachability before deploying this client update.
  No third-party STUN or TURN fallback is configured.

The STUN address is a fixed client constant alongside the fixed WSS address;
API responses, query parameters and page data cannot select another ICE server.
Each outgoing call and incoming answer receives a fresh peer-connection config.
STUN lets a browser discover a public address mapping when its host candidates
are private; it does not relay media. This change keeps normal ICE/DTLS/SDP and
the existing FreeSWITCH candidate ACL policy.

SIP 488 and media/ICE negotiation failures show a limited translated audio/network
message. Busy, rejected and unanswered calls retain their own messages. No SIP
body, SDP, token, credential or raw remote error is rendered or logged.

Review and copy **only** `bootstrap.php`, `api.php`, `index.php`, `phone.js`,
`phone.css`, `vendor/jssip-3.10.0.min.js` and `vendor/LICENSE.JsSIP` into a new
`app/servernet_voice` directory. Keep tests and build helpers outside the served
app. Do not overwrite native FusionPBX resources or include PBX configuration,
database exports, passwords or `.env` files in this repository. Verify hashes and
lint PHP before activation; the application adds no automatic menu or database
schema. The entry point is `/app/servernet_voice/index.php`.

Rollback removes this dedicated application directory after disconnecting its
browser sessions; it changes no PBX user, extension, gateway or dialplan data.

### FusionPBX 5.6 Nginx document root

Some 5.6 vhosts serve static files from `/var/www/fusionpbx/public`, while native
application PHP lives under `/var/www/fusionpbx/app`. In that layout the new app's
HTML can execute correctly while its CSS/JS return404 or a login-page fallback.
Review [the narrow server-block mount](deployment/nginx-servernet-voice.conf)
against the actual vhost. It sets a root only for `/app/servernet_voice/` and keeps
the existing generic PHP regex active. The existing PHP-FPM handler must resolve
these app scripts to `/var/www/fusionpbx`, not the public directory.

**Do not add `^~` to this app prefix or serve its PHP files as static content.**
The snippet is not a PHP handler and must not be installed without verifying the
existing PHP regex. Keep tests, manifests and deployment helpers outside the
served app; copy only the seven runtime/license files. Preserve the existing
server root, other apps and PHP handler.

The optional friendly alias redirects `/phone` to `/phone/`, then internally maps
the index, assets and API to this app. Keep the exact `/phone/api.php` and
`/phone/index.php` rewrite locations: Nginx selects its generic PHP regex before
a plain `/phone/` prefix, so the prefix rewrite alone cannot route these PHP
requests. Exact locations win and then restart normal app/PHP location selection.
Do not replace this with `^~`, which can bypass PHP execution. Rewriting bare `/phone` directly to the
index would make relative assets resolve from the site root. Alternatively use
a 302 redirect directly to `/app/servernet_voice/index.php` and omit the friendly
prefix. Run `nginx -t` before a controlled reload and validate actual response
bodies afterward:

- HTML renders through PHP; PHP source is never returned.
- CSS/JS/vendor responses have the expected MIME type and exact body SHA256 from
  `runtime.sha256`, at both canonical and friendly paths if the alias is enabled.
- Missing app assets return 404. A 200 login page is not a successful asset response.
- API requests return their intended JSON authorization/method status, not a
  front-controller login HTML fallback. GET never delivers a SIP password.

An explicit owner Connect can then verify registration without requesting a
microphone. An answered audio call remains a separate owner action.

## Production deployment — 25 November 2026

The owned STUN service uses coturn 4.19.2 in a dedicated hardened, unprivileged
`servernet-voice-stun` unit. Its configuration binds the voice VM on UDP 3478 with
`stun-only`, `no-auth`, `no-tcp`, `no-tls`, `cli=false`,
`software-attribute=false` and `log-file=stdout`. Legacy `no-dtls` and
`no-stun-backward-compatibility` flags are omitted for 4.19.2 compatibility;
RFC 5780 was off in the observed startup. Only public UDP 3478 is mapped, preserving
the source address and applying per-source and aggregate rate limits. External
STUN Binding succeeded; TURN Allocate was ignored. Public TCP, STUN-TLS and relay
ports are not exposed.

The installed binary SHA256 is
`e5542eeac00e4e2d8bf333ec83e5b8bd090f46ec2e14a9dbcb9e9220a071b01e`;
the source archive SHA256 is
`a1c895fc6fbf1a50c533437f8745de1ec145125233036a7e4391edbdc7d87022`.
The deployed browser client matches `phone.js` SHA256
`391bd09c7df96044d7085e9ba80178249f3332a5b87f5050da6637959c623af7`.

FreeSWITCH 1.11.4 also needed its native `include_external_ip=true` channel variable
before answering the loopback-proxied WebSocket leg, despite `ext-sip-ip` already
containing the public address. A tenant-scoped native dialplan
`servernet-browser-media` runs at order 5 with `continue=true`, conditioned on
`${domain_name}` matching `^pbx[.]servernet[.]cloud$`. It sets
`include_external_ip=true` inline and exports
`nolocal:include_external_ip=true` inline before media negotiation. Candidate ACL,
SIP profile and Nginx settings were not weakened or changed for this media fix.

An answered 7.8-second echo call advertised public ICE, reached DTLS READY and
showed bidirectional SRTP packet counters before NORMAL_CLEARING. No audio payload
or recording was captured. The owner subsequently confirmed hearing the returned
echo, completing the browser echo listening check.

### Trunk acceptance results — 25 November 2026, UTC

- One owner-authorized PSTN test from extension 1001 to the owner's mobile
  (number redacted) began at 19:13:35. The gateway returned 183 then 200/answered at
  19:13:37, with DTLS READY. The owner heard the announcement
  «شماره مورد نظر قطع میباشد» and hung up at 19:13:40; clearing was normal.
  A mobile conversation was not verified. SIP 200 and the heard announcement do
  not establish the account's outgoing-line permissions; those remain unverified.
- During the inbound test at 19:17:17–19:17:28, the existing ServerNet webhook
  received `CallIncomingStarted` and `CallIncomingEnded` for the owned DID.
  The report indicated `CallEntryType=Ivr`, `FinalHandler=IVR`,
  `MenuName=منوی اصلی` and `Result=false`. The owner confirmed reaching the IVR
  and that extension 110 did not connect. The registered gateway remained UP
  with zero inbound calls; delivery to local extension 1001 was not observed.
  The provider's inbound call flow needs review; its exact cause is unconfirmed.
- The provider portal's hook list remained in Loading with an undefined account,
  preventing inspection of line permissions and call flow. The available
  reports/callback API does not expose configuration. No account-UI evidence
  currently establishes an outgoing-line restriction.

## Vendored dependency

JsSIP **3.10.0**, official browser distribution:
`https://jssip.net/download/releases/jssip-3.10.0.min.js` (279285 bytes), MIT license
from the official `versatica/JsSIP` tag `3.10.0`. SHA256:
`06d3661907f43fd26ced77149bcbbd6d416a728dae46213a1c10ccee8a89698b`.
The official browser release index lists 3.10.0; npm source 3.10.1 exists but has
no corresponding browser artifact at that official download URL. This package
pins the actual published browser artifact and includes its license and SRI hash.
`bash vendor/fetch-verify.sh` reproduces the download and verifies the pinned
digest before replacing it. `cd vendor && sha256sum -c SHA256SUMS` works offline.

The public [WebSocketInterface setter](https://jssip.net/documentation/3.5.x/api/websocket/)
controls the outgoing SIP Via transport independently of the browser WebSocket
URL. JsSIP also documents the TLS-terminating proxy use case in its
[earlier `hack_via_ws` description](https://jssip.net/documentation/1.0.x/api/ua_configuration_parameters/#hack_via_ws).
This package uses the supported socket setter, not that obsolete UA option.

## Isolated verification

Use PHP8.4 with `pdo_sqlite` and `proc_open`:

```sh
php -l bootstrap.php
php -l api.php
php -l index.php
php -l tests/security.php
php tests/security.php
node --check phone.js
node --test tests/phone.test.cjs
```

The PHP harness copies this source into a temporary fake FusionPBX tree and runs
actual API queries against an in-memory SQLite fixture. Only PostgreSQL's regex
operator is adapted. Native session/token/permissions are fake; no real PBX
configuration, database, network or credentials are used. All UUIDs are synthetic.
It covers current ownership and tenant boundaries, revoked/disabled state,
CSRF/origin/method checks, generic failure responses and secret-free GET pages.
The JS harness uses fake browser/JsSIP objects and never opens a socket, acquires
a microphone or places a call. These tests do not prove live WebRTC media.
