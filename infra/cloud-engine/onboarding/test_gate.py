import hashlib
from pathlib import Path
import tempfile
import unittest

from gate import HARDWARE, SOFTWARE, evaluate


class EvidenceGateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "test.log").write_text("synthetic passing fixture\n")
        self.record = {"result": "passed", "mode": "mock", "evidence_path": "test.log", "evidence_sha256": hashlib.sha256((self.root / "test.log").read_bytes()).hexdigest(), "executed": 3, "exit_code": 0}

    def test_software_evidence_does_not_establish_real_hardware_or_authorization(self):
        result = evaluate({"checks": {k: self.record for k in SOFTWARE}}, self.root)
        self.assertTrue(result["software_purchase_gate_passed"])
        self.assertFalse(result["real_hardware_acceptance_passed"])
        self.assertFalse(result["purchase_authorized"])
        self.assertFalse(result["one_working_day_target_confirmed"])

    def test_mocked_hardware_is_rejected(self):
        result = evaluate({"checks": {k: self.record for k in SOFTWARE + HARDWARE}}, self.root)
        self.assertFalse(result["real_hardware_acceptance_passed"])
        self.assertIn("Real-node", result["checks"]["real_console"]["reason"])

    def test_changed_evidence_and_zero_tests_cannot_pass(self):
        (self.root / "test.log").write_text("modified evidence")
        result = evaluate({"checks": {k: self.record for k in SOFTWARE}}, self.root)
        self.assertFalse(result["software_purchase_gate_passed"])
        self.assertEqual("Evidence hash mismatch", result["checks"]["architecture"]["reason"])
        record = dict(self.record, executed=0)
        result = evaluate({"checks": {"api_tests": record}}, self.root)
        self.assertIn("nonzero", result["checks"]["api_tests"]["reason"])


if __name__ == "__main__":
    unittest.main()
