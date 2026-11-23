# Console and n8n workspace experience

Source base: `fc744ea4db7cb211da6daa8985cd4ec6e10d07ff`, `develop`.

The owner requested a full-width customer console, managed n8n under Services,
a country-first purchase flow, account security inside Profile, and a main-site
destination for the console header logo. This changes presentation and the
explicit owner password-reveal response, not prices or provisioning policy.

## Experience

- The account shell uses available width, a bounded scrollable desktop sidebar,
  the existing accessible mobile drawer, and a compact footer. Purchase and All
  leave the sidebar; their existing routes remain reachable. The account logo
  opens the main ServerNet site and preserves the selected language.
- Services provides n8n, AI and Identity API workspace entry points. Only the
  managed-application marker moves n8n into the Services presentation bucket.
  Its cloud identity, lifecycle, payments and ordinary VPS categorization stay
  intact. Acquired n8n cards show country flags, delivery state, HTTPS address,
  owner email, billing period and next renewal where applicable.
- Public and authenticated n8n pages share a progressive configurator: country,
  available package, then period and service name, with a server-priced summary.
  Links and forms work without JavaScript. Guest choices survive the existing
  sign-in redirect; account checkout still requires explicit submission.
  Country changes clear the old package. Turkish packages never enable hourly
  billing. The normal backend validates and recomputes every order.
- Profile and verification gain a workspace hero and responsive information
  cards. Security is the Profile security tab (`?tab=security`); legacy security
  deep links and all existing OTP, TOTP, IP and token actions remain supported.
- Motion is confined to decorative hero elements and small transitions; reduced
  motion is respected. The existing fa/en/tr interface is fully translated.

## Private access

Normal GET never decrypts the initial n8n password. The existing authenticated,
CSRF-protected owner-only POST can now return JSON with `no-store, private`.
The card requests it only after an explicit reveal, renders it using
`textContent`, and masks it on page exit. Copy is a separate user action.
The existing single-use flash redirect remains the non-JavaScript fallback.
Cancelled services and another customer's service cannot reveal credentials.

## Validation

241 isolated PHP tests / 1499 assertions passed, including managed n8n, account
sections under test fixtures, profile/navigation, security/OTP/TOTP, API workspace,
provisioning, delivery, Proxmox and VPS.TC renewal/lifecycle behavior. Four
configurator JavaScript tests and three guarded release/rollback tests passed.
Both new JavaScript assets passed syntax checks. Five merged production-overlay
PHP candidates passed syntax checks separately.

A separate section/header regression run had three existing failures. All three
were reproduced unchanged on the clean source base: the crypto partial contains
an inline style block; two impersonation tests treat the homepage's harmless
`imp-bar` text as an active impersonation bar. They are not reported as passing.
GitHub matrix results and live browser acceptance will be recorded after release.

## Limited release and rollback

The manifest lists 33 exact destinations, including both independent asset
copies. Fifteen relevant views are compiled in both observed account-owned FPM
pools. Source and destination hashes, a private backup and the four existing
release locks protect installation. Only the n8n public page cache keys and
the affected scripts/compiled views are invalidated.

The reviewed production overlay preserves existing Arvan traffic context,
deployed wallet display behavior, panel-head stack and birthday visibility,
public CSS behavior, Iranian menu visibility and full-width console header.
The site layout's deployed CRLF convention is preserved. These differences are
not silently replaced by unrelated newer repository behavior.

No schema change, migration, provider purchase, VM creation, real checkout,
customer debit or security-setting change is part of acceptance. The Iranian
purchase identity gate and existing delivery automation remain in place.
Rollback restores only exact prior manifest bytes and refuses newer operator
work; it does not revert transactions or alter existing customer VMs.

Status: implementation and isolated validation complete; production installation
and browser acceptance are pending.
