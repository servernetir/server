#!/usr/bin/env python3
"""Guarded pending-exposure source release; activation is a separate operation."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys

ROOT = Path('/home/servernetcloud/servernet_app')
QA = Path('/home/servernetcloud/traffic-batch-qa-20261105')
RELEASE = Path('/home/servernetcloud/traffic-exposure-release-20261105')
BASE = '9c3d8732336f4c2ae6c7c016818dd5c1b975a511'
CANDIDATE = '70402800682f6636ff9a7d7741857ae5de2369f4'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
PATHS = ['app/Services/Finance/ArvanTrafficExposure.php', 'app/Services/Finance/Wallet.php',
         'app/Services/Finance/ArvanTrafficCreditGuard.php', 'app/Services/Finance/ArvanTrafficMeter.php']
NEW = {PATHS[0]}

def check(ok, message):
    if not ok: raise RuntimeError(message)

def sha(data): return hashlib.sha256(data).hexdigest()

def read(path):
    check(path.is_file() and not path.is_symlink() and path.resolve() == path, 'unsafe_file')
    meta = path.stat()
    check(meta.st_uid == os.geteuid() and not meta.st_mode & 0o022 and meta.st_nlink == 1, 'unsafe_owner_or_mode')
    return path.read_bytes()

def fresh(path, data, mode=0o600):
    with path.open('xb') as out:
        os.chmod(path, mode)
        out.write(data); out.flush(); os.fsync(out.fileno())
    check(read(path) == data, 'write_verification_failed')

def lint(path):
    subprocess.run([PHP, '-l', str(path)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def main():
    os.umask(0o077)
    check(ROOT.resolve() == ROOT and QA.resolve() == QA, 'unexpected_roots')
    check(subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=QA).decode().strip() == CANDIDATE, 'qa_head_changed')
    RELEASE.mkdir(mode=0o700, exist_ok=True)
    check(RELEASE.resolve() == RELEASE and stat.S_IMODE(RELEASE.stat().st_mode) == 0o700, 'unsafe_release_directory')
    locks = []
    for name in ['storage/framework/arvan-meter-source-release.lock', 'storage/app/private/arvan-traffic-meter/run.lock']:
        path = ROOT / name
        read(path)
        handle = path.open('r+')
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        check(path.stat().st_ino == os.fstat(handle.fileno()).st_ino, 'lock_replaced')
        locks.append(handle)
    mode = sys.argv[1] if len(sys.argv) > 1 else '--plan'
    plan_path = RELEASE / 'plan.json'
    if mode == '--plan':
        check(not plan_path.exists(), 'plan_already_exists')
        entries = []
        for i, relative in enumerate(PATHS):
            target = ROOT / relative
            source = read(QA / 'website' / relative)
            reviewed = subprocess.check_output(['git', 'show', CANDIDATE + ':website/' + relative], cwd=QA)
            check(source == reviewed, 'qa_working_source_differs_from_reviewed_candidate')
            before = None if relative in NEW else read(target)
            if relative in NEW:
                check(not target.exists() and not target.is_symlink(), 'new_target_exists')
            else:
                base = subprocess.check_output(['git', 'show', BASE + ':website/' + relative], cwd=QA)
                check(before == base, 'live_source_differs_from_reviewed_base:' + relative)
            path = RELEASE / ('after-' + str(i) + '.php')
            fresh(path, source); lint(path)
            if before is not None: fresh(RELEASE / ('before-' + str(i) + '.php'), before)
            entries.append({'path': relative, 'before': None if before is None else sha(before), 'after': sha(source),
                            'mode': 0o644 if before is None else stat.S_IMODE(target.stat().st_mode)})
        fresh(plan_path, json.dumps({'base': BASE, 'candidate': CANDIDATE, 'entries': entries}, sort_keys=True).encode())
        print(json.dumps({'status': 'planned_not_installed', 'plan_sha256': sha(read(plan_path)), 'files': len(entries)}))
        return
    check(mode in ['--apply', '--verify'] and len(sys.argv) == 3, 'invalid_arguments')
    plan_bytes = read(plan_path)
    check(sha(plan_bytes) == sys.argv[2], 'plan_hash_changed')
    plan = json.loads(plan_bytes)
    check(plan['candidate'] == CANDIDATE and [x['path'] for x in plan['entries']] == PATHS, 'plan_scope_changed')
    if mode == '--verify':
        for i, entry in enumerate(plan['entries']):
            target = read(ROOT / entry['path'])
            check(sha(target) == entry['after'], 'installed_source_changed')
            reviewed = subprocess.check_output(['git', 'show', CANDIDATE + ':website/' + entry['path']], cwd=QA)
            check(target == reviewed, 'installed_source_differs_from_reviewed_candidate')
        print(json.dumps({'status': 'verified', 'files': len(PATHS)})); return
    check(not (RELEASE / 'receipt.json').exists(), 'already_applied')
    prepared = []
    for i, entry in enumerate(plan['entries']):
        target = ROOT / entry['path']
        check((not target.exists() and not target.is_symlink()) if entry['before'] is None else sha(read(target)) == entry['before'], 'live_precondition_changed')
        after = read(RELEASE / ('after-' + str(i) + '.php'))
        check(sha(after) == entry['after'], 'staged_source_changed')
        lint(RELEASE / ('after-' + str(i) + '.php'))
        if entry['before'] is not None: check(sha(read(RELEASE / ('before-' + str(i) + '.php'))) == entry['before'], 'backup_changed')
        temp = target.with_name(target.name + '.exposure-20261105')
        fresh(temp, after, entry['mode']); prepared.append(temp)
    installed = []
    try:
        for i, entry in enumerate(plan['entries']):
            target = ROOT / entry['path']
            check((not target.exists() and not target.is_symlink()) if entry['before'] is None else sha(read(target)) == entry['before'], 'last_precondition_changed')
            os.replace(prepared[i], target); installed.append((i, entry))
            check(sha(read(target)) == entry['after'], 'post_install_hash_failed')
        fresh(RELEASE / 'receipt.json', json.dumps({'status': 'installed_sources_activation_pending', 'candidate': CANDIDATE, 'files': len(PATHS)}).encode())
        print(json.dumps({'status': 'installed_sources_activation_pending', 'files': len(PATHS)}))
    except Exception:
        for i, entry in reversed(installed):
            target = ROOT / entry['path']
            check(sha(read(target)) == entry['after'], 'rollback_refused_newer_source')
            if entry['before'] is None: target.unlink()
            else:
                temp = target.with_name(target.name + '.exposure-rollback-20261105')
                fresh(temp, read(RELEASE / ('before-' + str(i) + '.php')), entry['mode']); os.replace(temp, target)
        raise

if __name__ == '__main__': main()
