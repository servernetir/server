#!/usr/bin/env python3
"""Two static asset destinations, exact hashes, private backup and homepage cache refresh."""
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
SOURCE = 'website/public/assets/css/studio-carousel.css'
TARGETS = (APP / 'public/assets/css/studio-carousel.css',
           HOME / 'public_html/assets/css/studio-carousel.css')
BEFORE = 'aa67283ed9230bdedf4ad30b85d691528676db13a45b28f731c72bf471790a05'
AFTER = 'c590d51d448652d53ccfdf300aea06a8066e69aeec7b5d715dac495c4674e4e2'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def current(path):
    assert path.is_file() and not path.is_symlink(), 'Unexpected target'
    return digest(path.read_bytes())


def write(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.globe-compact-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, mode)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def refresh_home():
    # The PHP sources and route table are unchanged. Only HTML with old CSS
    # version URLs needs invalidation, not any shared application/cache state.
    php = r'''require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cache = $app['cache']->store();
$gen = (int) $cache->get('page:gen', 0);
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host)
  foreach (['', '/index.php'] as $base)
    foreach (['/', 'en', 'tr'] as $path)
      $cache->forget('page:g'.$gen.':'.sha1($host.'|'.$base.'|'.$path));'''
    subprocess.run(['/opt/cpanel/ea-php84/root/usr/bin/php', '-r', php],
                   cwd=APP, check=True, stdout=subprocess.DEVNULL)


def main():
    mode, commit = sys.argv[1:3]
    assert mode in ('apply', 'verify', 'rollback') and re.fullmatch('[a-f0-9]{40}', commit)
    assert Path.home() == HOME and os.getuid() != 0
    stage = HOME / 'deploy-backups' / ('home-globe-compact-20261123-' + commit[:12])
    stage.mkdir(parents=True, exist_ok=True, mode=0o700)
    assert not stage.is_symlink()
    os.chmod(stage, 0o700)
    with (APP / 'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if mode == 'verify':
            assert all(current(p) == AFTER for p in TARGETS)
        elif mode == 'rollback':
            assert all(current(p) == AFTER for p in TARGETS)
            originals = [(stage / ('before-' + str(i))).read_bytes() for i in range(2)]
            assert all(digest(data) == BEFORE for data in originals)
            for path, data in zip(TARGETS, originals):
                write(path, data, path.stat().st_mode & 0o777)
            refresh_home()
            assert all(current(p) == BEFORE for p in TARGETS)
        else:
            assert not (stage / 'receipt.json').exists(), 'Already installed'
            assert all(current(p) == BEFORE for p in TARGETS)
            with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'
                                        + commit + '/' + SOURCE, timeout=20) as response:
                payload = response.read()
            assert digest(payload) == AFTER, 'Unexpected release bytes'
            originals = [p.read_bytes() for p in TARGETS]
            modes = [p.stat().st_mode & 0o777 for p in TARGETS]
            for i, original in enumerate(originals):
                assert digest(original) == BEFORE
                write(stage / ('before-' + str(i)), original, 0o600)
            changed = []
            try:
                for i, path in enumerate(TARGETS):
                    assert current(path) == BEFORE, 'Concurrent live change'
                    write(path, payload, modes[i])
                    changed.append(i)
                assert all(current(p) == AFTER for p in TARGETS)
                refresh_home()
                write(stage / 'receipt.json', json.dumps({'sha': commit, 'before': BEFORE,
                    'after': AFTER, 'files': 2}).encode(), 0o600)
            except BaseException:
                for i in reversed(changed):
                    if current(TARGETS[i]) == AFTER:
                        write(TARGETS[i], originals[i], modes[i])
                refresh_home()
                raise
        print(json.dumps({'status': mode, 'files': 2, 'sha': commit,
                          'sha256': BEFORE if mode == 'rollback' else AFTER,
                          'general_cache_flush': False}))


if __name__ == '__main__':
    main()
