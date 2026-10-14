# GPU visual experience — 2026-10-14

## Scope

The public GPU page now has original inline SVG artwork: a GPU illustration in the hero, chat/API imagery for Ollama, layered image artwork for ComfyUI and a notebook/chart for Jupyter. Each app has its own accent color in both the picker and the application guide. Finite entrance animations finish within 4.2 seconds; reduced-motion preferences disable animation and transitions. Artwork is decorative and hidden from assistive technology, with no external asset requests or simulated availability indicators.

The configurator has three numbered steps, a searchable GPU list and a persistent selected-model summary. Search filters only the visible list, preserves the user's selection and suppresses accidental form submission on Enter. Compatibility filtering, quoted prices, server-name validation, login continuation and the existing twelve FAQs remain intact. Added interface labels cover Persian, English and Turkish. The hero's delivery chip says automatic delivery instead of promising instant delivery.

## Files and validation

- Seven application files: `resources/views/pages/gpu.blade.php`, `lang/{fa,en,tr}/gpu.php`, and new partials `gpu-app-art.blade.php`, `gpu-compute-art.blade.php`, `gpu-visual-styles.blade.php` under `resources/views/partials/`.
- Existing public page/order suite: **20 tests, 155 assertions passed** against an isolated database with fake provider calls.
- Isolated visual export: three locales rendered successfully. Desktop 1366px and mobile 390px inspected; Persian and English had no document overflow. Search matches, no matches, preserved selection and the light theme were checked in the browser.
- No live order, invoice, debit, GPU allocation or paid server was created. No queue, provisioning or meter command was invoked. These checks do not establish live provider availability.

## Deployment state

Prepared patch SHA-256: `1f26b346e7e3f4dfc2fb3f13627f22d6a575f4df86f2a542cb079e51fa8d17ae`.

Published at **11:53:55 UTC** after the owner renewed the cPanel Terminal session. The seven-file install passed patch dry-run, translation lint, original-file checksum guards, reverse-patch verification and Blade compilation. The existing authenticated OPcache endpoint returned HTTP 200/reset=true at **11:54:09 UTC**, clearing config/route/view/application caches. No credentials were printed or recorded.

Target host: the existing ServerNet cPanel host. Application root: `/home/servernetcloud/servernet_app`. All changes are templates/translations within that root; no separate public asset upload is needed. No route, NAT/firewall, DNS, VPN, guest, host cron or routing-agent desired state changes are involved. The existing web OPcache reset mechanism must be used after deployment because FPM timestamp validation is disabled.

The one-shot deployment staged the patch against live copies, required the three new partials to be absent, checked original-file hashes immediately before installation, and made a private backup. It refuses to reuse staging/backup paths. Backup: `/home/servernetcloud/gpu-visual-before-20261014.tar.gz`; stage: `/home/servernetcloud/gpu-visual-stage-20261014`; post-install manifest: `/home/servernetcloud/gpu-visual-after-20261014.sha256`; log: `/home/servernetcloud/gpu-visual-deploy-20261014.log`.

Live Persian, English and Turkish pages all rendered the configurator, nine decorative app illustrations and twelve FAQ entries. English/Turkish delivery chips use the new automatic-delivery text. Searching 3090 returned the two matching live cards without changing the selected GTX 1650. The three compatible app choices and selected Jupyter rendered correctly at 390px with no horizontal overflow; desktop also had no overflow. The live visual proof is saved locally as `output/gpu-visual-live.png`, cropped to the public application-guide content to omit the customer impersonation header. No live submit action was used.

Source merged into `develop` through [PR67](https://github.com/servernetir/server/pull/67), merge `d679158b17431b5e92bafdf631f4837f02d7874f`; this deployment record follows that merge. No GitHub status checks were reported; the test evidence is from local isolated execution.

## Rollback

After a deployment, inspect current diffs and preserve any later concurrent edits. Reverse only this patch after `git apply --reverse --check -p2`; the private backup contains the four previously existing files. The three new partials can be removed only once the page no longer references them. Recompile Blade, reset web OPcache through the existing authenticated route, and check all three public GPU locales. No database or financial rollback is required.
