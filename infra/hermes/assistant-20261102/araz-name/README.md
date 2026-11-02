# Araz owner-facing name release

Final owner-selected name: **آراز / Araz**. This layer follows the initial eight-file assistant release and the four-file Nava follow-up. It changes names only across seven web sources and one native ingress; CSS is byte-identical to `ui-followup`. Technical paths, `/hermes`, auth keys and persisted provenance remain unchanged. Old/current assistant-name transcript exclusions are retained.

`release-manifest.json`, `deploy.py` and `native-release.php` pin the verified prior production hashes and the reviewed deployment core. Launch through the private staged PHP CLI launcher; do not serve or schedule it. The real support/operations/business/knowledge locks and shared web filesystem locks remain required. Native installation uses `install-native-name.py install SOURCE_DIRECTORY`, exact prior ingress/helper hashes, idle queue checks, owner-only restart and separate root-private Araz receipts/backups.

Rollback this layer first with its own web launcher and native installer. Never overwrite newer hashes or restore old conversation/knowledge/job databases. See [production report](../../../../docs/project/ARAZ-NAME-2026-11-02.md).
