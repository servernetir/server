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

## Owner key entry and complete Studio catalogue — 24 November follow-up

After the owner entered the key, the official authenticated `/auth/key` check returned `verified`, without inference or returning account/credential data. The native hourly cycle recorded DeepInfra at 07:00:08 UTC, OpenRouter at 07:00:10 and routing at 07:00:11. The last routing summary preceded key entry and therefore still described 205 DeepInfra entries; a fresh live calculation after key entry produced 476 eligible entries (279 OpenRouter, 195 DeepInfra, two direct DeepSeek). No redundant import was run. Margin remained 7%, OpenRouter funding overhead 3,390 bp, and GapGPT remained disabled. The retained tracker had no OpenRouter/DeepInfra errors. For 83 active, eligible shared identities, independent equal-quantity cost comparison found no selection/margin mismatches (73 DeepInfra, 10 OpenRouter); 204 bounded chat request preparations at three output sizes also found no route mismatches, without sending inference.

Review of the actual Studio consumer found that both its hosted proxy and frontend discarded embedding/rerank entries. The follow-up admits these to the model hub as API-only entries, adds the verified `/v1/embeddings` and `/v1/rerank` metadata and input-only taxed prices, and provides alphabetic name ordering and a localized category filter. It preserves distinct variant IDs and does not grant new scopes or offer unsupported chat/media actions.

Local validation: PHP 8.4, 32 relevant Gateway tests / 206 assertions; Studio Python 3.12, 129 tests; five Node tests including rendered FA/EN/TR catalogue checks; TypeScript/Vite production build. Provider calls are mocked. The live Studio page renders the sign-in screen; authenticated customer UI and paid serving are not claimed as accepted by these checks. Installation/activation evidence for this follow-up is recorded separately after execution.

Release preflight confirmed the registered Passenger application `ServerNetStudio` targets the existing private `servernet/` application. Live hosted proxy bytes match Studio base `d11102d490f33e46a850fb48a307f7d818403ac5`; the two observed Gateway runtime files also match the reviewed base. Changes are committed locally in both repositories. Automatic approval review rejected public GitHub branch publication because it required explicit publication authorization; no branch was published and this follow-up has not been installed. Next step after owner approval: create/review the two develop PRs, complete their checks, build a pinned narrow manifest with private backups, install new hashed assets before HTML, update the corresponding-source link, and activate only the two PHP files and the registered Studio app. Preserve prior assets for rollback, all credentials, manual disables/suspensions, margin and funding overhead. No migration or general cache flush is needed.


The owner explicitly approved publication to develop and installation on the main site after the initial approval stop. The narrow follow-up release is `scripts/studio-catalogue-union-20261124`: six runtime files, a pinned locally built Studio payload, private configuration/source-link backup, both existing FPM pools and only the registered Studio Passenger app. New hashed assets precede HTML and old assets remain for rollback. It does not change pricing settings, credentials, customer state, dependencies or migrations.

## Follow-up installed and production verified

- Core PR432 merged as `db2c7aacec600b974be96072602c8bfb4c9946d8`; recovery PR433 merged as `be38bdf3eb12d9f63fd714b606160d548051563e`. Studio PR13 merged as `50766e41098d0eff2bf1aa50a7c5611d58a465dc`. The pinned deployed core/release source is `a40895ac56354af8effd87fce4e0c162a08d8427`, with Studio source `b32821a00912e1920c8b2b2ea87a76ed84aca1d1`.
- The six-file manifest was prepared, installed and independently verified with matching hashes. Existing runtime files and the private source-link configuration have private backups. Both observed FPM pools accepted the targeted activation: 476 models, including all 50 embeddings and five rerank models with input-only pricing. No general cache flush occurred. Only the registered Studio Passenger app received a restart request.
- Public browser acceptance loaded the new `index-DRDqPPPB.js` and `index-tsmPX0xU.css` assets. The legal page retained its notices and linked the exact installed Studio source, confirming the restarted application read its new source-link setting. Authenticated customer UI and paid inference remain untested; no new key or customer financial write was used for acceptance.
- Core's nine original CI workflows and the recovery routing workflow succeeded. Studio's hosted and lint workflows passed. Its general workflow-guard job had eight existing failures: an isolated comparison of the five implicated suites produced exactly eight failures and 232 passes on both base `d11102d` and the changed code. No new guard failure was introduced; a fully green upstream suite is not claimed. The review-requested retained-asset retry fix has a passing failure-injection unittest and a follow-up review with no findings.
- Live post-install settings retained 7% global margin, 3,390 bp OpenRouter overhead and all three GapGPT enable flags false. The native hourly catalogue timestamps were still recent, so no duplicate import was run. The stored 07:00 routing summary predates the owner's key entry; a fresh real catalogue computation and both FPM checks confirmed the 476-entry union.

The owner also requested a read-only GAPGPT comparison. Results, data dates and connectivity limits are in [the comparison report](AI-GAPGPT-OPENROUTER-COMPARISON-2026-11-24.md); this did not re-enable GapGPT.
