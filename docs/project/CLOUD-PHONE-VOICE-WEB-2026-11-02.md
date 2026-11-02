# Cloud phone page and Android download — 2 November 2026

Base: `c7f3ccc` on develop. Scope: public `/solutions/cloud-phone` in fa/en/tr.
The owner explicitly requested publication of the existing Android review APK,
creative screenshot mockups, downloadable app and professional service copy.

The page adds two animated CSS device mockups using actual native screenshots
with fictional data. It describes employee extensions, internal and external
calls, IVR, queues, reporting and SIP Trunk integration subject to line/operator
compatibility and the selected plan. Unsupported unlimited/free claims were
removed. The Android download is clearly a preview requiring individual SIP
activation and an authorized outbound line; incoming calls require an active
app connection. ServerNet account provisioning and push wake-up are not claimed.

The app is unchanged from its tested review build in PR213, source commit
`5de56f11361690b313cc5ac10aa9f2e16f8224b9`. The page links its GPL source.
Public artifact: `/downloads/servernet-voice/ServerNet-Voice-0.1.0-preview.apk`.
Size: 31,338,219 bytes; SHA256:
`1549fe39c3270beecdb775fd61e0dbc2e4c4892f727c01c15cb872efcbf17eda`.
It is a development-key-signed Android9+ ARM preview. No real SIP call or device
acceptance is newly claimed by publishing this download.

SEO: localized titles/descriptions and content, native image dimensions/alts,
FAQ and SoftwareApplication structured data without invented prices or ratings.
Existing global canonical/hreflang/layout are preserved. The shared solution
view only adds the app partial and features anchor for cloud-phone.

Validation: 14 local static deployment-scope checks, Node syntax and diff checks
passed. The scoped workflow performs real PHP8.4/Blade renders in fa/en/tr,
16 Node/jsdom checks and an isolated native PHP deployment rehearsal. Exact CI
result and source SHA belong in the review PR, not inferred from this report.

Production scope is exactly14destinations: three PHP/Blade sources, one CSS and
four screenshots at both public roots, and one public APK. The fixed release
helper and sources are staged privately outside webroot at
`/home/servernetcloud/cloud-phone-voice-release-20261102`. Its transient launcher
uses existing web/admin authentication and normal CSRF; no credential or command
is accepted from the page. Before/after hashes, file mode/UID and protected
backend/layout/routes are guarded, with three shared release locks and private
backups. Config cache blocks apply. Activation is limited to this config and
compiled views and the six localized cloud-phone page-cache keys. No migration,
cron, provider call, financial setting or app entitlement is changed.

At preparation, Fileman reads matched the current solution source and all
configuration entries outside cloud-phone. The live layout differs from Git
and is deliberately protected, not replaced. Raw server hashes from the
read-only authenticated inventory, not converted Fileman text, are the release
preconditions. Browser/HTTP acceptance and exact final deployment state are
recorded after installation. No deployed state is implied by this preparation.

Rollback uses the same release helper with known-hash guards and its private
backups. It restores old source/compiled views before removing new assets and
refuses to overwrite newer or unrecognized files. Closing/reverting the web PR
alone does not remove already installed files.
