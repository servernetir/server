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

## Verified production publication

The owner explicitly approved the temporary existing-admin/CSRF-protected PHP
launcher after the initial automatic review rejection. Publication then continued
through the same reviewed scope; no alternative path bypassed the rejection.

Deployed source: `7cfc8927e54f345597a059279295e8bb69139ede`.
[PR222](https://github.com/servernetir/server/pull/222) records the final Git head.
[Final native/Blade CI](https://github.com/servernetir/server/actions/runs/41495846845)
passed PHP8.4 lint,18static deployment checks, native install/verify/rollback and
mode-drift rehearsals, actual fa/en/tr Blade renders and16Node assertions. The
earlier image-dimension failure was corrected to the real PNG1233x2673 headers.
The helper also preserves existing0755 compiled views, uses0644 for new compiled
views and checks immediate source/compiled mode drift during apply and rollback.

Authenticated live preview, apply and verify all succeeded:14exact destinations,
two compiled views and8protected backend/layout/routes/shared assets unchanged.
Account UID1020, uncached configuration and raw before-hashes were confirmed.
The existing solution compiled view was0755 and stayed0755; the new partial is0644.
Only the solutions config/two compiled views and six localized cloud-phone guest
page keys were activated. The live layout, callbacks, database, finances, cron and
provisioning were not changed; supplier API calls were zero.

Private stage/backups/receipt:
`/home/servernetcloud/cloud-phone-voice-release-20261102` (directory0700).
Payload SHA256: `a0481bfdea3b8d9ec84a5529dbe5bbb6676f5480f131cf637dca92b4c27caba7`.
Engine SHA256: `51db73a4fcb8c7b09e952628fbf41fdf2fa54e57ea68efadcc9a610507ee51c2`.
Launcher raw self-hash was checked before preview:
`bceba3f480e05e6df9505de1ba46d3753df7dd70ea44d844ed7d94efbb5a74c5`.
Fileman content reads strip22bytes from a meta literal and were not used as raw
launcher hash evidence. The protected layout's observed SHA256 remains
`bf2de51af9aacdff3dfe1b9d87c2e47c85beedf66b30f1b5008d0f30f23f532c`.

Public acceptance: fa/en/tr each returned HTTP200 both with a cache-bypass query
and as a clean guest URL. Each had one H1, correct canonical and hreflang,8visible
FAQ/schema entries, valid SoftwareApplication0.1.0-preview and an active download.
`/up` and the neighboring managed-service page returned200; the latter did not
inherit app assets. Persian desktop and all three mobile layouts were inspected,
with loaded images and no horizontal overflow at the observed433px CSS viewport.
The browser viewport was restored afterwards. The public APK returned Android
package MIME,31,338,219bytes and its recorded digest; final HEAD size also matched.

Cleanup: the approved launcher was retired outside webroot into the private stage
and set0600. Both its old public .php URL and the temporary extensionless name
created by Fileman's relative move resolution returned404. No public launcher
remains. Three noindex static previews remain for review; the requested dynamic
`/solutions/cloud-phone` page is now the published version. Rollback uses the
same guarded private receipt/backups and requires temporarily restoring an
approved authenticated launcher; reverting Git alone does not undo live files.

No real SIP call, operational trunk provisioning or customer rollout was tested.
