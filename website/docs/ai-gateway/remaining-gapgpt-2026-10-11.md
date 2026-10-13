# GapGPT remaining operations — 2026-10-11

This is an implementation checkpoint, not an activation receipt. Production deployment, authenticated discovery and representative paid outcomes are coordinated by the root release record. No paid upstream requests or production mutations were made while preparing this code. Read `native-launch-2026-10-11.md` and the final release evidence for the live counts.

## Seven additional operation candidates

`AiGapOperations` and `resources/content/gapgpt-operation-contracts.json` define seven operations. Their literal public GapGPT decimal tariffs are hashed; a changed or missing tariff rejects the contract before spending. Catalogue import preserves inactive/suspended states and does not activate a previously failed model. New legacy completions are classified as native operations so a chat-message endpoint is not mistaken for their prompt-only API.

| Model | Upstream operation | Published USD per1M input/output tokens | Reservation |
|---|---|---|---|
| `gpt-image-1` | `/v1/images/generations` |10 /40 | One1024×1024 image, **low quality only**; prompt≤4096 UTF-8 bytes,8192 output-token reserve |
| `gpt-image-1-mini` | `/v1/images/generations` |2 /4 | Same narrow generation contract |
| `gpt-image-2` | `/v1/images/generations` |8 /30 | Same narrow generation contract |
| `babbage-002` | `/v1/completions` |0.4 /0.4 | One prompt, one completion, `best_of=1`, output1–1024 tokens |
| `davinci-002` | `/v1/completions` |2 /2 | Same prompt-only contract |
| `gemini-2.5-flash-preview-tts` | `/v1/models/{model}:generateContent` |0.5 /10 | Entire official8192 input and16384 output model limits; maximumUSD0.167936 before gateway buffer |
| `gemini-2.5-pro-preview-tts` | `/v1/models/{model}:generateContent` |2 /40 | Same official model limits; maximumUSD0.671744 before gateway buffer |

The local customer operation is `POST /v1/inference`, scope `ai:native`; the response and replay remain in the existing encrypted native call/settlement flow. Speech exposes bounded `input`, a short built-in voice list and a requested32–2048 output-token cap, with **candidateCount=1**. The reservation still covers the entire documented model limit, rather than assuming that a requested short cap is a proven hard audio billing limit. Native user examples use these public customer fields, not the upstream nested content format.

**Hard versus inferred bounds:** Google's current2.5 TTS model pages explicitly publish8192 input/16384 output limits, no thinking and no caching. Their whole-model reservation has a documented limit. Legacy completions use a single literal UTF-8 prompt, byte-BPE input bound with256 tokens of reserve and an explicit output limit; one paid representative is still required to check GapGPT's actual translator. The fixed low-quality GPT Image reserve is a conservative inference, not a published whole-model maximum or provider spending guarantee. The official GPT Image1 legacy square table specifies272 low image tokens; GPT Image2's low square calculator estimates approximately400, but does not publish a hard maximum. Mini/2 token usage may differ. The additional4096 image input tokens are an explicit conservative allowance, not a documented GapGPT hidden-template limit. Medium/high/auto quality, automatic/large sizes, edits, remote images, streaming and extra generations are rejected. A representative result does not prove every future response stays within that inferred bound. Over-bound settlement/automatic model suspension remain essential.

The displayed one-call amount is a **maximum reservation/unit ceiling**, not an average image or speech price. Actual speech/image/completion settlement derives from the real returned token quantities and the frozen literal GapGPT tariff. Images accept a complete real `usage.input_tokens`/`output_tokens` pair or GapGPT's normalized `prompt_tokens`/`completion_tokens` pair. If both pairs exist they must agree exactly; partial pairs and contradictory zero placeholders are rejected. A reported `total_tokens` must equal their sum. Before delivery the parser strictly decodes the returned base64 PNG/JPEG/WebP and checks its actual1024×1024 metadata; a URL alone cannot establish these dimensions. Speech requires a nonempty audio part and consistent `usageMetadata`; billed output uses `totalTokenCount - promptTokenCount`, so thought/other output is not silently omitted or double-counted. Legacy completions require one nonempty text choice and actual prompt/completion usage. Missing quantities keep the existing unknown/reservation path; neither a binary file length nor the reservation is substituted as reported usage.

Derived prices are `cost_only=true`/`tariff_cost=true`, `usage_source=native_tariff`, with **provider_cost_micro=NULL**. Actual prompt/output quantities remain stored. This means the returned quantities and official rates support a known tariff calculation; it does not fabricate a provider invoice/receipt. Global rounded settlement preserves at least5% markup on recorded known cost, and VAT remains separate. Future unexplained fees, changed rates or uncollected over-bound credit cannot be described as unconditionally insured profit.

## The other32 entries from the original39

Seven identifiers were absent from the prior authenticated discovery: `k3`, `gpt-chat-latest`, `kimi-for-coding`, `qwen3.6-35b-a3b`, `kimi-2.7`, `gpt-5.6-luna-compaction`, `gpt-5.6-luna-openai-compact`. No similar-name alias is invented. A new authenticated discovery can establish availability; a public price-feed name alone cannot.

Eighteen chat entries retain their original inactive gates. Nine GLM routes returned401; five DeepSeek/Grok routes ignored the old output bound; four entries returned404/429/503 or later504. The driver now sends equal `max_tokens`/`max_completion_tokens` for the limited DeepSeek/Grok compatibility set, with disabled reasoning where supported. This is a translator repair to validate with one representative; it does **not** erase earlier failed probes or activate GLM/missing identifiers. The already verified GPT4 legacy chat exceptions continue sending only the completion-token field. Model-specific upstream credential failures must not be confused with permission to sell a model.

Seven remaining media/classification entries lack a reliable supplier-priced usage contract: `gemini-3-pro-image-preview`, `tts-1`, `gpt-4o-mini-tts`, `whisper-1`, `gapgpt/whisper-1`, `text-moderation-latest`, `typesafe/jev-1.13`. GapGPT's feed labels ASR/TTS prices as token prices, while its documented binary speech and transcription examples do not establish those priced token quantities. OpenAI's character/minute pricing is not silently substituted for GapGPT's contract. Gemini3 image preview's exact alias/model ceiling and GapGPT response metering require verification. Moderation/Jev zero-output/classification units are not guessed from an unverified endpoint. The manifest keeps these reasons visible and `supported=false`.

## Validation and state

The latest focused isolated PHP8.4.25/SQLite operation/recovery/privacy suite passes **17 tests/254 assertions**, including explicit candidateCount=1, normalized image aliases, dimensions and contradictory totals. Tests cover all seven hashed candidates/decimal tariffs, API route/body selection, measured token-cost settlement,5% rounded cost floor, separate VAT, one debit on replay, missing/contradictory usage, rejected remote/unbounded options and the equal chat cap fields. Encrypted retained responses use their frozen reservation tariff before any provider lookup; malformed/expired results remain unavailable. Late real usage refunds an earlier cap once, and a cost above the already collected cap is recorded as uncollected credit risk with model suspension rather than another late debit. Two hundred permanently malformed capped results cannot starve new unknown requests. Supplier `usage.cost`/`cost_details` are omitted from public delivery and replay. HTTP upstream calls are faked and the container network disabled. No real bank checkout, new account/key on a supplier, credential change or production activation is claimed by this checkpoint.

Root-coordinated live evidence subsequently showed a `gpt-image-1` response with a real image and normalized14 prompt/272 completion tokens. Its frozen10/40 tariff calculatesUSD0.011020; the upstream cost field reportedUSD0.010950. The tariff source remains explicit and the provider invoice field remainsNULL. Parser recovery can deliver and settle this existing response without generating another paid image. The tested Gemini Flash TTS translator returnedSTOP and8 prompt/55 audio output tokens in matching Google/OpenAI receipts, but an empty content-part list. Its exact `oai_chat`/`openai` receipt has unused input/output aliases0 and detailed text input8/audio output55. A narrowly scoped synthetic cleanup helper was prepared to settleUSD0.000554 and refund the earlier cap while preserving missing-output review, failed replay and the disabled model; it does not treat a billed empty result as successful speech delivery. GapGPT's own current model page advertises `/v1/audio/speech` while its generated Gemini TTS example uses `:generateContent`; an undocumented binary token quantity is not inferred from that contradiction.

The root's initial DeepSeek V4 Flash paired16-token request returned16 reasoning tokens and no final text. OpenRouter's primary V4 Flash0423 model page lists high/xhigh reasoning controls, so a request for none cannot be advertised as a proven disable control for this route. The root's subsequent bounded256-token sample delivered `OK`, with40 total completion tokens including39 reasoning tokens,USD0.000013 tariff cost, settled charge6IRT, zero uncollected cost, exact replay without another debit and a closed synthetic wallet. Final activation outcomes belong in the root release record.

## Nine GapGPT chat compatibility routes

This audit identifies exact local catalogue IDs and earlier causes. The compatibility branch includes two already verified GPT4 exceptions which send only `max_completion_tokens`; the other seven send equal `max_tokens`/`max_completion_tokens`. No new paid call or model-state mutation was performed for this audit.

| Local ID | Exact supplier ID | Evidence and status |
|---|---|---|
|387|`gpt-4-0613`|Earlier400 repaired by completion-token field; direct200/16-token proof and later public financial tests. Already active.|
|462|`gpt-4-turbo-2024-04-09`|Same verified GPT4 exception. Already active.|
|416|`deepseek-v4-flash`|Earlier96/156 completion tokens exceeded old16 cap; current paired-cap sample delivered `OK` in40≤256 tokens. Root has direct financial/replay proof.|
|396|`deepseek-reasoner`|Two earlier200 receipts:103/118 completion tokens exceeded16; prompt7 was within324. No separate HTTP denial/timeout found. Same DeepSeek compatibility branch and0.14/0.28 tariff as416; strongest candidate for representative family inference, but its current Gap backend alias is not separately established.|
|427|`grok-4.3`|Earlier200,114/122 completion tokens exceeded16; prompt195 and cached128 within324. Tariff1.25/2.5. Cap-only failure candidate, with a distinct xAI backend.|
|444|`grok-4.5`|Earlier200,18 completion tokens exceeded16; prompt209/cached128 within324. Tariff2/6. Cap-only failure candidate. xAI currently says reasoning cannot be disabled.|
|498|`grok-4.6`|Earlier200,72/121 completion tokens exceeded16; prompt209/cached128 within324. Tariff2/6. Cap-only failure candidate. xAI currently says reasoning cannot be disabled.|
|469|`grok-4`|Earlier1232 prompt tokens exceeded324; a later small successful probe was followed by504 on2026-10-11. Independent input-overhead/availability concerns exclude it from the cap-only group.|
|452|`qwen3-235b-a22b-instruct-2507`|Prior404. A matching generation field does not repair a missing/denied backend route.|

The four cap-only siblings396/427/444/498 have prior successful HTTP execution and known tariffs, and can be evaluated under the owner's representative-family instruction. Their new paired controls remain an **inference from the shared Gap transport and repaired416 sample**, not four new live verifications. No primary GapGPT documentation was found which guarantees uniform completion-token translation across their backend routes. A model-specific denial, missing route, inconsistent usage or over-bound cost must retain the existing separate failure/suspension handling. All output accounting includes reasoning; none is not marketed as a proven disable switch. Do not erase the original failed evidence or label these four individually tested when deciding activation.

The public note condition now covers the exact GapGPT DeepSeek Reasoner/V4 Flash and Grok4.3/4.5/4.6 routes. It uses the existing three-language explanation that reasoning and the answer share the output cap and a very short cap may leave no visible answer. Only `AiModelPage.php` changes in the preparedH3 production manifest, guarded againstH2's recorded AFTER hash; no charging or activation logic changes. Focused model SEO and billing-display verification passes16 tests/726 assertions. H3 deployment is coordinated by the root and is not claimed by this local preparation note.

Affected code is only the Laravel gateway implementation/catalogue/driver and local tests. No machine, guest, route/NAT/firewall/DNS, system cron or country/port-forward agent state changed. Final release rollback must disable only newly activated models first, preserve calls/usage/ledger and restore each file only under the root release's before/after hash guards. Do not replay deployment helpers without inspecting their existing receipts; no migration down is required by this helper.

## Final verified GapGPT result

The root's actual `remaining-activation-proof.json` and `final-state-v2-proof.json` finished successfully on2026-10-11 at14:01:29+03:30. They supersede the candidate/inactive checkpoint descriptions above: **eight of the original39 GapGPT entries are now ready;31 remain closed**. All eight have500bp markup and0bp acquisition surcharge. The complete authenticated catalogue contains332 ready models, including20 newly activated across both suppliers.

| Ready GapGPT models | Actual verification basis |
|---|---|
| `deepseek-v4-flash` | Meaningful bounded final text, customer HTTP execution and replay in the earlier direct sample. |
| `deepseek-reasoner`, `grok-4.3`, `gpt-image-1-mini` | Final v9 direct customer HTTP samples delivered meaningful text or a valid1024×1024 image; replay produced no additional debit. |
| `gpt-image-1` | Existing real1024×1024 PNG recovered through native-result and cached replay services, without another paid generation. The11020µUSD tariff settlement refunded the earlier cap and preserved its `unknown_charged`/`recovered_refund` review history. |
| `gpt-image-2` | Representative image-family activation using the recovered image1 sample and its separately checked tariff/contract; no separate paid image2 sample. |
| `grok-4.5`, `grok-4.6` | Representative activation from the meaningful Grok4.3 sample plus each exact pair of earlier HTTP200 output-cap-only receipts. No new individual paid samples; supplier denials, timeouts and input-cap failures were excluded from this group. |

`davinci-002` remains closed after the latest502 upstream failure released its hold without a charge; `babbage-002` remains closed after429. Gemini TTS still lacks delivered audio. Its synthetic Flash receipt was settled at the actual554µUSD tariff cost, with provider invoice costNULL, while missing-output review, failed-call state and the disabled model were retained. None of these failures is counted as a successful model launch. Image reservations remain conservative inferred bounds; the known-cost5% markup, separate VAT and over-bound review/suspension protections remain applicable.

The final read-only catalogue check returned200, then401 after key revocation. All eight historical synthetic identities were verified closed or intentionally never created; wallets and open usage were zero, and existing financial audit history was unchanged. The fresh verification account was also closed without funding or inference. The cumulative supplier reservation/commitment stayed **USD4.639 of the USD5 ceiling**; final verification added no supplier inference or payment. Installed release/H1/H2/H3/H4 file hashes passed the same final proof.

## Fresh continuation audit of22 text/discovery blockers

The root's subsequent `remaining36-read-v1-proof.json` obtained an authenticated GapGPT discovery HTTP200 with145 exact identifiers. Provider enabled/commercial/live flags, the encrypted key's presence and the fixed HTTPS `api.gapgpt.app/v1` base were correct. The same seven identifiers listed above remain absent even though the fresh public145-row price feed contains them. Discovery's seven additional names do not establish aliases for those missing entries; no replacement identifier is substituted. This diagnostic made no inference, payment or database mutation and preserved the cumulativeUSD4.639 guard.

The nine exact GLM routes remain `glm-3-turbo`, `glm-4-0520`, `glm-4-air`, `glm-4-airx`, `glm-4-alltools`, `glm-4-long`, `glm-4-plus`, `glm-4v`, `glm-4v-plus`. They are discoverable with complete prices, but their prior inference401 receipts remain backend-access failures, distinct from the working account discovery key. Current official Gap examples already match the driver's Bearer authorization and `/v1/chat/completions`; no alternate auth header or model-info/account GET for that API key was documented. Website `quota/tokens` uses its own authenticated account session and is not treated as an API-key inspection endpoint.

The other six exact routes retain independent causes: `qwen3-235b-a22b` previously503; `qwen3-235b-a22b-instruct-2507` previously404; `gpt-5.3-codex-spark` previously429; `grok-4` both an excessive1232-token hidden prompt and a later504; Babbage429 and Davinci502 as recorded above. A free discovery result does not erase those failed inference receipts. The fresh representative results below update only GLM Air and the Qwen base model; the other exact routes retain their historical evidence and inactive gates.

The generic Gap price-dialog text example suggests chat for all text rows, including Babbage/Davinci. It is synthesized from `model_type`, rather than an exact legacy-backend contract. Official OpenAI documentation explicitly specifies prompt-based legacy Completions for those base models. Its current Babbage page labels16384 as maximum **output**, which must not be repurposed as a published input/template bound. Likewise, no current Gap documentation establishes a maximum hidden prefix for its literal `grok-4`; changing the existing allowance to match one observed prompt would remain an inference. No shared driver, caller, credential, billing or model-state change was made by this continuation audit. The reviewed public source snapshots and22 exact target records are retained in private `gap-text-doc-audit-v1.json`.

### Actual representative text checkpoint

The root's `final36-chat-v1-proof.json` finished at2026-10-11T11:10:16Z, SHA256 `c1c502e5879934f7e9617464c5f010a897fa800e7765bf792721623e0e673b46`. Two customer API samples were sent using the unchanged documented chat endpoint; **neither delivered text or passed**.

| Exact model | Frozen input/output allowances | Actual result and gate |
|---|---|---|
|438 `glm-4-air`|352 /64 tokens; prepared tariff bound57µUSD|Supplier401 became customer503 `model_suspended`. Its hold was released and its original disabled state restored; the new model-specific suspension was preserved.|
|486 `qwen3-235b-a22b`|352 /512 tokens; prepared tariff bound303µUSD|Supplier503 became customer502 `upstream_error`. Its hold was released; it remains disabled without a suspension.|

Both usage rows are `released`, with zero customer debit, zero uncollected charge and no reported supplier token/cost receipt. The missing receipt is not replaced with the reservation or a zero-valued provider invoice. Cleanup verified the revoked key returns401, the customer is suspended, the project archived, wallet/hold/open usage all zero and both audit rows retained. At completion of this chat checkpoint the conservative cumulative supplier commitment was **USD4.649 of the same USD5 ceiling**, including the two5000µUSD sample allocations; this is a spending guard, not a claim that an error response incurred that exact provider charge. The later Jev checkpoint below updates the cumulative guard toUSD4.659. No GLM/Qwen family activation or additional paid retry follows these failed samples. All22 text/discovery blockers remain closed at this checkpoint.

### Full local regression checkpoint

The frozen implementation, including the separately prepared Jev decision operation, passed the full isolated AI suite: **SQLite355 tests/93079 assertions**, with seven deliberate MariaDB-only stress skips; **isolated MariaDB355 tests/93123 assertions**, with zero skips. Both suites had zero failures/errors. The35 configured test classes preserve the prior `V1ChatTest` integration cases and include six new decision-operation cases. This full checkpoint supersedes the earlier focused test counts for current integration validation while retaining those historical logs.

The tests verify the exact `/systemone` route, positive and negative recovery against the frozen requested answer schema, output-token price zero restricted to the exact Jev contract, measured tariff settlement, zero extra debit on replay and valid public examples in all three languages. All1527 pinned source/fixture files were unchanged before and after execution; the actual deployed route/ErrorTracker overlays and a real private FFmpeg decoder were included. SQLite had no network, and MariaDB used a new internal network with no published ports, temporary credentials and an isolated temporary database. Its containers/network were removed after success. No supplier inference, production database change or payment was performed by these local tests.

The immutable local proof is `final36-full-test-proof.json`, SHA256 `f431d21a2b98fb734faaf55a6863c39ce7e2f67e269b97bbe6033a7dde83d608`; source manifest SHA256 `4cd03ba841049ab2bea2e3a6a51d63c78d805c7141540b8f06f573c9d32dbb24`. These tests validate gateway and ledger behavior; they do not establish supplier backend entitlement or count Jev as live-ready before its own representative result.

### Actual Jev execution, activation and final catalogue

The root's subsequent `final36-jev-v1-proof.json` passed at2026-10-11T14:47:56+03:30, SHA256 `a75e4622f314b3ccd9d9019e8b41a3d2436402e9a51e533f412a1b63838eec5b`. Exact model450 `typesafe/jev-1.13` used the documented `/v1/systemone` operation and returned a valid requested `noul` answer at probability0.99. Actual usage was293 input/20 output tokens. Its frozen0.042USD-per-million input and free-output tariff settled13µUSD (`native_tariff`, provider invoice costNULL):4IRT cost,5IRT sale,1IRT VAT and6IRT total charge, with no uncollected charge or review flag. Cached replay returned the same body and created no additional debit, usage or ledger entry. The synthetic key was revoked, customer suspended, project archived and wallet/hold/open usage were zero.

After this single10000µUSD sample allocation, the unchanged cumulative ceiling is **USD4.659 committed of USD5**. The root's actual `activate-final36-jev-v2-proof.json` then passed at2026-10-11T15:13:15+03:30, SHA256 `41d63b340a2005f3f66ee23896b8e03929e0d9e70f18b396ef00991813279251`: exact model450 is active and ready, its financial audit unchanged and no additional inference or payment made by activation.

The subsequent `final36-state-v2-proof.json` passed at2026-10-11T15:13:44+03:30, SHA256 `910feeda7f34dff5962a91185540566462b38c330f733a7ea3f888ad44da67c7`. The complete authenticated catalogue now has **333 ready models**:115 GapGPT (103 chat,5 embedding,7 native),216 DeepInfra and2 direct DeepSeek. Of the exact original56 remaining models, **21 are now ready and35 remain closed**:12 of17 DeepInfra and9 of39 GapGPT are ready. The thirty still-closed GapGPT entries are the22 text/discovery routes and eight media/operation entries; the five remaining DeepInfra entries are outside this GapGPT note. This final checkpoint supersedes the prior eight-ready GapGPT count without changing its historical evidence.

The unfunded read-only customer catalogue check returned200 with the expected model list; its revoked key then returned401. Eleven prior synthetic identities were verified closed or intentionally never created, their wallets/open usage zero and historical financial audit unchanged. The new read-only account was closed with zero wallet, usage and ledger entries. Final verification made no supplier inference or payment and preserved theUSD4.659 commitment. All22 text/discovery models remain closed, and the failed chat receipt SHA above remains their actual fresh checkpoint; no additional paid retry or family activation is implied.

## Primary references

- GapGPT price and model documentation UI: <https://gapgpt.app/platform-v2/pricing>
- GapGPT literal public price feed: <https://gapgpt.app/api/v1/pricing>
- OpenAI Images endpoint and real usage schema: <https://developers.openai.com/api/reference/resources/images/methods/generate>
- OpenAI image output-token table/calculator: <https://developers.openai.com/api/docs/guides/image-generation>
- OpenAI legacy completions body/usage: <https://developers.openai.com/api/reference/resources/completions/methods/create>
- OpenAI Babbage002 legacy model and maximum output: <https://developers.openai.com/api/docs/models/babbage-002>
- OpenAI Davinci002 legacy model: <https://developers.openai.com/api/docs/models/davinci-002>
- GPT Image1 model/deprecation information: <https://developers.openai.com/api/docs/models/gpt-image-1>
- GPT Image1 Mini model: <https://developers.openai.com/api/docs/models/gpt-image-1-mini>
- GPT Image2 model: <https://developers.openai.com/api/docs/models/gpt-image-2>
- Gemini2.5 Flash TTS hard model limits: <https://ai.google.dev/gemini-api/docs/models/gemini-2.5-flash-preview-tts>
- Gemini2.5 Pro TTS hard model limits: <https://ai.google.dev/gemini-api/docs/models/gemini-2.5-pro-preview-tts>
- Google generateContent/usageMetadata/candidateCount: <https://ai.google.dev/api/generate-content>
- OpenRouter DeepSeek V4 Flash0423 reasoning controls: <https://openrouter.ai/deepseek/deepseek-v4-flash/api>
- DeepSeek official thinking-mode controls: <https://api-docs.deepseek.com/guides/thinking_mode/>
- xAI reasoning-mode limitations: <https://docs.x.ai/developers/model-capabilities/text/reasoning>

No key, password, complete authentication URL or client authentication UUID is recorded here.
