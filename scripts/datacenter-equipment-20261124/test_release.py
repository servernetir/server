import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
spec = importlib.util.spec_from_file_location("equipment_release", HERE / "release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class EquipmentReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.app = self.home / "servernet_app"
        (self.app / "bootstrap/cache").mkdir(parents=True)
        self.stage = self.home / "stage"
        self.stage.mkdir()
        self.patch = patch.multiple(release, HOME=self.home, APP=self.app)
        self.patch.start()
        self.addCleanup(self.patch.stop)
        self.original = json.loads((HERE / "release-manifest.json").read_text())["files"]
        self.rows = []
        for i, row in enumerate(self.original):
            p = self.home / row["destination"]
            p.parent.mkdir(parents=True, exist_ok=True)
            previous = ("fixture-before-" + str(i)).encode()
            if i % 3:
                p.write_bytes(previous)
                before = release.sha(previous)
            else:
                before = None
            self.rows.append(dict(row, before=[before]))
        self.payload = lambda _, source: (ROOT / source).read_bytes()

    def test_manifest_checks_every_real_source_and_dual_asset_root(self):
        self.assertEqual(39, len(self.original))
        for row in self.original:
            self.assertEqual(row["after"], hashlib.sha256((ROOT / row["source"]).read_bytes()).hexdigest())
            release.target(row)
        for asset in ("css/datacenter-equipment.css", "js/datacenter-planner.js"):
            self.assertEqual(2, sum(r["source"].endswith(asset) for r in self.original))

    def test_drift_stops_before_application_or_data_writes(self):
        (self.home / self.rows[-1]["destination"]).write_bytes(b"unexpected")
        with patch.object(release, "fetch", side_effect=self.payload), patch.object(release, "php_import") as data:
            with self.assertRaisesRegex(AssertionError, "Unexpected live bytes"):
                release.apply("a"*40, self.stage, self.rows)
            data.assert_not_called()
        self.assertFalse((self.stage/"prepared.json").exists())
        self.assertFalse((self.stage/"receipt.json").exists())

    def test_preflight_duplicate_refuses_before_install(self):
        with patch.object(release,"fetch",side_effect=self.payload), patch.object(release,"preflight_catalogue",side_effect=AssertionError("duplicate")), patch.object(release,"php_import") as data:
            with self.assertRaisesRegex(AssertionError,"duplicate"):
                release.apply("a"*40,self.stage,self.rows)
            data.assert_not_called()
        self.assertFalse((self.stage/"prepared.json").exists())
        self.assertTrue(all(release.current(row) in row["before"] for row in self.rows))

    def test_install_has_exact_scope_and_rollback_recovers_absent_and_existing_bytes(self):
        responses=[{"insert":79,"enrich":0,"applied":False},{"insert":79,"applied":True}]
        with patch.object(release,"fetch",side_effect=self.payload), patch.object(release,"preflight_catalogue"), patch.object(release,"php_import",side_effect=responses), patch.object(release,"refresh_pages"), patch.object(release.subprocess,"run"):
            result=release.apply("a"*40,self.stage,self.rows)
        self.assertEqual({"status":"installed","files":39,"inserted":79},result)
        self.assertTrue(all(release.current(row)==row["after"] for row in self.rows))
        saved=json.loads((self.stage/"prepared.json").read_text())["files"]
        record=json.loads((self.stage/"receipt.json").read_text())
        release.restore(saved,self.stage,record["changed"])
        self.assertTrue(all(release.current(row)==row["original"] for row in saved))

    def test_scope_rejects_unrelated_target(self):
        with self.assertRaises(AssertionError):
            release.target({"source":"website/config/hardware_topics.php","destination":"servernet_app/.env"})


if __name__ == "__main__":
    unittest.main()
