# Hardware catalog release evidence

The production release is already installed. Do not rerun these helpers blindly.

`deploy.py`, `catalog.php`, `lint.php` and `refresh.php` preserve the reviewed v2 deployment implementation. They require a private release folder containing its exact manifest, protected hashes, payload and original before-state. Paths and PHP runtime match the cPanel deployment, whose document root is separate from the Laravel checkout. Existing deployment credentials are read only in memory by the application and are not included here.

The dated build/audit scripts are research artifacts using the original workspace's output folder and inputs. They are not an automatic synchronizer or inventory source. Product and image provenance is in `website/database/catalog/hardware-provenance-20261014.json`; the canonical dated payload is `hardware-products-20261014.json`. Regeneration requires reviewing inputs and applying builder, image curation and enrichment in order; do not overwrite later editorial changes by rerunning the builder.

Importer use requires an absolute private receipt path. Its preflight rejects concurrent data changes; apply is transactional and an existing receipt blocks accidental repetition. Rollback rejects changed inserted rows and restores only fields that this import changed on older rows. See the root dated release report for live validation, limitations and the v4/v3/v2 rollback order.
