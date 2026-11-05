# Hardware specification and economic decision

As of 2026-11-03, this change contains no live Hetzner product quote or confirmed
stock/subnet pricing. Restricted development networking prevented current
official catalog verification. The exact purchasable Hetzner SKU, location,
minimum contract, VAT basis, setup and subnet products therefore remain **a
purchase blocker**, rather than being inferred from a historical model name.

## Request-for-quote profiles

| Resource | Minimum first-node profile | Recommended evaluation profile |
|---|---|---|
| CPU | 16 physical x86-64 cores with VT-x/AMD-V | At least 32 physical cores; logical SMT threads recorded separately |
| Memory | 64 GiB, ECC preferred/verified | 128 GiB ECC or more, verify ECC operation |
| Storage | Two 1 TB-class enterprise NVMe, mirrored, endurance/health measured | Two 1.92 TB-class enterprise NVMe or larger, mirrored, power-loss/endurance verified |
| Network | Dedicated 1 Gbit/s port with documented sustained/traffic terms | 1 Gbit/s or better, independent traffic/overage budget and VPN suitability |
| Management | Out-of-band/KVM access and supported rescue recovery | Same, with independent operator access and replacement procedure |
| Backup | Independent off-node encrypted target | Verified Proxmox Backup Server/storage, retention and restore capacity |
| Software | Supported Debian 13/PVE 9 compatible hardware | Subscription/support policy explicitly selected and costed |

These are exact **required resource profiles**, not claims that any named SKU is
currently sold at a particular price. Request a matching German dedicated offer
through [Hetzner Dedicated Root Servers](https://www.hetzner.com/dedicated-rootserver/)
and [Server Auction](https://www.hetzner.com/sb/) if suitable; auction hardware
requires exact CPU/disk/RAM health verification. A rental is not purchased until
the software gate, current quote and explicit owner approval are complete.

For the recommended 32-core example, reserve four physical cores and 16 GiB RAM
for host/emulator/storage overhead. With no CPU overcommit, CPU alone limits a
homogeneous fleet to 28 one-vCPU guests or 14 two-vCPU guests, before disk, IOPS,
network, traffic and public-IP constraints. Do not advertise 50/100 customers on
one node based only on available RAM or SMT thread count. CPU allocation above
1:1 requires measured saturation/tail-latency impact and an explicit policy;
RAM overcommit is excluded from the model.

## IPv4/subnet request

Request a provider-assigned **routed IPv4 subnet to the node's primary IP**, plus
the separate primary management IP. Verify that the chosen product supports the
subnet and routing model. Initial candidate: one `/27` per node (29 guest IPv4
after conventional network/broadcast and one bridge gateway), subject to the
supplier's actual reservations. A `/26` yields 61 under the same model, but is
not a reason to overfill CPU/I/O capacity. A 100-customer fleet needs sufficient
IPs across the modeled multiple nodes, not a fictitious single address pool.

Record exact current subnet product name, usable assignments, recurring cost,
one-time setup, VAT, rDNS ownership and any migration limitations from the quote
and [Robot network documentation](https://docs.hetzner.com/robot/dedicated-server/network/).
No movable-IP/VRRP/failover behavior is assumed. Reverse DNS uses only documented
Robot capabilities and must verify assigned-address ownership; it is not a VM
power/lifecycle operation.

## Commercial model

Run the [Decimal calculator](../../../infra/cloud-engine/calculators/README.md).
Its example includes synthetic EUR rental, IP/subnet, setup amortization, VAT,
storage, backup, traffic, FX, fees and overhead. The target is **1,000,000 Iranian
Tomans (IRT) per month = 10,000,000 IRR**, and FX is IRT per EUR. Dated current
FX/supplier values must be supplied before viability is established.

The reproducible synthetic example produces these **noncommercial illustrations**:

| Plan | Conservative guests/node | 10 customers | 20 customers | 30 customers | 50 customers | 100 customers |
|---|---:|---:|---:|---:|---:|---:|
| 1 vCPU / 1 or 2 GiB | 28 | 1 node | 1 node | 2 nodes | 2 nodes | 4 nodes |
| 2 vCPU / 2 or 4 GiB | 14 | 1 node | 2 nodes | 3 nodes | 4 nodes | 8 nodes |

At the synthetic inputs, the first one-vCPU break-even is 23 customers; adding a
second node at 29 customers temporarily makes the fleet negative again. Neither
two-vCPU size reaches break-even within the model's 10,000-customer search bound.
This demonstrates why a target price and high occupancy do not prove viability.
Do not use the illustrative result as a price change or capacity acceptance.

Before approval, attach a dated quote and measured workload assumptions, compare
downside FX/traffic/occupancy scenarios, calculate cash setup cost separately
from amortized margin, and retain operating reserve for failed disks, support,
customer refunds and spare restore capacity. Unmodeled legal/tax/license costs
are outstanding inputs, not zero by assumption.
