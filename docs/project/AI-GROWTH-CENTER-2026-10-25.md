# ServerNet AI Growth & Knowledge Center — 25 October 2026

## Delivery state

This is an implemented, tested first release in the Laravel source tree. It is **not installed or verified in production**. No Google account, social account or live Hermes instance was connected during this work. No paid supplier request, customer message, infrastructure operation or production migration was executed. Test provider responses and customer wallets were isolated fixtures.

Base: develop at 7329008b. Work was isolated in codex/growth-center-20261025; unrelated worktrees and existing production services were preserved. After reconciliation with develop at 537c43f3, the implementation commit is 5bd2e013ba3ef16250d490228a0091a9da4872f9. The final publication commit also contains the operational index update.

The supplied specification spans four phases. This release implements the core content workflow and local knowledge workflow; the table below explicitly identifies remaining work. The 13 navigation sections are not a claim that all 16 specification stages are complete.

## Implemented behavior

| Area | Implemented and exercised | Remaining dependency or scope |
|---|---|---|
| Sociality | Persistent drafts, fa/en/tr content, channel/format/objective, campaign association, exact topic deduplication, search, state filters, optimistic versions and history | No earlier Sociality module was found by name in the inspected tracked code, branch names or matching history; this is a new module using existing services |
| Calendar | Actual Jalali month grid for Persian, Gregorian grid for en/tr, UTC storage and selected timezone, shared Jalali picker | No changes to the older ContentCalendar quota algorithm |
| Text AI | Existing AiModelRegistry, admission, AiPricing, VAT, AiCaller, wallet and AiUsage receipts; quotes are approved explicitly before enqueue | Dedicated existing Gateway project/API key, enabled model, tariff, FX, project balance and scopes are required |
| Image AI | Existing native Gateway contracts; bounded image request, receipt, inline or allowlisted remote media, private permanent PNG archive and deterministic servernet.cloud mark | Supplier schema must accept the admitted prompt; aspect ratio is currently requested in the brief, not guaranteed by model-specific dimension parameters; official logo asset compositing remains separate |
| Approval | draft → review → approved → scheduled; changes revoke approval; queued/processing jobs prevent approval; stale versions fail with 409 | Public content claims and media rights require the human review explicitly shown in the UI |
| Publication | Approved scheduled blog posts publish once to existing Post/PostTranslation CMS, with actual approving author, tutorial category, sanitized Markdown and cache invalidation | Social channels use export/manual publication and a real publication URL; no social account is represented as connected |
| Drive | Official OAuth with PKCE, one-use session state, account/email verification, drive.file scope, encrypted credentials, refresh, private app-owned archive, upload idempotency via appProperties | OAuth consent for the owner account is required after installation; archive root is ServerNet AI Growth; richer per-campaign/folder organization remains future work |
| Cost controls | Daily/monthly IRT ceilings, serialized reservation checks, receipt reconciliation, elapsed time, failure codes, conservative uncertain-cost holds, circuit pause after five recent failures | Amounts are Gateway wallet charges, including its pricing/tax policy, rather than a new independent calculation of supplier expense |
| Knowledge | Local documents and chunks, provenance, versions, scope, classification, redaction, candidates, human verification/rejection, expiry and review renewal, deletion, local cited retrieval | Retrieval is explicitly lexical; embeddings, semantic/hybrid search and vector infrastructure are not implemented in this release |
| Hermes | HMAC API for search, verified solutions, cited draft suggestions, candidates, incorrect-knowledge reports, product docs and recent operational changes; linked ticket/service ownership check | Live Hermes client configuration is required; replies are evidence extracts, not an unverified model-generated support answer; no ticket is sent and no command executes |
| Conversation import | Local JSON preview for ChatGPT/Claude, explicit selection, redaction, internal candidates, duplicate prevention, temporary encrypted import cache | Automated redaction cannot identify every personal fact/name; the selection confirmation requires a human privacy review |
| Notebook | Download selected, current, verified public sources as Markdown with provenance | Consumer Notebook/Flow/AI Studio workflows remain manual; no nonexistent personal Notebook API is presented as connected |
| Notifications | Optional existing AdminNotifier path to existing admin Bale/email destinations, with content ID/deep link and no customer text | Disabled by default; delivery to a real destination was not tested here |
| Scheduler | Existing Laravel scheduler invokes bounded durable jobs; pause switch, conditional claims, overlap lock, stale-worker flagging and daily task digests | Digests are internal task checklists, not harvested news or automatic paid daily generation; existing n8n workflows were not changed |
| Research/analytics | Official-source research links, real content/campaign/job counts, real usage receipts, truthful unavailable analytics state | News ingestion, keyword clustering, content-gap/cannibalization analysis, platform insights, GSC/GA4 attribution, CPL/ROI and automatic optimization remain unimplemented |
| Media automation | Licensed image upload and immutable archived outputs with previews | Video/audio/music generation, FFmpeg assembly, subtitles, storyboards, multi-scene editing and direct auto-publish remain later phases |

No automatic provider fallback or blind paid retry was added: another possibly charged request is blocked while an earlier failed receipt remains uncertain. Automatic retries/fallback require a separate metered/idempotent design. An interrupted worker can require operator review of its Gateway receipt.

## Installation and configuration

These are deployment instructions, not a record that they have been executed on production. Production publication still requires the owner's explicit approval under the supplied specification.

1. Review and deploy the listed application files through the existing ServerNet deployment process. Preserve unrelated differences on the server, the separate public document root and existing OPcache procedure. No deployment helper was rerun.
2. Back up the production database and private local media before running the new migration. Run the project migration process for 2026_10_25_160000_create_growth_center_tables.php. This adds only growth_* tables; no existing migration was edited. Its rollback has destructive consequences for Growth data and is for an empty/disposable environment unless that data is separately backed up.
3. The existing Laravel scheduler must already execute schedule:run. No extra Proxmox container or queue daemon is required. growth:run processes at most three queued jobs per scheduled invocation; the command supports a bounded --limit for manual operation.
4. Open /admin/ai-growth. Only an authenticated User with role admin can use these screens; author/support roles cannot. The generated media endpoint is protected by the same boundary and never exposes a public storage URL.
5. In Settings, enter a dedicated ServerNet AI Gateway **customer project key**, with ai:chat and ai:native scopes as needed. Use an actual funded organization account/project, not a supplier API key. The existing Gateway performs its normal admission, project budget, wallet and provider checks. This work neither creates nor funds that account.
6. If the Gateway key has CIDR restrictions, configure GROWTH_GATEWAY_SOURCE_IP with the approved service source IP in the application environment. Do not reuse an arbitrary browser IP. The quote is bound to the configured Gateway token ID; rotating that key requires a new quote.
7. Set explicit daily/monthly limits in **toman / IRT**. Zero limits and the pause switch are safe installation defaults. Enable execution deliberately. Budget periods are UTC. Queued jobs carried into the next UTC day expire before inference rather than spending a previous day's reservation against a new budget period.
8. Existing Google OAuth client settings are reused (google_client_id and encrypted google_client_secret). Register the exact console callback /admin/ai-growth/google/callback in that client's authorized redirect URIs. The module stores its own encrypted refresh/access credential; it does not overwrite the existing Calendar credential. Drive account authorization is restricted to the specified owner account.
9. Connect Google from Integrations and approve only openid, email and drive.file. Archive is an explicit action on an approved version; its text and media remain local even if Drive fails. Raw knowledge and complete personal conversations are never automatically archived to Google.
10. Configure a separate random Hermes HMAC key of at least 32 characters in this module's settings and in the authorized Hermes client secret store. Keys never render back into the settings form. Existing notification destinations are reused only when the module notification switch is enabled.

Every secret remains in the project's encrypted Setting facility. Empty secret fields preserve existing values. The Drive disconnect action removes this module's local credential; it does not revoke the whole shared OAuth client or erase archived content.

## Operator acceptance path

Create a topic with a public brief, target locale, channel, format and business objective. Open the content record, select a real registered model and request a quote. Inspect the maximum charge, explicitly approve model processing and enqueue. The scheduler generates a draft or a private branded image. Inspect the rendered preview and archived media, edit if needed, request review and approve the current version. Export to publish manually or schedule a blog publication. Approved versions can be explicitly archived to Drive. Job records show request identity, status, model, elapsed milliseconds, ceiling and final receipt charge; unknown is not displayed as zero.

For a local knowledge scenario, create a candidate with source, classification, applicable service/general scope and a future review date; verify it as a human reviewer. Search returns only current verified public/internal matching-scope chunks and knowledge:ID:vVERSION#chunk-POSITION citations. A Hermes suggest request returns cited evidence and requires_human_review=true with executed_actions=[] even for a high-risk question. Restricted/candidate/expired/rejected/wrong-service documents are excluded.

## Hermes wire contract

POST application/json to /api/internal/growth/knowledge/ACTION; ACTION is search, solution, suggest, candidate, feedback, products or changes. Query parameters are rejected. Sign the exact UTF-8 body bytes.

Headers: X-Growth-Time (Unix seconds), X-Growth-Nonce (24–80 URL-safe characters), X-Growth-Signature (lowercase hexadecimal HMAC-SHA256).

The signed message is timestamp, nonce, uppercase HTTP method, path, and SHA256(body), joined with a newline between components. Timestamp tolerance is ±120 seconds; nonces are consumed atomically for 300 seconds, covering the entire acceptance window. Do not log keys, signatures or raw ticket bodies. Use one shared persistent cache across app nodes.

Search/suggest body: query, optional scope, optional ticket_id. When a linked ticket service exists, its real owner must match the ticket customer and its exact service:ID scope is used. solution uses document_id plus the applicable scope. candidate requires title/body/source and always creates an internal unverified candidate. feedback uses document_id/note and quarantines the source for human review. products and changes return verified current public general sources of those kinds. These endpoints provide no server-command, finance, publication or customer-send capability.

## Validation actually completed

- PHP 8.4.26, isolated checkout, SQLite in-memory, synthetic test users and fake provider HTTP only.
- New module suite: **36 tests / 183 assertions, passed** (GrowthCenterTest, GrowthDriveTest, GrowthGatewayTest). Includes actual existing Gateway admission/pricing/settlement code with fake supplier output for both text and image, exactly-once execution, explicit cost consent and quote/key invalidation.
- Related regression suite: **201 tests / 4,833 assertions; 200 passed, one existing failure**. ContentPipelineTest::test_the_queue_actually_reaches_the_end_of_the_year exhausts its pre-existing yearly queue after 611 entries. The identical test failed at the same point on clean base 7329008b; it was not skipped or changed to make this release appear green.
- All 13 module sections render through Laravel; translated screens were checked for fa/en/tr and matching translation key sets. Markdown/script escaping, valid existing asset authorization, HMAC tamper/replay rejection, expired knowledge, personal export selection and minimal OAuth/PKCE are covered.
- The new migration runs in the isolated test database and a dedicated test rolls it down/up while retaining an existing CMS Post. **MariaDB migration/concurrency validation has not been run.**
- JavaScript syntax and changed PHP syntax are checked separately. No provider money, real customer wallet or production content was used.
- **No visual desktop/mobile browser acceptance** was completed: the connected Browser JavaScript tool was unavailable in this session. Responsive CSS and rendered forms are implemented but this limitation remains explicit.
- No live Google consent/refresh/upload, live Bale/email receipt, live Hermes client, production scheduler tick or public publication was claimed.

Evidence is in ai-growth-center-2026-10-25/ alongside this record. The full repository's thousands of unrelated tests were not run.

## Current official Google references

Checked 25 October 2026, using Google's own documentation:

- [Google AI subscription plans and AI Studio/API usage](https://ai.google.dev/gemini-api/docs/google-ai-plans): subscription access and production API billing/credits must be configured through their actual supported surfaces; a consumer subscription is not treated here as unlimited API credit.
- [Gemini API video generation](https://ai.google.dev/gemini-api/docs/video): Veo 3.1 Preview shutdown was listed for 22 October 2026. This release provides no hardcoded video model or active video connector.
- [Drive API scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth): drive.file is the selected limited per-file scope.
- [Notebook Enterprise API prerequisites](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/api-notebooks): Enterprise setup/licensing is distinct from personal Notebook access. Only a manual public-source export is provided here.

## Persistence, infrastructure impact and rollback

Affected application: website/ Laravel app and its existing admin layout/routes. New source scheduler entry: growth:run once per minute, gated off after migration. Persistent state: new growth_* tables, encrypted module Settings and local private growth/ assets. Production state has not changed.

Affected machines: none. Proxmox guests, routes, NAT/firewall, DNS, country-routing/port-forward agents and their desired state: unchanged. Existing n8n flows and deployment jobs: unchanged.

Before any future production rollback, disable Growth execution and notifications, wait for in-flight inference to finish and reconcile its Gateway receipt. Preserve the module tables and private assets for audit; backing out routes/UI/command is sufficient to stop new access without deleting evidence. Existing AI Gateway reservations and charges must continue through its existing reconciliation process. Do not roll back its ledger or repeat a paid request. A full schema down operation deletes Growth documents/jobs/assets metadata and is not a routine production rollback. Drive files remain independent copies and should not be deleted automatically.

Logical future archive collections: Brand Assets, Campaigns, Social Posts, Video Projects, Generated Videos, Blog Media, Knowledge Exports, Research Reports and Approved Documents. The current implementation retains its own app-owned root and versioned files; automatic creation of that richer tree is not claimed.
