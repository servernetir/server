"""Isolated release safety tests; never uses a live app or network."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

SCRIPT = Path(__file__).resolve().parents[5] / 'infra' / 'hermes' / 'deploy_web.py'
SPEC = importlib.util.spec_from_file_location('hermes_deploy_web', SCRIPT)
web = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = web
SPEC.loader.exec_module(web)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.app = self.base / 'app-root'
        self.stage = self.base / 'stage'
        self.backup = self.base / 'private' / 'release-1'
        self.lock = self.base / 'release.lock'
        self.app.mkdir()
        self.stage.mkdir()
        self.write(self.app, 'routes/web.php', b"<?php\n// concurrent chat routes\nrequire __DIR__.'/hermes.php';\nRoute::get('/other', fn () => 1);\n")
        self.write(self.app, 'routes/hermes.php', b"<?php\n" + web.HERMES_PREFIX.encode() + b"(function () { /* live chat and memory */ });\n")
        self.write(self.app, 'routes/console.php', b"<?php\n// existing task\n\\Illuminate\\Support\\Facades\\Schedule::command('old:task')->daily();\n")
        self.view = b"""<!doctype html>\n<style>\nbody{font:14px/1.9 Tahoma,"Segoe UI",Arial,sans-serif;color:red}\n</style>\n<section><p class="note" data-i18n="infra_note"></p></section>\n<script>function show(view){if(view==='status'&&!overviewLoaded)overview()}/* concurrent chat remains intact */$('refresh').onclick=overview;</script>"""
        self.write(self.app, web.VIEW, self.view)
        os.chmod(self.app / web.VIEW, 0o640)
        self.write(self.app, 'app/Http/Controllers/HermesMiniAppController.php', b'<?php // concurrent controller')
        self.write(self.stage, web.VIEW, web.miniapp_patch(self.view))
        self.write(self.stage, 'routes/hermes-monitor.php', b'<?php // monitor routes\n')
        self.write(self.stage, 'app/Http/Controllers/HermesMiniAppMonitorController.php', b'<?php // new monitor\n')
        # Unselected prior experimental files must not leak into this release.
        self.write(self.stage, 'app/Http/Controllers/HermesMiniAppController.php', b'<?php // obsolete replacement')
        self.write(self.stage, 'infra/hermes/private.py', b'never deploy')
        self.expected = {'schema': 1, 'files': {}}
        for relative in (web.VIEW, 'routes/hermes-monitor.php', 'app/Http/Controllers/HermesMiniAppMonitorController.php', 'routes/web.php', 'routes/hermes.php', 'routes/console.php'):
            entry = {'before_sha256': web.current_hash(self.app / relative)}
            if relative not in web.GENERATED:
                entry['after_sha256'] = web.current_hash(self.stage / relative)
            self.expected['files'][relative] = entry
        self.before = self.snapshot()

    @staticmethod
    def write(root, relative, data):
        p = root / relative
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_bytes(data)

    def snapshot(self):
        return {p.relative_to(self.app).as_posix(): (p.read_bytes(), stat.S_IMODE(p.stat().st_mode))
                for p in self.app.rglob('*') if p.is_file()}

    def install(self):
        return web.install(self.stage, self.app, self.expected, self.backup, self.lock)

    def test_default_cli_is_plan_and_does_not_mutate_or_create_lock(self):
        manifest = self.base / 'expected.json'
        manifest.write_text(json.dumps(self.expected))
        completed = subprocess.run([sys.executable, str(SCRIPT), '--stage-dir', str(self.stage), '--app-root', str(self.app), '--expected-manifest', str(manifest)], capture_output=True, text=True, check=True)
        report = json.loads(completed.stdout)
        self.assertEqual('plan', report['status'])
        self.assertFalse(report['activation_performed'])
        self.assertEqual(self.before, self.snapshot())
        self.assertFalse(self.backup.exists())
        self.assertFalse(self.lock.exists())
        self.assertNotIn('app/Http/Controllers/HermesMiniAppController.php', {i['target'] for i in report['items']})

    def test_install_and_generated_private_rollback_restore_exact_bytes_and_modes(self):
        result = self.install()
        self.assertEqual('installed_only', result['status'])
        self.assertEqual(0o700, stat.S_IMODE(self.backup.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE((self.backup / 'manifest.json').stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE((self.app / web.VIEW).stat().st_mode))
        route = (self.app / 'routes/web.php').read_bytes()
        self.assertTrue(route.startswith(self.before['routes/web.php'][0]))
        self.assertEqual(1, route.count(web.WEB_REQUIRE.encode()))
        self.assertEqual(self.before['app/Http/Controllers/HermesMiniAppController.php'][0], (self.app / 'app/Http/Controllers/HermesMiniAppController.php').read_bytes())
        self.assertEqual(1, (self.app / 'routes/console.php').read_bytes().count(b"->name('hermes:support-review')"))
        self.assertIn(b'->withoutOverlapping(45)', (self.app / 'routes/console.php').read_bytes())
        self.assertFalse((self.app / 'infra').exists())
        planned = subprocess.run([sys.executable, str(self.backup / 'rollback.py')], capture_output=True, text=True, check=True)
        self.assertEqual('rollback_plan', json.loads(planned.stdout)['status'])
        self.assertNotEqual(self.before, self.snapshot())
        subprocess.run([sys.executable, str(self.backup / 'rollback.py'), '--install'], capture_output=True, text=True, check=True)
        self.assertEqual(self.before, self.snapshot())

    def test_all_before_hashes_guarded_before_first_target_write(self):
        (self.app / 'routes/console.php').write_text('<?php // concurrent edit')
        prior = self.snapshot()
        with self.assertRaisesRegex(web.Refused, 'live hash changed'):
            self.install()
        self.assertEqual(prior, self.snapshot())
        self.assertFalse(self.backup.exists())

    def test_absent_target_guard_and_source_pin(self):
        relative = 'app/Http/Controllers/HermesMiniAppMonitorController.php'
        self.write(self.app, relative, b'<?php // another deployment')
        with self.assertRaisesRegex(web.Refused, 'live hash changed'):
            web.build_plan(self.stage, self.app, self.expected)
        (self.app / relative).unlink()
        self.write(self.stage, relative, b'<?php // mutated artifact')
        with self.assertRaisesRegex(web.Refused, 'source hash changed'):
            web.build_plan(self.stage, self.app, self.expected)

    def test_protected_concurrent_files_are_not_selectable_even_by_manifest(self):
        self.expected['files']['app/Http/Controllers/HermesMiniAppController.php'] = {'before_sha256': self.before['app/Http/Controllers/HermesMiniAppController.php'][0].hex()}
        with self.assertRaisesRegex(web.Refused, 'unexpected manifest target'):
            self.install()
        self.assertEqual(self.before, self.snapshot())

    def test_noncanonical_duplicate_manifest_destinations_are_rejected(self):
        self.expected['files']['app//Foo.php'] = {'before_sha256': 'absent'}
        with self.assertRaisesRegex(web.Refused, 'unexpected manifest target'):
            self.install()

    def test_additive_patch_is_the_only_permitted_view_change(self):
        self.write(self.stage, web.VIEW, web.miniapp_patch(self.view) + b'<script>changed</script>')
        self.expected['files'][web.VIEW].pop('after_sha256')
        with self.assertRaisesRegex(web.Refused, 'exact additive miniapp patch'):
            self.install()
        self.assertEqual(self.before, self.snapshot())

    def test_preexisting_monitor_or_support_schedule_is_not_duplicated(self):
        for relative, text in [('routes/web.php', "<?php\n" + web.WEB_REQUIRE), ('routes/console.php', "<?php // hermes:support-review")]:
            with self.subTest(relative=relative):
                original = (self.app / relative).read_bytes()
                self.write(self.app, relative, text.encode())
                expected = copy.deepcopy(self.expected)
                expected['files'][relative]['before_sha256'] = web.current_hash(self.app / relative)
                with self.assertRaisesRegex(web.Refused, 'already mentioned'):
                    web.build_plan(self.stage, self.app, expected)
                self.write(self.app, relative, original)

    def test_crlf_php_closing_tag_keeps_existing_bytes_and_appends_inside_php(self):
        old = "<?php\r\nrequire __DIR__.'/hermes.php';\r\n?>\r\n"
        generated = web.generated_routes(old.encode(), b'<?php\r\n?>\r\n', b'<?php\r\n' + web.HERMES_PREFIX.encode() + b'(function () {});\r\n')
        updated = generated['routes/web.php'].decode()
        self.assertTrue(updated.startswith(old.split('?>')[0]))
        self.assertTrue(updated.endswith('?>\r\n'))
        self.assertNotIn('\n', updated.replace('\r\n', ''))
        self.assertIn(web.WEB_REQUIRE, updated)

    def test_audit_prefix_replaces_exactly_one_anchor_and_preserves_remaining_routes(self):
        old = b'<?php\n// before\n' + web.HERMES_PREFIX.encode() + b'(function () { /* unchanged chat */ });\n// after'
        generated = web.generated_routes(b'<?php', b'<?php', old)
        self.assertEqual(old.replace(web.HERMES_PREFIX.encode(), web.HERMES_AUDITED_PREFIX.encode(), 1), generated['routes/hermes.php'])
        for changed in (old + b'\n' + web.HERMES_PREFIX.encode(), old.replace(b'throttle:90,1', b'throttle:80,1')):
            with self.subTest(changed=changed), self.assertRaisesRegex(web.Refused, 'prefix changed'):
                web.generated_routes(b'<?php', b'<?php', changed)

    def test_all_three_view_anchors_must_be_unique(self):
        for anchor in (web.MONITOR_ANCHOR, web.SHOW_ANCHOR, web.REFRESH_ANCHOR):
            for text in (self.view.decode().replace(anchor, ''), self.view.decode() + anchor):
                with self.subTest(anchor=anchor), self.assertRaisesRegex(web.Refused, 'anchor changed'):
                    web.miniapp_patch(text.encode())

    def test_actual_staged_view_matches_exact_patch_and_chat_memory_handlers_are_unchanged(self):
        original = (Path(__file__).resolve().parent / 'fixtures/hermes-before-monitor.blade.php').read_bytes()
        staged = (SCRIPT.parents[2] / 'website' / web.VIEW).read_bytes()
        self.assertEqual(web.miniapp_patch(original), staged)
        original_handlers = original.decode().split("$('chatForm').onsubmit=", 1)[1].split(web.REFRESH_ANCHOR, 1)[0]
        staged_handlers = staged.decode().split("$('chatForm').onsubmit=", 1)[1].split('// Hermes monitoring panel (read-only).', 1)[0]
        self.assertEqual(original_handlers, staged_handlers)

    def test_cached_routes_refuse_install_without_flushing_anything(self):
        self.write(self.app, 'bootstrap/cache/routes-v7.php', b'<?php // existing cache')
        prior = self.snapshot()
        with self.assertRaisesRegex(web.Refused, 'cached routes present'):
            self.install()
        self.assertEqual(prior, self.snapshot())
        self.assertFalse(self.backup.exists())

    def test_symlink_target_source_or_ancestor_refused(self):
        selected = self.stage / 'app/Http/Controllers/HermesMiniAppMonitorController.php'
        saved = selected.read_bytes()
        selected.unlink()
        selected.symlink_to(self.app / 'routes/web.php')
        with self.assertRaisesRegex(web.Refused, 'symlink'):
            self.install()
        selected.unlink(); selected.write_bytes(saved)
        alias = self.base / 'alias'
        alias.symlink_to(self.base, target_is_directory=True)
        with self.assertRaisesRegex(web.Refused, 'symlink'):
            web.build_plan(alias / 'stage', self.app, self.expected)
        with self.assertRaisesRegex(web.Refused, 'symlink'):
            web.install(self.stage, self.app, self.expected, alias / 'backup', self.lock)

    def test_private_backup_cannot_be_in_public_or_application_root(self):
        for path in (self.app / 'backup', self.stage / 'backup', self.app.parent / 'public_html' / 'backup'):
            with self.subTest(path=path), self.assertRaisesRegex(web.Refused, 'outside app'):
                web.install(self.stage, self.app, self.expected, path, self.lock)
        self.assertEqual(self.before, self.snapshot())

    def test_change_after_backups_prevents_every_target_write(self):
        original = web.private_json
        def update_manifest(path, payload):
            original(path, payload)
            if payload['status'] == 'preparing':
                self.write(self.app, 'routes/web.php', b'<?php // concurrent route edit')
        with mock.patch.object(web, 'private_json', side_effect=update_manifest):
            with self.assertRaisesRegex(web.Refused, 'before release'):
                self.install()
        self.assertEqual(self.before[web.VIEW], self.snapshot()[web.VIEW])
        self.assertFalse((self.app / 'routes/hermes-monitor.php').exists())
        self.assertEqual(b'<?php // concurrent route edit', (self.app / 'routes/web.php').read_bytes())

    def test_install_failure_reverts_prior_writes(self):
        atomic = web.atomic_write
        def fail_view(path, data, mode, uid, gid):
            if path == self.app / web.VIEW:
                raise OSError('simulated disk error')
            return atomic(path, data, mode, uid, gid)
        with mock.patch.object(web, 'atomic_write', side_effect=fail_view):
            with self.assertRaisesRegex(OSError, 'simulated disk error'):
                self.install()
        self.assertEqual(self.before, self.snapshot())
        manifest = json.loads((self.backup / 'manifest.json').read_text())
        self.assertEqual('install_failed', manifest['status'])
        self.assertFalse(manifest['rollback_conflicts'])

    def test_newer_edit_blocks_whole_rollback_without_overwrite(self):
        self.install()
        self.write(self.app, 'routes/web.php', b'<?php // newer web edits')
        current = self.snapshot()
        with self.assertRaisesRegex(web.Refused, 'newer live file'):
            web.rollback(self.backup / 'manifest.json', True)
        self.assertEqual(current, self.snapshot())

    def test_interrupted_install_recovers_only_known_after_hashes(self):
        self.install()
        manifest_path = self.backup / 'manifest.json'
        manifest = json.loads(manifest_path.read_text())
        manifest['status'] = 'preparing'
        manifest_path.write_text(json.dumps(manifest))
        # A partially completed release has some files still at the original hash.
        self.write(self.app, web.VIEW, self.view)
        os.chmod(self.app / web.VIEW, 0o640)
        web.rollback(manifest_path, True)
        self.assertEqual(self.before, self.snapshot())

    def test_changed_backup_blocks_rollback(self):
        self.install()
        self.write(self.backup / 'before', 'routes/web.php', b'corrupt backup')
        current = self.snapshot()
        with self.assertRaisesRegex(web.Refused, 'backup is missing or changed'):
            web.rollback(self.backup / 'manifest.json', True)
        self.assertEqual(current, self.snapshot())


if __name__ == '__main__':
    unittest.main()
