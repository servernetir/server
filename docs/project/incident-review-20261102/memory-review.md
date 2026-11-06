### Independent review: price-refresh memory use — 2026-11-02

Reviewed source blob `8ee28c45b55bd6b37b98cd9e105e02e6c1ae6315` and regression-test blob `5e4fee7c6669c4078fe4486c48302298e21ba99a`.

The prepared batch now retains plan IDs and SHA-256 digests of serialized complete raw rows. It releases the original hydrated catalogue, provider collections, catalogue arrays and the last caught exception before subsequent reads and apply. Serialization preserves raw attribute types and key order. This also replaces repeated linear model lookups with direct digest lookup.

The eligible-ID set check, concurrent row-edit rejection, provider exclusions, settings and FX checks, and transactional publication remain in their previous order. Tests cover releasing hydrated rows through weak references and rejecting a concurrent non-price edit; the existing new-identity and administrator-note guards remain.

Production receipts supplied by the deployment operator show installation at 08:04:54 UTC and a successful actual FX cycle at 08:07:51–08:15:12 UTC under the unchanged 128 MiB PHP limit. Peak allocation fell from 132,644,864 bytes (126.5 MiB, two preceding cycles) to 117,964,800 bytes (112.5 MiB): a 14 MiB reduction, leaving 15.5 MiB below the configured limit. The largest OpenProvider response remained 1,895,691 bytes, and the published FX/catalogue pair remained valid.

This is one measured cycle after the change. It demonstrates increased headroom for that workload; future catalogue growth and heavier concurrent workloads remain outside this result.
