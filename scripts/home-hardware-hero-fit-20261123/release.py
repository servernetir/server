#!/usr/bin/env python3
"""Refresh two homepage assets in both roots, with exact-byte rollback."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import urllib.request

HOME = Path('/home/servernetcloud')
APP = HOME / 'servernet_app'
RELEASE = 'home-hardware-hero-fit-20261123'
PREFIX = 'scripts/' + RELEASE + '/'
SOURCES = ('assets/js/studio-carousel.js', 'assets/css/home-parts-slide.css')
SCOPE = [('website/public/' + source, root + '/' + source)
         for source in SOURCES for root in ('servernet_app/public', 'public_html')]


def sha(data):
    return hashlib.sha256(data).hexdigest()


def fetch(commit, source):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'
                                + commit + '/' + source, timeout=30) as response:
        return response.read()


def manifest(commit):
    rows = json.loads(fetch(commit, PREFIX + 'manifest.json'))['files']
    assert [(r['source'], r['destination']) for r in rows] == SCOPE, 'Unexpected scope'
    for row in rows:
        assert set(row) == {'source', 'destination', 'before', 'after'}
        assert all(re.fullmatch('[0-9a-f]{64}', row[k]) for k in ('before', 'after'))
        assert row['before'] != row['after']
    for first, second in zip(rows[::2], rows[1::2]):
        assert first['before'] == second['before'] and first['after'] == second['after']
    return rows


def target(row):
    path = HOME / row['destination']
    assert path.is_file() and not path.is_symlink() and path.resolve().is_relative_to(HOME), 'Unexpected asset target'
    return path


def check(rows, phase):
    for row in rows:
        assert sha(target(row).read_bytes()) == row[phase], 'Unexpected live bytes: ' + row['destination']


def write(path, data, mode):
    assert not path.is_symlink()
    fd, temporary = tempfile.mkstemp(prefix='.parts-fit-', dir=path.parent)
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


def refresh_home():
    # No Blade/PHP source changes. Only cached HTML containing the old asset
    # version URLs needs invalidation. Never flush the general cache.
    php = r'''require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cache = $app['cache']->store();
$gen = (int) $cache->get('page:gen', 0);
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host)
  foreach (['', '/index.php'] as $base)
    foreach (['/', 'en', 'tr'] as $path)
      $cache->forget('page:g'.$gen.':'.sha1($host.'|'.$base.'|'.$path));'''
    subprocess.run(['/opt/cpanel/ea-php84/root/usr/bin/php', '-r', php], cwd=APP,
                   check=True, stdout=subprocess.DEVNULL)


def restore(rows, backups, indices):
    for index in indices:
        row, (original, mode) = rows[index], backups[index]
        path = target(row)
        assert sha(path.read_bytes()) == row['after'], 'Concurrent change; refusing rollback: ' + row['destination']
        write(path, original, mode)
        assert sha(path.read_bytes()) == row['before']


def run(mode, commit, stage):
    rows = manifest(commit)
    receipt, prepared = stage / 'receipt.json', stage / 'prepared.json'
    if mode == 'verify':
        check(rows, 'after')
        return 'verified'
    if mode == 'rollback':
        assert receipt.is_file() and not receipt.is_symlink()
        assert json.loads(receipt.read_text())['sha'] == commit
        check(rows, 'after')
        modes = json.loads(prepared.read_text())['modes']
        backups = [((stage / ('before-' + str(i))).read_bytes(), modes[i]) for i in range(len(rows))]
        assert all(sha(data) == row['before'] for (data, _), row in zip(backups, rows)), 'Invalid backup'
        restore(rows, backups, reversed(range(len(rows))))
        check(rows, 'before')
        refresh_home()
        return 'rolled_back'
    assert mode == 'apply' and not prepared.exists() and not receipt.exists(), 'Already prepared or installed'
    check(rows, 'before')
    payloads = {source: fetch(commit, source) for source in {r['source'] for r in rows}}
    assert all(sha(payloads[r['source']]) == r['after'] for r in rows), 'Unexpected payload bytes'
    backups = [(target(r).read_bytes(), target(r).stat().st_mode & 0o777) for r in rows]
    assert all(sha(data) == row['before'] for (data, _), row in zip(backups, rows)), 'Concurrent change'
    for i, (data, _) in enumerate(backups):
        write(stage / ('before-' + str(i)), data, 0o600)
    write(prepared, json.dumps({'sha': commit, 'modes': [mode for _, mode in backups], 'files': rows}).encode(), 0o600)
    changed = []
    try:
        for index, row in enumerate(rows):
            path = target(row)
            assert sha(path.read_bytes()) == row['before'], 'Concurrent change: ' + row['destination']
            write(path, payloads[row['source']], backups[index][1])
            changed.append(index)
        check(rows, 'after')
        refresh_home()
        write(receipt, json.dumps({'sha': commit, 'files': rows}).encode(), 0o600)
    except BaseException:
        restore(rows, backups, reversed(changed))
        if changed:
            refresh_home()
        raise
    return 'installed_and_verified'


def main():
    mode, commit = sys.argv[1:3]
    assert mode in ('apply', 'verify', 'rollback') and re.fullmatch('[0-9a-f]{40}', commit)
    assert Path.home() == HOME and os.getuid() != 0, 'Unexpected account'
    stage = HOME / 'deploy-backups' / (RELEASE + '-' + commit[:12])
    stage.mkdir(mode=0o700, exist_ok=True)
    assert not stage.is_symlink() and stage.resolve() == stage
    os.chmod(stage, 0o700)
    with (APP / 'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        status = run(mode, commit, stage)
    print(json.dumps({'status': status, 'files': 4, 'sha': commit, 'general_cache_flush': False}))


if __name__ == '__main__':
    main()
