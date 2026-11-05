# Owner infrastructure sales context

Status: all three production files installed and hash-verified. All 253 isolated PHP checks and 12 deployment guards passed. Real-model response acceptance is tracked separately in the dated report.

The existing context compressor could replace the entire application snapshot with `context_size_limit`. A private audit measured a 27,643-character runtime and a 4,473-character final prompt with that replacement. A provider sales question then lacked the platform sales evidence needed to answer it. Proxmox/WHM/Plesk health or historical firewall policy cannot establish why Arvan plans are blocked.

`HermesInfrastructureContext` reads the existing owner application state through `CloudManager`'s current nine-supplier registry, `CloudSalesHealth`, `CloudPlan`, `CloudInstance`, settings and saved scan receipts. `HermesOperations` invokes it only after its existing owner-binding checks. It performs no provider API calls or writes. The projection is at most 3,500 Unicode JSON characters, prioritizes explicitly named providers, labels local inventory and sampled quarantine reasons, and exposes no raw notes, customer identities, IPs, provider resource IDs or credentials. Quota amounts and provider observation time remain null without actual evidence; configured credentials do not mean a connected API.

`HermesConversationMemory` preserves that bounded `provider_context` when optional knowledge and history exhaust the 9,000-character JSON budget. Its fallback also keeps validated scalar capability/worker receipts and at most six owner ticket events using fixed field/status whitelists. Ticket events are an incomplete receipt sample, not a ticket census or proof of resolution. No freeform ticket content enters this fallback. Existing reply routing, assistant names and prior UI changes are preserved.

This is an owner-context bridge, not a new Arvan MCP adapter or a live quota integration. At 2026-11-02T16:45:02Z, a separate read-only platform audit observed 268 Arvan offers, 804 catalogue rows, 473 blocked rows and zero current blocked rows matching the inspected RAM-note pattern. These dated local observations do not establish a total outage or a RAM cause for all blocked plans. Quarantine samples must not be extrapolated to the whole catalogue, and fresh questions require fresh evidence.

The manifest pins three targets in dependency order: the new canonical helper, the Operations overlay, then the Memory overlay. The private CLI launcher holds the existing native worker cache locks and invokes the pinned deployment core for prepare/apply/verify/rollback. Keep the current Araz context dependency used by `test-context-budget.php`; do not replace historical staged sources with canonical files from another layer. Source installation does not itself validate a real conversation or worker execution.

Run the pure PHP harnesses without Laravel bootstrap, credentials or network:

```sh
php infra/hermes/assistant-20261102/infra-context/test-infrastructure.php
php infra/hermes/assistant-20261102/infra-context/test-context-budget.php
php infra/hermes/assistant-20261102/infra-context/test-reply-intent.php
```

The projection, budget and routing harnesses passed 159, 33 and 61 checks respectively under the production PHP 8.4 interpreter, without framework bootstrap, credentials or network. The budget fixture produced 3,788 JSON characters and 10,697 relay characters with the maximum owner request. Twelve isolated deployment guards passed. All three destinations passed installation lint and exact manifest hashes. A post-install audit at 2026-11-02T17:11:05Z retained provider evidence in an 8,707-character prompt from a 30,015-character runtime. The static maximum relay estimate is 15,923/16,000 characters with a 9,000-character JSON block and a 4,000-character owner request. Real-model responses are not implied by these harnesses; see the dated acceptance report.

Rollback this layer before `chat-polish` or `araz-name`, using its own launcher and exact hashes. Preserve databases, conversations, knowledge, queue/notification receipts and sales settings; never replay uncertain work. See [the dated project report](../../../../docs/project/ARAZ-INFRA-CONTEXT-2026-11-02.md) and [release-manifest.json](release-manifest.json) for scope and final acceptance records.
