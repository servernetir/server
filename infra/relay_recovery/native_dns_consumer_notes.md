# Native DNS consumer acceptance

`dns_consumer_probe.py` is a read-only source component for the existing
`consumer_probes.dns` contract. It does not install itself, discover zone
ownership, update DNS, permit a candidate, or enable the recovery driver.

Run the fixed helper on the enrolled DNS101 guest through an approved pinned
transport. Every UDP/TCP socket binds the guest's enrolled secondary IPv4. A
probe executed on PVE cannot replace that observation. The primary DNS port is
a separate private enrollment; the context target port remains the reviewed
SSH port. Repository examples using port 5300 do not establish a live endpoint.

The private config has exactly these fields:

```json
{
  "schema": "servernet.relay.dns-consumer-config.v1",
  "agent_id": "INSTALLED_AGENT_ID",
  "binding": {
    "owner_id": 1,
    "service_id": 1,
    "project_id": "ENROLLED_PROJECT_ID",
    "config_hash": "EXACT_CONTEXT_CONFIG_SHA256"
  },
  "primary": {
    "ipv4": "ENROLLED_PRIMARY_IPV4",
    "port": 5300,
    "provider_id": "ENROLLED_PROVIDER_ID",
    "generation": 1
  },
  "secondary": {"ipv4": "ENROLLED_GUEST_IPV4", "port": 53},
  "ownership_evidence": {
    "path": "/etc/servernet/relay-recovery/dns-ownership.json",
    "sha256": "EXACT_OWNERSHIP_FILE_SHA256"
  }
}
```

This is an intentionally invalid placeholder template. Replace every identifier,
address, port, binding and hash only from authorized metadata. The config and
ownership evidence must be regular root-owned files with mode 0600, one link,
and non-writable trusted parents. The interpreter, helper, `protocol.py`, config
and ownership artifact require explicit local deployment pins. Use Python `-B`.
An adapter SPEC allows a fixed executable wrapper or pinned script operands;
it rejects shell commands and interpreter `-c`/`-m` execution.

The ownership artifact has exactly:

- `schema`: `servernet.relay.dns-ownership.v1`;
- `agent_id` and `binding`: exactly equal to the config;
- `source_receipt_sha256`: the hash of the actual authenticated metadata receipt;
- `observed_at` and `expires_at`: original positive integer clocks, at most 900
  seconds apart;
- `catalog`: one canonical lower-case DNS name ending in a dot;
- `zones`: one to three distinct owner-approved `{id, name}` objects with
  positive integer IDs and canonical names, distinct from the catalog.

The component verifies the artifact's bytes and scope. The private
`dns_ownership.DnsOwnershipBridge` now reads actual protected panel ownership,
retains its canonical source receipt and creates the artifact with the original
backend observation and a maximum 900-second lifetime. It reads only fixed
privately selected owner canary IDs and the raw catalog setting through the pinned
PHP pipe. It uses no Setting cache/default or API secrets; an explicitly stored
internal `.invalid` catalog is not replaced with an example. The protected relay
service authorizes the selected customer-owned canary read; nullable zone
`service_id` is not claimed to equal that relay service. This is not full-catalogue
coverage. Writing an arbitrary artifact does not prove ownership. Signed job data
carriage, fixed guest transport/receiver and an explicit mutable data-digest mode
remain separate integration work. The current installed ownership/config/SPEC
hashes are not updated by this builder.
The helper never refreshes either clock or substitutes a catalog/example for an
owned service zone.

Before opening any socket, the helper validates the entire finite enrollment,
artifact hash, owner/service/project binding, target IPv4/provider/generation,
context configuration hash and metadata lifetime. An original job
`params.expires_at`, when present, also caps the operation and is copied unchanged.
Isolated candidate profiles cannot count as default-path acceptance. An unknown
candidate or old primary mismatch causes failure before any network request.

For the catalog and each owned zone, all four fresh SOA answers are required:
primary UDP/TCP and secondary UDP/TCP, with recursion disabled. Responses must
be authoritative NOERROR answers for the exact question and owner, without
truncation or aliases. MNAME, RNAME, serial, refresh, retry, expire and minimum
must match across the four responses. DNS compression, record counts, frame
sizes and network deadlines are bounded. There is no resolver or metadata
success fallback. Re-enrollment or expiry during observation also rejects the
result.

The helper emits `servernet.relay.iran-probe.v1`, `probe_kind: dns`,
`read_only: true`, original scope and exact context target. A successful receipt
has `checks.consumer: dns`, `path_profile: default_path_v1` and `probe_ok: true`.
Zone names, secondary addresses, records, raw packets and credentials are not
included. Failed or absent evidence keeps acceptance false. Each exchange has a
two-second budget; parallel work has a six-second maximum further capped by the
original clocks. The enclosing read-only adapter deadline remains eight seconds;
the approved guest transport must fit inside that deadline.

This observation proves matching authoritative service data at the explicitly
enrolled endpoints. It does not establish DNSSEC validation, public delegation,
TSIG transfer configuration, publisher migration, backup/restore consistency,
other consumers or retirement readiness. Live ownership selection, endpoint and
source reachability, the metadata refresher, fixed transport, input pin updates
and end-to-end signed acceptance remain necessary before deployment or enabling
the recovery workflow.
