# Four services in one desktop viewport

Base: `develop@8849d6a75f1b3d1e32c409dd01093c358115d6b7`.

The homepage introduction now cycles infrastructure → Studio → n8n → hosting → infrastructure every 5000ms. The selectors below the slides select any service directly. Selection and content interaction pause rotation, and the play control resumes it. Hidden-tab, off-screen, focus and reduced-motion behavior remain. With Studio unconfigured, its slide and selector are omitted and the other three services still cycle.

At desktop widths of at least 1024 CSS pixels and heights of at least 600 CSS pixels, the introduction reserves the viewport minus the existing `--header-h`. It divides this space between slides, selectors and the four original statistics. The globe and Studio scene scale to the available space. Smaller windows and mobile use natural content height to keep content readable. No page scroll is locked.

n8n artwork uses a layered isometric core, connected cards and restrained motion inspired by the supplied Syntra component. It is implemented in the existing Blade/CSS stack with an inline SVG; React, Tailwind and animation dependencies were not added. The content describes possible workflows and links to the existing localized n8n offer and contact page. Hosting promotes existing Linux, Windows, WordPress, WooCommerce, Python, email, backup and download pages. No new prices, provisioning promises or inference calls are introduced.

## Scope and release

Runtime: the homepage, three partials, three `home_slides.php` translation dictionaries, CSS and JS (each in application/public and the separate public webroot). Eleven guarded destinations. A four-anchor home patch preserves unrelated live-only differences.

The fixed-SHA helper under `scripts/home-four-slides-20261123/` stages payloads privately, preflights every destination, holds the shared release lock, backs up replaced files and uses atomic file replacements. Targeted activation compiles only the four affected views in both observed application FPM pools and invalidates homepage cache keys. There is no global cache flush, migration, configuration or billing change. Exact rollback refuses newer live edits, restores all prior files and activates the prior home before removing only this release's new dependencies.

## Validation

Behavioral DOM tests cover the real Blade output in all three locales, the complete four-service loop at five-second boundaries, direct selection of every service, pause/resume, accessibility state, reduced motion, off-screen/hidden tabs, local Studio examples, idempotence and the three-service fallback. Three disposable-file release tests cover preservation, concurrent edits and guarded rollback. Local PHP rendering caught and corrected the n8n route name and an absolute-URL test expectation before publication.

Browser acceptance and production status are pending. Synthetic preview pages compile the real home intro and partials; only the network globe is replaced with a static image and a sample region. The review shell offers 1024×600, 1280×720, 1366×768, 1920×1080 and mobile sizes; its optional `?live` mode embeds the real public homepage. Temporary review files must be removed by their pinned hashes after acceptance.
