# Site chat: Qwen and public business knowledge — 2026-10-28

## Result and authorization

After the read-only quota diagnosis, the owner requested choosing the better Persian option, then explicitly authorized implementing the best design including business knowledge. The live site chat now uses **Qwen3-235B-A22B-Instruct-2507 through the ServerNet gateway**, with public organisational context rebuilt on each website request. This supersedes the pending-migration result in SITE-CHAT-MODEL-AUDIT-2026-10-28.md.

Final live validation at approximately16:32Tehran: workflow `WxwgkFYxcE1RBM4a` active, published version `1e3cba47-6255-498e-9b17-0935e50578fc`; existing draft-only differences retained separately in draft `08504e79-1873-4cd8-baee-f02698f4187c`. Production execution79023 succeeded through Respond to Site, used Qwen, received66 public product descriptions, and made zero sales-tool calls. The actual browser showed the correct plain URL `https://servernet.cloud/cloud/n8n`.

## Architecture and knowledge boundary

Website ChatController → authenticated existing n8n webhook → existing agent/memory/contact extraction → ServerNet `/v1` gateway → DeepInfra Qwen. The existing real sales subworkflow `DjMx9EdrLHoAY1jK` remains the only sales-action path.

New `App\Services\PublicChatKnowledge` reads the same public product configuration, explicitly selected public contact fields, official public page URLs, and at most200 published knowledge-base posts. Future-dated posts are excluded. For each message it supplies at most90 product descriptions and three relevant documentation excerpts of at most1500characters. Persian ی/ک and zero-width-space differences are normalized for retrieval. Named technical topics such as n8n/SSH/DNS/RDP must actually occur in a candidate article; generic AI wording is insufficient. HTML scripts/styles, credential-like values and VPN URLs are removed from excerpts. Context includes its timestamp and limitations.

Only phone/phone_link/email/sales_email are selected from the site's contact source. Internal notification recipients, tickets, invoices, customers, service credentials, provider administration and Hermes owner memory are not read or included. The public assistant can explain documented diagnostics and refer account-specific work to the authenticated support panel. It cannot inspect or operate a customer's service. The current owner Hermes agent and its tools/configuration were not changed.

The agent instructions use the current business context instead of obsolete fixed phone/panel information. They retain locale selection, visitor memory, exact normalized contact values and the original sales-receipt protocol. They prohibit invented prices, stock, delivery deadlines or claims of service inspection. The website normalizes Markdown links to bare URLs for its existing text renderer.

This is retrieval of published business knowledge, not model training or access to all organisational history. Newly published/edited public product configuration and KB content are read on the next site request; only the newest200 KB candidates are searched. No exact live price/stock/customer-health tool was added.

## Credentials, spend and persistence

- Dedicated owner customer1 project21, slug `site-chat-n8n`, with only `ai:models:read` and `ai:chat` on token41. No domains, infrastructure or identity abilities were granted.
- Current ceilings: **20,000IRT/day** and **1,000,000IRT/month**. The owner explicitly raised the initial monthly300,000IRT limit on28October at16:41Tehran; the daily ceiling is unchanged. These are limits, not wallet top-ups. Live AiCaller enforces both using charged usage and open reservations. The dedicated project's budget window is refreshed before website relay when stale; it does not increase either limit or refresh other projects.
- n8n encrypted OpenAI credential points to `https://servernet.cloud/v1`; allowed request domain is servernet.cloud. Model ID: `di-qwenqwen3-235b-a22b-instruct-2507-12d7a45f0c`.
- Separate encrypted header credential protects the existing webhook. The website loads the same secret from encrypted Setting `site_chat_n8n_webhook_secret`. Requests lacking it return403, including the real production path. The browser does not receive it. `site_chat_ai_project_id` identifies the bound budget project.
- Model node renamed **ServerNet Qwen Model**. Temperature0, output cap900, model retries0, timeout45seconds, agent maxIterations3. Website cURL timeout remains50seconds. Long multi-step requests can still exceed that outer timeout; this release does not add asynchronous customer-chat delivery or an uncertain replay loop.
- Keys are stored only in n8n encrypted credentials, encrypted site storage/settings and existing protected runtime stores. Temporary plaintext key/ingress transfer copies on VM127 were removed after acceptance; retained synthetic execution snapshots had the ingress secret redacted. No credential values are in this record or repository sources.

The checked model list rate remains USD0.09 input/USD0.55 output per million tokens. Actual ServerNet billing for all14 model calls in this release was **3,356IRT**, all settled, zero open/unknown usage at final validation. This includes repeated isolated acceptance cases and three live browser questions; it is not a fixed per-reply tariff. The final short live answer cost149IRT. No wallet credit, purchase, customer invoice or service order was created.

## Validation and corrections

- Both installed PHP files linted; final19 read-only knowledge checks passed, including current public contact, exclusion of notification destinations/private sources, Persian normalization, excerpt bounds, credential/VPN redaction and rejection of an unrelated site-builder article for an n8n question.
- Isolated n8n clone used the same model/agent/contact/memory path but a synthetic sales subworkflow returning a test receipt. Persian digits became the exact intended11 ASCII digits and email was preserved. Successful test registration called the stub once; a later memory question recalled both the synthetic name and tracking code without another tool call. Invalid short phone produced a correction request without a tool call. No real sales email/SMS/CRM/Bale action was invoked.
- Persian business-answer, English and Turkish cases completed. An initial English test had mismatched Persian reference locale, so language precedence was strengthened and the corrected English test passed. An initially unpublished stub caused a tool failure; publishing only the stub allowed the real tool round trip to pass. Neither issue was accepted as a successful sales test.
- Real site browser → ChatController → production n8n → Qwen → browser succeeded on executions79008,79016,79023. An initial answer selected a generic site-builder article; technical-topic filtering and reference precision were tightened before final acceptance. Final answer used the correct official n8n page. Markdown emitted despite formatting instructions is now normalized deterministically in ChatController.
- Active and draft workflow versions were checked independently. This n8n public API automatically republishes on PUT while active; after restoring prior draft differences, the tested production version was explicitly pinned again. Final active/draft IDs above are verified, not inferred from a successful PUT.
- Two temporary test workflows were deactivated/deleted; subsequent GETs returned404. Production remained active. Expiring, random-header, self-removing deployment helpers were checked absent after use. Final local/live file hashes agree.

## Affected state and source

Affected: Germany website account servernetcloud (ChatController, new PublicChatKnowledge, two dedicated settings and project/key records); VM104/n8n (dedicated credentials and this one production workflow); VM127 (private deployment receipts and temporary acceptance orchestration).

No guest reboot/resource/config change, Hermes restart, route, NAT/firewall, DNS, host cron or country-routing/port-forward reconciliation state changed. Existing guests, other workflows and real sales processor code were preserved.

Repository source:

- `website/app/Http/Controllers/ChatController.php`
- `website/app/Services/PublicChatKnowledge.php`
- `relay/n8n/site-chat-model-20261028/workflow.json` — final published graph, credential references only; do not import over an existing workflow blindly.
- `relay/n8n/site-chat-model-20261028/acceptance.json` — sanitized publication/production/cleanup receipts.
- `scripts/site-chat-model-20261028/knowledge-checks.php` — read-only checks against an existing application; no paid inference.

Final SHA256: ChatController `8240748936fc8073d91b5bcdd0df2b106005764eeebe270225c36df34997cb08`; PublicChatKnowledge `ebb560bea07c3c3095fa67ded27929a37eeb32ef3efe1f00ac1c5ddc407e46ac`.

Private site backup/receipts: `storage/app/private/site-chat-model-20261028`, including the original controller, provision/site/knowledge receipts and encrypted key recovery. VM127 records: `/var/lib/servernet-hermes-ops/site-chat-model-20261028`, including original active/draft workflow, final candidates, synthetic acceptance evidence, billing summary and cleanup. Directory permissions0700; private files0600. Deployment helpers intentionally stop on existing receipts or unexpected hashes; inspect state before continuing rather than rerunning provisioning.

## Rollback

Read fresh active/draft/source hashes first and retain all usage/audit receipts. To stop this path, disable only the dedicated site-chat project or deactivate only this workflow; the existing website fallback can answer while the issue is investigated. No network rollback is required.

The monthly-limit follow-up changed only project21's monthly budget from300,000 to1,000,000IRT, under a row lock after verifying the owner/project/token binding and the unchanged20,000IRT daily limit. A separate protected request read back the new budget, current monthly window, active status and unchanged installed source hashes; the Qwen workflow stayed active. No paid inference, wallet top-up or restart occurred. Sanitized evidence is in `relay/n8n/site-chat-model-20261028/budget-update.json`; before/after private receipts are alongside the original deployment records as `monthly-budget-1m-before.json`/`monthly-budget-1m.json`. To undo only this increase, verify the project is still bound/active and the current monthly value is still1,000,000IRT, then restore300,000IRT without changing the daily cap or historical usage. The retained daily ceiling still applies independently.

For a coordinated full rollback, restore the original model/agent/webhook node data from the private workflow snapshot, explicitly publish the intended version, preserve unrelated draft changes, and restore only the reviewed original ChatController diff with exact opcode invalidation. Remove only the dedicated project's settings/key dependency after the old transport is active; revoke token41 if no longer used. Do not remove pre-existing GapGPT/Hermes credentials or restore a whole database. The old GapGPT path remains out of credit, so rolling back model selection alone does not restore AI inference.

## Limits

No broad comparative Persian benchmark, long-running load/endurance test, actual customer sales delivery or authenticated service troubleshooting was claimed. Real sales email/SMS behavior is unchanged and was replaced by a stub during tests. Knowledge source selection is bounded keyword retrieval, not embeddings or complete Hermes memory. Cap/credit exhaustion can still select the website's existing fallback; no new scheduled monitor/notification automation was created.
