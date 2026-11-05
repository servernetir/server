"""Pinned wrapper tests using the real installer and its existing offline runner."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


bootstrap = load("bootstrap_linux_exporter", HERE.parents[1] / "website/resources/arvan-monitoring/bootstrap-linux-exporter.py")
legacy = load("existing_exporter_tests", HERE / "test_linux_exporter.py")
SOURCE = (HERE / "install-linux-exporter.py").read_bytes()
NOW = 1793674800


class BootstrapRunner(legacy.StubRunner):
    def __init__(self):
        super().__init__()
        self.missing_metadata = False
        self.fail_refresh = False
        self.untrusted_after_refresh = False
        self.still_missing_after_refresh = False
        self.refresh_calls = []
        self.module = None

    def run(self, args, *, cwd=None, timeout=15):
        if args[0] == "apt-get" and args[-1] == "update":
            self.calls.append(list(args))
            self.refresh_calls.append((list(args), timeout))
            if self.fail_refresh:
                raise self.module.Refused("command_failed:PRIVATE_MIRROR_CANARY")
            self.missing_metadata = self.still_missing_after_refresh
            self.foreign_origin = self.untrusted_after_refresh
            return "PRIVATE_APT_OUTPUT_MUST_NOT_PRINT"
        if args[:3] == ["apt-cache", "policy", "prometheus-node-exporter"] and self.missing_metadata:
            self.calls.append(list(args))
            return "prometheus-node-exporter:\n  Candidate: (none)\n"
        return super().run(args, cwd=cwd, timeout=timeout)


class LinuxBootstrapTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="linux-bootstrap-offline-")
        self.root = Path(self.temp.name)
        for directory in ["opt", "etc/systemd/system", "run/lock", "run/systemd/system", "proc/1", "var/lib/apt/lists"]:
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        (self.root / "etc/os-release").write_text('ID="debian"\n')
        (self.root / "proc/1/comm").write_text("systemd\n")
        self.release = self.root / "var/lib/apt/lists/deb.debian.org_debian_dists_bookworm_InRelease"
        self.release.write_text("SYNTHETIC_METADATA")
        os.utime(self.release, (NOW - 60, NOW - 60))
        self.runner = BootstrapRunner()
        self.apply_calls = 0
        self.factory_calls = 0

    def tearDown(self):
        self.temp.cleanup()

    def factory(self, module, root):
        self.factory_calls += 1
        self.runner.module = module
        installer = module.Installer(root, self.runner, lambda: legacy.METRICS)
        apply = installer.apply

        def counted_apply(*args):
            self.apply_calls += 1
            return apply(*args)

        installer.apply = counted_apply
        return installer

    def invoke(self, source=SOURCE):
        with patch.object(bootstrap.os, "geteuid", return_value=0), \
                patch.object(bootstrap.shutil, "which", side_effect=lambda name, path=None: "/usr/bin/" + name), \
                patch.object(bootstrap.time, "sleep"):
            return bootstrap.run_embedded(source, _root=self.root, _installer_factory=self.factory, _now=NOW)

    def assert_refused(self, output, reason):
        self.assertEqual(output["status"], "refused")
        self.assertEqual(output["reason"], reason)
        self.assertEqual(output["central_enrollment"], "not_performed")
        self.assertEqual(output["billing"], "not_performed")

    def test_source_pin_refuses_before_exec_or_factory(self):
        with patch("builtins.exec", side_effect=AssertionError("must_not_exec")):
            output = self.invoke(b'raise RuntimeError("SOURCE_CANARY")')
        self.assert_refused(output, "installer_source_pin_mismatch")
        self.assertEqual(self.factory_calls, 0)
        self.assertEqual(self.runner.calls, [])
        self.assertNotIn("SOURCE_CANARY", json.dumps(output))

    def test_installer_import_failure_before_refused_class_is_redacted(self):
        with patch("builtins.exec", side_effect=ImportError("PRIVATE_IMPORT_CANARY")):
            output = self.invoke()
        self.assert_refused(output, "bootstrap_runtime_failure")
        self.assertEqual(self.factory_calls, 0)
        self.assertEqual(self.runner.calls, [])
        self.assertNotIn("PRIVATE_IMPORT_CANARY", json.dumps(output))

    def test_first_install_uses_real_installer_and_only_downloads_exact_package(self):
        output = self.invoke()
        self.assertEqual(output["status"], "verified")
        self.assertTrue(output["changed"])
        self.assertEqual(self.apply_calls, 1)
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertTrue((self.root / "opt/servernet-node-exporter/manifest.json").is_file())
        unit = (self.root / "etc/systemd/system" / legacy.m.UNIT).read_text()
        self.assertIn("DynamicUser=yes", unit)
        self.assertIn("127.0.0.1:19100", unit)
        self.assertFalse(any("install" in command or "--unpack" in command or "--configure" in command for command in self.runner.calls))
        self.assertEqual(output["central_enrollment"], "not_performed")
        self.assertEqual(output["billing"], "not_performed")

    def test_managed_rerun_verifies_without_metadata_or_apt(self):
        self.invoke()
        self.runner.calls.clear()
        self.release.unlink()
        with patch.object(bootstrap, "_metadata_stale", side_effect=AssertionError("must_not_check_metadata")):
            output = self.invoke()
        self.assertEqual(output["status"], "verified")
        self.assertFalse(output["changed"])
        self.assertEqual(self.apply_calls, 2)
        self.assertFalse(any(command[0] in {"apt-get", "apt-cache", "dpkg-deb"} for command in self.runner.calls))

    def test_unsupported_os_refuses_before_installer_or_apt(self):
        for release in ["ID=rocky\n", "ID=linuxmint\nID_LIKE=ubuntu\n", "ID=ubuntu\nID=debian\n",
                        "ID=ubuntu\nID=rocky\n", 'ID="debian"\nID=windows\n', "ID=\n",
                        " ID=ubuntu\n", "ID=ubuntu\nID =windows\n"]:
            with self.subTest(release=release):
                (self.root / "etc/os-release").write_text(release)
                self.assert_refused(self.invoke(), "only_debian_ubuntu_supported")
        self.assertEqual(self.factory_calls, 0)
        self.assertEqual(self.runner.calls, [])

    def test_ubuntu_is_supported_with_matching_official_origin(self):
        (self.root / "etc/os-release").write_text("ID=ubuntu\n")
        self.runner.release_origin = "Ubuntu"
        self.assertEqual(self.invoke()["status"], "verified")

    def test_root_python_systemd_and_command_prerequisites_refuse_without_apt(self):
        with patch.object(bootstrap.os, "geteuid", return_value=1000):
            self.assert_refused(bootstrap.run_embedded(SOURCE, _root=self.root), "root_required")
        with patch.object(bootstrap.sys, "version_info", (3, 8)):
            self.assert_refused(self.invoke(), "python_3_9_required")
        (self.root / "proc/1/comm").write_text("other-init\n")
        self.assert_refused(self.invoke(), "systemd_required")
        (self.root / "proc/1/comm").write_text("systemd\n")
        with patch.object(bootstrap.os, "geteuid", return_value=0), patch.object(bootstrap.shutil, "which", return_value=None):
            self.assert_refused(bootstrap.run_embedded(SOURCE, _root=self.root), "required_command_missing:systemctl")
        self.assertEqual(self.runner.calls, [])

    def test_missing_candidate_refreshes_once_then_applies_once(self):
        self.runner.missing_metadata = True
        output = self.invoke()
        self.assertEqual(output["status"], "verified")
        self.assertEqual(self.runner.refresh_calls, [(bootstrap.REFRESH_ARGS, 120)])
        self.assertTrue(output["apt_metadata_refresh_attempted"])
        self.assertTrue(output["apt_metadata_refresh_completed"])
        self.assertEqual(self.apply_calls, 1)
        self.assertNotIn("PRIVATE_APT_OUTPUT", json.dumps(output))

    def test_stale_or_absent_release_metadata_refreshes_once(self):
        os.utime(self.release, (NOW - 86401, NOW - 86401))
        self.assertEqual(self.invoke()["status"], "verified")
        self.assertEqual(len(self.runner.refresh_calls), 1)
        self.assertEqual(self.apply_calls, 1)
        # Fresh independent empty metadata decision does not inspect package state.
        self.release.unlink()
        self.assertTrue(bootstrap._metadata_stale(self.root, NOW))

    def test_absent_release_index_refreshes_once_then_applies_once(self):
        self.release.unlink()
        self.assertEqual(self.invoke()["status"], "verified")
        self.assertEqual(len(self.runner.refresh_calls), 1)
        self.assertEqual(self.apply_calls, 1)

    def test_refresh_success_with_still_missing_candidate_never_retries(self):
        self.runner.missing_metadata = True
        self.runner.still_missing_after_refresh = True
        output = self.invoke()
        self.assert_refused(output, bootstrap.MISSING_CANDIDATE)
        self.assertEqual(len(self.runner.refresh_calls), 1)
        self.assertEqual(self.apply_calls, 0)
        self.assertTrue(output["apt_metadata_refresh_completed"])

    def test_refresh_failure_never_applies_or_leaks_output(self):
        self.runner.missing_metadata = True
        self.runner.fail_refresh = True
        output = self.invoke()
        self.assert_refused(output, "apt_metadata_refresh_failed")
        self.assertEqual(len(self.runner.refresh_calls), 1)
        self.assertFalse(output["apt_metadata_refresh_completed"])
        self.assertEqual(self.apply_calls, 0)
        self.assertFalse((self.root / "opt/servernet-node-exporter").exists())
        self.assertNotIn("PRIVATE_MIRROR", json.dumps(output))

    def test_refreshed_untrusted_candidate_is_refused_without_apply(self):
        self.runner.missing_metadata = True
        self.runner.untrusted_after_refresh = True
        output = self.invoke()
        self.assert_refused(output, "candidate_has_nonofficial_repository_requires_review")
        self.assertEqual(len(self.runner.refresh_calls), 1)
        self.assertEqual(self.apply_calls, 0)
        self.assertFalse(any(command[0] == "apt-get" and "download" in command for command in self.runner.calls))

    def test_initial_foreign_origin_exporter_or_ambiguous_nic_never_refreshes(self):
        os.utime(self.release, (NOW - 90000, NOW - 90000))
        self.runner.foreign_origin = True
        self.assert_refused(self.invoke(), "candidate_has_nonofficial_repository_requires_review")
        self.runner.foreign_origin = False
        self.runner.existing_unit = True
        self.assert_refused(self.invoke(), "existing_exporter_unit_requires_inventory_review")
        self.runner.existing_unit = False
        self.runner.routes.append({"dev": "ens4", "dst": "default"})
        self.assert_refused(self.invoke(), "ambiguous_default_interface_use_explicit_interface")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 0)

    def test_tampered_managed_installation_is_preserved_and_never_refreshed(self):
        self.invoke()
        binary = self.root / "opt/servernet-node-exporter/node_exporter"
        binary.write_bytes(b"changed")
        self.runner.calls.clear()
        self.assert_refused(self.invoke(), "managed_installation_changed_requires_review")
        self.assertEqual(binary.read_bytes(), b"changed")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 1)

    def test_metadata_symlink_requires_review_without_refresh_or_apply(self):
        self.release.unlink()
        self.release.symlink_to(self.root / "etc/os-release")
        self.assert_refused(self.invoke(), "apt_metadata_file_requires_review")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 0)

    def test_missing_candidate_cannot_bypass_release_symlink_guard(self):
        self.runner.missing_metadata = True
        self.release.unlink()
        self.release.symlink_to(self.root / "etc/os-release")
        self.assert_refused(self.invoke(), "apt_metadata_file_requires_review")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 0)

    def test_missing_candidate_cannot_bypass_unsafe_release_mode_guard(self):
        self.runner.missing_metadata = True
        self.release.chmod(0o666)
        self.assert_refused(self.invoke(), "apt_metadata_file_requires_review")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 0)

    def test_missing_candidate_cannot_bypass_lists_directory_symlink_guard(self):
        self.runner.missing_metadata = True
        directory = self.root / "var/lib/apt/lists"
        moved = directory.with_name("lists-original")
        directory.rename(moved)
        directory.symlink_to(moved, target_is_directory=True)
        self.assert_refused(self.invoke(), "apt_metadata_path_requires_review")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 0)

    def test_missing_candidate_cannot_bypass_metadata_parent_symlink_guard(self):
        self.runner.missing_metadata = True
        directory = self.root / "var/lib/apt"
        moved = directory.with_name("apt-original")
        directory.rename(moved)
        directory.symlink_to(moved, target_is_directory=True)
        self.assert_refused(self.invoke(), "apt_metadata_path_requires_review")
        self.assertEqual(self.runner.refresh_calls, [])
        self.assertEqual(self.apply_calls, 0)

    def test_unexpected_installer_refusal_is_redacted(self):
        def factory(module, root):
            class Refusing:
                def inventory(self):
                    raise module.Refused("PRIVATE_SECRET_CANARY")
            return Refusing()
        with patch.object(bootstrap.os, "geteuid", return_value=0), patch.object(bootstrap.shutil, "which", return_value="/usr/bin/tool"):
            output = bootstrap.run_embedded(SOURCE, _root=self.root, _installer_factory=factory)
        self.assert_refused(output, "installer_refused")
        self.assertNotIn("PRIVATE_SECRET_CANARY", json.dumps(output))


if __name__ == "__main__":
    unittest.main()
