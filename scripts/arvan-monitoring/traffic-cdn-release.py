#!/usr/bin/env python3
"""Install one reviewed source file; never bootstrap Laravel or execute billing."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess

ROOT = Path('/home/servernetcloud/servernet_app')
QA = Path('/home/servernetcloud/traffic-batch-qa-20261105')
RELEASE = Path('/home/servernetcloud/traffic-cdn-release-20261105')
BASE = 'd304823a27ad83f4d72b7c3443ee990617920603'
CANDIDATE = 'a1dcc1bcd62f618ec1657abc202bf3a51198dbe7'
RELATIVE = 'app/Services/Finance/ArvanTrafficSource.php'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'

def check(ok, message):
    if not ok: raise RuntimeError(message)

def sha(data): return hashlib.sha256(data).hexdigest()

def read(path):
    check(path.resolve() == path and not path.is_symlink() and path.is_file(), 'unsafe_file')
    st = path.stat()
    check(st.st_uid == os.geteuid() and not st.st_mode & 0o022 and st.st_nlink == 1, 'unsafe_owner_or_mode')
    return path.read_bytes()

def fresh(path, data, mode=0o600):
    with path.open('xb') as out:
        os.chmod(path, mode); out.write(data); out.flush(); os.fsync(out.fileno())
    check(read(path) == data, 'write_verification_failed')

def main():
    os.umask(0o077)
    locks = []
    for name in ['storage/framework/arvan-meter-source-release.lock', 'storage/app/private/arvan-traffic-meter/run.lock']:
        path = ROOT / name; read(path); handle = path.open('r+')
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        check(path.stat().st_ino == os.fstat(handle.fileno()).st_ino, 'lock_replaced'); locks.append(handle)
    before = subprocess.check_output(['git', 'show', BASE + ':website/' + RELATIVE], cwd=QA)
    after = subprocess.check_output(['git', 'show', CANDIDATE + ':website/' + RELATIVE], cwd=QA)
    target = ROOT / RELATIVE
    check(read(target) == before, 'source_precondition_changed')
    mode = stat.S_IMODE(target.stat().st_mode)
    RELEASE.mkdir(mode=0o700)
    check(RELEASE.resolve() == RELEASE and stat.S_IMODE(RELEASE.stat().st_mode) == 0o700, 'unsafe_backup_directory')
    fresh(RELEASE / 'before.php', before); fresh(RELEASE / 'after.php', after)
    subprocess.run([PHP, '-l', str(RELEASE / 'after.php')], check=True, stdout=subprocess.DEVNULL)
    temp = target.with_name(target.name + '.cdn-20261105')
    rollback = target.with_name(target.name + '.cdn-rollback-20261105')
    fresh(temp, after, mode); fresh(rollback, before, mode)
    check(read(target) == before, 'last_precondition_changed')
    os.replace(temp, target)
    try:
        check(read(target) == after, 'post_install_verification_failed')
        fresh(RELEASE / 'receipt.json', json.dumps({'status': 'installed_verified', 'candidate': CANDIDATE,
              'before_sha256': sha(before), 'after_sha256': sha(after)}).encode())
    except Exception:
        check(read(target) == after, 'rollback_refused_newer_source'); os.replace(rollback, target); raise
    rollback.unlink()
    print(json.dumps({'status': 'installed_verified', 'files': 1, 'candidate': CANDIDATE}))

if __name__ == '__main__': main()
