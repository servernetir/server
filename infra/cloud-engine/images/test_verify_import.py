import hashlib
from pathlib import Path
import tempfile
import unittest

from verify_import import import_plan, verify


class ImageVerificationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.artifact = Path(self.temp.name) / "fake-disk.qcow2"
        self.artifact.write_bytes(b"synthetic disk fixture; not a bootable OS")
        self.manifest = {"key": "debian-13", "family": "debian", "version": "13-test1", "architecture": "amd64", "source_url": "https://cloud.debian.org/images/cloud/trixie/20261101/debian-13-genericcloud-amd64.qcow2", "sha256": hashlib.sha256(self.artifact.read_bytes()).hexdigest(), "checksum_provenance": "synthetic test fixture only"}

    def test_hash_mismatch_rejects_artifact(self):
        self.artifact.write_bytes(b"tampered")
        with self.assertRaisesRegex(ValueError, "differs"):
            verify(self.manifest, self.artifact)

    def test_verified_plan_is_inert_and_not_saleable(self):
        result = import_plan(self.manifest, self.artifact, 900013, "local-zfs", "vmbr1")
        self.assertTrue(result["artifact_verified"])
        self.assertFalse(result["applied"])
        self.assertFalse(result["accepted_for_sales"])
        self.assertIn("firewall=1", result["commands"][0])
        self.assertTrue(any("exact returned disk" in step for step in result["remaining_steps"]))

    def test_mutable_url_and_missing_checksum_rejected(self):
        self.manifest["source_url"] = "https://cloud.debian.org/images/cloud/trixie/latest/disk.qcow2"
        with self.assertRaisesRegex(ValueError, "Moving"):
            verify(self.manifest, self.artifact)
        self.manifest["source_url"] = "https://example.com/pinned/disk.qcow2"
        self.manifest["sha256"] = "0" * 64
        with self.assertRaisesRegex(ValueError, "Placeholder"):
            verify(self.manifest, self.artifact)

    def test_windows_without_license_is_unavailable(self):
        self.manifest["family"] = "windows"
        with self.assertRaisesRegex(ValueError, "license"):
            verify(self.manifest, self.artifact)


if __name__ == "__main__":
    unittest.main()
