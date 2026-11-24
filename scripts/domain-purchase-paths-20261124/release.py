#!/usr/bin/env python3
"""Publish twelve domain purchase files; preserve reviewed production overlays.

No database, configuration, dependencies, financial settings or supplier writes.
Backups and prepared payloads remain outside the web root. The owner authorizes
the exact SSH invocation; --prepare never changes application files.
"""
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

APP = Path('/home/servernetcloud/servernet_app')
STAGE = Path('/home/servernetcloud/deploy-backups/domain-purchase-paths-20261124')
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
PREFIX = 'scripts/domain-purchase-paths-20261124/'
FILES = (
    'lang/fa/hosting-domains.php', 'lang/en/hosting-domains.php', 'lang/tr/hosting-domains.php',
    'app/Services/Domain/HostingDomainPurchase.php',
    'app/Http/Controllers/Account/HostingDomainQuoteController.php',
    'routes/hosting-domain-purchase.php',
    'resources/views/account/partials/hosting-domain-purchase.blade.php',
    'routes/web.php', 'app/Http/Controllers/Account/StoreController.php',
    'resources/views/account/checkout.blade.php',
    'app/Http/Controllers/DomainCheckController.php', 'app/Http/Controllers/DomainSearchController.php',
)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def fetch(commit, path):
    if not re.fullmatch('[a-f0-9]{40}', commit):
        raise ValueError('An immutable commit is required')
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/' + commit + '/' + path, timeout=20) as r:
        return r.read()


def atomic(path, data, mode=0o600):
    if path.is_symlink():
        raise RuntimeError('Symlink destination refused')
    fd, temporary = tempfile.mkstemp(prefix='.domain-release-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def target(path):
    if path not in FILES:
        raise RuntimeError('Unexpected application target')
    p = APP / path
    if not p.parent.is_dir() or p.is_symlink() or not p.resolve().is_relative_to(APP.resolve()):
        raise RuntimeError('Unsafe application target')
    return p


def current(path):
    p = target(path)
    return sha(p.read_bytes()) if p.exists() else None


def prepare(commit):
    if (STAGE / 'prepared.json').exists():
        raise RuntimeError('A prepared release already exists; inspect rather than overwrite')
    rows = json.loads(fetch(commit, PREFIX + 'manifest.json'))['files']
    if tuple(r['path'] for r in rows) != FILES:
        raise RuntimeError('Unexpected release scope')
    for index, row in enumerate(rows):
        p = target(row['path'])
        if current(row['path']) != row['before']:
            raise RuntimeError('Production changed: ' + row['path'])
        source = fetch(commit, 'website/' + row['path'])
        if sha(source) != row['source_sha']:
            raise RuntimeError('Source integrity mismatch')
        original = p.read_bytes() if p.exists() else None
        payload = source
        if 'edits' in row:
            payload = original
            for edit in row['edits']:
                old, new = edit['old'].encode(), edit['new'].encode()
                if payload.count(old) != 1 or source.count(new) != 1:
                    raise RuntimeError('Ambiguous overlay: ' + row['path'])
                payload = payload.replace(old, new, 1)
        if sha(payload) != row['after']:
            raise RuntimeError('Unverified candidate bytes')
        if original is not None:
            atomic(STAGE / ('backup-' + str(index)), original)
        atomic(STAGE / ('candidate-' + str(index) + '.php'), payload)
        row['mode'] = (p.stat().st_mode & 0o777) if p.exists() else 0o644
        if '.blade.php' not in row['path']:
            subprocess.run([PHP, '-l', str(STAGE / ('candidate-' + str(index) + '.php'))], check=True, capture_output=True)
    # Compile Blade without rendering a customer order or calling any supplier.
    compiler = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->routesAreCached()) exit(9);
$b=app('blade.compiler'); foreach(array_slice($argv,1) as $p)
file_put_contents($p.'.compiled.php',$b->compileString(file_get_contents($p)));'''
    views = [str(STAGE / ('candidate-' + str(i) + '.php')) for i, r in enumerate(rows) if '.blade.php' in r['path']]
    subprocess.run([PHP, '-r', compiler, *views], cwd=APP, check=True, capture_output=True)
    for path in views:
        subprocess.run([PHP, '-d', 'short_open_tag=1', '-l', path + '.compiled.php'], check=True, capture_output=True)
    atomic(STAGE / 'prepared.json', json.dumps({'commit': commit, 'files': rows}, indent=2).encode())
    return {'status': 'prepared', 'files': len(rows), 'commit': commit}


def activate():
    code = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->routesAreCached()) exit(9);
foreach(['account.order.domain-quote','en.account.order.domain-quote','tr.account.order.domain-quote'] as $name)
if (!Illuminate\Support\Facades\Route::has($name)) exit(10);
foreach(['account.checkout','account.partials.hosting-domain-purchase'] as $view)
app('blade.compiler')->compile(app('view')->getFinder()->find($view));
App\Http\Middleware\PageCache::purge(); echo 'routes_and_views_ready';'''
    return subprocess.run([PHP, '-r', code], cwd=APP, check=True, capture_output=True, text=True).stdout


def apply(commit):
    if (STAGE / 'receipt.json').exists():
        raise RuntimeError('Already applied; verify the existing receipt')
    record = json.loads((STAGE / 'prepared.json').read_text())
    if record['commit'] != commit or tuple(r['path'] for r in record['files']) != FILES:
        raise RuntimeError('Prepared revision does not match')
    rows = record['files']
    for i, r in enumerate(rows):
        if current(r['path']) != r['before'] or sha((STAGE / ('candidate-' + str(i) + '.php')).read_bytes()) != r['after']:
            raise RuntimeError('Prepared bytes changed: ' + r['path'])
    installed = []
    try:
        for i, r in enumerate(rows):
            if current(r['path']) != r['before']:
                raise RuntimeError('Concurrent edit: ' + r['path'])
            atomic(target(r['path']), (STAGE / ('candidate-' + str(i) + '.php')).read_bytes(), r['mode'])
            installed.append(i)
        result = activate()
        if any(current(r['path']) != r['after'] for r in rows):
            raise RuntimeError('Post-install integrity mismatch')
        atomic(STAGE / 'receipt.json', json.dumps({'commit': commit, 'files': rows, 'activation': result}, indent=2).encode())
    except Exception:
        # Never overwrite a concurrent edit during recovery.
        for i in reversed(installed):
            r = rows[i]
            if current(r['path']) != r['after']:
                raise RuntimeError('Concurrent edit prevents rollback: ' + r['path'])
            if r['before'] is None:
                os.replace(target(r['path']), STAGE / ('rolled-back-' + str(i)))
            else:
                atomic(target(r['path']), (STAGE / ('backup-' + str(i))).read_bytes(), r['mode'])
        raise
    return {'status': 'installed', 'files': len(rows), 'commit': commit, 'activation': result}


if __name__ == '__main__':
    if len(sys.argv) != 3 or sys.argv[1] not in ('--prepare', '--apply'):
        raise SystemExit('Usage: release.py --prepare|--apply COMMIT')
    STAGE.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(STAGE, 0o700)
    with (STAGE / 'release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        print(json.dumps(prepare(sys.argv[2]) if sys.argv[1] == '--prepare' else apply(sys.argv[2])))
