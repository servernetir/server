# Private traffic observation contract

Status: standalone source and isolated tests only. These classes are not wired
to any route, scheduler, delivery, billing or suspension path. No target was
added and no tunnel, credential or server configuration was changed.

`PrivatePrometheusTrafficSource` accepts only an operator-configured HTTP
loopback endpoint, for example `http://127.0.0.1:19090`. Default is disabled.
That endpoint must already terminate a supervised, authorized SSH local-forward
to management VM127's **loopback** Prometheus listener. The SSH transport must
use the existing approved identity and pinned host key, with no public 9090 bind
or SSH-agent forwarding. This code does not establish or repair that transport.
A future HTTPS management proxy requires separate authorization, exact origin
allowlisting, authenticated access, TLS verification and redirect rejection;
this adapter deliberately does not accept arbitrary HTTPS or remote endpoints.

**Loopback is not tenant isolation on the shared cPanel host.** Other local
accounts can connect to a raw loopback TCP forward. Before integration, require
an existing authorized transport with access control that excludes other host
users and restricts access to the intended read-only query service. An
authenticated restricted proxy, or an account-permission-protected Unix-socket
transport supplied through the injected client, requires its own security and
operational acceptance. The default HTTP client does not provision or supply
that access control. Do not create an unauthenticated shared-host forward or
enable this component on the assumption that `127.0.0.1` alone is sufficient.
No forward or proxy is provisioned by this change; the component stays disabled
and unwired.

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

Preserve the old checkpoint when a result is unknown. Later integration must
lock and revalidate current ownership/provider identity before persisting a
checkpoint or taking an action. A disabled/failed central transport must not be
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
