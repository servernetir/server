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
transport failure. There is no password storage, auto-connect or microphone
request on page load/Connect. The user must select Call or Answer before microphone
access. One call is allowed at a time. Extra incoming calls receive Busy. Incoming
calls require this tab to remain open and registered. Microphone and actual call
acceptance remain separate owner actions.

## Prerequisites and installation review

- Existing FusionPBX 5.6/PHP8.4 installation, native authentication and PostgreSQL.
- TLS for `https://pbx.servernet.cloud`; reverse proxy
  `wss://pbx.servernet.cloud/sip/` to the intended FreeSWITCH SIP WebSocket profile.
- The authenticated domain name becomes the SIP realm; it cannot be supplied in
  an API request. Fixed public origin/WSS constants must be deliberately reviewed
  before using this package on another host.
- An active user-extension link, a strong extension password and directory XML.
- Browser audio codec negotiated with the FreeSWITCH profile; current deployment
  supports PCMU. ICE/media reachability and an answered real call need separate
  validation. No STUN/TURN service or third-party media dependency is configured.

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
