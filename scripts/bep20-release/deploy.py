#!/usr/bin/env python3
"""Pinned, hash-guarded BEP20 release. Does not change wallets, cron, schema, or balances."""
import argparse, contextlib, fcntl, hashlib, json, os, re, stat, subprocess, urllib.request
from pathlib import Path

PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
BASE = 'c9a4fe6ed7b8024bac35a5b694a244097c0545bf'
FILES = [
    'app/Services/Payment/BscWatcher.php',
    'app/Services/Payment/CryptoIssuer.php',
    'app/Services/Payment/CryptoReconciler.php',
    'app/Http/Controllers/Admin/CryptoWalletController.php',
    'app/Console/Commands/CryptoAudit.php',
    'resources/views/account/partials/crypto-pane.blade.php',
    'resources/views/account/invoice.blade.php',
    'resources/views/admin/settings/accounts.blade.php',
]
VIEWS = [p for p in FILES if p.endswith('.blade.php')]
def sha(data): return hashlib.sha256(data).hexdigest()
def download(commit,path):
    bundle=Path(__file__).parent/'bundle'
    if bundle.is_dir():
        manifest=json.loads((bundle/'manifest.json').read_text())
        key=commit+'/'+path
        if key not in manifest:raise RuntimeError('missing bundle item')
        data=(bundle/key).read_bytes()
        if sha(data)!=manifest[key]:raise RuntimeError('bundle integrity failure')
        return data
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'+commit+'/website/'+path,timeout=30) as response:
        return response.read()
def read(path):
    if path.is_symlink(): raise RuntimeError('symlink target: '+path.name)
    return path.read_bytes() if path.exists() else None
def write(path,data,mode=0o644):
    path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_name('.'+path.name+'.bep20-stage')
    fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode)
    with os.fdopen(fd,'wb') as out:
        out.write(data);out.flush();os.fsync(out.fileno());os.fchmod(out.fileno(),mode)
    os.replace(tmp,path)
def php(code,*args):
    p=subprocess.run([PHP,'-r',code,*map(str,args)],capture_output=True,text=True,timeout=90)
    if p.returncode: raise RuntimeError('PHP step failed: '+p.stderr[-1200:]+p.stdout[-1200:])
    return p.stdout

def reconcile_view(path,before,baseline,source):
    changes={
        'resources/views/account/invoice.blade.php': [
            ("implode(' · ', array_keys($cryptoAssets))", "implode(' · ', array_column($cryptoAssets, 'label'))"),
            ('{{ $cyCodes }} · TRC20','{{ $cyCodes }}'),
        ],
        'resources/views/admin/settings/accounts.blade.php': [
            ('<option value="tron">Tron (TRC20)</option></select>', '<option value="tron">Tron (TRC20)</option><option value="bsc">BNB Smart Chain — USDT (BEP20)</option></select>'),
            ('شروع می‌شود و ۳۴ نویسه است؛ موارد نامعتبر یا','شروع می‌شود و ۳۴ نویسه است؛ آدرس BNB Smart Chain با <code>0x</code> شروع می‌شود و ۴۲ نویسه است. موارد نامعتبر یا'),
        ],
        'resources/views/account/partials/crypto-pane.blade.php': [
            ("$cyReady->has('USDT') ? 'USDT' : $cyReady->keys()->first()", "$cyReady->keys()->first()"),
            ('value="{{ $cy->asset }}"','value="{{ \\App\\Services\\Payment\\CryptoIssuer::selectionFor($cy) }}"'),
            ('data-net="{{ $spec[\'network\'] }}"','data-asset="{{ $spec[\'asset\'] }}" data-net="{{ $spec[\'network\'] }}"'),
            ("{{ $code }} — {{ $spec['network'] }}","{{ $spec['label'] }}"),
            ("['code' => $code, 'size' => 30]","['code' => $spec['asset'], 'size' => 30]"),
            ('<b dir="ltr">{{ $code }}</b>','<b dir="ltr">{{ $spec[\'asset\'] }}</b>'),
            ('{{ $cyDefault }}</b>',"{{ $cryptoAssets[$cyDefault]['asset'] }}</b>"),
            ("+ ' ' + code;", "+ ' ' + sel.dataset.asset;"),
        ],
    }
    def apply(data):
        text=data.decode().replace('\r\n','\n')
        for old,new in changes[path]:
            if text.count(old)!=1:raise RuntimeError('nonunique reconciliation anchor: '+path)
            text=text.replace(old,new,1)
        return text.encode()
    if path not in changes or apply(baseline)!=source.replace(b'\r\n',b'\n'):
        raise RuntimeError('reconciliation does not reproduce reviewed source: '+path)
    return apply(before)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['prepare','install','verify','rollback','cleanup']);parser.add_argument('commit')
    args=parser.parse_args()
    if not re.fullmatch('[0-9a-f]{40}',args.commit):raise RuntimeError('invalid commit')
    home=Path.home();live=home/'servernet_app';stage=home/('bep20-release-'+args.commit[:12])
    with contextlib.ExitStack() as lock_stack:
        for name in ['.servernet-web-release.lock','servernet-release.lock']:
            fd=os.open(home/name,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
            handle=lock_stack.enter_context(os.fdopen(fd,'a'));fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        boot="require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
        manifest_path=stage/'manifest.json'
        if args.mode=='prepare':
            if stage.exists():raise RuntimeError('stage exists; inspect before retry')
            stage.mkdir(mode=0o700)
            manifest={'commit':args.commit,'base':BASE,'files':{}}
            for path in FILES:
                dest=live/path;before=read(dest);data=download(args.commit,path)
                baseline=None if path.endswith('/BscWatcher.php') else download(BASE,path)
                if before != baseline and (before is None or baseline is None or before.replace(b'\r\n',b'\n') != baseline.replace(b'\r\n',b'\n')):
                    if path not in VIEWS or before is None:raise RuntimeError('live drift: '+path)
                    data=reconcile_view(path,before,baseline,data)
                if before is not None and b'\r\n' in before:data=data.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')
                write(stage/'staged'/path,data)
                if before is not None:write(stage/'before'/path,before,0o600)
                manifest['files'][path]={'before':sha(before) if before is not None else None,'after':sha(data)}
                if path not in VIEWS:
                    subprocess.run([PHP,'-l',str(stage/'staged'/path)],check=True,stdout=subprocess.DEVNULL)
            code=boot+"$compiler=$app->make('blade.compiler');foreach(array_slice($argv,3) as $i=>$file){file_put_contents($argv[2].'/compiled-'.$i.'.php',$compiler->compileString(file_get_contents($file)));}"
            php(code,live,stage,*[stage/'staged'/p for p in VIEWS])
            for compiled in stage.glob('compiled-*.php'):
                subprocess.run([PHP,'-l',str(compiled)],check=True,stdout=subprocess.DEVNULL)
            # Backup existing compiled templates as well; no global cache clear.
            compiled_paths=json.loads(php(boot+"echo json_encode(array_map(fn($p)=>$app->make('blade.compiler')->getCompiledPath($p),array_slice($argv,2)));",live,*[live/p for p in VIEWS]))
            for path in compiled_paths:
                dest=Path(path);relative=str(dest.relative_to(live));before=read(dest)
                if before is not None:write(stage/'before'/relative,before,0o600)
                manifest['files'][relative]={'before':sha(before) if before is not None else None,'after':None,'compiled':True}
            write(manifest_path,json.dumps(manifest,indent=2).encode(),0o600)
            print(json.dumps({'status':'prepared','files':len(FILES),'live_writes':0,'lint':'passed'}));return
        manifest=json.loads(manifest_path.read_text())
        if manifest['commit']!=args.commit:raise RuntimeError('commit mismatch')
        if args.mode=='verify':
            for path,item in manifest['files'].items():
                if sha(read(live/path) or b'') != item['after']:raise RuntimeError('installed drift: '+path)
            print(json.dumps({'status':'hashes_verified','files':len(manifest['files'])}));return
        if args.mode=='cleanup':
            activation=json.loads((stage/'activation.json').read_text())
            if activation.get('activated') is not True:raise RuntimeError('activation not accepted')
            helper=home/'public_html'/manifest['helper']
            if helper.exists():
                if sha(read(helper)) != manifest['helper_sha']:raise RuntimeError('helper drift')
                helper.unlink()
            print(json.dumps({'status':'activation_helper_removed'}));return
        if args.mode=='rollback':
            for path,item in manifest['files'].items():
                current=read(live/path)
                if current is not None and sha(current)!=item['after']:raise RuntimeError('later edit; stop rollback: '+path)
            for path,item in reversed(list(manifest['files'].items())):
                before=read(stage/'before'/path)
                if before is None:
                    if (live/path).exists():(live/path).unlink()
                else:write(live/path,before)
            print(json.dumps({'status':'rolled_back','activation_required':True}));return
        for path,item in manifest['files'].items():
            before=read(live/path)
            if (sha(before) if before is not None else None)!=item['before']:raise RuntimeError('live drift: '+path)
            if not item.get('compiled') and sha(read(stage/'staged'/path))!=item['after']:raise RuntimeError('stage drift')
        targets=[str(live/p) for p in manifest['files']]
        helper_name='servernet-bep20-activate-'+args.commit[:12]+'.php'
        template=Path(__file__).with_name('activation.php.template').read_text()
        replacements={'__AUTOLOAD__':str(live/'vendor/autoload.php'),'__BOOTSTRAP__':str(live/'bootstrap/app.php'),
            '__HELPER__':helper_name,'__TARGETS__':json.dumps(targets),'__RECEIPT__':str(stage/'activation.json')}
        for key,value in replacements.items():template=template.replace(key,value.replace("'","\\'"))
        helper=home/'public_html'/helper_name
        if helper.exists():raise RuntimeError('helper already exists')
        staged_helper=stage/'activation.php'
        write(staged_helper,template.encode(),0o600)
        subprocess.run([PHP,'-l',str(staged_helper)],check=True,stdout=subprocess.DEVNULL)
        installed=[]
        try:
            for path in FILES:
                item=manifest['files'][path];current=read(live/path)
                if (sha(current) if current is not None else None)!=item['before']:raise RuntimeError('concurrent edit: '+path)
                write(live/path,read(stage/'staged'/path));installed.append(path)
            php(boot+"foreach(array_slice($argv,2) as $path){$app->make('blade.compiler')->compile($path);}",live,*[live/p for p in VIEWS])
            for path,item in manifest['files'].items():
                if item.get('compiled'):item['after']=sha(read(live/path))
            write(helper,template.encode())
        except BaseException:
            for path in reversed(installed):
                if sha(read(live/path))!=manifest['files'][path]['after']:raise RuntimeError('concurrent edit during rollback: '+path)
                before=read(stage/'before'/path)
                if before is None:(live/path).unlink()
                else:write(live/path,before)
            # Recompile restored sources without touching unrelated caches.
            php(boot+"foreach(array_slice($argv,2) as $path){$app->make('blade.compiler')->compile($path);}",live,*[live/p for p in VIEWS])
            raise
        manifest['helper']=helper_name;manifest['helper_sha']=sha(template.encode())
        write(manifest_path,json.dumps(manifest,indent=2).encode(),0o600)
        print(json.dumps({'status':'installed','files':len(manifest['files']),'activation_path':'/'+helper_name,'wallets_added':0}))

if __name__=='__main__': main()
