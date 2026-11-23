#!/usr/bin/env python3
"""Pinned, limited homepage release; preserves the current live home template."""
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import time
import urllib.request

HOME = Path('/home/servernetcloud')
ROOT = HOME / 'servernet_app'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
RELEASE = 'home-four-slides-20261123'
PREFIX = 'scripts/' + RELEASE + '/'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def fetch(commit, path):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/' + commit + '/' + path, timeout=30) as response:
        return response.read()


def write(path, data, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.studio-release-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def current(row):
    path = HOME / row['destination']
    assert not path.is_symlink(), 'Symlink target'
    return sha(path.read_bytes()) if path.exists() else None


def preflight(records, phase):
    for row in records:
        assert current(row) == row[phase], 'Unexpected live bytes: ' + row['destination']


def activate(stage, manifest, phase):
    # Reuse already reviewed same-account socket ownership and HTTP-denial helpers.
    for name, source in manifest['helpers'].items():
        data = fetch(manifest['commit'], source['path'])
        assert sha(data) == source['sha256'], 'Activation helper changed'
        write(stage / name, data, 0o600)
    modules = {}
    for name in ['activation_common', 'fcgi']:
        spec = importlib.util.spec_from_file_location(name, stage / (name + '.py'))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        modules[name] = module
    common, fcgi = modules['activation_common'], modules['fcgi']
    fcgi.RELEASE = RELEASE
    results = []
    with common.temporary_primary(stage) as (primary, script_name):
        for target in common.observe_pools():
            assert common.owned_socket(target, os.getuid()), 'FPM ownership changed'
            params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1',
                'REQUEST_METHOD': 'POST', 'SCRIPT_FILENAME': str(primary), 'SCRIPT_NAME': script_name,
                'REQUEST_URI': script_name, 'DOCUMENT_ROOT': str(HOME / 'public_html'),
                'SERVER_NAME': 'servernet.cloud', 'HTTP_HOST': 'servernet.cloud', 'SERVER_PORT': '443',
                'HTTPS': 'on', 'REMOTE_ADDR': '127.0.0.1', 'CONTENT_LENGTH': '0',
                'SERVERNET_LOCAL_RELEASE': RELEASE, 'SERVERNET_STUDIO_PLAN': str(stage / 'prepared.json'),
                'SERVERNET_STUDIO_PHASE': phase}
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                connection.settimeout(20)
                connection.connect(str(target))
                connection.sendall(fcgi.record(1, struct.pack('!HB5s', 1, 0, b'\0' * 5))
                    + fcgi.record(4, fcgi.encode_params(params)) + fcgi.record(4) + fcgi.record(5))
                result = fcgi.receive(connection, time.monotonic() + 20)
            assert result.get('compiled_views') == 4 and result.get('general_cache_flush') is False
            results.append(result)
    return results


def run(mode, commit, stage):
    manifest = json.loads(fetch(commit, PREFIX + 'manifest.json'))
    manifest['commit'] = commit
    if mode == 'prepare':
        assert not (stage / 'receipt.json').exists(), 'Already installed'
        records = []
        for index, row in enumerate(manifest['files']):
            destination = HOME / row['destination']
            assert '..' not in destination.parts and not destination.is_symlink()
            before = destination.read_bytes() if destination.exists() else None
            assert (sha(before) if before is not None else None) == row['before'], 'Unexpected live bytes: ' + row['destination']
            if row.get('patch_home'):
                data = before
                for old, new in manifest['home_edits']:
                    old, new = old.encode(), new.encode()
                    assert data.count(old) == 1, 'Unexpected home anchor'
                    data = data.replace(old, new, 1)
            else:
                data = fetch(commit, row['source'])
            assert sha(data) == row['after'], 'Payload mismatch'
            write(stage / 'files' / str(index), data, 0o600)
            if row['destination'].endswith('.php'):
                subprocess.run([PHP, '-l', str(stage / 'files' / str(index))], check=True, stdout=subprocess.DEVNULL)
            records.append(dict(row, mode=(destination.stat().st_mode & 0o777) if before is not None else 0o644))
        write(stage / 'prepared.json', json.dumps(records).encode(), 0o600)
        print(json.dumps({'status': 'prepared', 'files': len(records), 'sha': commit}))
        return
    records = json.loads((stage / 'prepared.json').read_text())
    assert [{k: row[k] for k in manifest['files'][index]} for index, row in enumerate(records)] == manifest['files']
    if mode == 'verify':
        preflight(records, 'after')
        print(json.dumps({'status': 'verified', 'files': len(records), 'sha': commit}))
        return
    if mode == 'activate':
        preflight(records, 'after')
        print(json.dumps({'status': 'activated', 'pools': activate(stage, manifest, 'after')}))
        return
    if mode == 'rollback':
        preflight(records, 'after')
        for index, row in enumerate(records):
            if row['before'] is not None:
                assert sha((stage / 'backup' / str(index)).read_bytes()) == row['before']
        # Detach the include before retiring this release's new dependencies.
        for index, row in reversed(list(enumerate(records))):
            if row['before'] is not None:
                write(HOME / row['destination'], (stage / 'backup' / str(index)).read_bytes(), row['mode'])
        activate(stage, manifest, 'before')
        for row in records:
            if row['before'] is None:
                assert current(row) == row['after']
                (HOME / row['destination']).unlink()
        print(json.dumps({'status': 'rolled_back', 'sha': commit}))
        return
    assert mode == 'apply' and not (stage / 'receipt.json').exists(), 'Already installed'
    preflight(records, 'before')
    for index, row in enumerate(records):
        if row['before'] is not None:
            write(stage / 'backup' / str(index), (HOME / row['destination']).read_bytes(), 0o600)
    changed = []
    try:
        # Every dependency installs before the single homepage include.
        for index, row in enumerate(records):
            assert current(row) == row['before'], 'Concurrent live change'
            data = (stage / 'files' / str(index)).read_bytes()
            assert sha(data) == row['after']
            write(HOME / row['destination'], data, row['mode'])
            changed.append((index, row))
        write(stage / 'receipt.json', json.dumps({'sha': commit, 'files': records}).encode(), 0o600)
    except BaseException:
        for index, row in reversed(changed):
            if current(row) != row['after']:
                continue
            if row['before'] is None:
                (HOME / row['destination']).unlink()
            else:
                write(HOME / row['destination'], (stage / 'backup' / str(index)).read_bytes(), row['mode'])
        raise
    print(json.dumps({'status': 'installed', 'files': len(records), 'sha': commit}))


def main():
    mode, commit = sys.argv[1:3]
    assert mode in ['prepare', 'apply', 'verify', 'activate', 'rollback'] and re.fullmatch('[0-9a-f]{40}', commit)
    assert os.getuid() != 0 and Path.home() == HOME, 'Unexpected release account'
    stage = HOME / 'deploy-backups' / (RELEASE + '-' + commit[:12])
    stage.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(stage, 0o700)
    with (ROOT / 'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        run(mode, commit, stage)


if __name__ == '__main__':
    main()
