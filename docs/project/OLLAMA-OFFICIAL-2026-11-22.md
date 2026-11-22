# Ollama official image — 22 November 2026

Base: `20a55d08db0adadfec2510e0b63bedf260deb65d` (`develop`).

Customer ticket TK-261121-4436 reports HTTP 412 pulling `qwen3.5:4b`
with Ollama 0.24.0. New deliveries use the explicitly approved
`ollama/ollama:0.40.17`, rather than searching only Salad's stale wrapper
repository. The hourly `cloud:ollama-refresh` command now checks the latest non-draft, non-prerelease official GitHub release and requires an active Linux/amd64 Docker image. Its digest and version are stored atomically with the scoped Ollama catalog. Checkout makes no registry calls. Metadata discovery is not a full GPU regression test; each new instance must load a model and report GPU offload before readiness.

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

Release scope: `app/Services/Cloud/SaladOllamaImage.php`,
`app/Services/Cloud/SaladOperations.php`, the new `app/Console/Commands/RefreshOllamaRelease.php`
and the hourly entry in `routes/console.php`; image catalog/settings alignment is a
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

## Completion follow-up — 22 November 2026

Source base for this follow-up: `0765e213a79585914b98f12a943ca7cb7e5a2095`. The original two runtime files in production still matched `20a55d08db0adadfec2510e0b63bedf260deb65d`; the live console schedule has independent changes that the guarded three-way release must preserve. HTTP route cache was absent. The upstream latest stable release and its active Linux/amd64 manifest were independently read as 0.40.17.

Ticket 359 remains open; its current service 982 is stopped with zero provider instances. A stopped-service upgrade may preserve its group identity and customer endpoint; it must not start or charge that customer for acceptance testing. The authorized test service belongs to the owner and uses the ordinary GPU intent/provisioning/charge path, with a bounded stop guard and final empty-instance verification.

Stable discovery never downgrades, changes an approved tag's digest in place, accepts pre-releases, unpauses sales or patches existing groups. Source/registry failures retain the current valid release. The descriptor and catalog change share one DB transaction. The hourly job takes shared deployment locks; installation takes the corresponding exclusive locks. A corrupted descriptor or an arbitrary display setting cannot select an unverified image.

The official startup command waits for model loading and requires nonzero GPU offload from `ollama ps`; CPU-only fallback cannot mark a paid GPU ready. Five process tests and three activation tests pass locally. PHP tests and lint require CI because local PHP is unavailable. The activation helper uses the already verified application-owned FPM sockets, an HTTP-denied temporary primary with matching SCRIPT_NAME and exact cleanup. No root FPM configuration access or general cache flush is required.

Completion state: code prepared, installation and live model/version/generation acceptance remain pending. No customer reply has been sent by this follow-up.
