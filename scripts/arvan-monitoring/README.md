# Linux guest exporter preparation

Status: the reviewed standalone installer also supplies the pinned source for
the [automatic Ubuntu/Debian create hook](../../docs/project/ARVAN-LINUX-FIRST-BOOT-2026-11-03.md).
Git source and isolated tests do not establish production installation, central
enrollment, suspension or billing.

The separate [account-private UDS observation runner](UDS-OBSERVATION-RUNNER.md)
supplies real CLI/schedule and bounded per-generation state, with an explicitly
approved [query-only collector proxy](QUERY-PROXY.md). It stays disabled until
native transport and target enrollment are accepted. An installed exporter is
not itself central observation or billable-traffic proof.

This standalone installer is for an authorized root session inside one Debian
or Ubuntu guest (amd64/arm64). It requires Python 3, systemd, iproute2 and current
trusted apt metadata; it does not add repositories or refresh metadata itself.
It downloads the exact official `prometheus-node-exporter` candidate with apt,
checks Release Origin/Site, package SHA-256/size/name/version/architecture, and
extracts its binary. It never runs package maintainer scripts, installs/upgrades
system packages, modifies vendor exporter units/defaults, or changes firewall,
SSH, credentials or keys.

1. Read-only inventory (also the default):

   ```bash
   python3 install-linux-exporter.py
   ```

2. Review the reported default-route interface, exact package and inventory
   digest. Multiple default interfaces require `--interface ens3`, with the
   actual observed interface name. The selected default-route device is an
   observation; its counters are **not proven public or billable traffic**.

3. Apply that exact inventory in the same authorized guest:

   ```bash
   python3 install-linux-exporter.py --apply --inventory-hash REVIEWED_SHA256
   ```

`--help` performs no inventory. Apply rechecks inventory under a lock and refuses
changed sources, existing exporter/config/units/listeners, symlink targets and
modified managed files. Exact managed reruns verify rather than reinstall.

The only new runtime paths are `/opt/servernet-node-exporter`, its manifest, the
`servernet-arvan-node-exporter.service` systemd unit and its enable link, and an
installer lock under `/run/lock`. The service uses DynamicUser and binds only
`127.0.0.1:19100`. Verification requires an active service, its actual PID owning
that loopback listener, successful netdev collection and finite nonnegative RX
and TX cumulative byte counters for the selected device. Startup is verified
with bounded retries. Failure stops the newly created service and removes only
this invocation's new installation; an unexpected surviving listener requires
operator review. Existing installations are never automatically replaced.

Loopback counters alone do not protect revenue: the central private transport,
Prometheus enrollment, collector freshness supervision, provider-side stop
confirmation, counter-reset handling and final financial reconciliation are
separate components. A root customer can alter guest telemetry. Do not infer
fraud, automatic suspension or wallet debits from successful local installation.
Windows requires its own reviewed Windows exporter/bootstrap implementation.

Isolated behavioral tests (no apt downloads, network or service mutations):

```bash
python3 -m unittest discover -s scripts/arvan-monitoring -p 'test_linux_exporter.py' -v
python3 -m py_compile scripts/arvan-monitoring/install-linux-exporter.py
```
