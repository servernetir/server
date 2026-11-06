#!/usr/bin/env python3
"""Three explicit native cPanel tasks: prepare, apply, verify; then sealed.

Only the two reviewed Hermes command files may change. No framework bootstrap,
network requests, cron writes, cache flush, credentials or database operations.
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

PACKAGE = 'infra/hermes/error-reliability-20261028'
RELEASE = 'hermes-reliability-20261029-92686a36'
SOURCE_COMMIT = '92686a3619cabfca699a4cdd1aa3bbf56e9874e2'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
FILES = {
    'HermesKnowledgeRefresh.php': (
        '8297fda545f51efbc6dc5a91e45b1a7946691ccd2aba30aad35bbc76e125ea76',
        '2b5a9e361afd7969d1693c4360cbd1d2cdaf5adcb34fd3cebf3a0b7716d786ec'),
    'HermesOperationsReview.php': (
        'b0ef327a6625bed8dab4f73b3b080ff465f5e2cd3d1f7327df09894b977afc49',
        'f8e24b6082ba8d7fd8be0c08e4abcb6deab2cbd037244b98b1e3118cb2aeca8d'),
}
DEPENDENCIES = {
    'HermesKnowledge.php': 'dd59914f397f9c5f1860deb356d3107ebcfc58d5ee4336ad344b63413bf420b7',
    'HermesOperations.php': 'ba1502378e7a4f4c2c0eb1bb2fdd352c2c4c3f5be491df33bcb7bdba278846e5',
    'HermesOperationsStore.php': '6583a234855b55ada896e82975ad323a372ed04017a1a2903b2697f4359c966d',
}


class ReleaseError(RuntimeError):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def regular(root, relative):
    path = root / relative
    cursor = path
    while cursor != root:
        if cursor.is_symlink():
            raise ReleaseError('symlink_in_release_path')
        cursor = cursor.parent
    if not path.is_file():
        raise ReleaseError('missing_regular_release_file')
    return path


def check(path, expected):
    data = path.read_bytes()
    if digest(data) != expected:
        raise ReleaseError('hash_drift:' + path.name)
    return data


def atomic_write(path, data, mode):
    temporary = path.with_name(path.name + '.' + RELEASE + '.stage')
    fd = os.open(str(temporary), os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
            os.fchmod(handle.fileno(), mode)
        os.replace(str(temporary), str(path))
    finally:
        if temporary.exists() and not temporary.is_symlink():
            temporary.unlink()


@contextlib.contextmanager
def locks(home):
    handles = []
    try:
        for name in ('.servernet-web-release.lock', 'servernet-release.lock'):
            fd = os.open(str(home / name), os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
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
        raise ReleaseError('cannot_observe_existing_cron')
    return {'sha256': digest(result.stdout), 'jobs': sum(
        bool(line.strip()) and not line.lstrip().startswith(b'#')
        and (line.split(maxsplit=1)[0].startswith(b'@') or len(line.split()) >= 6)
        for line in result.stdout.splitlines())}


def receipt_write(release, receipt):
    atomic_write(release / 'receipt.json',
                 (json.dumps(receipt, indent=2, sort_keys=True) + '\n').encode(), 0o600)


def validate_dependencies(home):
    for name, expected in DEPENDENCIES.items():
        check(regular(home, 'servernet_app/app/Services/Hermes/' + name), expected)
    regular(home, 'servernet_app/app/Services/Bale/Admin/HermesMiniAppStore.php')


def run(home, source, lint=php_lint, observe_cron=cron_snapshot):
    """Injection points support isolated fake-home tests, never CLI overrides."""
    os.umask(0o077)
    with locks(home):
        validate_dependencies(home)
        for name, (_, after) in FILES.items():
            check(regular(source, PACKAGE + '/' + name), after)
        parent = home / 'deploy-backups'
        if parent.is_symlink() or (parent.exists() and not parent.is_dir()):
            raise ReleaseError('invalid_private_backup_parent')
        parent.mkdir(mode=0o700, exist_ok=True)
        release = parent / RELEASE
        if release.is_symlink():
            raise ReleaseError('symlink_release')
        receipt_path = release / 'receipt.json'
        if not release.exists():
            before = {}
            for name, (expected, _) in FILES.items():
                target = regular(home, 'servernet_app/app/Console/Commands/' + name)
                before[name] = (check(target, expected), stat.S_IMODE(target.stat().st_mode))
            cron = observe_cron()
            release.mkdir(mode=0o700)
            for name, (data, mode) in before.items():
                atomic_write(release / (name + '.before'), data, 0o600)
                staged = release / (name + '.after')
                atomic_write(staged, (source / PACKAGE / name).read_bytes(), 0o600)
                lint(staged)
            receipt = {'phase': 'prepared', 'source_commit': SOURCE_COMMIT,
                       'prepared_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                       'cron_before': cron, 'files': {
                           name: {'before_sha256': hashes[0], 'after_sha256': hashes[1],
                                  'mode': before[name][1]} for name, hashes in FILES.items()}}
            receipt_write(release, receipt)
            return receipt
        receipt = json.loads(regular(release, 'receipt.json').read_text())
        if receipt.get('source_commit') != SOURCE_COMMIT or set(receipt.get('files', {})) != set(FILES):
            raise ReleaseError('receipt_contract_drift')
        if observe_cron() != receipt['cron_before']:
            raise ReleaseError('cron_changed_since_prepare')
        if receipt['phase'] == 'prepared':
            for name, (before, after) in FILES.items():
                target = regular(home, 'servernet_app/app/Console/Commands/' + name)
                check(target, before)
                check(regular(release, name + '.before'), before)
                check(regular(release, name + '.after'), after)
                if stat.S_IMODE(target.stat().st_mode) != receipt['files'][name]['mode']:
                    raise ReleaseError('mode_drift')
                lint(release / (name + '.after'))
            receipt['phase'] = 'installing'
            receipt_write(release, receipt)
            installed = []
            try:
                for name, (before, after) in FILES.items():
                    target = regular(home, 'servernet_app/app/Console/Commands/' + name)
                    check(target, before)
                    atomic_write(target, check(release / (name + '.after'), after),
                                 receipt['files'][name]['mode'])
                    installed.append(name)
                    check(target, after)
                    lint(target)
                if observe_cron() != receipt['cron_before']:
                    raise ReleaseError('cron_changed_during_apply')
                receipt['phase'] = 'installed'
                receipt['installed_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
                receipt_write(release, receipt)
            except Exception:
                for name in reversed(installed):
                    target = regular(home, 'servernet_app/app/Console/Commands/' + name)
                    check(target, FILES[name][1])
                    atomic_write(target, check(release / (name + '.before'), FILES[name][0]),
                                 receipt['files'][name]['mode'])
                receipt['phase'] = 'rolled_back'
                receipt_write(release, receipt)
                raise
            return receipt
        if receipt['phase'] == 'installed':
            for name, (_, after) in FILES.items():
                target = regular(home, 'servernet_app/app/Console/Commands/' + name)
                check(target, after)
                lint(target)
            receipt['phase'] = 'verified'
            receipt['verified_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
            receipt['natural_command_acceptance'] = 'pending_existing_scheduler'
            receipt['cron_after'] = observe_cron()
            receipt_write(release, receipt)
            return receipt
        raise ReleaseError('sealed_or_incomplete_release:' + receipt['phase'])


if __name__ == '__main__':
    try:
        home = Path('/home/servernetcloud')
        if Path.home() != home or os.getuid() == 0:
            raise ReleaseError('unexpected_account')
        result = run(home, Path(__file__).resolve().parent.parent)
        print(json.dumps(result, sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'error_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, ReleaseError) else 'review_private_task_status'}))
        sys.exit(1)
