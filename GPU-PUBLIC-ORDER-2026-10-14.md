# Public GPU ordering and allocation messaging — 2026-10-14

## Scope and behavior

The public `/gpu` page now selects the GPU, compatible ready-made app and service name, displays the hourly rate and minimum starting credit, and submits a short-lived intent before login. The selections survive the existing login/registration session regeneration. An authenticated continuation page submits a CSRF-protected POST to the existing checkout; GET requests never create services. Expired selections, changed rates, disabled plans/apps, wrong continuation tokens and account switches are rejected. Repeated submissions reuse the approved intent and existing checkout request key.

The checkout continues to register only an unpaid GPU waiting request. The existing scheduler, fresh capacity checks, price approval, credit requirements and readiness-based first charge remain authoritative. No provider create call was added. The public page creates one independent service per submission; the previous quantity control, which multiplied the display price without ordering multiple services, was removed.

The first hour of a confirmed capacity wait is displayed as “awaiting GPU allocation”. After one hour, a latest recorded no-capacity result displays the capacity-wait label and explanation. Unknown capacity remains unknown; credit and price approval states are never masked. Download progress still comes from the provider after allocation. One hour is a planning estimate, not a guaranteed delivery deadline.

The page includes app descriptions and twelve FAQs in Persian, English and Turkish: app selection, preparation, billing, starting credit, temporary storage, FastAPI/network access, VRAM, interruptions, changes, cancellation and independent services/video workloads. It does not promise arbitrary exposed ports or persistent disk.

## Live deployment and persistence

- Host/account: `core`, `servernetcloud`; application root `/home/servernetcloud/servernet_app`. Public asset root remains `/home/servernetcloud/public_html`; this change adds no external CSS asset.
- Routes added in each existing locale: public POST `/gpu/order`, authenticated GET and POST `/account/gpu/continue`. Existing domain/session routing is reused.
- Fifteen application files installed at **10:24:11 UTC**, after staging the patch against copies of the live files, PHP lint, checksum concurrency guards and a private backup. Blade compilation and reverse patch verification passed; route listing showed eighteen GPU routes across the three locales.
- Patch SHA-256: `34700053b182038d0aac629a1cb037475b447c85e037a1b9da99c01649aa8c03`.
- Backup: `/home/servernetcloud/gpu-public-order-before-20261014.tar.gz`; staging/pre-change hashes: `/home/servernetcloud/gpu-public-order-stage-20261014`; post-change manifest: `/home/servernetcloud/gpu-public-order-after-20261014.sha256`; log: `/home/servernetcloud/gpu-public-order-deploy-20261014.log`.
- Existing authenticated OPcache endpoint returned HTTP 200, reset=true, and cleared config/route/view/application caches at **10:24:58 UTC**. Deployment credentials were read only in memory and not recorded.
- Follow-up wording removed the initial public no-capacity conditional while preserving the interruption/temporary-disk explanation. The three translation files were patched with dry-run/reverse checks, a separate private backup `/home/servernetcloud/gpu-public-order-copy-before-20261014.tar.gz`, and successful lint. Patch SHA-256: `cdc470b802644f2201d58d6cd2cdbe4d738b01a8363c7a87d7a004234c3ec191`. Final reset at **10:31:35 UTC** returned HTTP 200/reset=true and the four cache clears. The main post-change manifest predates this copy-only patch.
- Coordinated with the concurrent Arvan deletion work: its four backend files were preserved. No VM/guest, NAT/firewall, DNS, VPN, port-forward, hostcron, scheduler configuration or routing-agent desired state changed. No manual queue/meter/provisioning run occurred.

## Validation and limitations

- PHP 8.4 related suite: **158 tests, 1,565 assertions, no failures**. Public form/FAQ suite after final credit-display change: 20 tests, 155 assertions, all passed.
- After merging the latest develop and final copy changes, all 44 public-page/order/capacity tests passed with 283 assertions. Live guest GET checks returned 200 for each localized public page with the app/name controls and twelve FAQs; the protected continuation correctly redirected a fresh guest to login.
- New tests cover the guest intended destination, authenticated continuation, zero invoices/debits/instances/provider purchases, duplicate submissions, posted-field tampering, stale prices, paused apps, expired intentions, wrong tokens, account switches, localized FAQ content and the first-hour message transition.
- Live UI showed three compatible apps for GTX 1650, only Ollama for GTX 1060, synchronized hourly/credit amounts, and twelve expandable FAQ entries. Jupyter selection worked. Responsive checks at 390px and 1366px showed no horizontal document overflow.
- No live authenticated order was submitted. Post-login service creation was exercised with a fake provider and isolated database; this release does not prove future provider availability or a one-hour delivery guarantee.

## Rollback

First compare current hashes/diffs and preserve any later changes. Reverse the follow-up copy patch before the main patch, using `git apply --reverse --check -p2`; do not blindly restore complete files if another deployment has edited them. The main backup contains the ten previously existing files; the five newly added files are GpuOrderController, three `lang/*/gpu.php` files and the continuation view. Removing those is safe only after removing their route references. Refresh Blade and the existing authenticated OPcache endpoint, then verify the public page and customer GPU status. No database migration or financial rollback is required.
