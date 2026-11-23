# Homepage glass panels and fine globe — 23 November 2026

Base: `2c23c31453a0c5f6210321ce6f77b8e38a54a1a1` (`develop`). Production acceptance is pending at this source review.

The owner requested a slightly smaller globe, finer and more numerous geographic dots, and translucent header/lower intro panels so the same globe can be seen behind them.

## Scope

- Desktop globe canvas decreases from 76vw to 70vw (about 8%). The complete intro remains one viewport including the existing two-bar header, slide selectors and statistics.
- The globe can paint behind the header and lower panels; intro clipping contains it before the domain section. Home-only glass surfaces retain dark/light themes. Copy, region links and controls retain higher stacking layers.
- Geographic points increase from 2,708 to 5,184 with a deterministic scanline resample of the existing licensed Natural Earth coast polygons. Dot radius is capped at 1.15 CSS px. The existing adaptive density, offscreen pause, mobile poster and reduced-motion behavior remain.
- Shared Atlas source/minified/data assets and homepage CSS only; no live homepage template replacement. Existing live-only Iran catalog work is preserved.

## Validation and release

- 15 existing PHP integration tests / 517 assertions and 14 real-Blade DOM tests pass.
- The Atlas public-page test referenced the former `domain-form` ID; aligned its existing fallback-form assertion with `discovery-domain-form`, already present unchanged on the base branch. Its Iran destination assertion now follows the existing Persian-only public visibility policy.
- Source/minified JavaScript and review-page JavaScript syntax checks; deterministic geography rebuild; three disposable release preservation/concurrency/rollback tests.
- CI also checks the Atlas homepage/contact forms in fa/en/tr.
- Pinned manifest: `scripts/home-glass-fine-globe-20261123/manifest.json`; exact before/after SHA-256, private backups and the shared release lock. Five destinations: homepage CSS in app/public and public_html, Atlas source/minified/data in public_html (the app/public JS copies are absent).
- Release helper preserves exact current live bytes on rollback. Activation reuses same-account FPM ownership checks, recompiles only four existing home views, and forgets only home page-cache keys; no general cache flush.
- Temporary noindex review loads the real public homepage anonymously and replaces only the three proposed asset URLs before iframe execution. Remove the exact pinned review files after acceptance.

## Production acceptance

Pending visual review, CI and installation; this section will record the actual merge SHA, installed hashes, viewport results and proof image.
