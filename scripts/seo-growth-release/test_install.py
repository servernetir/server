"""Exercise drift, path containment, staging integrity and guarded writes."""
import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("installer", Path(__file__).with_name("install.py"))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / "home"
        self.bundle = Path(self.temp.name) / "bundle"
        self.home.mkdir()
        self.name = "servernet_app/config/growth.php"
        self.target = self.home / self.name
        self.target.parent.mkdir(parents=True)
        self.target.write_bytes(b"old")
        self.target.chmod(0o640)
        self.stage = self.bundle / "stage" / self.name
        self.stage.parent.mkdir(parents=True)
        self.stage.write_bytes(b"new")
        self.manifest = {"schema": 1, "files": [{"path": self.name,
            "before_sha256": hashlib.sha256(b"old").hexdigest(),
            "after_sha256": hashlib.sha256(b"new").hexdigest()}]}
        self.save()
    def save(self):
        (self.bundle / "manifest.json").write_text(json.dumps(self.manifest))
    def test_preview_never_writes(self):
        mod.plan(self.home, self.bundle)
        self.assertEqual(self.target.read_bytes(), b"old")
        self.assertFalse((self.bundle / "backups").exists())
    def test_success_preserves_mode_and_verified_backup(self):
        receipt = mod.install(self.home, self.bundle)
        self.assertEqual(self.target.read_bytes(), b"new")
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o640)
        self.assertEqual((Path(receipt["backup"]) / self.name).read_bytes(), b"old")
        self.assertEqual(receipt["cms"], "not performed")
    def test_drift_refuses_every_write(self):
        self.target.write_bytes(b"editor")
        with self.assertRaisesRegex(ValueError, "Live drift"):
            mod.install(self.home, self.bundle)
        self.assertEqual(self.target.read_bytes(), b"editor")
    def test_stage_corruption_refuses_every_write(self):
        self.stage.write_bytes(b"corrupt")
        with self.assertRaisesRegex(ValueError, "Stage hash"):
            mod.install(self.home, self.bundle)
        self.assertEqual(self.target.read_bytes(), b"old")
    def test_traversal_and_environment_targets_are_refused(self):
        for name in ["../escape", "servernet_app/.env", "public_html/index.php"]:
            with self.assertRaises(ValueError):
                mod.safe(self.home, name)
    def test_symlink_is_refused(self):
        self.target.unlink()
        self.target.symlink_to(self.stage)
        with self.assertRaisesRegex(ValueError, "Symlink"):
            mod.plan(self.home, self.bundle)
    def test_second_apply_refuses_to_overwrite_subsequent_state(self):
        mod.install(self.home, self.bundle)
        with self.assertRaisesRegex(ValueError, "Live drift"):
            mod.install(self.home, self.bundle)

    def test_lock_refuses_concurrent_release(self):
        with mod.lock(self.home):
            with self.assertRaises(BlockingIOError):
                mod.install(self.home, self.bundle)
        self.assertEqual(self.target.read_bytes(), b"old")

    def test_failed_second_write_restores_first_file(self):
        name = "servernet_app/config/next.php"
        stage = self.bundle / "stage" / name
        stage.write_bytes(b"next")
        self.manifest["files"].append({"path": name, "before_sha256": "absent",
            "after_sha256": hashlib.sha256(b"next").hexdigest()})
        self.save()
        original = mod.replace
        def failing(target, data, mode):
            if target.name == "next.php":
                raise OSError("simulated write failure")
            original(target, data, mode)
        with patch.object(mod, "replace", failing):
            with self.assertRaisesRegex(OSError, "simulated"):
                mod.install(self.home, self.bundle)
        self.assertEqual(self.target.read_bytes(), b"old")
        self.assertFalse((self.home / name).exists())

if __name__ == "__main__":
    unittest.main()
