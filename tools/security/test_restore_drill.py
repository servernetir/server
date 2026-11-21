import importlib.util
import io
import json
import os
import socket
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('restore_drill', Path(__file__).with_name('restore-drill.py'))
drill = importlib.util.module_from_spec(spec)
spec.loader.exec_module(drill)


class RecoveryBoundaries(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)/'home'
        self.home.mkdir()
        self.stage = Path(self.temp.name)/'stage'
        self.stage.mkdir(mode=0o700)
        drill.EXCLUDED_PREVIEWS.clear()
        self.addCleanup(drill.EXCLUDED_PREVIEWS.clear)
        for name in drill.APP_PARTS:
            (self.home/'servernet_app'/name).mkdir(parents=True)
        (self.home/'public_html').mkdir()

    def test_roundtrip_keeps_code_private_data_and_key_but_excludes_rebuildable_state(self):
        payloads = {'servernet_app/.env': b'APP_KEY=synthetic-key-never-production\n',
                    'servernet_app/app/example.php': b'<?php return true;',
                    'servernet_app/storage/app/private/evidence.txt': b'synthetic evidence',
                    'public_html/index.php': b'<?php echo "example";'}
        for name, data in payloads.items():
            path = self.home/name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        os.chmod(self.home/'servernet_app/.env', 0o600)
        for name in ['servernet_app/storage/framework/cache/old', 'servernet_app/storage/logs/old.log',
                     'servernet_app/bootstrap/cache/config.php']:
            p = self.home/name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text('must not recover')
        summary = drill.archive_sources(self.home, self.stage)
        self.assertEqual(len(payloads), summary['files'])
        target = self.stage/'restored'
        target.mkdir()
        drill.restore_members(self.stage/'files.tar.gz', target)
        for name, data in payloads.items():
            self.assertEqual(data, (target/name).read_bytes())
        self.assertFalse((target/'servernet_app/bootstrap/cache/config.php').exists())
        self.assertEqual(0o600, (target/'servernet_app/.env').stat().st_mode & 0o777)

    def test_internal_absolute_link_is_made_relative_for_recovery(self):
        source = self.home/'servernet_app/storage/app/public'
        source.mkdir(parents=True)
        (source/'photo.txt').write_text('synthetic')
        link = self.home/'public_html/storage'
        link.symlink_to(source, target_is_directory=True)
        drill.archive_sources(self.home, self.stage)
        target = self.stage/'restored'
        target.mkdir()
        drill.restore_members(self.stage/'files.tar.gz', target)
        self.assertEqual('synthetic', (target/'public_html/storage/photo.txt').read_text())
        self.assertTrue((target/'public_html/storage').resolve().is_relative_to(target))

    def test_reviewed_operator_exclusion_is_exact_and_recorded(self):
        excluded = sorted(drill.OPERATOR_ARTIFACTS)[0]
        for name in [excluded, excluded+'.customer-copy', 'servernet_app/storage/app/private/invoice.json']:
            path = self.home/name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('synthetic')
        drill.EXCLUDED_PREVIEWS.add(excluded)
        summary = drill.archive_sources(self.home, self.stage)
        self.assertEqual([excluded], summary['operator_previews_excluded'])
        with tarfile.open(self.stage/'files.tar.gz') as archive:
            self.assertEqual(sorted([excluded+'.customer-copy', 'servernet_app/storage/app/private/invoice.json']), sorted(archive.getnames()))

    def test_reviewed_operator_directory_can_be_excluded_without_entering_it(self):
        excluded = 'servernet_app/storage/app/private/diagnostics/'
        path = self.home/excluded
        path.mkdir(parents=True)
        os.chmod(path, 0)
        drill.EXCLUDED_PREVIEWS.add(excluded)
        try:
            summary = drill.archive_sources(self.home, self.stage)
            self.assertEqual([excluded], summary['operator_previews_excluded'])
            self.assertEqual(0, summary['files'])
        finally:
            os.chmod(path, 0o700)

    def test_source_link_outside_the_approved_roots_is_rejected(self):
        outside = Path(self.temp.name)/'outside'
        outside.write_text('do not include')
        (self.home/'public_html/external').symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, 'leaves approved'):
            drill.archive_sources(self.home, self.stage)

    def test_parent_traversal_member_is_rejected_before_extraction(self):
        archive = self.stage/'evil.tar.gz'
        with tarfile.open(archive, 'w:gz') as tar:
            member = tarfile.TarInfo('servernet_app/../../outside')
            member.size = 3
            tar.addfile(member, io.BytesIO(b'bad'))
        with self.assertRaises(RuntimeError):
            drill.restore_members(archive, self.stage/'restored')
        self.assertFalse((self.stage/'outside').exists())

    def test_archive_link_cannot_escape_restoration_directory(self):
        archive = self.stage/'evil-link.tar.gz'
        with tarfile.open(archive, 'w:gz') as tar:
            member = tarfile.TarInfo('servernet_app/external')
            member.type = tarfile.SYMTYPE
            member.linkname = '../../outside'
            tar.addfile(member)
        with self.assertRaises(tarfile.FilterError):
            drill.restore_members(archive, self.stage/'restored')

    def test_special_source_file_is_rejected_without_reading_it(self):
        os.mkfifo(self.home/'public_html/pipe')
        with self.assertRaisesRegex(RuntimeError, 'special recovery file'):
            drill.archive_sources(self.home, self.stage)

    def test_local_socket_is_recorded_as_ephemeral_and_never_read_as_a_file(self):
        endpoint = self.home/'servernet_app/storage/app/query.sock'
        try:
            server = socket.socket(socket.AF_UNIX)
        except PermissionError:
            self.skipTest('The execution host does not permit creating local IPC sockets.')
        with server:
            server.bind(str(endpoint))
            summary = drill.archive_sources(self.home, self.stage)
        self.assertEqual(['servernet_app/storage/app/query.sock'], summary['ephemeral_sockets_excluded'])
        self.assertEqual(0, summary['files'])

    def test_unreadable_customer_file_fails_instead_of_creating_complete_backup(self):
        path = self.home/'servernet_app/storage/app/private/evidence.txt'
        path.parent.mkdir(parents=True)
        path.write_text('synthetic evidence')
        os.chmod(path, 0)
        try:
            with self.assertRaises(PermissionError):
                drill.archive_sources(self.home, self.stage)
            self.assertFalse((self.stage/'files.tar.gz').exists())
            self.assertFalse((self.stage/'file-manifest.json').exists())
        finally:
            os.chmod(path, 0o600)

    def test_unreadable_directory_fails_instead_of_silently_omitting_customer_data(self):
        path = self.home/'servernet_app/storage/app/private'
        path.mkdir(parents=True)
        os.chmod(path, 0)
        try:
            with self.assertRaises(PermissionError):
                list(drill.candidates(self.home))
        finally:
            os.chmod(path, 0o700)

    def test_sql_counts_include_empty_tables_and_escaped_newline_payloads(self):
        sql = self.stage/'database.sql'
        sql.write_bytes(b"CREATE TABLE `a` (\n);\nCREATE TABLE `empty` (\n);\nINSERT INTO `a` VALUES (1,'hello\\nINSERT INTO `a` VALUES');\nINSERT INTO `a` VALUES (2,'other');\n")
        self.assertEqual({'a': 2, 'empty': 0}, drill.inspect_sql(sql, ['a', 'empty']))

    def test_sql_with_missing_schema_or_unexpected_table_is_rejected(self):
        sql = self.stage/'database.sql'
        sql.write_bytes(b'CREATE TABLE `a` (\n);\n')
        with self.assertRaisesRegex(RuntimeError, 'Incomplete'):
            drill.inspect_sql(sql, ['a', 'missing'])
        sql.write_bytes(b'CREATE TABLE `a` (\n);\nINSERT INTO `other` VALUES (1);\n')
        with self.assertRaisesRegex(RuntimeError, 'Unexpected table'):
            drill.inspect_sql(sql, ['a'])


if __name__ == '__main__':
    unittest.main()
