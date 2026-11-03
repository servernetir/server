# Native isolated candidate probe

Base: `b28557fed39e8a5cce60b77839825b766b5d32dc`. This change adds a native
guest operation and a separate enrolled Python client. It is disabled by default,
does not create network access, and is not connected to the scheduler or CLI.
No production enrollment, native Windows test, candidate transport or recovery
drill is established by the offline Python tests.

## Enrollment boundary

The existing default-path probe, endpoint, HMAC key, source admission and access
policy are retained. The installer does not create candidate enrollment.
Missing or invalid enrollment rejects only the candidate operation.

The Windows runtime reads the fixed private `candidate-proxy.json` alongside
its existing config. It requires canonical JSON (surrounding whitespace is
allowed), the same private ACL policy, at most 4096 bytes and exactly:

| Field | Requirement |
| --- | --- |
| `schema` | `servernet.relay.vm-candidate-proxy.v1` |
| `enabled` | Boolean `true` after separate local review; otherwise unavailable |
| `agent_id` | Exact existing agent binding |
| `proxy_host` | Canonical numeric IPv4, exact `127.0.0.1` or RFC1918 |
| `proxy_port` | Integer 1024–65535 |
| `proxy_binding_sha256` | Digest defined below |

The digest covers canonical sorted JSON containing `agent_id`,
`path_profile: candidate_socks_v1`, `probe_url: https://api.ipify.org`,
`proxy_host`, `proxy_port`, `schema: servernet.relay.vm-candidate-proxy.v1`, and
`source_ip: 10.10.10.50`. `proxy_binding()` in the new client computes it.
No live host, port or access policy is selected by these source files.

A trusted PVE caller passes the matching enrollment explicitly. The optional
`load_enrollment(path, expected_sha256, agent_id=...)` verifies the root-private
file, its independent hash pin, canonical JSON and deterministic endpoint
binding. There is no automatic enrollment-file discovery or key rotation.

The enrollment digest binds a reviewed endpoint; it does not establish that
endpoint's transport, access control, ownership, current candidate mapping or
runtime isolation. Those still need independent observation. The existing
CT113 candidate SOCKS listener is loopback-only; this change does not expose it
or make it reachable from Windows. The real fixed transport must be reviewed
and tested before enrollment, and unknown topology remains HOLD.

## Wire operation

The additional `candidate_probe` request uses the existing fixed VM endpoint and
HMAC framing. It contains exactly `candidate`, `op`, `scope`, `target`.
Candidate fields bind the path profile, endpoint digest, registry digest,
dependency baseline, resource identity/config hash, stage profile and restore
epoch/hash. A trial has explicit null restore fields. The restored profile
requires a positive integer epoch and a lowercase SHA-256 hash. The request
never includes a URL, proxy address, port, command or executable.

After source/HMAC/canonical-JSON/nonce checks, Windows executes its fixed curl
binary against only the existing HTTPS ipify URL through the enrolled numeric
SOCKS5 endpoint. It preserves the fixed source address, TLS verification,
deadlines, output bounds and environment cleanup; no guest routes or global
proxy settings change. Failure does not fall back to the default route.

The response is canonical sorted JSON using the distinct
`servernet.relay.vm-candidate-probe.v1` schema. It signs the exact candidate,
generation/revision, registry baseline, proxy binding and restore scope plus the
native guest egress observation. It cannot be relabelled as default-path proof.

## Client and final acceptance

`WindowsCandidateProbeClient(existing_config, reviewed_enrollment).probe(context)`
accepts only the exact `isolated_candidate_v1` context parameters and returns
the original signed guest envelope after authenticating and validating it.
Missing enrollment, drift, stale measurements, replay, schema errors and failed
egress raise fixed `ProtocolError` codes. A six-second monotonic budget includes
the existing fixed-endpoint HTTP exchange. The ordinary client/API is unchanged.

The returned envelope is only one input to `IsolatedAcceptanceVerifier`. A
trusted caller must bracket the guest call with fresh complete dependency
registry observations and a real CT candidate-stage measurement. Use separate
durable replay stores for the client transport and the final verifier. Neither
the envelope nor isolated acceptance proves default-path cutover, critical
consumer readiness, source fencing, backup restoration or the final DNS/CRM
transaction. No success hooks or automatic core gates are added.

## Validation and deployment limits

Offline Python tests cover the real HMAC framing and final isolated verifier,
candidate/epoch/digest binding, fail-closed enrollment, replay, policy drift,
fixed transport, typed/canonical messages and invalid/failed observations. They
use fake transport and temporary private files; no cloud/API calls occur.

The native PowerShell offline test script parses the runtime, extracts only
function definitions, and exercises enrollment/request/reply construction
without starting the listener or curl. It must be run with native Windows
PowerShell 5.1 before deployment. Linux source checks do not substitute for that
execution, real signed round trips or real candidate-path acceptance.

The runtime upgrade can use the existing installer only after native parser
and offline validation. Its existing binding/key/firewall preflight is
unchanged. Candidate enrollment and the reachable fixed transport are separate
reviewed operations. Rollback restores the previous reviewed runtime and keeps
the default probe's original config/key; disable candidate use by withholding
its separate enrollment. Do not arm recovery based only on this change.
