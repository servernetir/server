import importlib.util
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import agent
import protocol

KEY = b"a" * 32
AGENT = "pve-ir"
NOW = 1_800_000_000
PATH = "/relay-recovery-agent.php"


def manifest(jobs=None, generation=1, revision=1, now=NOW):
    jobs = jobs if jobs is not None else [{"id": "probe-1", "op": "probe", "target": {"host": "91.107.179.25", "port": 443, "provider_id": "123"}}]
    return dict(agent_id=AGENT, incident_id="incident-1", generation=generation,
                revision=revision, config_hash="d" * 64, jobs_hash=protocol.manifest_hash(jobs),
                issued_at=now, expires_at=now + 120, jobs=jobs)


class FakeController:
    def __init__(self, desired):
        self.desired = desired
        self.calls = []
        self.nonce = 0
        self.tamper = False
        self.bad_binding = False
        self.bad_ack = False
        self.status = 200

    def __call__(self, url, body, headers):
        self.calls.append((url, protocol.decode_json(body), headers))
        self.nonce += 1
        response = protocol.canonical_json(dict(schema=protocol.SCHEMA,
            request_nonce="0" * 32 if self.bad_binding else headers["X-Relay-Nonce"],
            report_ack="0" * 64 if self.bad_ack else hashlib.sha256(protocol.canonical_json(protocol.decode_json(body)["report"])).hexdigest(),
            manifest=self.desired))
        now = int(headers["X-Relay-Timestamp"])
        signed = protocol.sign_message(KEY, AGENT, "RESPONSE", PATH, response, now, f"{self.nonce:032x}")
        if self.tamper:
            response += b" "
        return self.status, signed, response


class AgentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.calls = []
        self.cfg = dict(agent_id=AGENT, controller_url="https://control.example/relay-recovery-agent.php",
                        controller_path=PATH, key=KEY, state_dir=self.tmp.name,
                        candidate_ports=[22, 443], control_targets=[dict(host="control.example", port=443)],
                        adapter="/not/executed", root_owned=False)
        self.controller = FakeController(manifest())
        self.fail_adapter = None
        self.reconciled = True
        self.probe_tcp = True
        self.probe_icmp = False

    def tearDown(self):
        self.tmp.cleanup()

    def adapter(self, action, context):
        self.calls.append((action, context))
        if self.fail_adapter:
            raise protocol.ProtocolError(self.fail_adapter)
        return {"schema": agent.ADAPTER_SCHEMA, "ok": True, "checks": {"tunnel_ok": True},
                "reconciled": self.reconciled,
                **{k: context[k] for k in ("generation", "revision", "incident_id", "config_hash")}}

    def instance(self, adapter_fn=None):
        return agent.Agent(self.cfg, transport=self.controller, adapter=adapter_fn or self.adapter,
                           tcp=lambda h, p: self.probe_tcp, icmp=lambda h: self.probe_icmp)

    def test_probe_checks_tcp_and_real_adapter_icmp_is_supplementary(self):
        obj = self.instance()
        self.assertTrue(obj.run_once(NOW)["ok"])
        report = protocol.decode_json(obj.report_path.read_bytes())
        self.assertFalse(report["jobs"][0]["checks"]["candidate_icmp"])
        self.assertTrue(report["jobs"][0]["checks"]["candidate_tcp"])
        self.assertTrue(report["jobs"][0]["checks"]["adapter"]["tunnel_ok"])

    def test_tcp_failure_does_not_become_success(self):
        self.probe_tcp = False
        self.assertFalse(self.instance().run_once(NOW)["ok"])

    def test_pending_report_sent_on_next_poll(self):
        obj = self.instance()
        obj.run_once(NOW)
        obj.run_once(NOW + 1)
        report = self.controller.calls[1][1]["report"]
        self.assertEqual(report["jobs"][0]["job_id"], "probe-1")
        self.assertEqual(report["direct_control_tcp"], [True])

    def test_effect_receipt_survives_crash_before_json_cache_write(self):
        self.controller.desired = manifest(jobs=[{"id": "apply-1", "op": "apply"}])
        obj = self.instance()
        with patch.object(obj, "_write_report", side_effect=OSError("simulated crash")):
            with self.assertRaises(OSError):
                obj.run_once(NOW)
        self.assertEqual(obj.journal.backlog(), 1)
        self.controller.desired = None
        resumed = self.instance()
        resumed.run_once(NOW + 1)
        self.assertEqual(self.controller.calls[-1][1]["report"]["jobs"][0]["job_id"], "apply-1")
        self.assertEqual(resumed.journal.backlog(), 0)
        self.assertEqual([x[0] for x in self.calls], ["apply"])

    def test_wrong_durable_report_ack_keeps_outbox_and_blocks_new_work(self):
        obj = self.instance()
        obj.run_once(NOW)
        self.assertEqual(obj.journal.backlog(), 1)
        self.controller.bad_ack = True
        self.controller.desired = manifest(jobs=[{"id": "apply-1", "op": "apply"}], revision=2)
        with self.assertRaisesRegex(protocol.ProtocolError, "report_ack_missing"):
            obj.run_once(NOW + 1)
        self.assertEqual(obj.journal.backlog(), 1)
        self.assertEqual([x[0] for x in self.calls], ["probe"])

    def test_receipt_batch_is_bounded_and_unsent_receipts_are_not_acknowledged(self):
        obj = self.instance()
        for i in range(10):
            obj.journal.queue_receipt({"job_id": str(i), "ok": True, "observed_at": NOW})
        self.controller.desired = None
        obj.run_once(NOW)
        self.assertEqual(len(self.controller.calls[-1][1]["report"]["jobs"]), 6)
        self.assertEqual(obj.journal.backlog(), 4)

    def test_cached_job_is_not_reexecuted_or_made_fresh(self):
        obj = self.instance()
        obj.run_once(NOW)
        obj.run_once(NOW + 1)
        self.assertEqual(len(self.calls), 1)
        report = protocol.decode_json(obj.report_path.read_bytes())
        self.assertEqual(report["jobs"][0]["observed_at"], NOW)

    def test_tampered_response_does_not_run_adapter(self):
        self.controller.tamper = True
        with self.assertRaisesRegex(protocol.ProtocolError, "authentication_failed"):
            self.instance().run_once(NOW)
        self.assertFalse(self.calls)

    def test_response_must_bind_request_nonce(self):
        self.controller.bad_binding = True
        with self.assertRaisesRegex(protocol.ProtocolError, "response_binding"):
            self.instance().run_once(NOW)
        self.assertFalse(self.calls)

    def test_invalid_status_and_html_never_run_jobs(self):
        self.controller.status = 503
        with self.assertRaisesRegex(protocol.ProtocolError, "controller_status"):
            self.instance().run_once(NOW)
        self.assertFalse(self.calls)

    def test_no_manifest_does_not_claim_tunnel_healthy(self):
        self.controller.desired = None
        result = self.instance().run_once(NOW)
        self.assertEqual(result, {"ok": True, "code": "no_job", "job_count": 0})
        self.assertFalse(self.calls)

    def test_old_generation_and_same_revision_hash_conflict_rejected(self):
        obj = self.instance()
        obj.run_once(NOW)
        self.controller.desired = manifest(revision=2)
        obj.run_once(NOW + 1)
        self.controller.desired = manifest(revision=1)
        with self.assertRaisesRegex(protocol.ProtocolError, "manifest_rollback"):
            obj.run_once(NOW + 2)
        self.controller.desired = manifest(jobs=[{"id": "accept-2", "op": "accept"}], revision=2)
        with self.assertRaisesRegex(protocol.ProtocolError, "manifest_rollback"):
            obj.run_once(NOW + 3)

    def test_mutation_is_journaled_before_adapter_call(self):
        self.controller.desired = manifest(jobs=[{"id": "apply-1", "op": "apply"}])
        obj = self.instance()
        def checked(action, context):
            with obj.journal.connect() as db:
                self.assertEqual(db.execute("SELECT status FROM jobs").fetchone()[0], "started")
            return self.adapter(action, context)
        obj.adapter = checked
        self.assertTrue(obj.run_once(NOW)["ok"])

    def test_crash_or_timeout_reconciles_instead_of_repeating_apply(self):
        self.controller.desired = manifest(jobs=[{"id": "apply-1", "op": "apply"}])
        self.fail_adapter = "adapter_timeout"
        self.assertFalse(self.instance().run_once(NOW)["ok"])
        self.fail_adapter = None
        self.assertTrue(self.instance().run_once(NOW + 1)["ok"])
        self.assertEqual([x[0] for x in self.calls], ["apply", "reconcile"])

    def test_unresolved_reconcile_stays_pending_and_stops_mutating_batch(self):
        self.controller.desired = manifest(jobs=[{"id": "apply-1", "op": "apply"}, {"id": "apply-2", "op": "apply"}])
        self.fail_adapter = "adapter_timeout"
        obj = self.instance()
        obj.run_once(NOW)
        self.fail_adapter = None
        self.reconciled = False
        result = obj.run_once(NOW + 1)
        self.assertFalse(result["ok"])
        self.assertEqual(result["job_count"], 1)
        with obj.journal.connect() as db:
            self.assertEqual(db.execute("SELECT status FROM jobs").fetchone()[0], "started")

    def test_adapter_exit_zero_without_valid_receipt_not_success(self):
        for bad in ({}, {"ok": True}, {"schema": agent.ADAPTER_SCHEMA, "ok": True, "checks": {}}):
            with self.subTest(bad=bad):
                self.controller.desired = manifest(revision=len(self.calls) + 1)
                obj = self.instance(adapter_fn=lambda action, ctx: bad)
                self.assertFalse(obj.run_once(NOW)["ok"])

    def test_unsafe_jobs_rejected_before_any_execution(self):
        jobs = [
            {"id": "x", "op": "shell", "params": {}},
            {"id": "x", "op": "probe", "target": {"host": "127.0.0.1", "port": 443, "provider_id": "1"}},
            {"id": "x", "op": "probe", "target": {"host": "10.10.10.50", "port": 443, "provider_id": "1"}},
            {"id": "x", "op": "probe", "target": {"host": "example.com", "port": 443, "provider_id": "1"}},
            {"id": "x", "op": "probe", "target": {"host": "91.107.179.25", "port": 25, "provider_id": "1"}},
            {"id": "x", "op": "apply", "params": {"command": "touch /tmp/not-executed"}},
            {"id": "x", "op": "apply", "executable": "/tmp/bad"},
        ]
        for job in jobs:
            with self.subTest(job=job), self.assertRaises(protocol.ProtocolError):
                agent.validate_job(job, self.cfg)
        self.assertFalse(self.calls)

    def test_duplicate_job_ids_reject_entire_manifest(self):
        job = {"id": "dup", "op": "accept"}
        self.controller.desired = manifest(jobs=[job, job])
        with self.assertRaisesRegex(protocol.ProtocolError, "job_id_duplicate"):
            self.instance().run_once(NOW)
        self.assertFalse(self.calls)

    def test_clock_rollback_blocks_manifest(self):
        obj = self.instance()
        obj.run_once(NOW)
        with self.assertRaisesRegex(protocol.ProtocolError, "clock_regression"):
            obj.run_once(NOW - 6)

    def test_expiry_after_blocking_job_prevents_next_mutation(self):
        ticks = [0.0]
        self.controller.desired = manifest(jobs=[{"id": "stage-1", "op": "stage"}, {"id": "apply-1", "op": "apply"}])
        self.controller.desired["expires_at"] = NOW + 2
        obj = self.instance()
        obj.monotonic = lambda: ticks[0]
        def slow(action, context):
            result = self.adapter(action, context)
            ticks[0] += 3
            return result
        obj.adapter = slow
        result = obj.run_once(NOW)
        self.assertFalse(result["ok"])
        self.assertEqual(result["code"], "manifest_expired")
        self.assertEqual([x[0] for x in self.calls], ["stage"])
        with obj.journal.connect() as db:
            self.assertEqual(db.execute("SELECT status FROM jobs").fetchone()[0], "done")

    def test_new_revision_cannot_bypass_original_mutation_reconciliation(self):
        self.controller.desired = manifest(jobs=[{"id": "apply-old", "op": "apply"}])
        obj = self.instance()
        self.fail_adapter = "adapter_timeout"
        obj.run_once(NOW)
        self.fail_adapter = None
        self.reconciled = False
        self.controller.desired = manifest(jobs=[{"id": "apply-new", "op": "apply"}], generation=2)
        self.assertFalse(obj.run_once(NOW + 1)["ok"])
        self.assertEqual([x[0] for x in self.calls], ["apply", "reconcile"])
        self.assertEqual(self.calls[-1][1]["job"]["id"], "apply-old")
        with obj.journal.connect() as db:
            self.assertEqual(db.execute("SELECT generation FROM cursor").fetchone()[0], 1)
        self.reconciled = True
        self.assertEqual(obj.run_once(NOW + 2)["code"], "reconciled")
        # Original reconciliation receipt survives for delivery before new work.
        self.assertEqual(protocol.decode_json(obj.report_path.read_bytes())["jobs"][0]["job_id"], "apply-old")
        self.assertTrue(obj.run_once(NOW + 3)["ok"])
        self.assertEqual([x[0] for x in self.calls], ["apply", "reconcile", "reconcile", "apply"])
        self.assertEqual(self.controller.calls[-1][1]["report"]["jobs"][0]["job_id"], "apply-old")

    def test_direct_journal_begin_blocks_cross_revision_pending_mutation(self):
        obj = self.instance()
        base = {"schema": protocol.SCHEMA, "agent_id": AGENT, "incident_id": "incident-1", "generation": 1,
                "revision": 1, "config_hash": "d" * 64, "jobs_hash": "e" * 64,
                "job": {"id": "first", "op": "apply"}}
        obj.journal.begin(base)
        with self.assertRaisesRegex(protocol.ProtocolError, "mutation_pending_reconciliation"):
            obj.journal.begin({**base, "revision": 2, "job": {"id": "second", "op": "apply"}})

    def test_actual_configuration_change_needs_new_generation(self):
        obj = self.instance()
        obj.run_once(NOW)
        self.controller.desired = manifest(revision=2)
        self.controller.desired["config_hash"] = "e" * 64
        with self.assertRaisesRegex(protocol.ProtocolError, "config_generation_conflict"):
            obj.run_once(NOW + 1)

    def test_config_rejects_http_and_wrong_file_mode(self):
        base = Path(self.tmp.name)
        key = base / "key"
        key.write_bytes(KEY); key.chmod(0o600)
        executable = base / "adapter"
        executable.write_text("#!/bin/sh\nexit 1\n"); executable.chmod(0o700)
        config = {k: self.cfg[k] for k in ("agent_id", "controller_url", "candidate_ports", "control_targets")}
        config.update(shared_key_file=str(key), state_dir=str(base / "state"), adapter=str(executable))
        p = base / "config.json"
        p.write_text(json.dumps(config)); p.chmod(0o600)
        self.assertEqual(agent.load_config(p, root_owned=False)["key"], KEY)
        config["controller_url"] = "http://control.example/relay-recovery-agent.php"
        p.write_text(json.dumps(config))
        with self.assertRaisesRegex(protocol.ProtocolError, "controller_url"):
            agent.load_config(p, root_owned=False)
        p.chmod(0o644)
        with self.assertRaisesRegex(protocol.ProtocolError, "private_file_mode"):
            agent.load_config(p, root_owned=False)

    def test_native_adapter_uses_fixed_argv_stdin_and_no_shell(self):
        context = {"incident_id": "i", "generation": 1, "revision": 1, "config_hash": "a" * 64}
        good = {"schema": agent.ADAPTER_SCHEMA, "ok": True, "checks": {"tunnel_ok": True}, **context}
        with patch.object(agent, "private_file"), patch.object(agent, "_bounded_process", return_value=(0, protocol.canonical_json(good))) as run:
            self.assertTrue(agent.local_adapter("/fixed/adapter", "probe", context)["ok"])
            args, kwargs = run.call_args
            self.assertEqual(args[0], ["/fixed/adapter", "probe"])
            self.assertNotIn("shell", kwargs)
            self.assertEqual(args[1], protocol.canonical_json(context))
        with patch.object(agent, "private_file"), patch.object(agent, "_bounded_process", side_effect=protocol.ProtocolError("adapter_timeout")):
            with self.assertRaisesRegex(protocol.ProtocolError, "adapter_timeout"):
                agent.local_adapter("/fixed/adapter", "probe", context)

    def test_adapter_output_and_timeout_are_enforced_while_running(self):
        with self.assertRaisesRegex(protocol.ProtocolError, "adapter_output_size"):
            agent._bounded_process([sys.executable, "-c", "import sys; sys.stdout.buffer.write(b'x'*70000)"], b"", 3)
        with self.assertRaisesRegex(protocol.ProtocolError, "adapter_timeout"):
            agent._bounded_process([sys.executable, "-c", "import time; time.sleep(5)"], b"", 0.05)

    def test_adapter_duplex_pipes_do_not_deadlock(self):
        code = "import sys; sys.stdout.buffer.write(b'x'*65536); sys.stdout.flush(); data=sys.stdin.buffer.read(); assert len(data)==65536"
        status, output = agent._bounded_process([sys.executable, "-c", code], b"a" * 65536, 3)
        self.assertEqual(status, 0)
        self.assertEqual(len(output), 65536)


if __name__ == "__main__":
    unittest.main()
