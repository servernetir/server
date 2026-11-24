# GAPGPT and OpenRouter cost comparison — 24 November 2026

GAPGPT is not uniformly more expensive. With the currently registered funding overheads (OpenRouter 33.9%, GAPGPT 0%), many published base token tariffs favor GAPGPT. This is an indicative tariff comparison, not confirmation that the disabled GAPGPT route is currently executable.

## Sources and method

OpenRouter's official public chat and embedding catalogues were downloaded on 24 November (473 chat catalogue entries and 40 embedding entries before filtering). GAPGPT's original public API endpoint failed TLS negotiation from the production application server; separate reads returned HTTP 503/403. Its branded public pricing page was discoverable through the search index, which reported a crawl two days before this check. Representative published prices agreed with the retained ServerNet tariff snapshot. A freshly fetched GAPGPT tariff is therefore not claimed.

For the numerical comparison, use only the 91 token-priced GAPGPT rows last discovered on 23 November at 08:00:10–11 UTC; exclude the 48 older retained rows. Seventy-two match an OpenRouter model of the same operation, manufacturer and version. Matching uses exact identifiers after a known manufacturer prefix and documented Anthropic dash/dot version aliases; it preserves dates and distinct versions. Unverified GapGPT-hosted aliases, free/batch variants, media and unsupported metering are excluded. This matching is analytical only and changes no routing configuration.

Compare uncached text at the ordinary base tariff. Embeddings compare one million input tokens only; no output charge is invented. Multiply OpenRouter rates by 1.339 once, using the actual provider configuration rather than its public standard platform fee. GAPGPT's registered funding overhead is zero; its true credit purchase/conversion costs beyond that setting have not been independently checked. ServerNet's 7% margin and customer VAT are excluded from supplier cost comparisons and remain unchanged.

## Findings

Among 72 matched identities (67 chat and five embedding models), GAPGPT is no more expensive on every billed token unit and strictly cheaper on at least one for 65; OpenRouter has that advantage for three. Four have crossed input/output prices, so the winning supplier depends on the workload. For the equal-input/output chat basket and input-only embedding basket, GAPGPT wins 66 and OpenRouter wins six. These are model counts, not traffic-weighted savings.

Illustrative costs in USD for one million input plus one million output tokens, aggregated over requests within each model's ordinary pricing tier:

| Model | GAPGPT | OpenRouter with 33.9% overhead | Equal-quantity outcome |
|---|---:|---:|---|
| GPT-4o | 12.50 | 16.7375 | GAPGPT 25.3% cheaper |
| Claude Sonnet 4.5 | 18.00 | 24.102 | GAPGPT 25.3% cheaper |
| Qwen3 Coder | 2.25 | 1.7407 | GAPGPT 29.3% more expensive |
| Gemma 3 27B IT | 0.50 | 0.32136 | GAPGPT 55.6% more expensive |

Qwen3 Coder and Gemma have crossed unit tariffs; their equal-quantity examples do not establish a universal winner for every request. At identical raw input/output prices, GAPGPT is 25.317% cheaper than an OpenRouter route carrying 33.9% overhead, before any unverified GAPGPT funding cost. Cache use, search, media, long-context tiers and access/availability can change a real request's outcome and are not inferred from this basket.

Keep GAPGPT disabled as instructed. Current OpenRouter/DeepInfra economic routing stays active; this report does not add GAPGPT to it. Re-enabling would require a separate decision after confirming live tariff retrieval, actual funding costs and the existing access/commercial gates.

Sources: [GAPGPT public API pricing](https://aigap.app/platform-v2/pricing), [OpenRouter chat catalogue](https://openrouter.ai/api/v1/models), [embedding catalogue](https://openrouter.ai/api/v1/embeddings/models), [OpenRouter model identifiers and aliases](https://openrouter.ai/docs/guides/overview/models). The dated matched numerical results and limitations are preserved in [the comparison data](ai-provider-cost-comparison-20261124.json). No paid inference, funding, new key or balance change occurred.
