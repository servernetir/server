# Initial preview — browser verification

Verified on 2026-11-03 against the isolated managed static preview, not the live ServerNet console. Source base: `31a311e904741f8b58f462824a83c9745731b23f`. This is a Chromium check. Responsive cases use real iframe CSS viewport widths in `review.html`; they are not iOS/Android hardware emulation.

## Observed results

| Case | Evidence / result |
|---|---|
| Persian desktop overview | `lang=fa`, `dir=rtl`, light theme; viewport1363px, document client/scroll widths both1348px. Sidebar and content use the available width without horizontal overflow. Screenshot captured. |
| English desktop detail | `lang=en`, `dir=ltr`; sidebar spans0–230px and main spans230–1348px (1118px). The earlier language-change overlap is fixed. Visible translation-key fallbacks:0. |
| Turkish detail | `lang=tr`, LTR; labels, accessible names, decimal and amount formatting update; no horizontal overflow observed. |
| Windows selection | Hosting card opens `north.example`, Windows/Plesk, with its own metadata, renewal and resource values. Linux selection opens `studio.example`, Linux/cPanel. |
| Hosting filters | Attention filter and a nonexistent-domain search produce the empty state. Clear filters restores the service cards. Both hosting services remain active; attention refers independently to DNS setup. |
| DNS sample | Check button temporarily disables while simulating. Linux sample result remains setup required and explicitly says no live lookup occurred. No unconditional connected/green transition. |
| Modal | Open cPanel opens the simulated-action dialog, explains no external connection, moves focus into the dialog; Escape dismisses it. |
| Mobile drawer390px | Opens on the correct RTL side, sidebar x130–390 and width260px; background has inert attribute. Focus enters the drawer. Tab from its last profile action returns to the brand button. Escape closes it and returns focus to menu-toggle. Closed offcanvas navigation has inert attribute. |
| Mobile navigation390px | Bottom hosting navigation switches the visible page. Document client/scroll widths both375px after the vertical scrollbar. Screenshot captured. |
| Small mobile320px | After removing the fixed minimum HTML width, overview, hosting list and Windows detail each report client/scroll widths305/305px. The15px difference from iframe width is the vertical scrollbar. |
| Turkish/dark320px | `lang=tr`, `dir=ltr`, dark theme; client/scroll305/305px. |
| Copy fallback | Copy both nameservers opens a selected, read-only textarea when Clipboard is unavailable. Value contains an actual newline separating `ns1.hosting.example` and `ns2.hosting.example`. No external request. |
| Tablet768px | Turkish dashboard: viewport768, client/scroll753/753, main width753px. |
| Compact desktop900px | Turkish dashboard: viewport900, client/scroll885/885, main width809px with compact sidebar. |
| Large desktop1440px | Turkish dashboard: viewport1440, client/scroll1425/1425, main width1195px. |
| Standalone HTML export | Browser rendered the exported file through the local QA host. Fonts loaded, no external script/style elements, hosting navigation and Windows/Plesk selection work. Its own CSS, JavaScript and WOFF2 fonts are embedded. Direct file:// was not separately exercised. |
| Sidebar persistence | Collapsed state persists after reload; aria-expanded=false and settled sidebar width76px. State restored to expanded for delivery. |

Initial testing found and corrected the Windows-card selection argument, the JS/CSS mobile breakpoint mismatch, LTR sidebar overlap, minimum-width scrollbar overflow, font URL paths, collapsed navigation names, startup aria-expanded and created-date sorting. Initial mobile microtype was replaced by readable labels and reflow; important controls use44px targets. The source checker retains only nonessential7px brand microcopy and10px preview-version typography as review notes.

Some browser-tool actions and clipped screenshot requests intermittently timed out. Final state was re-observed; full viewport screenshots were saved successfully. A clipboard API was unavailable in the local HTTP preview, and its manual-copy fallback was verified. Browser-extension metadata errors appeared in the extension's own content script; this is not evidence of an application failure. No claim is made that every browser console entry or every interactive state was exhaustively audited.

## Focused source checks

`check-preview.py` passed:898 HTML elements,74 buttons,100 unique IDs, existing local assets, accessible control names, ID references, noindex, synthetic data and isolation checks. Catalogue:208 nonempty keys in each of fa/en/tr. `node --check` passed for app.js, locales.js and the unchanged shared select script. Source styles contain focus, forced-color and reduced-motion handling; reduced-motion and assistive-technology behavior were not tested on physical devices.

## Resource size evidence and remaining performance work

These are file/gzip measurements, not network timing or Core Web Vitals scores. Gzip uses Python's standard compressor; production compression can differ.

| Asset group | Raw bytes | Sum of separately gzipped bytes |
|---|---:|---:|
| Styles + unchanged select CSS | 89,651 | 15,307 |
| App + all3 locales + unchanged select JS | 66,709 | 18,519 |
| Two WOFF2 fonts | 64,364 | Already compressed font format |
| Main HTML | 45,947 | 9,740 |
| Self-contained export | 287,987 | Downloadable offline deliverable, not a production asset |

The proposed20KiB compressed shell-CSS budget is met; the proposed15KiB initial shell-JS budget is **not yet met** when all three locales ship together. Later approved Blade integration should serve the requested locale's catalogue, scope the CSS components and measure at least three comparable runs before accepting performance results. The complete offline catalogue is intentional for this review artifact. No live LCP, INP, CLS, Lighthouse score, SEO improvement or ranking gain is asserted.

## Delivery and limits

The user can review a full-width desktop and an app-like mobile composition, with synthetic data only. Source changes are confined to the new preview/audit folders; production, develop, backend data and customer actions are unchanged. Actual Laravel action integration, every data state, Safari/iOS/Android, screen-reader sessions, production SEO changes and later modules remain outside this initial preview. Stop here for the user's design review.
