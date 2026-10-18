"""Exact source substitutions, independent public assets, private backups and drift guards."""
from pathlib import Path
import hashlib,json,os,shutil,subprocess,sys
HERE=Path(__file__).resolve().parent
APP=Path('/home/servernetcloud/servernet_app')
PUBLIC=Path('/home/servernetcloud/public_html')
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
ALLOWED={'app/Services/Cloud/ArvanClient.php'}
def target(rel):return PUBLIC/rel[8:] if rel.startswith('@public/') else APP/rel
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
def run(args):
 r=subprocess.run(args,cwd=APP,text=True,capture_output=True)
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout.strip()
def install(source,dest,mode):
 tmp=dest.with_name(dest.name+'.ready-apps-20261018');assert not tmp.exists()
 shutil.copyfile(source,tmp);os.chmod(tmp,mode);os.replace(tmp,dest)
def main():
 action=sys.argv[1]
 if action=='--prepare':
  assert not (HERE/'manifest.json').exists() and not (HERE/'APPLIED.json').exists()
  ops=json.loads((HERE/'operations.json').read_text());assert {o['path'] for o in ops}==ALLOWED
  manifest=[]
  for index,rel in enumerate(sorted(ALLOWED)):
   live=target(rel);before=digest(live);data=live.read_text() if live.exists() else ''
   for op in [o for o in ops if o['path']==rel]:
    if 'create' in op:
     assert before is None,'Already exists '+rel
     data=op['create']
    else:
     assert data.count(op['old'])==1,'Source mismatch '+rel+' '+op['old'][:50]
     data=data.replace(op['old'],op['new'])
   stage=HERE/('stage-'+str(index));stage.write_text(data);os.chmod(stage,0o600)
   backup=HERE/('backup-'+str(index))
   if before:shutil.copyfile(live,backup);os.chmod(backup,0o600)
   if rel.endswith('.php'):print(run([PHP,'-l',str(stage)]))
   manifest.append({'path':rel,'before':before,'after':digest(stage),'stage':stage.name,'backup':backup.name,
    'mode':os.stat(live).st_mode&0o777 if before else 0o644})
  print(run([PHP,str(HERE/'verify.php'),str(HERE/manifest[0]['stage'])]))
  (HERE/'manifest.json').write_text(json.dumps(manifest,indent=2));os.chmod(HERE/'manifest.json',0o600)
  print('PREPARED',len(manifest),'live files unchanged');return
 manifest=json.loads((HERE/'manifest.json').read_text())
 if action=='--rollback':
  assert (HERE/'APPLIED.json').exists() and not (HERE/'ROLLED_BACK.json').exists()
  for i in manifest:assert digest(target(i['path']))==i['after'],'Concurrent change '+i['path']
  for i in reversed(manifest):
   dest=target(i['path'])
   if i['before']:install(HERE/i['backup'],dest,i['mode'])
   else:dest.unlink()
  print(run([PHP,str(HERE/'reset.php')]))
  (HERE/'ROLLED_BACK.json').write_text('{"rolled_back":true}');return
 assert action=='--apply' and not (HERE/'APPLIED.json').exists()
 for i in manifest:
  assert digest(target(i['path']))==i['before'],'Live drift '+i['path']
  assert digest(HERE/i['stage'])==i['after'],'Packet drift'
 done=[]
 try:
  # Class first, gates second, provider third, then the UI assets.
  priority=lambda i:0 if i['path'].endswith('ArvanApplicationImages.php') else 1 if '/Models/' in i['path'] or '/Controllers/' in i['path'] else 2 if i['path'].endswith('ArvanClient.php') else 3
  for i in sorted(manifest,key=priority):
   assert digest(target(i['path']))==i['before'],'Concurrent change '+i['path']
   install(HERE/i['stage'],target(i['path']),i['mode']);done.append(i)
  for i in manifest:assert digest(target(i['path']))==i['after']
  (HERE/'APPLIED.json').write_text(json.dumps({'files':len(done)}));os.chmod(HERE/'APPLIED.json',0o600)
 except Exception:
  for i in reversed(done):
   if digest(target(i['path']))==i['after']:
    if i['before']:install(HERE/i['backup'],target(i['path']),i['mode'])
    else:target(i['path']).unlink()
  raise
 print(run([PHP,str(HERE/'reset.php')]))
 print('APPLIED',len(done),'hashes verified')
if __name__=='__main__':main()
