from datetime import datetime, timedelta, timezone
import unittest

from windows_marker_contract import verify_marker


class WindowsMarkerContractTest(unittest.TestCase):
    def setUp(self):
        self.now = datetime(2026, 11, 3, 8, 0, tzinfo=timezone.utc)
        self.marker = {"schema_version": 1, "image_key": "windows-2022-test1", "os_build": "20348",
                       "hostname": "sn-svc-17", "initialized": True, "credential_setup": True,
                       "written_at": self.now.isoformat()}

    def check(self, **overrides):
        return verify_marker(self.marker | overrides, "windows-2022-test1", "sn-svc-17", True, self.now)

    def test_current_marker_requires_exact_image_build_and_vm_identity(self):
        self.assertTrue(self.check())
        for mismatch in ({"image_key": "windows-2019"}, {"os_build": "17763"}, {"hostname": "another-vm"}):
            with self.assertRaises(ValueError): self.check(**mismatch)

    def test_truthy_strings_and_missing_license_are_not_acceptance(self):
        with self.assertRaises(ValueError): self.check(credential_setup="true")
        with self.assertRaises(ValueError): self.check(initialized=False)
        with self.assertRaises(ValueError): verify_marker(self.marker, "windows-2022-test1", "sn-svc-17", False, self.now)
        with self.assertRaises(ValueError): verify_marker(self.marker, "windows-2022-test1", "sn-svc-17", "true", self.now)
        with self.assertRaises(ValueError): self.check(schema_version=1.0)

    def test_stale_template_marker_cannot_establish_new_guest_readiness(self):
        with self.assertRaises(ValueError): self.check(written_at=(self.now - timedelta(days=1)).isoformat())
        with self.assertRaises(ValueError): self.check(written_at=(self.now + timedelta(minutes=1)).isoformat())
        with self.assertRaises(ValueError): self.check(written_at="invalid")
        with self.assertRaises(ValueError): self.check(written_at=123)
        with self.assertRaises(ValueError):
            verify_marker(self.marker, "windows-2022-test1", "sn-svc-17", True, self.now, self.now + timedelta(seconds=1))

    def test_marker_never_returns_initial_credentials(self):
        with self.assertRaises(ValueError): self.check(password="fixture-only-password")


if __name__ == "__main__":
    unittest.main()
