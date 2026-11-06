# Three-source SEO release, 2026-11-02

These reviewed tools apply only the accepted CountryVpsController schema change, SiteController sitemap hunks, and GPU decision guide from Git `6c0fcb7c` to `b47bb98a`. They are private CLI tools, not public endpoints. Preparation or a Git merge does not prove production acceptance.

`deploy.py`, `payload.json`, and `compile-blade.php` are the three execution files. The payload contains unique old/new context hunks, not a replacement of the live SiteController. Its independent home/quote overlay remains byte-for-byte outside those hunks. Country and GPU candidates must match Git after hashes; the final SiteController hash is derived from guarded live bytes and recorded at preview.

The account/path, file SHA-256, owner, mode, regular path, payload/helper identity and three ordered advisory locks are checked. Preview saves private backups, verifies reverse patch round-trips and lints all candidates, including a standalone Blade compile/lint using Composer autoload without booting Laravel. Apply writes its single-use intent before replacing sources, checks every source immediately before its replacement, and verifies installed identities. Three separate source replacements are not a multi-file atomic transaction; these changes are independently compatible during the short transition.

Run separately, only after verifying staging and the account:

```sh
python3 /home/servernetcloud/.seo-search-growth-20261102/deploy.py preview
python3 /home/servernetcloud/.seo-search-growth-20261102/deploy.py apply
```

Neither command runs SQL, application bootstrap, HTTP, jobs, migrations, broad cache/OPcache flushes or a new web endpoint. Blade compiled cache is left unchanged. Source installation remains **pending HTTP acceptance** until representative routes in fa/en/tr demonstrate the intended schema, sitemap and guide. A source hash alone does not prove the web worker executed new code. The release operator owns that check and any narrowly justified activation.

Rollback, if needed, is a separate guarded operation:

```sh
python3 /home/servernetcloud/.seo-search-growth-20261102/deploy.py rollback
```

Rollback checks all sources first, then rechecks each immediately before restoring only its known installed version. Unknown newer content, mode or owner stops restoration; it is deliberately preserved. Such a stop may leave a partial known release and requires operator reconciliation rather than blind retry. An unchanged baseline source is accepted without replacement. Private `preview.json`, `intent.json`, `applied.json`, `rollback.json` or `failure.json` distinguish the actual state. Single-use guards mean these commands must not be rerun blindly.

Local validation: seven temporary fixtures passed, covering apply/rollback and single-use, drift of source/candidate/backup, partial installation failure, and later work appearing during install/rollback. The SiteController fixture contains an independent overlay. Real current PHP controllers linted, and the GPU Blade compiled standalone and linted under local PHP 8.4. The Windows fixtures inject no-op advisory locks and use the platform file mode; Linux flock/uid/mode behavior requires live preview and was reviewed, not claimed as locally executed. Application regression tests and production HTTP evidence belong in the parent release report.

`review-manifest.json` pins the reviewed bytes; `fixture-validation.json` records the local fixture result. No archive/base64 transport or application/customer data is retained in this durable tool copy.
