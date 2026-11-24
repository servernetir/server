# OpenRouter and DeepInfra economic selection — 24 November 2026

Owner scope: add OpenRouter token resale, compare equivalent models, expose the union in AI Gateway and Studio, keep the AI margin from pricing settings, and disable GapGPT. Production acceptance is recorded below only after execution.

## Behavior

- The existing `ai:sync-catalog` hourly schedule refreshes both official OpenRouter catalogues (chat and embeddings), alongside DeepInfra. No additional host cron or recurring inference call is installed.
- Token prices are converted from decimal strings to integer micro-USD per million, rounding upward. Versions append through PriceBook; removed/expired/unsupported models close. Manual disables and suspensions remain closed.
- DeepInfra publishes promotional fractions separately from base token rates. Those fractions apply once. Expiring discounts stop accepting requests at expiry until the next valid refresh restores a current tariff.
- Model identity uses an exact case-insensitive upstream/Hugging Face identifier. Different versions and provider variants are never matched by a fuzzy display name. Only DeepInfra/OpenRouter chat and embedding routes participate.
- Catalogue deduplication compares landed cost for equal quantities of input/output. Requests compare the bounded input/output quantities of that actual payload, including FX and funding overhead. There is no universal cheapest supplier when input/output rates cross. Model support, context/output caps, readiness, pauses and daily caps constrain eligibility.
- The original public model slug is frozen on usage/replay while supplier/model/price IDs describe the selected route. No supplier retry occurs after an ambiguous or paid outcome. OpenRouter uses price sorting, parameter support, disabled fallbacks, and per-token ceilings frozen from the usage snapshot. Client plugins and routing preferences cannot override them.
- A single `ai_uniform_margin` switch makes all ordinary quotes use `ai_margin_pct`. Existing birthday behavior is preserved where deployed. Funding overhead remains separate and can exceed 25% for OpenRouter (maximum 100%). Actual credit-purchase costs must be updated after funding conditions change.
- Studio reads the same `/v1/models?studio=1` catalogue. No Studio build or duplicate registry is needed. Upstream cost fields remain private; the lossless usage parser accepts OpenRouter's `usage.cost` for both JSON and SSE.
- Batch/free/dynamic aliases, media output, search-inclusive endpoints and unsupported metering are imported with explicit pending reasons, without offering an incompatible operation for sale.

## Validation

Local baseline and head were compared using PHP 8.4 in isolated checkouts with fake providers. An initially missing local bcmath module was identified by exercising full settlement; final results below supersede those preliminary runs. Final isolated regression comparison: base 176 tests (162 passed, 2 historical failures, 11 historical errors, 1 skipped); head 191 tests (177 passed, the same failures/errors/skip). No new failures. The new routing suite passed 16 tests / 65 assertions. A selected catalogue/admin/JSON/SSE/settlement suite passed 134 tests / 987 assertions. Historical errors are old FX fixture/override expectations and are not represented as a green full suite.

An offline replay of the live public feeds imported 514 rows: 353 executable contracts and 161 pending contracts. This is a dated schema acceptance, not evidence that each model was called or billed by the supplier.

## Release and rollback

The pinned release manifest contains 17 runtime files. Private backups, exact before/after hashes, an exclusive release lock, dependency-first installation, targeted invalidation in both observed same-account PHP-FPM pools, and compiled admin views follow the existing project release mechanism. AiCaller/AiPricing are patched against their observed live hashes, preserving unrelated birthday differences from develop. No migration, vendor replacement, general cache flush, resource provisioning, message, or customer balance adjustment is involved.

`setup.php` registers the authorized provider, disables GapGPT, enables economic selection/uniform margin, and backs up the prior non-secret configuration. It never reads or exports API keys. The operator enters the API key through the existing encrypted provider form. Rollback first disables OpenRouter/selection, then restores only matching files with `release.py rollback`; price and usage history remain. Configuration rollback must use the private backup and account for subsequent operator changes.

Status: implementation prepared; production installation and API-key validation pending.
