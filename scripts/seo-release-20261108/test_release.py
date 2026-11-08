#!/usr/bin/env python3
"""Isolated release guards: synthetic sources only, no framework or network."""
from __future__ import annotations

import copy
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock

_spec = importlib.util.spec_from_file_location('seo_release_under_test', Path(__file__).with_name('release.py'))
core = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(core)


class HunkTests(unittest.TestCase):
    def test_insert_containment_applies_once_and_is_then_present(self):
        hunk = {'old': 'anchor\n', 'new': 'anchor\ninserted\n'}
        value, applied, present = core.apply_hunks(b'prefix\nanchor\n', [hunk])
        self.assertEqual(value, b'prefix\nanchor\ninserted\n')
        self.assertEqual((applied, present), ([0], []))
        self.assertEqual(core.apply_hunks(value, [hunk]), (value, [], [0]))

    def test_delete_containment_applies_once_and_is_then_present(self):
        hunk = {'old': 'anchor\nremove\n', 'new': 'anchor\n'}
        value, applied, present = core.apply_hunks(b'prefix\nanchor\nremove\n', [hunk])
        self.assertEqual(value, b'prefix\nanchor\n')
        self.assertEqual((applied, present), ([0], []))
        self.assertEqual(core.apply_hunks(value, [hunk]), (value, [], [0]))

    def test_duplicate_anchors_fail_instead_of_choosing_an_occurrence(self):
        hunk = {'old': 'old\n', 'new': 'new\n'}
        for value in (b'old\nold\n', b'new\nnew\n', b'old\nnew\n'):
            with self.subTest(value=value), self.assertRaises(core.Stop):
                core.apply_hunks(value, [hunk])

    def test_independent_partial_installation_preserves_unrelated_live_text(self):
        hunks = [{'old': 'old-one\n', 'new': 'new-one\n'},
                 {'old': 'old-two\n', 'new': 'new-two\n'}]
        value, applied, present = core.apply_hunks(
            b'production overlay\nnew-one\nunchanged\nold-two\nlate support copy\n', hunks)
        self.assertEqual(value, b'production overlay\nnew-one\nunchanged\nnew-two\nlate support copy\n')
        self.assertEqual((applied, present), ([1], [0]))

    def test_crlf_roundtrip_keeps_live_line_endings(self):
        value, applied, present = core.apply_hunks(
            b'prefix\r\nold\r\nsuffix\r\n', [{'old': 'old\n', 'new': 'new\n'}])
        self.assertEqual(value, b'prefix\r\nnew\r\nsuffix\r\n')
        self.assertEqual((applied, present), ([0], []))

    def test_mixed_or_binary_sources_are_rejected(self):
        for value in (b'old\r\nother\n', b'old\r', b'old\x00'):
            with self.subTest(value=value), self.assertRaises(core.Stop):
                core.apply_hunks(value, [{'old': 'old', 'new': 'new'}])

    def test_hosting_prefix_insertion_preserves_interleaved_iran_sales_restriction(self):
        prefix = "@extends('layouts.site')\n\n@php\n"
        restriction = "  $countryIso = 'IR';\n  $iranPurchaseRestricted = true;\n  $countrySeo = null;\n"
        addition = "  $hostingSeo = trans('hosting-seo.linux');\n"
        before = (prefix + restriction).encode()
        result, applied, present = core.apply_hunks(before, [{'old': prefix, 'new': prefix + addition}])
        self.assertEqual(result, (prefix + addition + restriction).encode())
        self.assertEqual((applied, present), ([0], []))
        self.assertEqual(core.apply_hunks(result, [{'old': prefix, 'new': prefix + addition}]), (result, [], [0]))


class ReleaseFixture(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='seo-release-guards-')
        self.addCleanup(self.temporary.cleanup)
        self.home = Path(self.temporary.name) / 'home'
        self.home.mkdir(mode=0o700)
        self.stage = self.home / '.seo-release-20261108-test'
        self.new_sources = {'lang/fa/hosting-seo.php', 'public/assets/css/tool-next-step.css'}
        self.baseline = {}
        self.payload = {'version': 1, 'release': core.RELEASE, 'target_commit': 'a' * 40,
                        'home': str(self.home), 'account_uid': os.geteuid(), 'helpers': {}, 'files': []}
        for index, source in enumerate(core.SOURCES):
            destination = core.destination(source)
            target = self.home / destination
            target.parent.mkdir(parents=True, exist_ok=True)
            old = f"<?php\n// {source}\n$marker = 'old-{index}';\n// retained\n".encode()
            desired = old.replace(f'old-{index}'.encode(), f'new-{index}'.encode())
            if source in self.new_sources:
                before = None
                row = {'source': source, 'destination': destination, 'kind': 'new',
                       'content': desired.decode(), 'curated_before_sha256': None,
                       'curated_after_sha256': core.sha(desired)}
            else:
                target.write_bytes(old)
                target.chmod(0o755 if index % 3 == 0 else 0o640)
                before = core.signature(target)
                row = {'source': source, 'destination': destination, 'kind': 'patch',
                       'curated_before_sha256': core.sha(old), 'curated_after_sha256': core.sha(desired),
                       'hunks': [{'old': f"$marker = 'old-{index}';\n",
                                  'new': f"$marker = 'new-{index}';\n"}]}
            self.baseline[source] = (old if before is not None else None, before)
            self.payload['files'].append(row)
        layout = self.home / core.LAYOUT
        layout.parent.mkdir(parents=True, exist_ok=True)
        layout.write_text('\n'.join(core.LAYOUT_MARKERS.values()) + '\n')
        layout.chmod(0o644)
        self.lint_calls = 0

    def fake_lint(self, home, stage, payload, rows, php):
        self.assertEqual(home, self.home)
        self.assertEqual(stage, self.stage)
        self.assertEqual(len(rows), len(core.SOURCES))
        self.assertNotEqual(php, '')
        for row in rows:
            self.assertTrue((stage / row['candidate']).is_file())
        self.lint_calls += 1

    def run_phase(self, phase, **options):
        return core.run(self.home, self.stage, self.payload, core.sha(core.canonical(self.payload)),
                        phase, '/unused/php', lint=self.fake_lint, **options)

    def preview(self):
        return self.run_phase('preview')

    def plan(self):
        return json.loads((self.stage / 'preview.json').read_bytes())

    def changed(self):
        return [row for row in self.plan()['files'] if row['change']]

    def application_state(self):
        return {source: core.signature(self.home / core.destination(source)) for source in core.SOURCES}

    def assert_baseline(self):
        for source, (data, info) in self.baseline.items():
            target = self.home / core.destination(source)
            self.assertEqual(core.signature(target), info, source)
            if data is not None:
                self.assertEqual(target.read_bytes(), data, source)

    def assert_shared_locks_held(self):
        import fcntl
        for name in core.LOCKS:
            with (self.home / name).open('r+b') as handle:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_preview_has_no_application_writes_and_private_backups(self):
        before = self.application_state()
        result = self.preview()
        self.assertEqual(result['application_writes'], 0)
        self.assertFalse(result['framework_bootstrapped'])
        self.assertEqual(result['files'], 34)
        self.assertEqual(self.application_state(), before)
        self.assertEqual(self.lint_calls, 1)
        self.assertEqual(stat.S_IMODE(self.stage.stat().st_mode), 0o700)
        plan = self.plan()
        self.assertTrue(plan['layout_dependency']['read_only'])
        self.assertTrue(all(row['reverse_byte_proof'] for row in plan['files']))
        for row in plan['files']:
            self.assertEqual(stat.S_IMODE((self.stage / row['candidate']).stat().st_mode), 0o600)
            if row['before'] is not None:
                self.assertEqual(stat.S_IMODE((self.stage / row['backup']).stat().st_mode), 0o600)

    def test_apply_verify_and_rollback_preserve_identity_and_absence(self):
        self.preview()
        result = self.run_phase('apply')
        self.assertEqual(result['files_verified'], 34)
        self.assertFalse(result['application_activation'])
        for row in self.plan()['files']:
            self.assertEqual(core.signature(self.home / row['destination']), row['after'])
            if row['before'] is not None:
                for field in ('mode', 'uid', 'gid'):
                    self.assertEqual(row['after'][field], row['before'][field])
        self.assertEqual(self.run_phase('verify')['files_verified'], 34)
        self.assertEqual(self.run_phase('rollback')['status'], 'rolled_back_pending_activation')
        self.assert_baseline()

    def test_public_css_maps_only_to_actual_webroot(self):
        self.preview()
        css = next(row for row in self.plan()['files'] if row['source'].startswith('public/'))
        self.assertEqual(css['destination'], 'public_html/assets/css/tool-next-step.css')
        self.run_phase('apply')
        self.assertTrue((self.home / css['destination']).is_file())
        self.assertFalse((self.home / 'servernet_app/public/assets/css/tool-next-step.css').exists())

    def reviewed_hosting_row(self):
        row = next(row for row in self.payload['files']
                   if row['source'] == 'resources/views/pages/hosting.blade.php')
        row['reviewed_before_sha256'] = row['curated_before_sha256']
        row['reviewed_after_sha256'] = row['curated_after_sha256']
        return row

    def test_reviewed_hosting_override_requires_exact_preimage_and_candidate(self):
        row = self.reviewed_hosting_row()
        self.preview()
        self.run_phase('apply')
        self.assertEqual(core.sha((self.home / row['destination']).read_bytes()), row['reviewed_after_sha256'])
        self.run_phase('rollback')
        self.assert_baseline()

    def test_reviewed_hosting_preimage_rejects_even_unrelated_later_edit(self):
        row = self.reviewed_hosting_row()
        target = self.home / row['destination']
        target.write_bytes(target.read_bytes() + b'\nindependent later work outside the hunk\n')
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'reviewed_preimage_drift:resources/views/pages/hosting.blade.php'):
            self.preview()
        self.assertEqual(self.application_state(), before)
        self.assertFalse((self.stage / 'intent.json').exists())

    def test_reviewed_hosting_candidate_hash_mismatch_stops_preview(self):
        row = self.reviewed_hosting_row()
        row['reviewed_after_sha256'] = '0' * 64
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'reviewed_candidate_drift:resources/views/pages/hosting.blade.php'):
            self.preview()
        self.assertEqual(self.application_state(), before)

    def test_reviewed_preimage_override_cannot_expand_to_other_source(self):
        row = next(row for row in self.payload['files'] if row['source'] == 'config/hosting.php')
        row['reviewed_before_sha256'] = row['curated_before_sha256']
        row['reviewed_after_sha256'] = row['curated_after_sha256']
        with self.assertRaisesRegex(core.Stop, 'reviewed_preimage_override_scope_invalid'):
            self.preview()
        self.assert_baseline()

    def test_live_crlf_and_unrelated_support_copy_survive_installation(self):
        source = 'resources/views/pages/gpu.blade.php'
        target = self.home / core.destination(source)
        original = target.read_bytes().replace(b'\n', b'\r\n')
        target.write_bytes(b'// new production support copy\r\n' + original)
        self.preview()
        self.run_phase('apply')
        actual = target.read_bytes()
        self.assertTrue(actual.startswith(b'// new production support copy\r\n'))
        self.assertNotIn(b'\n', actual.replace(b'\r\n', b''))

    def test_exact_existing_new_dependency_is_preserved_as_noop(self):
        spec = next(row for row in self.payload['files'] if row['kind'] == 'new')
        target = self.home / spec['destination']
        target.write_bytes(spec['content'].encode().replace(b'\n', b'\r\n'))
        target.chmod(0o755)
        before = core.signature(target)
        self.preview()
        row = next(row for row in self.plan()['files'] if row['source'] == spec['source'])
        self.assertFalse(row['change'])
        self.run_phase('apply')
        self.assertEqual(core.signature(target), before)

    def test_unexpected_existing_new_dependency_stops_without_application_writes(self):
        spec = next(row for row in self.payload['files'] if row['kind'] == 'new')
        target = self.home / spec['destination']
        target.write_bytes(b'unknown existing customer-safe data\n')
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'unexpected_existing_dependency'):
            self.preview()
        self.assertEqual(self.application_state(), before)

    def test_unknown_newer_live_source_after_preview_stops_before_first_write(self):
        self.preview()
        row = self.changed()[0]
        target = self.home / row['destination']
        target.write_bytes(b'newer independent edit\n')
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'live_identity_drift'):
            self.run_phase('apply')
        self.assertEqual(self.application_state(), before)
        self.assertFalse((self.stage / 'intent.json').exists())

    def test_candidate_and_backup_drift_stop_before_application_writes(self):
        for artifact in ('candidate', 'backup'):
            with self.subTest(artifact=artifact):
                if self.stage.exists():
                    self.stage = self.home / ('.seo-release-20261108-test-' + artifact)
                self.preview()
                row = next(row for row in self.plan()['files'] if row['before'] is not None)
                (self.stage / row[artifact]).write_bytes(b'tampered private snapshot\n')
                before = self.application_state()
                with self.assertRaisesRegex(core.Stop, artifact + '_snapshot_drift'):
                    self.run_phase('apply')
                self.assertEqual(self.application_state(), before)

    def test_private_snapshot_permission_drift_stops_before_application_writes(self):
        self.preview()
        row = self.changed()[0]
        (self.stage / row['candidate']).chmod(0o644)
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'private_snapshot_identity_invalid'):
            self.run_phase('apply')
        self.assertEqual(self.application_state(), before)

    def test_cached_config_or_routes_require_separate_activation_plan(self):
        cache = self.home / 'servernet_app/bootstrap/cache'
        cache.mkdir(parents=True)
        for name in ('config.php', 'routes-v7.php'):
            with self.subTest(name=name):
                target = cache / name
                target.write_bytes(b'<?php return [];')
                with self.assertRaisesRegex(core.Stop, 'config_or_route_cache_requires_reviewed_activation'):
                    self.preview()
                target.unlink()
        self.assert_baseline()

    def test_mode_change_after_preview_is_protected(self):
        self.preview()
        row = next(row for row in self.plan()['files'] if row['before'] is not None)
        target = self.home / row['destination']
        target.chmod(row['before']['mode'] ^ 0o100)
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'live_identity_drift'):
            self.run_phase('apply')
        self.assertEqual(self.application_state(), before)

    def test_uid_and_gid_are_part_of_live_guard(self):
        self.preview()
        row = next(row for row in self.plan()['files'] if row['before'] is not None)
        for field in ('uid', 'gid'):
            altered = copy.deepcopy(row)
            altered['before'][field] += 1
            with self.subTest(field=field), self.assertRaisesRegex(core.Stop, 'live_identity_drift'):
                core.guard(self.home, altered)

    def test_layout_change_is_detected_without_layout_replacement(self):
        self.preview()
        target = self.home / core.LAYOUT
        target.write_bytes(target.read_bytes() + b'new unrelated layout edit\n')
        before = target.read_bytes()
        with self.assertRaisesRegex(core.Stop, 'layout_changed_after_preview'):
            self.run_phase('apply')
        self.assertEqual(target.read_bytes(), before)
        self.assert_baseline()

    def test_missing_required_layout_marker_stops_preview(self):
        target = self.home / core.LAYOUT
        value = target.read_text().replace(core.LAYOUT_MARKERS['noindex_section'], '')
        target.write_text(value)
        with self.assertRaisesRegex(core.Stop, 'layout_dependency_requires_review'):
            self.preview()
        self.assert_baseline()

    def test_optional_public_style_marker_is_reported_without_layout_edit(self):
        target = self.home / core.LAYOUT
        target.write_text(target.read_text().replace(core.LAYOUT_MARKERS['public_style_service'], ''))
        before = target.read_bytes()
        result = self.preview()
        self.assertFalse(result['layout_dependency']['markers']['public_style_service'])
        self.assertEqual(target.read_bytes(), before)

    def test_only_reviewed_absent_public_styles_dependency_can_be_created(self):
        source = 'app/Services/PublicPageStyles.php'
        spec = next(row for row in self.payload['files'] if row['source'] == source)
        target = self.home / spec['destination']
        desired = target.read_bytes()
        for hunk in spec['hunks']:
            desired = desired.replace(hunk['old'].encode(), hunk['new'].encode())
        spec['absent_dependency_content'] = desired.decode()
        spec['reviewed_tree_sha256'] = core.sha(desired)
        target.unlink()
        self.baseline[source] = (None, None)
        self.preview()
        self.run_phase('apply')
        self.assertEqual(target.read_bytes(), desired)
        self.run_phase('rollback')
        self.assert_baseline()

    def test_absent_dependency_override_outside_reviewed_source_is_rejected(self):
        spec = next(row for row in self.payload['files'] if row['source'] == 'routes/web.php')
        spec['absent_dependency_content'] = 'arbitrary route replacement'
        spec['reviewed_tree_sha256'] = core.sha(spec['absent_dependency_content'].encode())
        with self.assertRaisesRegex(core.Stop, 'absent_dependency_scope_invalid'):
            self.preview()
        self.assert_baseline()

    def test_lock_is_held_across_every_write_and_final_source_verification(self):
        self.preview()
        calls = []
        ordinary_verify = core.verify_locked

        def observed_verify(*args):
            self.assert_shared_locks_held()
            calls.append('verify')
            return ordinary_verify(*args)

        def observed_writer(*args):
            self.assert_shared_locks_held()
            calls.append('write')
            return core.atomic(*args)

        with mock.patch.object(core, 'verify_locked', side_effect=observed_verify):
            self.run_phase('apply', writer=observed_writer)
        self.assertIn('write', calls)
        self.assertEqual(calls[-1], 'verify')

    def test_existing_shared_lock_prevents_preview(self):
        import fcntl
        with (self.home / core.LOCKS[1]).open('a+b') as handle:
            os.chmod(self.home / core.LOCKS[1], 0o600)
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(core.Stop, 'shared_release_lock_busy'):
                self.preview()
        self.assert_baseline()

    def test_apply_and_preview_are_single_use(self):
        self.preview()
        with self.assertRaisesRegex(core.Stop, 'preview_already_attempted'):
            self.preview()
        self.run_phase('apply')
        installed = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'apply_already_attempted'):
            self.run_phase('apply')
        self.assertEqual(self.application_state(), installed)
        self.run_phase('rollback')
        with self.assertRaisesRegex(core.Stop, 'rollback_not_available'):
            self.run_phase('rollback')

    def test_partial_write_failure_rolls_back_only_release_changes(self):
        self.preview()
        count = 0

        def failing_writer(*args):
            nonlocal count
            count += 1
            if count == 2:
                raise OSError('synthetic disk failure')
            return core.atomic(*args)

        with self.assertRaises(OSError):
            self.run_phase('apply', writer=failing_writer)
        self.assert_baseline()
        failure = json.loads((self.stage / 'failure.json').read_bytes())
        self.assertEqual(failure['status'], 'failed_guarded_rollback_complete')
        with self.assertRaisesRegex(core.Stop, 'apply_already_attempted'):
            self.run_phase('apply')

    def test_partial_failure_preserves_unknown_later_work_and_refuses_rollback(self):
        self.preview()
        first = self.changed()[0]
        first_target = self.home / first['destination']
        count = 0

        def concurrent_writer(*args):
            nonlocal count
            count += 1
            if count == 2:
                first_target.write_bytes(b'newer independent work after first write\n')
                raise OSError('synthetic concurrent failure')
            return core.atomic(*args)

        with self.assertRaises(OSError):
            self.run_phase('apply', writer=concurrent_writer)
        self.assertEqual(first_target.read_bytes(), b'newer independent work after first write\n')
        failure = json.loads((self.stage / 'failure.json').read_bytes())
        self.assertEqual(failure['status'], 'stopped_rollback_refused_preserve_later_work')
        before = self.application_state()
        with self.assertRaisesRegex(core.Stop, 'newer_work_prevents_rollback'):
            self.run_phase('rollback')
        self.assertEqual(self.application_state(), before)

    def test_source_symlink_is_rejected_before_application_writes(self):
        source = next(source for source in core.SOURCES if source not in self.new_sources)
        target = self.home / core.destination(source)
        old = target.read_bytes()
        other = self.home / 'unrelated.txt'
        other.write_bytes(old)
        target.unlink()
        target.symlink_to(other)
        with self.assertRaisesRegex(core.Stop, 'symlink_in_path'):
            self.preview()
        self.assertEqual(other.read_bytes(), old)

    def run_session(self, commands):
        ordinary_run = core.run
        output = io.StringIO()
        calls = []

        def observed_run(*args, **kwargs):
            self.assert_shared_locks_held()
            calls.append(args[4])
            return ordinary_run(*args, lint=self.fake_lint, **kwargs)

        with mock.patch.object(core.sys, 'stdin', io.StringIO(commands)), \
                mock.patch.object(core, 'run', side_effect=observed_run), \
                contextlib.redirect_stdout(output):
            exit_code = core.session(self.home, self.stage, self.payload,
                                     core.sha(core.canonical(self.payload)), '/unused/php')
        import fcntl
        for name in core.LOCKS:
            with (self.home / name).open('r+b') as handle:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                fcntl.flock(handle, fcntl.LOCK_UN)
        return exit_code, [json.loads(line) for line in output.getvalue().splitlines()], calls

    def test_session_retains_locks_and_finish_requires_successful_apply(self):
        exit_code, messages, calls = self.run_session('finish\npreview\nfinish\napply\nfinish\n')
        self.assertEqual(exit_code, 0)
        stops = [row for row in messages if row['status'] == 'stopped']
        self.assertEqual([row['reason'] for row in stops], ['successful_apply_required'] * 2)
        self.assertEqual(calls, ['preview', 'apply', 'verify'])
        self.assertEqual(messages[-1]['status'], 'finished')
        self.assertEqual(messages[-1]['locks_held'], 3)
        self.assertTrue((self.stage / 'finished.json').is_file())

    def test_session_eof_after_apply_does_not_claim_acceptance(self):
        exit_code, messages, calls = self.run_session('preview\napply\n')
        self.assertEqual(exit_code, 2)
        self.assertEqual(calls, ['preview', 'apply'])
        self.assertEqual(messages[-1]['status'], 'session_disconnected_pending_review')
        self.assertTrue(messages[-1]['installed'])
        self.assertFalse((self.stage / 'finished.json').exists())

    def test_session_abort_after_apply_is_refused_then_guarded_rollback_finishes(self):
        exit_code, messages, calls = self.run_session('preview\napply\nabort\nrollback\nfinish\n')
        self.assertEqual(exit_code, 0)
        self.assertEqual(calls, ['preview', 'apply', 'rollback', 'verify'])
        self.assertEqual(messages[-3]['reason'], 'installed_release_requires_finish_or_rollback')
        self.assertEqual(messages[-1]['status'], 'rollback_finished')
        self.assert_baseline()

    def test_session_rollback_retains_locks_through_activation_and_baseline_verification(self):
        ordinary_run = core.run
        output = io.StringIO()
        events = []

        def observed_run(*args, **kwargs):
            self.assert_shared_locks_held()
            result = ordinary_run(*args, lint=self.fake_lint, **kwargs)
            events.append(args[4])
            if args[4] == 'rollback':
                self.assert_baseline()
                self.assertFalse((self.stage / 'rollback-finished.json').exists())
            return result

        def commands():
            yield 'preview\n'
            yield 'apply\n'
            yield 'rollback\n'
            # Simulate the operator doing targeted rollback activation while
            # no command is executing. Every shared lock must remain held.
            self.assert_shared_locks_held()
            self.assert_baseline()
            events.append('rollback_activation')
            yield 'verify\n'
            yield 'finish\n'

        with mock.patch.object(core.sys, 'stdin', commands()), \
                mock.patch.object(core, 'run', side_effect=observed_run), \
                contextlib.redirect_stdout(output):
            exit_code = core.session(self.home, self.stage, self.payload,
                                     core.sha(core.canonical(self.payload)), '/unused/php')
        self.assertEqual(exit_code, 0)
        self.assertEqual(events, ['preview', 'apply', 'rollback', 'rollback_activation', 'verify', 'verify'])
        messages = [json.loads(line) for line in output.getvalue().splitlines()]
        self.assertEqual(messages[-1]['status'], 'rollback_finished')
        self.assertTrue(messages[-1]['baseline_verified'])
        self.assertFalse((self.stage / 'finished.json').exists())
        receipt = json.loads((self.stage / 'rollback-finished.json').read_bytes())
        self.assertEqual(receipt['status'], 'baseline_verified_operator_rollback_acceptance_complete')
        self.assertEqual(receipt['rollback_activation_and_http_acceptance'], 'operator_confirmed')

    def test_session_disconnect_after_rollback_does_not_claim_acceptance(self):
        exit_code, messages, _ = self.run_session('preview\napply\nrollback\n')
        self.assertEqual(exit_code, 2)
        self.assertFalse(messages[-1]['installed'])
        self.assertTrue(messages[-1]['rollback_pending_acceptance'])
        self.assertFalse((self.stage / 'rollback-finished.json').exists())
        self.assert_baseline()

    def test_finish_after_rollback_protects_unknown_newer_baseline_work(self):
        output = io.StringIO()
        ordinary_run = core.run

        def observed_run(*args, **kwargs):
            return ordinary_run(*args, lint=self.fake_lint, **kwargs)

        def commands():
            yield 'preview\n'
            yield 'apply\n'
            yield 'rollback\n'
            source = next(source for source in core.SOURCES if source not in self.new_sources)
            (self.home / core.destination(source)).write_bytes(b'newer work after rollback\n')
            yield 'finish\n'
            self.assert_shared_locks_held()
            self.assertFalse((self.stage / 'rollback-finished.json').exists())

        with mock.patch.object(core.sys, 'stdin', commands()), \
                mock.patch.object(core, 'run', side_effect=observed_run), \
                contextlib.redirect_stdout(output):
            exit_code = core.session(self.home, self.stage, self.payload,
                                     core.sha(core.canonical(self.payload)), '/unused/php')
        self.assertEqual(exit_code, 2)
        messages = [json.loads(line) for line in output.getvalue().splitlines()]
        self.assertTrue(any(row['status'] == 'stopped' and row['reason'].startswith('live_identity_drift:')
                            for row in messages))


if __name__ == '__main__':
    unittest.main(verbosity=2)
