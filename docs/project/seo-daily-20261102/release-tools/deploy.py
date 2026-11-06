#!/usr/bin/env python3
"""Private three-source SEO release. No application bootstrap, SQL, HTTP or cache flush."""
import contextlib
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

HOME=Path('/home/servernetcloud')
STAGE=HOME/'.seo-search-growth-20261102'
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
UID=1020
PAYLOAD_SHA='8cf9016eb3d833d12a8947ab2fbd1caf3dba8107f62e8d726f63c5847330e8c2'
TARGETS=('app/Http/Controllers/CountryVpsController.php','app/Http/Controllers/SiteController.php','resources/views/pages/gpu.blade.php')
LOCKS=('.servernet-web-release.lock','servernet-release.lock','.codex-web-deploy.lock')

class Stop(RuntimeError):pass
def insist(ok,reason):
    if not ok:raise Stop(reason)
def digest(data):return hashlib.sha256(data).hexdigest()
def read(path):
    insist(not path.is_symlink() and path.is_file() and path.resolve()==path,'file_path_invalid')
    return path.read_bytes()
def signature(path):
    data=read(path);s=path.stat()
    return {'sha256':digest(data),'mode':stat.S_IMODE(s.st_mode),'uid':s.st_uid,'bytes':len(data)}
def atomic(path,data,mode):
    insist(not path.is_symlink(),'write_symlink_refused')
    fd,tmp=tempfile.mkstemp(prefix='.seo-20261102-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chmod(tmp,mode);os.replace(tmp,path)
        insist(digest(read(path))==digest(data),'write_hash_mismatch')
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def record(path,data):atomic(path,(json.dumps(data,indent=2,sort_keys=True)+'\n').encode(),0o600)
def now():return datetime.now(timezone.utc).isoformat()
def patch(data,hunks,reverse=False):
    for h in reversed(hunks) if reverse else hunks:
        old,new=(h['new'],h['old']) if reverse else (h['old'],h['new'])
        old,new=old.encode('utf-8'),new.encode('utf-8')
        insist(old and data.count(old)==1,'hunk_not_unique')
        data=data.replace(old,new,1)
    return data
@contextlib.contextmanager
def lock_all(home,uid):
    import fcntl
    handles=[]
    try:
        for name in LOCKS:
            fd=os.open(home/name,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
            handle=os.fdopen(fd,'a+b');handles.append(handle)
            s=os.fstat(handle.fileno())
            insist(stat.S_ISREG(s.st_mode) and s.st_uid==uid and not(stat.S_IMODE(s.st_mode)&0o022),'lock_identity_invalid')
            fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield
    finally:
        for handle in reversed(handles):
            try:fcntl.flock(handle,fcntl.LOCK_UN)
            finally:handle.close()
def lint_candidates(root,stage,rows):
    for i,row in enumerate(rows):
        candidate=stage/('candidate-'+str(i)+'.php')
        subprocess.run([PHP,'-l',str(candidate)],check=True,capture_output=True,timeout=30)
        if row['target'].endswith('.blade.php'):
            compiled=stage/('candidate-'+str(i)+'-compiled.php')
            subprocess.run([PHP,str(stage/'compile-blade.php'),str(root),str(candidate),str(compiled),str(root/row['target'])],check=True,capture_output=True,timeout=30)
            subprocess.run([PHP,'-l',str(compiled)],check=True,capture_output=True,timeout=30)
def restore(root,stage,preview,uid,mode):
    # Guard all destinations before restoring any; preserve unknown later work.
    for i,row in enumerate(preview['files']):
        current=signature(root/row['target'])
        insist(current['sha256'] in (row['before']['sha256'],row['after_sha256'])
            and current['mode']==mode and current['uid']==uid,'newer_source_prevents_rollback')
        insist(digest(read(stage/'backups'/str(i)))==row['before']['sha256'],'backup_drift')
    for i,row in enumerate(preview['files']):
        target=root/row['target']
        backup=read(stage/'backups'/str(i));insist(digest(backup)==row['before']['sha256'],'backup_drift')
        current=signature(target)
        insist(current['sha256'] in (row['before']['sha256'],row['after_sha256'])
            and current['mode']==mode and current['uid']==uid,'newer_source_prevents_rollback')
        if current['sha256']==row['after_sha256']:
            insist(signature(target)==current,'source_changed_during_rollback')
            atomic(target,backup,mode)
        insist(signature(target)==row['before'],'restored_identity_mismatch')
    result={'release':'seo-search-growth-20261102','status':'rolled_back_pending_http_acceptance','at':now(),'files':preview['files']}
    record(stage/'rollback.json',result)
    return result
def run(home,stage,phase,uid=UID,mode=0o644,lint=lint_candidates,locks=lock_all):
    insist(phase in ('preview','apply','rollback'),'phase_invalid')
    insist(stage.resolve()==stage and stage.is_dir() and not stage.is_symlink(),'stage_path_invalid')
    insist(stage.stat().st_uid==uid and (os.name=='nt' or stat.S_IMODE(stage.stat().st_mode)==0o700),'stage_identity_invalid')
    payload_bytes=read(stage/'payload.json');insist(digest(payload_bytes)==PAYLOAD_SHA,'payload_drift')
    payload=json.loads(payload_bytes)
    insist(tuple(r['target'] for r in payload['files'])==TARGETS and payload['release']=='seo-search-growth-20261102','payload_scope_invalid')
    insist(digest(read(stage/'compile-blade.php'))==payload['compile_helper_sha256'],'compile_helper_drift')
    root=home/'servernet_app'
    with locks(home,uid):
        if phase=='preview':
            insist(not any((stage/n).exists() for n in ('preview.json','intent.json','backups')),'preview_already_attempted')
            sources=[]
            for row in payload['files']:
                before=signature(root/row['target'])
                insist(before['sha256']==row['before_sha256'] and before['mode']==mode and before['uid']==uid,'live_source_or_identity_drift')
                data=read(root/row['target']);candidate=patch(data,row['hunks'])
                insist(patch(candidate,row['hunks'],True)==data,'hunk_roundtrip_failed')
                if row.get('git_after_sha256'):
                    insist(digest(candidate)==row['git_after_sha256'],'candidate_differs_from_git')
                sources.append((data,candidate,before))
            (stage/'backups').mkdir(mode=0o700)
            rows=[]
            for i,(row,(before_bytes,candidate,before)) in enumerate(zip(payload['files'],sources)):
                atomic(stage/'backups'/str(i),before_bytes,0o600)
                saved=signature(stage/'backups'/str(i))
                insist(saved['sha256']==before['sha256'] and saved['uid']==uid
                    and (os.name=='nt' or saved['mode']==0o600),'backup_identity_mismatch')
                atomic(stage/('candidate-'+str(i)+'.php'),candidate,0o600)
                rows.append({'target':row['target'],'before':before,'after_sha256':digest(candidate),'after_bytes':len(candidate)})
            lint(root,stage,rows)
            for row in rows:insist(signature(root/row['target'])==row['before'],'source_changed_during_preview')
            receipt={'release':payload['release'],'status':'preview','at':now(),'payload_sha256':PAYLOAD_SHA,'files':rows,'candidate_lint':True,'app_bootstrapped':False}
            record(stage/'preview.json',receipt);return receipt
        preview=json.loads(read(stage/'preview.json'))
        insist(preview.get('payload_sha256')==PAYLOAD_SHA and tuple(r['target'] for r in preview['files'])==TARGETS,'preview_drift')
        for i,row in enumerate(preview['files']):
            insist(digest(read(stage/'backups'/str(i)))==row['before']['sha256'],'backup_drift')
            insist(digest(read(stage/('candidate-'+str(i)+'.php')))==row['after_sha256'],'candidate_drift')
        if phase=='rollback':
            insist((stage/'intent.json').exists() and not(stage/'rollback.json').exists(),'rollback_not_available')
            intent=json.loads(read(stage/'intent.json'))
            insist(intent['preview_sha256']==digest(read(stage/'preview.json')),'intent_drift')
            return restore(root,stage,preview,uid,mode)
        insist(not(stage/'intent.json').exists(),'apply_already_attempted')
        for row in preview['files']:insist(signature(root/row['target'])==row['before'],'state_changed_after_preview')
        record(stage/'intent.json',{'release':payload['release'],'status':'installing','at':now(),'preview_sha256':digest(read(stage/'preview.json')),'files':preview['files']})
        try:
            for i,row in enumerate(preview['files']):
                candidate=read(stage/('candidate-'+str(i)+'.php'))
                insist(digest(candidate)==row['after_sha256'],'candidate_drift')
                insist(signature(root/row['target'])==row['before'],'source_changed_during_install')
                atomic(root/row['target'],candidate,mode)
            for row in preview['files']:
                actual=signature(root/row['target'])
                insist(actual['sha256']==row['after_sha256'] and actual['mode']==mode and actual['uid']==uid,'installed_validation_failed')
            result={'release':payload['release'],'status':'installed_pending_http_acceptance','at':now(),'files':preview['files'],
                'compiled_cache_changed':False,'app_bootstrapped':False,'database_mutation':False,'network_requests':0}
            record(stage/'applied.json',result);return result
        except Exception:
            try:restore(root,stage,preview,uid,mode)
            except Exception:
                record(stage/'failure.json',{'status':'stopped_rollback_refused_preserve_later_changes','at':now()})
                raise
            record(stage/'failure.json',{'status':'failed_install_guarded_rollback_complete','at':now()})
            raise
if __name__=='__main__':
    try:
        insist(os.name=='posix' and os.getuid()==UID and Path.home()==HOME,'unexpected_account')
        insist(len(sys.argv)==2,'phase_required')
        print(json.dumps(run(HOME,STAGE,sys.argv[1]),sort_keys=True))
    except Exception as error:
        print(json.dumps({'release':'seo-search-growth-20261102','status':'stopped','reason':str(error) if isinstance(error,Stop) else 'inspect_private_release_status','exception':type(error).__name__}))
        raise SystemExit(1)
