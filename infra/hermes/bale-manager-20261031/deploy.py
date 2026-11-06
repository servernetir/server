#!/usr/bin/env python3
"""Install only the hash-pinned PHP files in a reviewed manager release manifest.

No app bootstrap, database/migration, setting, provider, cron or cache operation
is performed here. A private PHP caller must hold the relevant native worker
locks for the whole sequence and set BALE_MANAGER_LOCKS_HELD=1. This marker is a
caller contract, not an authentication boundary. Filesystem locks also serialize
cooperating web releases. The policy stays disabled until separate acceptance.
"""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import time

RELEASE = 'bale-manager-20261031'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
APP = 'servernet_app'
ROOTS = ('app/', 'resources/', 'routes/', 'lang/', 'database/migrations/', 'database/seeders/')
HASH = re.compile(r'^[0-9a-f]{64}$')


class ReleaseError(RuntimeError):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def relative(value):
    if not isinstance(value, str) or not value or '\\' in value or '\x00' in value:
        raise ReleaseError('invalid_relative_path')
    path = PurePosixPath(value)
    if path.is_absolute() or any(part in ('', '.', '..') for part in value.split('/')):
        raise ReleaseError('invalid_relative_path')
    return value


def safe_path(root, value):
    """Refuse symlinks and non-directory ancestors, including dangling links."""
    root = Path(root)
    path = root / relative(value)
    cursor = root
    if root.is_symlink() or not root.is_dir():
        raise ReleaseError('invalid_root')
    for part in Path(value).parts:
        cursor = cursor / part
        if cursor.is_symlink():
            raise ReleaseError('symlink_path:' + path.name)
        if cursor != path and cursor.exists() and not cursor.is_dir():
            raise ReleaseError('non_directory_parent:' + path.name)
    return path


def read_regular(path):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        raise ReleaseError('unreadable_regular_file:' + path.name) from None
    with os.fdopen(fd, 'rb') as handle:
        if not stat.S_ISREG(os.fstat(handle.fileno()).st_mode):
            raise ReleaseError('nonregular_file:' + path.name)
        return handle.read()


def current_hash(path):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ReleaseError('unexpected_target_type:' + path.name)
    return digest(read_regular(path)) if path.exists() else None


def check(path, expected):
    if expected is None:
        if path.is_symlink() or path.exists():
            raise ReleaseError('expected_absent:' + path.name)
        return None
    data = read_regular(path)
    if digest(data) != expected:
        raise ReleaseError('hash_drift:' + path.name)
    return data


def reject_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ReleaseError('duplicate_json_key')
        result[key] = value
    return result


def load_manifest(path):
    try:
        manifest = json.loads(read_regular(Path(path)), object_pairs_hook=reject_duplicates)
    except (ValueError, UnicodeError):
        raise ReleaseError('invalid_manifest_json') from None
    return validate_manifest(manifest)


def validate_manifest(manifest):
    if not isinstance(manifest, dict) or manifest.get('release') != RELEASE:
        raise ReleaseError('invalid_manifest_release')
    files = manifest.get('files')
    if not isinstance(files, list) or not 1 <= len(files) <= 300:
        raise ReleaseError('invalid_manifest_files')
    targets = set()
    for row in files:
        if not isinstance(row, dict) or set(row) != {'source', 'target', 'before', 'after'}:
            raise ReleaseError('invalid_manifest_row')
        source, target = relative(row['source']), relative(row['target'])
        if not source.startswith(('website/', 'infra/hermes/')):
            raise ReleaseError('invalid_source_root')
        if not target.startswith(ROOTS) or not target.endswith('.php'):
            raise ReleaseError('target_outside_php_package')
        if any(part.startswith('.') for part in Path(target).parts):
            raise ReleaseError('hidden_target_rejected')
        if target in targets:
            raise ReleaseError('duplicate_target')
        if row['before'] is not None and (not isinstance(row['before'], str) or not HASH.fullmatch(row['before'])):
            raise ReleaseError('invalid_before_hash')
        if not isinstance(row['after'], str) or not HASH.fullmatch(row['after']):
            raise ReleaseError('invalid_after_hash')
        targets.add(target)
    for target in targets:
        if any(parent.as_posix() in targets for parent in PurePosixPath(target).parents):
            raise ReleaseError('target_parent_collision')
    return manifest


def atomic_write(path, data, mode):
    stage = path.with_name('.' + path.name + '.' + RELEASE + '.stage')
    fd = os.open(stage, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
            os.fchmod(handle.fileno(), mode)
        os.replace(stage, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if stage.exists() and not stage.is_symlink():
            stage.unlink()


def make_parents(home, target):
    """Create only the manifest target's missing parents; never replace a path."""
    parts = Path(APP + '/' + target).parts[:-1]
    cursor = Path(home)
    for part in parts:
        cursor = cursor / part
        if cursor.is_symlink() or (cursor.exists() and not cursor.is_dir()):
            raise ReleaseError('invalid_target_parent')
        if not cursor.exists():
            cursor.mkdir(mode=0o755)
            cursor.chmod(0o755)  # umask is deliberately private for receipts.
        if cursor.is_symlink() or not cursor.is_dir():
            raise ReleaseError('invalid_target_parent')


@contextlib.contextmanager
def locks(home):
    handles = []
    try:
        for name in ('.servernet-web-release.lock', 'servernet-release.lock'):
            fd = os.open(home / name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            handle = os.fdopen(fd, 'a')
            handles.append(handle)
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise ReleaseError('nonregular_lock')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise ReleaseError('release_lock_busy') from None
        yield
    finally:
        for handle in reversed(handles):
            handle.close()


def php_lint(path):
    result = subprocess.run([PHP, '-l', str(path)], stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=30)
    if result.returncode:
        raise ReleaseError('php_lint_failed:' + path.name)


def cron_snapshot():
    result = subprocess.run(['/usr/bin/crontab', '-l'], stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=15)
    if result.returncode:
        raise ReleaseError('cron_unavailable')
    return digest(result.stdout)


def receipt_write(release, receipt):
    atomic_write(release / 'receipt.json',
                 (json.dumps(receipt, sort_keys=True, indent=2) + '\n').encode(), 0o600)


def target(home, row):
    return safe_path(home, APP + '/' + row['target'])


def stage_path(release, index, side):
    return safe_path(release, str(index).zfill(3) + '.' + side + '.php')


def verify_targets(home, manifest, side):
    for row in manifest['files']:
        check(target(home, row), row[side])


def verify_modes(home, manifest, modes):
    for index, row in enumerate(manifest['files']):
        path = target(home, row)
        if path.exists() and stat.S_IMODE(path.stat().st_mode) != modes[index]:
            raise ReleaseError('target_mode_drift:' + row['target'])


def validate_receipt(receipt, manifest):
    if not isinstance(receipt, dict) or receipt.get('manifest') != manifest:
        raise ReleaseError('receipt_contract_drift')
    if receipt.get('release') != RELEASE:
        raise ReleaseError('receipt_contract_drift')
    modes = receipt.get('modes')
    if not isinstance(modes, list) or len(modes) != len(manifest['files']):
        raise ReleaseError('receipt_modes_invalid')
    if any(type(mode) is not int or not 0 <= mode <= 0o777 for mode in modes):
        raise ReleaseError('receipt_modes_invalid')


def rollback(home, release, manifest, receipt, lint):
    # Validate the ENTIRE package and all recovery material before any mutation.
    # Do not restore cron: a later operator change is outside this release.
    for index, row in enumerate(manifest['files']):
        if current_hash(target(home, row)) not in (row['before'], row['after']):
            raise ReleaseError('rollback_drift:' + row['target'])
        if row['before'] is not None:
            backup = stage_path(release, index, 'before')
            check(backup, row['before'])
            lint(backup)
    verify_modes(home, manifest, receipt['modes'])
    receipt['phase'] = 'rolling_back'
    receipt_write(release, receipt)
    for index in reversed(range(len(manifest['files']))):
        row = manifest['files'][index]
        path = target(home, row)
        if current_hash(path) == row['after']:
            if row['before'] is None:
                path.unlink()  # Only this release's exact introduced file.
            else:
                atomic_write(path, check(stage_path(release, index, 'before'), row['before']), receipt['modes'][index])
                lint(path)
    verify_targets(home, manifest, 'before')
    receipt['phase'] = 'rolled_back'
    receipt['rolled_back_utc'] = int(time.time())
    receipt_write(release, receipt)
    return receipt


def run(home, source, manifest, phase, lint=php_lint, observe_cron=cron_snapshot):
    """Fake roots/lint/cron injection is for offline tests, never live CLI flags."""
    home, source = Path(home), Path(source)
    validate_manifest(manifest)
    if phase not in ('prepare', 'apply', 'verify', 'rollback'):
        raise ReleaseError('invalid_phase')
    if home.is_symlink() or not home.is_dir() or source.is_symlink() or not source.is_dir():
        raise ReleaseError('invalid_root')
    if not safe_path(home, APP).is_dir():
        raise ReleaseError('application_root_missing')
    os.umask(0o077)
    with locks(home):
        parent = safe_path(home, 'deploy-backups')
        if parent.exists() and not parent.is_dir():
            raise ReleaseError('invalid_backup_parent')
        if not parent.exists():
            parent.mkdir(mode=0o700)
        if stat.S_IMODE(parent.stat().st_mode) & 0o077:
            raise ReleaseError('backup_parent_not_private')
        release = safe_path(home, 'deploy-backups/' + RELEASE)
        receipt_path = safe_path(home, 'deploy-backups/' + RELEASE + '/receipt.json')
        if not receipt_path.exists():
            if phase != 'prepare' or release.exists():
                raise ReleaseError('prepare_required_or_incomplete')
            for row in manifest['files']:
                check(safe_path(source, row['source']), row['after'])
                check(target(home, row), row['before'])
            modes = [stat.S_IMODE(target(home, row).stat().st_mode) if row['before'] is not None else 0o644
                     for row in manifest['files']]
            if any(mode > 0o777 for mode in modes):
                raise ReleaseError('unexpected_special_mode')
            cron = observe_cron()
            release.mkdir(mode=0o700)
            for index, row in enumerate(manifest['files']):
                if row['before'] is not None:
                    atomic_write(stage_path(release, index, 'before'), check(target(home, row), row['before']), 0o600)
                staged = stage_path(release, index, 'after')
                atomic_write(staged, check(safe_path(source, row['source']), row['after']), 0o600)
                lint(staged)
            receipt = {'release': RELEASE, 'phase': 'prepared', 'manifest': manifest,
                       'modes': modes, 'cron_before': cron, 'prepared_utc': int(time.time()),
                       'installed': [], 'activation': 'CLI lint/hash only; web acceptance separate',
                       'schema_or_settings_changed': False}
            receipt_write(release, receipt)
            return receipt
        if release.is_symlink() or not release.is_dir() or stat.S_IMODE(release.stat().st_mode) & 0o077:
            raise ReleaseError('release_directory_not_private')
        try:
            receipt = json.loads(read_regular(receipt_path), object_pairs_hook=reject_duplicates)
        except (ValueError, UnicodeError):
            raise ReleaseError('invalid_receipt_json') from None
        validate_receipt(receipt, manifest)
        if phase == 'rollback':
            if receipt['phase'] == 'rolled_back':
                verify_targets(home, manifest, 'before')
                verify_modes(home, manifest, receipt['modes'])
                return receipt
            if receipt['phase'] not in ('installed', 'verified', 'installing', 'rolling_back'):
                raise ReleaseError('rollback_phase_invalid')
            return rollback(home, release, manifest, receipt, lint)
        if observe_cron() != receipt['cron_before']:
            raise ReleaseError('cron_changed_since_prepare')
        if phase == 'prepare':
            if receipt['phase'] != 'prepared':
                raise ReleaseError('release_already_started')
            verify_targets(home, manifest, 'before')
            return receipt
        if phase == 'apply':
            if receipt['phase'] in ('installed', 'verified'):
                verify_targets(home, manifest, 'after')
                verify_modes(home, manifest, receipt['modes'])
                return receipt
            if receipt['phase'] != 'prepared':
                raise ReleaseError('incomplete_apply_requires_rollback')
            verify_targets(home, manifest, 'before')
            for index, row in enumerate(manifest['files']):
                check(safe_path(source, row['source']), row['after'])
                staged = stage_path(release, index, 'after')
                check(staged, row['after'])
                if row['before'] is not None:
                    check(stage_path(release, index, 'before'), row['before'])
                    if stat.S_IMODE(target(home, row).stat().st_mode) != receipt['modes'][index]:
                        raise ReleaseError('target_mode_drift')
                lint(staged)
            receipt['phase'] = 'installing'
            receipt_write(release, receipt)
            try:
                for index, row in enumerate(manifest['files']):
                    make_parents(home, row['target'])
                    path = target(home, row)
                    check(path, row['before'])
                    atomic_write(path, check(stage_path(release, index, 'after'), row['after']), receipt['modes'][index])
                    receipt['installed'].append(row['target'])
                    receipt_write(release, receipt)
                    check(path, row['after'])
                    lint(path)
                if observe_cron() != receipt['cron_before']:
                    raise ReleaseError('cron_changed_during_apply')
                receipt['phase'] = 'installed'
                receipt['installed_utc'] = int(time.time())
                receipt_write(release, receipt)
                return receipt
            except Exception:
                rollback(home, release, manifest, receipt, lint)
                raise
        if receipt['phase'] not in ('installed', 'verified'):
            raise ReleaseError('verify_requires_installed')
        verify_targets(home, manifest, 'after')
        verify_modes(home, manifest, receipt['modes'])
        for row in manifest['files']:
            lint(target(home, row))
        if observe_cron() != receipt['cron_before']:
            raise ReleaseError('cron_changed_during_verify')
        if receipt['phase'] != 'verified':
            receipt['phase'] = 'verified'
            receipt['verified_utc'] = int(time.time())
            receipt['cron_after'] = receipt['cron_before']
            receipt_write(release, receipt)
        return receipt


if __name__ == '__main__':
    try:
        home = Path('/home/servernetcloud')
        if Path.home() != home or os.getuid() == 0 or os.environ.get('BALE_MANAGER_LOCKS_HELD') != '1':
            raise ReleaseError('unexpected_account_or_missing_native_lock_contract')
        if len(sys.argv) != 4:
            raise ReleaseError('usage_phase_source_root_manifest_json')
        result = run(home, Path(sys.argv[2]), load_manifest(sys.argv[3]), sys.argv[1])
        print(json.dumps(result, sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'error_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, ReleaseError) else 'inspect_private_release_status'}))
        sys.exit(1)
