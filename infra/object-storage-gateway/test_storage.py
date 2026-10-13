import os
import tempfile
import unittest


class StorageTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        os.environ["SN_OBJECT_DB"] = self.tmp.name + "/tenants.sqlite3"
        os.environ["SN_OBJECT_CREDENTIAL_SEED"] = "a" * 64
        import importlib
        import storage
        self.storage = importlib.reload(storage)
        self.storage.initialize()

    def tearDown(self):
        self.tmp.cleanup()

    def test_credentials_are_stable_per_version_and_never_equal_the_tenant_id(self):
        now = 1
        with self.storage.connection() as conn:
            conn.execute("""INSERT INTO tenants
              (id,region,bucket,access_key,quota_bytes,customer_ref,status,created_at,updated_at)
              VALUES('sn-svc-7','fsn1','sn-fsn1-7','SNACCESS',1024,'3','active',?,?)""", (now, now))
            row = conn.execute("SELECT * FROM tenants").fetchone()
            first = self.storage.secret_for(row)
            conn.execute("UPDATE tenants SET credential_version=2")
            second = self.storage.secret_for(conn.execute("SELECT * FROM tenants").fetchone())
        self.assertNotEqual(first, second)
        self.assertNotIn("sn-svc-7", first)

    def test_nonce_is_one_time(self):
        self.assertTrue(self.storage.reserve_nonce("0" * 24, 1000))
        self.assertFalse(self.storage.reserve_nonce("0" * 24, 1000))


if __name__ == "__main__":
    unittest.main()
