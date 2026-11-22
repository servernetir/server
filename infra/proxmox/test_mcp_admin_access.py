import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("access", Path(__file__).with_name("mcp_admin_access.py"))
access = importlib.util.module_from_spec(spec)
spec.loader.exec_module(access)


class AccessTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.blob = base64.b64encode(b"test-public-key-blob").decode()
        self.fp = "SHA256:" + base64.b64encode(hashlib.sha256(b"test-public-key-blob").digest()).decode().rstrip("=")
        self.line = f'restrict,from="192.0.2.1" ssh-ed25519 {self.blob} old-comment'

    def test_key_requires_exact_fingerprint_and_source_restrictions(self):
        result = access.key_line((self.line + "\n").encode(), self.fp, "192.0.2.1")
        self.assertEqual(result, self.line.replace("old-comment", "servernet-mcp-admin"))
        for source, fp, ip in ((self.line, "SHA256:other", "192.0.2.1"),
                               (self.line, self.fp, "192.0.2.2"),
                               (self.line.replace("restrict,", ""), self.fp, "192.0.2.1"),
                               (self.line + "\n" + self.line, self.fp, "192.0.2.1")):
            with self.assertRaises(ValueError):
                access.key_line(source.encode(), fp, ip)

    def test_grant_preserves_existing_key_and_is_idempotent(self):
        line = access.key_line(self.line.encode(), self.fp, "192.0.2.1")
        before = b"ssh-ed25519 unrelated owner"
        after = access.grant_bytes(before, line)
        self.assertTrue(after.startswith(before + b"\n"))
        self.assertEqual(after, access.grant_bytes(after, line))
        with self.assertRaises(ValueError):
            access.grant_bytes((line.replace("restrict,", "") + "\n").encode(), line)

    def test_proxmox_cluster_link_is_preserved_using_configured_local_file(self):
        ssh = access.private_dir(self.root / ".ssh")
        (ssh / "authorized_keys").symlink_to("/etc/pve/priv/authorized_keys")
        policy = {"pubkeyauthentication": "yes", "permitrootlogin": "without-password",
                  "authenticationmethods": "publickey",
                  "authorizedkeysfile": ".ssh/authorized_keys .ssh/authorized_keys2"}
        self.assertEqual(access.enrollment_target(self.root, policy), ssh / "authorized_keys2")
        self.assertTrue((ssh / "authorized_keys").is_symlink())
        policy["authorizedkeysfile"] = ".ssh/authorized_keys"
        with self.assertRaises(ValueError):
            access.enrollment_target(self.root, policy)

    def test_root_login_policy_is_never_silently_weakened(self):
        policy = {"pubkeyauthentication": "yes", "permitrootlogin": "no",
                  "authorizedkeysfile": ".ssh/authorized_keys"}
        with self.assertRaises(ValueError):
            access.enrollment_target(self.root, policy)
        policy.update(permitrootlogin="without-password", authenticationmethods="publickey,password")
        with self.assertRaises(ValueError):
            access.enrollment_target(self.root, policy)

    def test_file_receipt_rollback_preserves_original_bytes(self):
        target = self.root / "keys"
        access.replace(target, b"original\n", None)
        result = access.commit(target, b"original\n", b"new\n", self.root / "state")
        self.assertEqual(target.stat().st_mode & 0o777, 0o600)
        access.rollback(result["receipt"])
        self.assertEqual(target.read_bytes(), b"original\n")

    def test_rollback_refuses_newer_edits_and_corrupt_backup(self):
        target = self.root / "keys"
        result = access.commit(target, None, b"new\n", self.root / "state")
        target.write_bytes(b"another-admin\n")
        with self.assertRaises(ValueError):
            access.rollback(result["receipt"])
        self.assertEqual(target.read_bytes(), b"another-admin\n")
        target.write_bytes(b"new\n")
        (Path(result["receipt"]).parent / "before").write_bytes(b"wrong")
        with self.assertRaises(ValueError):
            access.rollback(result["receipt"])

    def test_symlink_and_changed_destination_are_rejected(self):
        target = self.root / "keys"
        access.replace(target, b"before", None)
        alias = self.root / "alias"
        alias.symlink_to(target)
        with self.assertRaises(ValueError):
            access.read(alias)
        with self.assertRaises(ValueError):
            access.replace(target, b"new", b"stale")
        self.assertEqual(target.read_bytes(), b"before")

    def registry(self):
        path = self.root / "registry" / "targets.json"
        access.private_dir(path.parent)
        data = {"proxmox-ir": {"user": "mcp-ops", "host": "192.0.2.2", "port": 2222},
                "servernet-app-de": {"user": "app", "other": ["preserve"]}}
        access.replace(path, json.dumps(data).encode(), None)
        return path, data

    def test_failed_root_proof_does_not_promote_or_create_backup(self):
        path, _ = self.registry()
        before = path.read_bytes()
        def fail(*args):
            raise ValueError("no_root")
        with self.assertRaises(ValueError):
            access.gateway_promote(path, verify=fail)
        self.assertEqual(path.read_bytes(), before)
        self.assertFalse((path.parent / "admin-access-receipts").exists())

    def test_promotion_requires_proof_preserves_other_targets_and_rolls_back(self):
        path, data = self.registry()
        calls = []
        result = access.gateway_promote(path, verify=lambda *args: calls.append(args))
        self.assertEqual(len(calls), 1)
        promoted = json.loads(path.read_bytes())
        self.assertEqual(promoted["proxmox-ir"]["user"], "root")
        self.assertEqual(promoted["servernet-app-de"], data["servernet-app-de"])
        access.rollback(result["receipt"])
        self.assertEqual(json.loads(path.read_bytes()), data)

    def test_promotion_refuses_concurrent_change_after_root_proof(self):
        path, _ = self.registry()
        def interfere(*args):
            path.write_bytes(b'{"newer":"configuration"}')
        with self.assertRaises(ValueError):
            access.gateway_promote(path, verify=interfere)
        self.assertEqual(path.read_bytes(), b'{"newer":"configuration"}')

    def test_root_proof_keeps_host_pinning_and_rejects_failed_qm(self):
        directory = self.root / "registry"
        access.private_dir(directory)
        key, known = directory / "key", directory / "known"
        access.replace(key, b"fake-key-not-a-credential", None)
        access.replace(known, b"fake-known-host", None)
        target = {"host": "192.0.2.2", "port": 2222,
                  "identity_file": str(key), "known_hosts_file": str(known)}
        with patch.object(access.subprocess, "run") as run:
            run.return_value.returncode = 0
            run.return_value.stdout = b"0\nstatus: running\n"
            access.verify_root(target, directory)
            command = run.call_args.args[0]
            self.assertIn("StrictHostKeyChecking=yes", command)
            self.assertIn("IdentitiesOnly=yes", command)
            self.assertIn("PasswordAuthentication=no", command)
            self.assertEqual(command[-1], "id -u && /usr/sbin/qm status 127")
            run.return_value.stdout = b"0\n"
            with self.assertRaises(ValueError):
                access.verify_root(target, directory)


if __name__ == "__main__":
    unittest.main()
