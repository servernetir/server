"""Explicit, default-off client for the native VM109 isolated candidate probe.

This API is not wired into the agent CLI or recovery scheduler. A trusted caller
must supply a separately reviewed private enrollment and obtain fresh CT stage
and dependency-registry observations around the guest call. The signed envelope
returned here is only the guest part of ``isolated_acceptance``; it never proves
default-path readiness, registry coverage, source fencing or global cutover.

The guest endpoint and HMAC key/policy remain those of windows_probe_client.
Neither the context nor enrollment can select an HTTP URL, command or endpoint.
The sole enrolled SOCKS endpoint is used by the native Windows fixed-ipify
probe, not by this client. Missing enrollment is a HOLD, never automatic setup.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import ipaddress
import os
from pathlib import Path
import secrets
import time

try:
    from . import isolated_acceptance as isolated
    from . import windows_probe_client as windows
    from .protocol import canonical_json, decode_json, private_file, ProtocolError, ReplayStore, IDENTIFIER, HEX64
except ImportError:
    import isolated_acceptance as isolated
    import windows_probe_client as windows
    from protocol import canonical_json, decode_json, private_file, ProtocolError, ReplayStore, IDENTIFIER, HEX64


ENROLLMENT_SCHEMA = "servernet.relay.vm-candidate-proxy.v1"
PROBE_URL = "https://api.ipify.org"
ENROLLMENT_FIELDS = {"schema", "enabled", "agent_id", "proxy_host", "proxy_port", "proxy_binding_sha256"}
PARAMS = {"acceptance_profile", "stage_profile", "expected_registry_hash", "dependency_baseline_sha256",
          "proxy_binding_sha256", "resource_identity", "resource_config_hash"}
PRIVATE_NETWORKS = tuple(ipaddress.ip_network(n) for n in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))
SIGNATURE_HEADERS = {"x-vm-timestamp": "X-VM-Timestamp", "x-vm-nonce": "X-VM-Nonce", "x-vm-signature": "X-VM-Signature"}


def proxy_binding(agent_id, host, port):
    """Digest one reviewed local endpoint; never infer a live address or port."""
    if not isinstance(agent_id, str) or not IDENTIFIER.fullmatch(agent_id):
        raise ProtocolError("vm_candidate_agent_id")
    try:
        address = ipaddress.IPv4Address(host)
    except (ipaddress.AddressValueError, TypeError):
        raise ProtocolError("vm_candidate_proxy_address") from None
    if (not isinstance(host, str) or str(address) != host
            or not (host == "127.0.0.1" or any(address in network for network in PRIVATE_NETWORKS))):
        raise ProtocolError("vm_candidate_proxy_address")
    if type(port) is not int or not 1024 <= port <= 65535:
        raise ProtocolError("vm_candidate_proxy_port")
    body = dict(schema=ENROLLMENT_SCHEMA, agent_id=agent_id, path_profile=isolated.PATH_PROFILE,
                probe_url=PROBE_URL, proxy_host=host, proxy_port=port, source_ip=windows.SOURCE_IP)
    return hashlib.sha256(canonical_json(body)).hexdigest()


def validate_enrollment(enrollment, agent_id):
    if (not isinstance(enrollment, dict) or set(enrollment) != ENROLLMENT_FIELDS
            or enrollment.get("schema") != ENROLLMENT_SCHEMA or enrollment.get("enabled") is not True
            or enrollment.get("agent_id") != agent_id):
        raise ProtocolError("vm_candidate_not_enrolled")
    digest = proxy_binding(agent_id, enrollment["proxy_host"], enrollment["proxy_port"])
    supplied = enrollment.get("proxy_binding_sha256")
    if not isinstance(supplied, str) or not HEX64.fullmatch(supplied) or not hmac.compare_digest(digest, supplied):
        raise ProtocolError("vm_candidate_proxy_binding")
    return digest


def load_enrollment(path, expected_sha256, *, agent_id, root_owned=True):
    """Read an explicitly selected, pinned private file; no default discovery."""
    if not isinstance(expected_sha256, str) or not HEX64.fullmatch(expected_sha256):
        raise ProtocolError("vm_candidate_enrollment_pin")
    path = private_file(path, root_owned=root_owned)
    if path.stat().st_size > 4096:
        raise ProtocolError("vm_candidate_enrollment_size")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, "rb") as source:
        raw = source.read(4097)
    if len(raw) > 4096 or not hmac.compare_digest(hashlib.sha256(raw).hexdigest(), expected_sha256):
        raise ProtocolError("vm_candidate_enrollment_pin")
    enrollment = decode_json(raw)
    if raw.strip() != canonical_json(enrollment):
        raise ProtocolError("vm_candidate_enrollment_canonical")
    validate_enrollment(enrollment, agent_id)
    return enrollment


def request_body(context, enrollment, agent_id):
    """Project an exact typed isolated context to a URL/command-free request."""
    binding = validate_enrollment(enrollment, agent_id)
    p = isolated.parameters(context)
    allowed = PARAMS | ({"restore_epoch", "restore_hash"} if p["stage_profile"] == "restored" else set())
    if set(p) != allowed or context["agent_id"] != agent_id:
        raise ProtocolError("vm_candidate_context_fields")
    if not hmac.compare_digest(p["proxy_binding_sha256"], binding):
        raise ProtocolError("vm_candidate_context_binding")
    candidate = dict(dependency_baseline_sha256=p["dependency_baseline_sha256"],
        path_profile=isolated.PATH_PROFILE, proxy_binding_sha256=binding,
        registry_sha256=p["expected_registry_hash"], resource_config_hash=p["resource_config_hash"],
        resource_identity=p["resource_identity"], restore_epoch=p.get("restore_epoch"),
        restore_hash=p.get("restore_hash"), stage_profile=p["stage_profile"])
    return dict(op="candidate_probe", scope={k: context[k] for k in isolated.SCOPE},
                target=context["job"]["target"], candidate=candidate)


def _signature_headers(headers):
    if not isinstance(headers, dict):
        raise ProtocolError("vm_candidate_response_headers")
    result, seen = {}, set()
    for name, value in headers.items():
        if not isinstance(name, str) or name.lower() in seen:
            raise ProtocolError("vm_candidate_response_headers")
        lower = name.lower()
        seen.add(lower)
        if lower in SIGNATURE_HEADERS:
            if not isinstance(value, str):
                raise ProtocolError("vm_candidate_response_headers")
            result[SIGNATURE_HEADERS[lower]] = value
    if set(result) != set(SIGNATURE_HEADERS.values()):
        raise ProtocolError("vm_candidate_response_headers")
    return result


class WindowsCandidateProbeClient:
    """Explicit enrolled API; raises a fixed ProtocolError when unavailable.

    It uses its own durable transport replay database. The final acceptance
    verifier must use a separate durable replay database so it can independently
    authenticate the unmodified raw guest envelope exactly once.
    """
    def __init__(self, config, enrollment=None, *, transport=windows.http_transport,
                 clock=time.time, monotonic=time.monotonic, nonce=lambda: secrets.token_hex(16), root_owned=True):
        agent_id = config.get("agent_id") if isinstance(config, dict) else None
        validate_enrollment(enrollment, agent_id)  # No key/state I/O before explicit enrollment.
        self.enrollment = decode_json(canonical_json(enrollment))
        config = decode_json(canonical_json(config))
        self.client = windows.WindowsProbeClient(config, transport=transport, clock=clock,
            monotonic=monotonic, nonce=nonce, root_owned=root_owned)
        self.replay = ReplayStore(Path(config["state_dir"]) / "candidate-responses.sqlite3")

    def probe(self, context):
        client = self.client
        request = request_body(context, self.enrollment, client.config["agent_id"])
        raw_request = canonical_json(request)
        request = decode_json(raw_request)  # Freeze nested scope against caller mutation.
        if len(raw_request) > windows.MAX_BODY:
            raise ProtocolError("vm_candidate_context_size")
        started, begin = int(client.clock()), client.monotonic()
        request_nonce = client.nonce()
        headers = windows.sign(client.key, "POST", raw_request, started, request_nonce)
        try:
            status, response_headers, raw = client.transport(windows.HOST, windows.PORT, windows.PATH,
                                                            raw_request, headers, windows.DEADLINE)
        except (OSError, TimeoutError):
            raise ProtocolError("vm_candidate_transport_failed") from None
        now = int(client.clock())
        if client.monotonic() - begin > windows.DEADLINE:
            raise ProtocolError("vm_candidate_timeout")
        if status != 200 or not isinstance(raw, bytes) or len(raw) > windows.MAX_BODY:
            raise ProtocolError("vm_candidate_http_response")
        response = decode_json(raw)
        if canonical_json(response) != raw:
            raise ProtocolError("vm_candidate_response_canonical")
        c = request["candidate"]
        bound = {**{k: c[k] for k in ("stage_profile", "resource_identity", "resource_config_hash",
                  "restore_epoch", "restore_hash", "proxy_binding_sha256")},
                 "scope": request["scope"], "target": request["target"]}
        expected = {"schema", "op", "path_profile", "request_nonce", "observed_at", "read_only",
                    "execution_origin", "source_ip", "egress_ip", "tunnel_ok", "probe_ok", "ok",
                    "registry_sha256", "dependency_baseline_sha256", *bound}
        policy = client.config.get("access_policy", windows.DEFAULT_ACCESS_POLICY)
        if policy == windows.SOURCE_HMAC_POLICY:
            expected.add("access_policy")
            if response.get("access_policy") != policy:
                raise ProtocolError("vm_candidate_access_policy")
        if (set(response) != expected or response.get("schema") != isolated.GUEST_SCHEMA
                or response.get("op") != "candidate_probe" or response.get("path_profile") != isolated.PATH_PROFILE
                or response.get("read_only") is not True or response.get("execution_origin") != "guest"):
            raise ProtocolError("vm_candidate_response_schema")
        if (response.get("request_nonce") != request_nonce
                or canonical_json({k: response.get(k) for k in bound}) != canonical_json(bound)
                or response.get("registry_sha256") != c["registry_sha256"]
                or response.get("dependency_baseline_sha256") != c["dependency_baseline_sha256"]):
            raise ProtocolError("vm_candidate_response_binding")
        observed = response.get("observed_at")
        if type(observed) is not int or not started - 5 <= observed <= now or not 0 <= now - observed <= windows.TTL:
            raise ProtocolError("vm_candidate_observation_stale")
        if (response.get("source_ip") != windows.SOURCE_IP or response.get("egress_ip") != request["target"]["host"]
                or any(response.get(k) is not True for k in ("ok", "tunnel_ok", "probe_ok"))):
            raise ProtocolError("vm_candidate_path_failed")
        signed_headers = _signature_headers(response_headers)
        windows.verify(client.key, "RESPONSE", raw, signed_headers, now, self.replay)
        return dict(body_base64=base64.b64encode(raw).decode("ascii"), headers=signed_headers,
                    request_nonce=request_nonce)
