"""Local fixtures only: no real SSH, service operation, Docker or provider calls."""
import copy
import hashlib
import io
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import relay_side as relay
# test_protocol loads a fresh standalone protocol fixture during discovery;
# assert against the class actually used by this already-imported helper.
ProtocolError = relay.ProtocolError
canonical_json = relay.canonical_json


class FakeRunner:
    def __init__(self):
        self.calls = []
        self.stopped = False
        self.alias = None
        self.docker = None
        self.docker_diff = b""
        self.timeout = False

    def __call__(self, args, **kwargs):
        self.calls.append(args)
        if self.timeout:
            raise subprocess.TimeoutExpired(args, 8)
        if args[:3] == ["/usr/bin/systemctl", "show", args[2]]:
            name = args[2]
            state = "inactive" if self.stopped else "active"
            body = f"Id={name}\nNames={self.alias or name}\nLoadState=loaded\nActiveState={state}\nSubState=running\nMainPID=123\nFragmentPath=/etc/systemd/system/{name}\n".encode()
            return subprocess.CompletedProcess(args, 0, body)
        if args[:2] == ["/usr/bin/systemctl", "stop"]:
            self.stopped = True
            return subprocess.CompletedProcess(args, 0)
        if args[:3] == ["/usr/bin/docker", "container", "inspect"]:
            return subprocess.CompletedProcess(args, 0, json.dumps([self.docker]).encode())
        if args[:2] == ["/usr/bin/docker", "diff"]:
            body = self.docker_diff.pop(0) if isinstance(self.docker_diff, list) else self.docker_diff
            return subprocess.CompletedProcess(args, 0, body)
        raise AssertionError(f"Unexpected fixed command: {args!r}")


class RelaySideTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = Path(self.tmp.name)
        self.source = self.base / "source"
        self.source.mkdir()
        (self.source / "service.conf").write_text("fixture=true\n")
        self.work = self.base / "recovery"
        self.cfg = dict(schema=relay.SCHEMA, node_id="relay-fixture", generation=7,
                        config_hash="e" * 64, work_dir=str(self.work),
                        groups={"gateway": {"consistency": "stateless", "paths": [str(self.source)]}})
        self.runner = FakeRunner()

    def tearDown(self):
        self.tmp.cleanup()

    def side(self):
        return relay.RelaySide(copy.deepcopy(self.cfg), runner=self.runner, root_owned=False)

    def request(self, op="export", **kw):
        return {"op": op, "request_id": "request-unique-1", **kw}

    def test_export_is_private_complete_regular_archive_with_bound_manifest(self):
        (self.source / "empty").mkdir(mode=0o700)
        obj = self.side()
        with obj.export_artifact("request-unique-1") as (path, manifest):
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with tarfile.open(path, "r:gz") as archive:
                members = archive.getmembers()
                self.assertEqual(members[0].name, "MANIFEST.json")
                self.assertTrue(all(not m.issym() and not m.islnk() for m in members))
                self.assertTrue(all(not m.name.startswith("/") and ".." not in Path(m.name).parts for m in members))
                actual = json.load(archive.extractfile("MANIFEST.json"))
                self.assertEqual(actual, manifest)
                self.assertEqual(actual["request_id"], "request-unique-1")
                self.assertEqual(actual["generation"], 7)
                self.assertFalse(actual["global_consistency_proven"])
                self.assertFalse(actual["persistent_fence"])
                for f in actual["files"]:
                    payload = archive.extractfile(f["member"]).read()
                    self.assertEqual(hashlib.sha256(payload).hexdigest(), f["sha256"])
                empty = "data/" + str(self.source / "empty").lstrip("/")
                self.assertTrue(archive.getmember(empty).isdir())
                self.assertEqual(archive.getmember(empty).mode, 0o700)
        self.assertFalse(path.exists())
        self.assertEqual(list(self.work.iterdir()), [])

    def test_registered_wal_sqlite_online_snapshot_includes_committed_wal_rows(self):
        database = self.source / "db.sqlite3"
        connection = sqlite3.connect(database)
        try:
            connection.execute("PRAGMA journal_mode=WAL")
            connection.execute("CREATE TABLE items (v TEXT)")
            connection.execute("INSERT INTO items VALUES ('committed-in-wal')")
            connection.commit()
            self.assertTrue(Path(str(database) + "-wal").exists())
            self.cfg["groups"]["gateway"]["consistency"] = "sqlite"
            self.cfg["sqlite_databases"] = [dict(path=str(database), group="gateway")]
            with self.side().export_artifact("request-1") as (path, manifest):
                self.assertEqual(manifest["sqlite_snapshots"][0]["quick_check"], "ok")
                self.assertTrue(all(not f["path"].endswith(("-wal", "-shm")) for f in manifest["files"]))
                entry = next(f for f in manifest["files"] if f["path"] == str(database))
                with tarfile.open(path, "r:gz") as archive:
                    saved = self.base / "saved.sqlite3"
                    saved.write_bytes(archive.extractfile(entry["member"]).read())
                    with sqlite3.connect(saved) as snapshot:
                        self.assertEqual(snapshot.execute("SELECT v FROM items").fetchall(), [("committed-in-wal",)])
                        self.assertEqual(snapshot.execute("PRAGMA quick_check").fetchall(), [("ok",)])
            self.assertEqual(connection.execute("SELECT count(*) FROM items").fetchone()[0], 1)
        finally:
            connection.close()

    def test_sqlite_with_unknown_extension_is_detected_and_blocks_export(self):
        with sqlite3.connect(self.source / "state.bin") as database:
            database.execute("CREATE TABLE values_ (v)")
        obj = self.side()
        self.assertIn("unregistered_sqlite", [i["code"] for i in obj.inspect("request-1")["issues"]])
        with self.assertRaisesRegex(ProtocolError, "export_inventory_unsafe"):
            with obj.export_artifact("request-1"): pass

    def test_unhandled_database_format_blocks_export(self):
        (self.source / "external.rdb").write_bytes(b"not a sqlite fixture")
        self.assertIn("unhandled_mutable_database", [i["code"] for i in self.side().inspect("request-1")["issues"]])

    def test_external_consistency_cannot_be_claimed_from_file_copy(self):
        self.cfg["groups"]["gateway"]["consistency"] = "external"
        self.assertFalse(self.side().inspect("request-1")["ok"])

    def test_unverified_group_blocks_export(self):
        self.cfg["groups"]["gateway"].pop("consistency")
        self.assertFalse(self.side().inspect("request-1")["ok"])

    def test_symlink_inside_inventory_is_only_metadata(self):
        (self.source / "alias.conf").symlink_to("service.conf")
        with self.side().export_artifact("request-1") as (path, manifest):
            self.assertTrue(manifest["symlinks"][0]["target_in_inventory_scope"])
            with tarfile.open(path) as archive:
                self.assertTrue(all("alias.conf" not in m.name for m in archive.getmembers()))

    def test_external_and_indirect_symlinks_are_never_followed(self):
        outside = self.base / "outside-secret"
        outside.write_text("secret-fixture")
        (self.source / "outside").symlink_to(outside)
        (self.source / "indirect").symlink_to("outside")
        report = self.side().inspect("request-1")
        self.assertFalse(report["ok"])
        codes = [i["code"] for i in report["issues"]]
        self.assertIn("symlink_target_outside_scope", codes)
        self.assertIn("symlink_target_not_exported", codes)
        self.assertNotIn("secret-fixture", json.dumps(report))

    def test_symlink_directory_parent_is_not_opened(self):
        proxy = self.base / "proxy"
        proxy.symlink_to(self.source, target_is_directory=True)
        self.cfg["groups"]["gateway"]["paths"] = [str(proxy / "service.conf")]
        with self.assertRaises(OSError):
            self.side().inspect("request-1")

    def test_special_file_blocks_unless_explicitly_excluded(self):
        fifo = self.source / "socket-fixture"
        os.mkfifo(fifo)
        self.assertFalse(self.side().inspect("request-1")["ok"])
        self.cfg["exclude_paths"] = [str(fifo)]
        with self.side().export_artifact("request-1") as (_, manifest):
            self.assertTrue(manifest["ok"])

    def test_recovery_directory_can_never_be_included(self):
        self.cfg["groups"]["gateway"]["paths"] = [str(self.base)]
        with self.assertRaisesRegex(ProtocolError, "recovery_directory_included"):
            self.side()

    def test_config_list_types_and_limits_fail_closed(self):
        for field, value in (("units", "nginx.service"), ("required_groups", [{}]), ("max_bytes", True)):
            with self.subTest(field=field):
                config = copy.deepcopy(self.cfg); config[field] = value
                with self.assertRaises(ProtocolError):
                    relay.RelaySide(config, root_owned=False)

    def test_request_accepts_only_fixed_operations_and_no_paths(self):
        for extra in ({"path": "/etc/shadow"}, {"command": "id"}, {"op": "shell"}, {"generation": 2}, {"config_hash": "f" * 64}):
            with self.subTest(extra=extra), self.assertRaises(ProtocolError):
                relay.request_from_bytes(canonical_json(self.request(**extra)), self.cfg)
        self.assertEqual(relay.request_from_bytes(canonical_json(self.request()), self.cfg)["op"], "export")

    def test_request_size_duplicates_and_missing_fence_identity_rejected(self):
        for body in (b" " * (relay.MAX_REQUEST + 1), b'{"op":"inspect","op":"export","request_id":"x"}', canonical_json(self.request("fence"))):
            with self.subTest(size=len(body)), self.assertRaises(ProtocolError):
                relay.request_from_bytes(body, self.cfg)

    def test_exact_registry_digest_changes_when_content_changes(self):
        obj = self.side()
        first = obj.inspect("r1")["registry_hash"]
        (self.source / "service.conf").write_text("fixture=false\n")
        self.assertNotEqual(first, obj.inspect("r2")["registry_hash"])

    def test_changed_file_between_inventory_and_stage_blocks_snapshot(self):
        obj = self.side()
        original = obj.inventory
        def drift():
            inventory = original()
            (self.source / "service.conf").write_text("different-content\n")
            return inventory
        obj.inventory = drift
        with self.assertRaisesRegex(ProtocolError, "source_changed_since_inventory"):
            with obj.export_artifact("request-1"): pass
        self.assertEqual(list(self.work.iterdir()), [])

    def test_size_limit_blocks_before_archive_is_returned(self):
        self.cfg["max_file_bytes"] = 1
        with self.assertRaisesRegex(ProtocolError, "source_file_size_limit"):
            with self.side().export_artifact("request-1"): pass

    def test_service_status_uses_only_fixed_arguments_and_timeout_is_unknown(self):
        self.cfg["units"] = ["gateway.service"]
        self.assertTrue(self.side().inspect("request-1")["services"]["gateway.service"]["ok"])
        self.assertEqual(self.runner.calls[0][:3], ["/usr/bin/systemctl", "show", "gateway.service"])
        self.runner.timeout = True
        self.assertFalse(self.side().inspect("request-1")["services"]["gateway.service"]["ok"])

    def test_fence_disabled_by_default_and_protected_units_cannot_be_configured(self):
        with self.assertRaisesRegex(ProtocolError, "fence_disabled"):
            self.side().fence(self.request("fence", generation=7, config_hash="e" * 64))
        self.assertEqual(self.runner.calls, [])
        for unit in ("ssh.service", "sshd.service", "servernet-recovery.service", "docker.service", "systemd-networkd.service"):
            with self.subTest(unit=unit):
                self.cfg["fence_units"] = [unit]
                with self.assertRaisesRegex(ProtocolError, "protected_unit"):
                    self.side()

    def test_fence_alias_for_protected_service_is_rejected_before_stop(self):
        self.cfg.update(fence_enabled=True, fence_units=["writer.service"])
        self.runner.alias = "writer.service ssh.service"
        with self.assertRaisesRegex(ProtocolError, "fence_unit_unverified"):
            self.side().fence(self.request("fence", generation=7, config_hash="e" * 64))
        self.assertFalse(self.runner.stopped)

    def test_explicit_fence_is_journalled_but_never_claims_persistent_fence(self):
        self.cfg.update(fence_enabled=True, fence_units=["writer.service"])
        result = self.side().fence(self.request("fence", generation=7, config_hash="e" * 64))
        self.assertTrue(result["units_stopped"])
        self.assertFalse(result["persistent_fence"])
        self.assertFalse(result["fenced"])
        journal = self.work / "fence-intent.json"
        self.assertEqual(journal.stat().st_mode & 0o777, 0o600)
        self.assertEqual(json.loads(journal.read_bytes()), result)

    def docker_fixture(self):
        digest = "sha256:" + "1" * 64
        self.cfg.update(docker_images=[digest], docker_containers=["marzban-fixture"], docker_include_config=True)
        self.runner.docker = {"Id": "container-fixture", "Image": digest,
            "Config": {"Image": "registered-fixture", "Env": ["TOKEN=fixture-private-value"]},
            "State": {"Running": True}, "Mounts": [{"Type": "bind", "Source": str(self.source), "Destination": "/var/lib/marzban", "RW": True}]}
        return digest

    def test_docker_credentials_only_in_private_binary_archive_not_inspect_json(self):
        digest = self.docker_fixture()
        obj = self.side()
        report = obj.inspect("request-1")
        self.assertTrue(report["ok"])
        self.assertNotIn("fixture-private-value", json.dumps(report))
        def fake_save(requested, target):
            self.assertEqual(requested, digest)
            target.write_bytes(b"fake-pinned-image-export")
        obj._save_image = fake_save
        with obj.export_artifact("request-1") as (path, manifest):
            self.assertNotIn("fixture-private-value", json.dumps(manifest))
            with tarfile.open(path) as archive:
                metadata = json.load(archive.extractfile("private/docker/marzban-fixture.json"))
                self.assertEqual(metadata["container"]["Config"]["Env"], ["TOKEN=fixture-private-value"])

    def test_docker_writable_mount_image_and_config_must_be_registered(self):
        self.docker_fixture()
        self.runner.docker["Mounts"][0]["Source"] = str(self.base / "unregistered")
        self.cfg["docker_images"] = []
        self.cfg["docker_include_config"] = False
        report = self.side().inspect("request-1")
        self.assertFalse(report["ok"])
        self.assertEqual({i["code"] for i in report["issues"]}, {"docker_writable_mount_outside_inventory", "docker_image_not_registered", "docker_config_not_exported"})

    def test_docker_readonly_config_mount_also_requires_inventory(self):
        self.docker_fixture()
        self.runner.docker["Mounts"][0].update(Source=str(self.base / "unregistered-config"), RW=False)
        self.assertIn("docker_readonly_mount_outside_inventory", [i["code"] for i in self.side().inspect("request-1")["issues"]])

    def test_empty_docker_diff_proves_only_configured_inventory_completeness(self):
        self.docker_fixture()
        report = self.side().inspect("request-1")
        self.assertTrue(report["docker_writable_layer_verified"])
        self.assertTrue(report["configured_inventory_complete"])
        self.assertFalse(report["global_consistency_proven"])

    def test_unapproved_overlay_blocks_completeness_but_keeps_per_database_export_available(self):
        self.docker_fixture()
        self.runner.docker_diff = b"A /app/runtime.db\n"
        obj = self.side()
        report = obj.inspect("request-1")
        self.assertTrue(report["ok"])
        self.assertFalse(report["configured_inventory_complete"])
        self.assertFalse(report["docker_writable_layer_verified"])
        self.assertEqual(report["completeness_issues"][0]["code"], "docker_writable_layer_unverified")
        obj._save_image = lambda digest, target: target.write_bytes(b"fake-image")
        with obj.export_artifact("request-1") as (_, manifest):
            self.assertFalse(manifest["configured_inventory_complete"])
            self.assertFalse(manifest["docker_writable_layer_verified"])
            self.assertFalse(manifest["global_consistency_proven"])

    def test_overlay_disposable_approval_is_exact_and_never_recursive(self):
        self.docker_fixture()
        self.cfg["docker_disposable_overlay_paths"] = {"marzban-fixture": ["/tmp/marzban.pid"]}
        self.runner.docker_diff = b"A /tmp/marzban.pid\n"
        self.assertTrue(self.side().inspect("request-1")["docker_writable_layer_verified"])
        self.runner.docker_diff = b"A /tmp/marzban.pid\nA /tmp/marzban.pid/secret\n"
        self.assertFalse(self.side().inspect("request-1")["docker_writable_layer_verified"])
        for path in ("/var", "/opt", "/app", "/tmp", "/var/*", "/tmp/a/../b"):
            self.cfg["docker_disposable_overlay_paths"] = {"marzban-fixture": [path]}
            with self.subTest(path=path), self.assertRaises(ProtocolError): self.side()

    def test_malformed_docker_diff_is_unknown(self):
        self.docker_fixture()
        for raw in (b"not a diff\n", b"A /tmp/a\nC /tmp/a\n", b"A /tmp/../secret\n"):
            self.runner.docker_diff = raw
            with self.subTest(raw=raw):
                self.assertFalse(self.side().inspect("request-1")["docker_writable_layer_verified"])

    def test_changed_overlay_during_export_does_not_receive_completeness_proof(self):
        self.docker_fixture()
        self.cfg["docker_disposable_overlay_paths"] = {"marzban-fixture": ["/tmp/a"]}
        self.runner.docker_diff = [b"", b"A /tmp/a\n"]
        obj = self.side(); obj._save_image = lambda digest, target: target.write_bytes(b"fake-image")
        with obj.export_artifact("request-1") as (_, manifest):
            self.assertFalse(manifest["docker_writable_layer_verified"])
            self.assertEqual(manifest["docker_overlay"]["marzban-fixture"]["code"], "docker_writable_layer_changed_during_export")

    def test_restore_plan_never_applies_or_claims_health(self):
        plan = self.side().restore_plan("request-1")
        self.assertTrue(plan["ok"])
        self.assertFalse(plan["applied"])
        self.assertTrue(plan["requires_target_identity_and_user_mapping"])
        self.assertFalse(plan["global_consistency_proven"])
        self.assertEqual(self.runner.calls, [])


if __name__ == "__main__":
    unittest.main()
