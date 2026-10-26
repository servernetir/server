#!/usr/bin/env python3
"""Plan, install or roll back only the reviewed VPS.TC runtime delta.

Default: create a private, linted plan without changing application files.
Apply: repeat --root/--target, add --release-dir PATH --apply PLAN_SHA256.
Rollback: repeat those arguments with --rollback PLAN_SHA256.
No application bootstrap, migrations, settings, API calls or cache commands run.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import stat
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request

BASE = '8489da5b559ce02c7ce34f5ede5b0e086fb72850'
REPOSITORY = 'servernetir/server'
MAX_SOURCE_BYTES = 2_000_000
RUNTIME_PATHS = (
    # New dependencies precede integrations; routes are installed last.
    'app/Services/Cloud/VpsTcCatalog.php',
    'app/Services/Cloud/VpsTcClient.php',
    'app/Services/Cloud/VpsTcPricing.php',
    'app/Services/Cloud/VpsTcRenewal.php',
    'app/Services/Cloud/VpsTcSales.php',
    'app/Services/Cloud/VpsTcApi.php',
    'app/Services/Cloud/VpsTcPreflight.php',
    'app/Services/Cloud/CloudPricing.php',
    'app/Services/Cloud/CloudPricingSnapshot.php',
    'app/Models/CloudImage.php',
    'app/Models/CloudPlan.php',
    'app/Services/Cloud/CloudCatalogRepricer.php',
    'app/Services/Cloud/CloudCatalogSync.php',
    'app/Services/Cloud/CloudManager.php',
    'app/Services/Cloud/CloudProvisioner.php',
    'app/Services/Billing/ComplimentaryServices.php',
    'app/Services/Payment/PaymentService.php',
    'app/Console/Commands/RunServiceLifecycle.php',
    'app/Http/Controllers/Account/CloudServerController.php',
    'app/Http/Controllers/Account/CloudStoreController.php',
    'app/Http/Controllers/Admin/VpsTcController.php',
    'resources/views/account/cloud-store.blade.php',
    'resources/views/admin/cloud-vpstc.blade.php',
    'routes/web.php',
)
NEW_PATHS = frozenset('app/Services/Cloud/' + name + '.php' for name in (
    'VpsTcCatalog', 'VpsTcClient', 'VpsTcPricing', 'VpsTcRenewal', 'VpsTcSales'))


class ReleaseError(RuntimeError):
    """Only non-sensitive reason codes and approved relative paths are emitted."""


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical(value: dict) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()


def validate_sha(value: str) -> str:
    if not re.fullmatch('[0-9a-f]{40}', value):
        raise ReleaseError('invalid_commit_sha')
    return value


def checked_root(value: str | Path) -> Path:
    path = Path(os.path.abspath(value))
    if path.is_symlink() or not path.is_dir() or path != path.resolve():
        raise ReleaseError('root_must_be_existing_real_directory')
    return path


def checked_file(root: Path, relative: str) -> Path:
    if relative not in RUNTIME_PATHS:
        raise ReleaseError('path_outside_release_scope')
    path = root / relative
    cursor = path
    while cursor != root:
        if cursor.is_symlink():
            raise ReleaseError('symlink_in_release_path:' + relative)
        cursor = cursor.parent
    if path.exists() and not path.is_file():
        raise ReleaseError('nonregular_release_file:' + relative)
    return path


def checked_private_file(release: Path, path: Path) -> Path:
    cursor = path
    while cursor != release:
        if cursor.is_symlink():
            raise ReleaseError('symlink_in_private_snapshot')
        cursor = cursor.parent
    if not path.is_file():
        raise ReleaseError('missing_private_snapshot')
    return path


@contextlib.contextmanager
def release_locks(root: Path):
    handles = []
    try:
        for name in ('.servernet-web-release.lock', 'servernet-release.lock'):
            flags = os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0)
            fd = os.open(root.parent / name, flags, 0o600)
            handle = os.fdopen(fd, 'a')
            handles.append(handle)
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise ReleaseError('release_lock_not_regular')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise ReleaseError('another_release_holds_shared_lock') from exc
        yield
    finally:
        for handle in reversed(handles):
            handle.close()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ReleaseError('source_redirect_rejected')


def fetch_source(commit: str, relative: str) -> bytes | None:
    validate_sha(commit)
    if relative not in RUNTIME_PATHS:
        raise ReleaseError('source_path_outside_scope')
    url = 'https://raw.githubusercontent.com/' + REPOSITORY + '/' + commit + '/website/' + relative
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    try:
        request = urllib.request.Request(url, headers={'User-Agent': 'ServerNet-VPSTC-scoped-release/1'})
        with opener.open(request, timeout=30) as response:
            if response.status != 200 or urllib.parse.urlsplit(response.url).hostname != 'raw.githubusercontent.com':
                raise ReleaseError('source_origin_or_status_rejected')
            data = response.read(MAX_SOURCE_BYTES + 1)
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            return None
        raise ReleaseError('source_fetch_failed:' + relative) from exc
    except (urllib.error.URLError, TimeoutError) as exc:
        raise ReleaseError('source_fetch_failed:' + relative) from exc
    if len(data) > MAX_SOURCE_BYTES or b'\0' in data:
        raise ReleaseError('source_size_or_binary_rejected:' + relative)
    return data


def private_write(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0)
    fd = os.open(path, flags, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())


def normalized(data: bytes, relative: str) -> tuple[bytes, bytes]:
    result = data.replace(b'\r\n', b'\n')
    if b'\r' in result:
        raise ReleaseError('mixed_or_bare_cr_line_endings:' + relative)
    if b'\r\n' in data and data.count(b'\r\n') != data.count(b'\n'):
        raise ReleaseError('mixed_line_endings:' + relative)
    return result, b'\r\n' if b'\r\n' in data else b'\n'


def merge_candidate(release: Path, relative: str, before: bytes | None,
                    base: bytes | None, desired: bytes) -> bytes:
    if before == desired:
        return desired
    if base is None:
        if before is not None:
            raise ReleaseError('unexpected_existing_new_file:' + relative)
    elif before is None:
        raise ReleaseError('expected_live_file_missing:' + relative)
    if base == desired:
        if before is None:
            raise ReleaseError('unchanged_source_has_no_live_file:' + relative)
        return before
    wanted, wanted_ending = normalized(desired, relative)
    original = None if base is None else normalized(base, relative)[0]
    current, ending = (None, wanted_ending) if before is None else normalized(before, relative)
    base_path = release / 'source-base' / relative
    target_path = release / 'source-target' / relative
    work = release / 'candidate'
    private_write(target_path, wanted)
    if original is not None:
        private_write(base_path, original)
    if current is not None:
        private_write(work / relative, current)
    old_argument = '/dev/null' if original is None else str(base_path.relative_to(release))
    result = subprocess.run(['git', 'diff', '--no-index', '--no-ext-diff', '--no-textconv',
                             '--binary', '--', old_argument, str(target_path.relative_to(release))],
                            cwd=release, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode != 1 or not result.stdout:
        raise ReleaseError('source_diff_failed:' + relative)
    patch = release / 'patches' / (relative.replace('/', '__') + '.patch')
    private_write(patch, result.stdout)
    work.mkdir(exist_ok=True, mode=0o700)
    command = ['git', 'apply', '-p2', '--whitespace=nowarn']
    check = subprocess.run(command + ['--check', str(patch)], cwd=work,
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check.returncode:
        raise ReleaseError('live_patch_conflict:' + relative)
    applied = subprocess.run(command + [str(patch)], cwd=work,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if applied.returncode:
        raise ReleaseError('live_patch_failed:' + relative)
    merged = (work / relative).read_bytes()
    if ending == b'\r\n':
        merged = merged.replace(b'\n', b'\r\n')
        (work / relative).write_bytes(merged)
    os.chmod(work / relative, 0o600)
    return merged


def lint(php: str, path: Path, relative: str) -> None:
    result = subprocess.run([php, '-l', str(path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise ReleaseError('php_lint_failed:' + relative)


def build_plan(root: Path, target: str, php: str, source_reader=fetch_source) -> tuple[Path, dict]:
    validate_sha(target)
    if target == BASE:
        raise ReleaseError('target_equals_base')
    # Active route caches need a separately reviewed activation operation.
    if list((root / 'bootstrap/cache').glob('routes-*.php')):
        raise ReleaseError('route_cache_requires_reviewed_activation')
    release = Path(tempfile.mkdtemp(prefix='vpstc-release-' + target[:12] + '-', dir=root.parent))
    os.chmod(release, 0o700)
    entries = []
    requests = [(commit, relative) for relative in RUNTIME_PATHS for commit in (BASE, target)]
    sources = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        values = executor.map(lambda item: source_reader(*item), requests)
        for key, value in zip(requests, values):
            sources[key] = value
    for relative in RUNTIME_PATHS:
        base, desired = sources[(BASE, relative)], sources[(target, relative)]
        if desired is None or (base is None and relative not in NEW_PATHS):
            raise ReleaseError('missing_required_git_source:' + relative)
        if base == desired:
            continue
        destination = checked_file(root, relative)
        before = destination.read_bytes() if destination.exists() else None
        mode = stat.S_IMODE(destination.stat().st_mode) if before is not None else 0o644
        if before is not None:
            private_write(release / 'before' / relative, before)
        candidate = merge_candidate(release, relative, before, base, desired)
        candidate_path = release / 'candidate' / relative
        if not candidate_path.exists():
            private_write(candidate_path, candidate)
        lint(php, candidate_path, relative)
        entries.append({'path': relative, 'before_sha256': digest(before) if before is not None else None,
                        'after_sha256': digest(candidate), 'before_mode': mode if before is not None else None,
                        'after_mode': mode, 'source_base_sha256': digest(base) if base is not None else None,
                        'source_target_sha256': digest(desired), 'change': before != candidate,
                        'preserved_live_drift': before is not None and before != base and before != desired})
    if not entries:
        raise ReleaseError('no_scoped_source_changes')
    plan = {'version': 1, 'repository': REPOSITORY, 'base': BASE, 'target': target,
            'root': str(root), 'files': entries}
    plan['plan_sha256'] = digest(canonical(plan))
    private_write(release / 'plan.json', canonical(plan))
    private_write(release / 'rollback-plan.json', canonical({
        'plan_sha256': plan['plan_sha256'], 'root': str(root), 'target': target,
        'files': [{'path': row['path'], 'installed_sha256': row['after_sha256'],
                   'restore_sha256': row['before_sha256'], 'restore_mode': row['before_mode']}
                  for row in reversed(entries) if row['change']],
        'requires': 'matching installed hashes and both shared release locks',
        'application_activation': 'separate reviewed operation; never run by this helper',
    }))
    return release, plan


def load_plan(root: Path, target: str, release: Path, expected_hash: str) -> dict:
    if not re.fullmatch('[0-9a-f]{64}', expected_hash):
        raise ReleaseError('invalid_plan_hash')
    if release.is_symlink() or release != release.resolve() or release.parent != root.parent \
            or not release.name.startswith('vpstc-release-' + target[:12] + '-'):
        raise ReleaseError('release_directory_outside_private_parent')
    metadata = release.stat()
    if not stat.S_ISDIR(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) != 0o700 or metadata.st_uid != os.geteuid():
        raise ReleaseError('release_directory_not_private_or_owned')
    try:
        plan = json.loads(checked_private_file(release, release / 'plan.json').read_bytes())
    except (ValueError, OSError) as exc:
        raise ReleaseError('invalid_plan_file') from exc
    actual = plan.pop('plan_sha256', None)
    if actual != expected_hash or digest(canonical(plan)) != expected_hash:
        raise ReleaseError('plan_hash_mismatch')
    plan['plan_sha256'] = actual
    if plan.get('version') != 1 or plan.get('base') != BASE or plan.get('target') != target \
            or plan.get('repository') != REPOSITORY or plan.get('root') != str(root):
        raise ReleaseError('plan_identity_mismatch')
    rows = plan.get('files')
    if not isinstance(rows, list) or not rows or len({r.get('path') for r in rows}) != len(rows):
        raise ReleaseError('invalid_plan_paths')
    for row in rows:
        relative = row['path']
        checked_file(root, relative)
        candidate = checked_private_file(release, release / 'candidate' / relative)
        if digest(candidate.read_bytes()) != row['after_sha256']:
            raise ReleaseError('candidate_hash_mismatch:' + relative)
        if row['before_sha256'] is not None:
            backup = checked_private_file(release, release / 'before' / relative)
            if digest(backup.read_bytes()) != row['before_sha256']:
                raise ReleaseError('backup_hash_mismatch:' + relative)
    return plan


def assert_live(root: Path, row: dict, installed: bool = False) -> None:
    path = checked_file(root, row['path'])
    expected = row['after_sha256'] if installed else row['before_sha256']
    mode = row['after_mode'] if installed else row['before_mode']
    if expected is None:
        if path.exists():
            raise ReleaseError('live_absence_precondition_failed:' + row['path'])
    elif not path.exists() or digest(path.read_bytes()) != expected or stat.S_IMODE(path.stat().st_mode) != mode:
        raise ReleaseError('live_hash_or_mode_changed:' + row['path'])


def fsync_directory(directory: Path) -> None:
    fd = os.open(directory, os.O_RDONLY | getattr(os, 'O_DIRECTORY', 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def atomic_write(path: Path, data: bytes, mode: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.vpstc-staged-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            os.fchmod(handle.fileno(), mode)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def receipt(release: Path, data: dict) -> None:
    atomic_write(release / 'receipt.json', canonical(data), 0o600)
    fsync_directory(release)


def rollback_rows(root: Path, release: Path, rows: list[dict]) -> list[str]:
    conflicts = []
    for row in reversed(rows):
        try:
            assert_live(root, row, installed=True)
            path = checked_file(root, row['path'])
            if row['before_sha256'] is None:
                path.unlink()
            else:
                backup = (release / 'before' / row['path']).read_bytes()
                if digest(backup) != row['before_sha256']:
                    raise ReleaseError('backup_hash_mismatch:' + row['path'])
                atomic_write(path, backup, row['before_mode'])
            fsync_directory(path.parent)
        except (OSError, ReleaseError):
            conflicts.append(row['path'])
    return conflicts


def apply_plan(root: Path, release: Path, plan: dict, php: str, writer=atomic_write) -> dict:
    if (release / 'receipt.json').exists():
        raise ReleaseError('release_already_has_receipt_review_before_reuse')
    if list((root / 'bootstrap/cache').glob('routes-*.php')):
        raise ReleaseError('route_cache_requires_reviewed_activation')
    # Verify every backup, candidate and live precondition before the first write.
    for row in plan['files']:
        assert_live(root, row)
        lint(php, release / 'candidate' / row['path'], row['path'])
    installed = []
    try:
        for row in plan['files']:
            if not row['change']:
                continue
            assert_live(root, row)
            path = checked_file(root, row['path'])
            candidate = checked_private_file(release, release / 'candidate' / row['path']).read_bytes()
            if digest(candidate) != row['after_sha256']:
                raise ReleaseError('candidate_hash_mismatch:' + row['path'])
            writer(path, candidate, row['after_mode'])
            installed.append(row)
            fsync_directory(path.parent)
        for row in plan['files']:
            assert_live(root, row, installed=True)
        result = {'status': 'installed', 'target': plan['target'], 'plan_sha256': plan['plan_sha256'],
                  'files_installed': len(installed), 'application_activation': 'not_performed'}
        receipt(release, result)
        return result
    except BaseException as exc:
        conflicts = rollback_rows(root, release, installed)
        result = {'status': 'rollback_incomplete' if conflicts else 'rolled_back',
                  'target': plan['target'], 'plan_sha256': plan['plan_sha256'],
                  'files_attempted': len(installed), 'protected_files': conflicts}
        receipt(release, result)
        raise ReleaseError(result['status']) from exc


def explicit_rollback(root: Path, release: Path, plan: dict) -> dict:
    try:
        previous = json.loads((release / 'receipt.json').read_bytes())
    except (OSError, ValueError) as exc:
        raise ReleaseError('installed_receipt_required_for_rollback') from exc
    if previous.get('status') != 'installed' or previous.get('plan_sha256') != plan['plan_sha256']:
        raise ReleaseError('installed_receipt_required_for_rollback')
    rows = [row for row in plan['files'] if row['change']]
    # An explicit rollback refuses all writes when any newer file is present.
    for row in rows:
        assert_live(root, row, installed=True)
    conflicts = rollback_rows(root, release, rows)
    result = {'status': 'rollback_incomplete' if conflicts else 'rolled_back',
              'target': plan['target'], 'plan_sha256': plan['plan_sha256'],
              'files_restored': len(rows) - len(conflicts), 'protected_files': conflicts,
              'application_activation': 'not_performed'}
    receipt(release, result)
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--target', required=True, help='Full public Git commit SHA')
    parser.add_argument('--root', required=True, help='Existing production application directory')
    parser.add_argument('--php', default='/opt/cpanel/ea-php84/root/usr/bin/php')
    parser.add_argument('--release-dir', type=Path)
    action = parser.add_mutually_exclusive_group()
    action.add_argument('--apply', metavar='PLAN_SHA256')
    action.add_argument('--rollback', metavar='PLAN_SHA256')
    args = parser.parse_args()
    try:
        target = validate_sha(args.target)
        root = checked_root(args.root)
        if bool(args.release_dir) != bool(args.apply or args.rollback):
            raise ReleaseError('release_directory_required_only_for_apply_or_rollback')
        with release_locks(root):
            if not args.apply and not args.rollback:
                release, plan = build_plan(root, target, args.php)
                report = {'status': 'planned', 'base': BASE, 'target': target,
                          'release_dir': str(release), 'plan_sha256': plan['plan_sha256'],
                          'files_changed': sum(row['change'] for row in plan['files']),
                          'files': plan['files'], 'application_writes': 0,
                          'rollback_plan': str(release / 'rollback-plan.json')}
            else:
                release = Path(os.path.abspath(args.release_dir))
                plan = load_plan(root, target, release, args.apply or args.rollback)
                report = apply_plan(root, release, plan, args.php) if args.apply else explicit_rollback(root, release, plan)
        print(json.dumps(report, sort_keys=True))
        return 2 if report['status'] == 'rollback_incomplete' else 0
    except Exception as exc:
        # Never print source, patch contents, process output, URLs with data or exception bodies.
        code = str(exc) if isinstance(exc, ReleaseError) else type(exc).__name__
        print(json.dumps({'status': 'failed', 'reason': code}, sort_keys=True))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
