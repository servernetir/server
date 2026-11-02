#!/usr/bin/env python3
"""Read-only VM109 probe over a fixed private HTTP endpoint with a distinct key.

Public API: probe(context, config_path=DEFAULT_CONFIG), or
WindowsProbeClient(config).probe(context). Returns an Iran PROBE_SCHEMA vm109
receipt with exact scope/target and guest-observed source/egress. A failed or
unauthenticated probe is false; it never becomes a relay-path outage by itself.

Private root-owned0600 config schema servernet.relay.windows-probe-client.v1:
agent_id, key_file (RAW32 bytes), state_dir (root0700), optional host and port
which, if present, MUST equal 10.10.10.50 and19443. Endpoint /relay-probe/ is
fixed. Install a different random key than the controller/agent HMAC key.

Wire headers X-VM-Timestamp, X-VM-Nonce, X-VM-Signature authenticate UTF8 bytes:
SN-VM-PROBE/1\nMETHOD\n/relay-probe/\nTIMESTAMP\nNONCE\nSHA256(rawbody)
No trailing newline. Request purpose POST; response purpose RESPONSE. TTL30s,
future skew5s. Response body also binds request_nonce, scope, target and actual
observation time. Request/response are <=8192 bytes, response headers<=8192,
and the entire connect/send/read transport has a six-second monotonic deadline.
There are no redirects, proxy settings, DNS names, request URLs or commands.
"""
from __future__ import annotations

import argparse
import errno
import hashlib
import hmac
import ipaddress
import os
from pathlib import Path
import re
import secrets
import selectors
import socket
import stat
import sys
import time

try:
    from . import iran_adapter
    from .protocol import canonical_json, decode_json, private_file, ProtocolError, ReplayStore, IDENTIFIER, HEX64
except ImportError:
    import iran_adapter
    from protocol import canonical_json, decode_json, private_file, ProtocolError, ReplayStore, IDENTIFIER, HEX64

SCHEMA = "servernet.relay.windows-probe-client.v1"
WIRE_SCHEMA = "servernet.relay.vm-probe.v1"
DEFAULT_CONFIG = "/etc/servernet/relay-recovery/windows-probe.json"
HOST = SOURCE_IP = "10.10.10.50"
PORT = 19443
PATH = "/relay-probe/"
TTL = 30
DEADLINE = 6
MAX_BODY = MAX_HEADERS = 8192
SCOPE = ("agent_id", "incident_id", "generation", "revision", "config_hash", "jobs_hash")
NONCE = re.compile(r"\A[0-9a-f]{32}\Z")
TIMESTAMP = re.compile(r"\A[1-9][0-9]{9,11}\Z")


def canonical(method: str, timestamp: str, nonce: str, body: bytes) -> bytes:
    if method not in {"POST", "RESPONSE"} or not TIMESTAMP.fullmatch(timestamp) or not NONCE.fullmatch(nonce) or len(body) > MAX_BODY:
        raise ProtocolError("vm_wire_fields")
    return f"SN-VM-PROBE/1\n{method}\n{PATH}\n{timestamp}\n{nonce}\n{hashlib.sha256(body).hexdigest()}".encode("ascii")


def sign(key: bytes, method: str, body: bytes, now: int, nonce: str) -> dict:
    if not isinstance(key, bytes) or len(key) != 32: raise ProtocolError("vm_key")
    timestamp = str(now)
    digest = hmac.new(key, canonical(method, timestamp, nonce, body), hashlib.sha256).hexdigest()
    return {"X-VM-Timestamp": timestamp, "X-VM-Nonce": nonce, "X-VM-Signature": digest}


def verify(key: bytes, method: str, body: bytes, headers: dict, now: int, replay: ReplayStore):
    normalized = {}
    for name, value in headers.items():
        lower = name.lower()
        if lower in normalized: raise ProtocolError("vm_duplicate_header")
        normalized[lower] = value
    timestamp = normalized.get("x-vm-timestamp", ""); nonce = normalized.get("x-vm-nonce", "")
    signature = normalized.get("x-vm-signature", "")
    if not all(isinstance(v, str) for v in (timestamp, nonce, signature)) or not HEX64.fullmatch(signature):
        raise ProtocolError("vm_signature")
    message = canonical(method, timestamp, nonce, body)
    stamp = int(timestamp)
    if not -5 <= now - stamp <= TTL: raise ProtocolError("vm_response_expired")
    if not hmac.compare_digest(hmac.new(key, message, hashlib.sha256).hexdigest(), signature):
        raise ProtocolError("vm_authentication_failed")
    if not replay.claim("vm109-response", nonce, stamp + TTL, now):
        raise ProtocolError("vm_replay")


def load_config(path: str | Path, *, root_owned=True) -> dict:
    path = private_file(path, root_owned=root_owned)
    if path.stat().st_size > MAX_BODY: raise ProtocolError("vm_config_size")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, "rb") as source: cfg = decode_json(source.read(MAX_BODY + 1))
    validate_config(cfg, root_owned=root_owned)
    return cfg


def validate_config(cfg: dict, *, root_owned=True):
    if not isinstance(cfg, dict) or cfg.get("schema") != SCHEMA or set(cfg) - {"schema", "agent_id", "key_file", "state_dir", "host", "port"}:
        raise ProtocolError("vm_config_schema")
    if not isinstance(cfg.get("agent_id"), str) or not IDENTIFIER.fullmatch(cfg["agent_id"]):
        raise ProtocolError("vm_agent_id")
    if cfg.get("host", HOST) != HOST or type(cfg.get("port", PORT)) is not int or cfg.get("port", PORT) != PORT:
        raise ProtocolError("vm_fixed_endpoint")
    key = private_file(cfg.get("key_file", ""), root_owned=root_owned)
    if key.stat().st_size != 32: raise ProtocolError("vm_key_size")
    path = Path(cfg.get("state_dir", ""))
    if not path.is_absolute() or ".." in path.parts: raise ProtocolError("vm_state_dir")
    for parent in path.parents:
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or (root_owned and (info.st_uid != 0 or info.st_mode & 0o022)):
            raise ProtocolError("vm_state_parent")
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_mode & 0o077 or (root_owned and info.st_uid != 0):
        raise ProtocolError("vm_state_permissions")


def http_transport(host, port, path, body, headers, timeout=DEADLINE):
    """Minimal bounded nonblocking HTTP/1 response reader, fixed endpoint only."""
    if (host, port, path) != (HOST, PORT, PATH) or len(body) > MAX_BODY or timeout > DEADLINE:
        raise ProtocolError("vm_fixed_endpoint")
    deadline = time.monotonic() + timeout
    selector = selectors.DefaultSelector(); sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    def wait(event):
        selector.modify(sock, event)
        remaining = deadline - time.monotonic()
        if remaining <= 0 or not selector.select(remaining): raise ProtocolError("vm_timeout")
    try:
        sock.setblocking(False); selector.register(sock, selectors.EVENT_WRITE)
        result = sock.connect_ex((host, port))
        if result not in (0, errno.EINPROGRESS, errno.EWOULDBLOCK, errno.EALREADY):
            raise ProtocolError("vm_connect_failed")
        if result:
            wait(selectors.EVENT_WRITE)
            if sock.getsockopt(socket.SOL_SOCKET, socket.SO_ERROR): raise ProtocolError("vm_connect_failed")
        lines = [f"POST {PATH} HTTP/1.1", f"Host: {HOST}:{PORT}", "Content-Type: application/json", "Connection: close", f"Content-Length: {len(body)}"]
        if set(headers) != {"X-VM-Timestamp", "X-VM-Nonce", "X-VM-Signature"}:
            raise ProtocolError("vm_request_headers")
        for name, value in headers.items():
            if not isinstance(value, str) or any(c in value for c in "\r\n"): raise ProtocolError("vm_request_headers")
            lines.append(name + ": " + value)
        pending = memoryview(("\r\n".join(lines) + "\r\n\r\n").encode("ascii") + body)
        while pending:
            wait(selectors.EVENT_WRITE)
            try: count = sock.send(pending)
            except BlockingIOError: continue
            if not count: raise ProtocolError("vm_connection_closed")
            pending = pending[count:]
        received = bytearray(); response_headers = None; content_length = None; status = None; offset = None
        while True:
            wait(selectors.EVENT_READ)
            try: chunk = sock.recv(4096)
            except BlockingIOError: continue
            if not chunk: raise ProtocolError("vm_connection_closed")
            received.extend(chunk)
            if len(received) > MAX_HEADERS + MAX_BODY + 4: raise ProtocolError("vm_output_size")
            if response_headers is None:
                split = received.find(b"\r\n\r\n")
                if split < 0:
                    if len(received) > MAX_HEADERS: raise ProtocolError("vm_header_size")
                    continue
                if split > MAX_HEADERS: raise ProtocolError("vm_header_size")
                try: rows = received[:split].decode("ascii").split("\r\n")
                except UnicodeError: raise ProtocolError("vm_http_headers") from None
                match = re.fullmatch(r"HTTP/1\.[01] ([0-9]{3})(?: [\x20-\x7e]{0,100})?", rows[0])
                if not match: raise ProtocolError("vm_http_status")
                status = int(match[1]); response_headers = {}
                for line in rows[1:]:
                    if not re.fullmatch(r"[A-Za-z0-9-]+:[\x20-\x7e]*", line): raise ProtocolError("vm_http_headers")
                    name, value = line.split(":", 1); name = name.lower()
                    if name in response_headers: raise ProtocolError("vm_duplicate_header")
                    response_headers[name] = value.strip()
                length = response_headers.get("content-length", "")
                if "transfer-encoding" in response_headers or not re.fullmatch(r"[0-9]{1,5}", length) or int(length) > MAX_BODY:
                    raise ProtocolError("vm_http_length")
                content_length = int(length); offset = split + 4
            if len(received) > offset + content_length: raise ProtocolError("vm_http_extra_data")
            if len(received) == offset + content_length:
                return status, response_headers, bytes(received[offset:])
    except (OSError, TimeoutError):
        raise ProtocolError("vm_transport_failed") from None
    finally:
        selector.close(); sock.close()


class WindowsProbeClient:
    def __init__(self, config: dict, *, transport=http_transport, clock=time.time,
                 monotonic=time.monotonic, nonce=lambda: secrets.token_hex(16), root_owned=True):
        validate_config(config, root_owned=root_owned)
        self.config = config; self.transport = transport; self.clock = clock; self.monotonic = monotonic; self.nonce = nonce
        key_path = private_file(config["key_file"], root_owned=root_owned)
        fd = os.open(key_path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, "rb") as source: self.key = source.read(33)
        if len(self.key) != 32: raise ProtocolError("vm_key_size")
        self.replay = ReplayStore(Path(config["state_dir"]) / "responses.sqlite3")

    def probe(self, context: dict) -> dict:
        context = iran_adapter.validate_context(context, {"agent_id": self.config["agent_id"], "candidate_ports": [22, 443]})
        target = context["job"]["target"]
        if ipaddress.ip_address(target["host"]).version != 4: raise ProtocolError("vm_ipv4_target_required")
        scope = {k: context[k] for k in SCOPE}
        result = dict(schema=iran_adapter.PROBE_SCHEMA, probe_kind="vm109", read_only=True, ok=False,
                      target=target, **{k: context[k] for k in iran_adapter.SCOPE},
                      checks=dict(execution_origin="guest", authenticated_guest=False, source_ip=None,
                                  egress_ip=None, tunnel_ok=False, probe_ok=False))
        started = int(self.clock()); begin = self.monotonic()
        try:
            request_nonce = self.nonce()
            body = canonical_json(dict(op="probe", scope=scope, target=target))
            headers = sign(self.key, "POST", body, started, request_nonce)
            status, response_headers, raw = self.transport(HOST, PORT, PATH, body, headers, DEADLINE)
            if self.monotonic() - begin > DEADLINE: raise ProtocolError("vm_timeout")
            now = int(self.clock())
            if status != 200 or not isinstance(raw, bytes) or len(raw) > MAX_BODY: raise ProtocolError("vm_http_response")
            verify(self.key, "RESPONSE", raw, response_headers, now, self.replay)
            response = decode_json(raw)
            expected_keys = {"schema", "op", "request_nonce", "scope", "target", "observed_at", "read_only", "execution_origin", "source_ip", "egress_ip", "tunnel_ok", "probe_ok", "ok"}
            if set(response) != expected_keys or response.get("schema") != WIRE_SCHEMA or response.get("op") != "probe" or response.get("read_only") is not True or response.get("execution_origin") != "guest":
                raise ProtocolError("vm_receipt_schema")
            if response.get("request_nonce") != request_nonce or canonical_json(response.get("scope")) != canonical_json(scope) or canonical_json(response.get("target")) != canonical_json(target):
                raise ProtocolError("vm_receipt_binding")
            observed = response.get("observed_at")
            if type(observed) is not int or not started - 5 <= observed <= now + 5 or now - observed > TTL:
                raise ProtocolError("vm_observation_stale")
            if any(type(response.get(k)) is not bool for k in ("ok", "tunnel_ok", "probe_ok")):
                raise ProtocolError("vm_receipt_boolean")
            egress = response.get("egress_ip")
            try:
                address = ipaddress.ip_address(egress)
                valid_egress = isinstance(egress, str) and address.version == 4 and address.is_global and str(address) == egress
            except (TypeError, ValueError): valid_egress = False
            source = response.get("source_ip")
            good = valid_egress and source == SOURCE_IP and egress == target["host"] and all(response[k] is True for k in ("ok", "tunnel_ok", "probe_ok"))
            result["ok"] = good
            result["checks"].update(authenticated_guest=True, source_ip=source if source == SOURCE_IP else None,
                                    egress_ip=egress if valid_egress else None, tunnel_ok=good, probe_ok=good,
                                    observed_at=observed)
        except (ProtocolError, OSError, TimeoutError):
            # Deliberately no raw exception, body, key or endpoint details.
            result["checks"]["probe_unavailable"] = True
        return result


def probe(context: dict, config_path: str | Path = DEFAULT_CONFIG) -> dict:
    return WindowsProbeClient(load_config(config_path)).probe(context)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default=DEFAULT_CONFIG)
    args = parser.parse_args(); os.umask(0o077)
    try:
        raw = sys.stdin.buffer.read(16385)
        if len(raw) > 16384: raise ProtocolError("vm_context_size")
        result = probe(decode_json(raw), args.config)
        sys.stdout.buffer.write(canonical_json(result))
        return 0 if result["ok"] else 1
    except (ProtocolError, OSError):
        sys.stderr.write('{"ok":false,"code":"vm_probe_unavailable"}\n')
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
