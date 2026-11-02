# Current provider sales facts in owner chat

This layer follows the installed `infra-context` layer. Real model canaries received the new evidence but still substituted 332 active catalogue rows for 268 sellable offers, ignored 473 blocked rows, and mixed private infrastructure with commercial products. Context delivery alone did not pass answer acceptance.

`HermesProviderReply` handles narrow supplier-list and single-provider sales/catalogue fact questions using the current bounded owner projection. It distinguishes sellable offers, active catalogue rows and blocked rows, lists the nine registered suppliers with their individual sales states, and labels sampled reasons and unverified live quota. Missing, stale, future or invalid evidence yields an unknown-status answer. Static supplier names and allowlisted reasons prevent raw notes or unrelated private services entering these replies.

The Memory overlay adds only the helper call before the existing ticket/model branch. Casual chat, knowledge reports, memory handling and operational or mixed-action requests retain their existing routes. This is the mini-app's existing local-reply branch, before model submission; it adds no provider API, execution authority or product listing. The first layer's prompt evidence remains available to model requests.

Install the new canonical helper first, then the Memory overlay. The manifest expects the installed first-layer Memory hash `585e61979ad6e7e8672bc2242a03948e29ddebff271edaab1ec46c404a96055f`. Use this layer's private CLI launcher with the existing worker and filesystem locks. Do not deploy old overlays over it.

```sh
php infra/hermes/assistant-20261102/infra-facts/test-provider-reply.php
php infra/hermes/assistant-20261102/infra-facts/test-context-budget.php
php infra/hermes/assistant-20261102/infra-facts/test-reply-intent.php
```

All 55 fact, 33 budget and 84 routing checks passed under production PHP 8.4. Twelve deployment guards passed. Both destinations were installed and hash-verified. Fresh-process live-class acceptance at 2026-11-02T17:29:04Z answered both original questions correctly from current owner application data and confirmed the real controller consumes this local reply before relay submission. It submitted zero jobs and sent zero messages. Read [the dated report](../../../../docs/project/ARAZ-INFRA-CONTEXT-2026-11-02.md) for final receipts. No UI login or real browser chat acceptance is implied by a CLI class check.

Rollback this layer before `infra-context`, then `chat-polish`, `araz-name`, `ui-followup` and the initial release. Use exact current hashes and preserve databases, conversations, knowledge, queue receipts and sales settings. Never replay uncertain work.

This installed intermediate release is historical. The current canonical helper includes the later [scope fix](../infra-facts-scope/README.md). For a fresh preparation of this exact manifest, use commit `a606cf9670b2f05cedaf10dbd286f30460e323c4`; existing prepared private rollback uses its frozen manifest/backups after rolling back scope first.
