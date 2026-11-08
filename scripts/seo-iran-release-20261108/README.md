# Scoped Iran marketing source release, 8 November 2026

This separate helper installs exactly nine reviewed runtime paths from
`ca7184db35e2ae5b0ba1504a368b795956b118fc` to published target
`b687f92a3f1ef300d1a8ae6f4890ec3ea5bdbc88`. Each target runtime file is proved
byte-identical to original curated commit
`b6e9206c56710587fa9c1d1c004cec5e410ad7dc`; the published followup adds only
CI, tests and documentation. None of those non-runtime paths is deployed.

`release.py` is copied from the exact main helper SHA-256
`d74869fc16fc470ba9446b92892d785950f710b9312ae1a622f2e1455e1a405d`.
Only its release identity, static nine-path source tuple and private stage prefix
change. `installer-scope.diff` shows the complete mechanical difference.
`installer-origin.json` and the builder reverse those three changes in memory
and prove the exact original hash. `compile-blade.php` is copied unchanged,
SHA-256 `15c41d553785c0bd26fff98cb62581f87ed4dd9ecca48abc2dde9d62c8f1b910`.
No shared main-helper files are changed. Its 34-path identity still rejects
this payload.

## Build and local guards

```sh
PYTHONDONTWRITEBYTECODE=1 python3 scripts/seo-iran-release-20261108/build_payload.py
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/seo-iran-release-20261108 -p 'test_*.py' -v
```

`scope.json` fixes the nine source/destination paths and baseline/target hashes.
The builder reads fixed Git objects with lazy fetch disabled; it neither fetches
missing objects nor reads mutable source files. Unknown commit, parent,
changed-path set, source bytes or destination stops the build. Builder output
stays inside this directory. `payload.json` binds the execution helper hashes;
builder/proof hashes are recorded separately.

Existing files receive only unique reversible old/new hunks: eight patches,
11 coalesced hunks, and one exact new `CountryVpsEligibility.php` file. Unrelated
live overlays are preserved outside those anchors. Unknown or ambiguous anchors
stop preview. The new file may be absent or exactly identical, including uniform
line-ending normalization under the unchanged core. A divergent existing new
service stops. Existing patch sources may not be absent. No absent-dependency or
hosting-preimage override fields appear in this payload; their unchanged core
branches are unreachable in this static nine-path scope.

Python synthetic tests cover identity/scope/stage rejection, all three shared locks,
no application writes during preview, byte reversibility, partial installation,
CRLF, symlink/permission/UID/GID protection, source and private-snapshot drift,
guarded rollback and continuous locks through rollback acceptance. They use
synthetic homes inside this directory; no application bootstrap, provider, job or
network action runs. Passing these tests does not establish compatibility with uncaptured live
hunks or prove production activation.

## Reviewed live workflow boundary

Transport only independently reviewed execution files and the exact payload;
never transport tests, Git contents or source snapshots wholesale. The app root
is `/home/servernetcloud/servernet_app`; all nine destinations are beneath it.
No routes, public assets, config, migrations, settings, provider actions or
payment paths are included. `scope.json` lists the exact paths.

The helper accepts release `seo-iran-release-20261108` and private stage names
`.seo-iran-release-20261108-<unique-suffix>` directly beneath the account home.
It preserves the same three release locks, private source identities/preimages,
preview hashes, lint, source verification, rollback and single-use receipts.
Review a fresh preview against actual live overlays before any apply.
Use its continuous `session` mode so locks remain held through independent
activation and HTTP acceptance. EOF is never acceptance. After rollback, locks
remain held through targeted rollback activation and baseline acceptance.

A separate `activate.php` variant and its tests/diff are maintained beside this
helper by the release coordinator. Its exact scope and activation receipt must
be independently reviewed; its actual SHA-256 is the third execution-helper hash
bound by the payload. Source installation, targeted Blade/cache activation,
fresh three-language HTTP/KYC/schema/sitemap acceptance, and rollback activation
remain separate steps. The source helper does not bootstrap Laravel or activate
caches. No production installation or activation is asserted by this directory.
