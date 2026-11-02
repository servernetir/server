"""Signed gateway boundary tests; temporary private files and fake reports only."""
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest

INFRA = Path(__file__).resolve().parents[2]
if str(INFRA) not in sys.path:
    sys.path.insert(0, str(INFRA))
from relay_recovery import gateway, protocol


class GatewayTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        self.now = 1_700_000_000
        self.key = b"unit-test-agent-key-32-bytes-exact!"
        self.keyfile = self.root / "key"
        self.keyfile.write_bytes(self.key)
        self.keyfile.chmod(0o600)
        self.config = {"state_dir": str(self.root), "agents": {
            "ir-agent": {"enabled": True, "key_file": str(self.keyfile)}}}
        self.configfile = self.root / "config.json"
        self.configfile.write_text(json.dumps(self.config))
        self.configfile.chmod(0o600)

    def envelope(self, report=None, *, body=None, now=None):
        if body is None:
            body = protocol.canonical_json({"agent_id": "ir-agent", "op": "poll",
                                            "report": report if report is not None else {"observed_at": self.now}})
        headers = protocol.sign_message(self.key, "ir-agent", "POST", "/relay-recovery-agent.php", body,
                                        self.now if now is None else now)
        return {"method": "POST", "path": "/relay-recovery-agent.php", "headers": headers,
                "body": body.decode()}

    def latest(self):
        with sqlite3.connect(self.root / "agent-reports.sqlite3") as db:
            row = db.execute("SELECT received_at, body FROM latest WHERE agent_id='ir-agent'").fetchone()
        return row[0], json.loads(row[1])

    def inbox(self):
        with sqlite3.connect(self.root / "agent-reports.sqlite3") as db:
            return db.execute("SELECT agent_id,report_hash,received_at,body_sha256,body,consumed_at FROM inbox ORDER BY received_at,rowid").fetchall()

    def receipt_report(self, job_id):
        return {"observed_at": self.now, "jobs": [{"job_id": job_id,
                "op": "apply", "ok": True, "generation": 2, "revision": 7,
                "observed_at": self.now, "checks": {"route_applied": True}}]}

    def test_report_receive_time_assigned_locally_and_ack_is_signed(self):
        report = {"observed_at": self.now - 300, "received_at": self.now + 99999,
                  "agent_id": "forged-other-agent", "healthy": False}
        envelope = self.envelope(report)
        reply = gateway.handle(envelope, self.config, self.now)
        self.assertEqual(200, reply["status"])
        timestamp, stored = self.latest()
        self.assertEqual(self.now, timestamp)
        self.assertEqual(self.now, stored["received_at"])
        self.assertEqual("ir-agent", stored["agent_id"])
        self.assertEqual(self.now - 300, stored["report"]["observed_at"])
        self.assertFalse(stored["report"]["healthy"])
        response_body = reply["body"].encode()
        protocol.verify_message(self.key, "ir-agent", "RESPONSE", envelope["path"], response_body,
                                reply["headers"], self.now, protocol.ReplayStore(self.root / "client-replay.sqlite3"))
        decoded = protocol.decode_json(response_body)
        self.assertEqual(envelope["headers"]["X-Relay-Nonce"], decoded["request_nonce"])
        self.assertEqual(hashlib.sha256(protocol.canonical_json(report)).hexdigest(), decoded["report_ack"])
        self.assertIsNone(decoded["manifest"])
        self.assertEqual(0o600, (self.root / "agent-reports.sqlite3").stat().st_mode & 0o777)

    def test_replay_does_not_overwrite_committed_report(self):
        envelope = self.envelope({"probe_ok": False})
        self.assertEqual(200, gateway.handle(envelope, self.config, self.now)["status"])
        before = self.latest()
        self.assertEqual(403, gateway.handle(envelope, self.config, self.now + 1)["status"])
        self.assertEqual(before, self.latest())

    def test_acknowledged_receipts_survive_later_polls_before_worker_reads(self):
        reports = [self.receipt_report("job-a"), self.receipt_report("job-b")]
        requests = []
        for report in reports:
            request = self.envelope(report)
            requests.append(request)
            reply = gateway.handle(request, self.config, self.now)
            self.assertEqual(200, reply["status"])
            self.assertEqual(hashlib.sha256(protocol.canonical_json(report)).hexdigest(),
                             json.loads(reply["body"])["report_ack"])
        # A health-only poll may replace latest, never the earlier job receipts.
        heartbeat = {"observed_at": self.now, "jobs": [], "direct_control_tcp": [True]}
        self.assertEqual(200, gateway.handle(self.envelope(heartbeat), self.config, self.now)["status"])
        rows = self.inbox()
        self.assertEqual(2, len(rows))
        self.assertEqual(reports, [json.loads(row[4])["report"] for row in rows])
        self.assertEqual([hashlib.sha256(request["body"].encode()).hexdigest() for request in requests],
                         [row[3] for row in rows])
        self.assertTrue(all(row[0] == "ir-agent" and row[5] is None for row in rows))
        self.assertEqual(heartbeat, self.latest()[1]["report"])
        self.assertEqual(0o600, (self.root / "agent-reports.sqlite3").stat().st_mode & 0o777)

    def test_repeated_report_is_acknowledged_without_replacing_immutable_inbox_row(self):
        report = self.receipt_report("job-a")
        self.assertEqual(200, gateway.handle(self.envelope(report), self.config, self.now)["status"])
        before = self.inbox()
        self.now += 1
        self.assertEqual(200, gateway.handle(self.envelope(report), self.config, self.now)["status"])
        self.assertEqual(before, self.inbox())
        self.assertEqual(self.now, self.latest()[0])

    def test_historical_mutation_with_unknown_measurement_clock_is_durable_and_acknowledged(self):
        receipt=dict(job_id='stage-7',op='stage',ok=True,code='completed',generation=2,revision=7,
            observed_at=None,outcome_reconciled_at=self.now,observation_kind='historical_outcome',
            fresh_observation=False,checks={'adapter':{'staged':True,'historical_outcome':True,
                                                      'network_observation_fresh':False}})
        report=dict(agent_id='ir-agent',observed_at=self.now,jobs=[receipt])
        envelope=self.envelope(report)
        reply=gateway.handle(envelope,self.config,self.now)
        self.assertEqual(200,reply['status'])
        self.assertEqual(hashlib.sha256(protocol.canonical_json(report)).hexdigest(),
                         json.loads(reply['body'])['report_ack'])
        stored=json.loads(self.inbox()[0][4])
        self.assertEqual(receipt,stored['report']['jobs'][0])
        self.assertIsNone(stored['report']['jobs'][0]['observed_at'])
        self.assertEqual(self.now,stored['received_at'])

    def test_full_inbox_withholds_ack_and_preserves_pending_receipts(self):
        self.config["report_inbox_max_rows"] = 1
        first = self.receipt_report("job-a")
        self.assertEqual(200, gateway.handle(self.envelope(first), self.config, self.now)["status"])
        before_rows, before_latest = self.inbox(), self.latest()
        reply = gateway.handle(self.envelope(self.receipt_report("job-b")), self.config, self.now)
        self.assertEqual(503, reply["status"])
        self.assertNotIn("report_ack", json.loads(reply["body"]))
        self.assertEqual({}, reply["headers"])
        self.assertEqual(before_rows, self.inbox())
        self.assertEqual(before_latest, self.latest())
        # Retry of an already-durable report needs no additional capacity.
        self.assertEqual(200, gateway.handle(self.envelope(first), self.config, self.now)["status"])
        self.assertEqual(before_rows, self.inbox())

    def test_inbox_byte_limit_fails_closed_without_silently_pruning_consumed_rows(self):
        self.config["report_inbox_max_bytes"] = 1
        reply = gateway.handle(self.envelope(self.receipt_report("job-a")), self.config, self.now)
        self.assertEqual(503, reply["status"])
        self.assertEqual([], self.inbox())
        self.config.pop("report_inbox_max_bytes")
        self.config["report_inbox_max_rows"] = 1
        self.assertEqual(200, gateway.handle(self.envelope(self.receipt_report("job-a")), self.config, self.now)["status"])
        with sqlite3.connect(self.root / "agent-reports.sqlite3") as db:
            db.execute("UPDATE inbox SET consumed_at=?", (self.now,))
        before = self.inbox()
        self.assertEqual(503, gateway.handle(self.envelope(self.receipt_report("job-b")), self.config, self.now)["status"])
        self.assertEqual(before, self.inbox())

    def test_inbox_and_latest_are_atomic_and_database_failure_never_acknowledges(self):
        self.assertEqual(200, gateway.handle(self.envelope({"jobs": []}), self.config, self.now)["status"])
        before = self.latest()
        with sqlite3.connect(self.root / "agent-reports.sqlite3") as db:
            db.execute("CREATE TRIGGER fail_latest BEFORE INSERT ON latest BEGIN SELECT RAISE(ABORT,'test failure'); END")
        reply = gateway.handle(self.envelope(self.receipt_report("job-a")), self.config, self.now)
        self.assertEqual(403, reply["status"])
        self.assertNotIn("report_ack", json.loads(reply["body"]))
        self.assertEqual([], self.inbox())
        self.assertEqual(before, self.latest())

    def test_wrong_signature_or_body_never_stores_report(self):
        envelope = self.envelope()
        envelope["body"] = envelope["body"].replace("observed_at", "tampered_at")
        response = gateway.handle(envelope, self.config, self.now)
        self.assertEqual(403, response["status"])
        self.assertFalse((self.root / "agent-reports.sqlite3").exists())

    def test_expired_message_and_wrong_agent_binding_are_rejected(self):
        self.assertEqual(403, gateway.handle(self.envelope(now=self.now - 91), self.config, self.now)["status"])
        envelope = self.envelope()
        envelope["headers"]["X-Relay-Agent"] = "unknown-agent"
        self.assertEqual(403, gateway.handle(envelope, self.config, self.now)["status"])
        self.config["agents"]["ir-agent"]["enabled"] = False
        self.assertEqual(403, gateway.handle(self.envelope(), self.config, self.now)["status"])

    def test_duplicate_json_keys_nonfinite_and_nonpoll_messages_are_rejected(self):
        for body in (b'{"agent_id":"ir-agent","op":"poll","op":"poll","report":{}}',
                     b'{"agent_id":"ir-agent","op":"poll","report":{"latency":NaN}}',
                     b'{"agent_id":"ir-agent","op":"execute","report":{}}'):
            self.assertEqual(403, gateway.handle(self.envelope(body=body), self.config, self.now)["status"])
        self.assertFalse((self.root / "agent-reports.sqlite3").exists())

    def test_unexpected_method_or_path_has_no_report_effect(self):
        for patch in ({"method": "GET"}, {"path": "/another-endpoint.php"}):
            envelope = self.envelope()
            envelope.update(patch)
            self.assertEqual(404, gateway.handle(envelope, self.config, self.now)["status"])
        self.assertFalse((self.root / "agent-reports.sqlite3").exists())

    def test_oversize_input_is_bounded_and_error_has_no_secret(self):
        envelope = self.envelope()
        envelope["body"] = "private-token-should-not-leak" * 5000
        response = gateway.handle(envelope, self.config, self.now)
        self.assertEqual(403, response["status"])
        self.assertNotIn("private-token-should-not-leak", response["body"])

    def test_private_config_key_and_state_symlinks_are_refused(self):
        self.configfile.chmod(0o644)
        with self.assertRaises(gateway.GatewayError):
            gateway.load_config(self.configfile)
        self.configfile.chmod(0o600)
        link = self.root / "config-link.json"
        link.symlink_to(self.configfile)
        with self.assertRaises(gateway.GatewayError):
            gateway.load_config(link)
        keylink = self.root / "key-link"
        keylink.symlink_to(self.keyfile)
        self.config["agents"]["ir-agent"]["key_file"] = str(keylink)
        self.assertEqual(403, gateway.handle(self.envelope(), self.config, self.now)["status"])

    def test_only_unexpired_manifest_for_same_agent_is_returned(self):
        path = self.root / "manifest-ir-agent.json"
        for agent_id, expiry, expected in (("other-agent", self.now + 10, None),
                                            ("ir-agent", self.now - 1, None),
                                            ("ir-agent", self.now, None),
                                            ("ir-agent", self.now + 10, "ir-agent")):
            path.write_text(json.dumps({"agent_id": agent_id, "expires_at": expiry, "jobs": []}))
            path.chmod(0o600)
            reply = gateway.handle(self.envelope(), self.config, self.now)
            self.assertEqual(200, reply["status"])
            manifest = json.loads(reply["body"])["manifest"]
            self.assertEqual(expected, manifest.get("agent_id") if manifest else None)

    def test_module_cli_entry_works_without_loading_laravel_or_provider(self):
        now = int(time.time())
        result = subprocess.run([sys.executable, "-m", "relay_recovery.gateway", "--config", str(self.configfile)],
                                cwd=INFRA, input=json.dumps(self.envelope(now=now)).encode(),
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(200, json.loads(result.stdout)["status"])


if __name__ == "__main__":
    unittest.main()
