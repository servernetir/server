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

Local baseline and head were compared using PHP 8.4 in isolated checkouts with fake providers. An initially missing local bcmath module was identified by exercising full settlement; final results below supersede those preliminary runs. Broader isolated regression comparison before the final access/response-redaction follow-ups: base 176 tests (162 passed, 2 historical failures, 11 historical errors, 1 skipped); head 191 tests (177 passed, the same failures/errors/skip). No new failures. The new routing suite passed 17 tests / 70 assertions. A selected catalogue/admin/JSON/SSE/settlement suite passed 135 tests / 992 assertions. Historical errors are old FX fixture/override expectations and are not represented as a green full suite.

An offline replay of the live public feeds imported 514 rows: 353 executable contracts and 161 pending contracts. This is a dated schema acceptance, not evidence that each model was called or billed by the supplier.

## Release and rollback

The pinned release manifest contains 17 runtime files. Private backups, exact before/after hashes, an exclusive release lock, dependency-first installation, targeted invalidation in both observed same-account PHP-FPM pools, and compiled admin views follow the existing project release mechanism. AiCaller/AiPricing are patched against their observed live hashes, preserving unrelated birthday differences from develop. No migration, vendor replacement, general cache flush, resource provisioning, message, or customer balance adjustment is involved.

`setup.php` registers the authorized provider, disables GapGPT, enables economic selection/uniform margin, and backs up the prior non-secret configuration. It never reads or exports API keys. The operator enters the API key through the existing encrypted provider form. Rollback first disables OpenRouter/selection, then restores only matching files with `release.py rollback`; price and usage history remain. Configuration rollback must use the private backup and account for subsequent operator changes.

## Production acceptance — 24 November 2026

Implementation PR427 merged as `78f408f78f21d194af7301b6a0b7ce2a40043cb5`; pinned runtime source `c779f7fe971d9488eb15ffe2185ac793d704a30f`. All eight workflows on that source succeeded. SQLite: 135 tests / 992 assertions; MariaDB: 17 tests / 70 assertions.

Seventeen runtime files were installed with matching hashes, private backups and targeted activation in both observed application PHP-FPM pools. Three admin views compiled; no general cache flush. Configuration setup retained the global 7% margin, set the owner-supplied OpenRouter funding overhead to 3,390 bp, disabled GapGPT and enabled economic selection/uniform ordinary margin. The hourly application scheduler already exists; no second cron was added.

Official production imports: OpenRouter 514 rows, 353 executable token contracts and 161 pending contracts; DeepInfra 414 rows (129 text/input-ready, 76 native-ready, 30 pending, 179 deprecated). These readiness counts do not override manual suspensions or commercial/access gates. Exact cross-provider identities: 121. A real DeepInfra 30% promotion produced input/output rates of 140,000/420,000 micro-USD per million, confirming that the separate discount was applied once.

The OpenRouter provider is ID5. The credential check returned `key_missing`; no paid inference was executed. The catalogue currently serves 205 eligible DeepInfra entries plus two direct DeepSeek entries. OpenRouter entries become customer-eligible after a valid key is entered in the existing encrypted provider form; authenticated OpenRouter inference and the final union in Studio remain pending that step.

Public model listings in FA/EN/TR returned HTTP 200 with source-rendered models and no supplier names. Unauthenticated `/v1/models` returned HTTP 401. The complete catalogue calculation was profiled at four database queries and approximately 1.47 seconds in CLI; public requests measured approximately 9.2–9.4 seconds including network/rendering, without a before/after latency baseline. No speed improvement is claimed.

A daily conditional health check was scheduled for 10:00 Asia/Tehran beginning 25 November. It checks catalogue freshness, economic routing, global margin and shared Studio availability, and notifies only on a material problem. Native price synchronization remains hourly.

Status: installed and production catalogue/configuration verified; OpenRouter API-key entry and authenticated serving acceptance pending.

