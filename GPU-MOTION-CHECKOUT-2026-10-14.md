# GPU motion and checkout verification — 2026-10-14

## Change and publication

This supersedes the finite-animation behavior described in GPU-VISUAL-EXPERIENCE-2026-10-14.md. The three app illustrations and hero GPU now use continuous, gentle SVG animation: staggered floating accents, moving signal paths and Ollama reply dots. The page has a localized pause/play control, pauses when the document is hidden, and disables motion for reduced-motion preferences. Without JavaScript, illustrations remain paused. No external animation library or image request was added.

The GPU section previously computed 110px of top padding on the live page. Its scoped override now uses 28px on desktop and 20px on mobile, preserving the separate fixed-header compensation. Other product pages and shared CSS were not modified.

Six display files were installed on the existing cPanel `core` host under `/home/servernetcloud/servernet_app` at **12:15:54 UTC**: the GPU page, app-art/styles partials and three `lang/*/gpu.php` files. Live originals were copied into staging, patch dry-run and PHP lint passed, original SHA-256 guards were checked before installation, and a private backup was taken. Reverse-patch verification and Blade compilation succeeded. The existing authenticated OPcache endpoint returned HTTP 200/reset=true at **12:16:22 UTC**, with the usual config/route/view/application cache clears.

- Patch SHA-256: `ec86503a51f57d0595ead3cda8f9d8ac4082cbb7d9e85d238107c7ed75d411f4`.
- Backup: `/home/servernetcloud/gpu-motion-before-20261014.tar.gz`.
- Staging/original hashes: `/home/servernetcloud/gpu-motion-stage-20261014`.
- Installed hashes: `/home/servernetcloud/gpu-motion-after-20261014.sha256`.
- Deployment log: `/home/servernetcloud/gpu-motion-deploy-20261014.log`.

## Checkout evidence and limits

Added three integration cases, one per locale, exercising the actual OTP login controller instead of `actingAs()`. Each uses an isolated database, a fake SMS sender and strict fake provider HTTP; unexpected provider requests are rejected. The customer has sufficient test credit. The test chooses a non-default RTX 3090 plan and Jupyter, submits the public form, follows login and code entry, rejects a wrong code without losing the intent, and completes valid login with session regeneration. It then submits the continuation form, checks the customer detail/status routes, runs the actual capacity command against the isolated database, and repeats submission.

Assertions establish preservation of plan, app, name, approved hourly rate and request key; exactly one unpaid `gpu_wait` service; unchanged total and available wallet balances; no invoice, debit, cloud instance or container API call. The browser continuation script is rendered and inspected; the test submits its form through the application HTTP test client. This is not a live paid-order or SMS-delivery test.

The expanded suite initially exposed a stale quote test: the October 13 capacity-intent change intentionally stopped using `is_high_demand` as a pricing veto, but the old test still expected it. Updated that test to verify that high demand can be quoted while zero exact capacity remains unavailable, with no financial/instance effects. No provider or billing implementation was changed.

Final relevant suite: **76 tests / 663 assertions passed**, including public orders, page rendering, OTP login, capacity/quote logic, schedule/audit and fixed-header behavior. The three-locale visual export also passed (1 test / 3 assertions). Browser inspection verified continuous running motion, pause/resume, reduced-motion override, desktop 28px and mobile 20px spacing, and no horizontal overflow. Live desktop computed infinite motion and pause/resume were confirmed after reset.

Read-only production inspection showed shared session domain `.servernet.cloud`, secure cookies and SameSite=lax. Natural capacity queue runs at **12:15:17** and **12:16:03 UTC** both recorded `status=ok`, two existing candidates and two waiting outcomes. No production queue command was invoked and those existing orders were not modified by this task. This evidence confirms the scheduler ran; it does not prove capacity availability or eventual delivery of those orders.

No live order was submitted because the normal queue may automatically allocate when capacity returns. No paid resource, invoice or debit was created by this work. No guest, route, NAT/firewall, DNS, VPN, host cron, scheduler configuration or routing-agent desired state changed. No credentials, OTPs or customer identifiers are recorded here.

## Rollback

Inspect current diffs and preserve later edits. Reverse only the six-file motion patch after `git apply --reverse --check -p2`; the backup contains all six pre-change files. Do not blindly rerun the deployment helper: existing stage/backup paths intentionally stop it. Recompile Blade and use the existing authenticated web OPcache reset, then verify the public GPU page. There is no migration or financial rollback.
