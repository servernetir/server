# Live-compatible domain release patches

Source runtime: `9d5a26cf7e19614c6acbfaedf951bc2b999ecc18`; source baseline: `6443ab136e52ae51d1af73347971dd07ead10cb1`.

The read-only live inventory found 61/67 destinations matching the source baseline and six drifted existing files. These patches apply only the domain feature to those exact live versions. They retain the currently deployed Iran purchase guards, the Bale webhook secret and Hermes route include, and do not implicitly install the unrelated birthday feature. No production credential, customer data or environment file is included.

Each patch has an exact live before-hash, patch hash and resulting overlay hash in the adjacent JSON manifest. Patch replay against the read-only live input was verified locally. This verifies deterministic reconstruction only; the six overlays have **not** passed PHP lint or behavioral CI and are **not** an approved deployment payload. The successful 204-test / 1146-assertion SQLite and MariaDB run applies to the source runtime, not these overlays.

Before installation: take a fresh live inventory, retain the exact private originals, reject every unexpected drift, reconstruct the overlays without fuzz, verify their hashes, lint and test the actual release candidates, obtain schema backup/restore evidence and execute only the two named additive migrations in the separately authorized release window. Verify OT&E contacts and writes before enabling sales. Do not install the source-baseline versions over the six drifted files. Feature and contact provisioning remain off until acceptance. No financial pilot is authorized by this package.

Rollback retains supplier journals and same-account reconciliation after any real submission. Never down live operation/contact journals or retry a purchase through another supplier.
