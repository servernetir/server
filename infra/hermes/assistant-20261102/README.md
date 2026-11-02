# Conversational assistant and project knowledge — 2 November 2026

Developer handoff. The current display name is **Araz / آراز**; Hermes service names, routes and runtime identity remain compatible. This directory retains the initial Nava release and later layers separately. Initial base: `develop`, `6443ab136e52ae51d1af73347971dd07ead10cb1`. See the historical [initial production evidence and limits](../../../docs/project/NAVA-ASSISTANT-2026-11-02.md), installed [Araz name](../../../docs/project/ARAZ-NAME-2026-11-02.md) and [chat polish](../../../docs/project/ARAZ-CHAT-POLISH-2026-11-02.md) reports. The [infra-context layer](infra-context/README.md) and final [infra-facts layer](infra-facts/README.md) are installed and hash-verified: 253 and 172 PHP checks respectively, plus 12 deployment guards for each. Direct model canaries failed grounding; the final local factual branch passed both live-class questions. See the [dated report](../../../docs/project/ARAZ-INFRA-CONTEXT-2026-11-02.md).

## Package boundaries

| Source | Runtime or purpose |
|---|---|
| `website/app/Services/Hermes/HermesAssistantContext.php` | Owner-only knowledge-layer, project-directory and freshness projection |
| `website/app/Services/Hermes/HermesRepositoryKnowledge.php` | Pure bounded parser, hourly due check, transactional source rotation and dated receipts |
| `website/app/Services/Hermes/HermesRelayMonitor.php` | Sanitized owner-only relay observation projection |
| `HermesKnowledge.php`, `HermesConversationMemory.php`, `HermesKnowledgeRefresh.php` | Captured live-source overlays mapped by `release-manifest.json` |
| `HermesMiniAppController.php`, `hermes.blade.php` | Authenticated chat-first mini-app and owner context integration |
| `owner_project_context.py`, `owner_ingress.py`, `install-native.py` | VM127 private owner-context reader and guarded initial installation |
| `relay_notifier.py`, `relay-notify.php`, `relay-probe.php`, `install-observer.py` | Notification-only off-relay observer, delivery adapter, optional path probe and scoped cron installation |
| `ui-followup/` | Separate four-file Nava/name/typing UI layer plus one-file VM127 display-name installer |
| `araz-name/` | Separate Araz naming layer, preserving earlier names in historical transcript exclusions |
| `chat-polish/` | Separate greeting routing and composer focus layer |
| `infra-context/` | Installed owner-only provider sales evidence and bounded core-receipt retention |
| `infra-facts/` | Installed narrow factual sales replies from current structured evidence; helper precedes Memory consumer |

Several production Hermes classes are absent from canonical application paths in this repository. These are explicit live snapshots, not permission to deploy another checkout's older versions. Preserve baseline hashes, overlay order and unrelated changes. New helper classes must be installed before their consumers. Do not flatten the initial and follow-up manifests.

## Historical initial and Nava acceptance

The initial eight web destinations and four-file follow-up were installed and verified through private deployment receipts. Twenty-three owner references were imported into the existing encrypted store, bringing the initial total to 174 references with five editable notes retained. Twelve Git summaries were imported separately during live source acceptance. The supplied project directory covers ServerNet, Kianpet, Urmia.city and Lubercar; counts overlap. A real Bale mini-app response identified those projects; a later UI response identified the assistant as Nava. VM127 received the initial helper/private payload/ingress and the Nava name layer. Receipt `1793621429` records an idle queue, only owner ingress restarted, and unchanged broker PID, helper and private payload.

The existing MCP integration already had seven tools. A real pre-install `proxmox_status` returned 28 QEMU guests (19 running, nine stopped) and six running LXC guests. This package does not install or expose an unrestricted remote shell.

Live repository receipt `1793620996` records SHA `93c556f680c20b70d11ebafcc60854375f852f39`, 12 documented summaries and `status=ok/stale=false`. Official feed status remains degraded solely by the existing cPanel issue; the six feed checks were due-policy skips, with five sources retaining usable observations. Observer cron receipt `1793620995` records one matching line and preservation of every other line. Its monitoring-unavailable alert was visible in Bale; natural executions at `1793621041` and `1793621161` followed, with a separate HTTPS-access-unavailable notification in the first. Authoritative recovery-controller state remains stale/unknown. No full relay health or recovery-controller activation is claimed.

## Continuous source refresh

The existing hourly `hermes:knowledge-refresh` command and native lock are reused; no second knowledge timer is created. Two credential-free, strict-TLS, no-redirect requests read the fixed public GitHub develop-branch endpoint, then `docs/project/README.md` pinned to the returned 40-character SHA. Responses are bounded to 64KiB and 256KiB. Only up to 12 safe dated index summaries are imported as owner-private `repository_document/documented` references.

Original report dates remain source dates. Undated, future-dated, invalid or sensitive entries are not assigned the fetch date. Only this source's managed `repo-index-*` records rotate; capacity or fetch failure retains the last-good reference set and original last-success timestamp. Future checked timestamps cannot suspend refresh. Source status, age and SHA are available in owner context. Git documentation never proves current production state or grants execution rights.

The private project-transfer payload is deliberately excluded from Git. It retains provenance and reference-only semantics; it does not train model weights, import the entire ChatGPT account or grant customer-send/financial authority.

## Validation commands

Run from an isolated repository checkout with PHP 8.4 and required extensions. These harnesses do not bootstrap production or use live secrets:

```bash
php infra/hermes/assistant-20261102/test-context.php
php infra/hermes/assistant-20261102/test-repository.php docs/project/README.md
php infra/hermes/assistant-20261102/test-relay-projection.php
python3 infra/hermes/assistant-20261102/test-deploy.py
node infra/hermes/assistant-20261102/test-ui.cjs
```

Run the Python owner-context, status and relay tests from this package directory so local imports resolve:

```bash
python3 -m unittest -v test_owner_project_context test_status test_relay_notifier
```

Run follow-up harnesses from their own layer/with their documented paths; do not count an initial-source test as acceptance of follow-up bytes. Historical initial/Nava counts: 153 PHP context and 22 relay-projection checks for Nava, 48 initial repository checks, 24 UI cases, 12 deployment tests, 33 native-path tests, 10 native-name-installer tests and 35 observer tests. Later layers record their own results. Live model/source/Proxmox/notification acceptance is separate from these isolated harnesses.

## Deployment and rollback

`native-release.php` is a private CLI launcher for `prepare/apply/verify/rollback`; it holds actual native worker locks and invokes the pinned reviewed Python deployment core. Initial manifest scope is eight web destinations; the four-file follow-up has its own launcher and exact initial-after baselines. Source deployment itself invokes no provider, model, message, schema migration or settings change. Do not serve or schedule deployment launchers.

Roll back only installed layers, each with its own launcher and manifest, in this order: **infra-facts → infra-context → chat-polish → araz-name → ui-followup → initial**. A drift refusal means inspect current changes, not force an older snapshot over them. Native display-name rollback uses the matching installed name layer's guarded installer (`araz-name/install-native-name.py` for Araz, then the historical `ui-followup` installer if applicable). Initial VM127 rollback requires the matching private receipt/backups, empty owner queue, deployment lock, current hash checks and only the affected source files; preserve helper consumers and installed-layer order.

Do not restore an old application/SQLite database over new notes, jobs, receipts or conversations. Do not replay uncertain external effects. If removing the relay observer, remove only its verified tagged cron line while retaining all later unrelated lines and the notification journal. See [relay observer contract](relay-notifier.README.md) for the distinction between controller health, monitoring availability and optional HTTPS-path observations.
