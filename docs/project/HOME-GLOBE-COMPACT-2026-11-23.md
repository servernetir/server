# Compact desktop globe — 23 November 2026

Base: `c155e139047e2627aec098b0db41e776a58fc4c5` (develop). Owner requested a smaller globe after the six-slide release.

Only the desktop globe layout changes: the square canvas uses `clamp(570px,min(60vw,118svh),1120px)` instead of `clamp(680px,70vw,1320px)`. A viewport-height cap avoids an oversized sphere on wide, short displays. The mirrored offset moves from -8vw to -2vw, keeping more of the smaller globe visible. Dot data, renderer, region glass, header, six-slide fade, statistics and mobile layout remain as previously released.

Both current production stylesheet copies matched the immutable source SHA-256 before editing. Release `scripts/home-globe-compact-20261123.py` guards both exact hashes, writes only the two CSS destinations under the existing release lock, keeps private original-byte backups and invalidates only the homepage HTML cache keys. No PHP source, configuration, routes, data or renderer changes.

PR #404 merged at `2d00d3b5bbbf9f3000b7bd514b2cc107751e6b4a`; source `a99d0f71939519f13baf75a4610e065cf4b392f7` verified identical to the merged develop on both changed release paths. CSS parsing had zero errors/warnings, two PHP tests/120 assertions and 15 DOM tests passed, and all five triggered CI workflows succeeded.

Both production CSS copies were installed and hash verified, without a general cache flush. FA/EN/TR public pages returned 200, served the new exact CSS hash and retained six slides at 6000ms. Actual browser verification at 1363×936 (content width 1348) measured an 817.8px square globe, down from 954.09px at the same viewport (14.3%). The proof rail bottom remained exactly 936px, scroll 0, and region blur remained 3px. [Production screenshot](home-globe-compact-2026-11-23/production.jpg).

Rollback (private exact-byte backup):

```sh
python3 /home/servernetcloud/deploy-backups/home-globe-compact-20261123-a99d0f719395/release.py rollback a99d0f71939519f13baf75a4610e065cf4b392f7
```
