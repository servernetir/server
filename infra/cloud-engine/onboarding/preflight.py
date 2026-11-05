#!/usr/bin/env python3
"""Read-only node preflight; failures never cause installation or purchases."""
import argparse
import json
import os
from pathlib import Path


def inspect(root=Path("/")):
    cpu = (root / "proc/cpuinfo").read_text()
    meminfo = dict(line.split(":", 1) for line in (root / "proc/meminfo").read_text().splitlines())
    memory_mib = int(meminfo["MemTotal"].strip().split()[0]) // 1024
    flags = set()
    for line in cpu.splitlines():
        if line.startswith(("flags", "Features")):
            flags.update(line.split(":", 1)[1].split())
    checks = {"hardware_virtualization": bool(flags & {"vmx", "svm"}),
              "ram_at_least_32_gib": memory_mib >= 32768,
              "kvm_device_present": (root / "dev/kvm").exists(),
              "management_console_confirmed": False,
              "mirrored_storage_health_confirmed": False,
              "routed_subnet_confirmed": False,
              "backup_restore_confirmed": False}
    return {"read_only": True, "memory_mib": memory_mib,
            "logical_cpu_count": os.cpu_count(), "checks": checks,
            "ready_for_sales": False,
            "note": "False manual checks require separate evidence; this is not a hardware acceptance test."}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    data = inspect()
    rendered = json.dumps(data, indent=2) + "\n"
    if args.output:
        with args.output.open("x") as file:
            file.write(rendered)
    else:
        print(rendered, end="")


if __name__ == "__main__":
    main()
