#!/usr/bin/env python3
"""Owner-approved, isolated wallet presentation and panel navigation release.

Input is a reviewed private plan with normalized before/after SHA-256 values.
No controllers, database, routes, configuration, cron, or global caches change.
"""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from datetime import datetime, timezone

HOME_DIR = Path('/home/servernetcloud')
LIVE = HOME_DIR / 'servernet_app'
STAGE = HOME_DIR / 'wallet-panel-release-20261031'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
SOURCE = '8486b6fa5cc54145533d3d6b69678e5ddc03ea08'
VIEWS = ['resources/views/panel/layout.blade.php', 'resources/views/account/wallet-refunds.blade.php']
ALLOWED = {
    'servernet_app/' + path for path in VIEWS + [
        'lang/fa/refund.php', 'lang/en/refund.php', 'lang/tr/refund.php',
        'public/assets/css/panel.css', 'public/assets/css/wallet-refunds.css',
        'public/assets/js/panel-navigation.js',
    ]
} | {'public_html/assets/' + path for path in [
    'css/panel.css', 'css/wallet-refunds.css', 'js/panel-navigation.js',
]}
BOOT = "require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"


def sha(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def normalized(data):
    return data.decode('utf-8').replace('\r\n', '\n') if data is not None else None


def read(path):
    if path.is_symlink():
        raise RuntimeError('Symlink target: ' + str(path))
    return path.read_bytes() if path.is_file() else None


def atomic(path, data, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name('.' + path.name + '.wallet-panel-staged')
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
        os.fchmod(stream.fileno(), mode)
    os.replace(temp, path)


def php(code, *args):
    result = subprocess.run([PHP, '-r', BOOT + code, str(LIVE), *map(str, args)], cwd=LIVE,
                            capture_output=True, text=True, timeout=90)
    if result.returncode:
        (STAGE / 'php-step-error.txt').write_text(result.stdout + result.stderr)
        raise RuntimeError('PHP release step failed; inspect private receipt')
    return result.stdout


def lint(path):
    result = subprocess.run([PHP, '-l', str(path)], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('PHP lint failed: ' + path.name)


def record(name, value):
    atomic(STAGE / name, json.dumps(value, indent=2).encode(), 0o600)


def activation_helper(targets):
    name = 'servernet-wallet-panel-activate-20261031.php'
    helper = HOME_DIR / 'public_html' / name
    if helper.exists():
        raise RuntimeError('Activation helper already exists')
    code = '''<?php
define('LARAVEL_START', microtime(true));
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$_SERVER['SCRIPT_FILENAME']=__DIR__.'/index.php';
$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';
$request=Illuminate\\Http\\Request::capture();$app->instance('request',$request);
Illuminate\\Support\\Facades\\Facade::clearResolvedInstance('request');
$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);$kernel->bootstrap();
Illuminate\\Support\\Facades\\Route::match(['GET','POST'],'/__ROUTE_PATH__',function(Illuminate\\Http\\Request $request){
 if($request->isMethod('GET'))return response('<!doctype html><meta charset="utf-8"><title>Wallet and panel release</title><h1>Wallet and panel release</h1><p>Refresh only the five PHP files in this release.</p><form method="post">'.csrf_field().'<button type="submit">Activate wallet and panel release</button></form>')->header('Cache-Control','private, no-store');
 $targets=json_decode('TARGETS',true,512,JSON_THROW_ON_ERROR);$cached=0;
 foreach($targets as $file=>$expected){
  abort_unless(is_file($file)&&!is_link($file)&&hash_file('sha256',$file)===$expected,409);
  if(function_exists('opcache_is_script_cached')&&opcache_is_script_cached($file)){
   abort_unless(opcache_invalidate($file,true),500);$cached++;
  }
 }
 $receipt=['activated'=>true,'targets'=>count($targets),'cached_files_invalidated'=>$cached,'utc'=>gmdate('c')];
 file_put_contents('/home/servernetcloud/wallet-panel-release-20261031/activation.json',json_encode($receipt),LOCK_EX);
 return response()->json($receipt)->header('Cache-Control','private, no-store');
})->middleware(['web','auth:web','admin']);
$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
'''.replace('__ROUTE_PATH__', name).replace('TARGETS', json.dumps(targets).replace("'", "\\'"))
    staged = STAGE / 'activation.php'
    atomic(staged, code.encode(), 0o600)
    lint(staged)
    return helper, code.encode()


def install(plan_path, expected_sha):
    if (STAGE / 'installed.json').exists():
        raise RuntimeError('Already installed; verify instead of repeating')
    data = plan_path.read_bytes()
    if sha(data) != expected_sha:
        raise RuntimeError('Plan transfer hash mismatch')
    plan = json.loads(data)
    if plan['source_commit'] != SOURCE or set(plan['files']) != ALLOWED:
        raise RuntimeError('Unexpected source or scope')
    candidates, manifest = {}, {}
    cron = subprocess.run(['crontab', '-l'], capture_output=True, check=True).stdout
    for name, item in plan['files'].items():
        target = HOME_DIR / name
        before = read(target)
        current = normalized(before)
        if sha(current.encode() if current is not None else None) != item['before_normalized_sha256']:
            raise RuntimeError('Concurrent source drift: ' + name)
        after = item.get('content')
        if after is None:
            after = current
            for old, new in item['replacements']:
                if after.count(old) != 1:
                    raise RuntimeError('Nonunique CSS patch anchor: ' + name)
                after = after.replace(old, new, 1)
        if sha(after.encode()) != item['after_normalized_sha256']:
            raise RuntimeError('Reviewed candidate mismatch: ' + name)
        candidate = after.encode()
        if before is not None and b'\r\n' in before:
            if before.count(b'\n') != before.count(b'\r\n'):
                raise RuntimeError('Mixed line endings require reconciliation: ' + name)
            candidate = candidate.replace(b'\n', b'\r\n')
        candidates[name] = candidate
        manifest[name] = {'before': sha(before), 'after': sha(candidate), 'mode': target.stat().st_mode & 0o777 if before is not None else 0o644}
        if before is not None:
            atomic(STAGE / 'before' / name, before, 0o600)
        staged = STAGE / 'staged' / name
        atomic(staged, candidate, 0o600)
        if name.endswith('.php') and not name.endswith('.blade.php'):
            lint(staged)
    # Validate the two templates privately before replacing any live source.
    php("$c=$app->make('blade.compiler');foreach(array_slice($argv,3) as $i=>$p)file_put_contents($argv[2].'/lint-view-'.$i.'.php',$c->compileString(file_get_contents($p)));", STAGE,
        *[STAGE / 'staged/servernet_app' / name for name in VIEWS])
    for path in STAGE.glob('lint-view-*.php'):
        lint(path)
    compiled = json.loads(php("echo json_encode(array_map(fn($p)=>$app->make('blade.compiler')->getCompiledPath($p),array_slice($argv,2)));", *[LIVE / name for name in VIEWS]))
    for name in compiled:
        target = Path(name)
        relative = str(target.relative_to(HOME_DIR))
        if not relative.startswith('servernet_app/storage/framework/views/'):
            raise RuntimeError('Unexpected compiled path')
        before = read(target)
        if before is not None:
            atomic(STAGE / 'before' / relative, before, 0o600)
        manifest[relative] = {'before': sha(before), 'after': None, 'compiled': True}
    record('manifest.json', {'source_commit': SOURCE, 'files': manifest, 'cron_before': sha(cron)})
    installed = []
    try:
        order = sorted(candidates, key=lambda p: 2 if p.endswith('.blade.php') else 1 if '/lang/' in p else 0)
        for name in order:
            if sha(read(HOME_DIR / name)) != manifest[name]['before']:
                raise RuntimeError('Concurrent edit before write: ' + name)
            atomic(HOME_DIR / name, candidates[name], manifest[name]['mode'])
            installed.append(name)
        php("foreach(array_slice($argv,2) as $p)$app->make('blade.compiler')->compile($p);", *[LIVE / name for name in VIEWS])
        targets = {}
        for name, item in manifest.items():
            if item.get('compiled'):
                lint(HOME_DIR / name)
                item['after'] = sha(read(HOME_DIR / name))
            elif sha(read(HOME_DIR / name)) != item['after']:
                raise RuntimeError('Post-install hash mismatch')
            if name.endswith('.php') and (item.get('compiled') or '/lang/' in name):
                targets[str(HOME_DIR / name)] = item['after']
        helper, helper_data = activation_helper(targets)
        atomic(helper, helper_data)
        receipt = {'status': 'installed', 'source_commit': SOURCE, 'source_destinations': len(candidates),
                   'compiled_views': len(compiled), 'files': manifest, 'helper': str(helper),
                   'helper_sha': sha(helper_data), 'cron_before': sha(cron),
                   'cron_after': sha(subprocess.run(['crontab', '-l'], capture_output=True, check=True).stdout),
                   'utc': datetime.now(timezone.utc).isoformat()}
        if receipt['cron_before'] != receipt['cron_after']:
            raise RuntimeError('Concurrent cron change; inspect before completing')
        record('installed.json', receipt)
        print(json.dumps({k: v for k, v in receipt.items() if k not in ['files', 'helper_sha']}))
    except BaseException:
        for name in reversed(installed):
            if sha(read(HOME_DIR / name)) != manifest[name]['after']:
                raise RuntimeError('Later edit prevents automatic rollback: ' + name)
            old = read(STAGE / 'before' / name)
            if old is None:
                (HOME_DIR / name).unlink()
            else:
                atomic(HOME_DIR / name, old, manifest[name]['mode'])
        php("foreach(array_slice($argv,2) as $p)$app->make('blade.compiler')->compile($p);", *[LIVE / name for name in VIEWS])
        record('failed.json', {'sources_restored': len(installed), 'activation_required': True})
        raise


def verify(cleanup=False):
    receipt = json.loads((STAGE / 'installed.json').read_text())
    for name, item in receipt['files'].items():
        if sha(read(HOME_DIR / name)) != item['after']:
            raise RuntimeError('Installed drift: ' + name)
    activation = json.loads((STAGE / 'activation.json').read_text()) if (STAGE / 'activation.json').exists() else None
    if cleanup:
        if not activation or activation.get('activated') is not True:
            raise RuntimeError('Activation is not accepted')
        helper = Path(receipt['helper'])
        if sha(read(helper)) != receipt['helper_sha']:
            raise RuntimeError('Activation helper drift')
        helper.unlink()
    result = {'status': 'verified', 'source_destinations': 11, 'compiled_views': 2, 'activation': activation,
              'cron_unchanged': sha(subprocess.run(['crontab', '-l'], capture_output=True, check=True).stdout) == receipt['cron_before'],
              'helper_removed': not Path(receipt['helper']).exists(), 'utc': datetime.now(timezone.utc).isoformat()}
    record('verification.json', result)
    print(json.dumps(result))


def main():
    if os.getuid() == 0 or Path.home() != HOME_DIR:
        raise RuntimeError('Run only as the authorized cPanel account')
    mode = sys.argv[1]
    if mode not in ['install', 'verify', 'cleanup']:
        raise RuntimeError('Unexpected mode')
    with contextlib.ExitStack() as stack:
        for name in ['.servernet-web-release.lock', 'servernet-release.lock']:
            fd = os.open(HOME_DIR / name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            handle = stack.enter_context(os.fdopen(fd, 'a'))
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        install(Path(sys.argv[2]), sys.argv[3]) if mode == 'install' else verify(mode == 'cleanup')


if __name__ == '__main__':
    main()
