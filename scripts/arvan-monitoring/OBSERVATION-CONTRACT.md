# Private traffic observation contract

Status: the concrete command and minute schedule are supplied by the
[account-private UDS runner](UDS-OBSERVATION-RUNNER.md), disabled until explicit
transport and per-service approval. No target, tunnel, credential or server
configuration is installed by these sources. No billing or suspension path
consumes this evidence.

The runtime command supplies a fixed canonical account-owned0600 Unix socket
with a0700 parent and reviewed metadata pins; there is no dummy TCP endpoint
or TCP fallback. A separately installed SSH forward must reach the
[query-only collector proxy](QUERY-PROXY.md) using the approved identity and
pinned host key. This code does not establish or repair transport. The older
loopback constructor remains for compatibility and isolated tests; it is not
used by the app runner. Loopback TCP alone is not tenant isolation on the
shared cPanel host and must not be exposed there without access control.

Before enrollment, independently match the current service, customer, instance,
provider reference, guest/default-route device and a newly generated enrollment
ID. Add these labels from trusted central inventory to the actual target:

| Label | Value |
| --- | --- |
| `job` | `servernet-arvan` |
| `snet_service_id` | Current positive service ID |
| `snet_instance_id` | Current positive local instance ID |
| `snet_customer_id` | Current positive customer ID |
| `snet_provider_ref` | Exact current region:UUID reference |
| `snet_enrollment_id` | Random 32-character lowercase hex ID |

The ID identifies an enrollment generation; it is **not** an authentication
secret. Existing pilot service613 is not assumed compatible until its actual
labels, selected device and transport have been checked. Other services remain
unknown until genuinely enrolled. Never label arbitrary guest data as trusted
inventory or accept IDs/endpoints from customer requests.

The adapter performs one bounded `/api/v1/query` GET selecting `up`, netdev
collection success, selected-device RX/TX counters and boot time, together with
`timestamp(metric{...})` evidence for each required metric. Prometheus instant
query result timestamps are evaluation times and cannot prove scrape freshness.
The original scrape timestamp comes from the timestamp expression's **value**;
identity labels are added after `timestamp()` receives its direct selector.
Repeated polls of the same source scrape retain the same checkpoint time and
cannot advance usage. Every value and timestamp evidence row must match the
inventory labels. Missing, duplicate, stale, future, incoherent,
wrong-owner/reference/generation/device or partial/warning responses fail unknown
with no sample. It does not contact guest exporters or provider APIs directly.

Selectors explicitly filter network values and timestamp evidence to the
reviewed device, and collector health to `collector="netdev"`. Default exporters
may expose many NICs/collectors; those other series are not fetched or added to
usage. A healthy single target therefore needs five values and five timestamp
evidence series, independent of its other exporter collectors. Wrong-device
series returned despite the selector still fail unknown. This query contract
must be accepted against the actual Prometheus deployment before integration;
isolated response tests do not establish production enrollment or compatibility.

Prometheus semantics: [instant selectors and lookback](https://prometheus.io/docs/prometheus/latest/querying/basics/#instant-vector-selectors),
[staleness](https://prometheus.io/docs/prometheus/latest/querying/basics/#staleness)
and [`timestamp()`](https://prometheus.io/docs/prometheus/latest/querying/functions/#timestamp).

Pass the sample to `ArvanTrafficObservation::evaluate()` with the current trusted
identity, a previous checkpoint and the current synchronized Unix time. A null
checkpoint means an **explicit prospective baseline**, with no historical
usage/delta. Only fresh increasing-time observations establish deltas. Reboot,
counter rollback, replay, gaps longer than150seconds or changed identities do
not advance the checkpoint. Re-enrollment/rebaseline is a separate reviewed
operation; never silently replace a failed checkpoint to erase an unknown gap.

Preserve the old checkpoint when a result is unknown. The concrete runner
locks and revalidates current ownership/provider identity before and after I/O,
then compares exact prior configuration/checkpoint bytes before persisting.
A disabled/failed central transport must not be
misinterpreted as one customer's exporter failure or trigger fleet suspension.

Counters describe the selected **guest interface**, which can include private,
free, incoming, retransmitted or otherwise nonbillable bytes. They are early
operational observations, not authoritative financial usage. Guest root can
alter them. Provider invoice reconciliation, tariffs, wallet reservations,
financial idempotency and confirmed lifecycle controls remain separate.

Standalone validation (does not bootstrap the app or need production secrets):

```bash
php scripts/arvan-monitoring/test-traffic-observation.php
```
