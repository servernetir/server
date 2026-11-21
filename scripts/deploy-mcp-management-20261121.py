#!/usr/bin/env python3
"""Pinned, limited MCP follow-up release. Never changes grants, keys, DB or shared caches."""
import fcntl, hashlib, json, os, re, shutil, subprocess, sys, tempfile, urllib.request
from pathlib import Path
ROOT=Path('/home/servernetcloud/servernet_app')
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
BACKUPS=Path('/home/servernetcloud/deploy-backups')
def sha(b): return hashlib.sha256(b).hexdigest()
def fetch(commit,path):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'+commit+'/'+path,timeout=30) as r: return r.read()
def write(path,data,mode):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.mcp-release-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f: f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(tmp,mode);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
def main():
    mode,commit=sys.argv[1:3]
    assert mode in ['prepare','apply','verify','rollback'] and re.fullmatch('[0-9a-f]{40}',commit)
    stage=BACKUPS/('mcp-management-20261121-'+commit[:12])
    stage.mkdir(parents=True,exist_ok=True,mode=0o700);os.chmod(stage,0o700)
    with (ROOT/'storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        return run_release(mode,commit,stage)

def run_release(mode,commit,stage):
    manifest=json.loads(fetch(commit,'scripts/mcp-management-release-20261121.json'))
    receipt=stage/'receipt.json'
    if mode=='prepare':
        assert not receipt.exists(),'Already applied'
        records=[]
        for row in manifest:
            rel=row['path'];assert rel.startswith('website/') and '..' not in Path(rel).parts
            dest=ROOT/rel.removeprefix('website/');assert not dest.is_symlink()
            source=fetch(commit,rel);assert sha(source)==row['sha256']
            before=dest.read_bytes() if dest.exists() else None
            data=source
            if row.get('patch_proxmox'):
                assert before is not None
                marker=b'    public function testConnection(): array\n'
                start=source.index(b'    /** Read-only transport shared by the owner MCP gateway;')
                block=source[start:source.index(marker)]
                assert b'function guestInventory(' not in before and before.count(marker)==1
                data=before.replace(marker,block+marker)
            else:
                assert (sha(before) if before is not None else None)==row['before_sha256'], 'Unexpected live file: '+rel
            target=stage/'files'/rel;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data)
            subprocess.run([PHP,'-l',str(target)],check=True,stdout=subprocess.DEVNULL)
            records.append({'path':str(dest.relative_to(ROOT)),'before':sha(before) if before is not None else None,
                'after':sha(data),'mode':(dest.stat().st_mode&0o777) if before is not None else 0o644})
        (stage/'prepared.json').write_text(json.dumps(records));print(json.dumps({'status':'prepared','files':len(records),'sha':commit}));return
    records=json.loads((stage/'prepared.json').read_text())
    if mode=='verify':
        for r in records: assert sha((ROOT/r['path']).read_bytes())==r['after'],r['path']
        print(json.dumps({'status':'verified','files':len(records),'sha':commit}));return
    if mode=='rollback':
        assert receipt.exists()
        # Preflight every target and backup before changing any file.
        for r in records:
            p=ROOT/r['path'];assert p.is_file() and not p.is_symlink() and sha(p.read_bytes())==r['after'], 'Newer or missing file, refuse rollback: '+r['path']
            if r['before'] is not None:
                b=stage/'backup'/r['path'];assert b.is_file() and sha(b.read_bytes())==r['before'], 'Invalid rollback backup: '+r['path']
        # Remove registration first, restore existing dependencies next, remove new inert classes last.
        ordered=sorted(records,key=lambda r:(r['path']!='app/Mcp/AdminInfrastructureServer.php',r['before'] is None))
        for r in ordered:
            p=ROOT/r['path']
            if r['before'] is not None: write(p,(stage/'backup'/r['path']).read_bytes(),r['mode'])
            else: p.unlink()
        print(json.dumps({'status':'rolled_back','sha':commit}));return
    assert not receipt.exists(),'Already applied'
    for r in records:
        p=ROOT/r['path'];assert not p.is_symlink()
        current=sha(p.read_bytes()) if p.exists() else None
        assert current==r['before'],'Live file changed: '+r['path']
        if p.exists():
            b=stage/'backup'/r['path'];b.parent.mkdir(parents=True,exist_ok=True,mode=0o700);shutil.copy2(p,b)
    # New inert classes first, tool registration last.
    ordered=sorted(records,key=lambda r:(r['before'] is not None,r['path']=='app/Mcp/AdminInfrastructureServer.php'))
    changed=[]
    try:
        for r in ordered:
            data=(stage/'files'/'website'/r['path']).read_bytes();assert sha(data)==r['after']
            write(ROOT/r['path'],data,r['mode']);changed.append(r)
        receipt.write_text(json.dumps({'sha':commit,'files':records,'grants_changed':False}));os.chmod(receipt,0o600)
    except BaseException:
        for r in reversed(changed):
            p=ROOT/r['path']
            if sha(p.read_bytes())!=r['after']: continue
            if r['before'] is None:p.unlink()
            else:write(p,(stage/'backup'/r['path']).read_bytes(),r['mode'])
        raise
    print(json.dumps({'status':'installed','files':len(records),'sha':commit,'grants_changed':False}))
if __name__=='__main__':main()
