"""Guarded shop-only source/assets and transactional catalog deployment."""
from pathlib import Path
import argparse,hashlib,json,os,shutil,subprocess,tempfile,datetime
HERE=Path(__file__).resolve().parent;APP=Path('/home/servernetcloud/servernet_app');WEB=Path('/home/servernetcloud/public_html');PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
items=json.loads((HERE/'manifest.json').read_text());allowed={i['path'] for i in items};protected=json.loads((HERE/'protected.json').read_text())
for path in allowed:
 assert '..' not in Path(path).parts and not Path(path).is_absolute()
 assert path.startswith(('app/','config/hardware_','routes/','resources/views/','public/assets/hardware/','public/assets/css/hardware-shop','database/catalog/'))
def target(rel):return WEB/rel[7:] if rel.startswith('public/') else APP/rel
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None
def state():return {p:digest(APP/p) for p in protected}
def command(args):
 r=subprocess.run(args,cwd=APP,capture_output=True,text=True)
 if r.returncode:raise RuntimeError('Command failed: '+str(args[:3])+'\n'+r.stdout+'\n'+r.stderr)
 return r.stdout
def atomic(src,dst):
 dst.parent.mkdir(parents=True,exist_ok=True);fd,name=tempfile.mkstemp(prefix='.shop-',dir=dst.parent)
 try:
  with os.fdopen(fd,'wb') as f:f.write(src.read_bytes());f.flush();os.fsync(f.fileno())
  os.chmod(name,0o644);os.replace(name,dst)
 finally:
  if os.path.exists(name):os.unlink(name)
def refresh():
 command([PHP,'artisan','config:clear']);command([PHP,'artisan','route:clear']);command([PHP,'artisan','view:clear'])
 r=json.loads(command([PHP,str(HERE/'refresh.php')]))
 (HERE/'cache-proof.json').write_text(json.dumps(r,indent=2));assert r['status']==200 and r['opcache_reset'] is True
 print('SHOP_CACHE_OK',flush=True)
def data_preflight():
 r=json.loads(command([PHP,str(HERE/'data-preflight.php')]))
 print('SHOP_DATA_PREFLIGHT',json.dumps(r),flush=True)
def restore(installed):
 for i in reversed(installed):
  dst=target(i['path'])
  if digest(dst)!=i['after']:continue
  if i['before'] is None:dst.unlink()
  else:atomic(HERE/'backup'/i['path'],dst)
parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');parser.add_argument('--rollback',action='store_true');parser.add_argument('--refresh',action='store_true');args=parser.parse_args();receipt=HERE/'installed.json'
if args.refresh:
 assert receipt.exists();refresh();raise SystemExit(0)
if args.rollback:
 assert receipt.exists() and all(digest(target(i['path']))==i['after'] for i in items),'Newer changes prevent rollback'
 command([PHP,str(HERE/'catalog.php'),'rollback']);restore(items);refresh();receipt.rename(HERE/'rolled-back.json');print('SHOP_ROLLED_BACK');raise SystemExit(0)
assert not receipt.exists(),'Release has already been installed'
for i in items:
 assert digest(target(i['path']))==i['before'],'Live drift: '+i['path']
 assert digest(HERE/'files'/i['path'])==i['after'],'Packet drift: '+i['path']
command([PHP,str(HERE/'lint.php')]);data_preflight();print('SHOP_SOURCE_PREFLIGHT_OK',len(items),flush=True)
if not args.apply:raise SystemExit(0)
before=state();(HERE/'backup').mkdir(exist_ok=False);os.chmod(HERE/'backup',0o700)
for i in items:
 if i['before'] is not None:
  dest=HERE/'backup'/i['path'];dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(target(i['path']),dest)
installed=[]
try:
 for i in sorted(items,key=lambda x:3 if x['path']=='routes/web.php' else 2 if x['path']=='resources/views/layouts/site.blade.php' else 1 if x['path'].startswith('resources/views/pages/') else 0):
  assert digest(target(i['path']))==i['before'],'Concurrent edit: '+i['path']
  atomic(HERE/'files'/i['path'],target(i['path']));installed.append(i)
 assert all(digest(target(i['path']))==i['after'] for i in items)
 assert state()==before,'Unrelated source changed during install'
 command([PHP,'artisan','config:clear']);command([PHP,'artisan','route:clear'])
 print(command([PHP,str(HERE/'catalog.php'),'apply']),flush=True)
 receipt.write_text(json.dumps({'status':'installed','source_files':len(items),'protected':before,'installed_at':datetime.datetime.now(datetime.timezone.utc).isoformat()},indent=2));os.chmod(receipt,0o600)
except Exception:
 # Keep the installed source when a committed catalog receipt needs recovery; never strand new data.
 if not (HERE/'catalog-receipt.json').exists():restore(installed);refresh()
 raise
refresh();assert state()==before,'Concurrent unrelated source edit after refresh';print('SHOP_DEPLOY_OK',len(items),'PROTECTED',len(before),flush=True)
