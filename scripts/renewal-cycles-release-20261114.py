#!/usr/bin/env python3
"""Narrow hosting renewal cycle release. Run as the cPanel account, never as root."""
import argparse, contextlib, datetime, fcntl, hashlib, json, os, pathlib, shutil, subprocess, tarfile, urllib.request

HOME = pathlib.Path.home()
APP = HOME / 'servernet_app'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
RELEASE = HOME / '.renewal-cycles-release-20261114'
BASE = '17180cc9ca5ccc745753b79a0b8e05e80a0fdbcc'
FILES = ['app/Services/Billing/ServiceRenewals.php', 'app/Http/Controllers/Account/ServiceRenewalController.php', 'lang/fa/ui.php', 'lang/en/ui.php', 'lang/tr/ui.php', 'resources/views/account/service-renewal.blade.php', 'resources/views/account/invoice.blade.php', 'resources/views/account/partials/svc-actions.blade.php', 'routes/web.php']

def digest(data):
    return hashlib.sha256(data).hexdigest()

def current(path):
    if path.is_symlink(): raise RuntimeError('Symlink destination refused: '+str(path))
    return digest(path.read_bytes()) if path.is_file() else None

@contextlib.contextmanager
def locks():
    held=[]
    try:
        for name in ['.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock']:
            handle=open(HOME/name,'a'); fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB); held.append(handle)
        yield
    finally:
        for handle in reversed(held): handle.close()

def archive(sha):
    if len(sha)!=40 or any(c not in '0123456789abcdef' for c in sha): raise ValueError('Expected immutable commit SHA')
    path=RELEASE/(sha+'.tar.gz')
    if not path.exists():
        with urllib.request.urlopen('https://codeload.github.com/servernetir/server/tar.gz/'+sha, timeout=120) as source, path.open('wb') as dest:
            shutil.copyfileobj(source,dest)
    return tarfile.open(path,'r:gz')

def source(tar,path):
    member=tar.getmembers()[0].name.rstrip('/')+'/'+path
    try: entry=tar.getmember(member)
    except KeyError: return None
    if not entry.isfile(): raise RuntimeError('Non-file source refused')
    return tar.extractfile(entry).read()

def prepare(head):
    if not APP.is_dir(): raise RuntimeError('Application not found')
    RELEASE.mkdir(mode=0o700,exist_ok=True)
    if (RELEASE/'installed.json').exists(): raise RuntimeError('Release already installed; inspect receipt first')
    with archive(head) as new:
        spec={'base_sha':BASE, 'sources':['website/'+p for p in FILES]}
        with archive(spec['base_sha']) as base:
            rows=[]; overlays=[]
            for index,path in enumerate(spec['sources']):
                if not path.startswith('website/') or '..' in pathlib.PurePosixPath(path).parts: raise ValueError('Invalid path')
                payload=source(new,path); before_source=source(base,path)
                relative=path[len('website/'):]
                destinations=[APP/relative] if not relative.startswith('public/') else [APP/relative,HOME/'public_html'/relative[len('public/'):]]
                for dest in destinations:
                    prior=current(dest)
                    old=dest.read_bytes() if prior is not None else None
                    after=payload
                    if old is not None and old!=payload and old!=before_source:
                        if before_source is None: raise RuntimeError('Unexpected pre-existing new file '+relative)
                        merge_dir=RELEASE/('merge-'+str(len(rows)));merge_dir.mkdir(exist_ok=True)
                        for label,data in [('live',old),('base',before_source),('new',payload)]: (merge_dir/label).write_bytes(data)
                        result=subprocess.run(['git','merge-file','-p',str(merge_dir/'live'),str(merge_dir/'base'),str(merge_dir/'new')],capture_output=True)
                        if result.returncode: raise RuntimeError('Manual reconciliation required: '+relative)
                        after=result.stdout; overlays.append(relative)
                    stage=RELEASE/('stage-'+str(len(rows)));stage.write_bytes(after)
                    if relative.endswith('.php') and not relative.endswith('.blade.php'):
                        subprocess.run([PHP,'-l',str(stage)],check=True,stdout=subprocess.DEVNULL)
                    rows.append({'source':path,'destination':str(dest),'before':prior,'after':digest(after),
                                 'stage':str(stage),'mode':dest.stat().st_mode & 0o777 if old is not None else 0o644})
            # Compile staged strings without changing production compiled views.
            code="require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); foreach(array_slice($argv,1) as $p) {file_put_contents($p.'.compiled.php',app('blade.compiler')->compileString(file_get_contents($p)));}"
            staged_views=[r['stage'] for r in rows if r['source'].endswith('.blade.php')]
            subprocess.run([PHP,'-r',code,'--',*staged_views],cwd=APP,check=True)
            for p in staged_views: subprocess.run([PHP,'-l',p+'.compiled.php'],check=True,stdout=subprocess.DEVNULL)
            manifest={'head':head,'base':spec['base_sha'],'prepared_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'rows':rows,'overlays':overlays}
            (RELEASE/'manifest.json').write_text(json.dumps(manifest,indent=2))
            print(json.dumps({'prepared':True,'destinations':len(rows),'overlays_preserved':overlays,
                  'route_cache':list(str(p) for p in (APP/'bootstrap/cache').glob('routes-*.php'))}))

def install():
    manifest=json.loads((RELEASE/'manifest.json').read_text())
    rows=manifest['rows']
    for row in rows:
        if current(pathlib.Path(row['destination']))!=row['before']: raise RuntimeError('Concurrent edit: '+row['source'])
        if current(pathlib.Path(row['stage']))!=row['after']: raise RuntimeError('Stage altered')
    backup=RELEASE/'backup';backup.mkdir(mode=0o700,exist_ok=True)
    for i,row in enumerate(rows):
        if row['before'] is not None:
            shutil.copy2(row['destination'],backup/str(i))
            if current(backup/str(i))!=row['before']: raise RuntimeError('Backup mismatch')
    def write(row):
        dest=pathlib.Path(row['destination']);dest.parent.mkdir(parents=True,exist_ok=True)
        temp=dest.with_name(dest.name+'.renewal-cycle-stage')
        with temp.open('xb') as out: out.write(pathlib.Path(row['stage']).read_bytes())
        os.chmod(temp,row['mode']);os.replace(temp,dest)
        if current(dest)!=row['after']: raise RuntimeError('Installed hash mismatch')
    # Dependencies first, shared routes last. No public entrypoint or config is copied.
    ordered=sorted(rows,key=lambda r: (r['source']=='website/routes/web.php',r['before'] is not None))
    installed=[]
    try:
        for row in ordered: write(row);installed.append(row)
        if list((APP/'bootstrap/cache').glob('routes-*.php')):
            subprocess.run([PHP,'artisan','route:clear'],cwd=APP,check=True)
        code="require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); foreach(array_slice($argv,1) as $path) { app('blade.compiler')->compile($path); } echo 'TARGETED_VIEWS_COMPILED'.PHP_EOL;"
        views=[r['destination'] for r in rows if r['source'].endswith('.blade.php')]
        subprocess.run([PHP,'-r',code,'--',*views],cwd=APP,check=True)
        for row in rows:
            if current(pathlib.Path(row['destination']))!=row['after']: raise RuntimeError('Post-install hash mismatch')
    except Exception as failure:
        (RELEASE/'failed.json').write_text(json.dumps({'failed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'installed_sources':[r['source'] for r in installed],'error_type':type(failure).__name__,'schema_changed':False},indent=2))
        for row in reversed(installed):
            dest=pathlib.Path(row['destination'])
            if current(dest)!=row['after']: continue
            if row['before'] is None: dest.unlink()
            else: shutil.copy2(backup/str(rows.index(row)),dest)
        # Refresh only this release's compiled templates, including templates
        # whose source was newly created then removed during rollback.
        restore_views=[r['destination'] for r in rows if r['source'].endswith('.blade.php')]
        rollback_code="require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $b=app('blade.compiler'); foreach(array_slice($argv,1) as $p) { if(is_file($p)) { $b->compile($p); } else { $c=$b->getCompiledPath($p); if(is_file($c)) { unlink($c); } } }"
        subprocess.run([PHP,'-r',rollback_code,'--',*restore_views],cwd=APP,check=True)
        raise
    manifest['installed_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    (RELEASE/'installed.json').write_text(json.dumps(manifest,indent=2))
    print(json.dumps({'installed':True,'destinations':len(rows),'head':manifest['head'],'hashes_verified':True,'schema_changed':False}))

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('action',choices=['prepare','apply']);parser.add_argument('--head');args=parser.parse_args()
    with locks():
        if args.action=='prepare': prepare(args.head)
        else: install()
