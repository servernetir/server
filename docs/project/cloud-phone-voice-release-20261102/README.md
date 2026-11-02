# Cloud Phone Voice limited release

This is a fixed fourteen-destination page/app-asset release, not a generic upload or command endpoint. The reviewed source consists of the solutions configuration, the shared solution Blade view with a cloud-phone-only hook, the new app partial, its CSS, four native UI screenshots, and the existing preview APK. The APK is only installed in the public download directory; it is not loaded into PHP strings or JSON.

The launcher is temporary and uses the existing `web/auth:web/admin` session plus normal CSRF verification. It accepts only a fixed phase value; HTTP cannot submit paths, commands, content, shell code, a replacement payload or an engine. Remove the launcher after authenticated verify and public browser acceptance. Engine, manifest, sources, candidates, receipts and backups stay outside `public_html`.

## Private staging and manifest

Use `/home/servernetcloud/cloud-phone-voice-release-20261102` with owner equal to the actual cPanel account UID and mode `0700`. The observed account UID must be rechecked rather than assumed. `engine.php`, `payload.json`, and all `sources/` files must be private (`0600`); the source directory may also be `0700`. No credentials belong in this package.

Create the payload with all fourteen `ALLOWED` destinations exactly once. Each entry accepts exactly one of a UTF-8 `content` string or a `source_file` name such as `sources/apk-preview.apk`. Source names permit a single basename, not paths outside `sources/`. Source files are SHA-256 pinned, non-symlink, account-owned, private and at most 64 MiB. Use `source_file` for PNGs and APK, and preferably for every source to keep the manifest small.

```json
{
  "release": "cloud-phone-voice-20261102",
  "source_sha": "<exact forty-hex reviewed source commit>",
  "account_uid": 1020,
  "entries": [
    {
      "destination": "public_html/downloads/servernet-voice/ServerNet-Voice-0.1.0-preview.apk",
      "source_file": "sources/ServerNet-Voice-0.1.0-preview.apk",
      "before_sha256": "absent",
      "after_sha256": "<exact artifact SHA-256>",
      "before_mode": null,
      "mode": 420
    }
  ],
  "protected": [
    {"destination": "servernet_app/routes/web.php", "sha256": "<unchanged raw SHA-256>"}
  ]
}
```

The example shows one entry only; an actual payload must include every fixed entry from the engine. Existing files preserve their actual mode; only config/views may retain `0600`, while assets must be `0644`. A new file has `before_sha256: "absent"`, `before_mode: null`, and mode `0644`. The engine creates only fixed new public cloud-phone and app-download directories. It never creates arbitrary public paths.

For the first read-only inventory, a bootstrap payload may describe all fourteen destinations with `content: ""`, its corresponding empty-string SHA-256, `before_sha256: "absent"`, `before_mode: null`, and mode `0644`. This needs no APK upload. GET only inspects the fixed destinations and does not apply those placeholders. **Do not preview or apply that bootstrap manifest.** Generate a new final payload using the raw before-hashes/modes from GET, real pinned sources and protected file hashes; repin the launcher for the final payload. Do not update a payload after its preview state has been created.

Inject the SHA-256 of the exact `engine.php` and exact raw final `payload.json` into `__ENGINE_SHA256__` and `__PAYLOAD_SHA256__` in `launcher.php.template`. Upload the private engine/payload before the launcher. Check the launcher's raw upload hash. The public filename is chosen by the operator and not taken from HTTP input. The launcher's route is determined by that actual filename.

## Phases and invariants

GET exposes only fixed file hash/size/mode/UID inventory, selected protected-file hashes, current release state and a `configuration_cached` blocker. Fileman charset-converted reads are not raw hash evidence. If configuration is cached, the helper refuses mutating phases rather than flush config/cache or silently publish an inactive config change. Reconcile that separate state before continuing.

POST `preview` holds the same three shared release locks as other web releases. It checks identities, paths, before-hashes, modes, protected files, source hashes and free space. It stream-copies private candidates/backups and compiles the two real Blade views with their real live paths, then PHP-lints those candidates and config. The candidate configuration must preserve every entry except `cloud-phone`, enforced by strict array comparison. Preview does not install application/public files.

POST `apply` requires preview state, repeats all before/compiled/protected/candidate checks, installs dependencies before views, atomically replaces exact destinations with an immediate hash guard, activates the two compiled views and invalidates only their OPcache entries and the solutions config script. It forgets the three cloud-phone guest page cache keys for both ordinary and `/index.php` base URLs (six keys). Existing compiled views preserve their observed `0600`, `0644` or legacy `0755` mode; new compiled files always use `0644`. Other existing modes are rejected. Source and compiled modes are rechecked during preflight and immediately before each rename, just like content hashes. It performs no supplier API, network, migration, queue, scheduler, entitlement or financial operation.

POST `verify` checks every exact source, compiled view and protected hash. It is integrity evidence; correct public HTML, images, APK digest/download behavior and responsive browser rendering remain separate acceptance checks.

POST `rollback` restores only this release's known hashes. It refuses unknown/newer source or compiled bytes, checks each backup, and immediately rechecks every destination hash and permission mode before replacing/removing it. Old page source and compiled views become active before newly introduced dependencies are removed. Public directories may remain empty after rollback; no shared directory cleanup is attempted. A crash in `applying` is not permission to reapply blindly.

## Rehearsal

```sh
python3 docs/project/cloud-phone-voice-release-20261102/test-release-scope.py
php -l docs/project/cloud-phone-voice-release-20261102/engine.php
php -l docs/project/cloud-phone-voice-release-20261102/launcher.php.template
php -l docs/project/cloud-phone-voice-release-20261102/test-engine-native.php
php docs/project/cloud-phone-voice-release-20261102/test-engine-native.php
```

The Python checks are static policy checks only. Native rehearsal requires locked Laravel dependencies under `website/vendor` and an unprivileged PHP user. It substitutes only HOME/PHP constants and the namespace in a temporary copy of the engine, uses the real Blade compiler and a fake scoped cache, and never boots production. It covers preview/apply/verify/rollback, streamed source hashes, old compiled activation before dependency removal, duplicate apply, preserved view mode, preflight drift, immediate rollback CAS, unknown newer bytes, symlinks, cached config, modification of unrelated solution entries, legacy compiled `0755` preservation, source/compiled mode-only drift, immediate streamed/compiled/rollback mode CAS, and refusal to introduce `0755` for new compiled files. No native test result should be claimed until this command actually succeeds.
