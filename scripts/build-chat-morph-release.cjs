const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const root = path.resolve(__dirname,'..');
const out = path.join(root,'output/chat-morph-20261018');
const hash = b => crypto.createHash('sha256').update(b).digest('hex');
// Immutable pre-release Git snapshot; rebuilding does not read live state or deploy.
const baseline = 'a688add4280429dcb33cf01a31e991fadc6ca083';
const originalView = cp.execFileSync('git',['show',baseline+':website/resources/views/partials/chat.blade.php'],{cwd:root});
const liveJs = cp.execFileSync('git',['show',baseline+':website/public/assets/js/site.js'],{cwd:root});
fs.mkdirSync(out,{recursive:true});
if (hash(originalView) !== 'e3fe3418ed96bcb3d5e02d7745d7c755160e4f3e6edf80f3a709e02df1b2a112') throw Error('Unexpected source view');
if (hash(liveJs) !== '92c266c7648bf164d5f6bf378ec8a7dc10ab8a5929a1521663962f6785e5b006') throw Error('Unexpected live JS');
let js = liveJs.toString();
const jsPatches = [
  ['if (fab && panel && chatBody && chatForm) {','if (fab && panel && chatBody && chatForm && !panel.dataset.morphReady) {'],
  ['    function open() {','    function open() {\n      panel.removeAttribute(\'inert\');'],
  ['    function close() {','    function close() {\n      panel.setAttribute(\'inert\', \'\');'],
];
for (const [before,after] of jsPatches) {
  if (js.split(before).length !== 2) throw Error('Ambiguous JS patch');
  js = js.replace(before,after);
}
const files=[];
function add(local,target,before=null,bytes=null) {
  const data = bytes || fs.readFileSync(path.join(root,'website',local));
  files.push({target,before,after:hash(data),data:data.toString('base64')});
}
add('public/assets/css/chat-morph.css','public_html/assets/css/chat-morph.css');
add('public/assets/js/chat-morph.js','public_html/assets/js/chat-morph.js');
for (const locale of ['fa','en','tr']) add(`lang/${locale}/chat.php`,`servernet_app/lang/${locale}/chat.php`);
files.push({target:'public_html/assets/js/site.js',before:hash(liveJs),after:hash(Buffer.from(js)),patches:jsPatches});
add('resources/views/partials/chat.blade.php','servernet_app/resources/views/partials/chat.blade.php',hash(originalView));
const payload=JSON.stringify(files);
const script=`import base64,hashlib,json,pathlib,subprocess,sys,os
HOME=pathlib.Path('/home/servernetcloud')
BASE=HOME/'chat-morph-20261018-v1'
PHP='/opt/cpanel/ea-php84/root/usr/bin/php'
FILES=json.loads(base64.b64decode('${Buffer.from(payload).toString('base64')}'))
def sha(data): return hashlib.sha256(data).hexdigest()
def current(p): return sha(p.read_bytes()) if p.exists() else None
def check(which):
 for f in FILES:
  p=HOME/f['target']
  if current(p)!=f[which]: raise RuntimeError('Concurrent change: '+f['target'])
def write(p,data):
 t=p.with_name(p.name+'.chat-morph-tmp')
 if t.exists(): raise RuntimeError('Temporary file exists: '+str(t))
 t.write_bytes(data); os.chmod(t,p.stat().st_mode & 0o777 if p.exists() else 0o644); os.replace(t,p)
mode=sys.argv[1]
if mode=='stage':
 check('before'); BASE.mkdir(mode=0o700)
 (BASE/'before').mkdir();(BASE/'after').mkdir()
 for i,f in enumerate(FILES):
  p=HOME/f['target']
  if 'patches' in f:
   text=p.read_text()
   for before,after in f['patches']:
    if text.count(before)!=1:raise RuntimeError('Ambiguous patch')
    text=text.replace(before,after)
   data=text.encode()
  else:data=base64.b64decode(f['data'])
  if sha(data)!=f['after']: raise RuntimeError('Payload mismatch')
  if p.exists(): (BASE/'before'/str(i)).write_bytes(p.read_bytes())
  (BASE/'after'/str(i)).write_bytes(data)
  if p.suffix=='.php' and not p.name.endswith('.blade.php'):
   subprocess.run([PHP,'-l',str(BASE/'after'/str(i))],check=True,capture_output=True)
 (BASE/'manifest.json').write_text(json.dumps([{k:v for k,v in f.items() if k!='data'} for f in FILES],indent=2))
 view=str(BASE/'after'/str(len(FILES)-1)); compiled=str(BASE/'compiled.php')
 phpcode="require '/home/servernetcloud/servernet_app/vendor/autoload.php'; $b=new Illuminate\\\\View\\\\Compilers\\\\BladeCompiler(new Illuminate\\\\Filesystem\\\\Filesystem,'"+str(BASE)+"');file_put_contents('"+compiled+"',$b->compileString(file_get_contents('"+view+"')));"
 phpcode=phpcode.replace('\\\\\\\\','\\\\')
 subprocess.run([PHP,'-r',phpcode],check=True,capture_output=True)
 subprocess.run([PHP,'-l',compiled],check=True,capture_output=True)
 print('STAGED: 7 files, PHP/Blade syntax valid; no production file changed')
elif mode=='apply':
 if not (BASE/'manifest.json').exists() or (BASE/'applied.json').exists(): raise RuntimeError('Stage missing or already applied')
 check('before');done=[]
 try:
  for i,f in enumerate(FILES):
   data=(BASE/'after'/str(i)).read_bytes()
   if sha(data)!=f['after']: raise RuntimeError('Stage changed')
   write(HOME/f['target'],data);done.append(i)
  check('after')
  subprocess.run([PHP,'artisan','view:clear'],cwd=str(HOME/'servernet_app'),check=True,capture_output=True)
  (BASE/'applied.json').write_text(json.dumps({'files':len(FILES)}))
  print('APPLIED: 7 files verified; view cache cleared')
 except Exception:
  for i in reversed(done):
   f=FILES[i];p=HOME/f['target']
   if current(p)!=f['after']: raise RuntimeError('Rollback stopped for concurrent edit')
   if f['before'] is None:p.unlink()
   else:write(p,(BASE/'before'/str(i)).read_bytes())
  raise
elif mode=='rollback':
 if not (BASE/'applied.json').exists() or (BASE/'rolled-back.json').exists():raise RuntimeError('Not applied or already rolled back')
 check('after')
 for i in reversed(range(len(FILES))):
  f=FILES[i];p=HOME/f['target']
  if f['before'] is None:p.unlink()
  else:write(p,(BASE/'before'/str(i)).read_bytes())
 subprocess.run([PHP,'artisan','view:clear'],cwd=str(HOME/'servernet_app'),check=True,capture_output=True)
 (BASE/'rolled-back.json').write_text('{}');print('ROLLED BACK: original seven paths restored')
elif mode=='verify':
 check('after');print('VERIFIED: all seven production hashes match')
else:raise RuntimeError('Unknown mode')
`;
fs.writeFileSync(path.join(out,'deploy.py'),script);
fs.writeFileSync(path.join(out,'manifest.json'),JSON.stringify(files.map(({data,...f})=>f),null,2));
const encoded=zlib.deflateSync(Buffer.from(script)).toString('base64');
const command=`python3 -c 'import base64,zlib,pathlib; p=pathlib.Path("/home/servernetcloud/deploy-chat-morph-20261018-v1.py"); p.open("xb").write(zlib.decompress(base64.b64decode("${encoded}")))'\npython3 /home/servernetcloud/deploy-chat-morph-20261018-v1.py stage`;
fs.writeFileSync(path.join(out,'stage-command.txt'),command);
console.log('Built scoped release: '+files.length+' files; transfer '+command.length+' characters');
