#!/usr/bin/env python3
"""Release only SnappPay branding; keep unrelated production template changes."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import time
from urllib.request import urlopen

HOME = Path('/home/servernetcloud')
APP = HOME / 'servernet_app'
WORK = HOME / 'snapp-compliance-20261103-e4c1'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
LOGO_HASH = '1cff8dbe9b689243b23c66e7609b79e8b948d6c733a17bbe7f5b23f39f100e1d'
VIEW = APP / 'resources/views/account/invoice.blade.php'
REPLACEMENTS = [
    ("asset('assets/img/snapppay.svg')", "asset('assets/img/snapppay.svg').'?v=1cff8dbe9b68'"),
    ('alt="Snapp! Pay" width="28" height="28"', 'alt="اسنپ‌پی" width="40" height="40"'),
    ('.pm-badge.sp{ background:#fff; border:1px solid var(--bd,#e5e7eb); }', '.pm-badge.sp{ width:48px; height:48px; background:#fff; border:1px solid var(--bd,#e5e7eb); }'),
    ('.pm-badge.sp img{ width:28px; height:28px; display:block; }', '.pm-badge.sp img{ width:40px; height:40px; display:block; object-fit:contain; }'),
]


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe(path):
    if not path.is_relative_to(HOME):
        raise RuntimeError('outside_account')
    cursor = HOME
    for part in path.relative_to(HOME).parts:
        cursor /= part
        if cursor.is_symlink():
            raise RuntimeError('symlink_rejected')
    if path.exists() and path.stat().st_uid != os.getuid():
        raise RuntimeError('wrong_owner')


def current(path):
    safe(path)
    return digest(path.read_bytes()) if path.is_file() else 'absent'


def atomic(path, data, mode):
    safe(path)
    fd, name = tempfile.mkstemp(prefix='.snapp-brand-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, mode)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def patch_view(data):
    result = data.decode('utf-8')
    for before, after in REPLACEMENTS:
        if after in result:
            if result.count(after) != 1:
                raise RuntimeError('ambiguous_existing_branding')
            continue
        if result.count(before) != 1:
            raise RuntimeError('branding_context_changed')
        result = result.replace(before, after, 1)
    return result.encode('utf-8')


def prepare(source):
    if not re.fullmatch('[0-9a-f]{40}', source or ''):
        raise RuntimeError('invalid_source')
    if (WORK / 'branding-manifest.json').exists():
        raise RuntimeError('preview_already_exists')
    logo_url = 'https://snapppay.ir/wp-content/uploads/2025/10/snapppay-logo.svg'
    with urlopen(logo_url, timeout=25) as response:
        logo = response.read(20000)
    if digest(logo) != LOGO_HASH:
        raise RuntimeError('official_logo_hash_mismatch')
    targets = [(APP / 'public/assets/img' / name, logo) for name in ['snapppay.svg', 'snapppay-logotype.svg']]
    targets += [(HOME / 'public_html/assets/img' / name, logo) for name in ['snapppay.svg', 'snapppay-logotype.svg']]
    targets.append((VIEW, patch_view(VIEW.read_bytes())))
    entries = []

    def stage(path, data, kind):
        safe(path)
        index = len(entries)
        before = current(path)
        backup = WORK / ('branding-before-' + str(index))
        candidate = WORK / ('branding-after-' + str(index))
        if before != 'absent':
            atomic(backup, path.read_bytes(), 0o600)
        atomic(candidate, data, 0o600)
        entries.append(dict(path=str(path), before=before, after=digest(data), backup=str(backup), candidate=str(candidate),
                            mode=stat.S_IMODE(path.stat().st_mode) if path.exists() else 0o644, kind=kind))

    for path, data in targets:
        stage(path, data, 'view' if path == VIEW else 'asset')
    compile_php = r'''<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $argv[3]);
$compiler->setPath($argv[4]);
$bytes = $compiler->compileString(file_get_contents($argv[1]))."\n<?php /**PATH ".$argv[4]." ENDPATH**/ ?>";
file_put_contents($argv[2], $bytes); chmod($argv[2], 0600);
'''
    atomic(WORK / 'branding-compile.php', compile_php.encode(), 0o600)
    compiled = WORK / 'branding-compiled.php'
    subprocess.run([PHP, str(WORK / 'branding-compile.php'), entries[-1]['candidate'], str(compiled), str(WORK), str(VIEW)], check=True, capture_output=True)
    subprocess.run([PHP, '-l', str(compiled)], check=True, capture_output=True)
    marker = ('/**PATH ' + str(VIEW) + ' ENDPATH**/').encode()
    for cached in sorted((APP / 'storage/framework/views').glob('*.php')):
        if re.fullmatch(r'[0-9a-f]+\.php', cached.name) and marker in cached.read_bytes():
            stage(cached, compiled.read_bytes(), 'compiled')
    plan = dict(source=source, phase='previewed', entries=entries)
    atomic(WORK / 'branding-manifest.json', json.dumps(plan, indent=2).encode(), 0o600)
    return dict(status='previewed', files=5, compiled=len(entries)-5, lint='passed')


def apply(phase):
    plan = json.loads((WORK / 'branding-manifest.json').read_text())
    entries = plan['entries']
    expected_paths = {str(VIEW)} | {str(root / name) for root in [APP / 'public/assets/img', HOME / 'public_html/assets/img'] for name in ['snapppay.svg', 'snapppay-logotype.svg']}
    if {entry['path'] for entry in entries if entry['kind'] != 'compiled'} != expected_paths:
        raise RuntimeError('scope_changed')
    for entry in entries:
        path = Path(entry['path'])
        safe(path)
        if entry['kind'] == 'compiled' and (path.parent != APP / 'storage/framework/views' or not re.fullmatch(r'[0-9a-f]+\.php', path.name)):
            raise RuntimeError('compiled_path_rejected')
        expected = entry['after'] if phase in ['verify', 'rollback'] else entry['before']
        if current(path) != expected or digest(Path(entry['candidate']).read_bytes()) != entry['after']:
            raise RuntimeError('concurrent_change:' + str(path.relative_to(HOME)))
        if entry['before'] != 'absent' and digest(Path(entry['backup']).read_bytes()) != entry['before']:
            raise RuntimeError('backup_changed')
    if phase != 'verify':
        written = []
        try:
            for entry in reversed(entries) if phase == 'rollback' else entries:
                path = Path(entry['path'])
                expected = entry['after'] if phase == 'rollback' else entry['before']
                if current(path) != expected:
                    raise RuntimeError('concurrent_change')
                if phase == 'rollback' and entry['before'] == 'absent':
                    path.unlink()
                else:
                    data = Path(entry['backup'] if phase == 'rollback' else entry['candidate']).read_bytes()
                    atomic(path, data, entry['mode'])
                    if phase == 'install' and entry['kind'] == 'compiled':
                        stamp = max(time.time(), VIEW.stat().st_mtime + 1)
                        os.utime(path, (stamp, stamp))
                written.append(entry)
        except Exception:
            if phase == 'install':
                for entry in reversed(written):
                    path = Path(entry['path'])
                    if current(path) != entry['after']:
                        raise RuntimeError('rollback_blocked_by_newer_change')
                    if entry['before'] == 'absent':
                        path.unlink()
                    else:
                        atomic(path, Path(entry['backup']).read_bytes(), entry['mode'])
            raise
        plan['phase'] = 'installed' if phase == 'install' else 'rolled_back'
        atomic(WORK / 'branding-manifest.json', json.dumps(plan, indent=2).encode(), 0o600)
    return dict(status='verified' if phase == 'verify' else plan['phase'], files=5, compiled=len(entries)-5)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('phase', choices=['preview', 'install', 'verify', 'rollback'])
    parser.add_argument('--source')
    args = parser.parse_args()
    if Path.home() != HOME or os.getuid() == 0:
        raise RuntimeError('wrong_account')
    WORK.mkdir(mode=0o700, exist_ok=True)
    safe(WORK)
    locks = []
    for name in ['.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock']:
        safe(HOME / name)
        fd = os.open(HOME / name, os.O_CREAT | os.O_RDWR, 0o600)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        locks.append(fd)
    result = prepare(args.source) if args.phase == 'preview' else apply(args.phase)
    atomic(WORK / ('branding-' + args.phase + '-result.json'), json.dumps(result).encode(), 0o600)
    print(json.dumps(result))


if __name__ == '__main__':
    main()
