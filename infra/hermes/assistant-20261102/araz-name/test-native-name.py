import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import stat
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('native_name_release', Path(__file__).with_name('install-native-name.py'))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class NativeNameReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.base, self.state, self.backup, self.source = [root / name for name in ('base', 'state', 'backup', 'source')]
        for path in (self.base, self.state, self.backup, self.source):
            path.mkdir(mode=0o700)
        self.old, self.new = b'# old ingress\n', b'# new ingress\n'
        (self.base / m.TARGET).write_bytes(self.old)
        (self.base / m.TARGET).chmod(0o640)
        (self.source / m.TARGET).write_bytes(self.new)
        (self.base / 'owner_project_context.py').write_bytes(b'# unchanged helper\n')
        (self.state / 'project-references.json').write_bytes(b'[]')
        with sqlite3.connect(self.state / 'jobs.db') as db:
            db.execute('CREATE TABLE jobs(status TEXT)')
        # This container has no UID1000 filesystem mapping. Model only the
        # observed stat ownership; real files/modes, SQLite and I/O stay in use.
        for name in ('jobs.db', 'project-references.json'):
            (self.state / name).chmod(0o600)
        self.state_owner = (1000, 1000)
        real_metadata = m.metadata_of
        def observed_metadata(path):
            value = real_metadata(path)
            if path in (self.state, self.state / 'jobs.db', self.state / 'project-references.json'):
                value = {**value, 'uid': self.state_owner[0], 'gid': self.state_owner[1]}
            return value
        patcher = mock.patch.object(m, 'metadata_of', side_effect=observed_metadata)
        patcher.start()
        self.addCleanup(patcher.stop)
        for key, value in {'BASE': self.base, 'STATE': self.state, 'BACKUP': self.backup,
                           'LOCK': root / 'release.lock', 'BEFORE': m.digest(self.old), 'AFTER': m.digest(self.new),
                           'HELPER': m.digest((self.base / 'owner_project_context.py').read_bytes())}.items():
            patcher = mock.patch.object(m, key, value)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.calls = []
        self.service = mock.patch.object(m, 'service', side_effect=self.fake_service)
        self.service.start()
        self.addCleanup(self.service.stop)
        patcher = mock.patch.object(m.os, 'geteuid', return_value=0)
        patcher.start()
        self.addCleanup(patcher.stop)

    def fake_service(self, *args):
        self.calls.append(args)
        if args[0] == 'is-active':
            return 'active'
        if args[0] == 'show':
            return '42'
        return ''

    def test_install_changes_only_ingress_and_preserves_exact_backup_and_metadata(self):
        original_info = m.metadata_of(self.base / m.TARGET)
        state_before = (self.state / 'jobs.db').read_bytes()
        result = m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.new)
        self.assertEqual(m.metadata_of(self.base / m.TARGET), original_info)
        self.assertEqual((self.backup / 'araz-owner-ingress.before.py').read_bytes(), self.old)
        self.assertEqual(stat.S_IMODE((self.backup / 'araz-owner-ingress.before.py').stat().st_mode), 0o600)
        self.assertEqual((self.state / 'jobs.db').read_bytes(), state_before)
        self.assertEqual((self.state / 'project-references.json').read_bytes(), b'[]')
        self.assertTrue(result['broker_pid_preserved'])
        self.assertEqual([c for c in self.calls if c[0] in ('stop', 'start')],
                         [('stop', m.OWNER_SERVICE), ('start', m.OWNER_SERVICE)])
        self.assertEqual(json.loads((self.backup / 'araz-installed.json').read_text())['after_sha256'], m.AFTER)

    def test_busy_queue_rejects_before_stop_or_backup(self):
        with sqlite3.connect(self.state / 'jobs.db') as db:
            db.execute("INSERT INTO jobs VALUES('running')")
        with self.assertRaisesRegex(RuntimeError, 'owner_queue_busy'):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
        self.assertEqual(list(self.backup.iterdir()), [])
        self.assertFalse(any(c[0] in ('stop', 'start') for c in self.calls))

    def test_newer_runtime_or_wrong_source_never_changes_runtime(self):
        (self.base / m.TARGET).write_bytes(b'# newer colleague work\n')
        with self.assertRaisesRegex(RuntimeError, 'ingress_hash_drift'):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), b'# newer colleague work\n')
        (self.base / m.TARGET).write_bytes(self.old)
        (self.source / m.TARGET).write_bytes(b'# unreviewed source\n')
        with self.assertRaisesRegex(RuntimeError, 'source_hash_drift'):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
        self.assertFalse(any(c[0] in ('stop', 'start') for c in self.calls))

    def test_existing_backup_is_never_overwritten(self):
        saved = self.backup / 'araz-owner-ingress.before.py'
        saved.write_bytes(b'previous evidence')
        with self.assertRaisesRegex(RuntimeError, 'existing_name_release_record'):
            m.install(self.source)
        self.assertEqual(saved.read_bytes(), b'previous evidence')
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)

    def test_queue_becoming_busy_after_stop_leaves_source_and_restarts_owner(self):
        idle = m.idle
        count = 0
        def observed():
            nonlocal count
            count += 1
            if count == 2:
                raise RuntimeError('owner_queue_busy')
            return idle()
        with mock.patch.object(m, 'idle', side_effect=observed), self.assertRaisesRegex(RuntimeError, 'owner_queue_busy'):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
        self.assertEqual([c for c in self.calls if c[0] in ('stop', 'start')],
                         [('stop', m.OWNER_SERVICE), ('start', m.OWNER_SERVICE)])

    def test_post_replace_failure_rolls_back_only_when_its_hash_still_matches(self):
        write = m.atomic_target
        count = 0
        def fail_after_replace(data, metadata):
            nonlocal count
            count += 1
            write(data, metadata)
            if count == 1:
                raise OSError('synthetic fsync failure')
        with mock.patch.object(m, 'atomic_target', side_effect=fail_after_replace), self.assertRaises(OSError):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
        self.assertFalse((self.backup / 'araz-installed.json').exists())

    def test_explicit_rollback_preserves_newer_edits(self):
        m.install(self.source)
        (self.base / m.TARGET).write_bytes(b'# newer work\n')
        with self.assertRaisesRegex(RuntimeError, 'ingress_hash_drift'):
            m.rollback()
        self.assertEqual((self.base / m.TARGET).read_bytes(), b'# newer work\n')

    def test_successful_explicit_rollback_restores_only_recorded_source(self):
        m.install(self.source)
        result = m.rollback()
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
        self.assertEqual((self.base / 'owner_project_context.py').read_bytes(), b'# unchanged helper\n')
        self.assertEqual((self.state / 'project-references.json').read_bytes(), b'[]')
        self.assertEqual(result['after_sha256'], m.BEFORE)
        self.assertTrue((self.backup / 'araz-rolled-back.json').exists())

    def test_observed_service_state_owner_is_accepted_but_metadata_drift_is_rejected(self):
        self.assertEqual(m.metadata_of(self.state), {'uid': 1000, 'gid': 1000, 'mode': 0o700})
        m.preflight()
        for uid, gid, mode in [(0, 1000, 0o700), (1000, 0, 0o700), (1000, 1000, 0o750)]:
            with self.subTest(uid=uid, gid=gid, mode=mode):
                self.state_owner = (uid, gid)
                self.state.chmod(mode)
                with self.assertRaisesRegex(RuntimeError, 'state_directory_metadata_drift'):
                    m.install(self.source)
                self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
                self.assertEqual(list(self.backup.iterdir()), [])
                self.state_owner = (1000, 1000)
                self.state.chmod(0o700)
        (self.state / 'project-references.json').chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, 'state_file_metadata_drift'):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)
        self.assertFalse(any(c[0] in ('stop', 'start') for c in self.calls))

    def test_installer_itself_is_ascii_and_refuses_non_root(self):
        self.assertTrue(Path(__file__).with_name('install-native-name.py').read_bytes().isascii())
        with mock.patch.object(m.os, 'geteuid', return_value=1000), self.assertRaisesRegex(RuntimeError, 'unexpected_host_or_account'):
            m.install(self.source)
        self.assertEqual((self.base / m.TARGET).read_bytes(), self.old)


if __name__ == '__main__':
    unittest.main(verbosity=2)
