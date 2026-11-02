import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import threading
import unittest

BASE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("protocol", BASE / "protocol.py")
import sys
protocol = importlib.util.module_from_spec(spec)
sys.modules["protocol"] = protocol
spec.loader.exec_module(protocol)

KEY = b"k" * 32
AGENT = "pve-ir"
PATH = "/relay-recovery-agent.php"
NOW = 1_800_000_000


class ProtocolTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store_path = Path(self.tmp.name) / "replay.sqlite3"
        self.replay = protocol.ReplayStore(self.store_path)
        self.body = protocol.canonical_json({"agent_id": AGENT, "op": "poll", "report": {}})

    def tearDown(self):
        self.tmp.cleanup()

    def headers(self, nonce="a" * 32):
        return protocol.sign_message(KEY, AGENT, "POST", PATH, self.body, NOW, nonce)

    def verify(self, headers=None, **kwargs):
        args = dict(key=KEY, expected_agent=AGENT, method="POST", path=PATH,
                    body=self.body, headers=headers or self.headers(), now=NOW,
                    replay_store=self.replay)
        args.update(kwargs)
        return protocol.verify_message(**args)

    def test_canonical_cross_language_vector(self):
        headers = self.headers()
        expected = (f"SN-RELAY/1\n{AGENT}\nPOST\n{PATH}\n{NOW}\n" + "a" * 32
                    + "\n" + hashlib.sha256(self.body).hexdigest()).encode()
        import hmac
        self.assertEqual(headers["X-Relay-Signature"], hmac.new(KEY, expected, hashlib.sha256).hexdigest())
        self.assertEqual(self.verify(headers).nonce, "a" * 32)

    def test_tamper_rejected_before_nonce_consumption(self):
        headers = self.headers()
        with self.assertRaisesRegex(protocol.ProtocolError, "authentication_failed"):
            self.verify(headers, body=self.body + b" ")
        self.verify(headers)

    def test_replay_survives_new_store_instance(self):
        self.verify()
        with self.assertRaisesRegex(protocol.ProtocolError, "replay"):
            self.verify(replay_store=protocol.ReplayStore(self.store_path))

    def test_replay_clock_regression_is_persistently_rejected(self):
        self.replay.claim(AGENT, "a" * 32, NOW + 90, NOW)
        restarted = protocol.ReplayStore(self.store_path)
        with self.assertRaisesRegex(protocol.ProtocolError, "clock_regression"):
            restarted.claim(AGENT, "b" * 32, NOW + 84, NOW - 6)

    def test_concurrent_replay_has_one_winner(self):
        outcomes = []
        barrier = threading.Barrier(2)
        def run():
            barrier.wait()
            outcomes.append(self.replay.claim(AGENT, "b" * 32, NOW + 90, NOW))
        threads = [threading.Thread(target=run) for _ in range(2)]
        for t in threads: t.start()
        for t in threads: t.join()
        self.assertEqual(sorted(outcomes), [False, True])

    def test_wrong_agent_method_and_path_rejected(self):
        for kwargs in ({"expected_agent": "pve-other"}, {"method": "RESPONSE"}, {"path": "/another.php"}):
            with self.subTest(kwargs=kwargs), self.assertRaises(protocol.ProtocolError):
                self.verify(**kwargs)

    def test_future_and_old_timestamps_rejected(self):
        for now in (NOW - 91, NOW + 91):
            with self.subTest(now=now), self.assertRaisesRegex(protocol.ProtocolError, "message_expired"):
                self.verify(now=now)

    def test_boundary_timestamp_valid(self):
        self.verify(now=NOW + 90)

    def test_invalid_timestamp_boolean_nonce_and_short_key(self):
        for now in (True, -1):
            with self.assertRaises(protocol.ProtocolError):
                protocol.sign_message(KEY, AGENT, "POST", PATH, self.body, now)
        with self.assertRaises(protocol.ProtocolError):
            protocol.sign_message(b"short", AGENT, "POST", PATH, self.body, NOW)
        for nonce in ("\n" * 32, "A" * 32, "a" * 31):
            with self.assertRaises(protocol.ProtocolError):
                self.headers(nonce)

    def test_body_size_is_bounded(self):
        with self.assertRaisesRegex(protocol.ProtocolError, "body_size"):
            self.verify(body=b"x" * (protocol.MAX_BODY + 1))

    def test_case_insensitive_headers_but_duplicate_rejected(self):
        self.verify({k.lower(): v for k, v in self.headers().items()})
        headers = self.headers("c" * 32)
        headers["x-relay-agent"] = AGENT
        with self.assertRaisesRegex(protocol.ProtocolError, "duplicate_header"):
            self.verify(headers)

    def test_duplicate_json_and_nonfinite_rejected(self):
        for body in (b'{"x":1,"x":2}', b'{"x":NaN}', b'[]', b'\xff'):
            with self.subTest(body=body), self.assertRaises(protocol.ProtocolError):
                protocol.decode_json(body)

    def test_manifest_hash_binds_jobs(self):
        jobs = [{"id": "test-1", "op": "accept"}]
        manifest = dict(agent_id=AGENT, incident_id="incident-1", generation=1,
                        revision=1, config_hash="d" * 64, jobs_hash=protocol.manifest_hash(jobs),
                        issued_at=NOW, expires_at=NOW + 120, jobs=jobs)
        self.assertIs(protocol.validate_manifest(manifest, AGENT, NOW), manifest)
        manifest["jobs"][0]["op"] = "apply"
        with self.assertRaisesRegex(protocol.ProtocolError, "manifest_jobs_hash"):
            protocol.validate_manifest(manifest, AGENT, NOW)

    def test_manifest_expiry_and_generation_required(self):
        base = dict(agent_id=AGENT, incident_id="incident-1", generation=1,
                    revision=1, config_hash="d" * 64, jobs_hash=protocol.manifest_hash([]),
                    issued_at=NOW, expires_at=NOW + 120, jobs=[])
        for delta in ({"generation": True}, {"generation": 0}, {"expires_at": NOW},
                      {"expires_at": NOW + 301}, {"issued_at": NOW + 31}):
            with self.subTest(delta=delta), self.assertRaises(protocol.ProtocolError):
                protocol.validate_manifest({**base, **delta}, AGENT, NOW)

    def test_private_file_rejects_symlink_and_unsafe_mode(self):
        p = Path(self.tmp.name) / "config"
        p.write_text("{}")
        p.chmod(0o644)
        with self.assertRaisesRegex(protocol.ProtocolError, "private_file_mode"):
            protocol.private_file(p, root_owned=False)
        p.chmod(0o600)
        self.assertEqual(protocol.private_file(p, root_owned=False), p)
        link = p.with_name("link")
        link.symlink_to(p)
        with self.assertRaises(protocol.ProtocolError):
            protocol.private_file(link, root_owned=False)


if __name__ == "__main__":
    unittest.main()
