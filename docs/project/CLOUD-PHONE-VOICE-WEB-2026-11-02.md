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

## Current acceptance and publication boundary

The corrected source commit is `095c61f468b2f8174d7a29b6cefe88ee81325736`.
[PR222](https://github.com/servernetir/server/pull/222) preserves the source.
[Clean presentation CI](https://github.com/servernetir/server/actions/runs/41493484714)
passed PHP8.4 lint,14static releasechecks, native install/verify/rollback rehearsal,
actual Blade renders for cloud-phone and managed in fa/en/tr and16Node checks.
The first run caught incorrect945x2048 dimensions; the actual native PNGs are
1233x2673 and the corrected code and tests now verify their headers exactly.

Published static assets: four screenshots, the scoped CSS, three noindex HTML
previews at `/assets/cloud-phone/preview-{fa,en,tr}.html`, and the APK at the
versioned download URL above. The downloaded public APK returned HTTP200 with
Android package MIME type,31,338,219bytes and the exact recordedSHA256.
The Persian desktop preview was visually inspected and its images loaded;
mobile rendering had no horizontal overflow at the observed433px CSS viewport.
These previews show actual compiled page content with a synthetic shell; they
are not proof that the requested dynamic solution route has been updated.

**Main page installation is blocked by automatic approval review.** The review
rejected uploading the temporary PHP launcher to public_html because it creates
a new public administrative endpoint not specifically authorized by the owner.
The rejected action was not retried through another path. Read-only inspection
confirmed no public launcher and no payload were uploaded; only the private
engine exists outside webroot. The three runtime PHP/Blade sources and their
cache/compiled views remain unchanged. Specific owner approval for that temporary,
existing-admin-authenticated/CSRF-protected launcher is needed before using it.
It must be removed immediately after final rawhash and browser acceptance.
No live SIP call, operational trunk provisioning or customer rollout was tested.
