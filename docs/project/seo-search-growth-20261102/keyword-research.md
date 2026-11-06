# Search growth research — 2 November 2026

Status: research only. No application edits, Git mutation, CMS write, publication, purchase, Google submission, account interaction or financial change.

Base inspected: `d3be9c422fa37f77a48aca05065421d56108dec4`, branch `codex/seo-search-growth-20261102`, managed checkout. AGENTS, project index/onboarding/team, NETWORK-CURRENT, website/CLAUDE, Oct10 growth plan, Nov1 daily report and current SEO queue were read. Historical claims were checked against present source; Nov2 query evidence supersedes Nov1 for opportunity selection.

## Evidence and priority

Fresh parent-collected GSC receipt: `docs/project/seo-search-growth-20261102/gsc-query-sample.json`, Web, Oct3–30 versus Sep5–Oct2. These are 499 rendered rows from a UI table displaying500, sorted by clicks, not all queries, search volume or property totals. Query figures are not proven query-to-page assignments; target URL below follows verified existing page intent.

| Query | Current clicks | Current impressions | Current position | Most relevant existing intent |
|---|---:|---:|---:|---|
| سرور مجازی ساعتی |66|390|5.8|`/vps/hourly`|
| خرید سرور ساعتی |22|240|8.8|`/vps/hourly`|
| اجاره gpu |30|162|6.3|`/gpu`|
| اجاره کارت گرافیک |21|122|6.1|`/gpu`|
| اجاره سرور هوش مصنوعی |3|33|9.5|GPU versus API decision, not a duplicate landing page|
| خرید api هوش مصنوعی |3|25|17.5|`/ai` / category and model pages|
| خرید هاست پایتون |0|255|66.7|`/hosting/python`; not a near-page-one quick win|
| python hosting satın al |1|191|45.0|`/tr/hosting/python`; needs sustained fit evidence|

The strongest bounded opportunity is useful selection content on the existing GPU page. Its commercial queries already have actual clicks and page-one average positions. Do not replace currently working titles merely because larger generic queries have low CTR. No search volume, keyword difficulty, stable local SERP rank or revenue estimate was obtained.

## Three concrete content/link gaps

### 1. GPU page: make the purchase decision more specific

Present source: `website/resources/views/pages/gpu.blade.php`, `website/lang/{fa,en,tr}/gpu.php`, `website/app/Http/Controllers/GpuController.php`. Existing page already has live prices, app compatibility, order form, warning before form, setup steps, detailed12FAQ, app explanations, ComfyUI/Jupyter guides and starting-credit explanation. Recreating those features is not new value.

Gap: the VRAM FAQ is broad, while hardware cards display CPU, **system RAM** and disk. A short, readable decision block can bring model/app requirements together before the reader commits: workload and supported app; model size/precision plus context or image resolution/concurrency; VRAM separate from system RAM; training versus inference; checkpoint/external copy and interruption tolerance. Keep it instructional, not a benchmark or an invented card-to-model compatibility table. A larger model name or more system RAM does not prove enough VRAM.

**Important source constraint:** CloudPlan has `gpu_vram_mb`, but the current hourly GPU normalizer `website/app/Services/Cloud/SaladOperations.php:501` explicitly writes it as null. GpuController does not expose VRAM; it exposes `ram_gb`, derived from `ram_mb`, as system memory. Do not display this as VRAM, infer GPU memory from card model names, or invent fixed8/16/24GB requirements. Dedicated-server metadata elsewhere is not proof of this managed hourly product's exact memory allocation. A static checklist can tell users to confirm model requirements and the selected card's published memory, or ask support with their workload when details are missing.

Suggested placement: compact section near the app guide / before general notes and FAQ, with native HTML links and a heading; optionally one small link from the configuration introduction. Preserve the existing warning ahead of the form, CTA visibility, price math, selections, app gates, fonts, mobile wrapping and empty-catalogue behavior. Do not load external scripts or add new pricing logic.

### 2. GPU versus AI Gateway: route the right need to the right product

Gap: GPU detail content has Ollama and inference explanations, but no contextual body link for visitors who only need a hosted model API. A generic navigation/header link to AI is not the same decision aid. `website/resources/views/pages/ai/home.blade.php`, `pages/ai/docs.blade.php`, `pages/ai/estimator.blade.php` verify current catalogue, comparison, request examples and per-token planner (only token-operation models with quote). Add a concise choice alongside GPU selection: use `/ai` to compare hosted model APIs when the aim is calling a model from an app; use managed GPU apps when the user needs supported notebook or model experimentation; use `/vps/hourly` for OS/port/background-process control. Do not promise every custom model, no cold start, lower cost, private hosting, unrestricted API parity or faster inference.

Suggested contextual anchors, localized with `lroute()` in fa/en/tr: «مقایسه API مدل‌های هوش مصنوعی», «راهنمای Jupyter و checkpoint», «راهنمای ComfyUI و ذخیرهٔ خروجی», «سرور مجازی ساعتی برای کنترل سیستم‌عامل». Existing guides should stay the detailed how-to destinations; keep the new block a purchasing decision, not copied tutorial body. A reciprocal GPU-versus-API line on an AI page is a later independently reviewed change, not a reason to expand this release into multiple pages.

### 3. Python hosting: practical deployment acceptance, later priority

Current `/hosting/python` already has a strong WSGI/ASGI/worker fit block, accurate Django/Flask versus VPS routing, SSH/pip/venv example, MySQL/MariaDB and no-WebSocket/SSE/Celery limitations. The product guide map currently links general speed/DNS/RUM content rather than a targeted production deployment checklist. A concise checklist of production settings, static files, WSGI entry, dependencies, logs and external HTTPS verification is more useful than another generic “what is Python hosting” page. The official Django deployment checklist supports `check --deploy`, production settings and replacing development runserver; it does not verify a particular ServerNet customer's environment.

This is lower priority: fresh query positions45–74 do not make it a quick win; no current Django/cPanel runtime was inspected in this task. Avoid repeating existing Git, DNS and speed guides or promising unverified panel controls. Domain buying/DNS topics already occupy many existing/planned guide slugs; fresh sample domain demand is weak and intent varies (name suggestion tool versus registration), so do not create another broad domain page or insert a large editorial section in the focused search form today.

## Real SERP and competitor check

One real Google search in an owned, separate Chrome tab: `اجاره gpu سرور گرافیکی ساعتی`, on2Nov2026. Visible results included Parspack, Lexoya, IranServer, ArvanCloud, IranGPU, Sharif HPC, Server.ir, Cloud4u and NovinHost. This is an observed session result, not a controlled/unpersonalized Google-Iran ranking sample or evidence of competitors' traffic. No account/profile identity or screenshot was saved; root's GSC/cPanel tabs were not touched.

Strong same-intent result verified directly: [Lexoya hourly GPU landing](https://lexoya.com/landings/gpu). Its public page foregrounds card memory and hourly/monthly choice, multiple use cases, a connection tutorial and many FAQs. It also advertises OS access and multi-GPU options. Those are competitor claims, not independently tested features, and do not apply to ServerNet's managed application environment. We should improve resource-selection clarity while retaining ServerNet's more explicit delivery, cost-start, app compatibility and temporary-disk constraints, rather than copy unsupported capabilities or add generic keyword paragraphs. Competitor prices/promotions were deliberately not copied into the proposed customer content.

Tool limitations: both web-search tool transports failed. Two Bing RSS attempts were not accepted as research evidence (one TLS failure; one returned obviously unrelated results). The single focused Google browser search and direct competitor verification supplied the usable SERP evidence. Hugging Face documentation navigation failed with connection closed and was not retried or cited for facts.

## Official technical / search sources actually retrieved

- [PyTorch CUDA memory management](https://docs.pytorch.org/docs/2.15/notes/cuda.html#memory-management): HTTP200. Stable URL exposed an official redirect to this versioned page, then that published target was read. Allocated tensor memory and allocator-reserved memory differ; clearing unused cached memory does not free live tensors. This supports avoiding misleading “empty cache fixes insufficient GPU memory” content. No performance result or workload-specific VRAM requirement follows from it.
- [Ollama FAQ](https://docs.ollama.com/faq): HTTP200. Context length and concurrent requests affect memory; concurrent model loading requires sufficient memory. Do not transplant its configurable defaults into a claim that the ServerNet managed package exposes every setting.
- [Django production checklist](https://docs.djangoproject.com/en/stable/howto/deployment/checklist/): HTTP200. Official basis for a later Python deployment acceptance checklist, not proof of live hosting delivery.
- [Google crawlable links and anchor text](https://developers.google.com/search/docs/crawling-indexing/links-crawlable): HTTP200. Use ordinary anchors with descriptive, relevant text and actual destinations. This supports contextual links, not a guarantee of ranking gain or a call for repeated exact-match keywords.

## Current public verification and cannibalization guard

Four bounded anonymous public GETs with `?qa=1`, no account cookies, read only:

| URL | Result | Observed |
|---|---|---|
| `/gpu` |200; self-canonical|One H1 «سرور گرافیکی ساعتی», order form present,54 public hardware cards, app options Ollama/ComfyUI/Jupyter present. This is a public catalogue observation, not live inventory/provisioning or purchase proof.|
| `/blog/comfyui-hourly-gpu-guide` |200; self-canonical|Existing how-to destination; preserve its distinct order-to-output scope.|
| `/blog/jupyter-hourly-gpu-guide` |200; self-canonical|Existing notebook/checkpoint destination; avoid copying its body.|
| `/ai` |200; self-canonical|Current AI hub exists; no API request/credit consumption or readiness guarantee tested.|

Three additional reserved calendar slugs were each checked once and returned404: `/blog/gpu-server-selection`, `/blog/self-hosting-ai-models`, `/blog/ai-api-integration-basics`. They are in `website/resources/content/blog-1405.php` but not currently published at those public URLs. **Do not add unconditional links to them or create parallel synonyms.** If published later, reuse their existing planned slugs and the existing conditional published/translated guide resolver. Current `config/growth.php` maps GPU only to the two verified how-to guides; `partials/growth-guides.blade.php` already suppresses posts absent from the published same-locale index.

Total own service GETs for verification:7 (4successful+3known-slug404); no crawling fan-out. Research GETs are not performance tests. No en/tr runtime link rendering was tested here; implementation must cover all locales with existing locale-aware helpers.

## Suggested acceptance for the scoped GPU block

1. fa/en/tr render with no translation keys/fallback and correct localized existing-route destinations; exactly one H1 and no duplicate IDs.
2. New block outside purchase form; no inputs/buttons/JS price configuration changes. Existing order payload, selected card/app, financial/eligibility guards and no-offer rendering remain identical.
3. System RAM never labeled VRAM; no hard-coded memory allocation, guaranteed model fit, uptime, provision time, price, benchmark or commercial superiority claims.
4. Native readable links to existing guides and service routes only. Existing missing/planned guide slugs remain absent until same-locale published content exists.
5. Actual390px live rendering with IRANSans, wrap/no overflow; confirm configuration and primary CTA remain usable. DOM loader presence is not GA4 transport/conversion acceptance.
6. Establish release time and changed URL cohort. Measure settled equal-period nonbrand query/page performance after crawl; no immediate SEO effect or permanent superiority claim.
