# Site chat model and Hermes feasibility — 2026-10-28

## Scope

Owner requested inspection of n8n workflow `WxwgkFYxcE1RBM4a`, execution `78921`, suspected exhausted model credit, and advice on using ServerNet tokens or Hermes at good cost/quality. This was a read-only audit; no model call, paid quality test, message, credential creation, workflow edit/publication/retry, service restart or operational change was performed.

## Live evidence

- Authenticated Chrome and n8n API both read execution78921: workflow **ServerNet AI Assistant (Site Chat)**, status=error; UI duration211ms. The model node is **GapGPT Model**, standard OpenAI chat-model node, credential type `openAiApi`, model `gpt-4o-mini`, temperature0.
- Generic error: `Forbidden - perhaps check your credentials?`. Provider description explicitly says pre-consume quota failed: remaining user quota **USD0.000334**, required pre-consume quota **USD0.000506**. This execution failed on provider quota; the required reservation is not an actual completed-request charge. Provider account billing itself was not opened.
- Workflow GET200 shows active=true. Existing current draft version differs from activeVersionId. Both current draft and execution snapshot use the same model above. An eventual release must preserve/reconcile existing unpublished edits, not publish them blindly.
- UI agent is a public site assistant with Persian/English/Turkish locale rules, conversation memory, deterministic contact extraction, and `notify_sales_team`. Fallback-model toggle is off; streaming toggle is off in the inspected current draft. No customer data or contact values were retained in this report.
- VM127 health helper at12:26UTC: Hermesv0.22.2 active/running, enabled, restart counter0, health200, authenticated local model listing200, unauthenticated401, model API only127.0.0.1:8642. This check makes no paid inference call.
- Live sanitized VM127 config confirms provider=custom, base URL `https://servernet.cloud/v1`, chat_completions, model `di-qwenqwen3-235b-a22b-instruct-2507-12d7a45f0c`, max_turns12/run_budget_seconds120.
- Authenticated ServerNet GET `/v1/models` returned200 with325 entries visible to the existing Hermes key. This is key-visible availability, not a full global-catalog count, credit-balance check or proof of successful fresh paid inference. Both the existing DeepInfra Qwen model and `di-deepseek-aideepseek-v4-flash-0731-0cb6a5868a` are listed; each advertises max_output_tokens4096. No secret was printed, copied into this workspace or placed in process arguments.

## Recommendation

Use a dedicated, budget-limited ServerNet project credential directly from n8n to the OpenAI-compatible ServerNet gateway. Preserve the existing webhook, agent instructions, memory/session key, contact extraction, sales tool and response format. Scope the new credential to model listing/chat only; do not reuse the privileged Hermes operations credential. Configure the base URL as `https://servernet.cloud/v1` and select the catalog ID, not the upstream vendor name. Confirm n8n's actual installed node/credential contract during implementation.

First candidate: the existing DeepInfra **Qwen3-235B-A22B-Instruct-2507**, because ServerNet's prior documented acceptance covers Persian responses and tool-call/result handling. This does not prove superior quality or latency on this specific site's prompts. Cheaper candidate: **DeepSeek-V4-Flash-0731**, subject to isolated Persian/English/Turkish, contact-preservation and tool-call tests before selection.

Do not connect the anonymous site chat to the current owner Hermes agent. Hermes uses the same paid gateway and additionally carries administrative tools/owner context and agent-loop overhead. A future public support Hermes instance would need separate identity, sessions, read-only public knowledge and restricted tools; that is a separate design, not a drop-in model credential change. Existing private owner ingress remains owner-bound.

ServerNet gateway API keys can fund this model path. ChatGPT/Codex subscription usage is a different path: the current n8n credential uses API keys. Official OpenAI documentation now also lists eligible Hermes Agent connections through Sign in with ChatGPT, but this audit did not verify enrollment/eligibility or add OAuth to the installed Hermesv0.22.2. Do not copy a desktop session token into n8n or promise subscription inference through its current API-key node.

## Cost basis and limitations

Official standard upstream list rates checked28October2026, USD per million tokens:

| Model/source | Input | Output | 1,000 single calls with3,000 input/400 output each |
| --- | ---: | ---: | ---: |
| Qwen3-235B-A22B-Instruct-2507 / DeepInfra |0.09|0.55|0.49|
| DeepSeek-V4-Flash-0731 / DeepInfra |0.06|0.18|0.252|
| Current model GPT-4o Mini / official OpenAI reference |0.15|0.60|0.69|

These are illustrative upstream charges, not verified current GapGPT retail or ServerNet IRT quotes. Actual cost includes full history/system prompt, any extra model/tool turns, provider-specific reasoning usage, and ServerNet conversion/markup/tax where applicable. Gateway/provider balances and quality/latency on this workflow remain untested. Model listing alone does not establish inference credit. No paid test or estimated budget was charged.

Before a later authorized migration: reconcile the draft/active version; take a private workflow backup; obtain fresh gateway quotes; create a separate restricted project key and enforce a daily cap; test a clone with sales/email/SMS/CRM writes disabled; check multilingual responses, exact contact digits and memory isolation; verify one controlled production interaction and record usage/charge. Avoid replaying execution78921 through its sales side effects. Add observable quota failure handling and budget alerts as separately reviewed changes.

## Affected machines, persistence and rollback

VM104/n8n and VM127/Hermes were inspected using existing authenticated routes. No guest/config/resources, production app or workflow persistence, route, NAT/firewall, DNS, cron or country-routing/port-forward reconciliation source changed. Only this detailed record and NETWORK-CURRENT.md were updated. No operational rollback is required. A future migration rollback should restore only the dedicated model-node/credential selection from a fresh backup while preserving later independent workflow edits; returning to the existing GapGPT key will still fail until that provider's quota is restored.

## Sources

- User's authenticated execution: https://flow.servernet.cloud/workflow/WxwgkFYxcE1RBM4a/executions/78921
- Qwen price/features: https://deepinfra.com/Qwen/Qwen3-235B-A22B-Instruct-2507
- DeepSeek price/features: https://deepinfra.com/deepseek-ai/DeepSeek-V4-Flash-0731
- Current model reference: https://developers.openai.com/api/docs/models/gpt-4o-mini
- n8n credential contract: https://docs.n8n.io/integrations/builtin/credentials/openai
- Official OpenAI authentication/billing: https://learn.chatgpt.com/docs/auth
- Eligible ChatGPT-plan integrations: https://learn.chatgpt.com/docs/sign-in-with-chatgpt
- Previous ServerNet Qwen/runtime acceptance: HERMES-READINESS-2026-10-20.md; HERMES-RELIABILITY-2026-10-21.md (current live config and health above take precedence).
