"""Worker integration boundaries. No real provider/network/PHP invocations."""
import copy
import hashlib
import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

INFRA = Path(__file__).resolve().parents[2]
if str(INFRA) not in sys.path:
    sys.path.insert(0, str(INFRA))
from relay_recovery import core, worker


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        self.now = 1_700_000_000
        self.cfg = {"state_dir": str(self.root), "binding": {"owner_id": "1", "service_id": "711"},
                    "policy": {"enabled": False, "owner_id": "1", "service_id": "711", "project_id": "project-a",
                               "outage_seconds": 60, "suspect_seconds": 30, "recover_seconds": 30},
                    "active": {"provider": "hetzner", "project_id": "project-a", "provider_id": "old",
                               "generation": 1, "config_hash": "config-1"}}

    def evidence(self, now, state):
        active = state["active"]
        fresh = {"verified": True, "received_at": now}
        return {"schema": "servernet.relay.evidence.v1", "evidence": {
            "health": dict(fresh, agent_id="ir-agent", generation=active["generation"], config_hash=active["config_hash"],
                           direct_control=True, host_healthy=True, internet_healthy=True, tunnel_ok=False, probe_ok=False),
            "preflight": dict(fresh, owner_id="1", service_id="711", project_id="project-a", ready=True,
                              checks={k: True for k in core.Config().required_preflight}),
            "inventory": dict(fresh, complete=True, managed_vm_count=1, project_id="project-a", active_identity=core.identity(active)),
            "options": [dict(fresh, country="DE", location="fsn1", available=True, compatible=True,
                             provider="hetzner", project_id="project-a", owner_id="1", service_id="711", currency="EUR",
                             architecture="x86", config_hash="config-2", server_type="cx-test", image="100",
                             monthly_gross_micro=5_000_000, trial_max_gross_micro=100_000)]}}

    def test_incomplete_adoption_does_not_even_invoke_adapter(self):
        cfg = {**self.cfg, "active": None}
        def forbidden(*args):
            raise AssertionError("must not invoke adapter")
        result = worker.tick(cfg, self.now, adapter=forbidden)
        self.assertEqual("ACTIVE_ADOPTION_NOT_COMPLETE", result["reason"])
        self.assertFalse(result["effects_enabled"])
        self.assertFalse((self.root / "controller.sqlite3").exists())

    def test_disabled_policy_collects_only_and_never_commits_effect(self):
        calls = []
        def adapter(config, action, payload):
            calls.append(action)
            self.assertEqual("collect", action)
            return self.evidence(payload["now"], payload["state"])
        for step in (0, 30, 60, 90):
            result = worker.tick(self.cfg, self.now + step, adapter=adapter)
            self.assertEqual(("HOLD", "DISABLED", False),
                             (result["intent"], result["reason"], result["effects_enabled"]))
        self.assertEqual(["collect"] * 4, calls)
        with sqlite3.connect(self.root / "controller.sqlite3") as db:
            self.assertEqual(0, db.execute("SELECT COUNT(*) FROM actions").fetchone()[0])

    def test_no_adapter_or_failed_collection_fails_closed_without_raw_error(self):
        self.cfg["policy"]["enabled"] = True
        result = worker.tick(self.cfg, self.now)
        self.assertEqual("HOLD", result["intent"])
        self.assertFalse(result["effect_performed"])
        def failed(*args):
            raise RuntimeError("password-and-token-must-never-leak")
        result = worker.tick(self.cfg, self.now + 30, adapter=failed)
        self.assertEqual("HOLD", result["intent"])
        self.assertNotIn("password-and-token", json.dumps(result))

    def test_owner_binding_is_checked_before_adapter_or_journal(self):
        self.cfg["binding"]["owner_id"] = "other-owner"
        with self.assertRaisesRegex(core.RecoveryError, "owner binding"):
            worker.tick(self.cfg, self.now, adapter=lambda *args: self.fail("adapter invoked"))
        self.assertFalse((self.root / "controller.sqlite3").exists())

    def test_worker_continues_after_promotion_with_original_startup_seed(self):
        # Fixture is a durable state left by a completed promotion. The startup
        # config remains the original adoption record across a process restart.
        journal = core.Journal(self.root / "controller.sqlite3")
        journal.initialize(self.cfg["active"], self.now)
        with journal.transaction() as db:
            state = journal._read(db)
            state["active"] = {"provider": "hetzner", "project_id": "project-a", "provider_id": "new",
                               "generation": 2, "config_hash": "config-2"}
            journal._write(db, state)
        result = worker.tick(self.cfg, self.now + 30, adapter=lambda c, a, p: self.evidence(p["now"], p["state"]))
        self.assertEqual("HOLD", result["intent"])
        self.assertEqual("new", journal.snapshot()["active"]["provider_id"])
        self.assertEqual("old", self.cfg["active"]["provider_id"])

    def test_wrong_existing_project_is_not_re_adopted_from_config(self):
        journal = core.Journal(self.root / "controller.sqlite3")
        journal.initialize({**self.cfg["active"], "project_id": "wrong-project"}, self.now)
        with self.assertRaisesRegex(core.RecoveryError, "provider scope"):
            worker.tick(self.cfg, self.now + 30, adapter=lambda *args: self.fail("adapter invoked"))

    def test_mutation_is_committed_before_adapter_and_timeout_only_reconciles(self):
        self.cfg["policy"]["enabled"] = True
        calls = []
        def adapter(config, action, payload):
            calls.append(action)
            if action == "collect":
                return self.evidence(payload["now"], payload["state"])
            intent = payload["intent"]
            with sqlite3.connect(self.root / "controller.sqlite3") as db:
                self.assertIsNotNone(db.execute("SELECT id FROM actions WHERE id=?", (intent["id"],)).fetchone())
                state = json.loads(db.execute("SELECT body FROM state WHERE id=1").fetchone()[0])
                self.assertEqual("CREATE_UNKNOWN", state["phase"])
            if action == "CREATE":
                raise TimeoutError("token-must-not-leak")
            self.assertEqual("RECONCILE_CREATE", action)
            self.assertTrue(intent["payload"]["read_only"])
            return {"schema": "servernet.relay.effect.v1", "receipt": {
                "intent_id": intent["id"], "kind": action, "outcome": "unknown",
                "verified": True, "received_at": payload["now"]}}
        for step in (0, 30, 60, 90):
            now = self.now + step
            with patch.object(worker.time, "time", return_value=now):
                result = worker.tick(self.cfg, now, adapter=adapter)
        self.assertEqual(1, calls.count("CREATE"))
        self.assertEqual(1, calls.count("RECONCILE_CREATE"))
        self.assertEqual("CREATE_UNKNOWN", result["phase"])
        self.assertNotIn("token-must-not-leak", json.dumps(result))

    def executable(self):
        path = self.root / "fixed-adapter"
        path.write_text("#!/usr/bin/python3\n# inert test adapter; execution is mocked\n")
        path.chmod(0o700)
        self.cfg["adapter"] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
        return path

    def test_reconciliation_requires_explicit_read_only_before_process(self):
        self.executable()
        with patch("relay_recovery.agent._bounded_process") as process:
            with self.assertRaisesRegex(core.RecoveryError, "read only"):
                worker.invoke_adapter(self.cfg, "RECONCILE_CREATE", {"intent": {"payload": {}}})
            process.assert_not_called()

    def test_pinned_fixed_adapter_uses_bounded_argv_stdin_without_shell(self):
        path = self.executable()
        payload = {"intent": {"payload": {"read_only": True}}, "now": self.now}
        with patch("relay_recovery.agent._bounded_process", return_value=(0, b'{"schema":"test-result"}')) as process:
            self.assertEqual({"schema": "test-result"}, worker.invoke_adapter(self.cfg, "RECONCILE_CREATE", payload))
            argv, raw = process.call_args.args
            self.assertEqual([str(path), "RECONCILE_CREATE"], argv)
            self.assertEqual(payload, json.loads(raw))
            self.assertEqual(30, process.call_args.kwargs["timeout"])
        path.write_text("changed trusted executable")
        with patch("relay_recovery.agent._bounded_process") as process:
            with self.assertRaisesRegex(core.RecoveryError, "hash mismatch"):
                worker.invoke_adapter(self.cfg, "collect", {})
            process.assert_not_called()

    def test_public_state_directory_is_refused(self):
        self.root.chmod(0o755)
        with self.assertRaisesRegex(core.RecoveryError, "Private state"):
            worker.tick(self.cfg, self.now)

    def test_module_cli_entry_works_without_provider_or_laravel(self):
        cfg = copy.deepcopy(self.cfg)
        cfg["active"] = None
        path = self.root / "config.json"
        path.write_text(json.dumps(cfg))
        path.chmod(0o600)
        result = subprocess.run([sys.executable, "-m", "relay_recovery.worker", "--config", str(path)],
                                cwd=INFRA, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("ACTIVE_ADOPTION_NOT_COMPLETE", json.loads(result.stdout)["reason"])
        self.assertEqual(0o600, (self.root / "status.json").stat().st_mode & 0o777)


if __name__ == "__main__":
    unittest.main()
