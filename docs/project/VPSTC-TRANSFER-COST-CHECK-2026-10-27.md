# VPS.tc 351: transfer and operation costs, 27 October 2026

Scope: public official sources only. No authenticated account request, message, order, resource change or billing change was performed **for this public-source review**. The separate authorized retained purchase is recorded in [27 October acceptance](VPSTC-RETAINED-TURKEY-ACCEPTANCE-2026-10-27.md).

| Topic | Supported by the checked sources | Still unresolved |
|---|---|---|
| Istanbul entry VPS | The public card advertises 1 core, 1 GB RAM, 20 GB NVMe and `2TB / 2x Traffic / 25 Gbit Port`. | Meaning of `2x`, counted traffic direction, quota reset details and guaranteed port throughput. The page also gives different generic network figures. |
| Traffic limit | Terms §4.1 commits to the selected package's resource/traffic allocation. | No checked source gives this product's per-GB overage price or promises automatic hard cutoff, throttling or suspension instead of an extra charge. An advertised allowance is not a verified no-overage contract. |
| Fair usage | Terms §12 permits shaping for sustained line saturation and applies fair usage to unlimited traffic. | This does not define what happens after the finite 2 TB allowance is exhausted. |
| Power | The VPS page advertises panel reboot; Swagger exposes start/stop/shutdown/reboot/reset. | No explicit included-price or zero-fee commitment was found. Permission alone does not establish cost. |
| Console | The VPS page advertises VNC; Swagger requires an authenticated browser cookie and a one-use 60-second grant. | No explicit zero-fee tariff or JWT-only reseller console contract was found. |
| Snapshots/backups | The page advertises manual snapshots and separately purchasable optional daily/weekly backups. Terms §13.2 excludes an obligation to provide backup absent an additional agreement. | Snapshot creation, storage, retention, pinning and restore prices for 351 are not specified. No universally free snapshot/backup operation is established. |

Retained-server acceptance can use light observations and traffic counters. Do not exhaust the quota or infer a hard cap/free action from a successful read. General sales and optional mutation costs retain their existing acceptance gates.

Sources checked:

- https://www.vps.tc/en/vps
- https://www.vps.tc/en/terms (§4.1, §12, §13.2)
- https://portal.vps.tc/?cmd=userapi&action=swagger&display=1&redirect=1 — public Swagger `4.20261019`, SHA-256 `3eb8bf67934abcafe7f1546dd019d4d1a77a50cf8ae499bf498715a71e57e53e`, retrieved 27 October.
