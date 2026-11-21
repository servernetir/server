"""Create a private recovery archive and verify it in a separate network namespace.

No application files, source database rows, cron entries or public routes are
replaced. Backups contain sensitive production data and MUST stay on the source
host. Only the summary receipt is suitable for a private audit report.
"""
import argparse
from contextlib import ExitStack
from datetime import datetime, timezone
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

APP_PARTS = ('app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor', 'storage')
EXCLUDED = ('servernet_app/storage/framework/', 'servernet_app/storage/logs/', 'servernet_app/bootstrap/cache/')
EXCLUDED_PREVIEWS = set()
# Historical, root-owned operator output, reviewed against executable sources.
# A flag is still required: these are never silently skipped by default.
OPERATOR_ARTIFACTS = frozenset({
    'servernet_app/storage/app/private/dev-folder-cleanup-preview-20261105.json',
    'servernet_app/storage/app/private/old-zip-classification-20261105.json',
    'servernet_app/storage/app/private/php-security-20261024/launch.json',
    'servernet_app/storage/app/private/diagnostics/',
})
ROOT_FILES = ('artisan', 'composer.json', 'composer.lock', '.env', '.env.example', '.htaccess')


def sha(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def private_json(path, value):
    with Path(path).open('x', encoding='utf-8') as stream:
        os.chmod(path, 0o600)
        json.dump(value, stream, indent=2)


def bounded(command, *, timeout, **kwargs):
    """Keep timeouts from leaving a dump/server descendant running unattended."""
    process = subprocess.Popen(command, start_new_session=True, **kwargs)
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGTERM)
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=5)
        raise
    if process.returncode:
        raise subprocess.CalledProcessError(process.returncode, command, output=stdout, stderr=stderr)
    return subprocess.CompletedProcess(command, process.returncode, stdout, stderr)


def scoped(relative):
    relative = str(relative)
    return relative not in EXCLUDED_PREVIEWS and not any(relative.startswith(p) for p in EXCLUDED)


def candidates(home):
    app = home/'servernet_app'
    for name in ROOT_FILES:
        if (app/name).is_file():
            yield app/name
    for root in [app/p for p in APP_PARTS]+[home/'public_html']:
        if not root.is_dir() or root.is_symlink():
            raise RuntimeError('Unexpected recovery source root')
        def denied(error):
            raise error
        for directory, dirs, files in os.walk(root, followlinks=False, onerror=denied):
            directory = Path(directory)
            dirs[:] = sorted(d for d in dirs if scoped((directory/d).relative_to(home).as_posix()+'/'))
            for name in sorted(files):
                path = directory/name
                if scoped(path.relative_to(home).as_posix()):
                    yield path
            # os.walk does not descend through links. Record them separately.
            for name in list(dirs):
                if (directory/name).is_symlink():
                    dirs.remove(name)
                    yield directory/name


def safe_link(home, path):
    resolved = path.resolve(strict=False)
    if not any(resolved.is_relative_to(home/p) for p in ['servernet_app', 'public_html']):
        raise RuntimeError('Recovery link leaves approved source roots')
    return os.path.relpath(resolved, path.parent)


def archive_sources(home, stage):
    manifest = {}
    total = 0
    sockets = []
    archive = stage/'files.partial.tar.gz'
    with tarfile.open(archive, 'x:gz', compresslevel=1, dereference=False) as output:
        os.chmod(archive, 0o600)
        for path in candidates(home):
            relative = path.relative_to(home).as_posix()
            if relative in manifest:
                raise RuntimeError('Duplicate recovery source')
            if path.is_symlink():
                link = safe_link(home, path)
                info = output.gettarinfo(str(path), arcname=relative)
                info.linkname = link
                output.addfile(info)
                manifest[relative] = {'link': link}
                continue
            if not path.is_file():
                info = path.lstat()
                # Local IPC endpoints have no durable contents. Their owning
                # service recreates them; never archive them as regular files.
                if stat.S_ISSOCK(info.st_mode) and info.st_uid == os.getuid():
                    sockets.append(relative)
                    continue
                raise RuntimeError('Unexpected special recovery file: '+relative)
            # Stage a stable read before committing a member to the archive.
            for attempt in range(3):
                with tempfile.SpooledTemporaryFile(max_size=8*1024*1024, dir=stage) as copy:
                    with path.open('rb') as source:
                        before = os.fstat(source.fileno())
                        digest = hashlib.sha256()
                        while chunk := source.read(1024*1024):
                            digest.update(chunk)
                            copy.write(chunk)
                        after = os.fstat(source.fileno())
                    if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
                        if attempt == 2:
                            raise RuntimeError('Source file is changing continuously')
                        continue
                    info = output.gettarinfo(str(path), arcname=relative)
                    info.type = tarfile.REGTYPE
                    info.linkname = ''
                    info.size = before.st_size
                    copy.seek(0)
                    output.addfile(info, copy)
                    manifest[relative] = {'sha256': digest.hexdigest(), 'bytes': before.st_size}
                    total += before.st_size
                    break
    private_json(stage/'file-manifest.json', manifest)
    archive.rename(stage/'files.tar.gz')
    archive = stage/'files.tar.gz'
    return {'files': len(manifest), 'source_bytes': total, 'archive_bytes': archive.stat().st_size,
            'sha256': sha(archive), 'excluded': list(EXCLUDED), 'operator_previews_excluded': sorted(EXCLUDED_PREVIEWS),
            'ephemeral_sockets_excluded': sockets}


def restore_members(archive, target):
    with tarfile.open(archive, 'r:gz') as source:
        for member in source:
            parts = Path(member.name).parts
            if not parts or parts[0] not in ['servernet_app', 'public_html'] or '..' in parts or Path(member.name).is_absolute():
                raise RuntimeError('Archive member outside recovery roots')
        source.extractall(target, filter='data')


def inspect_sql(sql_path, table_names):
    counts = dict.fromkeys(table_names, 0)
    created = set()
    with sql_path.open('rb') as stream:
        for line in stream:
            if match := re.match(rb'^CREATE TABLE `([A-Za-z0-9_]+)`', line):
                created.add(match[1].decode())
            if match := re.match(rb'^INSERT INTO `([A-Za-z0-9_]+)` VALUES ', line):
                table = match[1].decode()
                if table not in counts:
                    raise RuntimeError('Unexpected table in backup')
                counts[table] += 1
    if created != set(table_names):
        raise RuntimeError('Incomplete database schema in backup')
    return counts


def child(stage, php):
    if os.getuid() != 0 or os.readlink('/proc/self/ns/net') == (stage/'source-netns').read_text():
        raise RuntimeError('Recovery requires its own user and network namespace')
    interfaces = [line.split(':', 1)[0].strip() for line in Path('/proc/net/dev').read_text().splitlines()[2:]]
    if interfaces != ['lo']:
        raise RuntimeError('Unexpected network interface in recovery namespace')
    restore = stage/'restored'
    restore.mkdir(mode=0o700)
    restore_members(stage/'files.tar.gz', restore)
    manifest = json.loads((stage/'file-manifest.json').read_text())
    for relative, row in manifest.items():
        path = restore/relative
        if 'link' in row:
            if not path.is_symlink() or os.readlink(path) != row['link']:
                raise RuntimeError('Recovered link mismatch')
        elif path.is_symlink() or sha(path) != row['sha256']:
            raise RuntimeError('Recovered file hash mismatch')
    print('RESTORE_FILES_OK '+str(len(manifest)), flush=True)
    data = stage/'mariadb-data'
    data.mkdir(mode=0o700)
    socket = stage/'mysql.sock'
    if len(str(socket).encode()) >= 100:
        raise RuntimeError('Private socket path too long')
    log = (stage/'private-mariadb.log').open('xb')
    os.chmod(log.name, 0o600)
    init = ['/bin/mariadb-install-db', '--no-defaults', '--datadir='+str(data), '--user=root',
            '--auth-root-authentication-method=socket', '--skip-test-db']
    subprocess.run(init, stdout=log, stderr=log, check=True, timeout=60)
    command = ['/sbin/mariadbd', '--no-defaults', '--datadir='+str(data), '--socket='+str(socket),
               '--pid-file='+str(stage/'private-mariadb.pid'), '--user=root', '--skip-networking', '--skip-log-bin',
               '--event-scheduler=OFF', '--innodb-buffer-pool-size=64M', '--innodb-log-file-size=32M',
               '--max-connections=8', '--tmpdir='+str(stage)]
    server = subprocess.Popen(command, stdout=log, stderr=log)
    client = ['/bin/mariadb', '--no-defaults', '--protocol=SOCKET', '--socket='+str(socket), '--user=root', '--batch', '--skip-column-names']
    try:
        deadline = time.monotonic()+30
        while True:
            probe = subprocess.run(client+['-e', 'SELECT 1'], capture_output=True)
            if probe.returncode == 0:
                break
            if server.poll() is not None or time.monotonic() > deadline:
                raise RuntimeError('Private MariaDB startup failed')
            time.sleep(0.2)
        subprocess.run(client+['-e', 'CREATE DATABASE recovery_drill CHARACTER SET utf8mb4'], check=True, stdout=log, stderr=log)
        sql = stage/'database.sql'
        metadata = json.loads((stage/'database-metadata.json').read_text())
        if sha(sql) != metadata['sha256']:
            raise RuntimeError('Database backup hash mismatch')
        counts = inspect_sql(sql, metadata['table_names'])
        with sql.open('rb') as stream:
            subprocess.run(client+['recovery_drill'], stdin=stream, stdout=log, stderr=log, check=True, timeout=240)
        checks = 'SELECT '+', '.join('(SELECT COUNT(*) FROM `'+t+'`)' for t in counts)
        actual = subprocess.check_output(client+['recovery_drill', '-e', checks], text=True).strip().split('\t')
        if [int(n) for n in actual] != list(counts.values()):
            raise RuntimeError('Restored database row count mismatch')
        globals_check = subprocess.check_output(client+['-e', 'SELECT @@skip_networking,@@event_scheduler,@@socket'], text=True).strip().split('\t')
        if globals_check != ['1', 'OFF', str(socket)]:
            raise RuntimeError('Unexpected private database isolation')
        print('RESTORE_DATABASE_OK '+str(len(counts))+' tables', flush=True)
        app = restore/'servernet_app'
        for p in ['bootstrap/cache', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views']:
            (app/p).mkdir(mode=0o700, parents=True, exist_ok=True)
        # External environment variables override the copied .env. Cached config
        # is excluded. No workers, scheduler, mail or business route is run.
        env = os.environ.copy()
        env.update(APP_ENV='testing', APP_DEBUG='false', APP_URL='https://recovery.invalid',
                   DB_CONNECTION='mariadb', DB_URL='', DB_HOST='localhost', DB_SOCKET=str(socket),
                   DB_DATABASE='recovery_drill', DB_USERNAME='root', DB_PASSWORD='',
                   CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync',
                   MAIL_MAILER='array', BROADCAST_CONNECTION='null', LOG_CHANNEL='errorlog')
        check = r'''<?php
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        if ($db->getConfig('unix_socket') !== getenv('DB_SOCKET') || $db->getDatabaseName() !== 'recovery_drill') { throw new RuntimeException('Unexpected recovery connection'); }
        if ($db->selectOne('SELECT @@socket AS s')->s !== getenv('DB_SOCKET')) { throw new RuntimeException('Unexpected recovery socket'); }
        $key = Illuminate\Support\Facades\DB::table('settings')->where('key', 'salad_api_key')->value('value');
        $decrypted = $key === null ? null : is_string(Illuminate\Support\Facades\Crypt::decryptString($key));
        unset($key);
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('https://recovery.invalid/up'));
        if ($response->getStatusCode() !== 200) { throw new RuntimeException('Recovery application health failed'); }
        echo json_encode(['health'=>200, 'private_database'=>true, 'encrypted_setting_recovered'=>$decrypted]);
        '''
        result = subprocess.run([php], input=check, cwd=app, env=env, text=True, capture_output=True, timeout=45)
        if result.returncode != 0:
            (stage/'application-check-error.log').write_text(result.stderr)
            raise RuntimeError('Recovered application acceptance failed; details retained privately')
        application = json.loads(result.stdout)
        summary = {'ok': True, 'file_hashes_checked': len(manifest), 'tables': len(counts),
                   'rows': sum(counts.values()), 'network_namespace': True, 'skip_networking': True,
                   'event_scheduler': 'OFF', 'application': application}
        private_json(stage/'recovered.json', summary)
        print('RESTORE_ACCEPTED '+json.dumps(summary), flush=True)
    finally:
        server.terminate()
        try:
            server.wait(timeout=15)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)
        log.close()


def run(home, stage, php):
    if home != Path.home().resolve() or home.is_symlink() or stage.parent != home/'deploy-backups':
        raise RuntimeError('Unexpected source account')
    os.umask(0o077)
    if stage.exists() or stage.is_symlink() or stage.parent.is_symlink():
        raise RuntimeError('Recovery directory already exists or is unsafe')
    subprocess.run(['unshare', '--user', '--map-root-user', '--net', 'true'], check=True, capture_output=True, timeout=5)
    stage.mkdir(mode=0o700, parents=True)
    (stage/'source-netns').write_text(os.readlink('/proc/self/ns/net'))
    with ExitStack() as stack:
        for name in ['.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock']:
            fd = os.open(home/name, os.O_RDWR | os.O_NOFOLLOW)
            stream = stack.enter_context(os.fdopen(fd, 'r+b'))
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid():
                raise RuntimeError('Unexpected release lock')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        size = sum(p.stat().st_size for p in candidates(home) if not p.is_symlink())
        if shutil.disk_usage(home).free < size*3+3*1024**3:
            raise RuntimeError('Insufficient recovery headroom')
        started = time.monotonic()
        receipt = {'started_utc': datetime.now(timezone.utc).isoformat(), 'ok': False,
                   'source_database_mutations': False, 'production_files_replaced': False,
                   'off_host_backup_verified': False}
        try:
            helper = Path(__file__).with_name('restore-dump.php')
            try:
                result = bounded([php, str(helper), str(home), str(stage)], text=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180)
            except subprocess.CalledProcessError as error:
                (stage/'source-dump-error.log').write_text(error.stderr or '')
                raise RuntimeError('Source database backup failed; details retained privately') from None
            receipt['dump'] = json.loads(result.stdout)
            print('BACKUP_DATABASE_OK '+str(receipt['dump']['tables'])+' tables', flush=True)
            receipt['files'] = archive_sources(home, stage)
            print('BACKUP_FILES_OK '+str(receipt['files']['files']), flush=True)
        except BaseException as error:
            receipt['failure_class'] = type(error).__name__
            private_json(stage/'failed.json', receipt)
            raise
        finally:
            # The source option file must not survive a helper timeout.
            option = stage/'dump-client.cnf'
            if option.is_file() and not option.is_symlink():
                option.unlink()
    # Release deployment locks after the source snapshot. Restore uses only the
    # private archive and database; ordinary site activity continues independently.
    command = ['unshare', '--user', '--map-root-user', '--net', '--', sys.executable,
               str(Path(__file__).resolve()), '--child', str(stage), '--php', php]
    try:
        bounded(command, timeout=420)
        receipt['recovery'] = json.loads((stage/'recovered.json').read_text())
        receipt['ok'] = receipt['recovery']['ok']
        receipt['elapsed_seconds'] = round(time.monotonic()-started, 2)
        # Preserve the tested archive/dump; discard only disposable restored copies.
        for name in ['restored', 'mariadb-data']:
            path = stage/name
            if path.exists() and path.resolve() == path and path.parent == stage:
                shutil.rmtree(path)
        with gzip.open(stage/'database.sql.gz', 'xb', compresslevel=1) as target, (stage/'database.sql').open('rb') as source:
            os.chmod(stage/'database.sql.gz', 0o600)
            shutil.copyfileobj(source, target, 1024*1024)
        receipt['database_archive_sha256'] = sha(stage/'database.sql.gz')
        (stage/'database.sql').unlink()
        private_json(stage/'receipt.json', receipt)
        print('BACKUP_RESTORE_COMPLETE '+json.dumps(receipt), flush=True)
    except BaseException:
        receipt['elapsed_seconds'] = round(time.monotonic()-started, 2)
        private_json(stage/'failed.json', receipt)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--home', type=Path, default=Path.home())
    parser.add_argument('--stage', type=Path)
    parser.add_argument('--php', required=True)
    parser.add_argument('--child', type=Path)
    parser.add_argument('--exclude-operator-artifact', action='append', default=[])
    args = parser.parse_args()
    for path in args.exclude_operator_artifact:
        if path not in OPERATOR_ARTIFACTS:
            parser.error('Only an explicitly reviewed historical operator artifact may be excluded')
        EXCLUDED_PREVIEWS.add(path)
    if args.child:
        child(args.child.resolve(), args.php)
    else:
        if args.stage is None or not re.fullmatch('security-restore-[a-z0-9-]+', args.stage.name):
            parser.error('A new private security-restore-* stage is required')
        run(args.home.resolve(), args.stage, args.php)
