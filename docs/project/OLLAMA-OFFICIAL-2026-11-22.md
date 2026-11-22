# Ollama official image — 22 November 2026

Base: `20a55d08db0adadfec2510e0b63bedf260deb65d` (`develop`).

Customer ticket TK-261121-4436 reports HTTP 412 pulling `qwen3.5:4b`
with Ollama 0.24.0. New deliveries use the explicitly approved
`ollama/ollama:0.40.17`, rather than searching only Salad's stale wrapper
repository. Future registry releases require a separate tested version bump.

`SaladOllamaImage` recognizes old saved references. `createServer` normalizes
Ollama references only at new creation, so queued legacy selections also receive
the approved image. Existing container groups are not patched or restarted.
Jupyter, ComfyUI, pricing, billing and customer authentication are unchanged.

The official image starts with the Salad recipe's IPv6/model-load command;
readiness becomes true only after the default `llama3.1` model runs. The local
client uses IPv6 loopback independently of the server's wildcard bind. Failed
model loading terminates the server instead of falsely declaring it ready.

Validation: three actual shell-process tests passed locally, using a fake Ollama
binary and no provider requests. PHP feature tests and lint run in the dedicated
Ollama delivery CI job. Local PHP is not installed in this workspace.

Release scope: only `app/Services/Cloud/SaladOllamaImage.php` and
`app/Services/Cloud/SaladOperations.php`; image catalog/settings alignment is a
separate scoped operation, not a full provider sync. Use private backups,
SHA-256 preconditions and the shared release lock. A changed live source must
be reconciled before deployment. Activate the relevant web OPcache afterward.
Rollback restores the two guarded files plus the captured catalog setting;
it does not recover ephemeral GPU disks or remove a provider instance.

Owner authorized one new test service on his own account. Before declaring
acceptance, verify the actual provider image, `/api/version`, full pull of
`qwen3.5:4b`, non-streaming and streaming generation through the normal customer
endpoint, GPU use, and stop confirmation. Record the service identifier and
actual cost. No customer announcement or migration is part of this release.

Status: implementation prepared; production and live acceptance pending.
