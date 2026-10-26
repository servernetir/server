#!/usr/bin/env python3
"""Prepare, apply or finalize the bounded backlog release. No credentials required.

Run inside the owner's existing cPanel terminal. Downloads public, pinned source;
never reads .env, grants access, starts providers, sends messages or moves money.
Preparation writes only a private review package. Applying requires its unchanged
live hashes, both existing release locks, and the exact additive migration.
"""
import argparse
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import urllib.request

PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
MIGRATION = 'database/migrations/2026_10_25_150000_create_wallet_refund_workflow.php'
TABLES = ['wallet_refund_permissions', 'wallet_refund_requests', 'wallet_refund_events']


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def merge_candidate(current, base, desired):
    if current == desired:
        return current
    if current == base:
        return desired
    if base is None or current is None:
        raise RuntimeError('Unexpected existing or missing target')
    with tempfile.TemporaryDirectory() as directory:
        files = [Path(directory) / n for n in ['live', 'base', 'source']]
        for path, content in zip(files, [current, base, desired]):
            path.write_bytes(content)
        result = subprocess.run(['git', 'merge-file', '-p', *map(str, files)], capture_output=True)
        if result.returncode:
            raise RuntimeError('Overlapping live edits require review')
        return result.stdout


def merge_runtime(name, current, base, desired):
    # The live settings file has historical formatting drift. This release's
    # entire source delta in that controller is the reviewed tab caption.
    if name == 'app/Http/Controllers/Admin/SettingsController.php':
        old = "'accounts' => ['t' => 'حساب‌ها',".encode()
        new = "'accounts' => ['t' => 'حساب‌ها و پرداخت',".encode()
        old_line = b'        ' + old + b"         'icon' => 'i-coins'],"
        new_line = b'        ' + new + b" 'icon' => 'i-coins'],"
        if base is not None and base.count(old_line) == 1 and base.replace(old_line, new_line, 1) == desired and current is not None:
            if current.count(old) == 1:
                return current.replace(old, new, 1)
            if current.count(new) == 1:
                return current
    if name == 'config/services.php' and base is not None and current is not None:
        anchor = b'return [\n'
        block = b"    // Reseller origin must be confirmed by VPS.TC; no guessed default host.\n    // Credentials may instead be stored encrypted in the dedicated admin page.\n    'vpstc' => [\n        'base_url' => env('VPSTC_API_BASE_URL', ''),\n        'username' => env('VPSTC_API_USERNAME', ''),\n        'password' => env('VPSTC_API_PASSWORD', ''),\n    ],\n\n"
        live_anchor = b'return [\r\n' if current.count(b'return [\r\n') == 1 else anchor
        live_block = block.replace(b'\n', b'\r\n') if live_anchor.endswith(b'\r\n') else block
        if base.count(anchor) == 1 and base.replace(anchor, anchor + block, 1) == desired and current.count(live_anchor) == 1:
            if live_block in current:
                return current
            if b"'vpstc'" not in current:
                return current.replace(live_anchor, live_anchor + live_block, 1)
    return merge_candidate(current, base, desired)


def fetch(url):
    with urllib.request.urlopen(url, timeout=45) as response:
        data = response.read(90_000_001)
    if len(data) > 90_000_000:
        raise RuntimeError('Source download exceeded limit')
    return data


def sources(sha, wanted):
    if not re.fullmatch('[a-f0-9]{40}', sha):
        raise RuntimeError('A full pinned commit is required')
    output = {}
    archive = tarfile.open(fileobj=io.BytesIO(fetch('https://codeload.github.com/servernetir/server/tar.gz/' + sha)), mode='r:gz')
    for member in archive:
        relative = member.name.partition('/')[2]
        if relative in wanted:
            if not member.isfile() or member.size > 1_000_000:
                raise RuntimeError('Invalid source member')
            output[relative] = archive.extractfile(member).read()
    if set(output) != set(wanted):
        raise RuntimeError('Incomplete source archive')
    return output


def probe(live):
    code = r'''
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$schema = Illuminate\Support\Facades\Schema::class;
$prerequisites = [];
foreach (['customers','settings','credit_ledger','bank_accounts','finance_vouchers','finance_documents','finance_controls','finance_accounts','migrations'] as $t) $prerequisites[$t] = $schema::hasTable($t);
$tables=[];
foreach (['wallet_refund_permissions','wallet_refund_requests','wallet_refund_events'] as $t) $tables[$t] = $schema::hasTable($t) ? Illuminate\Support\Facades\DB::table($t)->count() : null;
echo json_encode(['php'=>PHP_VERSION,'bcmath'=>extension_loaded('bcmath'),'prerequisites'=>$prerequisites,'tables'=>$tables,'config_cached'=>$app->configurationIsCached(),'routes_cached'=>$app->routesAreCached()]);
'''
    result = subprocess.run([PHP, '-r', code], cwd=live, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Read-only application preflight failed; inspect privately')
    result = json.loads(result.stdout)
    if not result['bcmath'] or not all(result['prerequisites'].values()):
        raise RuntimeError('Application or database prerequisites missing')
    if any(value not in [None, 0] for value in result['tables'].values()):
        raise RuntimeError('Existing refund data requires a separate reviewed release')
    if len({value is None for value in result['tables'].values()}) != 1:
        raise RuntimeError('Partial refund schema requires review')
    return result


def php_lint(path):
    if path.suffix == '.php':
        result = subprocess.run([PHP, '-l', str(path)], capture_output=True)
        if result.returncode:
            raise RuntimeError('PHP lint failed for ' + path.name)


def artisan(live, *arguments):
    result = subprocess.run([PHP, 'artisan', *arguments], cwd=live, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Artisan failed: ' + arguments[0] + '; inspect privately')


def activation_helper(live, stage, manifest):
    targets = [str(live / name) for name in manifest if name.endswith('.php')]
    # Standard web/session/CSRF/admin middleware; no deploy token or new credential.
    name = 'servernet-backlog-activate-' + digest(str(stage).encode())[:16] + '.php'
    code = '''<?php
require %s;
$app = require %s;
$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
Illuminate\\Support\\Facades\\Route::match(['GET','POST'], '/%s', function (Illuminate\\Http\\Request $request) {
    if ($request->isMethod('GET')) return response('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>فعال‌سازی انتشار</title><h1>فعال‌سازی قابلیت‌های بررسی‌شده</h1><p>فقط کش کد همین انتشار تازه می‌شود.</p><form method="post">'.csrf_field().'<button type="submit">فعال‌سازی انتشار بررسی‌شده</button></form></html>')->header('Cache-Control','private, no-store');
    $failed = []; $cached = 0;
    foreach (json_decode(%s, true) as $file) {
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($file)) {
            $cached++;
            if (!opcache_invalidate($file, true)) $failed[] = basename($file);
        }
    }
    abort_if($failed !== [], 500);
    $receipt = ['activated'=>true,'cached_files_invalidated'=>$cached,'utc'=>gmdate('c')];
    file_put_contents(%s, json_encode($receipt));
    return response()->json($receipt)->header('Cache-Control','private, no-store');
})->middleware(['web','auth','admin']);
$request=Illuminate\\Http\\Request::capture();
$response=$kernel->handle($request); $response->send(); $kernel->terminate($request,$response);
''' % (repr(str(live / 'vendor/autoload.php')), repr(str(live / 'bootstrap/app.php')), name,
       repr(json.dumps(targets)), repr(str(stage / 'activation.json')))
    path = Path.home() / 'public_html' / name
    if path.exists():
        raise RuntimeError('Activation helper path already exists')
    path.write_text(code)
    php_lint(path)
    return path, digest(path.read_bytes())


def prepare(args, live, stage):
    if stage.exists():
        raise RuntimeError('Private stage already exists; inspect it before continuing')
    source_manifest = json.loads(fetch('https://raw.githubusercontent.com/servernetir/server/' + args.source + '/docs/project/backlog-2026-10-26/source-manifest.json'))
    entries = source_manifest['files']
    allowed = ('app/', 'config/', 'lang/', 'resources/views/', 'routes/', 'database/migrations/')
    if any(not name.startswith(allowed) or '..' in Path(name).parts for name in entries):
        raise RuntimeError('Unexpected runtime path')
    before = probe(live)
    desired = sources(args.source, ['website/' + name for name in entries])
    base = sources(source_manifest['base_sha'], ['website/' + name for name, row in entries.items() if row['base'] is not None])
    candidates = {}
    manifest = {}
    conflicts = []
    for name, row in entries.items():
        new = desired['website/' + name]
        old = base.get('website/' + name)
        if digest(new) != row['source'] or digest(old) != row['base']:
            raise RuntimeError('Pinned source hash mismatch: ' + name)
        path = live / name
        current = path.read_bytes() if path.exists() else None
        try:
            merged = merge_runtime(name, current, old, new)
        except RuntimeError as error:
            conflicts.append(name)
            continue
        candidates[name] = (current, merged)
        manifest[name] = {'before': digest(current), 'after': digest(merged), 'drift': current != old, 'changed': current != merged}
    if conflicts:
        raise RuntimeError('Overlapping live edits require review: ' + ', '.join(conflicts))
    stage.mkdir(mode=0o700)
    for name, (current, merged) in candidates.items():
        target = stage / 'candidate' / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(merged)
        php_lint(target)
        if current is not None:
            backup = stage / 'before' / name
            backup.parent.mkdir(parents=True, exist_ok=True)
            backup.write_bytes(current)
    (stage / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    (stage / 'preflight.json').write_text(json.dumps(before, indent=2))
    print(json.dumps({'status': 'prepared', 'files': len(manifest), 'changed': sum(row['changed'] for row in manifest.values()),
                      'preserved_drift': [name for name, row in manifest.items() if row['drift']], 'business_writes': 0}))


def apply(live, stage):
    if (stage / 'installed.json').exists():
        raise RuntimeError('Installation already recorded; do not run twice')
    manifest = json.loads((stage / 'manifest.json').read_text())
    before = probe(live)
    for name, row in manifest.items():
        path = live / name
        if digest(path.read_bytes() if path.exists() else None) != row['before']:
            raise RuntimeError('Concurrent live change: ' + name)
        if digest((stage / 'candidate' / name).read_bytes()) != row['after']:
            raise RuntimeError('Candidate was modified: ' + name)
    def rank(name):
        if manifest[name]['before'] is None:
            return 0
        return 3 if name.startswith('resources/views/') else (2 if name.startswith('routes/') else 1)
    changed = sorted((name for name in manifest if manifest[name]['changed']), key=rank)
    installed = []
    migration_run = False
    try:
        for name in changed:
            if rank(name) >= 2 and not migration_run:
                if all(value is None for value in before['tables'].values()):
                    artisan(live, 'migrate', '--path=' + MIGRATION, '--force', '--no-interaction')
                migration_run = True
            target = live / name
            if digest(target.read_bytes() if target.exists() else None) != manifest[name]['before']:
                raise RuntimeError('Live drift during installation: ' + name)
            target.parent.mkdir(parents=True, exist_ok=True)
            sibling = target.with_name(target.name + '.backlog-stage')
            if sibling.exists():
                raise RuntimeError('Staging sibling already exists')
            sibling.write_bytes((stage / 'candidate' / name).read_bytes())
            os.chmod(sibling, 0o644)
            os.replace(sibling, target)
            installed.append(name)
        if not migration_run and all(value is None for value in before['tables'].values()):
            artisan(live, 'migrate', '--path=' + MIGRATION, '--force', '--no-interaction')
        after = probe(live)
        if any(value != 0 for value in after['tables'].values()):
            raise RuntimeError('Refund schema did not initialize empty')
        if before['config_cached']:
            artisan(live, 'config:clear')
        if before['routes_cached']:
            artisan(live, 'route:clear')
        artisan(live, 'view:clear')
        helper, helper_hash = activation_helper(live, stage, manifest)
        receipt = {'status': 'installed', 'files': len(manifest), 'changed': len(installed),
                   'schema': after['tables'], 'activation_file': helper.name, 'activation_sha256': helper_hash}
        (stage / 'installed.json').write_text(json.dumps(receipt, indent=2))
        print(json.dumps(receipt))
    except Exception:
        restored = []
        for name in reversed(installed):
            target = live / name
            if target.exists() and digest(target.read_bytes()) == manifest[name]['after'] and manifest[name]['before'] is not None:
                target.write_bytes((stage / 'before' / name).read_bytes())
                restored.append(name)
        (stage / 'failure.json').write_text(json.dumps({'restored': restored, 'schema_preserved': True}))
        raise


def finalize(live, stage):
    installed = json.loads((stage / 'installed.json').read_text())
    activation = json.loads((stage / 'activation.json').read_text())
    if activation.get('activated') is not True:
        raise RuntimeError('Web activation is not confirmed')
    manifest = json.loads((stage / 'manifest.json').read_text())
    if any(digest((live / name).read_bytes()) != row['after'] for name, row in manifest.items()):
        raise RuntimeError('Post-install file drift; inspect rather than restore')
    helper = Path.home() / 'public_html' / installed['activation_file']
    if helper.exists():
        if digest(helper.read_bytes()) != installed['activation_sha256']:
            raise RuntimeError('Activation helper changed; refusing cleanup')
        helper.unlink()
    receipt = {'status': 'finalized', 'file_hashes': len(manifest), 'activation': activation, 'schema': probe(live)['tables'], 'helper_removed': True}
    (stage / 'final.json').write_text(json.dumps(receipt, indent=2))
    print(json.dumps(receipt))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['prepare', 'apply', 'finalize'])
    parser.add_argument('--source', required=True)
    parser.add_argument('--stage', required=True)
    args = parser.parse_args()
    stage = Path(args.stage).resolve()
    if stage.parent != Path.home() or not stage.name.startswith('servernet-backlog-release-'):
        raise RuntimeError('Stage must be a fresh private home release directory')
    if not re.fullmatch('[a-f0-9]{40}', args.source):
        raise RuntimeError('Full source SHA required')
    live = Path.home() / 'servernet_app'
    locks = []
    for path in [Path.home() / '.servernet-web-release.lock', Path.home() / 'servernet-release.lock']:
        handle = path.open('a')
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        locks.append(handle)
    if args.mode == 'prepare':
        prepare(args, live, stage)
    elif args.mode == 'apply':
        apply(live, stage)
    else:
        finalize(live, stage)


if __name__ == '__main__':
    main()
