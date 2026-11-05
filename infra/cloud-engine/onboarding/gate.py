#!/usr/bin/env python3
"""Validate evidence references; never purchases hardware or activates sales."""
import argparse
import hashlib
import json
from pathlib import Path


SOFTWARE = (
    "architecture", "application", "migrations", "api_tests", "existing_tests",
    "provisioning_tests", "security_tests", "network_design", "ip_subnet_design",
    "images_prepared", "console_mock_tests", "traffic_mock_tests", "deployment_tools",
    "monitoring", "backup_runbook", "capacity_model", "commercial_model",
)
HARDWARE = (
    "node_install", "real_network_isolation", "real_image_initialization",
    "real_console", "generated_traffic_accuracy", "backup_restore", "pilot_order_lifecycle",
)


def evaluate(document, base):
    records = document.get("checks", {})
    checks = {}
    for key in SOFTWARE + HARDWARE:
        record = records.get(key, {})
        reason = None
        if record.get("result") != "passed":
            reason = "No completed passing evidence"
        elif record.get("mode") not in {"review", "mock", "unit", "real"}:
            reason = "Unknown evidence mode"
        elif key in HARDWARE and (record.get("mode") != "real" or not record.get("node_identity")):
            reason = "Real-node evidence and node identity required"
        elif key.endswith("tests") and (record.get("exit_code") != 0 or not isinstance(record.get("executed"), int) or record["executed"] < 1):
            reason = "A completed nonzero test count and zero command exit are required"
        else:
            path = record.get("evidence_path")
            expected = record.get("evidence_sha256")
            target = (Path(base) / str(path or "")).resolve()
            if not path or not target.is_relative_to(Path(base).resolve()) or not target.is_file():
                reason = "Missing local evidence file beneath evidence directory"
            elif not isinstance(expected, str) or len(expected) != 64:
                reason = "Evidence SHA256 required"
            else:
                with target.open("rb") as file:
                    digest = hashlib.file_digest(file, "sha256").hexdigest()
                if digest != expected:
                    reason = "Evidence hash mismatch"
        checks[key] = {"passed": reason is None, "reason": reason}
    software_ready = all(checks[key]["passed"] for key in SOFTWARE)
    hardware_ready = software_ready and all(checks[key]["passed"] for key in HARDWARE)
    return {"software_purchase_gate_passed": software_ready,
            "real_hardware_acceptance_passed": hardware_ready,
            "purchase_authorized": False, "sales_activation_authorized": False,
            "one_working_day_target_confirmed": False,
            "checks": checks,
            "note": "Evidence checks do not grant purchase/deployment authority; owner approval and measured real-node timing remain separate."}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("evidence", type=Path)
    p.add_argument("--phase", choices=["software", "hardware"], default="software")
    a = p.parse_args()
    try:
        result = evaluate(json.loads(a.evidence.read_text()), a.evidence.parent)
    except (ValueError, OSError) as exc:
        p.exit(2, f"Evidence rejected: {exc}\n")
    print(json.dumps(result, indent=2))
    raise SystemExit(0 if result["software_purchase_gate_passed" if a.phase == "software" else "real_hardware_acceptance_passed"] else 2)


if __name__ == "__main__":
    main()
