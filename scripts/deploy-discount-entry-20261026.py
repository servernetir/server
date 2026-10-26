#!/usr/bin/env python3
"""Owner-approved narrow discount display release; no coupon/payment/provider operation."""
import difflib,fcntl,hashlib,json,os,re,subprocess,sys
from pathlib import Path
src,live,stage=(Path(p).resolve() for p in sys.argv[1:4]);mode=sys.argv[4]
if mode=='control':mode=(live.parent/'discount-entry-phase-20261026').read_text().strip()
if mode not in ['preflight','apply','verify']:raise SystemExit('Invalid mode')
if live!=Path('/home/servernetcloud/servernet_app') or stage.parent!=live.parent/'deploy-backups' or not stage.name.startswith('discount-entry-'):raise SystemExit('Unexpected deployment scope')
php='/opt/cpanel/ea-php84/root/usr/bin/php'
release=json.loads((src/'docs/project/discount-entry-visibility-20261026/manifest.json').read_text())
files=[x['source'].removeprefix('website/') for x in release['files']]
new={'app/Services/Marketing/DiscountEntryVisibility.php','app/Http/Controllers/Admin/DiscountVisibilityController.php','database/migrations/2026_10_26_140000_add_discount_entry_visibility_to_customers.php','resources/views/admin/settings/discount-entry.blade.php','resources/views/admin/partials/customer-discount-entry.blade.php'}
def sha(b):return hashlib.sha256(b).hexdigest()
def once(s,a,b):
 pattern=r'\r?\n'.join(re.escape(part) for part in a.split('\n'));matches=list(re.finditer(pattern,s))
 if len(matches)!=1:raise RuntimeError('Changed/nonunique live anchor')
 m=matches[0];near=s[m.start():].split('\n',1)[0];ending='\r\n' if '\r\n' in m.group(0) or near.endswith('\r') else '\n'
 return s[:m.start()]+b.replace('\n',ending)+s[m.end():]
def php_run(code):
 r=subprocess.run([php,'-r',code],cwd=live,capture_output=True,text=True)
 if r.returncode:raise RuntimeError('PHP release step failed: '+r.stdout[-2000:]+r.stderr[-1000:])
 return json.loads(r.stdout)
boot="chdir("+json.dumps(str(live))+");require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
locks=[]
for lock in [live.parent/'.servernet-web-release.lock',live.parent/'servernet-release.lock']:
 h=lock.open('a');fcntl.flock(h,fcntl.LOCK_EX|fcntl.LOCK_NB);locks.append(h)
for item in release['files']:
 if sha((src/item['source']).read_bytes())!=item['sha256']:raise RuntimeError('Source manifest mismatch')
if mode=='preflight':
 if stage.exists():raise SystemExit('Stage exists: inspect receipt before reuse')
 output={};before={}
 for f in files:
  desired=(src/'website'/f).read_bytes();path=live/f
  if f in new:
   if path.exists():raise RuntimeError('New target already exists: '+f)
   before[f]=None;output[f]=desired;continue
  before[f]=path.read_bytes();s=before[f].decode();wanted=desired.decode()
  if f.startswith('lang/'):
   if "'code_prompt'" in s:raise RuntimeError('Visibility translations already exist')
   block=wanted[wanted.index("    'code_prompt'"):wanted.rindex('];')]
   s=once(s,'\n];\n','\n'+block+'];\n')
  elif f.endswith('settings/accounts.blade.php'):
   anchor="@include('admin.settings.payment-visibility')";s=once(s,anchor,anchor+"\n@include('admin.settings.discount-entry')")
  elif f.endswith('account/partials/campaign-discount.blade.php'):
   base=subprocess.check_output(['git','-C',str(src),'show','f383474e^:website/'+f]).decode()
   if s.replace('\r\n','\n')!=base.replace('\r\n','\n'):raise RuntimeError('Campaign partial drift')
   ending='\r\n' if '\r\n' in s else '\n';s=wanted.replace('\n',ending)
  elif f.endswith('admin/customer.blade.php'):
   anchor='<div class="ct-pane" data-pane="finance">';s=once(s,anchor,anchor+"\n@include('admin.partials.customer-discount-entry')")
  elif f=='routes/web.php':
   anchor="        Route::post('/customers/{customer}/status', [\\App\\Http\\Controllers\\Admin\\CustomerController::class, 'status']);"
   s=once(s,anchor,anchor+"\n        Route::post('/customers/{customer}/discount-entry', [\\App\\Http\\Controllers\\Admin\\DiscountVisibilityController::class, 'updateCustomer'])->middleware('admin');")
   anchor="        Route::post('/settings/payment-visibility', [\\App\\Http\\Controllers\\Admin\\SettingsController::class, 'paymentVisibility'])->middleware('admin');"
   s=once(s,anchor,anchor+"\n        Route::post('/settings/discount-entry', [\\App\\Http\\Controllers\\Admin\\DiscountVisibilityController::class, 'updateDefault'])->middleware('admin');")
  elif f.endswith('account/invoice.blade.php'):
   s=once(s,"@include('account.partials.campaign-discount')\n",'')
   anchor="</section>\n\n@if($invoice->isPayable())"
   s=once(s,anchor,"</section>\n\n@include('account.partials.campaign-discount')\n\n@if($invoice->isPayable())")
   start=s.index('    {{-- کوپنِ هدیه — فقط وقتی مشتری کوپنِ زنده‌ای دارد.');end=s.index('    @if($pendingBank)',start)
   s=s[:start]+s[end:]
   css=wanted[wanted.index('.invoice-discount{'):wanted.index('.pm-lead{')]
   s=once(s,'<style>\n.pm-lead','<style>\n'+css+'\n.pm-lead')
  else:raise RuntimeError('Unreviewed shared file')
  output[f]=s.encode()
 for p in ['vendor/autoload.php','app/Services/Marketing/DiscountEngine.php','app/Models/MarketingRedemption.php']:
  if not (live/p).is_file():raise RuntimeError('Missing dependency: '+p)
 state=php_run(boot+"echo json_encode(['php'=>PHP_VERSION,'driver'=>Illuminate\\Support\\Facades\\DB::connection()->getDriverName(),'has_column'=>Illuminate\\Support\\Facades\\Schema::hasColumn('customers','discount_entry_visibility'),'default'=>App\\Models\\Setting::get('invoice_discount_entry_enabled','0'),'customers'=>App\\Models\\Customer::count()]);")
 if state['has_column']:raise RuntimeError('Column already exists; reconcile before installing')
 if state['default']!='0':raise RuntimeError('Unexpected existing default; review')
 stage.mkdir(parents=True,mode=0o700)
 manifest={}
 for f,data in output.items():
  p=stage/'staged'/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data);p.chmod(0o600)
  subprocess.run([php,'-l',str(p)],check=True,stdout=subprocess.DEVNULL)
  if before[f] is not None:
   p=stage/'before'/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before[f]);p.chmod(0o600)
  manifest[f]={'before':sha(before[f]) if before[f] is not None else None,'after':sha(data)}
 (stage/'manifest.json').write_text(json.dumps(manifest,indent=2));(stage/'preflight.json').write_text(json.dumps({'status':'ready','files':len(files),'state':state,'application_writes':0}))
 print(json.dumps({'status':'ready','files':len(files),'state':state,'application_writes':0}));raise SystemExit(0)
manifest=json.loads((stage/'manifest.json').read_text())
if mode=='verify':
 assert all(sha((live/f).read_bytes())==v['after'] for f,v in manifest.items())
 r=subprocess.run([php,str(src/'scripts/verify-discount-entry-20261026.php'),str(live),str(stage)],cwd=live,capture_output=True,text=True)
 if r.returncode:raise RuntimeError('Acceptance failed: '+r.stdout[-1800:]+r.stderr[-1000:])
 result=json.loads(r.stdout)
 (stage/'verification.json').write_text(json.dumps(result,indent=2));print(json.dumps({'status':'verified','files':len(files),'acceptance':result}));raise SystemExit(0)
if (stage/'installed.json').exists():raise SystemExit('Already installed; use verify, never repeat apply blindly')
for f,v in manifest.items():
 actual=(live/f).read_bytes() if (live/f).exists() else None
 if (sha(actual) if actual is not None else None)!=v['before']:raise RuntimeError('Live drift before apply: '+f)
 if sha((stage/'staged'/f).read_bytes())!=v['after']:raise RuntimeError('Stage drift')
installed=[]
try:
 for f in files:
  p=live/f;v=manifest[f];old=p.read_bytes() if p.exists() else None
  if (sha(old) if old is not None else None)!=v['before']:raise RuntimeError('Live drift: '+f)
  p.parent.mkdir(parents=True,exist_ok=True);temp=p.with_name(p.name+'.discount-staged')
  if temp.exists():raise RuntimeError('Stale sibling')
  temp.write_bytes((stage/'staged'/f).read_bytes());temp.chmod(0o644);os.replace(temp,p);installed.append(f)
 migration='database/migrations/2026_10_26_140000_add_discount_entry_visibility_to_customers.php'
 result=php_run(boot+"$exit=Illuminate\\Support\\Facades\\Artisan::call('migrate',['--path'=>"+json.dumps(migration)+",'--force'=>true]);if($exit!==0)throw new RuntimeException('Exact migration failed');App\\Models\\Setting::put('invoice_discount_entry_enabled','0');Illuminate\\Support\\Facades\\Artisan::call('route:clear');echo json_encode(['has_column'=>Illuminate\\Support\\Facades\\Schema::hasColumn('customers','discount_entry_visibility'),'default'=>App\\Models\\Setting::get('invoice_discount_entry_enabled'),'overrides'=>App\\Models\\Customer::where('discount_entry_visibility','!=','inherit')->count()]);")
 if result!={'has_column':True,'default':'0','overrides':0}:raise RuntimeError('Unexpected preference defaults')
 blades=[str(live/f) for f in files if f.endswith('.blade.php')]
 php_run(boot+'$paths=json_decode('+json.dumps(json.dumps(blades))+',true);foreach($paths as $p)app("blade.compiler")->compile($p);echo json_encode(["compiled"=>count($paths)]);')
 assert all(sha((live/f).read_bytes())==v['after'] for f,v in manifest.items())
 receipt={'status':'installed','files':len(installed),'preferences':result,'migration':migration,'shared_live_differences_preserved':True}
 (stage/'installed.json').write_text(json.dumps(receipt,indent=2));print(json.dumps(receipt))
except BaseException:
 for f in reversed(installed):
  p=live/f
  if sha(p.read_bytes())!=manifest[f]['after']:raise RuntimeError('Later drift: manual rollback required')
  if manifest[f]['before'] is None:p.unlink()
  else:p.write_bytes((stage/'before'/f).read_bytes())
 (stage/'failed.json').write_text(json.dumps({'files_restored':len(installed),'column_retained_if_migrated':True}));raise
