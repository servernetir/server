#!/usr/bin/env python3
"""Private three-phase, hash-guarded native release. No network or app bootstrap.

Live caller must hold Laravel Cache::lock('hermes:support-review', 2700) for
the entire subprocess and set HERMES_REVIEW_LOCK_HELD=1. The environment marker
is a caller contract, not an authentication boundary. No cron, state, database,
configuration or global OPcache operation is performed here.
"""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time

RELEASE = 'hermes-business-20261030-v1'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
APP = 'servernet_app/app/Services/Hermes/'
FILES = {
    'HermesApprovedBusinessKnowledge.php': {
        'source': 'website/app/Services/Hermes/HermesApprovedBusinessKnowledge.php',
        'before': None,
        'after': '77770c7b3050470d206ed8fd2debc90e0365b8f596270378646fe2434fa3901f'},
    'HermesOperations.php': {
        'source': 'infra/hermes/business-20261030/HermesOperations.php',
        'before': 'ba1502378e7a4f4c2c0eb1bb2fdd352c2c4c3f5be491df33bcb7bdba278846e5',
        'after': '155cb80ff57cc92ebe110584390ef99c4162ded52159a4cbcd2f4df8795394ab'},
    'HermesSupportDraftWriter.php': {
        'source': 'infra/hermes/business-20261030/HermesSupportDraftWriter.php',
        'before': '48cf83e8edb43aa5809733d760ffe280b7902860b0cbcb52bb60be42c6a0cb28',
        'after': '3665d64d9b80d09d99c90712e03d088743142b3d9cb986a2271eb5b0385e6d9b'},
}


class ReleaseError(RuntimeError):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_path(root, relative):
    root = Path(root)
    path = root / relative
    if Path(relative).is_absolute() or '..' in Path(relative).parts:
        raise ReleaseError('invalid_relative_path')
    cursor = path
    while cursor != root:
        if cursor.is_symlink():
            raise ReleaseError('symlink_path:' + path.name)
        cursor = cursor.parent
    if root.is_symlink():
        raise ReleaseError('symlink_root')
    return path


def check(path, expected):
    if path.is_symlink():
        raise ReleaseError('symlink_file:' + path.name)
    if expected is None:
        if path.exists():
            raise ReleaseError('expected_absent:' + path.name)
        return None
    if not path.is_file() or digest(path.read_bytes()) != expected:
        raise ReleaseError('hash_drift:' + path.name)
    return path.read_bytes()


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
    finally:
        if stage.exists() and not stage.is_symlink():
            stage.unlink()


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
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
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


def target(home, name):
    return safe_path(home, APP + name)


def current_hash(path):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ReleaseError('unexpected_target_type:' + path.name)
    return digest(path.read_bytes()) if path.exists() else None


def validate_receipt(receipt):
    if receipt.get('release') != RELEASE or receipt.get('files') != FILES:
        raise ReleaseError('receipt_contract_drift')


def verify_targets(home, expected):
    for name, row in FILES.items():
        check(target(home, name), row[expected])


def rollback(home, release, receipt, lint):
    # Validate the ENTIRE bounded set before writing any rollback file. A newer
    # developer's edit must stop rollback, including in a partial/crashed apply.
    for name, row in FILES.items():
        actual = current_hash(target(home, name))
        if actual not in (row['before'], row['after']):
            raise ReleaseError('rollback_drift:' + name)
        if row['before'] is not None:
            check(safe_path(release, name + '.before'), row['before'])
    receipt['phase'] = 'rolling_back'
    receipt_write(release, receipt)
    # Consumers revert before removing the newly introduced dependency.
    for name, row in reversed(list(FILES.items())):
        path = target(home, name)
        if current_hash(path) == row['after']:
            if row['before'] is None:
                path.unlink()  # Only this release's exact newly introduced file.
            else:
                atomic_write(path, check(release / (name + '.before'), row['before']), receipt['modes'][name])
                lint(path)
    verify_targets(home, 'before')
    receipt['phase'] = 'rolled_back'
    receipt['rolled_back_utc'] = int(time.time())
    receipt_write(release, receipt)
    return receipt


def run(home, source, phase, lint=php_lint, observe_cron=cron_snapshot):
    """Fake-home injection is exclusively for offline tests, never live CLI flags."""
    home, source = Path(home), Path(source)
    if phase not in ('prepare', 'apply', 'verify', 'rollback'):
        raise ReleaseError('invalid_phase')
    os.umask(0o077)
    with locks(home):
        parent = safe_path(home, 'deploy-backups')
        if parent.exists() and not parent.is_dir():
            raise ReleaseError('invalid_backup_parent')
        parent.mkdir(mode=0o700, exist_ok=True)
        release = safe_path(home, 'deploy-backups/' + RELEASE)
        receipt_path = safe_path(home, 'deploy-backups/' + RELEASE + '/receipt.json')
        if not receipt_path.exists():
            if phase != 'prepare' or release.exists():
                raise ReleaseError('prepare_required_or_incomplete')
            for name, row in FILES.items():
                check(safe_path(source, row['source']), row['after'])
                check(target(home, name), row['before'])
            modes = {name: stat.S_IMODE(target(home, name).stat().st_mode) if row['before'] else 0o644
                     for name, row in FILES.items()}
            cron = observe_cron()
            release.mkdir(mode=0o700)
            for name, row in FILES.items():
                if row['before']:
                    atomic_write(release / (name + '.before'), check(target(home, name), row['before']), 0o600)
                staged = release / (name + '.after')
                atomic_write(staged, check(safe_path(source, row['source']), row['after']), 0o600)
                lint(staged)
            receipt = {'release': RELEASE, 'phase': 'prepared', 'files': FILES,
                       'modes': modes, 'cron_before': cron, 'prepared_utc': int(time.time()),
                       'installed': [], 'activation': 'CLI only; web OPcache acceptance separate'}
            receipt_write(release, receipt)
            return receipt
        receipt = json.loads(check(receipt_path, digest(receipt_path.read_bytes())))
        validate_receipt(receipt)
        if observe_cron() != receipt['cron_before']:
            raise ReleaseError('cron_changed_since_prepare')
        if phase == 'rollback':
            if receipt['phase'] == 'rolled_back':
                verify_targets(home, 'before')
                return receipt
            if receipt['phase'] not in ('installed', 'verified', 'installing', 'rolling_back'):
                raise ReleaseError('rollback_phase_invalid')
            return rollback(home, release, receipt, lint)
        if phase == 'prepare':
            if receipt['phase'] != 'prepared':
                raise ReleaseError('release_already_started')
            verify_targets(home, 'before')
            return receipt
        if phase == 'apply':
            if receipt['phase'] in ('installed', 'verified'):
                verify_targets(home, 'after')
                return receipt  # Repeating a completed phase performs no writes.
            if receipt['phase'] != 'prepared':
                raise ReleaseError('incomplete_apply_requires_rollback')
            verify_targets(home, 'before')
            for name, row in FILES.items():
                check(safe_path(source, row['source']), row['after'])
                check(release / (name + '.after'), row['after'])
                if row['before']:
                    check(release / (name + '.before'), row['before'])
                    if stat.S_IMODE(target(home, name).stat().st_mode) != receipt['modes'][name]:
                        raise ReleaseError('target_mode_drift')
                lint(release / (name + '.after'))
            receipt['phase'] = 'installing'
            receipt_write(release, receipt)
            try:
                # Dict insertion order installs the new dependency first.
                for name, row in FILES.items():
                    path = target(home, name)
                    check(path, row['before'])
                    atomic_write(path, check(release / (name + '.after'), row['after']), receipt['modes'][name])
                    receipt['installed'].append(name)
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
                rollback(home, release, receipt, lint)
                raise
        if receipt['phase'] not in ('installed', 'verified'):
            raise ReleaseError('verify_requires_installed')
        verify_targets(home, 'after')
        for name in FILES:
            lint(target(home, name))
        if receipt['phase'] == 'verified':
            return receipt
        receipt['phase'] = 'verified'
        receipt['verified_utc'] = int(time.time())
        receipt['cron_after'] = observe_cron()
        receipt['natural_model_acceptance'] = 'not_performed'
        receipt_write(release, receipt)
        return receipt


if __name__ == '__main__':
    try:
        home = Path('/home/servernetcloud')
        if Path.home() != home or os.getuid() == 0 or os.environ.get('HERMES_REVIEW_LOCK_HELD') != '1':
            raise ReleaseError('unexpected_account_or_missing_review_lock_contract')
        if len(sys.argv) != 3 or sys.argv[1] not in ('prepare', 'apply', 'verify', 'rollback'):
            raise ReleaseError('usage_phase_source_root')
        result = run(home, Path(sys.argv[2]), sys.argv[1])
        print(json.dumps(result, sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'error_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, ReleaseError) else 'review_private_task_status'}))
        sys.exit(1)
