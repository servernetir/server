#!/usr/bin/env python3
"""Install only the reviewed hardware files and insert quote-only catalogue rows.
   The production command must be approved by the account owner. No migration,
   secrets access, general cache flush, prices, or existing-record updates.
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

HOME = Path('/home/servernetcloud')
APP = HOME / 'servernet_app'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
RELEASE = 'datacenter-equipment-20261124'
PREFIX = 'scripts/' + RELEASE + '/'
CATALOGUES = ('website/database/catalog/hardware-growth-20261123.json',
              'website/database/catalog/datacenter-equipment-20261124.json')


def sha(data):
    return hashlib.sha256(data).hexdigest()


def fetch(commit, source):
    assert re.fullmatch(r'[a-f0-9]{40}', commit)
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/' + commit + '/' + source, timeout=45) as response:
        return response.read()


def target(row):
    source, dest = row['source'], row['destination']
    assert source.startswith('website/') and '..' not in Path(source).parts
    rel = source.removeprefix('website/')
    assert rel.startswith(('app/', 'config/', 'lang/', 'resources/views/', 'routes/web.php', 'database/catalog/', 'public/assets/'))
    permitted = ['servernet_app/' + rel]
    if rel.startswith('public/assets/'):
        permitted.append('public_html/' + rel.removeprefix('public/'))
    assert dest in permitted, 'Invalid target mapping'
    result = HOME / dest
    assert result.parent.is_dir() and not result.is_symlink() and result.resolve().is_relative_to(HOME)
    assert not result.exists() or result.is_file()
    return result


def current(row):
    p = target(row)
    return sha(p.read_bytes()) if p.exists() else None


def atomic(path, data, mode=0o600):
    assert not path.is_symlink()
    fd, name = tempfile.mkstemp(prefix='.hardware-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(name, mode)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def manifest(commit):
    rows = json.loads(fetch(commit, PREFIX + 'release-manifest.json'))['files']
    assert len(rows) == 39 and len({r['destination'] for r in rows}) == len(rows), 'Unexpected release scope'
    for row in rows:
        assert set(row) == {'source', 'destination', 'before', 'after'}
        assert row['before'] and all(x is None or re.fullmatch('[a-f0-9]{64}',x) for x in row['before'])
        assert re.fullmatch('[a-f0-9]{64}', row['after'])
        target(row)
    return rows


def preflight_catalogue(combined):
    php = r'''require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$counts = [];
foreach (['part' => 'server_parts', 'server' => 'physical_servers'] as $kind => $table) {
    if (!Illuminate\Support\Facades\Schema::hasTable($table)) exit(12);
    $slugs = array_column(array_values(array_filter($rows, fn($row)=>$row['kind']===$kind)), 'slug');
    $counts[$kind] = Illuminate\Support\Facades\DB::table($table)->whereIn('slug', $slugs)->count();
}
echo json_encode($counts, JSON_THROW_ON_ERROR);'''
    result = subprocess.run([PHP, '-r', php, str(combined)], cwd=APP, check=True, capture_output=True, text=True)
    counts = json.loads(result.stdout)
    assert counts == {'part':0,'server':0}, 'Catalogue identities already exist; reconcile before any live write'


def php_import(combined, receipt, mode=None):
    cmd = [PHP, 'artisan', 'shop:import-hardware', '--manifest='+str(combined), '--receipt='+str(receipt)]
    if mode:
        cmd.append('--'+mode)
    result = subprocess.run(cmd, cwd=APP, check=True, capture_output=True, text=True)
    return json.loads(result.stdout)


def restore(rows, stage, changed):
    for idx in reversed(changed):
        row = rows[idx]
        assert current(row) == row['after'], 'Concurrent edit; refusing rollback: '+row['destination']
        p = target(row)
        if row['original'] is None:
            # Recoverable removal of the exact newly installed bytes.
            os.replace(p, stage / ('removed-'+str(idx)))
        else:
            data = (stage / ('before-'+str(idx))).read_bytes()
            assert sha(data) == row['original']
            atomic(p, data, row['mode'])
        assert current(row) == row['original']


def refresh_pages():
    # Invalidate only the site-page cache namespace after changing the global
    # navigation. Other caches, locks, sessions and queues remain untouched.
    php = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Http\Middleware\PageCache::purge();'''
    subprocess.run([PHP, '-r', php], cwd=APP, check=True, stdout=subprocess.DEVNULL)


def apply(commit, stage, rows):
    prepared, receipt = stage/'prepared.json', stage/'receipt.json'
    data_receipt, combined = stage/'catalogue-receipt.json', stage/'catalogue.json'
    assert not any(p.exists() for p in (prepared,receipt,data_receipt,combined)), 'Existing release state; inspect before retry'
    assert not (APP/'bootstrap/cache/config.php').exists() and not list((APP/'bootstrap/cache').glob('routes*.php')), 'Config/route cache requires separate activation plan'
    # Preflight *all* destinations and downloaded bytes before writing production.
    payloads = {}
    for row in rows:
        original = current(row)
        assert original in row['before'] or original == row['after'], 'Unexpected live bytes: '+row['destination']
        p = target(row)
        if row['source'] not in payloads:
            payloads[row['source']] = fetch(commit,row['source'])
        assert sha(payloads[row['source']]) == row['after']
        row.update(original=original, mode=(p.stat().st_mode & 0o777) if p.exists() else 0o644)
    records = [record for source in CATALOGUES for record in json.loads(payloads[source])]
    assert len(records) == 79 and len({r['slug'] for r in records}) == 79
    assert all(r['data']['condition']=='catalog' and r['data']['price_contact'] is True and
               'price_eur' not in r['data'] and 'price_irt' not in r['data'] and
               (r['kind']!='part' or r['data']['in_stock'] is False) for r in records)
    atomic(combined,json.dumps(records,ensure_ascii=False).encode())
    preflight_catalogue(combined)
    for i,row in enumerate(rows):
        p = target(row)
        if row['original'] is not None:
            backup = p.read_bytes()
            assert sha(backup) == row['original']
            atomic(stage/('before-'+str(i)),backup)
        if row['source'].endswith('.php') and not row['source'].endswith('.blade.php'):
            staged = stage/('lint-'+str(i)+'.php')
            atomic(staged,payloads[row['source']])
            subprocess.run([PHP,'-l',str(staged)],check=True,stdout=subprocess.DEVNULL)
    atomic(prepared,json.dumps({'sha':commit,'files':rows}).encode())
    changed = []
    imported = False
    try:
        for i,row in enumerate(rows):
            assert current(row)==row['original'], 'Concurrent edit before install: '+row['destination']
            if row['original']==row['after']:
                continue
            atomic(target(row),payloads[row['source']],row['mode'])
            changed.append(i)
        assert all(current(row)==row['after'] for row in rows)
        plan = php_import(combined,data_receipt)
        assert plan['insert']==79 and plan['enrich']==0 and plan['applied'] is False
        applied = php_import(combined,data_receipt,'apply')
        imported = True
        assert applied['insert']==79 and applied['applied'] is True
        refresh_pages()
        atomic(receipt,json.dumps({'sha':commit,'files':len(rows),'inserted':79,'changed':changed}).encode())
        return {'status':'installed','files':len(rows),'inserted':79}
    except BaseException:
        # If an import interrupted after writing its receipt, never guess about
        # database state; stop for inspection rather than a blind file rollback.
        if data_receipt.exists() and not imported:
            raise RuntimeError('Import receipt present; inspect transaction before any rollback')
        if imported:
            php_import(combined,data_receipt,'rollback')
        restore(rows,stage,changed)
        refresh_pages()
        raise


def run(mode, commit, stage):
    rows = manifest(commit)
    if mode=='verify':
        assert all(current(row)==row['after'] for row in rows)
        receipt=json.loads((stage/'receipt.json').read_text())
        assert receipt['sha']==commit and receipt['inserted']==79
        return {'status':'verified_files','files':len(rows),'insert_receipt':79}
    if mode=='rollback':
        receipt=json.loads((stage/'receipt.json').read_text())
        saved=json.loads((stage/'prepared.json').read_text())
        assert receipt['sha']==saved['sha']==commit
        assert all(current(row)==row['after'] for row in rows)
        php_import(stage/'catalogue.json',stage/'catalogue-receipt.json','rollback')
        restore(saved['files'],stage,receipt['changed'])
        refresh_pages()
        return {'status':'rolled_back'}
    assert mode=='apply'
    return apply(commit,stage,rows)


def main():
    mode,commit=sys.argv[1:3]
    assert mode in ('apply','verify','rollback') and re.fullmatch('[a-f0-9]{40}',commit)
    assert Path.home()==HOME and os.getuid()!=0,'Unexpected account'
    stage=HOME/'deploy-backups'/(RELEASE+'-'+commit[:12])
    stage.mkdir(mode=0o700,exist_ok=True)
    assert not stage.is_symlink() and stage.resolve()==stage
    os.chmod(stage,0o700)
    with (APP/'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        result=run(mode,commit,stage)
    print(json.dumps(dict(result,sha=commit),ensure_ascii=False))


if __name__=='__main__':
    main()
