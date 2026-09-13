import os
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

import auth_proxy
import storage


class AuthProxyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        storage.DB_PATH = os.path.join(self.tmp.name, "tenants.sqlite3")
        os.environ.update({
            "SN_ACCESS_SECRET_SEED": "test-seed-that-is-definitely-longer-than-thirty-two-characters",
            "SN_RCLONE_REMOTE": "poolcrypt",
            "SN_CAPACITY_BYTES": str(10 * 1024**3),
            "SN_RESERVE_BYTES": "0",
        })
        storage.initialize()
        with storage.connection() as conn:
            conn.execute("""
                INSERT INTO tenants
                (id, username, quota_bytes, used_bytes, pool, customer_ref,
                 s3_access_key, credential_version, status, created_at, updated_at)
                VALUES ('sn-svc-12', 'sn12', ?, 0, 'google', '44', ?, 1, 'active', 1, 1)
            """, (1024**3, storage.make_s3_access_key("sn-svc-12")))
            self.row = conn.execute("SELECT * FROM tenants WHERE id='sn-svc-12'").fetchone()
            self.secret = storage.access_secret(self.row)
            self.access_key = self.row["s3_access_key"]

    def tearDown(self):
        self.tmp.cleanup()

    @patch("auth_proxy.subprocess.run")
    def test_password_protocols_are_chrooted_inside_backup_bucket(self, run):
        run.return_value = SimpleNamespace(stdout='{"type":"local"}')
        result = auth_proxy.backend_for({"user": "sn12", "pass": self.secret, "client_ip": "127.0.0.1"})
        self.assertEqual("tenants/sn-svc-12/backup", result["_root"])
        self.assertNotIn("_secret_access_key", result)

    @patch("auth_proxy.subprocess.run")
    def test_s3_is_chrooted_one_level_higher_and_returns_signing_secret(self, run):
        run.return_value = SimpleNamespace(stdout='{"type":"local"}')
        result = auth_proxy.backend_for({"user": self.access_key, "client_ip": "127.0.0.1"})
        self.assertEqual("tenants/sn-svc-12", result["_root"])
        self.assertEqual(self.secret, result["_secret_access_key"])

    def test_wrong_password_and_public_key_are_denied(self):
        with self.assertRaises(PermissionError):
            auth_proxy.backend_for({"user": "sn12", "pass": "wrong"})
        with self.assertRaises(PermissionError):
            auth_proxy.backend_for({"user": "sn12", "public_key": "not-enabled"})


if __name__ == "__main__":
    unittest.main()
