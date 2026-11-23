#!/usr/bin/env python3
"""Pinned, reversible release of the seventh homepage slide to both asset roots."""
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
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
RELEASE = 'home-hardware-hero-20261123'
PREFIX = 'scripts/' + RELEASE + '/'


def public_pair(asset):
    return [('website/public/' + asset, root + '/' + asset)
            for root in ('servernet_app/public', 'public_html')]


# New dependencies first; the homepage includes the new slide only at the end.
SCOPE = [pair for asset in ('assets/img/home-parts-exploded.webp',
                          'assets/img/home-parts-cutout.svg', 'assets/css/home-parts-slide.css')
         for pair in public_pair(asset)]
SCOPE += [('website/resources/views/partials/home-parts-slide.blade.php',
           'servernet_app/resources/views/partials/home-parts-slide.blade.php')]
SCOPE += [('website/lang/' + locale + '/home_slides.php',
           'servernet_app/lang/' + locale + '/home_slides.php') for locale in ('fa', 'en', 'tr')]
SCOPE += public_pair('assets/js/studio-carousel.js')
SCOPE += [('website/resources/views/partials/studio-carousel-controls.blade.php',
           'servernet_app/resources/views/partials/studio-carousel-controls.blade.php'),
          ('website/resources/views/pages/home.blade.php', 'servernet_app/resources/views/pages/home.blade.php')]
HELPERS = {'activation_common.py': 'scripts/mcp-auto-approval-20261122/activate.py',
           'fcgi.py': 'scripts/mcp-auto-approval-20261122/fcgi.py', 'activate.php': PREFIX + 'activate.php'}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def fetch(commit, path):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/' + commit + '/' + path, timeout=30) as response:
        return response.read()


def write(path, data, mode=0o644):
    """Replace one known file atomically, keeping stage/backup bytes private."""
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.parts-release-', dir=path.parent)
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


def manifest_for(commit):
    manifest = json.loads(fetch(commit, PREFIX + 'manifest.json'))
    rows = manifest['files']
    assert [(row['source'], row['destination']) for row in rows] == SCOPE, 'Invalid release scope/order'
    assert {name: item['path'] for name, item in manifest['helpers'].items()} == HELPERS, 'Invalid helper scope'
    for row in rows:
        assert row['before'] is None or re.fullmatch('[a-f0-9]{64}', row['before'])
        assert re.fullmatch('[a-f0-9]{64}', row['after'])
        assert bool(row.get('patch')) == row['destination'].endswith('/pages/home.blade.php')
        if row.get('patch'):
            assert re.fullmatch('[a-f0-9]{64}', row['source_sha256'])
    assert all(re.fullmatch('[a-f0-9]{64}', h['sha256']) for h in manifest['helpers'].values())
    return manifest


def destination(row):
    path = HOME / row['destination']
    assert row['destination'] in {dest for _, dest in SCOPE}, 'Out-of-scope destination'
    assert not path.is_symlink() and path.parent.resolve().is_relative_to(HOME.resolve()), 'Unexpected target path'
    return path


def current(row):
    path = destination(row)
    assert not path.exists() or path.is_file(), 'Target is not a regular file'
    return sha(path.read_bytes()) if path.exists() else None


def preflight(records, phase):
    for row in records:
        assert current(row) == row[phase], 'Unexpected live bytes: ' + row['destination']


def prepare(commit, stage, manifest):
    assert not any((stage / name).exists() for name in ('prepared.json', 'files', 'backup', 'receipt.json')), 'Existing/incomplete release; inspect before retry'
    preflight(manifest['files'], 'before')
    edits = json.loads(fetch(commit, PREFIX + 'edits.json'))
    records, fetched = [], {}
    for index, row in enumerate(manifest['files']):
        path = destination(row)
        if row['source'] not in fetched:
            fetched[row['source']] = fetch(commit, row['source'])
        source = fetched[row['source']]
        if row.get('patch'):
            assert sha(source) == row['source_sha256'], 'Reviewed Git source changed'
            data = path.read_bytes()
            assert sha(data) == row['before'], 'Live source changed during preparation'
            for edit in edits[row['source']]:
                old, new = edit['old'].encode(), edit['new'].encode()
                assert data.count(old) == edit['count'] == 1, 'Unexpected patch anchor'
                data = data.replace(old, new)
        else:
            data = source
        assert sha(data) == row['after'], 'Payload mismatch: ' + row['source']
        write(stage / 'files' / str(index), data, 0o600)
        if path.suffix == '.php':
            subprocess.run([PHP, '-l', str(stage / 'files' / str(index))], check=True, stdout=subprocess.DEVNULL)
        mode = (path.stat().st_mode & 0o777) if path.exists() else 0o644
        records.append(dict(row, mode=mode))
    # Fetch and validate activation helpers before touching any live file.
    for name, helper in manifest['helpers'].items():
        data = fetch(commit, helper['path'])
        assert sha(data) == helper['sha256'], 'Activation helper changed'
        write(stage / name, data, 0o600)
    write(stage / 'prepared.json', json.dumps(records).encode(), 0o600)
    return records


def activate(stage, manifest, phase):
    for name, helper in manifest['helpers'].items():
        path = stage / name
        assert not path.is_symlink() and sha(path.read_bytes()) == helper['sha256'], 'Staged helper changed'
    modules = {}
    for name in ('activation_common', 'fcgi'):
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
                'SERVERNET_LOCAL_RELEASE': RELEASE, 'SERVERNET_PARTS_PLAN': str(stage / 'prepared.json'),
                'SERVERNET_PARTS_PHASE': phase}
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                connection.settimeout(20)
                connection.connect(str(target))
                connection.sendall(fcgi.record(1, struct.pack('!HB5s', 1, 0, b'\0' * 5))
                    + fcgi.record(4, fcgi.encode_params(params)) + fcgi.record(4) + fcgi.record(5))
                result = fcgi.receive(connection, time.monotonic() + 20)
            if result.get('status') == 'stopped':
                raise RuntimeError('Scoped activation stopped: ' + json.dumps({k: result.get(k) for k in ('step', 'reason', 'file', 'line')}))
            assert result.get('compiled_views') == (3 if phase == 'after' else 2)
            assert result.get('phase') == phase and result.get('general_cache_flush') is False
            assert result.get('route_cache_rebuilt') is False
            results.append(result)
    assert len(results) == 2, 'Both verified application pools required'
    return results


def restore(stage, manifest, records, changed):
    # All changed files must still be this release's exact bytes; never overwrite
    # a later edit. Backup integrity is checked before any restoration.
    for index, row in changed:
        assert current(row) == row['after'], 'Concurrent change prevents rollback: ' + row['destination']
        if row['before'] is not None:
            assert sha((stage / 'backup' / str(index)).read_bytes()) == row['before'], 'Backup mismatch'
    for index, row in reversed(changed):
        if row['before'] is not None:
            write(destination(row), (stage / 'backup' / str(index)).read_bytes(), row['mode'])
    if any(row['before'] is not None for _, row in changed):
        # New slide dependencies remain in place until the old compiled views
        # are confirmed in both FPM pools.
        activate(stage, manifest, 'before')
    for index, row in reversed(changed):
        if row['before'] is None:
            retired = stage / 'retired-dependencies' / str(index)
            retired.parent.mkdir(mode=0o700, exist_ok=True)
            assert not retired.parent.is_symlink() and not retired.exists() and current(row) == row['after']
            os.replace(destination(row), retired)
    preflight(records, 'before')


def apply(stage, manifest, records, commit):
    preflight(records, 'before')
    for index, row in enumerate(records):
        assert sha((stage / 'files' / str(index)).read_bytes()) == row['after'], 'Staged payload changed'
        if row['before'] is not None:
            original = destination(row).read_bytes()
            assert sha(original) == row['before'], 'Concurrent live change'
            write(stage / 'backup' / str(index), original, 0o600)
    changed = []
    try:
        for index, row in enumerate(records):
            assert current(row) == row['before'], 'Concurrent live change'
            candidate = (stage / 'files' / str(index)).read_bytes()
            assert sha(candidate) == row['after']
            write(destination(row), candidate, row['mode'])
            changed.append((index, row))
        preflight(records, 'after')
        results = activate(stage, manifest, 'after')
        preflight(records, 'after')
        write(stage / 'receipt.json', json.dumps({'sha': commit, 'files': records, 'activation': results}).encode(), 0o600)
    except BaseException:
        restore(stage, manifest, records, changed)
        raise
    return results


def run(mode, commit, stage):
    manifest = manifest_for(commit)
    if mode == 'install':
        records = prepare(commit, stage, manifest)
        results = apply(stage, manifest, records, commit)
        print(json.dumps({'status': 'installed_and_verified', 'sha': commit, 'files': len(records), 'activation': results}))
        return
    records = json.loads((stage / 'prepared.json').read_text())
    assert [{k: row[k] for k in manifest['files'][i]} for i, row in enumerate(records)] == manifest['files']
    if mode == 'verify':
        preflight(records, 'after')
        print(json.dumps({'status': 'verified', 'sha': commit, 'files': len(records)}))
        return
    assert mode == 'rollback' and (stage / 'receipt.json').is_file(), 'Only completed release can be rolled back'
    preflight(records, 'after')
    restore(stage, manifest, records, list(enumerate(records)))
    write(stage / 'rollback.json', json.dumps({'sha': commit, 'status': 'rolled_back'}).encode(), 0o600)
    print(json.dumps({'status': 'rolled_back', 'sha': commit, 'files': len(records)}))


def main():
    mode, commit = sys.argv[1:3]
    assert mode in ('install', 'verify', 'rollback') and re.fullmatch('[a-f0-9]{40}', commit)
    assert os.getuid() != 0 and Path.home() == HOME, 'Unexpected release account'
    stage = HOME / 'deploy-backups' / (RELEASE + '-' + commit[:12])
    stage.mkdir(parents=True, exist_ok=True, mode=0o700)
    assert not stage.is_symlink()
    os.chmod(stage, 0o700)
    assert (HOME / 'public_html').resolve() != (ROOT / 'public').resolve(), 'Unexpected shared asset roots'
    # The shared shipping lock is held from complete preflight through receipt.
    with (ROOT / 'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        assert not (ROOT / 'bootstrap/cache/config.php').exists(), 'Unexpected config cache'
        assert not list((ROOT / 'bootstrap/cache').glob('routes-*.php')), 'Unexpected route cache'
        run(mode, commit, stage)


if __name__ == '__main__':
    main()
