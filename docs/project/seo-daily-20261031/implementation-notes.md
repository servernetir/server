# AI token-usage planner discovery — 2026-10-31

Subtask status: implemented and locally verified; parent subsequently merged PR165 and applied the two views at2026-10-31T06:47:21Z. Overall release, CI, mobile acceptance and guarded rollback are recorded in [release-manifest.json](release-manifest.json). Statements about no operation by this subtask refer to the implementation/review author only.

Base: `7425ecf9e639d3a4f231d1f6f6ded46c35ec113f`, branch `codex/seo-weekly-20261031`, managed checkout `C:\Users\admin\.codex\worktrees\seo-indexing-products\servernet`.

## Evidence and scope

The existing model detail page includes `pages.ai.estimator`, whose `#usage-planner` section appears exactly when `$seo['tokenOperation'] && $state['quote']`. `AiModelPage` defines token operations as chat, embedding and rerank. The shared model card already computes the identical category predicate. Before this change, neither the shared card nor the model's in-page jump navigation linked to the planner. The public-code reviewer independently observed an existing calculator without a jump link on a public model page; that observation motivates discovery, not a pricing or availability change.

Two ordinary HTML links now expose that existing tool:

- Shared home/catalogue/related-model card: localized `lroute('ai.model', $m->slug).'#usage-planner'`, only for a token operation with a quote.
- Model jump navigation: `#usage-planner`, under the exact same predicate as the section it targets.

Both reuse `ai_experience.estimator`, already translated in fa/en/tr. Pending models with a quote retain the link because their calculator already exists; an availability/readiness condition would incorrectly hide that path. Media cards and models without a quote get no new planner link. Existing wrapping, focus styles and `scroll-margin-top` cover the new anchors, so no CSS or JavaScript was added.

## Files

| Path | SHA256 after this subtask |
|---|---|
| `website/resources/views/pages/ai/card.blade.php` | `ab05b448a6035c5d2b1bea03041998d43b0375de7575ebb1c98b416f369c880f` |
| `website/resources/views/pages/ai/model.blade.php` | `0b99d7b95e97c1afde701379af9882b60ad626706c0162f843c2c21bcfc37944` |
| `website/tests/Feature/AiUsagePlannerDiscoveryTest.php` | `e7aa044b460b2c8599e86d137308b6b9324bd75218113559ffdb0c8ee694873c` |

Raw Git blobs from the base were exported without text/newline conversion and match the parent's freshly observed live files exactly: card `d5382d1c84685ecbb732902b8f39de5b4b5e5decd7fc5522a303d43fc79274fc` (1,968 bytes), model `15f92c99462829df1c411b0ba9b11627f3d689ad280b30757d12ecbedc674f66` (11,444 bytes). No three-way reconciliation is needed for these two observed inputs; stop and recheck if either live hash changes before installation.

The only production-source edits are these two Blade sections. Controller/model/service, quote/rate math, eligibility, authentication, API endpoints, estimator inputs/results, translations, canonical/indexability, tracking and customer flows were not edited. Common project documents are parent-owned and were not edited by this subtask. Current unrelated `output/` artifacts were preserved.

## Local validation

Commands run from `website/` using isolated PHP 8.4, SQLite memory fixtures and blocked stray HTTP requests:

```powershell
& 'C:\Users\admin\.codex\.tmp\server-menu-php84\php.exe' vendor/phpunit/phpunit/phpunit tests/Feature/AiUsagePlannerDiscoveryTest.php --display-warnings --fail-on-warning --colors=never
& 'C:\Users\admin\.codex\.tmp\server-menu-php84\php.exe' vendor/phpunit/phpunit/phpunit tests/Feature/AiUsagePlannerDiscoveryTest.php tests/Feature/AiWorkspaceTest.php tests/Feature/AiModelSeoTest.php --display-warnings --fail-on-warning --colors=never
```

- New regression suite: **4 passed / 116 assertions**, 3.179 seconds; exact output `ai-planner-discovery-tests.txt`.
- Combined relevant suite: **29 total, 28 passed, 1 skipped / 1,105 assertions**, 8.943 seconds; exact output `ai-planner-regression-tests.txt`. This includes the same four new tests; do not add the two totals together. The existing Bria ready-contract test skips when its trusted video decoder is unavailable (`AiModelSeoTest.php` requires that prerequisite); skipped acceptance is not claimed successful.
- New cases follow home/catalogue links to the actual model section in all three languages, verify translated anchor text and one target, verify embedding/rerank input-only calculators, preserve pending-with-quote discovery, and reject missing-quote/media dead links. Fixtures assert the catalogue remains unchanged by public GETs and no AI call is made.
- The first authoring run caught adjacent `@endif@if` directives in the expanded one-line jump navigation. The edited sections were split into separate Blade lines; both runs reported above are after that repair. No failing version was deployed.
- `git diff --check` passed.
- Independent read-only review accepted the predicates, localized routes/anchors and existing CSS behavior. A structured local receipt is `ai-planner-test-receipt.json`.

## Limits and handoff

Parent subsequently installed the two views; this subtask performed exactly nine anonymous public GETs before and nine after the parent's release, covering fa/en/tr home/catalogue/one model with `?qa=1`. All eighteen responses were HTTP200/BYPASS. Each page retained one H1, self canonical, four hreflang and valid JSON-LD. Planner links increased from zero to six on home, ten on catalogue and seven on the chosen model detail, in each locale; all labels and destination locales were correct. The model calculator target remained present. Public rate displays, model IDs, pre-existing link signatures, H1, canonical, hreflang and JSON-LD were unchanged. Receipts: `ai-planner-public-before.json`, `ai-planner-public-after.json`, and definitive `ai-planner-public-comparison.json` (`accepted`, zero page/comparison failures, pins both snapshot hashes).

The initial script flagged unordered PowerShell dictionary key serialization for rates/hreflang. Exact retained field values were then compared offline with fixed key order; there was no actual price/alternate drift and no further HTTP request. Original snapshots were not rewritten. The script's comparator and display formatting have been corrected for repeatability, without rerunning either network phase. It refuses existing snapshot paths and never retries or follows redirects. Parent owns live browser/mobile acceptance; it is not claimed by this subtask.

No paid AI request, real purchase, session/account operation, price/availability mutation, GSC submission or analytics event was executed. HTTP/returned HTML does not establish mobile layout, Google indexing, ranking, traffic uplift or a calculation-quality improvement. Only the two Blade files are a production payload; tests and these notes are not.

Rollback is a scoped reversal of the two link additions, with the parent's live before/after guards. Preserve any newer unrelated edits. No database rollback or cache-wide flush is required by this change.
