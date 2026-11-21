#!/usr/bin/env python3
"""Stage and install the reviewed, disabled-by-default administrator MCP release.

Usage: python3 deploy-admin-mcp-20261121.py prepare|apply|verify SHA OWNER_ID
Never installs SSH authorized_keys, enables an OAuth grant, or touches customer services.
"""
import concurrent.futures
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import urllib.request

ROOT = Path('/home/servernetcloud/servernet_app')
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
COMPOSER_VERSION = '2.11.2'
COMPOSER_SHA256 = 'dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028'

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None

def private(path, data):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    path.write_bytes(data if isinstance(data, bytes) else data.encode())
    path.chmod(0o600)

def download(url):
    with urllib.request.urlopen(url, timeout=90) as response:
        if response.status != 200:
            raise RuntimeError('Download failed')
        return response.read()

def php(code, *args, cwd=ROOT):
    return subprocess.check_output([PHP, '-r', code, *map(str, args)], cwd=cwd, text=True)

def atomic(path, data, mode=0o644):
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    temp = path.with_name(path.name + '.mcp-install')
    if temp.exists() or temp.is_symlink() or path.is_symlink():
        raise RuntimeError('Unexpected or symlink destination')
    private(temp, data)
    temp.chmod(mode)
    os.replace(temp, path)

def owner_check(owner):
    result = php('require "vendor/autoload.php"; $a=require "bootstrap/app.php"; '
                 '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                 '$u=App\\Models\\User::find((int)$argv[1]); '
                 'if(!$u || !$u->isAdmin()){exit(3);} '
                 'echo json_encode(["owner_id"=>$u->id,"owner_name"=>$u->name]);', owner)
    return json.loads(result)

def prepare(folder, sha, owner):
    if (folder/'plan.json').exists():
        raise RuntimeError('Prepared packet already exists. Inspect it; do not overwrite.')
    release = json.loads(download(f'https://raw.githubusercontent.com/servernetir/server/{sha}/scripts/admin-mcp-release-20261121.json'))
    folder.mkdir(mode=0o700, parents=True, exist_ok=True)
    owner_info = owner_check(owner)
    if list((ROOT/'bootstrap/cache').glob('routes*.php')):
        raise RuntimeError('Route cache requires a separate reviewed activation plan')
    def stage(item):
        path = item['path']
        if not re.fullmatch(r'[A-Za-z0-9_./-]+', path) or '..' in Path(path).parts:
            raise RuntimeError('Unsafe source path')
        dest = ROOT/path
        if dest.is_symlink():
            raise RuntimeError('Symlink destination')
        before = dest.read_bytes() if dest.is_file() else None
        if item['kind'] == 'patch':
            if before is None:
                raise RuntimeError('Missing patch target: '+path)
            text = before.decode()
            for patch in item['patches']:
                if text.count(patch['before']) != 1:
                    raise RuntimeError('Patch context changed: '+path)
                text = text.replace(patch['before'], patch['after'], 1)
            after = text.encode()
        else:
            if item['kind'] == 'new' and before is not None:
                raise RuntimeError('New destination already exists: '+path)
            after = download(f'https://raw.githubusercontent.com/servernetir/server/{sha}/website/{path}')
            if hashlib.sha256(after).hexdigest() != item['sha256']:
                raise RuntimeError('Source digest mismatch')
        key = hashlib.sha256(path.encode()).hexdigest()
        if before is not None:
            private(folder/(key+'.before'), before)
        private(folder/(key+'.after'), after)
        if path.endswith('.php'):
            subprocess.run([PHP, '-l', str(folder/(key+'.after'))], check=True, stdout=subprocess.DEVNULL)
        return {'path': path, 'key': key, 'before': digest(dest),
                'after': hashlib.sha256(after).hexdigest()}
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        files = list(pool.map(stage, release['files']))
    lock_entry = next(f for f in files if f['path'] == 'composer.lock')
    proposed = folder/(lock_entry['key']+'.after')
    # Refuse to remove or downgrade any runtime dependency already on production.
    comparison = php('$a=json_decode(file_get_contents("composer.lock"),true); '
        '$b=json_decode(file_get_contents($argv[1]),true); $map=[]; '
        'foreach($b["packages"] as $p){$map[$p["name"]]=ltrim($p["version"],"v");} '
        'foreach($a["packages"] as $p){if(!isset($map[$p["name"]]) || '
        'version_compare($map[$p["name"]],ltrim($p["version"],"v"),"<")){echo $p["name"],"\n";}}', proposed)
    if comparison.strip():
        raise RuntimeError('Runtime dependency removal/downgrade refused: '+comparison.strip())
    deps = folder/'dependencies'
    deps.mkdir(mode=0o700)
    for path in ['composer.json', 'composer.lock']:
        item = next(f for f in files if f['path'] == path)
        private(deps/path, (folder/(item['key']+'.after')).read_bytes())
    # Composer may scan these public source trees; no .env or customer storage is copied.
    for path in ['app', 'database']:
        (deps/path).symlink_to(ROOT/path, target_is_directory=True)
    composer = download(f'https://getcomposer.org/download/{COMPOSER_VERSION}/composer.phar')
    if hashlib.sha256(composer).hexdigest() != COMPOSER_SHA256:
        raise RuntimeError('Composer digest mismatch')
    private(folder/'composer.phar', composer)
    with (folder/'composer-install.log').open('w') as log:
        subprocess.run([PHP, str(folder/'composer.phar'), 'install', '--no-dev', '--no-scripts',
                        '--no-plugins', '--prefer-dist', '--no-interaction', '--no-progress'],
                       cwd=deps, check=True, stdout=log, stderr=log)
        subprocess.run([PHP, str(folder/'composer.phar'), 'check-platform-reqs', '--no-dev'],
                       cwd=deps, check=True, stdout=log, stderr=log)
    (folder/'composer-install.log').chmod(0o600)
    plan = {'sha': sha, 'owner': owner, 'files': files,
            'installed_before': digest(ROOT/'vendor/composer/installed.json'), 'migration_paths': release['migration_paths']}
    private(folder/'plan.json', json.dumps(plan, indent=2))
    print(json.dumps({'status': 'prepared', 'files': len(files), **owner_info, 'access_enabled': False}))

def apply(folder, sha, owner):
    plan = json.loads((folder/'plan.json').read_text())
    if plan['sha'] != sha or plan['owner'] != owner or (folder/'applied.json').exists():
        raise RuntimeError('Packet identity mismatch or already applied')
    owner_check(owner)
    for item in plan['files']:
        if digest(ROOT/item['path']) != item['before']:
            raise RuntimeError('Live source changed since prepare: '+item['path'])
    if digest(ROOT/'vendor/composer/installed.json') != plan['installed_before']:
        raise RuntimeError('Live dependencies changed since prepare')
    if (ROOT/'vendor').is_symlink():
        raise RuntimeError('Unexpected vendor symlink')
    caches = {}
    for name in ['config.php', 'packages.php', 'services.php']:
        path = ROOT/'bootstrap/cache'/name
        caches[name] = path.read_bytes() if path.is_file() else None
        if caches[name] is not None:
            private(folder/('cache-before-'+name), caches[name])
    # Generate a separate signing key only if this app has none; never rotate an existing key.
    keys = [ROOT/'storage/oauth-private.key', ROOT/'storage/oauth-public.key']
    if any(p.exists() for p in keys) and not all(p.exists() for p in keys):
        raise RuntimeError('Incomplete existing Passport key pair')
    if not any(p.exists() for p in keys):
        php('$k=openssl_pkey_new(["private_key_bits"=>3072]); '
            'if(!$k || !openssl_pkey_export($k,$p)){exit(2);} '
            'file_put_contents($argv[1],$p); file_put_contents($argv[2],openssl_pkey_get_details($k)["key"]); '
            'chmod($argv[1],0600); chmod($argv[2],0600);', *keys)
    written = []
    vendor_swapped = False
    try:
        # New classes are inert until the provider is registered at the end.
        for item in plan['files']:
            if item['before'] is not None:
                continue
            if item['before'] == item['after']:
                continue
            atomic(ROOT/item['path'], (folder/(item['key']+'.after')).read_bytes())
            written.append(item)
        os.rename(ROOT/'vendor', folder/'vendor.before')
        try:
            os.rename(folder/'dependencies/vendor', ROOT/'vendor')
        except BaseException:
            os.rename(folder/'vendor.before', ROOT/'vendor')
            raise
        vendor_swapped = True
        for item in plan['files']:
            if item['before'] is None or item['path'] == 'bootstrap/providers.php':
                continue
            if item['before'] != item['after']:
                atomic(ROOT/item['path'], (folder/(item['key']+'.after')).read_bytes())
                written.append(item)
        # Run only the five new additive migrations, never the outstanding global queue.
        for path in plan['migration_paths']:
            subprocess.run([PHP, 'artisan', 'migrate', '--force', '--path='+path], cwd=ROOT, check=True)
        php('require "vendor/autoload.php"; $a=require "bootstrap/app.php"; '
            '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            '$old=App\\Models\\Setting::get("mcp_admin_owner_user_id"); '
            'if($old!==null && (int)$old!==(int)$argv[1]){exit(5);} '
            'App\\Models\\Setting::put("mcp_admin_owner_user_id",$argv[1]);', owner)
        # Preserve the live cached configuration and its secrets; add only new keys.
        if caches['config.php'] is not None:
            cache_text = php('require "vendor/autoload.php"; $c=require "bootstrap/cache/config.php"; '
                '$c["auth"]["guards"]["mcp_admin"]=["driver"=>"passport","provider"=>"users"]; '
                '$c["mcp_admin"]=require "config/mcp_admin.php"; '
                'foreach(["mcp","passport"] as $n){if(!isset($c[$n])){$c[$n]=require "vendor/laravel/$n/config/$n.php";}} '
                'echo "<?php return ".var_export($c,true).";\n";')
            atomic(ROOT/'bootstrap/cache/config.php', cache_text, 0o600)
        provider = next(f for f in plan['files'] if f['path'] == 'bootstrap/providers.php')
        atomic(ROOT/provider['path'], (folder/(provider['key']+'.after')).read_bytes())
        written.append(provider)
        # Build just the package manifest. Do not flush shared caches or start any jobs.
        php('require "vendor/autoload.php"; '
            '$m=new Illuminate\\Foundation\\PackageManifest(new Illuminate\\Filesystem\\Filesystem,getcwd(),getcwd()."/bootstrap/cache/packages.php"); $m->build();')
        for item in plan['files']:
            if digest(ROOT/item['path']) != item['after']:
                raise RuntimeError('Installed hash mismatch')
        private(folder/'applied.json', json.dumps({'sha': sha, 'files': len(plan['files']), 'access_enabled': False}))
        print(json.dumps({'status': 'installed', 'sha': sha, 'access_enabled': False}))
    except BaseException:
        # Additive tables/signing keys are retained; no customer data or keys are deleted.
        for item in reversed(written):
            path = ROOT/item['path']
            if digest(path) != item['after']:
                continue
            if item['before'] is None:
                path.unlink()
            else:
                atomic(path, (folder/(item['key']+'.before')).read_bytes())
        if vendor_swapped:
            os.rename(ROOT/'vendor', folder/'vendor.failed')
            os.rename(folder/'vendor.before', ROOT/'vendor')
        for name, content in caches.items():
            path = ROOT/'bootstrap/cache'/name
            if content is not None:
                atomic(path, content, 0o600)
            elif path.exists():
                path.unlink()
        raise

def verify(folder, sha, owner):
    plan = json.loads((folder/'plan.json').read_text())
    if any(digest(ROOT/f['path']) != f['after'] for f in plan['files']):
        raise RuntimeError('Verification hash mismatch')
    output = php('require "vendor/autoload.php"; $a=require "bootstrap/app.php"; '
        '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
        'foreach(["mcp-admin.authorize","mcp-admin.index","mcp-admin.action"] as $v){'
        'app("blade.compiler")->compile(app("view.finder")->find($v));} '
        'echo json_encode(["resource"=>config("mcp_admin.resource"), '
        '"owner_id"=>(int)App\\Models\\Setting::get("mcp_admin_owner_user_id"), '
        '"enabled"=>App\\Services\\Mcp\\AdminAccess::enabled(), '
        '"guard"=>config("auth.guards.mcp_admin.driver"), '
        '"actions_table"=>Illuminate\\Support\\Facades\\Schema::hasTable("mcp_admin_actions")]);')
    print(output)

if __name__ == '__main__':
    mode, sha, owner_text = sys.argv[1:]
    if mode not in ['prepare', 'apply', 'verify'] or not re.fullmatch('[0-9a-f]{40}', sha) or not owner_text.isdigit():
        raise SystemExit('Invalid arguments')
    owner = int(owner_text)
    if not ROOT.is_dir() or owner < 1:
        raise SystemExit('Unexpected installation root or owner')
    os.umask(0o077)
    with (ROOT/'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        folder = ROOT.parent/'deploy-backups'/('admin-mcp-20261121-'+sha[:12])
        {'prepare': prepare, 'apply': apply, 'verify': verify}[mode](folder, sha, owner)
