# Homepage glass panels and fine globe — 23 November 2026

Base: `2c23c31453a0c5f6210321ce6f77b8e38a54a1a1` (`develop`). Source PR388 merged and the bounded release was verified in production.

The owner requested a slightly smaller globe, finer and more numerous geographic dots, and translucent header/lower intro panels so the same globe can be seen behind them.

## Scope

- Desktop globe canvas decreases from 76vw to 70vw (about 8%). The complete intro remains one viewport including the existing two-bar header, slide selectors and statistics.
- The globe can paint behind the header and lower panels; intro clipping contains it before the domain section. Home-only glass surfaces retain dark/light themes. Copy, region links and controls retain higher stacking layers.
- Geographic points increase from 2,708 to 5,184 with a deterministic scanline resample of the existing licensed Natural Earth coast polygons. Dot radius is capped at 1.15 CSS px. The existing adaptive density, offscreen pause, mobile fallback, input and reduced-motion behavior remain.
- Production homepage globe renderer/data assets and homepage CSS only; no live homepage template replacement. Existing live-only Iran catalog work is preserved.

## Validation and release

- 15 existing PHP integration tests / 517 assertions and 14 real-Blade DOM tests pass.
- The Atlas public-page test referenced the former `domain-form` ID; aligned its existing fallback-form assertion with `discovery-domain-form`, already present unchanged on the base branch. Its Iran destination assertion now follows the existing Persian-only public visibility policy.
- Source/minified JavaScript and review-page JavaScript syntax checks; deterministic geography rebuild; three disposable release preservation/concurrency/rollback tests.
- CI also checks the Atlas homepage/contact forms in fa/en/tr.
- Pinned manifest: `scripts/home-glass-fine-globe-20261123/manifest.json`; exact before/after SHA-256, private backups and the shared release lock. Four destinations: homepage CSS in app/public and public_html, the actual homepage globe renderer and land data in public_html. The previously untracked production renderer is preserved in Git with only its dot radius expression changed and a color-helper variable renamed; generic atlas.js/min.js are unchanged.
- Release helper preserves exact current live bytes on rollback. Activation reuses same-account FPM ownership checks, recompiles only four existing home views, and forgets only home page-cache keys; no general cache flush.
- Temporary noindex review uses anonymous public homepage snapshots, strips CSRF values and replaces only the three proposed asset URLs before iframe execution. It retains the actual HTTPS origin required by the existing renderer. Remove only hash-matching generated and pinned review files after acceptance.

## Production acceptance

- Final reviewed source: `5cfbf125168a45507dfe359c2c063eecc11e1e9c`; PR388 merge/deployed SHA: `920912630be52271772440f03a44066dd2b948cf`.
- All seven final-source CI workflows passed, including the homepage presentation workflow. Local checks above were actually run; the baseline Atlas test had two stale assertions, now aligned with existing form/visibility behavior.
- Four destination hashes verified on disk; both same-account FPM pools activated, each compiling four existing home views. Studio remains configured and `general_cache_flush=false`.
- Public fa/en/tr each returned all four slides and the expected versioned CSS, dedicated renderer and land data bytes.

| Asset | SHA-256 |
| --- | --- |
| `studio-carousel.css` (both destinations) | `feea7b1be9fa54ca47a6706e257cc8f4e11ee53abeaae4ad4898c75ca8be781b` |
| `atlas-home-globe.js` | `f00f1da38d72aa3abb0113fa89c0a83b56ec764934b25d0d85c268d9a6b30b2a` |
| `atlas-land.js` | `09c589038c62725fafb387f43f5d43f2a66895c9c7b0bf7757bfc48fd4128ef9` |

- Existing live home Blade remained exactly `03543be0bd5faf871c52fca7fde156a848bdd56ae63e9adb1ab625d2c8134fab`; no live-only catalog or region integration was replaced.
- Browser review used real public anonymous snapshots with the proposed assets and actual HTTPS origin. Desktop frames 1024×600, 1280×720, 1366×768 and 1920×1080 kept statistics at the viewport bottom with zero horizontal document overflow. Persian, English and Turkish, light/dark, n8n slide and country selection were reviewed. The 390×844 mobile frame retained natural document height and zero horizontal overflow; the desktop glass/full-height override does not apply to mobile.
- Production native viewport: 1363×936 (content width 1348). Intro top/bottom 0/936; proof rail bottom 936; globe host width 954.09 vs previous 1035.88 px. Dedicated renderer was ready with its existing adaptive step 3. Header computed `rgba(6,12,22,.18)` with `blur(4px) saturate(1.12)`.
- All three exact-hash temporary review folders were removed after acceptance. No new private endpoint remains.
- Native production proof: [home-glass-production.jpg](home-glass-fine-globe-2026-11-23/home-glass-production.jpg).

Rollback uses the private exact-byte backups with the same lock and refuses newer live edits:

```sh
python3 /home/servernetcloud/deploy-backups/home-glass-fine-globe-20261123-920912630be5/release.py rollback 920912630be52271772440f03a44066dd2b948cf
```
