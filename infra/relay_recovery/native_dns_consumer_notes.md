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
coverage. Writing an arbitrary artifact does not prove ownership. The explicit
signed handoff and fixed guest transport described below add a separate dynamic
mode; the legacy static mode above keeps its pinned artifact. Actual installation
and enrollment remain necessary. This builder updates no installed config/SPEC pin.
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
source reachability, installation/enrollment of the metadata refresh and fixed
transport components, input pin updates and live end-to-end signed acceptance
remain necessary before enabling the recovery workflow.

## Explicit signed ownership handoff

`collector.dns_ownership_refresh: true` opts the ordinary publisher into a fresh
protected `dns_ownership` read. The default remains unchanged. The reader retains
canonical source and ownership bytes privately. The active probe receives a
data-only `params.dns_ownership` object with exactly `schema`, `source`,
`source_receipt_sha256`, `artifact`, and `artifact_sha256`. Its schema is
`servernet.relay.dns-ownership-handoff.v1`. Local source/artifact paths never
enter the wire message. The manifest jobs hash is computed after attachment, and
the existing gateway response signature authenticates those bytes.

The source identity/configuration belongs to `params.resource_identity` and
`resource_config_hash`. A manifest containing several resources may have a later
wire generation/configuration; neither value can rebind an active source to a
candidate. The native receipt preserves the original wire scope. A candidate
without an exact matching protected source receives no handoff and fails DNS
acceptance in the dynamic mode.

The dynamic guest's immutable private enrollment uses schema
`servernet.relay.dns-handoff-config.v1` and exactly these fields: `agent_id`,
`binding`, `source_identity`, `primary`, `secondary`, and
`ownership_enrollment_sha256`, plus `schema`. Binding and endpoint shapes are the
same as above; source identity is the exact provider/project/provider ID/resource
generation. The protected controller enrollment SHA is a local trust pin. This
file contains no renewable ownership artifact. Run the same native helper with
`--handoff-config PRIVATE_PATH --config-sha256 HEX --agent-id INSTALLED_AGENT`.
It verifies the protected source, canonical hashes, exact artifact reconstruction,
enrolled owner/service/project and resource identity, source observation/receipt
clocks, and original 900-second expiry before any socket. These checks are one
implementation shared by the transport and native helper. They do not consume
the gateway nonce again; authoritative measurement occurs once on the guest.

`dns_guest_transport.py` is an optional fixed PVE consumer SPEC. Its private
`servernet.relay.dns-guest-transport.v1` config contains only `schema`, `agent_id`,
`native_enrollment`, `ssh`, and `guest`. `native_enrollment` is a path/hash to a
local copy of the guest enrollment. `ssh` contains a pinned executable, port,
existing private identity-file selector, pinned dedicated known-hosts file and exact
ed25519 host fingerprint. Key bytes are not read by this transport. `guest`
contains path/hash pins for the existing real Python binary, installed native
script, installed `protocol.py`, and guest enrollment. Script/protocol must share
the trusted installed directory; guest enrollment bytes must equal the local
copy. An enclosing pinned SPEC invokes the transport with fixed local config,
config SHA and agent ID. Every local and imported runtime input requires its
explicit deployment pin.

SSH uses strict known-host checking, only the enrolled ed25519 host identity,
the existing key, public-key authentication and no forwarding, proxies, dynamic
commands or new trust. A fixed remote loader checks root ownership, safe parents,
regular files, hashes and interpreter identity before compiling the installed
native/protocol bytes. It runs the native CLI with `-E -S -B`; the original signed
context is stdin data. No network-provided path, source text, executable, command
or guest selector is accepted. The dedicated known-hosts file contains exactly
one literal guest entry and its enrolled ed25519 key; wildcard, hashed, alias,
marker or additional key entries are rejected even if that file's hash is pinned.
This narrow file may copy an already trusted guest public key; it does not create
a new key or expand host trust. The transport checks the exact returned scope,
original ownership clocks and source/artifact hashes, then rechecks its local
pins and expiry. The existing eight-second outer deadline is retained.

An absent, stale or rejected fresh source does not read a static artifact in this
mode. Passing signed data to the legacy static mode also fails. No mutable
artifact file is rewritten on PVE or the guest; no lease is renewed from delivery
time. This source wiring does not enroll endpoints/keys, open DNS ports, alter
publisher configuration, enable timers, validate the actual application database
deployment, or turn a manually invoked diagnostic into an authenticated pipeline
receipt. Those remain separate deployment and live acceptance requirements.
