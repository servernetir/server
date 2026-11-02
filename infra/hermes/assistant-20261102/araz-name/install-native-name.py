#!/usr/bin/env python3
"""Guarded VM127 owner-ingress display-name update. No other runtime target."""
import ast
import fcntl
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import stat
import subprocess
import sys
import time

BASE = Path('/usr/local/lib/servernet-hermes/ops')
STATE = Path('/var/lib/servernet-hermes-owner')
BACKUP = Path('/root/hermes-assistant-araz-20261102')
LOCK = Path('/run/lock/servernet-hermes-owner-deploy.lock')
TARGET = 'owner_ingress.py'
BEFORE = '16fac65b9b4739222a8f8a71b91a892b11634148feadc198675e6dae049e2919'
AFTER = '67d592b157105af6d146a7dca3c84368fbcab966ed2bdb63cbdcd618ed5828bd'
HELPER = '1ea6b2514784d1380ba43d31385d1b6e760f2149ae511aac147077df92c93858'
OWNER_SERVICE = 'servernet-hermes-owner.service'
BROKER_SERVICE = 'servernet-hermes-ops.service'
RELEASE = 'araz-owner-ingress-20261102'
# Observed VM127 metadata, 2026-11-02. These are checks, never chown/chmod actions.
STATE_METADATA = {'uid': 1000, 'gid': 1000, 'mode': 0o700}
STATE_FILE_METADATA = {'uid': 1000, 'gid': 1000, 'mode': 0o600}
BACKUP_METADATA = {'uid': 0, 'gid': 0, 'mode': 0o700}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def regular(path):
    if any(p.is_symlink() for p in (path, *path.parents)) or not path.is_file():
        raise RuntimeError('regular_path_required')
    return path


def private_directory(path, expected, label):
    if any(p.is_symlink() for p in (path, *path.parents)) or not path.is_dir():
        raise RuntimeError('private_directory_required')
    if metadata_of(path) != expected:
        raise RuntimeError(label + '_metadata_drift')


def service(*args):
    return subprocess.run(['systemctl', *args], check=True, capture_output=True, text=True).stdout.strip()


def broker_pid():
    value = service('show', BROKER_SERVICE, '--property=MainPID', '--value')
    if not value.isdigit() or int(value) <= 0:
        raise RuntimeError('broker_pid_unavailable')
    return value


def idle():
    regular(STATE / 'jobs.db')
    with sqlite3.connect('file:' + str(STATE / 'jobs.db') + '?mode=ro', uri=True, timeout=5) as db:
        db.execute('PRAGMA query_only=ON')
        if db.execute("SELECT count(*) FROM jobs WHERE status IN ('queued','running')").fetchone()[0]:
            raise RuntimeError('owner_queue_busy')


def checked_target(expected):
    target = regular(BASE / TARGET)
    if digest(target.read_bytes()) != expected:
        raise RuntimeError('ingress_hash_drift')
    if digest(regular(BASE / 'owner_project_context.py').read_bytes()) != HELPER:
        raise RuntimeError('helper_hash_drift')
    return target


def exclusive(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as out:
        out.write(data)
        out.flush()
        os.fsync(out.fileno())


def atomic_target(data, metadata):
    target = BASE / TARGET
    stage = BASE / '.owner_ingress.py.araz-stage'
    exclusive(stage, data)
    try:
        os.chown(stage, metadata['uid'], metadata['gid'])
        os.chmod(stage, metadata['mode'])
        os.replace(stage, target)
        fd = os.open(BASE, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    finally:
        if stage.exists():
            stage.unlink()


def metadata_of(path):
    info = path.stat()
    return {'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode)}


def preflight():
    if os.geteuid() != 0 or not BASE.is_dir():
        raise RuntimeError('unexpected_host_or_account')
    private_directory(STATE, STATE_METADATA, 'state_directory')
    private_directory(BACKUP, BACKUP_METADATA, 'backup_directory')
    for name in ('jobs.db', 'project-references.json'):
        if metadata_of(regular(STATE / name)) != STATE_FILE_METADATA:
            raise RuntimeError('state_file_metadata_drift')
    if service('is-active', OWNER_SERVICE) != 'active':
        raise RuntimeError('owner_service_not_active')


def lock_file():
    fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
        os.close(fd)
        raise RuntimeError('invalid_release_lock')
    return os.fdopen(fd, 'w')


def restore_if_owned(metadata):
    # An error after replacement cannot overwrite a newer independent change.
    checked_target(AFTER)
    saved = regular(BACKUP / 'araz-owner-ingress.before.py').read_bytes()
    if digest(saved) != BEFORE:
        raise RuntimeError('backup_hash_drift')
    atomic_target(saved, metadata)
    checked_target(BEFORE)


def install(source):
    os.umask(0o077)
    preflight()
    staged = regular(Path(source) / TARGET).read_bytes()
    if digest(staged) != AFTER:
        raise RuntimeError('source_hash_drift')
    ast.parse(staged.decode('utf-8'))
    with lock_file() as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        idle()
        target = checked_target(BEFORE)
        for name in ('araz-owner-ingress.before.py', 'araz-installed.json', 'araz-rolled-back.json'):
            if (BACKUP / name).exists() or (BACKUP / name).is_symlink():
                raise RuntimeError('existing_name_release_record')
        metadata = metadata_of(target)
        references_hash = digest(regular(STATE / 'project-references.json').read_bytes())
        pid = broker_pid()
        original = target.read_bytes()
        if digest(original) != BEFORE:
            raise RuntimeError('ingress_hash_drift')
        exclusive(BACKUP / 'araz-owner-ingress.before.py', original)
        stopped = replaced = False
        try:
            service('stop', OWNER_SERVICE)
            stopped = True
            idle()
            checked_target(BEFORE)
            atomic_target(staged, metadata)
            replaced = True
            checked_target(AFTER)
            if metadata_of(BASE / TARGET) != metadata:
                raise RuntimeError('target_metadata_changed')
            if digest(regular(STATE / 'project-references.json').read_bytes()) != references_hash:
                raise RuntimeError('private_reference_changed')
            service('start', OWNER_SERVICE)
            stopped = False
            if service('is-active', OWNER_SERVICE) != 'active':
                raise RuntimeError('owner_service_inactive')
            if broker_pid() != pid:
                raise RuntimeError('broker_pid_changed')
            result = {'release': RELEASE, 'installed_at': int(time.time()), 'before_sha256': BEFORE,
                      'after_sha256': AFTER, 'metadata': metadata, 'helper_unchanged': True,
                      'private_references_unchanged': True, 'broker_pid_preserved': True,
                      'queue_idle_before_and_after_stop': True, 'restarted': [OWNER_SERVICE]}
            exclusive(BACKUP / 'araz-installed.json', (json.dumps(result, indent=2) + '\n').encode('ascii'))
            return result
        except Exception:
            # os.replace can succeed before a later directory fsync fails.
            if not replaced and digest(regular(BASE / TARGET).read_bytes()) == AFTER:
                replaced = True
            if replaced:
                try:
                    service('stop', OWNER_SERVICE)
                    stopped = True
                    idle()
                    restore_if_owned(metadata)
                except Exception as rollback_error:
                    if stopped:
                        service('start', OWNER_SERVICE)
                    raise RuntimeError('name_release_rollback_blocked') from rollback_error
            if stopped:
                service('start', OWNER_SERVICE)
            raise


def rollback():
    os.umask(0o077)
    preflight()
    with lock_file() as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        idle()
        checked_target(AFTER)
        receipt = json.loads(regular(BACKUP / 'araz-installed.json').read_bytes())
        if receipt.get('release') != RELEASE or receipt.get('before_sha256') != BEFORE or receipt.get('after_sha256') != AFTER:
            raise RuntimeError('name_release_receipt_invalid')
        metadata = receipt.get('metadata')
        if not isinstance(metadata, dict) or set(metadata) != {'uid', 'gid', 'mode'} or any(type(v) is not int for v in metadata.values()):
            raise RuntimeError('name_release_metadata_invalid')
        if metadata_of(BASE / TARGET) != metadata:
            raise RuntimeError('target_metadata_changed')
        if (BACKUP / 'araz-rolled-back.json').exists() or (BACKUP / 'araz-rolled-back.json').is_symlink():
            raise RuntimeError('existing_rollback_record')
        pid = broker_pid()
        service('stop', OWNER_SERVICE)
        try:
            idle()
            restore_if_owned(metadata)
        finally:
            service('start', OWNER_SERVICE)
        if service('is-active', OWNER_SERVICE) != 'active' or broker_pid() != pid:
            raise RuntimeError('rollback_service_unverified')
        result = {'release': RELEASE, 'rolled_back_at': int(time.time()), 'after_sha256': BEFORE,
                  'broker_pid_preserved': True, 'restarted': [OWNER_SERVICE]}
        exclusive(BACKUP / 'araz-rolled-back.json', (json.dumps(result, indent=2) + '\n').encode('ascii'))
        return result


if __name__ == '__main__':
    try:
        if len(sys.argv) == 3 and sys.argv[1] == 'install':
            result = install(Path(sys.argv[2]))
        elif len(sys.argv) == 2 and sys.argv[1] == 'rollback':
            result = rollback()
        else:
            raise RuntimeError('usage_install_source_directory_or_rollback')
        print(json.dumps(result, sort_keys=True))
    except Exception as error:
        reason = str(error) if isinstance(error, RuntimeError) and str(error).replace('_', '').isalnum() else 'inspect_private_release_error'
        print(json.dumps({'status': 'stopped', 'reason': reason, 'error_class': type(error).__name__}))
        sys.exit(1)
