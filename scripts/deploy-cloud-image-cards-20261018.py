"""Guarded, one-use UI patch packet. Prepare before applying; retain private backups."""
from pathlib import Path
import hashlib,json,os,shutil,subprocess,sys

APP=Path('/home/servernetcloud/servernet_app')
HERE=Path(__file__).resolve().parent
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
ALLOWED={'resources/views/account/cloud-store.blade.php','public/assets/css/panel.css',
         'public/assets/js/cloud-image-cards.js','lang/fa/ui.php','lang/en/ui.php','lang/tr/ui.php'}
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
def run(args):
    r=subprocess.run(args,cwd=APP,text=True,capture_output=True)
    if r.returncode:raise RuntimeError(r.stdout+r.stderr)
    return r.stdout.strip()
def install(source,target,mode):
    temp=target.with_name(target.name+'.image-cards-20261018')
    assert not temp.exists()
    shutil.copyfile(source,temp);os.chmod(temp,mode);os.replace(temp,target)
def main():
    action=sys.argv[1]
    if action=='--prepare':
        assert not (HERE/'manifest.json').exists() and not (HERE/'APPLIED.json').exists()
        ops=json.loads((HERE/'operations.json').read_text())
        assert {o['path'] for o in ops}==ALLOWED
        manifest=[]
        for rel in sorted(ALLOWED):
            target=APP/rel;before=digest(target)
            data=target.read_text() if target.exists() else ''
            for op in [o for o in ops if o['path']==rel]:
                if 'create' in op:
                    assert before is None,'Asset already exists: '+rel
                    data=op['create']
                else:
                    assert data.count(op['old'])==1,'Source mismatch: '+rel+' '+op['old'][:50]
                    data=data.replace(op['old'],op['new'])
            staged=HERE/'staged'/rel;backup=HERE/'backup'/rel
            staged.parent.mkdir(parents=True,exist_ok=True);staged.write_text(data);os.chmod(staged,0o600)
            if before:
                backup.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(target,backup);os.chmod(backup,0o600)
            if rel.endswith('.php') and not rel.endswith('.blade.php'):run([PHP,'-l',str(staged)])
            manifest.append({'path':rel,'before':before,'after':digest(staged),
                'mode':os.stat(target).st_mode&0o777 if before else 0o644})
        compile_php=r'''<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$c=new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem,__DIR__.'/blade-cache');
file_put_contents(__DIR__.'/compiled.php',$c->compileString(file_get_contents(__DIR__.'/staged/resources/views/account/cloud-store.blade.php')));
echo 'BLADE_COMPILED'.PHP_EOL;
'''
        (HERE/'compile.php').write_text(compile_php)
        print(run([PHP,str(HERE/'compile.php')]))
        print(run([PHP,'-l',str(HERE/'compiled.php')]))
        (HERE/'manifest.json').write_text(json.dumps(manifest,indent=2));os.chmod(HERE/'manifest.json',0o600)
        print('PREPARED',len(manifest),'files; live state unchanged')
        for item in manifest:print(item['path'],(item['before'] or 'new')[:12],item['after'][:12])
        return
    manifest=json.loads((HERE/'manifest.json').read_text())
    if action=='--rollback':
        assert (HERE/'APPLIED.json').exists() and not (HERE/'ROLLED_BACK.json').exists()
        for item in manifest:assert digest(APP/item['path'])==item['after'],'Concurrent change: '+item['path']
        for item in reversed(manifest):
            target=APP/item['path']
            if item['before']:install(HERE/'backup'/item['path'],target,item['mode'])
            else:target.unlink()
        print(run([PHP,str(HERE/'reset.php')]))
        (HERE/'ROLLED_BACK.json').write_text('{"rolled_back":true}')
        print('ROLLED_BACK');return
    assert action=='--apply' and not (HERE/'APPLIED.json').exists()
    for item in manifest:
        assert digest(APP/item['path'])==item['before'],'Live drift: '+item['path']
        assert digest(HERE/'staged'/item['path'])==item['after'],'Packet drift: '+item['path']
    done=[]
    try:
        # The new asset is installed before the view that loads it.
        for item in sorted(manifest,key=lambda i:i['path'].startswith('resources/')):
            target=APP/item['path'];assert digest(target)==item['before'],'Concurrent change: '+item['path']
            install(HERE/'staged'/item['path'],target,item['mode']);done.append(item)
        for item in manifest:assert digest(APP/item['path'])==item['after']
        (HERE/'APPLIED.json').write_text(json.dumps({'files':len(done),'state':'installed; browser verification pending'}))
        os.chmod(HERE/'APPLIED.json',0o600)
    except Exception:
        for item in reversed(done):
            target=APP/item['path']
            if digest(target)==item['after']:
                if item['before']:install(HERE/'backup'/item['path'],target,item['mode'])
                else:target.unlink()
        raise
    print(run([PHP,str(HERE/'reset.php')]))
    print('APPLIED',len(done),'files; live hashes matched')
if __name__=='__main__':main()
