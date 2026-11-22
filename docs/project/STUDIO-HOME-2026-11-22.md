# ServerNet Studio homepage introduction

Base: `develop@38c1f391f534b6e35438f187a6197b7846890d69`.

The homepage introduces Studio before the infrastructure hero when central Studio access is configured. A purple and cyan composition explains chat, image, video and audio creation. Four local preview buttons show clearly labelled illustrative content and never request inference. The primary link opens the existing Studio; the installation link follows the locale to the existing web app guide. Shared account, wallet billing and model availability are explained without fixed model counts, prices or signed desktop installer claims.

Scope: one include in `pages/home.blade.php`, a new partial, three translations and three public assets. The SVG is an original conceptual illustration. Styles support RTL, small screens, light theme and reduced motion; ordinary buttons retain native keyboard navigation. Without JavaScript, the default illustration, capability descriptions and real links remain available.

## Validation

PHP 8.4.27 with locked Composer dependencies and synthetic SQLite: `StudioHomeHeroTest`, `StudioDownloadsTest`, `HomePricingPresentationTest` passed **14 tests / 328 assertions**. Four JavaScript DOM tests passed against real compiled Blade in fa/en/tr, covering preview switching, one visible panel, pressed state, localized installation links, the no-JavaScript initial state and zero inference requests. PHP syntax, JavaScript syntax, SVG XML and whitespace checks passed. The release helper has disposable-file tests for live-content preservation, rollback and refusal of concurrent edits.

The browser cannot access the executor's loopback preview. Public visual and responsive acceptance is recorded separately; DOM tests do not claim browser visual acceptance.

## Publication and rollback

`scripts/studio-home-20261122/manifest.json` pins eleven destinations: the partial, three translations, three assets in both the application public tree and actual document root, and the home view. The live homepage includes previously deployed changes absent from the checkout. Publication inserts only the new include into hash-pinned live bytes, preserving those differences. The prepared result has its own pinned after-hash. Dependencies install before the include.

The installer uses the shared release lock, complete preflight, private backup, atomic replacements, final hashes and a fixed reviewed commit. Activation reuses pinned same-account socket and HTTP-denial helpers, invalidates only release PHP and two compiled views, and forgets only homepage cache keys and front-controller aliases on the two canonical hosts. No general cache flush, migration, configuration, inference or financial change is involved.

On the approved application account: `python3 release.py prepare <sha>`, then `apply`, `verify` and `activate` with the same fixed SHA. Private receipt: `deploy-backups/studio-home-20261122-<sha12>/`. `rollback` with that SHA checks every installed hash and the backup before restoring the exact home bytes, recompiling the two views and retiring only this release's new files. Any newer live change stops rollback.

Status: implementation and isolated checks complete; Git, CI and production acceptance pending.
