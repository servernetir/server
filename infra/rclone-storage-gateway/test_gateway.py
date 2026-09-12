import hashlib
import hmac
import json
import os
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request

import gateway
import storage


class GatewayTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        storage.DB_PATH = os.path.join(self.tmp.name, "tenants.sqlite3")
        os.environ.update({
            "SN_GATEWAY_HMAC_SECRET": "test-secret-that-is-longer-than-thirty-two-characters",
            "SN_CAPACITY_BYTES": str(2 * 1024**3),
            "SN_RESERVE_BYTES": str(512 * 1024**2),
            "SN_SFTP_PUBLIC_HOST": "backup.example.test",
            "SN_SFTP_PUBLIC_PORT": "2022",
        })
        storage.initialize()
        self.httpd = gateway.ThreadingHTTPServer(("127.0.0.1", 0), gateway.Api)
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()
        self.base = f"http://127.0.0.1:{self.httpd.server_port}"
        self.counter = 0

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.tmp.cleanup()

    def request(self, method, path, payload=None, nonce=None):
        body = b"" if payload is None else json.dumps(payload, separators=(",", ":")).encode()
        timestamp = str(int(time.time()))
        self.counter += 1
        nonce = nonce or f"{self.counter:024x}"
        canonical = b"\n".join([method.encode(), path.encode(), timestamp.encode(), nonce.encode(), body])
        signature = hmac.new(os.environ["SN_GATEWAY_HMAC_SECRET"].encode(), canonical, hashlib.sha256).hexdigest()
        req = urllib.request.Request(self.base + path, data=body if body else None, method=method, headers={
            "Content-Type": "application/json", "X-ServerNet-Timestamp": timestamp,
            "X-ServerNet-Nonce": nonce, "X-ServerNet-Signature": signature,
        })
        try:
            with urllib.request.urlopen(req, timeout=3) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            return error.code, json.load(error)

    def tenant_spec(self, quota=1024**3):
        return {
            "username": "sn12", "password": "Correct-Horse-Battery-Staple-42",
            "quota_bytes": quota, "pool": "google", "customer_ref": "44",
        }

    def test_hmac_replay_protection_and_capacity(self):
        status, _ = self.request("PUT", "/v1/tenants/sn-svc-12", self.tenant_spec(), nonce="a" * 24)
        self.assertEqual(201, status)
        status, data = self.request("PUT", "/v1/tenants/sn-svc-13", self.tenant_spec(), nonce="a" * 24)
        self.assertEqual(401, status)
        self.assertEqual("unauthorized", data["error"])

        second = self.tenant_spec(600 * 1024**2)
        second["username"] = "sn13"
        status, data = self.request("PUT", "/v1/tenants/sn-svc-13", second)
        self.assertEqual(409, status)
        self.assertEqual("insufficient_capacity", data["error"])

    def test_create_is_idempotent_and_lifecycle_never_purges_data(self):
        status, created = self.request("PUT", "/v1/tenants/sn-svc-12", self.tenant_spec())
        self.assertEqual(201, status)
        self.assertNotIn("password_hash", created["tenant"])
        status, reused = self.request("PUT", "/v1/tenants/sn-svc-12", self.tenant_spec())
        self.assertEqual(200, status)
        self.assertTrue(reused["reused"])
        changed = self.tenant_spec(256 * 1024**2)
        status, mismatch = self.request("PUT", "/v1/tenants/sn-svc-12", changed)
        self.assertEqual(409, status)
        self.assertEqual("tenant_spec_mismatch", mismatch["error"])

        status, suspended = self.request("POST", "/v1/tenants/sn-svc-12/suspend")
        self.assertEqual("suspended", suspended["tenant"]["status"])
        status, retired = self.request("DELETE", "/v1/tenants/sn-svc-12", {"retention_days": 30})
        self.assertEqual("retired", retired["tenant"]["status"])
        self.assertGreater(retired["tenant"]["retire_after"], int(time.time()))
        status, denied = self.request("POST", "/v1/tenants/sn-svc-12/unsuspend")
        self.assertEqual(409, status)
        self.assertEqual("tenant_retired", denied["error"])
        status, found = self.request("GET", "/v1/tenants/sn-svc-12")
        self.assertEqual(200, status)
        self.assertEqual("retired", found["tenant"]["status"])

    def test_password_rotation_replaces_the_old_hash(self):
        self.request("PUT", "/v1/tenants/sn-svc-12", self.tenant_spec())
        with storage.connection() as conn:
            before = bytes(conn.execute("SELECT password_hash FROM tenants WHERE id='sn-svc-12'").fetchone()[0])
        status, _ = self.request("POST", "/v1/tenants/sn-svc-12/credentials", {
            "password": "A-New-Password-Longer-Than-20-Chars"
        })
        self.assertEqual(200, status)
        with storage.connection() as conn:
            after = bytes(conn.execute("SELECT password_hash FROM tenants WHERE id='sn-svc-12'").fetchone()[0])
        self.assertNotEqual(before, after)


if __name__ == "__main__":
    unittest.main()
