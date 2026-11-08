#!/usr/bin/env python3
"""Private, scoped SEO source installer. Never boot Laravel or activate caches."""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

RELEASE = 'seo-iran-release-20261108'
LOCKS = ('.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock')
SOURCES = (
    'app/Http/Controllers/CountryVpsController.php',
    'app/Http/Controllers/SiteController.php',
    'app/Services/Cloud/CountryLandingSeo.php',
    'app/Services/Cloud/CountryVpsEligibility.php',
    'lang/en/country-seo.php',
    'lang/fa/country-seo.php',
    'lang/tr/country-seo.php',
    'resources/views/pages/vps-country.blade.php',
    'resources/views/partials/vps-plan-table.blade.php',
)
LAYOUT = 'servernet_app/resources/views/layouts/site.blade.php'
LAYOUT_MARKERS = {
    'noindex_section': "@hasSection('noindex')",
    'noindex_meta': 'content="noindex,follow"',
    'public_style_service': 'PublicPageStyles',
    'canonical': 'rel="canonical"',
    'hreflang': 'hreflang=',
}


class Stop(RuntimeError):
    """Public errors contain only fixed reason codes and approved relative paths."""


def insist(condition, reason):
    if not condition:
        raise Stop(reason)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical(data) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()


def now():
    return datetime.now(timezone.utc).isoformat()


def destination(source: str) -> str:
    insist(source in SOURCES, 'source_outside_scope')
    return ('public_html/' + source[7:]) if source.startswith('public/') else 'servernet_app/' + source


def real_directory(path: Path):
    insist(path.is_absolute() and path.exists() and path.is_dir()
           and not path.is_symlink() and path.resolve() == path, 'directory_identity_invalid')


def safe_path(root: Path, relative: str) -> Path:
    insist(relative and not relative.startswith('/') and '..' not in Path(relative).parts,
           'unsafe_relative_path')
    path = root / relative
    cursor = path
    while cursor != root:
        insist(not cursor.is_symlink(), 'symlink_in_path')
        cursor = cursor.parent
    insist(not path.exists() or path.is_file(), 'nonregular_file')
    return path


def signature(path: Path):
    if not path.exists():
        return None
    insist(not path.is_symlink() and path.is_file(), 'file_identity_invalid')
    metadata = path.stat()
    return {'sha256': sha(path.read_bytes()), 'mode': stat.S_IMODE(metadata.st_mode),
            'uid': metadata.st_uid, 'gid': metadata.st_gid, 'bytes': metadata.st_size}


def normalized(data: bytes) -> tuple[bytes, bytes]:
    value = data.replace(b'\r\n', b'\n')
    insist(b'\r' not in value and (b'\r\n' not in data or data.count(b'\r\n') == data.count(b'\n')),
           'mixed_line_endings')
    insist(b'\0' not in value, 'binary_source_rejected')
    return value, b'\r\n' if b'\r\n' in data else b'\n'


def apply_hunks(data: bytes, hunks: list[dict]) -> tuple[bytes, list[int], list[int]]:
    """Apply exact independent hunks; skip one exact installed hunk, never guess."""
    original, ending = normalized(data)
    value = original
    applied, present = [], []
    for index, hunk in enumerate(hunks):
        old, new = hunk['old'].encode(), hunk['new'].encode()
        insist(old and new and old != new, 'empty_or_noop_hunk')
        old_count, new_count = value.count(old), value.count(new)
        if new_count == 1 and old_count == 0:
            present.append(index)
            continue
        if old_count == 1 and new_count == 1:
            old_at, new_at = value.index(old), value.index(new)
            # Insertions at EOF may retain the entire old anchor inside new.
            if old in new and new_at <= old_at and old_at + len(old) <= new_at + len(new):
                present.append(index)
                continue
            # Deletions may retain the entire new anchor inside old.
            insist(new in old and old_at <= new_at and new_at + len(new) <= old_at + len(old),
                   'ambiguous_hunk_state')
        else:
            insist(old_count == 1 and new_count == 0, 'hunk_anchor_missing_or_duplicate')
        value = value.replace(old, new, 1)
        applied.append(index)
    restored = value
    for index in reversed(applied):
        hunk = hunks[index]
        new, old = hunk['new'].encode(), hunk['old'].encode()
        insist(restored.count(new) == 1, 'reverse_hunk_anchor_not_unique')
        restored = restored.replace(new, old, 1)
    insist(restored == original, 'reverse_byte_proof_failed')
    return (value.replace(b'\n', ending) if ending != b'\n' else value), applied, present


def validate_payload(payload: dict):
    insist(payload.get('version') == 1 and payload.get('release') == RELEASE, 'payload_identity_invalid')
    insist(re.fullmatch('[0-9a-f]{40}', payload.get('target_commit', '')), 'target_commit_invalid')
    rows = payload.get('files')
    insist(isinstance(rows, list) and len(rows) == len(SOURCES), 'payload_file_count_invalid')
    insist(set(row.get('source') for row in rows) == set(SOURCES), 'payload_scope_invalid')
    insist(len({row['source'] for row in rows}) == len(rows), 'payload_duplicate_path')
    for row in rows:
        insist(row.get('destination') == destination(row['source']), 'payload_destination_invalid')
        if row['kind'] == 'new':
            data = row['content'].encode()
            insist(sha(data) == row['curated_after_sha256'] and row['curated_before_sha256'] is None,
                   'new_source_hash_invalid')
        else:
            insist(row['kind'] == 'patch' and row.get('hunks'), 'payload_patch_invalid')
            for hunk in row['hunks']:
                insist(set(hunk) == {'old', 'new'} and hunk['old'] and hunk['new']
                       and hunk['old'] != hunk['new'], 'payload_hunk_invalid')
        insist(re.fullmatch('[0-9a-f]{64}', row['curated_after_sha256']), 'source_hash_invalid')
        if 'absent_dependency_content' in row:
            insist(row['source'] == 'app/Services/PublicPageStyles.php'
                   and sha(row['absent_dependency_content'].encode()) == row['reviewed_tree_sha256'],
                   'absent_dependency_scope_invalid')
        if 'reviewed_before_sha256' in row or 'reviewed_after_sha256' in row:
            insist(row['kind'] == 'patch' and row['source'] == 'resources/views/pages/hosting.blade.php'
                   and re.fullmatch('[0-9a-f]{64}', row.get('reviewed_before_sha256', ''))
                   and re.fullmatch('[0-9a-f]{64}', row.get('reviewed_after_sha256', '')),
                   'reviewed_preimage_override_scope_invalid')


@contextlib.contextmanager
def locks(home: Path, uid: int):
    handles = []
    try:
        for name in LOCKS:
            fd = os.open(home / name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            handle = os.fdopen(fd, 'a+b')
            handles.append(handle)
            info = os.fstat(fd)
            insist(stat.S_ISREG(info.st_mode) and info.st_uid == uid
                   and not stat.S_IMODE(info.st_mode) & 0o022, 'release_lock_identity_invalid')
            import fcntl
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise Stop('shared_release_lock_busy') from exc
        yield
    finally:
        for handle in reversed(handles):
            handle.close()


def fsync_directory(path: Path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


_UNSPECIFIED = object()


def atomic(path: Path, data: bytes, mode: int, uid: int, gid: int, expected=_UNSPECIFIED):
    insist(path.parent.is_dir() and path.parent.resolve() == path.parent and not path.is_symlink(),
           'write_parent_invalid')
    fd, temporary = tempfile.mkstemp(prefix='.seo-staged-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            os.fchown(stream.fileno(), uid, gid)
            os.fchmod(stream.fileno(), mode)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        if expected is not _UNSPECIFIED:
            insist(signature(path) == expected, 'live_changed_before_atomic_replace')
        os.replace(temporary, path)
        fsync_directory(path.parent)
        insist(sha(path.read_bytes()) == sha(data), 'write_hash_mismatch')
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def private_write(stage: Path, relative: str, data: bytes):
    path = safe_path(stage, relative)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    insist(not path.exists(), 'private_snapshot_already_exists')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    fsync_directory(path.parent)


def private_read(stage: Path, relative: str) -> bytes:
    path = safe_path(stage, relative)
    insist(path.is_file() and path.stat().st_uid == os.geteuid()
           and stat.S_IMODE(path.stat().st_mode) == 0o600, 'private_snapshot_identity_invalid')
    cursor = path.parent
    while cursor != stage:
        insist(cursor.stat().st_uid == os.geteuid() and stat.S_IMODE(cursor.stat().st_mode) == 0o700,
               'private_snapshot_directory_invalid')
        cursor = cursor.parent
    return path.read_bytes()


def record(stage: Path, name: str, data: dict):
    private_write(stage, name, canonical(data) + b'\n')


def layout_report(home: Path) -> dict:
    path = safe_path(home, LAYOUT)
    insist(path.is_file(), 'layout_dependency_missing')
    data = path.read_bytes()
    markers = {key: value.encode() in data for key, value in LAYOUT_MARKERS.items()}
    return {'path': LAYOUT, 'signature': signature(path), 'markers': markers,
            'full_bundle_style_optimization_present': markers['public_style_service'], 'read_only': True}


def no_cached_activation(home: Path):
    cache = home / 'servernet_app/bootstrap/cache'
    insist(not (cache / 'config.php').exists() and not list(cache.glob('routes-*.php')),
           'config_or_route_cache_requires_reviewed_activation')


def lint_candidates(home: Path, stage: Path, payload: dict, rows: list[dict], php: str):
    helper = Path(__file__).with_name('compile-blade.php')
    insist(sha(helper.read_bytes()) == payload['helpers']['compile-blade.php'], 'compile_helper_drift')
    for index, row in enumerate(rows):
        candidate = safe_path(stage, row['candidate'])
        if row['source'].endswith('.php'):
            result = subprocess.run([php, '-l', str(candidate)], capture_output=True, timeout=30)
            insist(result.returncode == 0, 'php_candidate_lint_failed:' + row['source'])
        if row['source'].endswith('.blade.php'):
            output = stage / ('compiled-' + str(index) + '.php')
            result = subprocess.run([php, str(helper), str(home / 'servernet_app'), str(candidate),
                                     str(output), str(home / row['destination'])],
                                    capture_output=True, timeout=30)
            insist(result.returncode == 0, 'blade_compile_failed:' + row['source'])
            result = subprocess.run([php, '-l', str(output)], capture_output=True, timeout=30)
            insist(result.returncode == 0, 'compiled_blade_lint_failed:' + row['source'])


def order(rows):
    def rank(row):
        source = row['source']
        if row['kind'] == 'new' or row.get('before') is None:
            return (0 if source.startswith(('app/', 'lang/')) else 1, source)
        if source.startswith(('app/Services/', 'lang/', 'config/')):
            return (2, source)
        if source.startswith('resources/views/partials/'):
            return (3, source)
        if source.startswith('resources/views/'):
            return (4, source)
        if source == 'routes/web.php':
            return (6, source)
        return (5, source)
    return sorted(rows, key=rank)


def guard(home: Path, row: dict, installed=False):
    path = safe_path(home, row['destination'])
    expected = row['after'] if installed else row['before']
    insist(signature(path) == expected, 'live_identity_drift:' + row['source'])


def verify_locked(home: Path, stage: Path, payload: dict, preview: dict):
    for row in preview['files']:
        insist(sha(private_read(stage, row['candidate'])) == row['after']['sha256'],
               'candidate_snapshot_drift')
        if row['before'] is not None:
            insist(sha(private_read(stage, row['backup'])) == row['before']['sha256'],
                   'backup_snapshot_drift')
        guard(home, row, True)
    insist(layout_report(home) == preview['layout_dependency'], 'layout_dependency_drift')
    return {'release': RELEASE, 'target_commit': payload['target_commit'],
            'status': 'installed_source_verified_pending_activation_and_http',
            'files_verified': len(preview['files']),
            'files_changed': sum(row['change'] for row in preview['files']),
            'framework_bootstrapped': False, 'application_activation': False}


def verify_restored_locked(home: Path, stage: Path, payload: dict, preview: dict, preview_sha: str):
    rollback = json.loads(private_read(stage, 'rollback.json'))
    insist(rollback.get('release') == RELEASE
           and rollback.get('status') == 'rolled_back_pending_activation'
           and rollback.get('preview_sha256') == preview_sha, 'rollback_receipt_invalid')
    for row in preview['files']:
        if row['before'] is not None:
            insist(sha(private_read(stage, row['backup'])) == row['before']['sha256'],
                   'backup_snapshot_drift')
        guard(home, row)
    insist(layout_report(home) == preview['layout_dependency'], 'layout_dependency_drift')
    return {'release': RELEASE, 'target_commit': payload['target_commit'],
            'status': 'restored_source_verified_pending_activation_and_http',
            'files_verified': len(preview['files']), 'baseline_verified': True,
            'framework_bootstrapped': False, 'application_activation': False}


def restore_locked(home: Path, stage: Path, preview: dict):
    changed = [row for row in preview['files'] if row['change']]
    # A partial apply may still have baseline files. Unknown edits stop all writes.
    for row in changed:
        current = signature(safe_path(home, row['destination']))
        insist(current in (row['before'], row['after']), 'newer_work_prevents_rollback:' + row['source'])
        if row['before'] is not None:
            insist(sha(private_read(stage, row['backup'])) == row['before']['sha256'],
                   'backup_snapshot_drift')
    restored = 0
    for row in reversed(changed):
        target = safe_path(home, row['destination'])
        current = signature(target)
        insist(current in (row['before'], row['after']), 'rollback_race:' + row['source'])
        if current == row['before']:
            continue
        if row['before'] is None:
            target.unlink()
            fsync_directory(target.parent)
        else:
            previous = row['before']
            atomic(target, private_read(stage, row['backup']),
                   previous['mode'], previous['uid'], previous['gid'], expected=row['after'])
        insist(signature(target) == row['before'], 'rollback_identity_mismatch')
        restored += 1
    return restored


def run(home: Path, stage: Path, payload: dict, payload_sha: str, phase: str,
        php: str, lint=lint_candidates, lock_context=locks, writer=atomic, preview_sha=None):
    validate_payload(payload)
    insist(phase in ('preview', 'apply', 'verify', 'rollback'), 'phase_invalid')
    insist(str(home) == payload['home'] and os.geteuid() == payload['account_uid'], 'account_identity_invalid')
    real_directory(home)
    insist(home.stat().st_uid == os.geteuid(), 'home_owner_invalid')
    insist(stage.parent == home and re.fullmatch(r'\.seo-iran-release-20261108-[a-z0-9-]+', stage.name),
           'stage_outside_private_home')
    uid = os.geteuid()
    with lock_context(home, uid):
        if phase == 'preview':
            if not stage.exists():
                stage.mkdir(mode=0o700)
            real_directory(stage)
            insist(stage.stat().st_uid == uid and stat.S_IMODE(stage.stat().st_mode) == 0o700,
                   'private_stage_identity_invalid')
            insist(not any((stage / name).exists() for name in ('preview.json', 'intent.json', 'before', 'candidate')),
                   'preview_already_attempted')
            no_cached_activation(home)
            layout = layout_report(home)
            # The live layout already uses external CSS and need not call the
            # optional full-bundle optimizer for this scoped release to work.
            insist(all(value for key, value in layout['markers'].items() if key != 'public_style_service'),
                   'layout_dependency_requires_review')
            layout['full_bundle_style_optimization_present'] = layout['markers']['public_style_service']
            rows = []
            for index, spec in enumerate(payload['files']):
                target = safe_path(home, spec['destination'])
                real_directory(target.parent)
                before = signature(target)
                insist(before is None or before['uid'] == uid, 'source_owner_invalid:' + spec['source'])
                data = target.read_bytes() if before is not None else None
                if 'reviewed_before_sha256' in spec:
                    insist(before is not None and before['sha256'] == spec['reviewed_before_sha256'],
                           'reviewed_preimage_drift:' + spec['source'])
                if spec['kind'] == 'new':
                    desired = spec['content'].encode()
                    insist(data is None or normalized(data)[0] == desired,
                           'unexpected_existing_dependency:' + spec['source'])
                    candidate, applied, present = data if data is not None else desired, [], []
                else:
                    if data is None and 'absent_dependency_content' in spec:
                        candidate, applied, present = spec['absent_dependency_content'].encode(), [], []
                    else:
                        insist(data is not None, 'required_source_missing:' + spec['source'])
                        try:
                            candidate, applied, present = apply_hunks(data, spec['hunks'])
                        except Stop as error:
                            raise Stop(str(error) + ':' + spec['source']) from error
                if 'reviewed_after_sha256' in spec:
                    insist(sha(candidate) == spec['reviewed_after_sha256'],
                           'reviewed_candidate_drift:' + spec['source'])
                backup = 'before/' + str(index)
                saved = 'candidate/' + str(index)
                if data is not None:
                    private_write(stage, backup, data)
                private_write(stage, saved, candidate)
                info = before or {'mode': 0o644, 'uid': uid, 'gid': target.parent.stat().st_gid}
                after = {'sha256': sha(candidate), 'mode': info['mode'], 'uid': info['uid'],
                         'gid': info['gid'], 'bytes': len(candidate)}
                rows.append({'source': spec['source'], 'destination': spec['destination'], 'kind': spec['kind'],
                             'before': before, 'after': after, 'candidate': saved, 'backup': backup,
                             'applied_hunks': applied, 'already_present_hunks': present,
                             'change': data != candidate, 'reverse_byte_proof': True})
            lint(home, stage, payload, rows, php)
            for row in rows:
                guard(home, row)
            current_layout = layout_report(home)
            current_layout['full_bundle_style_optimization_present'] = current_layout['markers']['public_style_service']
            insist(current_layout == layout, 'layout_changed_during_preview')
            preview = {'release': RELEASE, 'payload_sha256': payload_sha, 'at': now(),
                       'layout_dependency': layout, 'files': order(rows)}
            record(stage, 'preview.json', preview)
            return {'release': RELEASE, 'status': 'preview', 'application_writes': 0,
                    'payload_sha256': payload_sha, 'preview_sha256': sha((stage / 'preview.json').read_bytes()),
                    'files': len(rows), 'files_changed': sum(row['change'] for row in rows),
                    'hunks_applied': sum(len(row['applied_hunks']) for row in rows),
                    'hunks_already_present': sum(len(row['already_present_hunks']) for row in rows),
                    'layout_dependency': layout, 'candidate_lint': True, 'framework_bootstrapped': False}
        real_directory(stage)
        insist(stage.stat().st_uid == uid and stat.S_IMODE(stage.stat().st_mode) == 0o700,
               'private_stage_identity_invalid')
        preview_bytes = private_read(stage, 'preview.json')
        if preview_sha is not None:
            insist(sha(preview_bytes) == preview_sha, 'preview_hash_mismatch')
        preview = json.loads(preview_bytes)
        insist(preview['release'] == RELEASE and preview['payload_sha256'] == payload_sha,
               'preview_identity_invalid')
        insist({row['source'] for row in preview['files']} == set(SOURCES)
               and len(preview['files']) == len(SOURCES)
               and all(row['destination'] == destination(row['source']) for row in preview['files']),
               'preview_scope_invalid')
        # Snapshot identity is checked for every action before application writes.
        for row in preview['files']:
            insist(sha(private_read(stage, row['candidate'])) == row['after']['sha256'],
                   'candidate_snapshot_drift')
            if row['before'] is not None:
                insist(sha(private_read(stage, row['backup'])) == row['before']['sha256'],
                       'backup_snapshot_drift')
        if phase == 'rollback':
            intent = json.loads(private_read(stage, 'intent.json'))
            insist(intent['preview_sha256'] == sha(preview_bytes) and not (stage / 'rollback.json').exists(),
                   'rollback_not_available')
            count = restore_locked(home, stage, preview)
            record(stage, 'rollback.json', {'release': RELEASE, 'at': now(), 'status': 'rolled_back_pending_activation',
                                           'preview_sha256': sha(preview_bytes), 'files_restored': count})
            return {'release': RELEASE, 'status': 'rolled_back_pending_activation', 'files_restored': count}
        if phase == 'verify':
            intent = json.loads(private_read(stage, 'intent.json'))
            insist(intent['preview_sha256'] == sha(preview_bytes), 'install_intent_invalid')
            if (stage / 'rollback.json').exists():
                return verify_restored_locked(home, stage, payload, preview, sha(preview_bytes))
            return verify_locked(home, stage, payload, preview)
        insist(not (stage / 'intent.json').exists(), 'apply_already_attempted')
        no_cached_activation(home)
        current_layout = layout_report(home)
        current_layout['full_bundle_style_optimization_present'] = current_layout['markers']['public_style_service']
        insist(current_layout == preview['layout_dependency'], 'layout_changed_after_preview')
        for row in preview['files']:
            guard(home, row)
        # Source installation and its verification share this one lock lifetime.
        record(stage, 'intent.json', {'release': RELEASE, 'at': now(),
                                     'preview_sha256': sha(preview_bytes), 'payload_sha256': payload_sha})
        try:
            for row in preview['files']:
                if not row['change']:
                    continue
                guard(home, row)
                info = row['after']
                arguments = (safe_path(home, row['destination']), private_read(stage, row['candidate']),
                             info['mode'], info['uid'], info['gid'])
                if writer is atomic:
                    writer(*arguments, expected=row['before'])
                else:
                    writer(*arguments)
            result = verify_locked(home, stage, payload, preview)
            record(stage, 'applied.json', dict(result, at=now()))
            return result
        except BaseException:
            try:
                count = restore_locked(home, stage, preview)
                record(stage, 'failure.json', {'release': RELEASE, 'at': now(),
                                               'status': 'failed_guarded_rollback_complete', 'files_restored': count})
            except BaseException:
                if not (stage / 'failure.json').exists():
                    record(stage, 'failure.json', {'release': RELEASE, 'at': now(),
                                                  'status': 'stopped_rollback_refused_preserve_later_work'})
            raise


def session(home, stage, payload, payload_sha, php, expected_preview=None):
    """One coordinator owns all three locks through operator acceptance."""
    real_directory(home)
    insist(str(home) == payload['home'] and os.geteuid() == payload['account_uid'], 'account_identity_invalid')
    with locks(home, os.geteuid()):
        def held_lock(*_):
            return contextlib.nullcontext()
        print(json.dumps({'release': RELEASE, 'status': 'session_locked', 'locks_held': 3,
                          'commands': ['preview', 'apply', 'verify', 'finish', 'rollback', 'abort']}), flush=True)
        for line in sys.stdin:
            command = line.strip()
            if not command:
                continue
            try:
                insist(command in ('preview', 'apply', 'verify', 'finish', 'rollback', 'abort'), 'command_not_allowed')
                if command == 'abort':
                    insist(not (stage / 'intent.json').exists(), 'installed_release_requires_finish_or_rollback')
                    print(json.dumps({'release': RELEASE, 'status': 'session_aborted_before_apply'}), flush=True)
                    return 0
                if command == 'finish':
                    rollback_pending = (stage / 'rollback.json').exists()
                    insist(expected_preview is not None
                           and (rollback_pending or (stage / 'applied.json').exists()), 'successful_apply_required')
                    result = run(home, stage, payload, payload_sha, 'verify', php,
                                 lock_context=held_lock, preview_sha=expected_preview)
                    if rollback_pending:
                        record(stage, 'rollback-finished.json', {
                            'release': RELEASE, 'at': now(), 'preview_sha256': expected_preview,
                            'status': 'baseline_verified_operator_rollback_acceptance_complete',
                            'rollback_activation_and_http_acceptance': 'operator_confirmed'})
                        print(json.dumps({'release': RELEASE, 'status': 'rollback_finished',
                                          'baseline_verified': True, 'files_verified': result['files_verified'],
                                          'locks_held': 3}), flush=True)
                        return 0
                    record(stage, 'finished.json', {'release': RELEASE, 'at': now(),
                                                  'preview_sha256': expected_preview,
                                                  'status': 'source_verified_operator_acceptance_complete',
                                                  'activation_and_http_acceptance': 'operator_confirmed'})
                    print(json.dumps({'release': RELEASE, 'status': 'finished',
                                      'files_verified': result['files_verified'], 'locks_held': 3}), flush=True)
                    return 0
                insist(command == 'preview' or expected_preview is not None, 'preview_hash_required')
                result = run(home, stage, payload, payload_sha, command, php,
                             lock_context=held_lock, preview_sha=expected_preview)
                if command == 'preview':
                    expected_preview = result['preview_sha256']
                result['locks_held'] = 3
                if command == 'preview':
                    # Keep the terminal result compact. Full paths/identity are
                    # retained in private preview.json for the release record.
                    result['layout_dependency'] = result['layout_dependency']['markers']
                print(json.dumps(result, sort_keys=True), flush=True)
            except Exception as error:
                print(json.dumps({'release': RELEASE, 'status': 'stopped', 'locks_held': 3,
                                  'reason': str(error) if isinstance(error, Stop) else 'inspect_private_release_state',
                                  'error_class': type(error).__name__}), flush=True)
        # EOF cannot establish acceptance. Preserve the receipt and release the
        # advisory locks when the coordinator disconnects; never claim finish.
        print(json.dumps({'release': RELEASE, 'status': 'session_disconnected_pending_review',
                          'installed': (stage / 'intent.json').exists() and not (stage / 'rollback.json').exists(),
                          'rollback_pending_acceptance': (stage / 'rollback.json').exists()}), flush=True)
        return 2


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=('preview', 'apply', 'verify', 'rollback', 'session'))
    parser.add_argument('--payload', type=Path, required=True)
    parser.add_argument('--payload-sha256', required=True)
    parser.add_argument('--preview-sha256')
    parser.add_argument('--stage', type=Path, required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php84/root/usr/bin/php')
    args = parser.parse_args()
    try:
        insist(re.fullmatch('[0-9a-f]{64}', args.payload_sha256), 'payload_hash_invalid')
        insist(args.payload.is_file() and not args.payload.is_symlink(), 'payload_path_invalid')
        raw = args.payload.read_bytes()
        insist(sha(raw) == args.payload_sha256, 'payload_hash_mismatch')
        payload = json.loads(raw)
        insist(sha(Path(__file__).read_bytes()) == payload['helpers']['release.py'], 'release_helper_drift')
        insist(args.preview_sha256 is None or re.fullmatch('[0-9a-f]{64}', args.preview_sha256), 'preview_hash_invalid')
        if args.phase == 'session':
            return session(Path(payload['home']), args.stage, payload, args.payload_sha256, args.php,
                           args.preview_sha256)
        insist(args.phase == 'preview' or args.preview_sha256 is not None, 'preview_hash_required')
        result = run(Path(payload['home']), args.stage, payload, args.payload_sha256, args.phase, args.php,
                     preview_sha=args.preview_sha256)
        print(json.dumps(result, sort_keys=True))
    except Exception as error:
        print(json.dumps({'release': RELEASE, 'status': 'stopped',
                          'reason': str(error) if isinstance(error, Stop) else 'inspect_private_release_state',
                          'error_class': type(error).__name__}, sort_keys=True))
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
