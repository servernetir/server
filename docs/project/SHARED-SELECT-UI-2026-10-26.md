# Shared select menus and customer profile — 26 October 2026

Status: prepared; behavior, visual and profile acceptance passed; production installation pending.

## Request and scope

The owner reports tiny native dropdowns in server hourly billing and inconsistent select/profile UI, and requests the smooth OS-version selector pattern throughout the site. Add self-hosted site-select.js and site-select.css through the shared site layout. Single native selects gain a themed trigger and animated body/top-layer listbox. The original select remains the only form field and owns values, name, validation, autofill and original input/change handlers. No new values are submitted merely by enhancing or opening a menu. Existing OS-version selectors, multi-select/listboxes, Jalali month/year controls and explicit native opt-outs stay with their existing presentation.

Menus use viewport-aware width/height, open upward when needed and escape clipping from panel cards. The control supports Arrow keys, Home/End, Enter/Space, Escape, Tab, typeahead, disabled/hidden options and groups, reset and option/attribute updates. Missing CSS or JS keeps the native fallback. Motion respects reduced-motion settings. Option labels use textContent. The control has no backend endpoint, external CDN or dependency.

Customer profile corrections: shrinkable two-column grids, bounded file/name controls and themed file buttons; valid semantic state badge instead of invalid var(--color)22; retain the submitted company/individual type after validation and show the matching fields.

## Acceptance

A private browser fixture uses current production panel CSS, the profile CSS and representative real-form markup. Twelve scenarios pass at exact mobile widths320/390 and desktop/tablet widths752/1184, in fa/RTL/dark, en/LTR/light and tr/LTR/dark. These test layout directions/themes and simulated field content; no live customer record or real submission is used. Assertions cover a body-level animated menu inside viewport bounds, upward opening at the bottom edge, original FormData value, exactly one input and one change event, company visibility, required-validation focus and error clearing, Escape and no horizontal page overflow. The rendered fixture was visually inspected.

Nine jsdom behavior tests pass: original serialization/events, keyboard skipping of disabled/hidden/group options, Escape/Tab/outside close, native required validation, reset, mutation/disabled synchronization, dynamic mounting and special-picker exclusion, missing-CSS fallback, typeahead and safe text labels. Long-country-list browser acceptance also confirms that a selected last option is scrolled into view.

AccountPanelTest passes10tests/43assertions, including a new rendered-profile regression for old company selection, visible company fields and the draft status badge. Exact production layout/profile candidates compile with Laravel and pass PHP8.4 lint. The new JS passes node --check. Broader account-suite attempts reached the local timeout and are not reported as complete; the focused account panel suite is complete.

## Persistence, production safety and rollback

Only shared web assets, the shared layout and profile template are installed. No DB, order, invoice, balance, provider request, service, route, DNS, NAT/firewall or network agent changes. The production layout differs from develop, so add only the two asset hooks to the fresh production template. Profile currently matches source; patch only the reviewed UI lines. Upload and verify both app/public and public_html asset copies first, then activate the templates. Preserve fresh private backups and verify pre/post hashes. Verify HTTP assets in both domains plus the rendered login page asset hooks. Authenticated live customer browsing is unavailable in this environment; acceptance uses isolated browser fixtures and Laravel renders.

Rollback: restore each template only if its current hash still matches this release, otherwise merge the inverse UI patch. Deactivating the layout hooks returns native selects. New unreferenced static files may remain until a later controlled cleanup. Do not overwrite unrelated production layout changes or rerun earlier deployment helpers.
