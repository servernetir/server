# Balanced desktop globe — 23 November 2026

Base: `0dc1a1e024d41739534cd2ec766fa72b2e885b34` (develop). The owner requested a visibly smaller globe after the previous compact adjustment.

The desktop square globe host now uses `clamp(460px,min(46vw,89svh),810px)`, compared with `clamp(570px,min(60vw,118svh),1120px)`. A mirrored 4.5vw inset replaces the -2vw offset, keeping the globe centered within its half of the hero. The renderer takes its radius directly from the host dimensions. The points, masks, glass panel, full-height stage, proof rail, six-slide fade and mobile layout are unchanged. At a 1363×936 viewport the expected square host size is about 627px rather than 818px (23.3% smaller).

Only the desktop CSS rule and exact before/after hashes in the existing two-destination static release helper change. Both live CSS copies matched the original hash before the edit. The helper takes private exact-byte backups, uses the shared release lock and invalidates only homepage HTML cache keys. No application code, data, configuration or global cache changes.
