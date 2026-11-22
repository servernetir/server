# Ollama official delivery — 22 November 2026

Production installed and live acceptance passed. New Ollama orders use `ollama/ollama:0.40.17`; the customer's stopped service 982 is configured for that version. Ticket TK-261121-4436 / 359 reported HTTP 412 pulling `qwen3.5:4b` on the old 0.24.0 image.

## Delivery and continuing updates

The hourly `cloud:ollama-refresh` command checks the latest official non-draft, non-prerelease GitHub release and an active Linux/amd64 Docker image. The verified version and observed digest are stored atomically with the Ollama catalog. Checkout uses local verified metadata, without registry requests. Source or registry failures retain the current valid release; discovery never downgrades, silently replaces an approved tag's digest, unpauses sales or restarts existing customer groups.

Salad's actual create API rejected both `repository:tag@sha256:digest` and `repository@sha256:digest` with `container_image_name_invalid`. Delivery therefore uses the verified explicit version tag. The digest remains audit metadata, not a provider-enforced immutable reference. Real creation with `ollama/ollama:0.40.17` succeeded.

Queued legacy Ollama selections normalize at new creation. Other applications are unaffected. Each new container must load the default `llama3.1` model and show nonzero GPU offload before readiness; CPU-only fallback cannot declare it ready. Metadata discovery is not a complete GPU/model regression test for every future upstream release.

Runtime scope:
- `app/Services/Cloud/SaladOllamaImage.php`
- `app/Services/Cloud/SaladOperations.php`
- `app/Console/Commands/RefreshOllamaRelease.php`
- the scoped hourly entry in `routes/console.php`

## Source and production receipt

PR [372](https://github.com/servernetir/server/pull/372) installed the delivery, scheduler and guarded release. Source `b229dcf8ea269a4890f760698823db374ff09a7f` was deployed with private backups, exact source hashes, shared deployment locks and preservation of independent live schedule changes. PR [376](https://github.com/servernetir/server/pull/376), source `afc603e2711a27c0c92ada72d60bc856c547fa50`, corrected the provider image syntax. Its single runtime class was installed with an exact precondition and a separate private backup.

Both verified application FPM pools returned version 0.40.17 and reference `ollama/ollama:0.40.17` after targeted OPcache invalidation. The temporary primary was HTTP-denied and removed exactly; no general cache flush or root FPM configuration access was used. The scoped catalog refresh succeeded.

CI passed the 79 relevant PHP tests / 337 assertions, PHP lint, five real shell-process startup tests, three activation tests and 13 generic deployment tests. The final compatibility PR had all 17 reported checks successful before merge. Local PHP was unavailable; PHP verification ran in isolated CI and production lint, never against production data with PHPUnit.

## Live owner-account acceptance

One owner-account service, 985, was created through the ordinary GPU intent/provisioning and charging path. A private bounded stop guard was armed first. Rejected image-format attempts created no provider group; only the successful version-tag request allocated a machine.

Acceptance completed at 16:34:40 Tehran time:
- Actual provider image: `ollama/ollama:0.40.17`.
- Normal branded HTTPS endpoint with existing customer authentication: `/api/version` returned 0.40.17.
- Full streaming pull of `qwen3.5:4b` reached terminal success, without HTTP 412.
- Non-streaming generation completed and returned `OK`.
- Streaming generation completed with nonempty output.
- `/api/ps` reported `qwen3.5:4b` with 4,220,549,856 bytes in VRAM; all its reported model allocation was in VRAM.

At 16:35:32 Tehran time the test group was confirmed stopped, with no instances. The test service was closed, the verified off observation recorded and pending charges stopped. The actual owner wallet debit for this test service was 80,900 IRT. This is the platform charge, not an upstream invoice amount; the provider's final billed amount was not available from the API.

## Customer service 982

After acceptance, the customer's group was patched under its normal power-operation lock while it was stopped with zero instances. Only the official image, startup command, Ollama environment and readiness probe were changed. Immediate read-after-write validation was inconclusive; a fresh read confirmed the image and command, stopped state and empty instance list. No second patch or customer start was needed.

Final verification at 16:36:27 Tehran time confirmed version reference 0.40.17, the existing group identity and gateway hostname, stopped state, zero instances and zero new customer hourly charges during this work. The same endpoint and authentication remain applicable. The customer has not been started for testing: the configured image takes effect on the next start, with normal model download/preparation.

No public ticket reply has been sent in this follow-up. Suggested reply:

> سلام و وقت بخیر. نسخهٔ Ollama سرویس شما به ۰٫۴۰٫۱۷ ارتقا داده شد. دانلود مدل qwen3.5:4b و تولید پاسخ با استفاده از GPU روی نسخهٔ جدید با موفقیت آزمایش شد. سرویس شما در وضعیت خاموش باقی مانده است؛ می‌توانید آن را از پنل روشن کنید و پس از آماده‌شدن، با همان آدرس و توکن قبلی مدل را دریافت و استفاده کنید. بابت تأخیر در رفع مشکل پوزش می‌خواهیم.

## Rollback

Restore only the guarded runtime files from their matching private backup, the captured Ollama catalog/settings snapshot and targeted FPM activation. Preserve later unrelated schedule changes. A code rollback does not restore ephemeral model disks or remove a provider instance. Any customer-group rollback requires its matching private pre-upgrade snapshot and a fresh stopped/empty check under the same power lock.
