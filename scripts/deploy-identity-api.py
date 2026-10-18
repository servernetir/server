#!/usr/bin/env python3
"""Install only a reviewed identity manifest; never run a general migration/seeder or clear shared cache."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--stage', required=True, type=Path)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    root = Path('/home/servernetcloud/servernet_app').resolve(strict=True)
    stage = args.stage.resolve(strict=True)
    manifest = json.loads((stage / 'manifest.json').read_text())
    release = manifest['release']
    if release not in {'identity-api-20261017', 'identity-api-20261017-runtime', 'identity-api-20261017-routing', 'identity-api-20261018-readiness', 'identity-api-20261018-sales', 'identity-api-20261018-funding'} or not stage.is_relative_to(root.parent):
        raise RuntimeError('Unexpected release or staging destination')
    lock = (root.parent / 'identity-api-deploy.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    if digest(root / 'composer.lock') != manifest['composer_lock_sha256']:
        raise RuntimeError('Composer lock drift; do not replace dependencies')
    rows = manifest['files']
    if len({r['path'] for r in rows}) != len(rows):
        raise RuntimeError('Duplicate manifest path')
    for row in rows:
        rel = Path(row['path'])
        if rel.is_absolute() or '..' in rel.parts or rel.parts[0] not in {
            'app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes'
        } or 'cache' in rel.parts or (root / rel).is_symlink():
            raise RuntimeError('Unsafe path')
        if not (root / rel).resolve().is_relative_to(root):
            raise RuntimeError('Destination escapes app root')
        if digest(stage / 'files' / rel) != row['after'] or digest(root / rel) != row['before']:
            raise RuntimeError('Hash conflict: ' + str(rel))
    php = '/opt/cpanel/ea-php84/root/usr/bin/php'
    for row in rows:
        if row['path'].endswith('.php') and not row['path'].endswith('.blade.php'):
            subprocess.run([php, '-l', str(stage / 'files' / row['path'])], check=True, stdout=subprocess.DEVNULL)
    if not args.apply:
        print(json.dumps({'preflight': 'passed', 'files': len(rows), 'source_sha': manifest['source_sha']}))
        return
    backup = root.parent / (release + '-backup')
    backup.mkdir(mode=0o700)  # Refuse blind reruns and preserve evidence of a partial install.
    shutil.copyfile(stage / 'manifest.json', backup / 'manifest.json')
    with tarfile.open(backup / 'sources-before.tar.gz', 'w:gz') as archive:
        for row in rows:
            if row['before'] is not None:
                archive.add(root / row['path'], arcname=row['path'])
    # Full DB snapshot is private, never downloaded or included in release artifacts.
    subprocess.run([php, str(stage / 'identity-db-backup.php'), str(backup)], cwd=root, check=True)
    # Recheck every destination after backup and before any write.
    for row in rows:
        if digest(root / row['path']) != row['before']:
            raise RuntimeError('Concurrent source change: ' + row['path'])
    installed = []
    try:
        # Dependencies before entry points; schema readiness guards keep feature inert until migration.
        entry = {'routes/web.php', 'routes/console.php', 'bootstrap/app.php'}
        for row in sorted(rows, key=lambda r: r['path'] in entry):
            target = root / row['path']
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            temp = target.with_name(target.name + '.identity-release-new')
            with temp.open('xb') as handle:
                handle.write((stage / 'files' / row['path']).read_bytes())
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temp, 0o644)
            if digest(target) != row['before']:
                raise RuntimeError('Concurrent write: ' + row['path'])
            os.replace(temp, target)
            installed.append(row['path'])
            (backup / 'installed.json').write_text(json.dumps(installed))
        migration = 'database/migrations/2026_10_17_190000_create_identity_api_tables.php'
        if any(row['path'] == migration for row in rows):
            with (backup / 'migration.private.log').open('w') as log:
                subprocess.run([php, 'artisan', 'migrate', '--force', '--path=' + migration],
                               cwd=root, stdout=log, stderr=subprocess.STDOUT, check=True)
        else:
            migration = None
        for row in rows:
            if digest(root / row['path']) != row['after']:
                raise RuntimeError('Post-install hash mismatch: ' + row['path'])
        # Only compiled views; no shared cache flush that could drop running jobs' locks.
        subprocess.run([php, 'artisan', 'view:clear'], cwd=root, check=True)
        (backup / 'installed-ok.json').write_text(json.dumps({'files': len(rows), 'source_sha': manifest['source_sha']}))
        print(json.dumps({'installed': len(rows), 'migration': migration, 'backup': str(backup)}))
    except Exception:
        # Keep additive schema and financial code; avoid silently hiding any newly accepted hold.
        print('Installation incomplete; private manifest/journal retained. Keep dispatch disabled and inspect before retry.')
        raise


if __name__ == '__main__':
    main()
