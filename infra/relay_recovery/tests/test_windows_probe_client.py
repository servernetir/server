"""Fake private HTTP/guest fixtures only; no Windows installation or live I/O."""
from __future__ import annotations

import copy
import hashlib
import hmac
import json
import os
from pathlib import Path
import socket
import sys
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import windows_probe_client as mod

ProtocolError = mod.ProtocolError
KEY = bytes(range(32))
NOW = 1800000000
NONCE = "1" * 32


class FakeGuest:
    def __init__(self):
        self.calls = []
        self.count = 0
        self.mutate = None
        self.tamper = False
        self.status = 200
        self.response_nonce = None
        self.timestamp = NOW
        self.header_extra = {}
        self.oversize = False

    def __call__(self, host, port, path, body, headers, timeout):
        self.calls.append((host, port, path, body, headers, timeout))
        self.count += 1
        request = mod.decode_json(body)
        expected = mod.sign(KEY, "POST", body, NOW, NONCE)
        if headers != expected: raise AssertionError("request signature or fixed nonce mismatch")
        response = dict(schema=mod.WIRE_SCHEMA, op="probe", request_nonce=headers["X-VM-Nonce"],
                        scope=request["scope"], target=request["target"], observed_at=NOW,
                        read_only=True, execution_origin="guest", source_ip=mod.SOURCE_IP,
                        egress_ip=request["target"]["host"], tunnel_ok=True, probe_ok=True, ok=True)
        if self.mutate: self.mutate(response)
        raw = mod.canonical_json(response)
        nonce = self.response_nonce or f"{self.count:032x}"
        signed = mod.sign(KEY, "RESPONSE", raw, self.timestamp, nonce)
        signed.update(self.header_extra)
        if self.tamper: signed["X-VM-Signature"] = "f" * 64
        if self.oversize: raw = b"x" * 8193
        return self.status, signed, raw


class ClientTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.base = Path(self.tmp.name)
        self.key = self.base / "probe.key"; self.key.write_bytes(KEY); self.key.chmod(0o600)
        self.cfg = dict(schema=mod.SCHEMA, agent_id="pve-ir", key_file=str(self.key), state_dir=str(self.base / "state"))
        self.guest = FakeGuest(); self.now = NOW

    def tearDown(self): self.tmp.cleanup()

    def context(self):
        return dict(schema=mod.iran_adapter.agent.SCHEMA if hasattr(mod.iran_adapter.agent, "SCHEMA") else "servernet.relay.recovery.v1",
                    agent_id="pve-ir", incident_id="incident-1", generation=2, revision=5,
                    config_hash="a" * 64, jobs_hash="b" * 64,
                    job=dict(id="probe-1", op="probe", target=dict(host="91.107.179.25", port=443, provider_id="173913007")))

    def client(self, **kw):
        return mod.WindowsProbeClient(copy.deepcopy(self.cfg), transport=self.guest, clock=lambda: self.now,
                                      nonce=lambda: NONCE, root_owned=False, **kw)

    def test_actual_guest_receipt_is_bound_and_fixed_endpoint_has_no_command_or_url_input(self):
        context = self.context(); result = self.client().probe(context)
        self.assertTrue(result["ok"])
        self.assertEqual(result["schema"], mod.iran_adapter.PROBE_SCHEMA)
        self.assertEqual(result["probe_kind"], "vm109"); self.assertTrue(result["read_only"])
        for field in mod.iran_adapter.SCOPE: self.assertEqual(result[field], context[field])
        self.assertEqual(result["target"], context["job"]["target"])
        self.assertTrue(result["checks"]["authenticated_guest"])
        self.assertEqual(result["checks"]["execution_origin"], "guest")
        self.assertEqual(result["checks"]["source_ip"], "10.10.10.50")
        self.assertEqual(result["checks"]["egress_ip"], "91.107.179.25")
        host, port, path, body, _, timeout = self.guest.calls[0]
        self.assertEqual((host, port, path, timeout), ("10.10.10.50", 19443, "/relay-probe/", 6))
        request = json.loads(body)
        self.assertEqual(set(request), {"op", "scope", "target"})
        self.assertEqual(request["scope"], {k: context[k] for k in mod.SCOPE})
        self.assertNotIn("https://", body.decode()); self.assertNotIn("curl", body.decode())

    def test_distinct_purpose_and_powershell_public_wire_vector_match(self):
        body = b'{"op":"probe"}'
        headers = mod.sign(KEY, "POST", body, NOW, "0" * 32)
        self.assertEqual(headers["X-VM-Signature"], "3e25b4f0216aa9b4bb5c2cc811d9d25059908c6cdf8d148795516fab29d93577")
        self.assertNotEqual(headers["X-VM-Signature"], mod.sign(KEY, "RESPONSE", body, NOW, "0" * 32)["X-VM-Signature"])
        self.assertFalse(mod.canonical("POST", str(NOW), "0" * 32, body).endswith(b"\n"))

    def test_bad_signature_stays_false_and_does_not_consume_nonce(self):
        self.guest.response_nonce = "9" * 32; self.guest.tamper = True
        client = self.client(); result = client.probe(self.context())
        self.assertFalse(result["ok"]); self.assertFalse(result["checks"]["authenticated_guest"])
        self.guest.tamper = False
        self.assertTrue(client.probe(self.context())["ok"])

    def test_response_nonce_replay_persists_across_client_restart(self):
        self.guest.response_nonce = "9" * 32
        self.assertTrue(self.client().probe(self.context())["ok"])
        self.assertFalse(self.client().probe(self.context())["ok"])

    def test_request_nonce_scope_target_and_type_binding_cannot_be_reused(self):
        changes = [lambda r: r.update(request_nonce="2" * 32),
                   lambda r: r["scope"].update(incident_id="incident-other"),
                   lambda r: r["scope"].update(generation=True),
                   lambda r: r["scope"].update(jobs_hash="d" * 64),
                   lambda r: r["target"].update(host="1.1.1.1"),
                   lambda r: r["target"].update(port=443.0)]
        client = self.client()
        for number, change in enumerate(changes):
            self.guest.mutate = change
            with self.subTest(number=number): self.assertFalse(client.probe(self.context())["ok"])

    def test_guest_health_requires_real_source_public_egress_and_explicit_booleans(self):
        variants = [{"source_ip": "10.10.10.20"}, {"egress_ip": "1.1.1.1"}, {"egress_ip": "127.0.0.1"},
                    {"execution_origin": "host"}, {"tunnel_ok": "true"}, {"ok": False}, {"probe_ok": False}]
        client = self.client()
        for values in variants:
            self.guest.mutate = lambda r, v=values: r.update(v)
            with self.subTest(values=values): self.assertFalse(client.probe(self.context())["ok"])

    def test_freshness_uses_time_after_http_and_observation_time(self):
        client = self.client(); original = client.transport
        def slow(*args):
            result = original(*args); self.now = NOW + 31; return result
        client.transport = slow
        self.assertFalse(client.probe(self.context())["ok"])
        self.now = NOW; self.guest.timestamp = NOW + 6
        self.assertFalse(self.client().probe(self.context())["ok"])
        self.guest.timestamp = NOW
        self.guest.mutate = lambda r: r.update(observed_at=NOW - 6)
        self.assertFalse(self.client().probe(self.context())["ok"])

    def test_monotonic_deadline_excludes_slow_injected_transport(self):
        ticks = iter((0.0, 6.01))
        self.assertFalse(self.client(monotonic=lambda: next(ticks)).probe(self.context())["ok"])

    def test_http_redirect_oversize_duplicate_header_and_missing_signature_fail_closed(self):
        client = self.client(); self.guest.status = 302
        self.assertFalse(client.probe(self.context())["ok"])
        self.guest.status = 200; self.guest.oversize = True
        self.assertFalse(client.probe(self.context())["ok"])
        self.guest.oversize = False; self.guest.header_extra = {"x-vm-nonce": "a" * 32}
        self.assertFalse(client.probe(self.context())["ok"])

    def test_transport_error_returns_bound_failure_and_never_prints_exception_or_key(self):
        client = self.client()
        def fail(*args): raise OSError("private fixture must not be echoed")
        client.transport = fail
        result = client.probe(self.context())
        self.assertFalse(result["ok"]); self.assertTrue(result["checks"]["probe_unavailable"])
        self.assertEqual(result["target"], self.context()["job"]["target"])
        self.assertNotIn("private fixture", json.dumps(result))

    def test_config_endpoint_key_permissions_and_input_commands_are_fixed(self):
        for field, value in (("host", "127.0.0.1"), ("port", 80), ("url", "http://other/")):
            cfg = copy.deepcopy(self.cfg); cfg[field] = value
            with self.subTest(field=field), self.assertRaises(ProtocolError):
                mod.WindowsProbeClient(cfg, root_owned=False)
        self.key.chmod(0o644)
        with self.assertRaises(ProtocolError): self.client()
        self.key.chmod(0o600); self.key.write_bytes(b"short")
        with self.assertRaisesRegex(ProtocolError, "vm_key_size"): self.client()

    def test_network_target_cannot_smuggle_shell_command_or_internal_host(self):
        context = self.context(); context["job"]["params"] = {"command": "whoami"}
        with self.assertRaises(mod.iran_adapter.ProtocolError): self.client().probe(context)
        context = self.context(); context["job"]["target"]["host"] = "10.10.10.1"
        with self.assertRaises(mod.iran_adapter.ProtocolError): self.client().probe(context)


class FakeSocket:
    def __init__(self, chunks): self.chunks = list(chunks); self.sent = bytearray(); self.closed = False
    def setblocking(self, value): pass
    def connect_ex(self, address): self.address = address; return 0
    def send(self, data): self.sent.extend(data); return len(data)
    def recv(self, count): return self.chunks.pop(0) if self.chunks else b""
    def close(self): self.closed = True


class FakeSelector:
    def register(self, *args): pass
    def modify(self, *args): pass
    def select(self, timeout): return [(None, 1)]
    def close(self): pass


class TransportTests(unittest.TestCase):
    def call(self, response, *, selector=None):
        chunks = response if isinstance(response, list) else [response]
        fake = FakeSocket(chunks)
        headers = mod.sign(KEY, "POST", b"{}", NOW, NONCE)
        with patch.object(mod.socket, "socket", return_value=fake), patch.object(mod.selectors, "DefaultSelector", return_value=selector or FakeSelector()):
            try: return mod.http_transport(mod.HOST, mod.PORT, mod.PATH, b"{}", headers), fake
            finally: self.assertTrue(fake.closed)

    def test_bounded_raw_http_reader_sends_only_fixed_post_and_accepts_fragmented_body(self):
        (status, headers, body), fake = self.call([b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\n", b"X-VM-Nonce: abc\r\n\r\n{", b"}"])
        self.assertEqual((status, body), (200, b"{}"))
        self.assertEqual(fake.address, ("10.10.10.50", 19443))
        self.assertTrue(fake.sent.startswith(b"POST /relay-probe/ HTTP/1.1\r\n"))
        self.assertNotIn(b"Proxy-Authorization", fake.sent)

    def test_duplicate_length_chunked_oversize_and_extra_data_are_rejected(self):
        responses = [b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 2\r\n\r\n{}",
                     b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Length: 2\r\n\r\n{}",
                     b"HTTP/1.1 200 OK\r\nContent-Length: 8193\r\n\r\n",
                     b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}x",
                     b"HTTP/1.1 200 OK\r\nX-Large: " + b"x" * 8192]
        for response in responses:
            with self.subTest(size=len(response)), self.assertRaises(ProtocolError): self.call(response)

    def test_deadline_or_closed_socket_is_failure(self):
        selector = FakeSelector(); selector.select = lambda timeout: []
        with self.assertRaisesRegex(ProtocolError, "vm_timeout"): self.call(b"", selector=selector)
        with self.assertRaisesRegex(ProtocolError, "vm_connection_closed"): self.call(b"")


class PowerShellSourceTests(unittest.TestCase):
    """Source invariants only. These do NOT claim native PowerShell parsing."""
    @classmethod
    def setUpClass(cls):
        cls.runtime = (BASE / "windows_probe.ps1").read_text()
        cls.installer = (BASE / "install_windows_probe.ps1").read_text()

    def test_public_wire_vector_is_shared_with_powershell(self):
        expected = mod.sign(KEY, "POST", b'{"op":"probe"}', NOW, "0" * 32)["X-VM-Signature"]
        self.assertIn(expected, self.runtime)
        self.assertIn("'SN-VM-PROBE/1'", self.runtime)
        self.assertIn("'RESPONSE'", self.runtime)

    def test_runtime_authentication_and_durable_nonce_claim_precede_curl_execution(self):
        execution = self.runtime.rindex("$observation = Invoke-FixedGuestProbe")
        authentication = self.runtime.rindex("Test-FixedEqual (Get-Signature $Key 'POST'")
        claim = self.runtime.rindex("Claim-Nonce $nonce $stamp")
        self.assertLess(authentication, claim); self.assertLess(claim, execution)
        self.assertIn("[IO.FileShare]::None", self.runtime)
        self.assertIn("$stream.Flush($true)", self.runtime)
        self.assertIn("if ($entries.Count -ge $MaxNonces) { throw 'nonce_capacity' }", self.runtime)

    def test_curl_is_fixed_private_source_no_curlrc_proxy_or_network_argv(self):
        self.assertIn("$start.Arguments = '-q -4 --noproxy", self.runtime)
        self.assertIn("--interface 10.10.10.50", self.runtime)
        self.assertIn("https://api.ipify.org", self.runtime)
        self.assertIn("$start.UseShellExecute = $false", self.runtime)
        self.assertIn("$outBytes.Length + $count -gt 128", self.runtime)
        self.assertNotIn("Invoke-Expression", self.runtime)
        self.assertNotIn("$data.command", self.runtime)

    def test_installer_parses_before_mutation_preserves_key_and_sets_only_fixed_private_scope(self):
        self.assertLess(self.installer.index("[System.Management.Automation.Language.Parser]::ParseFile"), self.installer.index("[IO.File]::WriteAllBytes($Runtime"))
        self.assertIn("Read-Host 'Distinct VM109 probe key (64 hex characters, hidden)' -AsSecureString", self.installer)
        self.assertIn("[IO.FileMode]::CreateNew", self.installer)
        self.assertIn("existing_probe_key_invalid_preserved", self.installer)
        self.assertIn("ZeroFreeBSTR", self.installer)
        self.assertIn("SetAccessRuleProtection($true, $false)", self.installer)
        self.assertIn("'10.10.10.1'", self.installer)
        self.assertIn("'0.0.0.0-10.10.10.0'", self.installer)
        self.assertIn("'10.10.10.2-255.255.255.255'", self.installer)
        self.assertIn("-UserId 'SYSTEM' -LogonType ServiceAccount", self.installer)
        self.assertNotIn("Set-NetFirewallProfile", self.installer)
        self.assertNotIn("Set-ExecutionPolicy", self.installer)

    def test_effective_firewall_policy_and_owned_task_upgrade_are_verified(self):
        self.assertIn("Get-NetFirewallProfile -PolicyStore ActiveStore", self.runtime)
        self.assertIn("Get-NetFirewallRule -PolicyStore ActiveStore", self.runtime)
        self.assertIn("EnforcementStatus[0] -ne 'Full'", self.runtime)
        self.assertIn("Profile -notin @(0, 7)", self.runtime)
        self.assertIn("AllowLocalFirewallRules -eq 'False'", self.runtime)
        stop = self.installer.index("Stop-ScheduledTask -TaskName $TaskName")
        verify_owned = self.installer.index("throw 'scheduled_task_name_conflict'")
        start = self.installer.index("Start-ScheduledTask -TaskName $TaskName")
        self.assertLess(verify_owned, stop); self.assertLess(stop, start)

    def test_shared_port_and_namespace_preflight_precedes_any_firewall_change(self):
        check = self.installer.index("throw 'port19443_owner_unverified'")
        self.assertLess(check, self.installer.index("Ensure-FirewallRule $BlockRule"))
        self.assertIn("foreign_port_prefix_conflict", self.installer)
        self.assertIn("existing_prefix_owner_unverified", self.installer)
        self.assertIn("Get-NetTCPConnection -State Listen -ErrorAction Stop", self.installer)


if __name__ == "__main__": unittest.main()
