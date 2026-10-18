"""Publish only the card CSS addition and JS into cPanel's separate document root."""
from pathlib import Path
import hashlib,json,os,shutil,subprocess,sys
APP=Path('/home/servernetcloud/servernet_app')
WEB=Path('/home/servernetcloud/public_html')
HERE=Path(__file__).resolve().parent
SOURCE=Path('/home/servernetcloud/cloud-image-cards-20261018')
MARKER='\n/* Image library: one card per family,'
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
def install(src,target,mode):
    temp=target.with_name(target.name+'.image-card-assets-20261018')
    assert not temp.exists();shutil.copyfile(src,temp);os.chmod(temp,mode);os.replace(temp,target)
def main():
    mode=sys.argv[1]
    if mode=='--prepare':
        assert not (HERE/'manifest.json').exists() and not (HERE/'APPLIED.json').exists()
        css=(SOURCE/'staged/public/assets/css/panel.css').read_text()
        assert css.count(MARKER)==1
        fragment=MARKER+css.split(MARKER,1)[1]
        manifest=[]
        for rel in ['assets/css/panel.css','assets/js/cloud-image-cards.js']:
            target=WEB/rel;before=sha(target)
            if rel.endswith('.css'):
                data=target.read_text();assert MARKER not in data;data+=fragment
            else:
                assert before is None,'Asset already exists'
                data=(APP/'public'/rel).read_text()
                assert sha(APP/'public'/rel)==sha(SOURCE/'staged/public'/rel)
            staged=HERE/'staged'/rel;staged.parent.mkdir(parents=True,exist_ok=True);staged.write_text(data);os.chmod(staged,0o600)
            if before:
                backup=HERE/'backup'/rel;backup.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(target,backup);os.chmod(backup,0o600)
            manifest.append({'path':rel,'before':before,'after':sha(staged),'mode':os.stat(target).st_mode&0o777 if before else 0o644})
        (HERE/'manifest.json').write_text(json.dumps(manifest,indent=2));os.chmod(HERE/'manifest.json',0o600)
        print('PUBLIC_ASSETS_PREPARED',len(manifest));return
    manifest=json.loads((HERE/'manifest.json').read_text())
    if mode=='--rollback':
        assert (HERE/'APPLIED.json').exists() and not (HERE/'ROLLED_BACK.json').exists()
        for item in manifest:assert sha(WEB/item['path'])==item['after'],'Concurrent public edit'
        for item in reversed(manifest):
            target=WEB/item['path']
            if item['before']:install(HERE/'backup'/item['path'],target,item['mode'])
            else:target.unlink()
        (HERE/'ROLLED_BACK.json').write_text('{"rolled_back":true}')
    else:
        assert mode=='--apply' and not (HERE/'APPLIED.json').exists()
        for item in manifest:
            assert sha(WEB/item['path'])==item['before'],'Public file drift'
            assert sha(HERE/'staged'/item['path'])==item['after'],'Packet drift'
        for item in manifest:
            assert sha(WEB/item['path'])==item['before'],'Concurrent public edit'
            install(HERE/'staged'/item['path'],WEB/item['path'],item['mode'])
        for item in manifest:assert sha(WEB/item['path'])==item['after']
        (HERE/'APPLIED.json').write_text(json.dumps({'public_files':2,'hashes_matched':True}));os.chmod(HERE/'APPLIED.json',0o600)
    # asset_ver uses application-public mtimes; refresh the two keys without changing content.
    for item in manifest:os.utime(APP/'public'/item['path'],None)
    reset=subprocess.run([PHP,str(SOURCE/'reset.php')],text=True,capture_output=True)
    if reset.returncode:raise RuntimeError(reset.stdout+reset.stderr)
    print(reset.stdout.strip());print('PUBLIC_ASSETS_'+('ROLLED_BACK' if mode=='--rollback' else 'APPLIED'))
if __name__=='__main__':main()
