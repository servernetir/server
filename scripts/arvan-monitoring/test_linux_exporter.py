#!/usr/bin/env python3
"""Behavioral tests: no package scripts, network, systemd or host changes."""
import hashlib
import importlib.util
import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("installer", Path(__file__).with_name("install-linux-exporter.py"))
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)
DEB = b"trusted fixture package"
VERSION = "1.7.0-1"
METRICS = ('node_scrape_collector_success{collector="netdev"} 1\n'
           'node_network_receive_bytes_total{device="ens3"} 1200000\n'
           'node_network_transmit_bytes_total{device="ens3"} 2500000\n')


class StubRunner:
    def __init__(self):
        self.calls = []
        self.active = False
        self.public = False
        self.port_in_use = False
        self.routes = [{"dev": "ens3", "dst": "default"}]
        self.foreign_origin = False
        self.tamper_package = False
        self.wrong_identity = False
        self.existing_unit = False
        self.existing_managed_name_unit = False
        self.additional_candidate_index = ""
        self.release_origin = "Debian"
        self.listener_pid = "4321"

    def run(self, args, *, cwd=None, timeout=15):
        self.calls.append(list(args))
        command = args[0]
        if command == "ip":
            if "link" in args:
                return json.dumps([{"ifname": args[-1]}])
            return json.dumps(self.routes if "-4" in args else [])
        if command == "ss":
            if self.active or self.port_in_use:
                host = "0.0.0.0" if self.public else "127.0.0.1"
                process = f' users:(("node_exporter",pid={self.listener_pid},fd=3))' if "-ltnp" in args else ""
                return f"LISTEN 0 4096 {host}:19100 0.0.0.0:*{process}\n"
            return ""
        if command == "dpkg":
            return "amd64\n"
        if command == "apt-cache":
            if args[1] == "policy":
                host = "untrusted.example" if self.foreign_origin else "deb.debian.org"
                if len(args) == 2:
                    return f"Package files:\n 500 http://{host}/debian bookworm/main amd64 Packages\n     release o={self.release_origin},n=bookworm,c=main,b=amd64\n     origin {host}\n"
                return f"{m.PACKAGE}:\n  Installed: (none)\n  Candidate: {VERSION}\n  Version table:\n     {VERSION} 500\n        500 http://{host}/debian bookworm/main amd64 Packages\n{self.additional_candidate_index}"
            return f"Package: {m.PACKAGE}\nVersion: {VERSION}\nArchitecture: amd64\nSHA256: {m.digest(DEB)}\nSize: {len(DEB)}\n"
        if command == "apt-get":
            assert "download" in args and "install" not in args
            (Path(cwd) / "exporter.deb").write_bytes(b"tampered" if self.tamper_package else DEB)
            return ""
        if command == "dpkg-deb":
            if "--field" in args:
                return {"Package": "wrong" if self.wrong_identity else m.PACKAGE,
                        "Version": VERSION, "Architecture": "amd64"}[args[-1]]
            source = Path(args[-1]) / "usr/bin/prometheus-node-exporter"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"fixture binary")
            source.chmod(0o755)
            return ""
        if command == "systemctl":
            if args[1] == "show":
                if "--property=MainPID" in args:
                    return "4321"
                if args[2] == m.UNIT:
                    return "loaded" if self.existing_managed_name_unit else "not-found"
                return "loaded" if self.existing_unit else "not-found"
            if args[1] == "is-active":
                return "active" if self.active else "inactive"
            if args[1] == "enable":
                self.active = True
            if args[1] == "disable":
                self.active = False
            return ""
        if command.endswith("/prometheus-node-exporter"):
            return "node_exporter, version 1.7.0\n"
        raise AssertionError(args)


class InstallerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        for path in ["opt", "etc/systemd/system", "run/lock"]:
            (self.root / path).mkdir(parents=True, exist_ok=True)
        (self.root / "etc/os-release").write_text('ID="debian"\n')
        self.runner = StubRunner()
        self.installer = m.Installer(self.root, self.runner, lambda: METRICS)

    def tearDown(self):
        self.temp.cleanup()

    def plan_hash(self, plan):
        return m.digest(json.dumps(plan, sort_keys=True, separators=(",", ":")).encode())

    def apply(self, plan=None):
        plan = plan or self.installer.inventory()
        with patch.object(m.os, "geteuid", return_value=0), patch.object(m.time, "sleep"):
            return self.installer.apply(plan, self.plan_hash(plan))

    def test_plan_is_read_only_and_downloads_nothing(self):
        before = sorted(str(x.relative_to(self.root)) for x in self.root.rglob("*"))
        result = self.installer.inventory()
        after = sorted(str(x.relative_to(self.root)) for x in self.root.rglob("*"))
        self.assertEqual(before, after)
        self.assertEqual(result["interface"], "ens3")
        self.assertFalse(any(c[0] in {"apt-get", "dpkg-deb"} or c[1] in {"enable", "daemon-reload"} for c in self.runner.calls))

    def test_official_download_extract_and_loopback_service(self):
        result = self.apply()
        self.assertEqual(result["status"], "verified")
        self.assertEqual(result["counters"]["transmit_bytes"], "2500000")
        self.assertEqual(result["billing"], "not_performed")
        unit = self.installer.unit.read_text()
        self.assertIn("--web.listen-address=127.0.0.1:19100", unit)
        self.assertIn("--collector.netdev.device-include=^ens3$", unit)
        self.assertTrue(self.installer.manifest.exists())
        self.assertFalse((self.root / "etc/default/prometheus-node-exporter").exists())
        self.assertFalse(any("install" in c or "--unpack" in c or "--configure" in c for c in self.runner.calls))

    def test_exact_rerun_is_idempotent_and_no_download(self):
        self.apply()
        self.runner.calls.clear()
        result = self.apply()
        self.assertFalse(result["changed"])
        self.assertFalse(any(c[0] in {"apt-get", "dpkg-deb", "apt-cache"} for c in self.runner.calls))

    def test_unreviewed_hash_prevents_mutation(self):
        plan = self.installer.inventory()
        with self.assertRaisesRegex(m.Refused, "inventory_digest_mismatch"):
            self.installer.apply(plan, "0" * 64)
        self.assertFalse(self.installer.home.exists())

    def test_changed_inventory_prevents_mutation(self):
        plan = self.installer.inventory()
        self.runner.port_in_use = True
        with self.assertRaises(m.Refused):
            self.apply(plan)
        self.assertFalse(self.installer.home.exists())

    def test_existing_exporter_config_is_preserved(self):
        p = self.root / "etc/default/prometheus-node-exporter"
        p.parent.mkdir(exist_ok=True)
        p.write_text('ARGS="--existing-setting"')
        with self.assertRaisesRegex(m.Refused, "existing_exporter"):
            self.installer.inventory()
        self.assertEqual(p.read_text(), 'ARGS="--existing-setting"')

    def test_existing_unit_is_preserved(self):
        self.runner.existing_unit = True
        with self.assertRaisesRegex(m.Refused, "existing_exporter_unit"):
            self.installer.inventory()

    def test_same_name_unit_loaded_outside_etc_is_never_overridden_or_stopped(self):
        self.runner.existing_managed_name_unit = True
        with self.assertRaisesRegex(m.Refused, "existing_exporter_unit"):
            self.installer.inventory()
        self.assertFalse(self.installer.home.exists())
        self.assertFalse(self.installer.unit.exists())
        self.assertFalse(any(c[0] == "systemctl" and c[1] in {"enable", "disable", "daemon-reload"}
                             for c in self.runner.calls))

    def test_non_http_sibling_index_cannot_hide_behind_official_candidate(self):
        for source in ["file:/srv/packages stable/main amd64 Packages",
                       "cdrom://Debian stable/main amd64 Packages",
                       "/var/lib/dpkg/status",
                       "copy:/srv/packages stable/main amd64 Packages"]:
            with self.subTest(source=source):
                self.runner.additional_candidate_index = f"        500 {source}\n"
                with self.assertRaisesRegex(m.Refused, "index_transport_not_verified"):
                    self.installer.inventory()
                self.assertFalse(any(c[0] == "apt-get" for c in self.runner.calls))

    def test_dotted_interface_unit_argv_keeps_literal_regex_and_counters(self):
        self.runner.routes = [{"dev": "ens3.100", "dst": "default"}]
        self.installer.metrics_reader = lambda: METRICS.replace("ens3", "ens3.100")
        result = self.apply()
        self.assertEqual(result["interface"], "ens3.100")
        exec_start = next(line[10:] for line in self.installer.unit.read_text().splitlines()
                          if line.startswith("ExecStart="))
        # No backslash escapes reach systemd; argv has no shell/glob expansion.
        self.assertNotIn("\\", exec_start)
        argv = shlex.split(exec_start)
        option = next(x for x in argv if x.startswith("--collector.netdev.device-include="))
        pattern = option.split("=", 1)[1]
        self.assertTrue(re.fullmatch(pattern, "ens3.100"))
        self.assertFalse(re.fullmatch(pattern, "ens3X100"))

    def test_foreign_repository_is_refused(self):
        self.runner.foreign_origin = True
        with self.assertRaisesRegex(m.Refused, "nonofficial_repository"):
            self.installer.inventory()

    def test_release_origin_must_match_official_site_and_os(self):
        self.runner.release_origin = "ForeignRepo"
        with self.assertRaisesRegex(m.Refused, "origin_and_site"):
            self.installer.inventory()

    def test_help_does_not_inventory_or_mutate_host(self):
        result = subprocess.run([sys.executable, str(Path(m.__file__)), "--help"], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertIn("--inventory-hash", result.stdout)
        self.assertFalse(self.runner.calls)

    def test_ambiguous_interfaces_require_explicit_review(self):
        self.runner.routes.append({"dev": "ens4", "dst": "default"})
        with self.assertRaisesRegex(m.Refused, "ambiguous_default"):
            self.installer.inventory()
        self.assertEqual(self.installer.inventory("ens4")["interface"], "ens4")
        with self.assertRaises(m.Refused):
            self.installer.inventory("docker0")

    def test_package_hash_or_identity_mismatch_prevents_extract(self):
        for setting in ["tamper_package", "wrong_identity"]:
            with self.subTest(setting=setting):
                setattr(self.runner, setting, True)
                with self.assertRaises(m.Refused):
                    self.apply()
                self.assertFalse(self.installer.home.exists())
                self.assertFalse(any("--extract" in c for c in self.runner.calls))
                setattr(self.runner, setting, False)
                self.runner.calls.clear()

    def test_missing_rx_tx_or_failed_netdev_rolls_back_owned_files(self):
        for body in [METRICS.replace("transmit_bytes_total", "other"),
                     METRICS.replace('netdev"} 1', 'netdev"} 0'),
                     METRICS.replace("1200000", "NaN")]:
            with self.subTest(body=body):
                self.installer.metrics_reader = lambda: body
                with self.assertRaises(m.Refused):
                    self.apply()
                self.assertFalse(self.runner.active)
                self.assertFalse(self.installer.home.exists())
                self.assertFalse(self.installer.unit.exists())

    def test_public_listener_fails_verification_and_is_stopped(self):
        self.runner.public = True
        with self.assertRaisesRegex(m.Refused, "loopback_listener"):
            self.apply()
        self.assertFalse(self.runner.active)

    def test_tampered_binary_or_unit_never_overwritten(self):
        self.apply()
        self.installer.binary.write_bytes(b"changed by operator")
        with self.assertRaisesRegex(m.Refused, "installation_changed"):
            self.installer.inventory()
        self.assertEqual(self.installer.binary.read_bytes(), b"changed by operator")

    def test_listener_must_belong_to_our_unit_pid(self):
        self.runner.listener_pid = "9876"
        with self.assertRaisesRegex(m.Refused, "not_owned"):
            self.apply()
        self.assertFalse(self.runner.active)

    def test_default_route_change_on_rerun_requires_review(self):
        self.apply()
        self.runner.routes = [{"dev": "ens4", "dst": "default"}]
        with self.assertRaisesRegex(m.Refused, "interface_changed"):
            self.installer.inventory()

    def test_symlink_path_is_refused(self):
        target = self.root / "other"
        target.mkdir()
        self.installer.home.symlink_to(target, target_is_directory=True)
        with self.assertRaisesRegex(m.Refused, "symlink"):
            self.installer.inventory()

    def test_metric_counters_accept_integer_scientific_not_negative(self):
        self.apply()
        self.installer.metrics_reader = lambda: METRICS.replace("1200000", "1.2e+06")
        self.assertEqual(self.installer.verify("ens3")["status"], "verified")
        self.installer.metrics_reader = lambda: METRICS.replace("1200000", "-1")
        with self.assertRaisesRegex(m.Refused, "counter_invalid"):
            self.installer.verify("ens3")


if __name__ == "__main__":
    unittest.main()
