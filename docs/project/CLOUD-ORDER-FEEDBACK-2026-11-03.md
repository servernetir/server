# Expected cloud capacity failures — 3 November 2026

Base: `da62f84f7b73adf3ebb58e0aa117be6d9e50d0ed`, branch `develop`.

Customer request: a courteous, actionable capacity message and another-server selection, committed to GitHub and deployed to the main site.

The customer details and server cards previously rendered a definitive `provision_status=failed` as continuing build progress. A presentation-only classifier now exposes `capacity` for an explicit `[resource_unavailable]` rejection without a resource reference. It exposes `review` for ambiguous errors and existing receipts; these do not invite another purchase. Delivered and closed services retain their existing presentation. No retry, cancellation, refund, pricing, provider, database schema or scheduler behavior is changed.

The shared card offers a language-aware catalogue link and preserves hourly billing mode. Persian, English and Turkish text describes changing capacity without claiming knowledge of another buyer. Raw infrastructure errors remain private. Status polling reloads on issue-code changes, including recovery, while existing ownership checks remain in place.

Runtime manifest: `app/Services/Cloud/CloudOrderIssue.php`, `app/Http/Controllers/Account/CloudServerController.php`, `resources/views/account/cloud-server.blade.php`, `resources/views/account/partials/{cloud-order-issue,card-server,status-pill}.blade.php`, `lang/{fa,en,tr}/cloud-order.php`, `public/assets/css/cloud-order-issue.css`. Public CSS also needs the independently served `public_html/assets/css/` copy.

Validation: pending execution of the dedicated workflow. The feature suite uses synthetic resources only and covers three-language details/list/status, ownership, closed/delivered/pending states, ambiguous failures, existing receipts, privacy and failure/recovery transitions. Chromium checks use actual server-rendered synthetic pages in two themes and mobile/desktop viewports, plus the actual polling function.

Deployment: pending authorized cPanel access and current-file reconciliation. Only the listed runtime files may be installed, with private backups, before/after SHA-256 guards and a deployment lock. Preserve concurrent production changes; invalidate only affected compiled views if required. Rollback restores only this release's destinations after verifying that no later change has superseded them.
