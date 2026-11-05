# Cloud Engine delivery and acceptance

The [architecture audit](../CLOUD-ENGINE-ARCHITECTURE.md) precedes implementation.
This package prepares a separate dedicated infrastructure provider while existing
Hetzner Cloud/Robot and Iran Proxmox services retain their existing lifecycle.
Code in Git, a generated configuration and mocked tests are separate from a
production installation or physical-node acceptance.

Cloud Engine sales support **monthly, quarterly, semiannual and yearly VPS** (1/3/6/12 months) through the existing
invoice/Wallet settlement and renewal flow. Hourly orders are blocked even when
submitted with forged price fields. Hourly delivery needs explicit asynchronous
billing-hold/recovery integration and separate acceptance before it can be offered.

| Deliverable | Entry point |
|---|---|
| VPS parity, implemented capabilities and remaining work | [Feature parity](FEATURE-PARITY.md) |
| Dedicated node preparation, network, TLS, backup and recovery | [Node runbook](NODE-RUNBOOK.md) |
| Software versus physical acceptance and rollout gates | [Acceptance](ACCEPTANCE.md) |
| Security review and remaining real-node work | [Security review](SECURITY-REVIEW.md) |
| Dated purchase specifications and commercial inputs | [Hardware and economics](HARDWARE-AND-ECONOMICS.md) |
| Inert node candidates and read-only checks | [Onboarding tools](../../../infra/cloud-engine/onboarding/README.md) |
| Verified artifact and per-image acceptance | [Image preparation](../../../infra/cloud-engine/images/README.md) |
| Executable capacity/cost model | [Calculator](../../../infra/cloud-engine/calculators/README.md) |

Do not purchase or activate new hardware while the software purchase gate has
outstanding evidence. A successful gate result also does not authorize a
purchase; the owner gives that decision separately. No current supplier quote,
image download, physical-node deployment, customer migration or live service
modification is claimed by these documents.
