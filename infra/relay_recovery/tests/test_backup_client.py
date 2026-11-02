"""Private local fixtures and fake SSH transports; no network or live changes."""
import base64
import copy
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import sqlite3
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import backup_client as backup
import relay_side as relay
canonical_json = backup.canonical_json
ProtocolError = backup.ProtocolError

NOW = 1800000000
NONCE = "1" * 32


def repack(blob, *, manifest_change=None, member_change=None, add=None, truncate_end=False):
    """Rewrite a fixture archive; deliberately does not use production validation."""
    output = io.BytesIO()
    with tarfile.open(fileobj=io.BytesIO(blob), mode="r:gz") as source, tarfile.open(fileobj=output, mode="w:gz", format=tarfile.PAX_FORMAT) as target:
        for member in source:
            data = source.extractfile(member).read() if member.isreg() else None
            if member.name == "MANIFEST.json" and manifest_change:
                manifest = json.loads(data)
                manifest_change(manifest)
                data = canonical_json(manifest)
                member.size = len(data)
            if member_change:
                result = member_change(member, data)
                if result is None: continue
                member, data = result
            target.addfile(member, io.BytesIO(data) if data is not None else None)
        if add:
            for member, data in add:
                target.addfile(member, io.BytesIO(data) if data is not None else None)
    result = output.getvalue()
    if truncate_end:
        return result[:-8]
    return result


class BackupClientTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = Path(self.tmp.name)
        os.chmod(self.base, 0o700)
        self.source = self.base / "source"; self.source.mkdir(mode=0o700)
        (self.source / "service.conf").write_text("private-fixture=true\n")
        self.key = self.base / "ssh-key"; self.key.write_text("never-an-actual-key"); self.key.chmod(0o600)
        keytype = b"ssh-ed25519"
        self.key_blob = len(keytype).to_bytes(4, "big") + keytype + (32).to_bytes(4, "big") + b"k" * 32
        self.known = self.base / "known-hosts"
        self.known.write_text("91.107.179.25 ssh-ed25519 " + base64.b64encode(self.key_blob).decode() + "\n")
        self.known.chmod(0o600)
        fingerprint = "SHA256:" + base64.b64encode(hashlib.sha256(self.key_blob).digest()).decode().rstrip("=")
        self.cfg = dict(schema=backup.SCHEMA, node_id="relay-fixture", generation=7, config_hash="e" * 64,
                        source_host="91.107.179.25", provider_id="12345", ssh_key_file=str(self.key),
                        known_hosts_file=str(self.known), expected_host_key_sha256=fingerprint,
                        state_dir=str(self.base / "backups"), expected_groups=["gateway"])
        self.side_cfg = dict(schema=relay.SCHEMA, node_id="relay-fixture", generation=7, config_hash="e" * 64,
                             work_dir=str(self.base / "source-recovery"),
                             groups={"gateway": {"consistency": "stateless", "paths": [str(self.source)]}})
        self.calls = []
        self.code = 0
        self.blob = None

    def tearDown(self):
        self.tmp.cleanup()

    def fixture(self):
        with patch.object(relay.time, "time", return_value=NOW):
            side = relay.RelaySide(copy.deepcopy(self.side_cfg), root_owned=False)
            with side.export_artifact(NONCE) as (path, _):
                return path.read_bytes()

    def transport(self, argv, request, output, max_bytes, timeout):
        decoded = json.loads(request)
        self.calls.append((argv, decoded, max_bytes, timeout))
        if decoded["op"] == "inspect":
            with patch.object(relay.time, "time", return_value=NOW):
                result = relay.RelaySide(copy.deepcopy(self.side_cfg), root_owned=False).inspect(decoded["request_id"])
            output.write(canonical_json(result))
        else:
            output.write(self.blob if self.blob is not None else self.fixture())
        return self.code

    def client(self):
        return backup.BackupClient(copy.deepcopy(self.cfg), transport=self.transport, clock=lambda: NOW,
                                   nonce=lambda: NONCE, allow_test_paths=True)

    def assert_rejected(self, blob, pattern=None):
        self.blob = blob
        with self.assertRaisesRegex(ProtocolError, pattern or ".*"):
            self.client().backup()
        state = Path(self.cfg["state_dir"])
        self.assertEqual([p for p in state.iterdir() if p.name != ".backup.lock"], [])

    def test_backup_publishes_private_atomic_artifact_and_metadata_without_extracting(self):
        result = self.client().backup()
        self.assertTrue(result["artifact_verified"])
        self.assertFalse(result["ready"])
        self.assertFalse(result["restore_current"])
        self.assertFalse(result["persistent_fence"])
        self.assertTrue(result["docker_writable_layer_verified"])  # Fixture has no containers.
        state = Path(self.cfg["state_dir"])
        artifact = state / result["artifact_id"]
        self.assertEqual(set(p.name for p in artifact.iterdir()), {"snapshot.tar.gz", "metadata.json"})
        self.assertEqual(json.loads((artifact / "metadata.json").read_bytes()), result)
        self.assertEqual(json.loads((state / "latest.json").read_bytes()), result)
        self.assertEqual(hashlib.sha256((artifact / "snapshot.tar.gz").read_bytes()).hexdigest(), result["artifact_sha256"])
        for path in (state, artifact): self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o700)
        for path in artifact.iterdir(): self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertNotIn("never-an-actual-key", json.dumps(result))
        self.assertNotIn("private-fixture=true", json.dumps(result))

    def test_real_local_sqlite_snapshot_is_recorded_per_database_only(self):
        database = self.source / "state.sqlite3"
        with sqlite3.connect(database) as conn:
            conn.execute("CREATE TABLE t (v)")
            conn.execute("INSERT INTO t VALUES (42)")
        self.side_cfg["groups"]["gateway"]["consistency"] = "sqlite"
        self.side_cfg["sqlite_databases"] = [{"group": "gateway", "path": str(database)}]
        result = self.client().backup()
        self.assertTrue(result["per_database_ready"])
        self.assertEqual(result["sqlite_count"], 1)
        self.assertFalse(result["restore_current"])

    def test_ssh_options_pin_one_key_and_never_enable_shell_forwarding_or_accept_new(self):
        self.client().backup()
        args, request, limit, timeout = self.calls[0]
        self.assertEqual(args[:4], ["/usr/bin/ssh", "-F", "/dev/null", "-T"])
        self.assertIn("-oStrictHostKeyChecking=yes", args)
        self.assertIn("-oUserKnownHostsFile=" + str(self.known), args)
        self.assertIn("-oGlobalKnownHostsFile=/dev/null", args)
        self.assertIn("-oIdentitiesOnly=yes", args)
        self.assertIn("-oClearAllForwardings=yes", args)
        self.assertEqual(args[-2:], ["root@91.107.179.25", backup.REMOTE_MARKER])
        self.assertNotIn("accept-new", " ".join(args))
        self.assertEqual(request, {"op": "export", "request_id": NONCE, "generation": 7, "config_hash": "e" * 64})
        self.assertLessEqual(limit, 512 << 20)
        self.assertLessEqual(timeout, 180)

    def test_inspect_proves_independent_control_without_runtime_or_restore_claim(self):
        result = self.client().inspect()
        self.assertTrue(result["independent_control_reachable"])
        self.assertTrue(result["inventory_safe"])
        self.assertFalse(result["application_health_proven"])
        self.assertFalse(result["restore_current"])
        self.assertEqual(self.calls[0][1]["op"], "inspect")

    def test_inspect_reports_unsafe_inventory_without_faking_unreachable_control(self):
        self.side_cfg["groups"]["gateway"].pop("consistency")
        result = self.client().inspect()
        self.assertTrue(result["independent_control_reachable"])
        self.assertFalse(result["inventory_safe"])
        self.assertIn("consistency_unverified", result["issue_codes"])

    def test_actual_inspect_exit2_still_proves_independent_control_with_unsafe_inventory(self):
        self.code = 2
        self.side_cfg["groups"]["gateway"].pop("consistency")
        result = self.client().inspect()
        self.assertTrue(result["independent_control_reachable"])
        self.assertFalse(result["inventory_safe"])
        self.assertFalse(result["configured_inventory_complete"])
        self.assertFalse(result["ready"])
        self.assertIn("consistency_unverified", result["issue_codes"])

    def test_inspect_exit2_requires_valid_report_and_never_relaxes_export_status(self):
        self.code = 2
        with self.assertRaisesRegex(ProtocolError, "inspect_exit_mismatch"):
            self.client().inspect()  # Safe fixture falsely contradicts exit2.
        state = Path(self.cfg["state_dir"])
        self.assertFalse((state / "inspect-latest.json").exists())
        with self.assertRaisesRegex(ProtocolError, "ssh_exit_failed"):
            self.client().backup()
        self.assertFalse((state / "latest.json").exists())

    def test_other_inspect_exit_codes_are_never_reachability_evidence(self):
        for code in (1, 3, 255):
            self.code = code
            with self.subTest(code=code), self.assertRaisesRegex(ProtocolError, "ssh_exit_failed"):
                self.client().inspect()

    def test_wrong_host_pin_and_multiple_host_keys_are_rejected_before_transport(self):
        self.cfg["expected_host_key_sha256"] = "SHA256:wrong"
        with self.assertRaisesRegex(ProtocolError, "host_key_pin"): self.client()
        self.assertEqual(self.calls, [])
        self.known.write_text(self.known.read_text() * 2)
        with self.assertRaisesRegex(ProtocolError, "host_key_count"): self.client()

    def test_nondefault_ssh_port_requires_exact_bracketed_knownhosts_entry(self):
        self.cfg["ssh_port"] = 2222
        with self.assertRaisesRegex(ProtocolError, "host_key_entry"): self.client()
        self.known.write_text(self.known.read_text().replace("91.107.179.25 ", "[91.107.179.25]:2222 "))
        self.client().backup()
        self.assertIn("2222", self.calls[0][0])

    def test_private_key_permissions_and_symlink_are_rejected(self):
        self.key.chmod(0o644)
        with self.assertRaisesRegex(ProtocolError, "private_file"): self.client()
        self.key.chmod(0o600)
        alias = self.base / "key-alias"; alias.symlink_to(self.key)
        self.cfg["ssh_key_file"] = str(alias)
        with self.assertRaisesRegex(ProtocolError, "private_file"): self.client()

    def test_production_rejects_world_writable_ancestor_and_wrong_uid(self):
        with self.assertRaisesRegex(ProtocolError, "private_parent_mode"):
            backup.BackupClient(copy.deepcopy(self.cfg))
        with self.assertRaisesRegex(ProtocolError, "private_file"):
            backup.trusted_file(str(self.key), uid=os.geteuid() + 1, allow_test_paths=True)

    def test_limits_source_hostname_private_ip_user_and_provider_are_static(self):
        for field, value in (("max_artifact_bytes", (512 << 20) + 1), ("timeout_seconds", 181),
                             ("source_host", "relay.example"), ("source_host", "127.0.0.1"),
                             ("provider_id", "0"), ("ssh_user", "root;id"), ("generation", True)):
            with self.subTest(field=field, value=value):
                cfg = copy.deepcopy(self.cfg); cfg[field] = value
                with self.assertRaises(ProtocolError):
                    backup.BackupClient(cfg, allow_test_paths=True)

    def test_nonzero_ssh_exit_never_publishes_even_valid_archive(self):
        self.code = 255
        self.assert_rejected(self.fixture(), "ssh_exit_failed")

    def test_artifact_limit_is_checked_even_for_injected_transport(self):
        self.cfg["max_artifact_bytes"] = 1
        self.assert_rejected(self.fixture(), "artifact_size_limit")

    def test_identity_nonce_generation_hash_node_and_time_are_bound(self):
        fixture = self.fixture()
        for field, value in (("request_id", "2" * 32), ("generation", 6), ("generation", True),
                             ("config_hash", "f" * 64), ("node_id", "other-node"), ("observed_at", NOW - 301)):
            with self.subTest(field=field, value=value):
                self.assert_rejected(repack(fixture, manifest_change=lambda m, f=field, v=value: m.update({f: v})))

    def test_gzip_crc_truncation_and_trailing_non_gzip_bytes_are_rejected(self):
        fixture = self.fixture()
        bad_crc = bytearray(fixture); bad_crc[-8] ^= 1
        for blob in (fixture[:-8], bytes(bad_crc), fixture + b"unexpected"):
            with self.subTest(size=len(blob)): self.assert_rejected(blob)

    def test_manifest_must_be_first_and_plain_regular(self):
        fixture = self.fixture()
        def rename(member, data):
            if member.name == "MANIFEST.json": member.name = "elsewhere.json"
            return member, data
        self.assert_rejected(repack(fixture, member_change=rename), "manifest_first")

    def test_file_hash_or_size_corruption_is_rejected(self):
        fixture = self.fixture()
        def mutate(member, data):
            if member.name != "MANIFEST.json" and member.isreg():
                data = b"X" + data[1:]
            return member, data
        self.assert_rejected(repack(fixture, member_change=mutate), "archive_member_hash")
        def resize(member, data):
            if member.name != "MANIFEST.json" and member.isreg():
                data += b"x"; member.size += 1
            return member, data
        self.assert_rejected(repack(fixture, member_change=resize), "archive_member_size")

    def test_missing_unlisted_duplicate_and_unsafe_paths_are_rejected(self):
        fixture = self.fixture()
        def remove(member, data):
            return None if member.isreg() and member.name != "MANIFEST.json" else (member, data)
        self.assert_rejected(repack(fixture, member_change=remove), "archive_missing_member")
        for name in ("extra", "MANIFEST.json", "../escape", "/absolute", "data/./hidden", "data\\escape"):
            with self.subTest(name=name):
                entry = tarfile.TarInfo(name); entry.size = 1; entry.mode = 0o600
                self.assert_rejected(repack(fixture, add=[(entry, b"x")]))

    def test_tar_symlink_hardlink_fifo_device_and_sparse_are_rejected(self):
        fixture = self.fixture()
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.FIFOTYPE, tarfile.CHRTYPE, tarfile.BLKTYPE):
            with self.subTest(kind=kind):
                member = tarfile.TarInfo("unexpected"); member.type = kind; member.linkname = "/etc/shadow"
                self.assert_rejected(repack(fixture, add=[(member, None)]), "archive_member_type")

    def test_archive_trailing_nonzero_plaintext_is_rejected(self):
        plain = gzip.decompress(self.fixture())
        self.assert_rejected(gzip.compress(plain + b"not-tar"), "archive_end")

    def test_manifest_member_registry_hash_and_complete_groups_are_required(self):
        fixture = self.fixture()
        for change in (lambda m: m.update(registry_hash="0" * 64), lambda m: m.update(groups={}),
                       lambda m: m.update(global_consistency_proven=True), lambda m: m.update(total_uncompressed_bytes=0)):
            self.assert_rejected(repack(fixture, manifest_change=change))

    def test_existing_completed_artifact_is_never_overwritten(self):
        client = self.client()
        first = client.backup()
        artifact = Path(self.cfg["state_dir"]) / first["artifact_id"] / "snapshot.tar.gz"
        original = artifact.read_bytes()
        with self.assertRaisesRegex(ProtocolError, "artifact_exists"): client.backup()
        self.assertEqual(artifact.read_bytes(), original)

    def test_failed_transfer_does_not_remove_previous_verified_backup(self):
        client = self.client(); result = client.backup()
        self.code = 255
        with self.assertRaisesRegex(ProtocolError, "ssh_exit_failed"): client.backup()
        state = Path(self.cfg["state_dir"])
        self.assertTrue((state / result["artifact_id"]).exists())
        self.assertEqual(json.loads((state / "latest.json").read_bytes()), result)
        self.assertFalse(any(p.name.startswith(".pending-") for p in state.iterdir()))

    def test_transport_timeout_cleanup(self):
        client = self.client()
        def timeout(*args): raise ProtocolError("ssh_timeout")
        client.transport = timeout
        with self.assertRaisesRegex(ProtocolError, "ssh_timeout"): client.backup()
        self.assertEqual([p for p in Path(self.cfg["state_dir"]).iterdir() if p.name != ".backup.lock"], [])

    def test_storage_count_budget_holds_without_removing_latest(self):
        self.cfg["max_backup_count"] = 1
        client = self.client(); first = client.backup()
        before = len(self.calls)
        with self.assertRaisesRegex(ProtocolError, "backup_count_budget_hold"): client.backup()
        self.assertEqual(len(self.calls), before)
        self.assertEqual(json.loads((Path(self.cfg["state_dir"]) / "latest.json").read_bytes()), first)

    def test_free_space_and_storage_budget_hold_before_network(self):
        client = self.client(); client.disk_free = lambda path: 0
        with self.assertRaisesRegex(ProtocolError, "backup_free_space_hold"): client.backup()
        self.assertEqual(self.calls, [])
        self.cfg["max_backup_bytes"] = 1000
        with self.assertRaisesRegex(ProtocolError, "backup_storage_budget_hold"): self.client().backup()
        self.assertEqual(self.calls, [])

    def test_temporary_validation_bytes_are_included_in_storage_budget(self):
        self.cfg.update(max_artifact_bytes=2000, max_backup_bytes=5000)
        self.assert_rejected(self.fixture(), "backup_storage_budget_hold")

    def test_unknown_private_state_is_never_removed_for_budget(self):
        self.cfg.update(max_artifact_bytes=1000, max_backup_bytes=1000)
        client = self.client(); unknown = client.state / "unverified-user-data"
        unknown.write_bytes(b"private"); unknown.chmod(0o600)
        with self.assertRaisesRegex(ProtocolError, "backup_storage_budget_hold"): client.backup()
        self.assertEqual(unknown.read_bytes(), b"private")
        self.assertEqual(self.calls, [])

    def test_concurrent_backup_is_a_bounded_hold(self):
        client = self.client()
        with client._lock():
            with self.assertRaisesRegex(ProtocolError, "backup_in_progress_hold"): client.backup()
        self.assertEqual(self.calls, [])

    def test_huge_pax_header_rejected_before_tarfile_parser_or_body_read(self):
        first = tarfile.TarInfo("MANIFEST.json"); first.size = 2
        extension = tarfile.TarInfo("././@PaxHeader"); extension.type = tarfile.XHDTYPE; extension.size = 1 << 30
        plain = first.tobuf(tarfile.USTAR_FORMAT) + b"{}" + b"\0" * 510 + extension.tobuf(tarfile.USTAR_FORMAT) + b"\0" * 1024
        self.blob = gzip.compress(plain)
        with patch.object(backup.tarfile, "open", side_effect=AssertionError("parser must not run")):
            with self.assertRaisesRegex(ProtocolError, "archive_pax_size"): self.client().backup()

    def test_sparse_pax_and_global_headers_are_rejected_before_parser(self):
        for kind in (tarfile.XGLTYPE, tarfile.GNUTYPE_SPARSE, tarfile.GNUTYPE_LONGNAME):
            first = tarfile.TarInfo("MANIFEST.json"); first.size = 2
            extra = tarfile.TarInfo("unsupported"); extra.type = kind
            plain = first.tobuf(tarfile.USTAR_FORMAT) + b"{}" + b"\0" * 510 + extra.tobuf(tarfile.USTAR_FORMAT) + b"\0" * 1024
            self.blob = gzip.compress(plain)
            with self.subTest(kind=kind), self.assertRaisesRegex(ProtocolError, "archive_member_type"):
                self.client().backup()

    def test_bounded_producer_pax_long_path_remains_supported(self):
        folder = self.source / ("long-" + "a" * 85); folder.mkdir()
        (folder / ("file-" + "b" * 85 + ".conf")).write_text("long fixture")
        self.assertTrue(self.client().backup()["artifact_verified"])


class StreamTests(unittest.TestCase):
    def test_real_local_pipe_stream_is_bounded_and_uses_no_shell(self):
        real_popen = subprocess.Popen
        calls = []
        def spawn(argv, **kwargs):
            calls.append((argv, kwargs))
            return real_popen([sys.executable, "-c", "import sys;sys.stdin.buffer.read();sys.stdout.buffer.write(b'x'*100)"] , **kwargs)
        with patch.object(backup.subprocess, "Popen", side_effect=spawn), tempfile.TemporaryFile() as out:
            self.assertEqual(backup.ssh_stream(["/usr/bin/ssh", "fixed"], b"{}", out, 100, 5), 0)
            out.seek(0); self.assertEqual(out.read(), b"x" * 100)
        self.assertNotIn("shell", calls[0][1])
        self.assertEqual(calls[0][1]["env"], backup.SAFE_ENV)

    def test_local_pipe_output_limit_and_timeout_kill_child(self):
        real_popen = subprocess.Popen
        for program, limit, seconds, code in (("import sys;sys.stdout.buffer.write(b'x'*1000)", 10, 5, "artifact_size_limit"),
                                               ("import time;time.sleep(10)", 100, 0.02, "ssh_timeout")):
            def spawn(argv, **kwargs): return real_popen([sys.executable, "-c", program], **kwargs)
            with self.subTest(code=code), patch.object(backup.subprocess, "Popen", side_effect=spawn), tempfile.TemporaryFile() as out:
                with self.assertRaisesRegex(ProtocolError, code):
                    backup.ssh_stream(["fixed"], b"{}", out, limit, seconds)


if __name__ == "__main__":
    unittest.main()
