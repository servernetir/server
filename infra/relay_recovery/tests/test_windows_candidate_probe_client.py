"""Offline candidate wire/acceptance tests; no Windows, DNS or cloud effects."""
import base64
import copy
import hashlib
from pathlib import Path
import sys
import tempfile
import unittest

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import windows_candidate_probe_client as mod
import isolated_acceptance as isolated
import protocol
import windows_probe_client as windows

NOW = 1_800_000_000
KEY = bytes(range(32))
REQUEST_NONCE = "a" * 32


class CandidateGuest:
    """An authenticated wire peer with explicit error injection, no networking."""
    def __init__(self, enrollment, policy=windows.DEFAULT_ACCESS_POLICY):
        self.enrollment, self.policy = enrollment, policy
        self.calls, self.counter = [], 0
        self.mutate = None
        self.tamper = False
        self.purpose = "RESPONSE"
        self.response_nonce = None
        self.response_timestamp = NOW
        self.extra_headers = {}
        self.raw_mutate = None

    def __call__(self, host, port, path, body, headers, timeout):
        self.calls.append((host, port, path, body, headers, timeout))
        self.counter += 1
        assert headers == windows.sign(KEY, "POST", body, NOW, REQUEST_NONCE)
        request = protocol.decode_json(body)
        assert set(request) == {"candidate", "op", "scope", "target"}
        assert request["op"] == "candidate_probe"
        c = request["candidate"]
        assert c["proxy_binding_sha256"] == mod.validate_enrollment(self.enrollment, request["scope"]["agent_id"])
        response = dict(schema=isolated.GUEST_SCHEMA, op="candidate_probe", path_profile=isolated.PATH_PROFILE,
            request_nonce=REQUEST_NONCE, scope=request["scope"], target=request["target"],
            observed_at=NOW, read_only=True, execution_origin="guest", source_ip=windows.SOURCE_IP,
            egress_ip=request["target"]["host"], tunnel_ok=True, probe_ok=True, ok=True,
            **{k: c[k] for k in ("dependency_baseline_sha256", "proxy_binding_sha256", "registry_sha256",
                               "resource_config_hash", "resource_identity", "restore_epoch", "restore_hash", "stage_profile")})
        if self.policy == windows.SOURCE_HMAC_POLICY:
            response["access_policy"] = self.policy
        if self.mutate:
            self.mutate(response)
        raw = protocol.canonical_json(response)
        if self.raw_mutate:
            raw = self.raw_mutate(raw)
        nonce = self.response_nonce or f"{self.counter:032x}"
        signed = windows.sign(KEY, self.purpose, raw, self.response_timestamp, nonce)
        if self.tamper:
            signed["X-VM-Signature"] = "0" * 64
        # Real transport lowercases HTTP headers and includes non-auth headers.
        signed = {k.lower(): v for k, v in signed.items()}
        signed.update({"content-type": "application/json", **self.extra_headers})
        return 200, signed, raw


class CandidateClientTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.key = self.root / "guest.key"
        self.key.write_bytes(KEY)
        self.key.chmod(0o600)
        self.config = dict(schema=windows.SCHEMA, agent_id="ir-agent", key_file=str(self.key),
                           state_dir=str(self.root / "state"), access_policy=windows.SOURCE_HMAC_POLICY)
        self.enrollment = dict(schema=mod.ENROLLMENT_SCHEMA, enabled=True, agent_id="ir-agent",
                               proxy_host="127.0.0.1", proxy_port=19081,
                               proxy_binding_sha256=mod.proxy_binding("ir-agent", "127.0.0.1", 19081))
        self.context = dict(schema=protocol.SCHEMA, agent_id="ir-agent", incident_id="incident-1",
            generation=2, revision=7, config_hash="a" * 64, jobs_hash="b" * 64,
            job=dict(id="accept-1", op="accept", target=dict(host="9.9.9.9", port=22, provider_id="51"),
                     params=dict(acceptance_profile=isolated.PROFILE, stage_profile="restored",
                        restore_epoch=1, restore_hash="c" * 64, expected_registry_hash="d" * 64,
                        dependency_baseline_sha256="e" * 64, proxy_binding_sha256=self.enrollment["proxy_binding_sha256"],
                        resource_identity=dict(provider="hetzner", project_id="project-1", provider_id="51", generation=2),
                        resource_config_hash="f" * 64)))
        self.guest = CandidateGuest(self.enrollment, windows.SOURCE_HMAC_POLICY)

    def client(self, **kwargs):
        return mod.WindowsCandidateProbeClient(copy.deepcopy(self.config), copy.deepcopy(self.enrollment),
            transport=self.guest, clock=lambda: NOW, nonce=lambda: REQUEST_NONCE, root_owned=False, **kwargs)

    def test_explicit_guest_envelope_is_accepted_only_with_fresh_ct_and_registry_proofs(self):
        envelope = self.client().probe(self.context)
        body = protocol.decode_json(base64.b64decode(envelope["body_base64"]))
        self.assertEqual(set(envelope), {"body_base64", "headers", "request_nonce"})
        self.assertEqual(set(envelope["headers"]), set(mod.SIGNATURE_HEADERS.values()))
        params = self.context["job"]["params"]
        bound = {k: body[k] for k in ("scope", "target", "stage_profile", "resource_identity", "resource_config_hash",
                                    "restore_epoch", "restore_hash", "proxy_binding_sha256")}
        ct = dict(schema=isolated.CT_SCHEMA, **bound, read_only=True, fresh_probe=True, historical_outcome=False,
                  accepted=True, generation_verified=True, socks="127.0.0.1:10829", observed_at=NOW)
        registry = dict(schema=isolated.REGISTRY_SCHEMA, scope=bound["scope"], target=bound["target"], read_only=True,
            coverage_complete=True, resource_count=4, registry_sha256=params["expected_registry_hash"],
            before_state_sha256=params["dependency_baseline_sha256"], after_state_sha256=params["dependency_baseline_sha256"],
            before_observed_at=NOW - 1, after_observed_at=NOW)
        receipt = dict(action="accept", read_only=True, ok=True, checks=dict(accepted=True, generation_verified=True),
                       isolated_evidence=dict(schema=isolated.SCHEMA, ct=ct, registry=registry, guest=envelope))
        verifier = isolated.IsolatedAcceptanceVerifier(KEY, protocol.ReplayStore(self.root / "acceptance.sqlite3"), now=lambda: NOW)
        proof = verifier.verify(self.context, receipt)
        self.assertTrue(proof["verified"])
        self.assertFalse(proof["default_path_verified"])
        self.assertFalse(proof["global_cutover_performed"])
        with self.assertRaisesRegex(protocol.ProtocolError, "vm_replay"):
            verifier.verify(self.context, receipt)

    def test_no_enrollment_is_default_off_before_network_key_or_state_access(self):
        for enrollment in (None, {}, {**self.enrollment, "enabled": False}, {**self.enrollment, "enabled": 1}):
            config = {**self.config, "key_file": "/missing/key", "state_dir": str(self.root / "new-state")}
            with self.subTest(enrollment=enrollment), self.assertRaisesRegex(protocol.ProtocolError, "not_enrolled"):
                mod.WindowsCandidateProbeClient(config, enrollment, transport=self.guest, root_owned=False)
        self.assertFalse(self.guest.calls)
        self.assertFalse((self.root / "new-state").exists())

    def test_fixed_private_http_endpoint_and_projected_request_have_no_url_command_or_proxy_address(self):
        self.client().probe(self.context)
        host, port, path, raw, headers, deadline = self.guest.calls[0]
        self.assertEqual((host, port, path, deadline), (windows.HOST, windows.PORT, windows.PATH, 6))
        self.assertNotIn(mod.PROBE_URL.encode(), raw)
        self.assertNotIn(self.enrollment["proxy_host"].encode(), raw)
        self.assertNotIn(b"proxy_port", raw)
        self.assertNotIn(b"command", raw)
        self.assertEqual(protocol.decode_json(raw)["scope"], {k: self.context[k] for k in windows.SCOPE})

    def test_context_cannot_supply_url_command_endpoint_or_hidden_parameters(self):
        for field, value in (("url", "https://example.invalid"), ("command", "arbitrary"), ("proxy_host", "8.8.8.8")):
            context = copy.deepcopy(self.context)
            context["job"]["params"][field] = value
            with self.subTest(field=field), self.assertRaises(protocol.ProtocolError):
                self.client().probe(context)
        self.assertFalse(self.guest.calls)

    def test_context_agent_proxy_binding_identity_and_epoch_must_be_valid_before_transport(self):
        changes = (("agent_id", "other-agent"), ("proxy_binding_sha256", "0" * 64),
                   ("restore_epoch", True), ("restore_hash", "bad"), ("stage_profile", "default"))
        for field, bad in changes:
            context = copy.deepcopy(self.context)
            (context if field == "agent_id" else context["job"]["params"])[field] = bad
            with self.subTest(field=field), self.assertRaises(protocol.ProtocolError):
                self.client().probe(context)
        context = copy.deepcopy(self.context)
        context["job"]["params"]["resource_identity"]["provider_id"] = "52"
        with self.assertRaises(protocol.ProtocolError):
            self.client().probe(context)
        self.assertFalse(self.guest.calls)

    def test_authenticated_response_cannot_change_candidate_scope_registry_generation_or_restore(self):
        changes = (("scope", {**{k: self.context[k] for k in windows.SCOPE}, "generation": 3}),
                   ("target", {**self.context["job"]["target"], "provider_id": "52"}),
                   ("resource_identity", {**self.context["job"]["params"]["resource_identity"], "generation": 1}),
                   ("restore_epoch", 2), ("restore_hash", "0" * 64), ("stage_profile", "trial"),
                   ("proxy_binding_sha256", "0" * 64), ("registry_sha256", "0" * 64),
                   ("dependency_baseline_sha256", "0" * 64), ("resource_config_hash", "0" * 64),
                   ("request_nonce", "b" * 32))
        for field, bad in changes:
            self.guest.mutate = lambda body, field=field, bad=bad: body.update({field: bad})
            with self.subTest(field=field), self.assertRaisesRegex(protocol.ProtocolError, "response_binding"):
                self.client().probe(self.context)

    def test_float_and_boolean_identity_aliases_cannot_match_integer_scope(self):
        for bad in (2.0, True):
            self.guest.mutate = lambda body, bad=bad: body["scope"].update(generation=bad)
            with self.subTest(bad=bad), self.assertRaises(protocol.ProtocolError):
                self.client().probe(self.context)

    def test_signature_tampering_and_request_purpose_reuse_are_rejected_without_consuming_nonce(self):
        self.guest.response_nonce = "9" * 32
        client = self.client()
        self.guest.tamper = True
        with self.assertRaisesRegex(protocol.ProtocolError, "authentication_failed"):
            client.probe(self.context)
        self.guest.tamper = False
        self.guest.purpose = "POST"
        with self.assertRaisesRegex(protocol.ProtocolError, "authentication_failed"):
            client.probe(self.context)
        self.guest.purpose = "RESPONSE"
        self.assertIn("body_base64", client.probe(self.context))

    def test_response_replay_is_durable_across_client_restart(self):
        self.guest.response_nonce = "9" * 32
        self.client().probe(self.context)
        with self.assertRaisesRegex(protocol.ProtocolError, "vm_replay"):
            self.client().probe(self.context)

    def test_wrong_egress_source_failed_probe_and_nonboolean_success_do_not_produce_envelope(self):
        for field, bad in (("egress_ip", "8.8.8.8"), ("source_ip", "10.10.10.20"), ("ok", False),
                           ("probe_ok", 1), ("tunnel_ok", None)):
            self.guest.mutate = lambda body, field=field, bad=bad: body.update({field: bad})
            with self.subTest(field=field), self.assertRaisesRegex(protocol.ProtocolError, "path_failed"):
                self.client().probe(self.context)

    def test_signed_default_path_cannot_masquerade_as_candidate_proof(self):
        for field, bad in (("schema", windows.WIRE_SCHEMA), ("op", "probe"), ("path_profile", "default_path_v1"),
                           ("read_only", 1), ("execution_origin", "ct113")):
            self.guest.mutate = lambda body, field=field, bad=bad: body.update({field: bad})
            with self.subTest(field=field), self.assertRaisesRegex(protocol.ProtocolError, "response_schema"):
                self.client().probe(self.context)

    def test_signed_noncanonical_or_extra_response_fields_are_rejected(self):
        self.guest.raw_mutate = lambda raw: raw + b"\n"
        with self.assertRaisesRegex(protocol.ProtocolError, "canonical"):
            self.client().probe(self.context)
        self.guest.raw_mutate = None
        self.guest.mutate = lambda body: body.update(extra=True)
        with self.assertRaisesRegex(protocol.ProtocolError, "response_schema"):
            self.client().probe(self.context)

    def test_policy_cannot_be_omitted_or_changed(self):
        self.guest.mutate = lambda body: body.pop("access_policy")
        with self.assertRaisesRegex(protocol.ProtocolError, "access_policy"):
            self.client().probe(self.context)
        self.guest.mutate = lambda body: body.update(access_policy=windows.DEFAULT_ACCESS_POLICY)
        with self.assertRaisesRegex(protocol.ProtocolError, "access_policy"):
            self.client().probe(self.context)

    def test_duplicate_auth_header_is_rejected_and_standard_headers_are_not_exposed(self):
        self.guest.extra_headers["X-VM-Nonce"] = "b" * 32
        with self.assertRaisesRegex(protocol.ProtocolError, "headers"):
            self.client().probe(self.context)

    def test_old_observation_or_old_hmac_does_not_become_fresh(self):
        self.guest.mutate = lambda body: body.update(observed_at=NOW - 31)
        with self.assertRaisesRegex(protocol.ProtocolError, "stale"):
            self.client().probe(self.context)
        self.guest.mutate = None
        self.guest.response_timestamp = NOW - 31
        with self.assertRaisesRegex(protocol.ProtocolError, "expired"):
            self.client().probe(self.context)

    def test_trial_request_explicitly_has_null_restore_fields_and_cannot_inherit_epoch(self):
        self.context["job"]["params"].update(stage_profile="trial")
        del self.context["job"]["params"]["restore_epoch"]
        del self.context["job"]["params"]["restore_hash"]
        result = self.client().probe(self.context)
        body = protocol.decode_json(base64.b64decode(result["body_base64"]))
        self.assertIsNone(body["restore_epoch"])
        self.assertIsNone(body["restore_hash"])
        self.context["job"]["params"]["restore_epoch"] = 1
        with self.assertRaises(protocol.ProtocolError):
            self.client().probe(self.context)

    def test_six_second_monotonic_budget_is_enforced(self):
        ticks = iter([0, 6.001])
        with self.assertRaisesRegex(protocol.ProtocolError, "timeout"):
            self.client(monotonic=lambda: next(ticks)).probe(self.context)

    def test_transport_error_is_a_fixed_unavailable_code(self):
        client = self.client()
        def unavailable(*args):
            raise OSError("untrusted raw endpoint diagnostic")
        client.client.transport = unavailable
        with self.assertRaisesRegex(protocol.ProtocolError, "^vm_candidate_transport_failed$"):
            client.probe(self.context)

    def test_original_nested_context_cannot_change_expected_scope_during_transport(self):
        client = self.client()
        transport = client.client.transport
        def changed(*args):
            self.context["job"]["params"]["resource_identity"]["generation"] = 1
            self.context["job"]["target"]["provider_id"] = "52"
            return transport(*args)
        client.client.transport = changed
        result = client.probe(self.context)
        body = protocol.decode_json(base64.b64decode(result["body_base64"]))
        self.assertEqual(body["resource_identity"]["generation"], 2)
        self.assertEqual(body["target"]["provider_id"], "51")


class EnrollmentTests(unittest.TestCase):
    def test_binding_is_deterministic_and_only_private_or_exact_loopback_numeric_endpoint(self):
        digest = mod.proxy_binding("ir-agent", "127.0.0.1", 19081)
        expected = protocol.canonical_json(dict(agent_id="ir-agent", path_profile="candidate_socks_v1",
            probe_url="https://api.ipify.org", proxy_host="127.0.0.1", proxy_port=19081,
            schema=mod.ENROLLMENT_SCHEMA, source_ip="10.10.10.50"))
        self.assertEqual(digest, hashlib.sha256(expected).hexdigest())
        for host in ("8.8.8.8", "example.invalid", "127.0.0.2", "169.254.1.1", "::1", "010.1.1.1", "10.1.1.1\n"):
            with self.subTest(host=host), self.assertRaises(protocol.ProtocolError):
                mod.proxy_binding("ir-agent", host, 19081)
        for port in (22, 443, 65536, True, 10829.0, "10829"):
            with self.subTest(port=port), self.assertRaises(protocol.ProtocolError):
                mod.proxy_binding("ir-agent", "127.0.0.1", port)
        for host in ("10.0.0.1", "172.16.0.1", "192.168.0.1"):
            self.assertEqual(len(mod.proxy_binding("ir-agent", host, 10829)), 64)

    def test_private_enrollment_is_canonical_pinned_and_unchanged(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "enrollment.json"
            enrollment = dict(schema=mod.ENROLLMENT_SCHEMA, enabled=True, agent_id="ir-agent", proxy_host="127.0.0.1",
                proxy_port=19081, proxy_binding_sha256=mod.proxy_binding("ir-agent", "127.0.0.1", 19081))
            raw = protocol.canonical_json(enrollment) + b"\n"
            path.write_bytes(raw)
            path.chmod(0o600)
            digest = hashlib.sha256(raw).hexdigest()
            self.assertEqual(mod.load_enrollment(path, digest, agent_id="ir-agent", root_owned=False), enrollment)
            self.assertEqual(path.read_bytes(), raw)
            with self.assertRaisesRegex(protocol.ProtocolError, "pin"):
                mod.load_enrollment(path, "0" * 64, agent_id="ir-agent", root_owned=False)
            with self.assertRaisesRegex(protocol.ProtocolError, "not_enrolled"):
                mod.load_enrollment(path, digest, agent_id="other", root_owned=False)
            path.chmod(0o644)
            with self.assertRaises(protocol.ProtocolError):
                mod.load_enrollment(path, digest, agent_id="ir-agent", root_owned=False)

    def test_enrollment_cannot_add_secret_command_url_or_mismatch_digest(self):
        enrollment = dict(schema=mod.ENROLLMENT_SCHEMA, enabled=True, agent_id="ir-agent", proxy_host="127.0.0.1",
            proxy_port=19081, proxy_binding_sha256=mod.proxy_binding("ir-agent", "127.0.0.1", 19081))
        for field, value in (("url", "https://example.invalid"), ("command", "arbitrary"),
                             ("proxy_binding_sha256", "0" * 64), ("proxy_port", 19082)):
            with self.subTest(field=field), self.assertRaises(protocol.ProtocolError):
                mod.validate_enrollment({**enrollment, field: value}, "ir-agent")

    def test_duplicate_noncanonical_aliased_or_symlinked_enrollment_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "enrollment.json"
            enrollment = dict(schema=mod.ENROLLMENT_SCHEMA, enabled=True, agent_id="ir-agent", proxy_host="127.0.0.1",
                proxy_port=19081, proxy_binding_sha256=mod.proxy_binding("ir-agent", "127.0.0.1", 19081))
            canonical = protocol.canonical_json(enrollment)
            for raw in (canonical.replace(b'"enabled":true', b'"enabled":true,"enabled":true'),
                        canonical.replace(b'"enabled":true', b'"Enabled":true'),
                        canonical.replace(b'"proxy_port":19081', b'"proxy_port":19081.0'),
                        canonical.replace(b',', b', ')):
                path.write_bytes(raw)
                path.chmod(0o600)
                with self.subTest(raw=raw), self.assertRaises(protocol.ProtocolError):
                    mod.load_enrollment(path, hashlib.sha256(raw).hexdigest(), agent_id="ir-agent", root_owned=False)
            path.write_bytes(canonical)
            symlink = Path(directory) / "link.json"
            symlink.symlink_to(path)
            with self.assertRaises(protocol.ProtocolError):
                mod.load_enrollment(symlink, hashlib.sha256(canonical).hexdigest(), agent_id="ir-agent", root_owned=False)


if __name__ == "__main__":
    unittest.main()
