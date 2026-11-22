#!/usr/bin/env python3
"""Install three reviewed files, preserving the independently deployed sitemap implementation."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import urllib.request

ROOT = Path('/home/servernetcloud/servernet_app')
BACKUPS = Path('/home/servernetcloud/deploy-backups')
RELEASE = BACKUPS / 'error-recurrence-20261122'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
FILES = {
    'app/Services/Ai/AdditionalProviderCatalog.php': (
        '7883b5c6891ee94df61ff59a8baefbc40fc67a099475300cdec36f82b4f82f00',
        '3c1769e5058f41d760f52d250c57ba1f1a091f1ba180329b6551c9181232f021'),
    'app/Services/Finance/ArvanTrafficSource.php': (
        'f853259921b4b5ec1ac41b7a9fc87d3104fbf32a3f9e4ffb56f727cf60fd407b',
        'ae904db69f202e78c93d24ecdccd14dac6671252bd9f38e71d946118fe7e999d'),
    'app/Http/Controllers/SiteController.php': (
        '38992d9d2aa6ddbb6513944df1969f08d7a56afe648ac9bb99bf1778b95c0d7b',
        'bef259cffefda54c43c69f16db218656b9362810009cd7aa0844d9cde795ab8f'),
}
FOLLOWUP_FILES = {
    'app/Services/Ai/AdditionalProviderCatalog.php': (
        '3c1769e5058f41d760f52d250c57ba1f1a091f1ba180329b6551c9181232f021',
        '20ce5e23e2c55850b599b9b6072dfdab8b0e769e7ccfb1e80303473c5b86d1e3'),
    'app/Services/Finance/ArvanTrafficSource.php': (
        'ae904db69f202e78c93d24ecdccd14dac6671252bd9f38e71d946118fe7e999d',
        '66a570f31d608746455ae8d3a485d2ee4150bb263a15f562bcf8e8af64acec07'),
}
NEEDLE = b"            foreach (array_keys($products) as $slug) {\n                $country = $category === 'vps'"
REPLACEMENT = b"            foreach (array_keys($products) as $slug) {\n                if ($category === 'vps' && $slug === 'gpu') {\n                    continue; // The legacy alias redirects to the canonical GPU route added above.\n                }\n                $country = $category === 'vps'"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def checked(path):
    if path.is_symlink() or not path.is_file():
        raise RuntimeError('Unexpected source or destination: ' + str(path))
    return path.read_bytes()


def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.error-recurrence-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp, mode)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def receipt():
    return json.loads(checked(RELEASE / 'receipt.json'))


def verify(record, rollback=False):
    for relative, hashes in FILES.items():
        expected = hashes[0 if rollback else 1]
        if digest(checked(ROOT / relative)) != expected:
            raise RuntimeError('File verification failed: ' + relative)
    print(json.dumps({'state': 'rolled_back' if rollback else 'installed', 'source_sha': record['source_sha'],
                      'files': {p: h[0 if rollback else 1] for p, h in FILES.items()}}, sort_keys=True))


def install(sha, artifact_root=None):
    if not re.fullmatch(r'[a-f0-9]{40}', sha or ''):
        raise RuntimeError('A pinned source SHA is required')
    if RELEASE.exists():
        old = receipt()
        if old.get('source_sha') != sha:
            raise RuntimeError('Release directory belongs to another source')
        verify(old)
        return
    if any(digest(checked(ROOT / p)) != h[0] for p, h in FILES.items()):
        raise RuntimeError('Production preimage changed; reconcile before installing')
    if os.statvfs(ROOT).f_bavail * os.statvfs(ROOT).f_frsize < 300 * 1024 * 1024:
        raise RuntimeError('Insufficient free disk space')
    staged = {}
    for relative, hashes in FILES.items():
        if relative.endswith('/SiteController.php'):
            current = checked(ROOT / relative)
            if current.count(NEEDLE) != 1:
                raise RuntimeError('Sitemap patch is not unique')
            data = current.replace(NEEDLE, REPLACEMENT)
        elif artifact_root is not None:
            data = checked(artifact_root / relative)
        else:
            url = 'https://raw.githubusercontent.com/servernetir/server/' + sha + '/website/' + relative
            with urllib.request.urlopen(url, timeout=15) as response:
                data = response.read(300000)
        if digest(data) != hashes[1]:
            raise RuntimeError('Artifact hash mismatch: ' + relative)
        staged[relative] = data
    RELEASE.mkdir(mode=0o700)
    modes = {}
    for relative, data in staged.items():
        backup = RELEASE / 'before' / relative
        backup.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        atomic(backup, checked(ROOT / relative), 0o600)
        modes[relative] = (ROOT / relative).stat().st_mode & 0o777
        candidate = RELEASE / 'staged' / relative
        candidate.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        atomic(candidate, data, 0o600)
        subprocess.run([PHP, '-l', str(candidate)], check=True, stdout=subprocess.DEVNULL)
    record = {'source_sha': sha, 'files': FILES, 'modes': modes}
    atomic(RELEASE / 'receipt.json', json.dumps(record, indent=2).encode(), 0o600)
    installed = []
    try:
        for relative, data in staged.items():
            if digest(checked(ROOT / relative)) != FILES[relative][0]:
                raise RuntimeError('Concurrent production edit: ' + relative)
            atomic(ROOT / relative, data, modes[relative])
            installed.append(relative)
        verify(record)
    except BaseException:
        for relative in reversed(installed):
            if digest(checked(ROOT / relative)) != FILES[relative][1]:
                raise RuntimeError('Concurrent edit; automatic rollback refused: ' + relative)
            old = checked(RELEASE / 'before' / relative)
            if digest(old) != FILES[relative][0]:
                raise RuntimeError('Backup hash mismatch: ' + relative)
            atomic(ROOT / relative, old, modes[relative])
        raise


def rollback():
    record = receipt()
    for relative, hashes in FILES.items():
        if digest(checked(ROOT / relative)) != hashes[1] or digest(checked(RELEASE / 'before' / relative)) != hashes[0]:
            raise RuntimeError('Rollback precondition failed: ' + relative)
    for relative in reversed(FILES):
        atomic(ROOT / relative, checked(RELEASE / 'before' / relative), record['modes'][relative])
    verify(record, rollback=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--source-sha')
    parser.add_argument('--artifact-root', type=Path,
                        help='Private staged artifacts, checked against the same pinned SHA-256 values')
    parser.add_argument('--followup', action='store_true', help='Install the two verified live-feed compatibility follow-ups')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--verify', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    args = parser.parse_args()
    if args.followup:
        FILES = FOLLOWUP_FILES
        RELEASE = BACKUPS / 'error-recurrence-20261122-r2'
    BACKUPS.mkdir(mode=0o700, exist_ok=True)
    if BACKUPS.is_symlink() or RELEASE.is_symlink():
        raise RuntimeError('Unexpected backup directory')
    lock_path = BACKUPS / '.servernet-deploy.lock'
    if lock_path.is_symlink():
        raise RuntimeError('Unexpected deployment lock')
    with lock_path.open('a+b') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.verify:
            verify(receipt())
        elif args.rollback:
            rollback()
        else:
            install(args.source_sha, args.artifact_root)
