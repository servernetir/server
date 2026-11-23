# Compact desktop globe — 23 November 2026

Base: `c155e139047e2627aec098b0db41e776a58fc4c5` (develop). Owner requested a smaller globe after the six-slide release.

Only the desktop globe layout changes: the square canvas uses `clamp(570px,min(60vw,118svh),1120px)` instead of `clamp(680px,70vw,1320px)`. A viewport-height cap avoids an oversized sphere on wide, short displays. The mirrored offset moves from -8vw to -2vw, keeping more of the smaller globe visible. Dot data, renderer, region glass, header, six-slide fade, statistics and mobile layout remain as previously released.

Both current production stylesheet copies matched the immutable source SHA-256 before editing. Release `scripts/home-globe-compact-20261123.py` guards both exact hashes, writes only the two CSS destinations under the existing release lock, keeps private original-byte backups and invalidates only the homepage HTML cache keys. No PHP source, configuration, routes, data or renderer changes.

Production acceptance pending.
