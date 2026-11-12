# Initial preview source review — 2026-11-03

Status: **documented source review with separately attributed parent browser evidence; preview only**. This report does not claim integration, production or complete accessibility acceptance.

- Base: `31a311e904741f8b58f462824a83c9745731b23f` (`develop` snapshot).
- Working branch supplied for review: `preview/full-width-ui-20261103`.
- Reviewed surface: `preview/full-width-20261103/` only, plus project instructions and the supplied redesign brief.
- Python: `3.12.14`; Node: `v24.22.1`.
- No server, browser automation, application boot, database operation, external API request, merge or deployment was performed by this review.

## Checks actually run

```sh
python preview/full-width-20261103/check-preview.py
node --check preview/full-width-20261103/app.js
node --check preview/full-width-20261103/locales.js
node --check preview/full-width-20261103/assets/site-select.js
```

All commands passed on the reviewed source. The structural checker inspected **898 HTML elements, 74 buttons, 100 unique IDs and six HTML/CSS/JS source files**. Its locale check found **208 matching, nonempty keys in each of fa/en/tr**, covered the HTML translation references and literal `t()` references, and found no Persian characters in English or Turkish catalogue values.

The checker is a focused static guard. It parses actual HTML references and assets; it does not simulate the implementation or count each element as a separate test. It validates:

- Unique IDs and resolved ARIA, label, SVG and fragment references.
- Named buttons, labelled fields, explicit button types and existing view targets.
- Initial Persian RTL, main landmark, skip link, viewport and `noindex,nofollow` metadata.
- Self-contained script, stylesheet and CSS font references within the preview directory.
- Absence of forms, embedded documents, inline event handlers and external navigation targets.
- Absence of the outgoing-request APIs it scans, and use of documentation IP ranges.
- Presence of focus-visible, reduced-motion, safe-area and responsive grid CSS.

The checker reports the remaining 7px and 10px source font sizes for manual review. They are the duplicate English brand wordmark and preview version decoration. Supporting mobile copy was raised to 12px; primary labels and actions are larger. This is a source assessment, not a computed-size or zoom acceptance test.

## Findings corrected during review

| Finding | Resolution observed in source |
|---|---|
| CSS referenced `assets/font/…`, while copied fonts were directly under `assets/` | Font URLs now resolve to the actual local assets. |
| Many mobile supporting labels used 6–10px sizes | Supporting labels raised; service information and actions reflow or stack on narrow screens. |
| Primary, muted and status text had insufficient representative light-theme contrast | Light palette darkened; dark primary buttons use dark text. Selected numerical checks are below. |
| Collapsed sidebar hid navigation text without accessible names | Navigation buttons now retain translated `aria-label` attributes. |
| Small icon targets, including the sidebar collapse control | Shell controls now provide larger targets; collapse retains its small visible chevron within a 44px hit area. |
| Windows detail clicks matched `data-view` before using `data-service` | Delegation now passes the selected service to `view()`. |
| JavaScript treated 801–900px as a drawer while CSS showed a desktop rail | JavaScript drawer breakpoint now matches CSS at 800px. |
| November 18 displayed as 18 Aban in Persian | Preview catalogue and initial HTML now use 27 Aban 1405. Relative offsets are pinned to the synthetic November 3 reference. |
| Persisted collapsed navigation did not synchronize the collapse button state on startup | Language/startup initialization now sets `aria-expanded` from the actual collapsed body state. |
| “Newest” sorting used reverse renewal dates | Cards now carry distinct `data-created` dates (Linux October 1; Windows November 1), and newest sorting uses those creation dates. |
| Navigation could clip at short viewport heights | A shrinking, vertically scrollable `.sidebar-nav-scroll` area now separates navigation from the fixed identity/support area. |

Representative sRGB contrast calculations, using the revised source colors:

| Pair | Ratio |
|---|---:|
| Light muted `#5d6d7c` / white | 5.32:1 |
| White / light primary `#00798e` | 5.09:1 |
| Amber `#90611d` / `#fff8e8` | 5.07:1 |
| Green `#197959` / `#edf8f2` | 4.93:1 |
| Dark primary text `#102c37` / `#54c3d4` | 7.05:1 |

These selected pairs do not constitute a complete rendered contrast audit.

## Isolation and data review

The preview contains synthetic account and service labels, `.example` domains, `192.0.2.x` IP addresses and illustrative amounts. No reference screenshot is bundled. No customer name, real IP, bank account, production credential, backend endpoint, session token or operational secret was found in the reviewed preview source.

Service data explicitly maps Linux to cPanel and Windows to Plesk. The Linux DNS fixture remains pending; its simulated check reports that setup is still required. The Windows connected fixture is explicitly labelled as sample data. Neither DNS check performs a lookup. Password, payment, renewal, provisioning and support actions open explanatory simulation UI; they do not perform live operations. Clipboard and namespaced localStorage are local browser interactions.

The reviewed tree added preview and documentation files without changing tracked application files. This review did not modify Laravel routes, controllers, permissions, business logic, migrations, billing, wallet, provisioning or production styles. The reviewer only authored this report and `check-preview.py`.

## Parent-supplied desktop browser evidence

The parent task explicitly reported the following managed-browser observations. The source reviewer did not repeat or independently perform these browser checks.

| Observed check | Reported result |
|---|---|
| English desktop viewport | 1363px overall viewport, including scrollbar; document client width 1348px and scroll width 1348px. No horizontal overflow in this measured state. |
| English desktop sidebar | Left 0px, right 230px. |
| English desktop main content | Left 230px, right 1348px, width 1118px. |
| Windows detail selection | Correct `north.example` service and Plesk control panel. |
| English translation rendering | No missing translation keys reported. |
| Initial Persian screenshot | Parent reported no horizontal overflow in the initial screenshot. No specific viewport measurement was supplied for this screenshot. |

These observations cover those specific rendered states only. Mobile browser QA was still in progress when this report was updated.

## Remaining separate browser acceptance

The parent task owns managed preview rendering and browser verification. Source inspection alone cannot verify:

- Actual overflow, full-width composition and text wrapping at 320/390px, tablet, desktop and ultrawide widths.
- Persian RTL versus English/Turkish LTR rendering, 200% zoom and long translations.
- Drawer Tab containment, Escape, focus restoration, breakpoint transitions and persisted collapsed state.
- Native dialog and select keyboard behavior, focus visibility, popover placement and reduced-motion behavior.
- Windows/Plesk and Linux/cPanel detail selection, copy fallback, filtering, sorting and simulated DNS feedback in the rendered app.
- Console errors, rendered contrast, loading behavior, performance or assistive technology behavior.

The two earlier startup/sort observations are corrected in the refreshed source. The structural checker and all three JavaScript syntax checks were rerun after these corrections, the font-path correction and the navigation wrapper, and passed with the same element/key counts above. No source-review blockers remain.

Production deployment, merge and the next major redesign phase remain outside this preview acceptance. Reverting the preview requires removing its isolated preview files and report; no application migration or production rollback is involved.
