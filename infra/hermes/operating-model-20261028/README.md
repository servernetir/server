# Owner operating context — 28 October 2026

Status: release prepared; **not installed**. Base `develop`: `e76c4f7e7681ca6157a8414b486b139efd3f536e`.

The production application's owner context was inspected through the existing cPanel Fileman API. Its conversation memory already filters private history, bounds context, separates owner notes from authority and passes recorded operations to the VM. This release adds a compact working method and knowledge/work map at that existing boundary. It creates no second task queue, model server, schema, timer or execution permission.

`website/app/Services/Hermes/HermesOperatingContext.php` is a new runtime dependency. `HermesConversationMemory.php` here is an additive release overlay of the inspected production file, which is absent from tracked `website/`. Only `context()` changes: add the structured operating model, reserve room by reducing the JSON budget from 9,000 to 7,600 characters, and prepend the fixed working instructions. All memory extraction, source filters, deterministic replies and customer-send rules remain unchanged. The operating model survives truncation even when large application evidence is explicitly marked unavailable.

This is prompt/context guidance, not fine-tuning, semantic mastery, a deterministic permission gate or proof that a tool works. The existing application's policy/approval code remains the enforcement point. Private identity, personal mail, immigration history, credentials and customer data are intentionally absent from this public repository.

## Installation and acceptance

Use an authenticated terminal on the existing application host. Before installation, compare the current live conversation-memory hash with `release-manifest.json`. Hold the project's normal release locks; preserve any concurrent change. Stage/lint the dependency and overlay privately, back up both destinations, then install the dependency before the consumer. Apply only the environment's existing targeted OPcache procedure. Do not add a public execution helper, temporary cron or persistent key to overcome lack of shell access.

Call `HermesConversationMemory::context()` on-server with synthetic owner/runtime fixtures without touching customer state or sending a message; verify the operating-model version and total relay size. Test a normal owner context using the existing authenticated app and observe the VM response before reporting model uptake. A file hash alone proves installation, not runtime execution or learning. No live owner chat or customer message was sent by this release.

Audit VM127 separately through the existing SSH/Proxmox guest-agent path: current version, effective model/context, three relevant services, queues/worker progress and a synthetic tool round trip. Do not print environment variables or raw logs. Compare current SOUL/context/memory with this policy and merge only the relevant additions; do not overwrite the VM's identity or memory. A gateway restart must wait for in-flight work and use the existing operational procedure. No global upgrade is part of this release.

Rollback only this overlay and the newly unused dependency after verifying their current hashes against this release. Preserve operations state, learned cases, task receipts and any later work; no SQL or whole-directory rollback.

## Verification limits

Local context tests verify data isolation, payload budget, preserved authority and truncation behavior. They do not evaluate model reasoning quality. Model acceptance uses the scenario rubric in the development plan and actual outcome evidence. VM health, current queue counts and model uptake are not inferred from historical reports or file metadata.
