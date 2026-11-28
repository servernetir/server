#!/usr/bin/env python3
"""Stage exact locked production patches; atomically exchange and verify vendor."""
import ctypes
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import urllib.request

sys.dont_write_bytecode = True
HOME = Path('/home/servernetcloud')
APP = HOME/'servernet_app'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
RELEASE = 'symfony-patch-20261128'
OLD_LOCK = '353c606e833d8f49588520fdc7f7b79812ec491574ab27b0e01cea6d65213349'
COMPOSER_JSON = 'a36194f33f674e2c91170a7b1fc46ee0cd0a4518adfb0c85121a0b6f1adf8d34'
OLD_INSTALLED = 'f53a4326a98a7051f7bd92d942eb249ad1aededdbd5b9c6ff71baa62438d80c4'
ENGINE_COMMIT = '12e2bc6868524b0eaed40ced57d4a9d47c46c994'
ENGINE_SHA = '1cf55c18261d113819abbf301ac727c70c055bcf9f12b3aba4fe9b3dedca1dea'
COMPOSER_SHA = 'dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028'
ACTIVATION_HELPERS = {'activation_common.py': '9ed099500a7f3c274c1bd76f36d4215bd5f0dcd59724e00ab0c2f93947d41045',
                      'fcgi.py': '57b07c6c014104b2fb5c2e3e232defb8b5283a39df6bdf0ffdbc45f76b3e5b62'}
VERSIONS = {'symfony/http-foundation': ('v8.1.11','v8.1.13'), 'symfony/http-kernel': ('v8.1.11','v8.1.12'),
            'symfony/mailer': ('v8.1.1','v8.1.12'), 'symfony/routing': ('v8.1.11','v8.1.12')}


def sha(content):
    return hashlib.sha256(content).hexdigest()


def get(url):
    with urllib.request.urlopen(url, timeout=25) as response:
        return response.read()


def source(commit, path):
    return get(f'https://raw.githubusercontent.com/servernetir/server/{commit}/{path}')


def exclusive(path, data, mode=0o600):
    with path.open('xb') as handle:
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())
    path.chmod(mode)


def packages(content):
    rows = json.loads(content)['packages']
    assert len({p['name'] for p in rows}) == len(rows), 'Duplicate package identity'
    return {p['name']: (p['version'],p['source']['reference']) for p in rows}


def compare(old, new):
    assert old.keys() == new.keys(), 'Package list changed'
    assert {name for name in old if old[name] != new[name]} == VERSIONS.keys(), 'Outside the four-package scope'
    assert all((old[n][0], new[n][0]) == v for n,v in VERSIONS.items()), 'Version mismatch'


def inventory(root):
    assert root.is_dir() and not root.is_symlink() and root.stat().st_uid == os.getuid()
    result = {}
    for base, dirs, files in os.walk(root):
        for name in sorted(dirs+files):
            p = Path(base)/name
            s = p.lstat()
            assert s.st_uid == os.getuid() and not p.is_symlink(), 'Unexpected vendor owner or symlink'
            assert stat.S_ISDIR(s.st_mode) or stat.S_ISREG(s.st_mode), 'Unexpected vendor file type'
            if stat.S_ISREG(s.st_mode):
                result[p.relative_to(root).as_posix()] = [stat.S_IMODE(s.st_mode), sha(p.read_bytes())]
    return result


def signature(rows):
    return sha(json.dumps(rows, sort_keys=True, separators=(',',':')).encode())


def is_allowed(path):
    return path == 'autoload.php' or path.startswith(('composer/', *(p+'/' for p in VERSIONS)))


def verify_difference(before, after):
    changes = sorted(p for p in before.keys()|after.keys() if before.get(p) != after.get(p))
    assert all(is_allowed(p) for p in changes), 'Unrelated vendor bytes or modes changed'
    return [p for p in changes if p.endswith('.php')]


def exchange(a, b):
    assert a.is_dir() and b.is_dir() and not a.is_symlink() and not b.is_symlink()
    assert a.stat().st_dev == b.stat().st_dev, 'Vendor exchange must use one filesystem'
    lib = ctypes.CDLL(None, use_errno=True)
    op = lib.renameat2
    op.argtypes = [ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
    op.restype = ctypes.c_int
    if op(-100,os.fsencode(a),-100,os.fsencode(b),2) != 0:  # AT_FDCWD, RENAME_EXCHANGE
        err = ctypes.get_errno()
        raise OSError(err, os.strerror(err))


def helper_info(commit, stage):
    common = {name:{'path':'scripts/mcp-auto-approval-20261122/'+('activate.py' if name=='activation_common.py' else name), 'sha256':digest}
              for name,digest in ACTIVATION_HELPERS.items()}
    common['activate.php'] = {'path':f'scripts/{RELEASE}/activate.php', 'sha256':sha(source(commit,f'scripts/{RELEASE}/activate.php'))}
    return {'commit':commit,'helpers':common,'views':[]}


def engine(stage):
    path = stage/'engine.py'
    assert path.is_file() and not path.is_symlink() and sha(path.read_bytes()) == ENGINE_SHA
    spec = importlib.util.spec_from_file_location('reviewed_activation',path)
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    mod.RELEASE = RELEASE
    return mod


def prepare(commit, stage):
    before = (APP/'composer.lock').read_bytes()
    config = (APP/'composer.json').read_bytes()
    installed = (APP/'vendor/composer/installed.json').read_bytes()
    assert sha(before)==OLD_LOCK and sha(config)==COMPOSER_JSON and sha(installed)==OLD_INSTALLED, 'Production dependency preimage changed'
    target = source(commit,'website/composer.lock')
    assert sha(source(commit,'website/composer.json'))==COMPOSER_JSON, 'Composer configuration changed'
    assert json.loads(before)['packages-dev']==json.loads(target)['packages-dev'], 'Development dependencies changed'
    compare(packages(before),packages(target))
    assert packages(installed)==packages(before) and json.loads(installed)['dev'] is False, 'Live vendor does not match production lock'
    original = inventory(APP/'vendor')
    exclusive(stage/'lock.before', before)
    exclusive(stage/'lock.after', target)
    bin_data = get('https://getcomposer.org/download/2.11.2/composer.phar')
    assert sha(bin_data)==COMPOSER_SHA, 'Composer binary checksum mismatch'
    exclusive(stage/'composer.phar', bin_data)
    engine_data = source(ENGINE_COMMIT,'scripts/search-growth-20261124/release.py')
    assert sha(engine_data)==ENGINE_SHA, 'Reviewed release engine checksum mismatch'
    exclusive(stage/'engine.py', engine_data)
    deps = stage/'deps'
    deps.mkdir(mode=0o700)
    exclusive(deps/'composer.json',config)
    exclusive(deps/'composer.lock',target)
    for d in ('app','database'):
        (deps/d).symlink_to(APP/d,target_is_directory=True)
    shutil.copytree(APP/'vendor',deps/'vendor',symlinks=True)
    assert inventory(deps/'vendor')==original, 'Vendor changed during snapshot'
    env = dict(os.environ, COMPOSER_HOME=str(stage/'composer-home'), COMPOSER_CACHE_DIR=str(stage/'composer-cache'), COMPOSER_NO_INTERACTION='1')
    with (stage/'composer.log').open('xb') as log:
        for action in [('install','--no-dev','--prefer-dist','--no-scripts','--no-plugins','--no-interaction','--no-progress'),
                       ('check-platform-reqs','--no-dev'),('audit','--locked','--no-dev','--format=summary')]:
            subprocess.run([PHP,str(stage/'composer.phar'),*action],cwd=deps,env=env,stdout=log,stderr=subprocess.STDOUT,check=True,timeout=60)
    assert (deps/'composer.lock').read_bytes()==target
    staged=inventory(deps/'vendor')
    manifest={'sha':commit,'lock_before':sha(before),'lock_after':sha(target),'vendor_before':signature(original),'vendor_after':signature(staged),
              'invalidate':verify_difference(original,staged),'old_versions':{n:v[0] for n,v in VERSIONS.items()},'new_versions':{n:v[1] for n,v in VERSIONS.items()},
              'activation':helper_info(commit,stage)}
    added = (deps/'vendor/composer/installed.json').read_bytes()
    assert packages(added)==packages(target) and json.loads(added)['dev'] is False
    exclusive(stage/'prepared.json',json.dumps(manifest,sort_keys=True).encode())
    print(json.dumps({'status':'prepared','packages':len(packages(added)),'changed_php':len(manifest['invalidate']),'lock':manifest['lock_after']}))


def preflight(stage, plan, phase):
    reverse='after' if phase=='before' else 'before'
    assert sha((APP/'composer.json').read_bytes())==COMPOSER_JSON
    assert sha((APP/'composer.lock').read_bytes())==plan['lock_'+phase], 'Live lock changed'
    assert signature(inventory(APP/'vendor'))==plan['vendor_'+phase], 'Live vendor changed'
    assert signature(inventory(stage/'deps/vendor'))==plan['vendor_'+reverse], 'Counterpart vendor changed'
    assert all(sha((stage/('lock.'+p)).read_bytes())==plan['lock_'+p] for p in ('before','after')), 'Private lock backups changed'


def run(stage, commit, mode):
    if mode=='prepare':
        return prepare(commit,stage)
    plan=json.loads((stage/'prepared.json').read_bytes())
    assert plan['sha']==commit and sha(source(commit,'website/composer.lock'))==plan['lock_after']
    mod=engine(stage)
    if mode=='verify':
        preflight(stage,plan,'after')
        print(json.dumps({'status':'verified','pools':mod.activate(stage,plan['activation'],'after')}))
        return
    phase, target = ('before','after') if mode=='apply' else ('after','before')
    receipt=stage/('installed.json' if mode=='apply' else 'rolled-back.json')
    assert not receipt.exists()
    preflight(stage,plan,phase)
    # Confirm both existing application pools and the exact current runtime first.
    mod.activate(stage,plan['activation'],phase)
    preflight(stage,plan,phase)
    mode_lock=stat.S_IMODE((APP/'composer.lock').stat().st_mode)
    exchange(APP/'vendor',stage/'deps/vendor')
    lock_replaced=False
    try:
        assert sha((APP/'composer.lock').read_bytes())==plan['lock_'+phase]
        mod.write(APP/'composer.lock',(stage/('lock.'+target)).read_bytes(),mode_lock)
        lock_replaced=True
        preflight(stage,plan,target)
        results=mod.activate(stage,plan['activation'],target)
        exclusive(receipt,json.dumps({'sha':commit,'pools':results}).encode())
        print(json.dumps({'status': 'installed' if mode=='apply' else 'rolled_back','pools':results}))
    except BaseException:
        assert signature(inventory(APP/'vendor'))==plan['vendor_'+target], 'New vendor changed; inspect before rollback'
        assert signature(inventory(stage/'deps/vendor'))==plan['vendor_'+phase], 'Backup vendor changed; inspect before rollback'
        assert sha((APP/'composer.lock').read_bytes())==plan['lock_'+(target if lock_replaced else phase)], 'Lock changed during release'
        exchange(APP/'vendor',stage/'deps/vendor')
        if lock_replaced:
            mod.write(APP/'composer.lock',(stage/('lock.'+phase)).read_bytes(),mode_lock)
        preflight(stage,plan,phase)
        mod.activate(stage,plan['activation'],phase)
        raise


if __name__=='__main__':
    mode,commit=sys.argv[1:]
    assert mode in ('prepare','apply','verify','rollback') and re.fullmatch('[0-9a-f]{40}',commit)
    assert os.getuid()!=0 and Path.home()==HOME
    os.umask(0o077)
    stage=HOME/'deploy-backups'/(RELEASE+'-'+commit[:12])
    assert stage.is_dir() and stage.resolve()==stage and stage.stat().st_uid==os.getuid() and stat.S_IMODE(stage.stat().st_mode)==0o700
    with (APP/'storage/framework/servernet-release.lock').open('a') as guard:
        fcntl.flock(guard,fcntl.LOCK_EX|fcntl.LOCK_NB)
        assert not (APP/'bootstrap/cache/config.php').exists() and not list((APP/'bootstrap/cache').glob('routes*.php'))
        run(stage,commit,mode)
