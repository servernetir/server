# Light theme and Atlas rendering — 21 October 2026

Status: code prepared for a draft pull request targeting `develop`; not merged, installed or verified in production.

Base: `3e7bd7355e0cbe86702225c824fecf98ad257843`. The source/compact asset pairs were read from the same develop head. This is a frontend change; no network, DNS, route, NAT, firewall, VM, provider, financial calculation, authentication, cron or database state was changed.

## Changes

- Improve light-theme dim, cyan, green and information text. Apply the corresponding overrides to Atlas and public-page token scopes. Preserve the deliberately dark terminal's own text colours.
- Give the IP map coordinate label a light background in light mode; give the popular-plan badge white text on its dark blue light-mode background.
- Keep source and compact CSS declarations in sync. Atlas partials consume the compact assets directly.
- Limit decorative desktop globe painting to approximately 30 Hz, retaining display cadence during pointer dragging. Coalesce geographic connection rebuilding into drawing, so repeated region clicks update labels, links and aria-pressed state immediately without rebuilding geographic vectors in the event handler.
- Stop mobile globe painting when a selected-region transition settles. Resume for selection, keyboard, dragging, visibility and page restore. Preserve reduced-motion, offscreen and hidden-tab handling.
- Label the homepage domain card with the existing annual fa/en/tr translations. Other product cards keep the monthly default. This changes only a display period, not prices or billing.

## Validation actually performed

The test fixture in `website/tests/js/atlas-render-budget.test.cjs` was executed against BOTH complete changed JavaScript assets in the tool's JavaScript isolate. All **38 behavioral assertions passed**. It uses a deterministic DOM/Canvas/event/animation-frame fixture; it is not a browser performance measurement.

The same fixture against the original source failed its render-budget assertion (61 paints in the simulated second), demonstrating that the test detects the original behavior. The changed assets draw within the 25–32 simulated-paints acceptance range. Tests also cover latest-click-wins labels/links/aria state, deferred geometry allocation, one animation loop, hiding, offscreen pause, back/forward restore, mobile settling, reduced motion and keyboard input. Both assets parsed successfully. An independent review found no blocking source/compact semantic or lifecycle issues and additionally exercised dragging, viewport changes and reduced-motion toggles with the deterministic fixture.

The portable Node entry point is:

```text
node website/tests/js/atlas-render-budget.test.cjs
```

**Node CLI was not run**: this session's execution host rejects its mixed Windows/Linux sandbox paths before starting a process. Browser startup fails on the same class of environment-path error. PHP lint, Laravel tests, screenshots, actual device testing, Lighthouse, LCP/INP and live city-click latency therefore remain unverified. No live speed improvement or search ranking gain is claimed.

Changed declarations were compared across six corresponding source/compact CSS rule pairs. Calculated contrast ratios:

| Text / background | Ratio |
|---|---:|
| dim / white | 5.24:1 |
| dim / Atlas raised surface | 4.64:1 |
| cyan / Atlas raised surface | 4.58:1 |
| green / Atlas raised surface | 4.86:1 |
| information / light page background | 4.92:1 |
| popular badge white / blue | 5.41:1 |
| terminal dim / dark terminal | 6.16:1 |

These are mathematical colour checks, not a full visual/accessibility audit.

## Live findings and remaining scope

Text retrieved from `/cloud`, `/cloud/de-falkenstein`, `/cloud/de-frankfurt`, `/vps/hourly` and `/gpu` displayed empty/unavailable catalogue states during read-only checks. This may reflect catalogue/cache trouble or a difference between crawler and interactive responses; backend health was not established. Do not refresh production catalogues or alter provider/financial state from this observation alone.

The current develop `pages/cloud.blade.php` uses static product artwork. The interactive Atlas globe is used by the home/contact partials; the account store in develop contains city-navigation links. The exact globe-enhanced VPS purchase implementation reported by the owner must be compared with the live files before deployment. This patch does **not** claim to have reproduced or fixed that specific live city-click delay.

Additional read-only content findings for a separate reconciled change: contradictory hourly-billing prose, hosting-specific paragraphs on the domain catalogue template, and country copy leaking another city name. Validate the current deployed source and actual billing contract before editing those statements.

## Release and rollback

Do not deploy this branch blindly or copy the full repository over production. In a working isolated checkout, run the Node fixture, PHP lint/relevant Laravel rendering tests, and browser checks in fa/en/tr at mobile and desktop widths, light/dark modes, actual purchase city navigation and reduced motion. Rebuild compact assets with the existing reviewed toolchain if available and rerun the fixture against the result.

Compare live source/asset hashes with the branch, resolve drift (particularly the VPS globe), create a limited manifest and private backups, and then use the project's existing deployment procedure. Public assets have a separate document root. Validate cache-busted public bytes and real interactions after installation.

Until merged, rollback is simply leaving this draft branch unmerged. After a later approved limited installation, restore only that release's matching private backups with hash guards and activate only the affected assets/views. Never revert unrelated concurrent work or restore the entire application.
