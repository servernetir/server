# انتشار مدل‌های رسانه‌ای AI — ۲۰۲۶-۱۰-۱۱

فروش زنده۳۱۲ مدل (DeepInfra۲۰۴، GapGPT۱۰۶، DeepSeek۲) و۷۸ مدل رسانه‌ای تازه تأیید شد. ۱۷ DeepInfra و۳۹ GapGPT هنوز بسته‌اند؛ تکمیل همه۱۳۴ مدل ادعا نمی‌شود.

# AI Gateway native operations — October 11, 2026

Production activation at **07:39:43 UTC**: **312 saleable models**, DeepInfra **204** (129 token-based +75 native), GapGPT **106** (103 chat +3 image), DeepSeek **2**. This supersedes the 234-model checkpoint. Authenticated HTTPS `/v1/models` returned HTTP200 and exactly312 entries at07:45 UTC. All78 native price/example builds in FA/EN/TR passed (234 builds); the read-only temporary key was revoked, synthetic wallet0 and open usage0.

The owner's latest instruction was representative text/audio/image/video testing, not paid tests of every model. **78 of the134 outstanding models are now enabled;56 remain unavailable.** A shared adapter does not establish a billing contract for every model. Seven DeepInfra video-input paths still need bounded input decoding, duration verification and admission code; these are implementation gaps, not claimed supplier outages. Other unsupported tariffs and prior GapGPT probe failures remain explicit below.

## Execution and money

`POST /v1/inference` requires an explicitly selected `ai:native` scope and a bound customer project. Text-generation, embeddings and rerank continue through their existing endpoints. DeepInfra uses `/v1/inference/{upstream_model}`; the three supported GapGPT image models use `/v1/images/generations`. Native usage is stored independently of token counts; no synthetic prompt/completion tokens are invented.

The versioned contract includes the full tariff fingerprint, documented schema/defaults, whitelisted options and a conservative cost bound. Public feed changes, stale capabilities, unknown units or missing contracts close admission. Premium tiers, callbacks, remote input fetching, unknown parameters, unbounded input/output and streaming on native endpoints are rejected. Images are bounded to one generated image and gateway dimensions; input is inline PNG/JPEG/WebP or a validated complete PCM WAV of at most60 seconds, total payload8MB. Nested reference media and video input remain unavailable.

Video/music return202 with a receipt; `GET /v1/inference/{receipt}` reports pending/final status, scoped to the customer and project. Execution continues in the existing PHP-FPM termination callback with a300-second supplier timeout, not a new durable queue. A process loss remains governed by existing unknown-outcome reconciliation. Concurrent/pending/final identical idempotent requests replay the same receipt/output with no second supplier call or debit. Encrypted final output and compact pricing snapshots avoid storing raw client media in usage rows.

**The global financial policy now preserves at least the configured5% markup against the already rounded cost ledger:** `sell = max(existing price-book amount, existing fractional floor, ceil(cost_irt * 105/100))`. It applies to native and existing token paths; VAT is added separately. Acquisition overhead remains the owner's explicit0%, not an inferred tax exemption. Very small whole-Toman requests can exceed5% effective markup; this is not an exact5% return on revenue. Example: actual cost1 Toman requires sale2, plus applicable VAT1, total3. Final Whisper cost824, sale866, VAT87, total953; no unpaid balance/review flag. Initial pre-fix image/video tests demonstrated that separate integer rounding could reduce the ledger markup below5%; those historical rows remain unchanged and the current formula fixes subsequent requests.

Reservations use the same rounded formula and a buffer; balance-aware text output uses an affordable-bound search rather than the old fractional estimate. Admission-time FX, VAT and tariffs are frozen. Provider-reported cost is settled when supported. For GapGPT's three documented image tariffs, cost derives from the published bounded tariff and `provider_cost_micro` staysNULL: no upstream receipt is fabricated. Qwen image2.1 is perMP, so fixed1024×1024 uses1.048576 MP, not a flat one-unit charge.

**Live ASR discrepancy:** DeepInfra Whisper4.125 seconds returned estimated billedUSD0.00309375, about100× the public tariff ofUSD0.00045/minute. The official response schema explicitly labels cost USD; we did not divide the receipt by100. The first call charged the actual cost and triggered `over_bound`/automatic suspension. The second call after repair settled3094 microUSD without a review flag. The eight ASR contracts reserve at100× their public tariff, recorded as `observed_asr_family_receipt_100x_public_tariff`; applying this to other ASR models is a conservative family inference, not separate paid verification. The public page identifies a unit ceiling; settlement charges the reported result cost, not that ceiling.

Current known-cost markup and observed invoices do not guarantee profit against new undisclosed provider taxes, changed tariffs, unexplained over-bound receipts or future currency acquisition fees. The earlier funding invoice had no surcharge; this release does not claim a new invoice reconciliation. Supplier changes require a fee/contract review; suspicious overages close the affected model. Owner-assumed reseller permission is preserved as an assumption, never described as a signed agreement.

## Representative live validation

Seven public paid gateway operations plus one direct GapGPT image preflight were performed. DeepInfra image: valid1024×1024 blue cube JPEG,200,1.816s; Kokoro voice: complete PCM WAV24kHz/4.125s,200,2.233s; FastWan video:202 then200,5.519s, validH.264832×480/16fps/5.06s, five decoded frames; GapGPT Flux image:200,12.81s, matching image; final Whisper transcription:200,16.583s, “Hello. This is a clear voice test for ServerNet.”; final Llama text:200,0.994s, “TEHRAN”. Video shows movement but has weaker prompt fidelity and visible artifacts; it was not judged equivalent to higher-quality models. Audio intelligibility is evidenced by the exact transcription, not a claimed subjective listening score.

Final replay checks, pending-video receipt replay, monthly budget402, missing scope403, revoked key401 and tenant isolation passed. Final synthetic project archived/customer suspended, keys revoked, wallet0, open usage0; financial/test history retained. Real bank/OTP checkout was not tested. Existing prior checkpoints include ordinary/SSE and embedding/reranking live tests, reused per the owner's request to avoid additional spend.

Paid test limit **USD5**. Conservative committed budget **USD2.07**, including twoUSD1 GapGPT reservations where no actual cost receipt is returned. Summed DeepInfra reported rounded costs plus documented GapGPT tariffs estimate **USD0.022037**, not an invoice or a proven total supplier charge. No per-model paid loop was resumed after the owner's instruction.

AI suite before the final one-line display compatibility correction: **297 tests /92,003 assertions**, isolated MariaDB with seven concurrency stress cases. The final native regression run: **13 tests /437 assertions**, including all78 bounded requests and their displayed unit prices. No full non-AI suite is claimed. Nineteen free public HTTPS checks passed:12 native model pages across3 languages,3 docs,3 catalogue pages and sitemap; oneH1, locale canonical, alternates, ready-only IRR offers and selected model sitemap links. All78 native price/example builds were also checked against production BrickMath at zero supplier calls. Ranking above competitors is not established by these technical checks.

## Production, persistence and rollback

Affected machine: core /cPanel Laravel application `/home/servernetcloud/servernet_app`, PHP8.4.26. Twenty-nine production paths deployed from a fresh snapshot with before/after hash guards and backups. Migration **2026_11_03_000150_ai_native_operations.php only** adds compact native snapshots/usage, nullable input-price reference and extends usage_source to32 characters. Existing request/settlement/catalogue refresh paths persist the change; no new host cron, credentials, VM/guest, routes/NAT/firewall/DNS, relay or country/port-forward agent state was changed. HTTP application routes add native POST/GET only. The route payload was merged with the fresh live version, preserving concurrent unrelated site features.

Private release root `/home/servernetcloud/ai-launch-20261011/native-v1`, release proofs/backups mode0600, directories0700. Ordered installation layers: `release`29 files; `hotfix` catalogue decimal normalization for production BrickMath; `price-fix` native ledger floor; `asr-fix` three paths with global rounded floor and observed ASR ceiling/snapshot basis; `display-fix` one AiNative path replacing optional-scale division with exact cent-to-USD multiplication. Authenticated web OPcache reset200/resettrue, view clearing and page-cache purge confirmed. The first activation upload was absent; a new upload completed100%, SHA256 matched, then activation succeeded. Do not infer installation from an uploader attempt or shell exit alone.

Rollback begins by inspecting live state and disabling only the78 affected model states under row locks using `release/native-activation-before.json`; preserve usage, calls and ledger. Inspect `release/native-activation-proof.json`, smoke/final/read-only proofs and each layer's manifest/receipt before replaying anything. Reverse files from `display-fix/before.php`, then `asr-fix/backup`, `price-fix` and `hotfix`, then `release/backup`, **only if each current hash matches the recorded after version**. New source files should be moved to a private rollback directory, not erase evidence. Concurrent edits require manual reconciliation. Clear compiled views, reset web OPcache and purge page cache, then verify availability and public pages. Never run migration down or restore the entire database; native financial history must survive. Rollback not executed.

The read-only check uses only a five-minute models-read test key and makes no inference call. Deployment/audit helpers have receipts and stop if previously started; inspect their evidence rather than rerunning blindly. The local Windows FFmpeg decoder was used to validate the returned sample; FFmpeg was not installed as a production input decoder.

## Remaining unavailable models

| Provider | Upstream model | Current blocker |
|---|---|---|
| deepinfra | `PrunaAI/p-video-avatar` | duration_contract_pending |
| deepinfra | `Bria/video_foreground_mask` | required_input_contract_pending, duration_contract_pending |
| deepinfra | `ByteDance/Seedance-2.0` | unbounded_or_missing_tariff, tier_contract_pending |
| deepinfra | `black-forest-labs/FLUX-2-pro` | cost_derived_image_contract_pending |
| deepinfra | `openai/clip-vit-large-patch14-336` | unbounded_or_missing_tariff |
| deepinfra | `black-forest-labs/FLUX-2-max` | cost_derived_image_contract_pending |
| deepinfra | `XiaomiMiMo/MiMo-V2.5-tts` | unbounded_or_missing_tariff |
| deepinfra | `Bria/video_remove_background` | required_input_contract_pending, duration_contract_pending |
| deepinfra | `Wan-AI/Wan2.7-R2V` | required_input_contract_pending |
| deepinfra | `openai/clip-vit-base-patch32` | unbounded_or_missing_tariff |
| deepinfra | `Bria/video_increase_resolution` | required_input_contract_pending, duration_contract_pending |
| deepinfra | `Bria/video_eraser` | required_input_contract_pending, duration_contract_pending |
| deepinfra | `XiaomiMiMo/MiMo-V2.5-tts-voicedesign` | unbounded_or_missing_tariff |
| deepinfra | `Bria/video_mask_by_key_points` | required_input_contract_pending, duration_contract_pending |
| deepinfra | `ByteDance/Seedance-1.5-Pro` | unbounded_or_missing_tariff |
| deepinfra | `Bria/video_mask_by_prompt` | required_input_contract_pending, duration_contract_pending |
| deepinfra | `XiaomiMiMo/MiMo-V2.5-tts-voiceclone` | unbounded_or_missing_tariff |
| gapgpt | `gpt-image-1-mini` | endpoint_or_metering_pending |
| gapgpt | `gpt-image-1` | endpoint_or_metering_pending |
| gapgpt | `k3` | model_unavailable |
| gapgpt | `deepseek-reasoner` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gpt-chat-latest` | model_unavailable |
| gapgpt | `glm-4v-plus` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gemini-3-pro-image-preview` | endpoint_or_metering_pending |
| gapgpt | `kimi-for-coding` | model_unavailable |
| gapgpt | `glm-4-airx` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `deepseek-v4-flash` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gpt-4o-mini-tts` | endpoint_or_metering_pending |
| gapgpt | `tts-1` | endpoint_or_metering_pending |
| gapgpt | `grok-4.3` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `glm-4-long` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `glm-4-air` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `qwen3.6-35b-a3b` | model_unavailable |
| gapgpt | `grok-4.5` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `text-moderation-latest` | endpoint_or_metering_pending |
| gapgpt | `kimi-2.7` | model_unavailable |
| gapgpt | `typesafe/jev-1.13` | endpoint_or_metering_pending |
| gapgpt | `qwen3-235b-a22b-instruct-2507` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gemini-2.5-pro-preview-tts` | endpoint_or_metering_pending |
| gapgpt | `grok-4` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `whisper-1` | endpoint_or_metering_pending |
| gapgpt | `gpt-5.6-luna-compaction` | model_unavailable |
| gapgpt | `gpt-image-2` | endpoint_or_metering_pending |
| gapgpt | `gpt-5.6-luna-openai-compact` | model_unavailable |
| gapgpt | `qwen3-235b-a22b` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gpt-5.3-codex-spark` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `glm-4-alltools` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `grok-4.6` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `babbage-002` | endpoint_or_metering_pending |
| gapgpt | `glm-4-plus` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gapgpt/whisper-1` | endpoint_or_metering_pending |
| gapgpt | `glm-3-turbo` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `davinci-002` | endpoint_or_metering_pending |
| gapgpt | `glm-4v` | prior execution/billing-bound probe failed; manual inactive gate retained |
| gapgpt | `gemini-2.5-flash-preview-tts` | endpoint_or_metering_pending |
| gapgpt | `glm-4-0520` | prior execution/billing-bound probe failed; manual inactive gate retained |

For inactive GapGPT chat models, the earlier bounded probe evidence (18 failures) remains authoritative; fresh feed presence alone does not override ignored output limits or supplier errors. Seven entries were missing from the authenticated discovery; four legacy/classification endpoints and ten remaining media billing contracts are unsupported. These39 entries are not silently enabled. DeepInfra's17 entries include runtime/zero/derived tariffs and input-duration/nested-media contracts; do not represent their reference pages as executable paid APIs.

Primary references: [DeepInfra native API](https://docs.deepinfra.com/apis/deepinfra-native), [Whisper public price](https://deepinfra.com/openai/whisper-large-v3), [Whisper API schema](https://api.deepinfra.com/models/openai/whisper-large-v3), [GapGPT public pricing](https://gapgpt.app/platform-v2/pricing). Sanitized evidence: `evidence/native-launch-2026-10-11.json`. No keys, passwords, authentication URLs or client authentication UUIDs are stored in this record.
