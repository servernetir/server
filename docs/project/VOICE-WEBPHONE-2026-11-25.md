# ServerNet Voice native browser phone — 25 November 2026

Base: `19481868e6c505d341622f25a211b0925c757a24` on fresh `develop`.
Scope: `infrastructure/voice/webphone`, its isolated CI workflow and this map.
Runtime installation, registration, echo transport and owner confirmation of
hearing the returned echo were verified on 25 November. An outgoing PSTN test
reached an answered announcement; a mobile conversation remains unverified.
The owner confirmed the inbound test reached IVR without connecting extension
110, and local SIP delivery was not observed. Details are in the package README.

The package supplies a native-authenticated FusionPBX 5.6 browser phone at
`/app/servernet_voice/index.php`, with Persian, English and Turkish labels. It
uses a self-hosted pinned official JsSIP 3.10.0 browser artifact, its MIT license,
SHA256, browser SRI and a bounded re-fetch/verify helper. It loads no runtime CDN.

Existing PBX `extension_view` and `extension_password` permissions are required.
Active extensions must also be explicitly linked to the active current user and
tenant; administrator status supplies no ownership bypass. GET renders metadata
only. Native body CSRF plus exact same-origin POST gates credential delivery on an
explicit Connect action. The database query rechecks current ownership and active
states. Credential responses are no-store; an audit records only three UUIDs.

Credentials remain in tab memory, with no password storage or auto-connect.
Disconnect, transport failure and page lifecycle events stop the UA and tracks.
Microphone acquisition requires Call or Answer. The client supports one call,
incoming answer/reject, hangup, mute and DTMF. Codec preference uses the standard
browser API when available; SDP is not rewritten. Current server negotiation is
PCMU. Both call directions use only the owned UDP 3478 STUN endpoint at
`stun:pbx.servernet.cloud:3478`; the owned server and network are separately
installed and verified by the operator. No third-party ICE service is used.

The PHP fixture runs actual API SQL against an in-memory synthetic database,
using fake native authentication/token classes. It tests tenant/user boundaries,
disabled state, method/origin/CSRF rejection, secret-free GET, exception redaction
and metadata-only audit. The Node suite uses fake browser and JsSIP objects;
neither suite opens a SIP socket, requests real microphone access or calls PSTN.
Exact results belong with the final source SHA and CI/test receipt.

Pre-commit verification: the frozen `bootstrap.php`, `api.php`, `index.php` and
fixture harness were staged in a private isolated directory and run with PHP8.4
on 25 November. All four PHP syntax checks passed; the synthetic SQL fixture ran
30 cases with zero failures. No Laravel/PBX configuration, production database or
live credentials were used. The committed fake browser/JsSIP suite passed all 17
tests on Node24.22.3 with zero failures or skips. Local Node syntax, vendor/license SHA256 checks,
shell syntax and whitespace checks also passed. Live registration and media are
outside these test claims.

Deployment copies only the seven runtime/license files listed in the package
README into a new app directory, after PHP lint and integrity checks. No native
FusionPBX core, database schema, gateway, dialplan, console billing, commercial
module, menu permission or background job is changed by this package. Root
installation and media/network configuration remain separate scoped operations.

Rollback disconnects browser sessions and removes this dedicated app directory.
It changes no provisioned PBX records. The previous cloud-phone marketing and
Android preview publication remains documented in
[the earlier voice web report](CLOUD-PHONE-VOICE-WEB-2026-11-02.md).

Deployment integration note: a 5.6 vhost can serve static files from `public/` while
application PHP executes outside it. A dedicated Nginx prefix example now mounts
only this app, preserves generic PHP regex execution and documents the optional
trailing-slash `/phone/` alias. Asset acceptance requires body SHA256 and MIME
checks: HTTP200 can otherwise be a login-page fallback. The mount snippet keeps
the existing PHP-FPM handler and adds no credential or database operations.
The friendly API and index require exact rewrite locations because the generic
PHP regex takes precedence over a plain alias prefix. Those exact locations
restart selection at the canonical app PHP paths without suppressing PHP-FPM.

Proxy transport integration: the browser connects to verified WSS at the public
Nginx endpoint, while Nginx forwards to FreeSWITCH over loopback WS. The client
therefore sets the supported `socket.via_transport = 'WS'` before UA construction.
This adjusts only the SIP Via header; it keeps the public WSS URL and browser TLS.
The fake socket starts with WSS and the Connect test asserts WS is passed to the
UA. The runtime manifest changes only for this reviewed `phone.js` update. No TLS
backend, credential handling or PHP source change is included.

Owned STUN integration: outgoing calls and incoming answers receive a fresh peer
configuration with exactly the fixed owned endpoint. API data cannot override
the ICE servers. The client keeps normal ICE/DTLS/SDP and does not weaken the
FreeSWITCH candidate ACL. SIP 488 and media/ICE failures map to a limited translated
audio/network message without raw SIP, SDP or credential diagnostics. The updated
Node suite passed 22 tests locally, including both call directions, injected ICE
URL rejection, translated errors and cleanup after browser media exceptions.

Production follow-through installed the owned STUN-only coturn 4.19.2 service and
the hashed client. FreeSWITCH 1.11.4 required a tenant-scoped native
`include_external_ip=true` setting before answering the proxied WebSocket leg.
An answered echo showed public ICE, DTLS READY and bidirectional SRTP counters,
then normal clearing; no payload was captured. The owner subsequently confirmed
hearing the echo. See
[the deployment note](../../infrastructure/voice/webphone/README.md#production-deployment--25-november-2026)
for the compatible service flags, build hashes and native media variable.

At 19:13:35 UTC an owner-authorized test to the owner's mobile (redacted) received
183 then 200/answered at 19:13:37 and reached DTLS READY. The owner heard an
announcement reporting the requested number disconnected, then hung up at
19:13:40 with normal clearing. A mobile conversation and outgoing-line permission
status remain unverified; an answered SIP leg does not establish either.
The 19:17:17–19:17:28 inbound test reached the existing ServerNet webhook as
`CallIncomingStarted`/`CallIncomingEnded`, with IVR entry/final handler,
`MenuName=منوی اصلی` and `Result=false`. The owner confirmed reaching IVR without
connecting extension 110. The gateway remained registered/UP with zero inbound
calls; delivery to local extension 1001 was not observed. The provider's inbound
call flow needs review, with its exact cause still unconfirmed.
The provider portal's hook list remained Loading with an undefined account;
the available reports/callback API does not expose call-flow configuration.
No account-UI evidence of an outgoing-line restriction was obtained.
