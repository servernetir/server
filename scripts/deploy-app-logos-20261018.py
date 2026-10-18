"""Guarded logo-only deployment to the application and independent document root."""
from pathlib import Path
import hashlib,json,os,shutil,subprocess,sys,xml.etree.ElementTree as ET
APP=Path('/home/servernetcloud/servernet_app')
PUBLIC=Path('/home/servernetcloud/public_html')
HERE=Path(__file__).resolve().parent
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
FAMILIES=json.loads((HERE/'families.json').read_text())
CODE={'app/Models/CloudImage.php','resources/views/account/cloud-store.blade.php',
      'public/assets/js/cloud-image-cards.js','@public/assets/js/cloud-image-cards.js'}
FILES=CODE|{prefix+'assets/apps/'+name for prefix in ['public/','@public/']
 for name in [f+'.svg' for f in FAMILIES]+['sources.json','LICENSE-dashboard-icons.txt','LICENSE-simple-icons.txt']}
def target(rel):return PUBLIC/rel[8:] if rel.startswith('@public/') else APP/rel
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
def run(args):
 r=subprocess.run(args,cwd=APP,text=True,capture_output=True)
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout.strip()
def install(src,dest,mode):
 dest.parent.mkdir(parents=True,exist_ok=True)
 temp=dest.with_name(dest.name+'.logos-20261018');assert not temp.exists()
 shutil.copyfile(src,temp);os.chmod(temp,mode);os.replace(temp,dest)
def priority(i):return 1 if i['path'] in CODE else 0
def main():
 mode=sys.argv[1]
 if mode=='--prepare':
  assert not (HERE/'manifest.json').exists() and not (HERE/'APPLIED.json').exists()
  ops=json.loads((HERE/'operations.json').read_text());assert {o['path'] for o in ops}==FILES
  items=[]
  for index,rel in enumerate(sorted(FILES)):
   live=target(rel);before=digest(live);data=live.read_text() if before else ''
   for op in [o for o in ops if o['path']==rel]:
    if 'create' in op or 'create_ref' in op:
     assert before is None,'Asset already exists '+rel
     data=op['create'] if 'create' in op else next(o['create'] for o in ops if o['path']==op['create_ref'])
    else:
     assert data.count(op['old'])==1,'Live source mismatch '+rel
     data=data.replace(op['old'],op['new'])
   if rel.endswith('.svg'):
    root=ET.fromstring(data);assert root.tag.endswith('svg')
    for node in root.iter():
     assert node.tag.split('}')[-1].lower() not in ['script','foreignobject']
     for k,v in node.attrib.items():
      assert not k.lower().startswith('on') and 'javascript:' not in v.lower()
      if k.split('}')[-1] in ['href','src']:assert v.startswith(('#','data:image/'))
   staged=HERE/('stage-'+str(index));staged.write_text(data);os.chmod(staged,0o600)
   backup=HERE/('backup-'+str(index))
   if before:shutil.copyfile(live,backup);os.chmod(backup,0o600)
   items.append({'path':rel,'before':before,'after':digest(staged),'stage':staged.name,'backup':backup.name,
    'mode':os.stat(live).st_mode&0o777 if before else 0o644})
   if rel.endswith('.php') and not rel.endswith('.blade.php'):print(run([PHP,'-l',str(staged)]))
  model=next(i for i in items if i['path']=='app/Models/CloudImage.php')
  view=next(i for i in items if i['path']=='resources/views/account/cloud-store.blade.php')
  print(run([PHP,str(HERE/'verify.php'),str(HERE/model['stage']),str(HERE/view['stage'])]))
  print(run([PHP,'-l',str(HERE/'compiled.php')]))
  (HERE/'manifest.json').write_text(json.dumps(items,indent=2));os.chmod(HERE/'manifest.json',0o600)
  print('LOGOS_PREPARED',len(items),'files; live unchanged');return
 items=json.loads((HERE/'manifest.json').read_text())
 if mode=='--rollback':
  assert (HERE/'APPLIED.json').exists() and not (HERE/'ROLLED_BACK.json').exists()
  for i in items:assert digest(target(i['path']))==i['after'],'Concurrent change '+i['path']
  for i in sorted(items,key=priority,reverse=True):
   if i['before']:install(HERE/i['backup'],target(i['path']),i['mode'])
   else:target(i['path']).unlink()
  print(run([PHP,str(HERE/'reset.php')]))
  (HERE/'ROLLED_BACK.json').write_text('{"rolled_back":true}');print('LOGOS_ROLLED_BACK');return
 assert mode=='--apply' and not (HERE/'APPLIED.json').exists()
 for i in items:
  assert digest(target(i['path']))==i['before'],'Live drift '+i['path']
  assert digest(HERE/i['stage'])==i['after'],'Packet drift'
 done=[]
 try:
  for i in sorted(items,key=priority):
   assert digest(target(i['path']))==i['before'],'Concurrent change '+i['path']
   install(HERE/i['stage'],target(i['path']),i['mode']);done.append(i)
  for i in items:assert digest(target(i['path']))==i['after']
  (HERE/'APPLIED.json').write_text(json.dumps({'files':len(done),'families':len(FAMILIES)}));os.chmod(HERE/'APPLIED.json',0o600)
 except Exception:
  for i in reversed(done):
   if digest(target(i['path']))==i['after']:
    if i['before']:install(HERE/i['backup'],target(i['path']),i['mode'])
    else:target(i['path']).unlink()
  raise
 print(run([PHP,str(HERE/'reset.php')]))
 print('LOGOS_APPLIED',len(done),'files; hashes verified')
if __name__=='__main__':main()
