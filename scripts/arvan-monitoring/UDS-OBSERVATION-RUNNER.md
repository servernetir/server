# Account-private traffic observation runner

This supplies a concrete command, persistence and minute schedule. It is
disabled until an operator approves the real transport and each current
enrollment. Source publication or an enabled setting does not prove an enrolled
target, two fresh samples or fleet coverage.

The command constructs the actual Unix transport and strict Prometheus parser.
It has no supplier API, exporter installation, wallet, invoice, power,
suspension or deletion calls. Guest counters remain untrusted, nonbillable
operational evidence. Financial checkpoints and the historical customer545
exclusion are unchanged.

## Transport and native acceptance

The only app-side endpoint is
`<canonical application root>/storage/app/private/arvan-observation/prometheus-query.sock`.
The canonical parent must be owned by the real/effective application UID with
exact mode0700. The canonical Unix socket must have the same UID, mode0600
and one link. Approval pins directory/socket device, inode, UID, GID and mode;
each request checks before connecting and after reading. A changed/recreated
socket produces unknown until explicitly reviewed and approved again. Polling
does not repair a forward or re-pin it.

The transport opens a literal Unix socket only: one fixed HTTP/1.0
`/api/v1/query` GET, `Host: localhost`, total5second monotonic deadline,
16KiB headers and256KiB body. It refuses redirects, duplicate headers,
chunked/compressed data, wrong JSON content type and incomplete/extra framed
bodies. There is no TCP, DNS, environment proxy, credential or customer-selected
endpoint in this path; the legacy TCP constructor is not used by the runner.

A separately installed supervised SSH stream-local forward must reach the
[query-only collector proxy](QUERY-PROXY.md). Prove another local account cannot
connect to the app socket; authenticate the collector host key through the
trusted channel; restrict the SSH key to the exact proxy destination; preserve
existing exporters and tunnels. No SSH or systemd installation is performed by
these sources. Shared-host loopback TCP alone is not access control.

Before enabling, record a private native acceptance receipt and its SHA-256.
Prove the actual PHP CLI can use this exact approved socket, the actual proxy
rejects other paths/queries, all ten metric/timestamp rows have current labels,
real scrape times increase, clocks agree, and transport loss becomes central
unknown. Synthetic tests do not establish these facts.

Inspect local metadata without changing approval:

```bash
php artisan cloud:approve-arvan-observation --inspect-pin
```

Create a regular single-link0600 JSON file directly inside that0700 directory.
The following values are synthetic; use the actual inspected pin and private
native acceptance receipt hash. Decimal448 means0700, decimal384 means0600.

```json
{
  "version": 1,
  "kind": "transport",
  "enabled": true,
  "operator_acceptance_sha256": "REVIEWED_NATIVE_ACCEPTANCE_RECEIPT_SHA256",
  "pin": {
    "directory": {"dev": 1, "ino": 2, "uid": 3, "gid": 3, "mode": 448},
    "socket": {"dev": 1, "ino": 4, "uid": 3, "gid": 3, "mode": 384}
  }
}
```

Approval checks canonical path, owner, mode, link count, stable file identity,
bounded JSON and the exact descriptor bytes against the separately supplied hash:

```bash
php artisan cloud:approve-arvan-observation \
  --descriptor=/CANONICAL_APP/storage/app/private/arvan-observation/transport.json \
  --sha256=REVIEWED_DESCRIPTOR_SHA256
```

To disable during an outage, approve `"enabled": false` with the existing
transport pin; the absent socket need not be recreated. This updates central
status and preserves all enrollment/checkpoint records.

## Trusted enrollment

Independently reconcile current customer, service, local instance, exact Arvan
region:UUID, authenticated guest/default-route device and a new32lowercase-hex
enrollment ID. Give the actual target labels and proxy allowlist that same tuple.
Service613's legacy labels are not assumed compatible. A new guest's separate
first-boot installation does not itself establish central enrollment.

Approve a private descriptor with the same command and exact reviewed file hash:

```json
{
  "version": 1,
  "kind": "enrollment",
  "enabled": true,
  "operator_acceptance_sha256": "REVIEWED_ENROLLMENT_ACCEPTANCE_RECEIPT_SHA256",
  "expected_previous_enrollment_id": null,
  "identity": {
    "service_id": 1,
    "instance_id": 2,
    "customer_id": 3,
    "provider_ref": "ir-test-one:00000000-0000-0000-0000-000000000001",
    "enrollment_id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "device": "ens3"
  }
}
```

These are synthetic values. No credential, IP, URL, arbitrary socket path,
tariff, threshold or customer input is accepted. Enabling verifies current
customer ownership, nonterminal service, exactly one local VM and exactly one
global Arvan owner of the provider reference. Every poll repeats those current
locking reads before and after UDS I/O. Lock order is Customer, Service,
Instance, then own settings. No transaction stays open during the source read.
The final write also compares exact enrollment, transport and checkpoint bytes
against the pre-read snapshot; reassignment, duplicates, disablement or a newer
checkpoint cannot accept an old response.

## Persistence and unknown states

Only uncached dedicated settings records are written:

| Key | Purpose |
| --- | --- |
| `arvan_observation_transport_v1` | Explicit transport approval |
| `arvan_observation_enrollment:<service>` | Current identity and checkpoint hash |
| `arvan_observation_generation:<service>:<generation>` | Retained generation approval |
| `arvan_observation_checkpoint:<service>:<generation>` | Sample, sequence, predecessor hash |
| `arvan_observation_status:<service>` | Latest bounded result or explicit unknown |
| `arvan_observation_progress_v1` | Fair scan cursor |
| `arvan_observation_run_v1` | Central run/configuration status |

Values are capped at16KiB. Discovery reads at most20keys; duplicate-resource
checks fetch at most two rows. Each pass checks a35second elapsed budget before
starting another poll; an active source read is capped at5seconds. Database
reads/locks use the database's configured timeouts and can extend total wall
time; a running job never proves current coverage. The cursor rotates past invalid or
orphan records. Large/slow fleets need enough accepted capacity to obtain a
sample for each enrollment within150seconds; an incomplete pass is not coverage.

The first sample is a prospective baseline with no historical use. Only a
second strictly newer, coherent, monotonic scrape within150seconds sets
`two_fresh_samples_verified` and yields guest RX/TX deltas. Repeated polling of
one scrape does not count twice. Unknown never advances the old checkpoint;
the enrollment independently pins its hash, so missing/changed history cannot
silently start another baseline.

Reboot, rollback, gap or changed identity requires a reviewed new generation:
specify the exact previous ID, a new unused ID and new acceptance evidence.
Retain old generation/checkpoint records. Same-identity reapproval cannot reset
history. Approval writes pending/unknown, and no generation can be reused.

Source/binding failures persist generic unknown, without raw exceptions,
Prometheus bodies, queries, IPs or credentials. A failed old poll cannot replace
newer enrollment/status bytes. Invalid central configuration or scan progress
records a central unavailable/review state. Missing storage remains unavailable;
it cannot be reported as persisted. No central failure proves a customer
disabled an exporter and no failure suspends a service.

Any reader must require enabled current transport/enrollment, current central
run, matching current identity and a result at most150seconds old, together
with `two_fresh_samples_verified`. A stored historical green result alone is
not current health. This change supplies no new UI or enforcement reader.

## Running

The natural minute schedule invokes this actual command with overlap protection:

```bash
php artisan cloud:observe-arvan --limit=20
```

Absent transport configuration is disabled: no source call or observation write.
Output contains bounded aggregate status/counts; a baseline counts as not yet
observed. It never dumps queries, provider references or bodies.

Install this source change after the separate current11-file PR247/248 release.
The appended console schedule is not part of that frozen deployment artifact.
Focused CI uses fake evidence, disposable local sockets and isolated testing
databases. Runtime enablement, labels, real transport and two actual fresh
samples remain explicit operational acceptance steps.
