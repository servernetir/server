#!/usr/bin/env python3
"""VM127-only guarded installation; source/payload staged privately by the owner."""
import ast
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import stat
import subprocess
import sys
import time

BASE = Path('/usr/local/lib/servernet-hermes/ops')
STATE = Path('/var/lib/servernet-hermes-owner')
BACKUP = Path('/root/hermes-assistant-20261102')
EXPECTED = '5bd27ccdc759ad77fc0862da9ca9cf6fde426375a15558c6973cb1d635802048'


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def regular(path):
    if any(p.is_symlink() for p in (path, *path.parents)) or not path.is_file():
        raise RuntimeError('regular_private_path_required')
    return path


def service(*args):
    return subprocess.run(['systemctl', *args], check=True, capture_output=True, text=True).stdout.strip()


def idle():
    with sqlite3.connect('file:'+str(STATE/'jobs.db')+'?mode=ro', uri=True) as db:
        if db.execute("SELECT count(*) FROM jobs WHERE status IN ('queued','running')").fetchone()[0]:
            raise RuntimeError('owner_queue_busy')


def install(source):
    if os.geteuid() != 0 or not STATE.is_dir():
        raise RuntimeError('unexpected_host_or_account')
    os.umask(0o077)
    before = regular(BASE/'owner_ingress.py')
    if digest(before) != EXPECTED:
        raise RuntimeError('ingress_hash_drift')
    files = ['owner_project_context.py', 'owner_ingress.py']
    for name in files:
        ast.parse(regular(source/name).read_text())
    if (BASE/'owner_project_context.py').exists():
        raise RuntimeError('helper_already_present')
    sys.path.insert(0, str(source))
    import owner_project_context as projects
    payload = regular(source/'project-references.json').read_bytes()
    if len(payload) > projects.MAX_BYTES:
        raise RuntimeError('reference_size')
    rows = projects._validate(json.loads(payload))
    lockfd = os.open('/run/lock/servernet-hermes-owner-deploy.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(lockfd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        idle()
        if digest(regular(BASE/'owner_ingress.py')) != EXPECTED:
            raise RuntimeError('ingress_hash_drift')
        BACKUP.mkdir(mode=0o700, exist_ok=True)
        if (BACKUP/'installed.json').exists() or (BACKUP/'owner_ingress.before.py').exists():
            raise RuntimeError('existing_release_receipt')
        broker_pid = service('show', 'servernet-hermes-ops.service', '--property=MainPID', '--value')
        source_stat = before.stat()
        shutil.copy2(before, BACKUP/'owner_ingress.before.py')
        os.chmod(BACKUP/'owner_ingress.before.py', 0o600)
        service('stop', 'servernet-hermes-owner.service')
        installed = []
        try:
            idle()
            if digest(regular(BASE/'owner_ingress.py')) != EXPECTED:
                raise RuntimeError('ingress_hash_drift')
            for name, data, mode in [('project-references.json', payload, 0o600)] + [(n, (source/n).read_bytes(), 0o755) for n in files]:
                target = STATE/name if name.endswith('.json') else BASE/name
                if target.is_symlink() or (name != 'owner_ingress.py' and target.exists()):
                    raise RuntimeError('unexpected_destination')
                temp = target.with_name('.'+target.name+'.assistant-stage')
                with temp.open('xb') as out:
                    out.write(data); out.flush(); os.fsync(out.fileno())
                os.chmod(temp, mode)
                if name.endswith('.json'):
                    state_stat = STATE.stat()
                    os.chown(temp, state_stat.st_uid, state_stat.st_gid)
                if name == 'owner_ingress.py':
                    os.chown(temp, source_stat.st_uid, source_stat.st_gid)
                    os.chmod(temp, stat.S_IMODE(source_stat.st_mode))
                os.replace(temp, target)
                installed.append(target)
            service('start', 'servernet-hermes-owner.service')
            if service('is-active', 'servernet-hermes-owner.service') != 'active':
                raise RuntimeError('owner_service_inactive')
            if service('show', 'servernet-hermes-ops.service', '--property=MainPID', '--value') != broker_pid:
                raise RuntimeError('broker_changed_during_release')
        except Exception:
            service('stop', 'servernet-hermes-owner.service')
            shutil.copy2(BACKUP/'owner_ingress.before.py', BASE/'owner_ingress.py')
            os.chmod(BASE/'owner_ingress.py', stat.S_IMODE(source_stat.st_mode))
            os.chown(BASE/'owner_ingress.py', source_stat.st_uid, source_stat.st_gid)
            for target in installed:
                if target.name != 'owner_ingress.py': target.unlink()
            service('start', 'servernet-hermes-owner.service')
            raise
        result = {'release': 'hermes-assistant-20261102', 'installed_at': int(time.time()),
                  'source_files': {n: digest(BASE/n) for n in files}, 'private_reference_count': len(rows),
                  'broker_pid_preserved': True, 'restarted': ['servernet-hermes-owner.service'], 'queue_was_idle': True}
        (BACKUP/'installed.json').write_text(json.dumps(result, indent=2)+'\n')
        os.chmod(BACKUP/'installed.json', 0o600)
        return result


if __name__ == '__main__':
    print(json.dumps(install(Path(sys.argv[1]).resolve()), sort_keys=True))
