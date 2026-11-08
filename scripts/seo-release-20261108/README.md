# Scoped SEO source release, 8 November 2026

This private installer applies only the reviewed runtime deltas from commits
`9b31f79`, `00d9c94b`, `c5b42391`, `2fd0c216` and `f1ef751c`. The payload covers
the existing 33-file SEO inventory plus the exact Urmia legacy route alias.
Existing files receive coalesced, unique old/new hunks; no complete target
snapshot replaces an existing shared file. The route payload adds only the
approved alias, preserving other live routes and sitemap changes.

`build_payload.py` operates offline against fixed full Git hashes. It replays
only those commit deltas, then proves baseline-to-candidate reconstruction.
`payload.json` binds source/reference hashes and the two execution helper hashes.
The payload has 62 coalesced hunks, 13 new files, and one explicitly reviewed
absent dependency fallback: the full `PublicPageStyles.php` from the target tree.
That fallback is used only when the live file is absent. Existing divergent new
dependencies stop preview. Independent already-installed hunks are skipped;
unknown or ambiguous anchors stop rather than guess. A known intermediate
country-view version may need an independently reviewed payload reconciliation.

The app root is `/home/servernetcloud/servernet_app`; the tool CSS goes to the
separate `/home/servernetcloud/public_html/assets/css/tool-next-step.css`.
Private staging is a `0700` direct child of home named
`.seo-release-20261108-<unique-suffix>`. The helper verifies UID 1020, source
UID/GID/mode/hash or absence, rejects symlink paths, keeps `0600` private
preimages/candidates, and checks the live precondition again before replacement.
All three shared release locks are acquired in the project order.

Preview compiles candidate Blade using Composer autoload only and lints PHP,
without bootstrapping Laravel, models, providers, jobs, SQL or transport. It
writes only private staging/lock files. The existing layout is checked read-only
for noindex/canonical/hreflang compatibility. Its optional full-bundle
`PublicPageStyles` integration is informational; an external-CSS layout remains
compatible, while full-bundle performance acceptance stays open. No layout is
installed. Active config/route cache artifacts stop for separate activation
review; the observed release environment has neither.

## Build and verify locally

```sh
python3 scripts/seo-release-20261108/build_payload.py
python3 -m unittest discover -s scripts/seo-release-20261108 -p 'test_*.py' -v
```

Review the generated payload, helper hashes and printed payload SHA-256 before
transporting only `release.py`, `compile-blade.php` and `payload.json` into the
private bundle directory. Do not transport tests, repository contents or secrets.

## Continuous release session

Use the printed payload SHA-256 as `PAYLOAD_HASH` below, and a new private stage
path. The execution files can be in a separate private bundle directory.

```sh
python3 /home/servernetcloud/.seo-release-20261108-bundle/release.py session \
  --payload /home/servernetcloud/.seo-release-20261108-bundle/payload.json \
  --payload-sha256 PAYLOAD_HASH \
  --stage /home/servernetcloud/.seo-release-20261108-run1
```

The coordinator prints compact JSON and accepts only these stdin commands:

| Command | Effect |
|---|---|
| `preview` | Private preimages, reverse-byte proofs, candidate lint and plan hash; no app writes. |
| `apply` | Single-use intent, exact scoped source installation and verification under the same locks. |
| `verify` | Recheck installed source/metadata, private snapshots and unchanged layout; after rollback, verify the original baseline instead. |
| `finish` | Final source verification and operator acceptance receipt, then release locks. After rollback this verifies the baseline and writes a separate rollback acceptance receipt. Use only after actual activation and HTTP/mobile acceptance. |
| `rollback` | Guard all changed files, restore only known installed preimages/remove only known new files, and retain all three locks for targeted rollback activation and HTTP acceptance. Then send `finish`. |
| `abort` | End before apply. An installed release requires finish or rollback. |

All three locks remain held while the operator performs targeted activation and
HTTP/mobile acceptance. A separate trusted activation command must not reacquire
these locks. No arbitrary shell command is accepted by the coordinator.
After `rollback`, the session continues holding the same locks; `verify` and
`finish` check source hashes, UID/GID/modes and absence against the original
baseline. `finish` then writes `rollback-finished.json` and reports
`rollback_finished`, without claiming the candidate was accepted or installed.
Disconnect/EOF never means acceptance: it reports pending review and closes the
locks. Never blindly repeat apply. To resume a session, supply the exact private
preview SHA-256 via `--preview-sha256` and use verify/finish/rollback as appropriate.

Separate `preview`, `apply`, `verify`, `rollback` CLI phases are also available;
non-preview phases require `--preview-sha256`. Each separate phase holds locks
only for that invocation, so use session mode for continuous release acceptance.

## Activation and rollback boundary

The installer does not modify compiled Blade caches, OPcache, page-cache
generation, config cache or route cache. It does not change billing, settings,
schema, cron, providers or customer state. Its successful result is
`installed_source_verified_pending_activation_and_http`.

The release operator activates only exact changed compiled views, verifies the
actual `PageCache::purge()` generation change, and observes fresh GET markers.
Web-pool OPcache work is only necessary if actual stale code is observed; CLI
invalidation is not web-pool activation. Install order noindex and sitemap
exclusion together. Follow `docs/seo/deployment-plan.md` for all language,
price/availability, CSRF/cache, schema, sitemap and mobile acceptance.

Receipts are private: `preview.json`, `intent.json`, `applied.json`, `finished.json`,
`failure.json`, `rollback.json`, `rollback-finished.json`. Partial failures attempt only guarded source
rollback. Unknown newer work prevents restoration and is preserved. Rollback
also needs the corresponding targeted activation and HTTP acceptance; source
restoration alone does not prove the web worker is executing the restored code.
