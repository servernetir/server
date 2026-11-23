# Balanced desktop globe — 23 November 2026

Base: `0dc1a1e024d41739534cd2ec766fa72b2e885b34` (develop). The owner requested a visibly smaller globe after the previous compact adjustment.

The desktop square globe host now uses `clamp(460px,min(46vw,89svh),810px)`, compared with `clamp(570px,min(60vw,118svh),1120px)`. A mirrored 4.5vw inset replaces the -2vw offset, keeping the globe centered within its half of the hero. The renderer takes its radius directly from the host dimensions. The points, masks, glass panel, full-height stage, proof rail, six-slide fade and mobile layout are unchanged. At a 1363×936 viewport the expected square host size is about 627px rather than 818px (23.3% smaller).

Only the desktop CSS rule and exact before/after hashes in the existing two-destination static release helper change. Both live CSS copies matched the original hash before the edit. The helper takes private exact-byte backups, uses the shared release lock and invalidates only homepage HTML cache keys. No application code, data, configuration or global cache changes.

## Production acceptance

PR #407, source `b6f19deca58e86ed3b41c432347f35c13b3ac43c`, merged to develop at `d6f06eae55ea7f22b64a3f582210684d8ba11b32`. The merged release paths match the tested source exactly. Local isolated verification: CSS parsing (zero errors/warnings), 2 feature tests/120 assertions, 15 DOM tests, Python AST and diff check; all five PR workflows succeeded.

The two CSS destinations were installed and verified at SHA-256 `34adf43c0373609a223a02fc0996d6ebbda96589f72a0b948c5751ca14105850`. Persian, English and Turkish public homepages returned HTTP 200 and served this exact CSS under the refreshed version `1795431418`; all six slides and the 6000ms interval remain intact. In the same actual browser viewport (1363×936, client width 1348), the square globe host decreased from 817.796875px to 626.96875px (23.33% smaller). Its left inset is 61.328125px, slide opacity is 1, proof rail bottom is 936px, scroll is zero, and region glass blur remains 3px. [Production screenshot](home-globe-balanced-2026-11-23/production.jpg).

Rollback (private exact-byte backup for this release):

```sh
python3 /home/servernetcloud/deploy-backups/home-globe-compact-20261123-b6f19deca58e/release.py rollback b6f19deca58e86ed3b41c432347f35c13b3ac43c
```
