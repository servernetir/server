# Server order layout correction — 26 October 2026

Status: implementation and isolated visual acceptance complete; production installation pending.

## Scope and cause

The owner's screenshots show operating-system cards and explanatory text overlapping advanced SSH/hourly settings, plus a malformed check beside the ordered stage. The live server runs the cloud-store-experience workspace from branch codex/cloud-store-experience (92dbb06e), which is ahead of develop. Production templates and its dedicated stylesheet were inspected; no pending workspace redesign was imported into develop.

At desktop widths >=1001px and viewport heights >=700px the workspace fixes the form height and applies flex:1/min-height:0 and height:100% to every step and its body. Non-location content can overflow that reserved height into the advanced settings. The shared panel stylesheet now restores intrinsic height and normal block flow for steps other than location, with selectors that take precedence over the workspace stylesheet. The geographic viewport layout is preserved. Both shared checkmark pseudo-elements use the physical left border when drawing a fixed-angle shape, so RTL does not turn a check into a chevron.

Files changed: website/public/assets/css/panel.css and this record. No server provisioning, prices, balances, services, DB, routes, NAT/firewall, persistence agents or network configuration changed.

## Validation

A private local fixture uses the current production panel and workspace CSS and representative image cards/advanced hourly controls. Before the patch, at CSS viewport1315x805, the settings start at y364.78 while the OS note ends at y1014.38. Afterward settings start at y1029.38, below the note, and the step body grows from139.78px to804.38px. The computed check borders change from left+bottom in RTL to right+bottom, matching LTR. The final screenshot was visually inspected.

Ten desktop/tablet scenarios passed across actual CSS widths500,752,1008,1315,1904 in RTL/dark and LTR/light. Additional same-origin iframe fixtures at exact mobile widths320 and390 passed. Assertions cover cards before the note, note before settings, label/control separation and horizontal overflow. No real order/payment or provider API was used.

Existing PHP suites CloudServerPageLayoutTest, CloudStoreSlipTest and CssVariablesDefinedTest:26tests/194assertions,18pass/8fail. The same8failure names occur on the untouched baseline and patched tree. Six legacy slip assumptions and two existing CSS-variable/fallback audits fail; this change adds no failure. These are not reported as a passing full suite. The authenticated live customer page could not be operated because the browser environment fails at startup; visual acceptance is isolated, with production asset verification performed separately during installation.

## Production installation and rollback

The public document-root panel.css differs from app/public/panel.css by existing hourly-billing styles. Each file must be patched from its own fresh snapshot; never upload the whole repository CSS to the document root. Preserve all existing bytes outside the two checkmarks and appended layout rules. Back up both originals outside public_html, verify current hashes before upload, and verify post-upload contents and both public domains. Updating the app copy also advances asset_ver. CSS needs no PHP cache flush.

Rollback: only restore each corresponding original when its current hash still matches this release; otherwise merge the inverse patch to preserve concurrent changes. Retain both originals and the release receipt.
