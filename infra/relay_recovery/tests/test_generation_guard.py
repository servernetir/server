"""Offline generation gate fixtures; no systemctl, metadata or provider calls."""
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import generation_guard as guard

UUID = "12345678-1234-5678-9abc-123456789abc"
CLONE_UUID = "87654321-4321-8765-cba9-cba987654321"


def identity(**changes):
    result = dict(schema=guard.SCHEMA, service_id="495", provider_id="173913007",
                  generation=1, config_hash="a" * 64,
                  machine_uuid_sha256=hashlib.sha256(UUID.encode()).hexdigest(),
                  fenced=False, units=["docker.service", "nginx.service", "pdns.service",
                                       "servernet-gateway.service", "wg-quick@wg0.service"])
    result.update(changes)
    return result


class GenerationIdentityTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.path = self.base / "identity.json"

    def tearDown(self):
        self.temp.cleanup()

    def write(self, body=None, mode=0o600):
        if body is None:
            body = json.dumps(identity()).encode()
        if self.path.exists():
            self.path.chmod(0o600)
        self.path.write_bytes(body)
        self.path.chmod(mode)

    def test_running_generation_allows_but_snapshot_clone_and_fence_deny(self):
        original = guard.machine_uuid_hash(lambda: UUID)
        clone = guard.machine_uuid_hash(lambda: CLONE_UUID)
        self.assertTrue(guard.check_guard(identity(), original))
        self.assertFalse(guard.check_guard(identity(), clone))
        self.assertFalse(guard.check_guard(identity(fenced=True), original))
        self.assertFalse(guard.check_guard(identity(fenced=True), clone))
        for missing in (None, "", "invalid", True, "A" * 64):
            with self.subTest(machine_hash=missing):
                self.assertFalse(guard.check_guard(identity(), missing))

    def test_machine_uuid_normalizes_case_and_outer_whitespace(self):
        expected = hashlib.sha256(UUID.encode("ascii")).hexdigest()
        self.assertEqual(guard.machine_uuid_hash(lambda: " \t" + UUID.upper() + "\r\n"), expected)
        self.assertEqual(guard.machine_uuid_hash(lambda: UUID.upper().encode() + b"\n"), expected)

    def test_machine_uuid_unavailable_malformed_or_placeholder_denies(self):
        for raw in (None, 123, b"\xff", "", "not-a-uuid", " " * 129, UUID + "x",
                    "0" * 8 + "-" + "0" * 4 + "-" + "0" * 4 + "-" + "0" * 4 + "-" + "0" * 12,
                    "ffffffff-ffff-ffff-ffff-ffffffffffff", "{" + UUID + "}", UUID.replace("-", "")):
            with self.subTest(raw=raw), self.assertRaises(guard.GuardError):
                guard.machine_uuid_hash(lambda: raw)
        def unavailable():
            raise PermissionError("sensitive fixture detail")
        with self.assertRaisesRegex(guard.GuardError, "^machine_identity_unavailable$"):
            guard.machine_uuid_hash(unavailable)

    def test_default_uuid_reader_is_fixed_root_dmi(self):
        stream = io.BytesIO(UUID.encode() + b"\n")
        stream.fileno = lambda: 10
        fake_info = os.stat_result((stat.S_IFREG | 0o400, 0, 0, 1, 0, 0, 4096, 0, 0, 0))
        with patch("generation_guard.open", return_value=stream, create=True) as opened, \
             patch("generation_guard.os.fstat", return_value=fake_info):
            self.assertEqual(guard.machine_uuid_hash(), identity()["machine_uuid_sha256"])
        opened.assert_called_once_with(guard.DMI_UUID_PATH, "rb")

    def test_identity_is_strict_and_copies_reviewed_unit_order(self):
        value = identity()
        normalized = guard.validate_identity(value)
        self.assertEqual(normalized, value)
        normalized["units"].append("vpnserver.service")
        self.assertEqual(len(value["units"]), 5)
        invalid = [None, [], {}, {**value, "argv": ["arbitrary"]},
                   {key: item for key, item in value.items() if key != "fenced"}]
        for field, options in {
            "schema": ["wrong", None], "service_id": [495, "0", "-1", "01", "1\n", "1" * 21],
            "provider_id": [True, "1;id", "١٢"], "generation": [True, False, 0, -1, 1.0, "1"],
            "config_hash": ["A" * 64, "a" * 63, "g" * 64, None],
            "machine_uuid_sha256": [False, "short"], "fenced": [0, 1, None, "false"],
            "units": [[], None, ["nginx.service", "nginx.service"]],
        }.items():
            invalid.extend(identity(**{field: item}) for item in options)
        for value in invalid:
            with self.subTest(value=value), self.assertRaises(guard.GuardError):
                guard.validate_identity(value)
            self.assertFalse(guard.check_guard(value, identity()["machine_uuid_sha256"]))

    def test_units_preserve_business_inventory_and_reject_protected_control_paths(self):
        units = identity()["units"] + ["openvpn-server@server.service", "vpnserver.service"]
        self.assertEqual(guard.validate_units(units), units)
        for unit in ("ssh.service", "sshd@fixture.service", "ssh:fixture.service", "dropbear.service",
                     "servernet-recovery.service", "servernet-control.service", "servernet-relay-agent.service",
                     "NetworkManager.service", "networking.service", "systemd-networkd.service",
                     "systemd-resolved.service", "network@fixture.service", "dbus.service",
                     "cloudflared.service", "containerd.service", "nftables.service", "cron.service",
                     "getty@tty1.service", "sshd.socket", "nginx.timer", "../nginx.service",
                     "/etc/nginx.service", "nginx.service;id", "nginx.service\n", "--nginx.service",
                     "*.service", "nginx .service", "ngіnx.service", 1):
            with self.subTest(unit=unit), self.assertRaises(guard.GuardError):
                guard.validate_units([unit])
        for units in ((), ["nginx.service"] * 2, ["nginx.service"] * 129):
            with self.assertRaises(guard.GuardError):
                guard.validate_units(units)

    def test_private_identity_loads_and_does_not_mutate(self):
        self.write()
        self.assertEqual(guard.load_identity(self.path, root_owned=False), identity())

    def test_load_rejects_missing_noncanonical_and_relative_paths(self):
        for path in (self.path, "identity.json", "/", str(self.base) + "/./identity.json",
                     str(self.base) + "/../identity.json", str(self.path) + "\n", None):
            with self.subTest(path=path), self.assertRaises(guard.GuardError):
                guard.load_identity(path, root_owned=False)

    def test_load_rejects_unsafe_file_modes_even_in_fixture_mode(self):
        for mode in (0o000, 0o400, 0o640, 0o644, 0o660, 0o700, 0o1600, 0o4600):
            with self.subTest(mode=oct(mode)):
                self.write(mode=mode)
                with self.assertRaises(guard.GuardError):
                    guard.load_identity(self.path, root_owned=False)

    def test_root_owned_checks_every_parent_even_for_a_private_leaf(self):
        self.write()
        self.base.chmod(0o777)
        with self.assertRaises(guard.GuardError):
            guard.load_identity(self.path)
        # Only an explicit fixture API override permits temporary unsafe parents.
        self.assertEqual(guard.load_identity(self.path, root_owned=False), identity())

    def test_root_ownership_is_required_for_directory_and_file(self):
        self.write()
        real_fstat = os.fstat
        for wrong_file in (False, True):
            def fstat(fd):
                actual = real_fstat(fd)
                fields = list(actual)
                if stat.S_ISDIR(actual.st_mode):
                    fields[0] = stat.S_IFDIR | 0o755
                    fields[4] = 0 if wrong_file else 12345
                elif wrong_file:
                    fields[4] = 12345
                return os.stat_result(fields)
            with self.subTest(wrong_file=wrong_file), patch("generation_guard.os.fstat", side_effect=fstat):
                with self.assertRaises(guard.GuardError):
                    guard.load_identity(self.path)

    def test_load_rejects_symlinks_hardlinks_directory_and_fifo(self):
        self.write()
        target = self.base / "target.json"
        self.path.rename(target)
        self.path.symlink_to(target)
        with self.assertRaises(guard.GuardError):
            guard.load_identity(self.path, root_owned=False)
        self.path.unlink()
        os.link(target, self.path)
        with self.assertRaises(guard.GuardError):
            guard.load_identity(self.path, root_owned=False)
        self.path.unlink()
        self.path.mkdir()
        with self.assertRaises(guard.GuardError):
            guard.load_identity(self.path, root_owned=False)
        self.path.rmdir()
        os.mkfifo(self.path, 0o600)
        with self.assertRaises(guard.GuardError):
            guard.load_identity(self.path, root_owned=False)
        link = self.base / "link"
        link.symlink_to(self.base, target_is_directory=True)
        with self.assertRaises(guard.GuardError):
            guard.load_identity(link / "target.json", root_owned=False)

    def test_load_rejects_duplicate_nonfinite_invalid_and_oversized_json(self):
        duplicate = json.dumps(identity())[:-1] + ',"fenced":true}'
        malformed = [b"", b"[]", b"null", b"{", b"\xff", duplicate.encode(),
                     json.dumps(identity(generation=float("nan"))).encode(),
                     b'{"generation":' + b"1" * 5000 + b"}",
                     b" " * (guard.MAX_IDENTITY_BYTES + 1), b"[" * 2000]
        for body in malformed:
            with self.subTest(body=body[:80]):
                self.write(body)
                with self.assertRaises(guard.GuardError):
                    guard.load_identity(self.path, root_owned=False)

    def test_atomic_identity_persists_fence_then_reload_keeps_writer_denied(self):
        guard.atomic_identity(self.path, identity(), root_owned=False)
        self.assertEqual(self.path.stat().st_mode & 0o7777, 0o600)
        before = self.path.stat().st_ino
        guard.atomic_identity(self.path, identity(fenced=True), root_owned=False)
        current = guard.load_identity(self.path, root_owned=False)
        self.assertTrue(current["fenced"])
        self.assertNotEqual(before, self.path.stat().st_ino)
        self.assertFalse(guard.check_guard(current, current["machine_uuid_sha256"]))
        self.assertEqual(list(self.base.iterdir()), [self.path])

    def test_atomic_identity_syncs_regular_file_before_directory(self):
        kinds = []
        real_sync = os.fsync
        def sync(fd):
            kinds.append(stat.S_IFMT(os.fstat(fd).st_mode))
            real_sync(fd)
        with patch("generation_guard.os.fsync", side_effect=sync):
            guard.atomic_identity(self.path, identity(), root_owned=False)
        self.assertEqual(kinds, [stat.S_IFREG, stat.S_IFDIR])

    def test_failed_atomic_replace_preserves_prior_state_and_cleans_temporary(self):
        guard.atomic_identity(self.path, identity(), root_owned=False)
        with patch("generation_guard.os.replace", side_effect=OSError("private fixture")):
            with self.assertRaisesRegex(guard.GuardError, "^identity_write$"):
                guard.atomic_identity(self.path, identity(fenced=True), root_owned=False)
        self.assertEqual(guard.load_identity(self.path, root_owned=False), identity())
        self.assertEqual(list(self.base.iterdir()), [self.path])

    def test_atomic_temporary_name_collision_never_deletes_existing_file(self):
        collision = self.base / (".identity.json." + "f" * 32 + ".tmp")
        collision.write_text("existing fixture")
        with patch("generation_guard.secrets.token_hex", return_value="f" * 32):
            with self.assertRaises(guard.GuardError):
                guard.atomic_identity(self.path, identity(), root_owned=False)
        self.assertEqual(collision.read_text(), "existing fixture")
        self.assertFalse(self.path.exists())

    def test_atomic_rejects_invalid_identity_unsafe_existing_file_and_missing_parent(self):
        with self.assertRaises(guard.GuardError):
            guard.atomic_identity(self.path, identity(fenced="false"), root_owned=False)
        self.assertFalse(self.path.exists())
        self.write(mode=0o644)
        with self.assertRaises(guard.GuardError):
            guard.atomic_identity(self.path, identity(fenced=True), root_owned=False)
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o644)
        self.assertEqual(json.loads(self.path.read_bytes()), identity())
        with self.assertRaises(guard.GuardError):
            guard.atomic_identity(self.base / "missing" / "identity.json", identity(), root_owned=False)
        self.assertFalse((self.base / "missing").exists())

    def test_atomic_never_follows_final_or_parent_symlink(self):
        target = self.base / "target.json"
        guard.atomic_identity(target, identity(), root_owned=False)
        self.path.symlink_to(target)
        with self.assertRaises(guard.GuardError):
            guard.atomic_identity(self.path, identity(fenced=True), root_owned=False)
        link = self.base / "link"
        link.symlink_to(self.base, target_is_directory=True)
        with self.assertRaises(guard.GuardError):
            guard.atomic_identity(link / "target.json", identity(fenced=True), root_owned=False)
        self.assertEqual(guard.load_identity(target, root_owned=False), identity())

    def test_cli_exit_status_is_fail_closed_and_output_never_discloses_identity(self):
        for value, machine, error, expected in (
            (identity(), identity()["machine_uuid_sha256"], None, 0),
            (identity(), guard.machine_uuid_hash(lambda: CLONE_UUID), None, 1),
            (identity(fenced=True), identity()["machine_uuid_sha256"], None, 1),
            (identity(), None, OSError("private fixture secret"), 1),
        ):
            output = io.StringIO()
            with patch("generation_guard.load_identity", return_value=value) as loaded, \
                 patch("generation_guard.machine_uuid_hash", return_value=machine, side_effect=error), \
                 patch("sys.stdout", output):
                code = guard.main(["--config", guard.DEFAULT_CONFIG])
            self.assertEqual(code, expected)
            loaded.assert_called_once_with(guard.DEFAULT_CONFIG)
            self.assertEqual(json.loads(output.getvalue()),
                             {"schema": guard.RESULT_SCHEMA, "allowed": expected == 0})

    def test_cli_rejects_paths_and_fixture_options_before_reading_anything(self):
        for args in ([], ["--config", str(self.path)], ["--config", guard.DEFAULT_CONFIG, "--root-owned=false"],
                     ["--validate"], ["--config", guard.DEFAULT_CONFIG, "--config", guard.DEFAULT_CONFIG]):
            with self.subTest(args=args), patch("generation_guard.load_identity") as loaded, \
                 patch("generation_guard.machine_uuid_hash") as machine, patch("sys.stdout", io.StringIO()) as output:
                self.assertEqual(guard.main(args), 1)
                self.assertFalse(json.loads(output.getvalue())["allowed"])
                loaded.assert_not_called()
                machine.assert_not_called()
        result = subprocess.run([sys.executable, str(BASE / "generation_guard.py"), "--config", str(self.path)],
                                capture_output=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, b"")
        self.assertEqual(json.loads(result.stdout), {"schema": guard.RESULT_SCHEMA, "allowed": False})

    def test_cli_missing_or_malformed_config_denies_without_reading_dmi_or_logging_error(self):
        for error in (guard.GuardError("identity_unavailable"), ValueError("private fixture secret")):
            with patch("generation_guard.load_identity", side_effect=error), \
                 patch("generation_guard.machine_uuid_hash") as machine, patch("sys.stdout", io.StringIO()) as output:
                self.assertEqual(guard.main(["--config", guard.DEFAULT_CONFIG]), guard.DENY_EXIT_CODE)
                machine.assert_not_called()
                self.assertEqual(json.loads(output.getvalue()), {"schema": guard.RESULT_SCHEMA, "allowed": False})


if __name__ == "__main__":
    unittest.main()
