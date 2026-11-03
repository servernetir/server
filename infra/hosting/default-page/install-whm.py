#!/usr/bin/env python3
"""Replace only the exact, inspected ServerNet welcome page; keep private receipts."""
import argparse, fcntl, hashlib, json, os, pathlib, pwd, shutil, ssl, tempfile, urllib.request

OLD = 'c41f577bb6e5a058d05ffa6cc0429897ae6f4869798c0d6aedf912ed3922a09e'
NEW = '3a4bcdbe665d5471b3230ad419d5af22e5772ba4e63e462f09448f9337167bbb'
SOURCE = 'https://servernet.cloud/assets/hosting-default/index.html'
STAGE = pathlib.Path('/root/servernet-hosting-defaults-20261103')

def sha(data): return hashlib.sha256(data).hexdigest()
def write_json(path, data):
    with path.open('x', encoding='utf-8') as f: json.dump(data, f, indent=2)
    path.chmod(0o600)

def main():
    p = argparse.ArgumentParser(); p.add_argument('--apply', action='store_true'); p.add_argument('--create-skeleton', action='store_true'); a = p.parse_args()
    os.umask(0o077); STAGE.mkdir(mode=0o700, exist_ok=True)
    with (STAGE / 'release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with urllib.request.urlopen(SOURCE, context=ssl.create_default_context(), timeout=35) as r: content=r.read(65536)
        if sha(content) != NEW: raise RuntimeError('Source changed')
        skeleton = pathlib.Path('/root/cpanel3-skel/public_html/index.html')
        candidates = [skeleton]
        for user in pathlib.Path('/var/cpanel/users').iterdir():
            try: candidates.append(pathlib.Path(pwd.getpwnam(user.name).pw_dir) / 'public_html/index.html')
            except KeyError: pass
        candidates += list(pathlib.Path('/home').glob('*/cpanel3-skel/public_html/index.html'))
        paths=[]
        for path in dict.fromkeys(candidates):
            if path.is_symlink() or not path.is_file() or path.stat().st_size > 65536: continue
            if sha(path.read_bytes()) == OLD: paths.append(path)
        if skeleton not in paths:
            if a.create_skeleton and not skeleton.exists() and not skeleton.is_symlink() and skeleton.parent.is_dir() and not skeleton.parent.is_symlink(): paths.insert(0,skeleton)
            else: raise RuntimeError('Native skeleton changed; inspect before release')
        print(json.dumps({'preview':not a.apply,'native_skeleton':True,'exact_default_pages':len(paths)-1,'after':NEW}))
        if not a.apply: return
        if (STAGE/'installed.json').exists(): raise RuntimeError('Already installed; read receipt')
        backups=STAGE/'backup';backups.mkdir(mode=0o700, exist_ok=False)
        manifest=[]
        for i,path in enumerate(paths):
            if path==skeleton and not path.exists():
                manifest.append({'path':str(path),'before':None,'after':NEW,'backup':None,'mode':0o644,'uid':0,'gid':0});continue
            before=path.read_bytes(); stat=path.stat()
            if sha(before)!=OLD: raise RuntimeError('Concurrent change')
            backup=backups/str(i);backup.write_bytes(before);backup.chmod(0o600)
            manifest.append({'path':str(path),'before':OLD,'after':NEW,'backup':str(backup),'mode':stat.st_mode&0o7777,'uid':stat.st_uid,'gid':stat.st_gid})
        write_json(STAGE/'manifest.json',manifest)
        for row in manifest:
            path=pathlib.Path(row['path'])
            if path.is_symlink() or (sha(path.read_bytes()) if path.exists() else None)!=row['before']: raise RuntimeError('Concurrent change')
            fd,tmp=tempfile.mkstemp(prefix='.servernet-welcome-',dir=path.parent)
            try:
                with os.fdopen(fd,'wb') as f: f.write(content);f.flush();os.fsync(f.fileno())
                os.chmod(tmp,row['mode']);os.chown(tmp,row['uid'],row['gid'])
                os.replace(tmp,path)
            finally:
                if os.path.exists(tmp): os.unlink(tmp)
            if sha(path.read_bytes())!=NEW: raise RuntimeError('Readback failed')
        result={'installed':len(manifest),'native_skeleton':True,'existing_default_pages':len(manifest)-1,'hash_verified':True}
        write_json(STAGE/'installed.json',result);print(json.dumps(result))

if __name__=='__main__': main()
