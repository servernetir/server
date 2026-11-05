#!/usr/bin/env python3
"""Preflight/install a reviewed, file-scoped console release; never activates it.

Default operation is read-only. --apply writes only manifest-listed application
files, after whole-release hash checks, with private backups and atomic renames.
No .env, database, cache, jobs, service reload or external API is touched.
"""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import tempfile
import zipfile

ALLOWLIST = (
    'website/app/Support/ConsoleNext.php',
    'website/app/Http/Middleware/ConsoleNext.php',
    'website/app/Console/Commands/ConsoleNextEnable.php',
    'website/app/Console/Commands/ConsoleNextDisable.php',
    'website/config/console-next.php',
    'website/resources/views/panel/next.blade.php',
    'website/lang/fa/console_next.php',
    'website/lang/en/console_next.php',
    'website/lang/tr/console_next.php',
    'website/public/assets/css/console-next.css',
    'website/public/assets/js/console-next.js',
    'website/public/assets/js/console-next-network.js',
    'website/bootstrap/app.php',
    'website/app/Http/Controllers/Account/CloudStoreController.php',
    'website/resources/views/account/cloud-server.blade.php',
    'website/app/Http/Middleware/SecurityHeaders.php',
    'website/app/helpers.php',
    'website/resources/views/panel/layout.blade.php',
    'website/routes/web.php',
)
HASH = re.compile(r'^[a-f0-9]{64}$')
RELEASE = re.compile(r'^console-next-[a-f0-9]{16}$')


class ReleaseError(Exception):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def relative_path(value):
    if not isinstance(value, str) or not value or '\\' in value or '\x00' in value:
        raise ReleaseError('Invalid relative path.')
    path = PurePosixPath(value)
    if path.is_absolute() or any(part in ('', '.', '..') for part in value.split('/')):
        raise ReleaseError('Unsafe relative path: ' + value)
    return path


def reject_symlinks(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ReleaseError('Use explicit absolute paths without traversal.')
    current = Path(path.anchor)
    for part in path.parts[1:]:
        current /= part
        if current.is_symlink():
            raise ReleaseError('Symlinks are not accepted: ' + str(current))


def roots(app_root, webroot):
    result = {'app': Path(app_root), 'webroot': Path(webroot)}
    for path in result.values():
        reject_symlinks(path)
        if not path.is_dir():
            raise ReleaseError('Target root must already be a directory: ' + str(path))
    a, b = result.values()
    if a == b or a in b.parents or b in a.parents:
        raise ReleaseError('Application and webroot must be separate directories.')
    for marker in (a / 'artisan', a / 'bootstrap/app.php', a / 'vendor/autoload.php', b / 'index.php'):
        reject_symlinks(marker)
        if not marker.is_file():
            raise ReleaseError('Expected installation marker is missing: ' + str(marker))
    return result


def destination(target_roots, entry):
    path = target_roots[entry['target_root']].joinpath(*relative_path(entry['target_path']).parts)
    reject_symlinks(path)
    for parent in path.parents:
        if parent.exists() and not parent.is_dir():
            raise ReleaseError('Non-directory target ancestor: ' + str(parent))
    return path


def current_hash(path):
    reject_symlinks(path)
    if not path.exists():
        return None
    if not path.is_file():
        raise ReleaseError('Target is not a regular file: ' + str(path))
    return digest(path.read_bytes())


def validate_manifest(manifest):
    if manifest.get('schema') != 1 or not RELEASE.fullmatch(manifest.get('release_id', '')):
        raise ReleaseError('Unsupported release manifest.')
    if not re.fullmatch(r'[a-f0-9]{40}', manifest.get('base_sha', '')):
        raise ReleaseError('A full Git base SHA is required.')
    if not HASH.fullmatch(manifest.get('installer_sha256', '')):
        raise ReleaseError('Missing installer checksum.')
    entries = manifest.get('files')
    if not isinstance(entries, list) or len(entries) != len(ALLOWLIST):
        raise ReleaseError('Release must contain exactly the reviewed file set.')
    seen = set()
    for entry in entries:
        source = entry.get('source_path')
        if source not in ALLOWLIST or source in seen:
            raise ReleaseError('Unreviewed or duplicate source path.')
        seen.add(source)
        root = 'webroot' if source.startswith('website/public/') else 'app'
        target = source[len('website/public/'):] if root == 'webroot' else source[len('website/'):]
        home_prefix = 'public_html/' if root == 'webroot' else 'servernet_app/'
        if (entry.get('target_root') != root or entry.get('target_path') != target
                or entry.get('destination_relative_to_home') != home_prefix + target
                or entry.get('payload_path') != 'payload/' + source):
            raise ReleaseError('Source/target mapping differs from the reviewed release.')
        relative_path(target)
        before = entry.get('expected_before_sha256_or_absent')
        if before is not None and not HASH.fullmatch(str(before)):
            raise ReleaseError('Invalid before checksum.')
        if not HASH.fullmatch(entry.get('after_sha256', '')):
            raise ReleaseError('Invalid payload checksum.')
        if entry.get('operation') != ('add' if before is None else 'replace') or entry.get('mode') != 0o644:
            raise ReleaseError('Only reviewed add/replace operations and mode 0644 are accepted.')
    if seen != set(ALLOWLIST):
        raise ReleaseError('Incomplete release.')


def load_release(archive):
    try:
        with zipfile.ZipFile(archive) as bundle:
            names = bundle.namelist()
            if len(names) != len(set(names)):
                raise ReleaseError('Duplicate archive entries.')
            if sum(item.file_size for item in bundle.infolist()) > 64 * 1024 * 1024:
                raise ReleaseError('Release exceeds the allowed size.')
            for name in names:
                relative_path(name)
                info = bundle.getinfo(name)
                if stat.S_ISLNK(info.external_attr >> 16):
                    raise ReleaseError('Archive symlinks are not accepted.')
            manifest = json.loads(bundle.read('manifest.json'))
            validate_manifest(manifest)
            expected = {'manifest.json', 'installer.py'} | {entry['payload_path'] for entry in manifest['files']}
            if set(names) != expected:
                raise ReleaseError('Unexpected archive content.')
            installer = bundle.read('installer.py')
            if digest(installer) != manifest['installer_sha256'] or digest(Path(__file__).read_bytes()) != digest(installer):
                raise ReleaseError('Run the exact reviewed installer shipped in this release.')
            payload = {}
            for entry in manifest['files']:
                data = bundle.read(entry['payload_path'])
                if digest(data) != entry['after_sha256']:
                    raise ReleaseError('Payload checksum failed: ' + entry['source_path'])
                payload[entry['source_path']] = data
    except (zipfile.BadZipFile, KeyError, ValueError, TypeError) as error:
        raise ReleaseError('Invalid release archive.') from error
    return manifest, payload


def candidate_state(target_roots):
    marker = target_roots['app'] / 'storage/app/console-next.state'
    reject_symlinks(marker)
    if not marker.exists():
        return 'absent'
    if not marker.is_file() or marker.stat().st_size > 32:
        raise ReleaseError('Invalid private candidate activation marker.')
    state = marker.read_text().strip()
    if state not in ('disabled', 'enabled'):
        raise ReleaseError('Unrecognized private candidate activation marker.')
    return state


def preflight(manifest, target_roots):
    canonical_lock_path(target_roots)
    checks = []
    for entry in manifest['files']:
        path = destination(target_roots, entry)
        actual = current_hash(path)
        before = entry['expected_before_sha256_or_absent']
        if actual == entry['after_sha256']:
            state = 'installed'
        elif actual == before:
            state = 'base'
        else:
            raise ReleaseError('Concurrent/unrecognized bytes; no target writes: ' + entry['source_path'])
        checks.append({'source_path': entry['source_path'], 'state': state})
    cache = target_roots['app'] / 'bootstrap/cache'
    reject_symlinks(cache)
    cached_routes = sorted(path.name for path in cache.glob('routes*.php')) if cache.is_dir() else []
    return {'status': 'preflight_passed', 'release_id': manifest['release_id'], 'files': checks,
            'candidate_state': candidate_state(target_roots), 'route_cache_files': cached_routes,
            'activation': 'not_performed', 'runtime_verification_required': True}


def private_backup_root(value, target_roots):
    path = Path(value)
    reject_symlinks(path)
    for root in target_roots.values():
        if path == root or root in path.parents or path in root.parents:
            raise ReleaseError('Backup root must be separate from application and webroot.')
    if path.exists() and (not path.is_dir() or stat.S_IMODE(path.stat().st_mode) & 0o077):
        raise ReleaseError('Existing backup root must be a private directory (0700).')
    return path


def canonical_lock_path(target_roots):
    lock = target_roots['app'] / 'storage/app/.console-next-release.lock'
    reject_symlinks(lock)
    if lock.exists():
        info = lock.stat()
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid != os.geteuid()):
            raise ReleaseError('Canonical deployment lock must be an owned private regular file with one link.')
    return lock


@contextlib.contextmanager
def deployment_lock(target_roots):
    # The same canonical inode is used by the scoped Artisan activation commands.
    lock = canonical_lock_path(target_roots)
    lock.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    reject_symlinks(lock)
    if lock.exists() and not lock.is_file():
        raise ReleaseError('Canonical deployment lock is not a regular file.')
    fd = os.open(lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        opened = os.fstat(fd)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                or stat.S_IMODE(opened.st_mode) != 0o600 or opened.st_uid != os.geteuid()):
            raise ReleaseError('Canonical deployment lock is not a regular file.')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise ReleaseError('Another console release holds the private deployment lock.') from error
        named = lock.lstat()
        if (named.st_dev, named.st_ino) != (opened.st_dev, opened.st_ino):
            raise ReleaseError('Canonical lock path changed concurrently.')
        yield
    finally:
        os.close(fd)


def prepare_disabled(target_roots, apply=False):
    """Initialize only an absent marker; never replaces enabled/unknown state."""
    canonical_lock_path(target_roots)
    state = candidate_state(target_roots)
    if state == 'enabled':
        raise ReleaseError('Preparation cannot override enabled state; use the scoped Artisan disable command.')
    if state == 'disabled':
        return {'status': 'already_disabled', 'activation': 'not_performed'}
    if not apply:
        return {'status': 'disabled_marker_preflight_passed', 'would_create': 'storage/app/console-next.state',
                'activation': 'not_performed'}
    with deployment_lock(target_roots):
        state = candidate_state(target_roots)
        if state == 'disabled':
            return {'status': 'already_disabled', 'activation': 'not_performed'}
        if state != 'absent':
            raise ReleaseError('Candidate state changed concurrently; preparation stopped.')
        marker = target_roots['app'] / 'storage/app/console-next.state'
        reject_symlinks(marker)
        fd, temporary = tempfile.mkstemp(prefix='.console-next-disabled-', dir=marker.parent)
        try:
            with os.fdopen(fd, 'wb') as handle:
                handle.write(b'disabled\n')
                handle.flush()
                os.fsync(handle.fileno())
                os.fchmod(handle.fileno(), 0o600)
            # A no-replace hard link publishes the complete bytes atomically.
            # A concurrent or symlink destination causes EEXIST, never overwrite.
            os.link(temporary, marker, follow_symlinks=False)
        finally:
            os.unlink(temporary)
    return {'status': 'disabled_marker_prepared', 'activation': 'not_performed', 'legacy_interface': 'unchanged'}


def atomic_write(path, data, mode=0o600):
    reject_symlinks(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.console-next-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
            os.fchmod(handle.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def save_receipt(path, receipt):
    atomic_write(path, (json.dumps(receipt, sort_keys=True, indent=2) + '\n').encode())


def install(manifest, payload, target_roots, backup_value):
    initial = preflight(manifest, target_roots)
    if all(item['state'] == 'installed' for item in initial['files']):
        return dict(initial, status='already_installed')
    if initial['candidate_state'] != 'disabled':
        raise ReleaseError('An explicit disabled marker is required. Initialize an absent marker with --prepare-disabled --apply; use the scoped Artisan command for an existing candidate.')
    backup_root = private_backup_root(backup_value, target_roots)
    with deployment_lock(target_roots):
        checked = preflight(manifest, target_roots)
        if checked['candidate_state'] != 'disabled':
            raise ReleaseError('Candidate state changed concurrently; installation stopped.')
        backup_root.mkdir(mode=0o700, parents=True, exist_ok=True)
        states = {item['source_path']: item['state'] for item in checked['files']}
        pending = [entry for entry in manifest['files'] if states[entry['source_path']] == 'base']
        release_dir = Path(tempfile.mkdtemp(prefix=manifest['release_id'] + '-', dir=backup_root))
        receipt_path = release_dir / 'receipt.json'
        receipt = {'schema': 1, 'manifest': manifest, 'roots': {name: str(path) for name, path in target_roots.items()},
                   'backup_root': str(backup_root), 'complete': False, 'activation': 'not_performed',
                   'runtime_verification_required': True, 'route_cache_files': checked['route_cache_files'], 'changes': []}
        # Back up every previous file before writing any destination file.
        for entry in pending:
            path = destination(target_roots, entry)
            before = entry['expected_before_sha256_or_absent']
            change = {'source_path': entry['source_path'], 'before_sha256': before,
                      'after_sha256': entry['after_sha256'], 'written': False, 'backup_path': None, 'before_mode': None}
            if before is not None:
                data = path.read_bytes()
                if digest(data) != before:
                    raise ReleaseError('Concurrent change while preparing private backup.')
                change['before_mode'] = stat.S_IMODE(path.stat().st_mode)
                change['backup_path'] = 'backup/' + entry['target_root'] + '/' + entry['target_path']
                atomic_write(release_dir / change['backup_path'], data)
            receipt['changes'].append(change)
        save_receipt(receipt_path, receipt)
        staged = []
        try:
            for entry in pending:
                path = destination(target_roots, entry)
                path.parent.mkdir(parents=True, exist_ok=True)
                reject_symlinks(path)
                fd, temporary = tempfile.mkstemp(prefix='.console-next-', dir=path.parent)
                with os.fdopen(fd, 'wb') as handle:
                    handle.write(payload[entry['source_path']])
                    handle.flush()
                    os.fsync(handle.fileno())
                    os.fchmod(handle.fileno(), entry['mode'])
                staged.append((entry, path, Path(temporary)))
            # Dependencies first; shared adapters next; the route file is last.
            staged.sort(key=lambda item: (item[0]['source_path'] == 'website/routes/web.php',
                                          item[0]['expected_before_sha256_or_absent'] is not None,
                                          ALLOWLIST.index(item[0]['source_path'])))
            preflight(manifest, target_roots)
            for entry, path, temporary in staged:
                if candidate_state(target_roots) != 'disabled':
                    raise ReleaseError('Candidate state changed concurrently; replacement stopped.')
                if current_hash(path) != entry['expected_before_sha256_or_absent']:
                    raise ReleaseError('Concurrent target change before atomic replacement: ' + entry['source_path'])
                reject_symlinks(path)
                os.replace(temporary, path)
                change = next(item for item in receipt['changes'] if item['source_path'] == entry['source_path'])
                change['written'] = True
                save_receipt(receipt_path, receipt)
            final = preflight(manifest, target_roots)
            if any(item['state'] != 'installed' for item in final['files']):
                raise ReleaseError('Installed byte verification failed.')
            receipt['complete'] = True
            save_receipt(receipt_path, receipt)
            return dict(final, status='installed_bytes_only', receipt=str(receipt_path), files_written=len(pending))
        except Exception as error:
            receipt['error'] = 'Installation interrupted; inspect this private receipt before a guarded rollback.'
            save_receipt(receipt_path, receipt)
            raise ReleaseError('Installation interrupted; private receipt: ' + str(receipt_path)) from error
        finally:
            for entry, _, temporary in staged:
                if temporary.is_file() and not temporary.is_symlink() and digest(temporary.read_bytes()) == entry['after_sha256']:
                    temporary.unlink()


def rollback(receipt_value, target_roots, backup_value, apply=False):
    backup_root = private_backup_root(backup_value, target_roots)
    receipt_path = Path(receipt_value)
    reject_symlinks(receipt_path)
    if not receipt_path.is_relative_to(backup_root) or receipt_path.name != 'receipt.json':
        raise ReleaseError('Receipt must be inside the explicit private backup root.')
    receipt = json.loads(receipt_path.read_text())
    manifest = receipt.get('manifest', {})
    validate_manifest(manifest)
    if receipt.get('schema') != 1 or receipt.get('roots') != {key: str(value) for key, value in target_roots.items()}:
        raise ReleaseError('Receipt target roots do not match the explicit operator roots.')
    entries = {entry['source_path']: entry for entry in manifest['files']}

    def check():
        restore = []
        seen = set()
        for change in receipt['changes']:
            source = change['source_path']
            if source not in entries or source in seen:
                raise ReleaseError('Invalid receipt changes.')
            seen.add(source)
            entry = entries[source]
            if (change['before_sha256'] != entry['expected_before_sha256_or_absent']
                    or change['after_sha256'] != entry['after_sha256']):
                raise ReleaseError('Receipt checksums differ from the release manifest.')
            path = destination(target_roots, entry)
            actual = current_hash(path)
            # A crash between rename and receipt update is recoverable from hashes.
            if not change.get('written') and actual == entry['expected_before_sha256_or_absent']:
                continue
            if entry['expected_before_sha256_or_absent'] is None:
                if actual != entry['after_sha256']:
                    raise ReleaseError('New file changed after installation; rollback stopped: ' + source)
                # New additive dependencies remain inert; this tool deletes no deployed files.
                continue
            if actual == entry['expected_before_sha256_or_absent']:
                continue
            if actual != entry['after_sha256']:
                raise ReleaseError('File changed after installation; rollback stopped: ' + source)
            expected_backup = 'backup/' + entry['target_root'] + '/' + entry['target_path']
            if change.get('backup_path') != expected_backup:
                raise ReleaseError('Invalid backup mapping.')
            backup = receipt_path.parent.joinpath(*relative_path(expected_backup).parts)
            if current_hash(backup) != entry['expected_before_sha256_or_absent']:
                raise ReleaseError('Private backup checksum failed.')
            data = backup.read_bytes()
            if digest(data) != entry['expected_before_sha256_or_absent']:
                raise ReleaseError('Private backup changed during preflight.')
            restore.append((entry, path, data, (change.get('before_mode') or 0o644) & 0o777))
        return restore

    planned = check()
    state = candidate_state(target_roots)
    if not apply:
        return {'status': 'rollback_preflight_passed', 'restore_count': len(planned),
                'candidate_state': state, 'apply_requires_disabled': state != 'disabled',
                'new_files': 'retained_inert', 'activation': 'not_performed'}
    if candidate_state(target_roots) != 'disabled':
        raise ReleaseError('Disable the candidate with its scoped Artisan command before rollback.')
    with deployment_lock(target_roots):
        planned = check()
        # Reverse installation: shared routes revert before helper/template adapters.
        planned.sort(key=lambda item: (item[0]['source_path'] == 'website/routes/web.php', ALLOWLIST.index(item[0]['source_path'])), reverse=True)
        for entry, path, data, mode in planned:
            if candidate_state(target_roots) != 'disabled':
                raise ReleaseError('Candidate state changed concurrently; rollback stopped.')
            if current_hash(path) != entry['after_sha256']:
                raise ReleaseError('Concurrent change before rollback replacement.')
            atomic_write(path, data, mode)
            if current_hash(path) != entry['expected_before_sha256_or_absent']:
                raise ReleaseError('Rollback byte verification failed.')
        receipt['rolled_back'] = True
        save_receipt(receipt_path, receipt)
    return {'status': 'rolled_back_bytes_only', 'restored_count': len(planned),
            'new_files': 'retained_inert', 'activation': 'not_performed', 'runtime_verification_required': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    operation = parser.add_mutually_exclusive_group(required=True)
    operation.add_argument('--archive', type=Path, help='Reviewed console-next-release.zip')
    operation.add_argument('--rollback', type=Path, help='Private receipt.json for guarded rollback')
    operation.add_argument('--prepare-disabled', action='store_true', help='Prepare only an absent private disabled marker; never enables the candidate')
    parser.add_argument('--app-root', type=Path, required=True)
    parser.add_argument('--webroot', type=Path, required=True)
    parser.add_argument('--backup-root', type=Path, help='Private 0700 directory, separate from both roots')
    parser.add_argument('--apply', action='store_true', help='Explicitly write the reviewed files; default is read-only')
    args = parser.parse_args()
    try:
        target_roots = roots(args.app_root, args.webroot)
        if args.backup_root:
            private_backup_root(args.backup_root, target_roots)
        if args.prepare_disabled:
            result = prepare_disabled(target_roots, args.apply)
        elif args.rollback:
            if not args.backup_root:
                raise ReleaseError('--backup-root is required for rollback.')
            result = rollback(args.rollback, target_roots, args.backup_root, args.apply)
        else:
            manifest, payload = load_release(args.archive)
            if args.apply:
                if not args.backup_root:
                    raise ReleaseError('--backup-root is required with --apply.')
                result = install(manifest, payload, target_roots, args.backup_root)
            else:
                result = preflight(manifest, target_roots)
        print(json.dumps(result, sort_keys=True))
    except (ReleaseError, OSError, ValueError) as error:
        print('STOP: ' + str(error), file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
