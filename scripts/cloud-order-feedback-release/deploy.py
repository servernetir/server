#!/usr/bin/env python3
"""Limited same-account release; --preview never changes application destinations."""
import argparse
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import urllib.request

HOME = Path('/home/servernetcloud')
APP = HOME / 'servernet_app'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
WORK = APP / 'storage/app/private/cloud-order-feedback-20261103'
SHARED = ['app/Http/Controllers/Account/CloudServerController.php',
          'resources/views/account/cloud-server.blade.php',
          'resources/views/account/partials/card-server.blade.php',
          'resources/views/account/partials/status-pill.blade.php']
NEW = ['app/Services/Cloud/CloudOrderIssue.php',
       'resources/views/account/partials/cloud-order-issue.blade.php',
       *['lang/'+locale+'/cloud-order.php' for locale in ['fa','en','tr']],
       'public/assets/css/cloud-order-issue.css']

def digest(data):
    return hashlib.sha256(data).hexdigest()

def overlay(before, after, live):
    """Apply only unique, contextual source hunks; retain all other live bytes."""
    if live == after:
        return live
    old, new = before.decode().splitlines(True), after.decode().splitlines(True)
    result = live.decode()
    for group in difflib.SequenceMatcher(None, old, new, autojunk=False).get_grouped_opcodes(3):
        a = ''.join(old[group[0][1]:group[-1][2]])
        b = ''.join(new[group[0][3]:group[-1][4]])
        if not a or result.count(a) != 1:
            raise RuntimeError('source_context_conflict')
        result = result.replace(a, b, 1)
    return result.encode()

def safe(path):
    if not path.is_relative_to(HOME):
        raise RuntimeError('outside_account')
    cursor = HOME
    for part in path.relative_to(HOME).parts:
        cursor /= part
        if cursor.is_symlink():
            raise RuntimeError('symlink_rejected')
    if path.exists() and path.stat().st_uid != os.getuid():
        raise RuntimeError('wrong_owner')

def atomic(path, data, mode):
    safe(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.cloud-order-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.chmod(name, mode); os.replace(name, path)
    finally:
        if os.path.exists(name): os.unlink(name)

def fetch(sha, relative):
    if not re.fullmatch('[a-f0-9]{40}', sha): raise RuntimeError('invalid_source_sha')
    url = 'https://raw.githubusercontent.com/servernetir/server/'+sha+'/website/'+relative
    with urllib.request.urlopen(url, timeout=30) as response:
        data = response.read(500001)
    if len(data) > 500000: raise RuntimeError('source_too_large')
    return data

def current(path):
    safe(path)
    return digest(path.read_bytes()) if path.is_file() else 'absent'

def preview(base, source):
    if (WORK/'manifest.json').exists(): raise RuntimeError('preview_already_exists')
    entries=[]
    for rel in SHARED+NEW:
        path=APP/rel; safe(path)
        after=fetch(source,rel)
        if rel in SHARED:
            after=overlay(fetch(base,rel),after,path.read_bytes())
        elif path.exists() and path.read_bytes()!=after:
            raise RuntimeError('new_destination_already_exists')
        index=len(entries); prior=current(path)
        if prior!='absent': atomic(WORK/('before-'+str(index)),path.read_bytes(),0o600)
        atomic(WORK/('candidate-'+str(index)),after,0o600)
        entries.append({'path':str(path),'candidate':str(WORK/('candidate-'+str(index))),
                        'backup':str(WORK/('before-'+str(index))),'before':prior,'after':digest(after),
                        'mode':stat.S_IMODE(path.stat().st_mode) if path.exists() else (0o644 if rel.endswith('.css') else 0o600)})
    source_css=next(x for x in entries if x['path'].endswith('/public/assets/css/cloud-order-issue.css'))
    public=HOME/'public_html/assets/css/cloud-order-issue.css'; safe(public)
    if public.exists() and current(public)!=source_css['after']: raise RuntimeError('public_css_exists')
    backup=WORK/'before-public-css'
    if public.exists(): atomic(backup,public.read_bytes(),0o600)
    entries.append({**source_css,'path':str(public),'backup':str(backup),'before':current(public),'mode':0o644})
    plan={'source':source,'base':base,'entries':entries,'compiled':[],'status':'previewed'}
    atomic(WORK/'manifest.json',json.dumps(plan,indent=2).encode(),0o600)
    compile_code = '''<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$p=$argv[1]; $plan=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
$compiler=$app->make('blade.compiler');
foreach($plan['entries'] as $i=>$entry){
 if(!str_ends_with($entry['path'],'.blade.php'))continue;
 $compiler->setPath($entry['path']);
 $bytes=$compiler->compileString(file_get_contents($entry['candidate']));
 $candidate=dirname($p).'/compiled-'.$i.'.php';file_put_contents($candidate,$bytes);chmod($candidate,0600);
 $path=$compiler->getCompiledPath($entry['path']);
 $backup=dirname($p).'/compiled-before-'.$i;
 if(is_file($path)){copy($path,$backup);chmod($backup,0600);}
 $plan['compiled'][]=['path'=>$path,'candidate'=>$candidate,'backup'=>$backup,
 'before'=>is_file($path)?hash_file('sha256',$path):'absent','after'=>hash('sha256',$bytes),
 'mode'=>is_file($path)?fileperms($path)&0777:0600];
}
file_put_contents($p,json_encode($plan,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
'''
    atomic(WORK/'compile.php',compile_code.encode(),0o600)
    subprocess.run([PHP,str(WORK/'compile.php'),str(WORK/'manifest.json')],check=True,capture_output=True,cwd=APP)
    plan=json.loads((WORK/'manifest.json').read_text())
    linted=0
    for e in plan['entries']+plan['compiled']:
        if e['path'].endswith('.php') and not e['path'].endswith('.blade.php'):
            subprocess.run([PHP,'-l',e['candidate']],check=True,capture_output=True);linted+=1
    return {'status':'previewed','files':len(entries),'compiled':len(plan['compiled']),'linted':linted}

def apply_or_verify(phase):
    plan=json.loads((WORK/'manifest.json').read_text()); entries=plan['entries']+plan['compiled']
    allowed_sources={str(APP/p) for p in SHARED+NEW}|{str(HOME/'public_html/assets/css/cloud-order-issue.css')}
    if {e['path'] for e in plan['entries']}!=allowed_sources: raise RuntimeError('manifest_scope_changed')
    if len(plan['compiled'])!=4: raise RuntimeError('compiled_scope_changed')
    for e in entries:
        path=Path(e['path'])
        allowed=(e['path'] in allowed_sources or
                 (path.parent==APP/'storage/framework/views' and re.fullmatch('[a-f0-9]+\\.php',path.name)))
        if not allowed: raise RuntimeError('manifest_path_rejected')
        expected=e['after'] if phase in ['verify','rollback'] else e['before']
        if current(path)!=expected: raise RuntimeError('destination_changed:'+str(path.relative_to(HOME)))
        if digest(Path(e['candidate']).read_bytes())!=e['after']: raise RuntimeError('candidate_changed')
        if e['before']!='absent' and digest(Path(e['backup']).read_bytes())!=e['before']: raise RuntimeError('backup_changed')
    if phase=='verify': return {'status':'verified','files':len(plan['entries']),'compiled':len(plan['compiled'])}
    written=[]
    try:
        # Class/translations/component/assets precede controller and parent views.
        ordered=sorted(entries,key=lambda e: (e['path'] in [str(APP/p) for p in SHARED], e in plan['compiled']))
        if phase=='rollback': ordered=list(reversed(ordered))
        for e in ordered:
            path=Path(e['path']); expected=e['after'] if phase=='rollback' else e['before']
            if current(path)!=expected: raise RuntimeError('concurrent_change')
            data=Path(e['backup']).read_bytes() if phase=='rollback' and e['before']!='absent' else Path(e['candidate']).read_bytes()
            if phase=='rollback' and e['before']=='absent':
                # Only newly introduced, exact-hash files owned by this release.
                path.unlink()
            else: atomic(path,data,e['mode'])
            written.append(e)
        plan['status']='rolled_back' if phase=='rollback' else 'installed'
        atomic(WORK/'manifest.json',json.dumps(plan,indent=2).encode(),0o600)
    except Exception:
        if phase=='install':
            for e in reversed(written):
                path=Path(e['path'])
                if current(path)!=e['after']: raise RuntimeError('rollback_blocked_by_newer_change')
                if e['before']=='absent': path.unlink()
                else: atomic(path,Path(e['backup']).read_bytes(),e['mode'])
        raise
    return {'status':plan['status'],'files':len(plan['entries']),'compiled':len(plan['compiled'])}

def main():
    parser=argparse.ArgumentParser();parser.add_argument('phase',choices=['preview','install','verify','rollback'])
    parser.add_argument('--base');parser.add_argument('--source');args=parser.parse_args()
    if Path.home()!=HOME or os.getuid()==0: raise RuntimeError('wrong_account')
    WORK.mkdir(mode=0o700,parents=True,exist_ok=True);safe(WORK)
    locks=[]
    for name in ['.servernet-web-release.lock','servernet-release.lock','.codex-web-deploy.lock']:
        path=HOME/name;safe(path);fd=os.open(path,os.O_CREAT|os.O_RDWR,0o600)
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);locks.append(fd)
    result=preview(args.base,args.source) if args.phase=='preview' else apply_or_verify(args.phase)
    atomic(WORK/(args.phase+'-result.json'),json.dumps(result).encode(),0o600)
    print(json.dumps(result))

if __name__=='__main__':
    main()
