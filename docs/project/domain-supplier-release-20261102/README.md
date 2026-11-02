# Live-compatible domain release patches

Source runtime: `9d5a26cf7e19614c6acbfaedf951bc2b999ecc18`; source baseline: `6443ab136e52ae51d1af73347971dd07ead10cb1`.

The read-only live inventory found 61/67 destinations matching the source baseline and six drifted existing files. These patches apply only the domain feature to those exact live versions. They retain the currently deployed Iran purchase guards, the Bale webhook secret and Hermes route include, and do not implicitly install the unrelated birthday feature. No production credential, customer data or environment file is included.

Each patch has an exact live before-hash, patch hash and resulting overlay hash in the adjacent JSON manifest. Patch replay against the read-only live input was verified locally. This verifies deterministic reconstruction only; the six overlays subsequently passed PHP 8.4 lint in a private temporary directory on the actual cPanel server, with all original live files unchanged. They have **not** passed behavioral acceptance as a release payload. The successful 204-test / 1146-assertion SQLite and MariaDB run applies to the source runtime, not these overlays.

Before installation: take a fresh live inventory, retain the exact private originals, reject every unexpected drift, reconstruct the overlays without fuzz, verify their hashes, repeat lint if any bytes change and test the actual release candidates, obtain schema backup/restore evidence and execute only the two named additive migrations in the separately authorized release window. Verify OT&E contacts and writes before enabling sales. Do not install the source-baseline versions over the six drifted files. Feature and contact provisioning remain off until acceptance. No financial pilot is authorized by this package.

Rollback retains supplier journals and same-account reconciliation after any real submission. Never down live operation/contact journals or retry a purchase through another supplier.

Live lint receipt, 2 November 2026: all six exact before-hashes, patch hashes and overlay after-hashes matched; each private candidate passed `/opt/cpanel/ea-php84/root/usr/bin/php -l`. The temporary directory was removed on completion. No source destination, database, configuration or scheduler was changed.
