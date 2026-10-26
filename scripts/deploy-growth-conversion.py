#!/usr/bin/env python3
"""Narrow conversion release; no schema, credentials, payments, sends or cron changes."""
import fcntl,hashlib,json,os,re,subprocess,sys
from pathlib import Path
src,live,stage=(Path(p).resolve() for p in sys.argv[1:4]);execute=sys.argv[4:]==['--execute']
if len(sys.argv) not in [4,5] or (len(sys.argv)==5 and not execute):raise SystemExit('Invalid args')
php='/opt/cpanel/ea-php84/root/usr/bin/php'
new=['app/Http/Controllers/ReportConsultationController.php','app/Services/Marketing/CheckoutRecovery.php','lang/fa/conversion.php','lang/en/conversion.php','lang/tr/conversion.php','resources/views/partials/report-consultation.blade.php','resources/views/account/partials/checkout-recovery.blade.php']
shared=['app/Services/Crm/ChatLeadCapture.php','app/Http/Controllers/ReportController.php','app/Http/Controllers/Account/AccountController.php','resources/views/pages/report.blade.php','resources/views/layouts/site.blade.php','resources/views/account/home.blade.php','routes/web.php']
def once(s,a,b,counts=(1,)):
 # Match either line ending, preserving every byte outside the exact insertion.
 pattern=r'\r?\n'.join(re.escape(part) for part in a.split('\n'))
 matches=list(re.finditer(pattern,s))
 if len(matches) not in counts:raise RuntimeError('Anchor differs; inspect live source')
 m=matches[0];tail=s[m.end():].split('\n',1)[0]
 ending='\r\n' if '\r\n' in m.group(0) or tail.endswith('\r') else '\n'
 return s[:m.start()]+b.replace('\n',ending)+s[m.end():]
def sha(b):return hashlib.sha256(b).hexdigest()
handles=[]
for file in [Path.home()/'.servernet-web-release.lock',Path.home()/'servernet-release.lock']:
 h=file.open('a');fcntl.flock(h,fcntl.LOCK_EX|fcntl.LOCK_NB);handles.append(h)
if stage.exists():raise SystemExit('Stage exists; inspect receipt before retry')
if list((live/'bootstrap/cache').glob('routes-*.php')):raise SystemExit('Route cache active; requires reviewed host activation')
output={};before={}
for f in new:
 if (live/f).exists():raise SystemExit('New target exists: '+f)
 output[f]=(src/f).read_bytes();before[f]=None
for f in shared:
 before[f]=(live/f).read_bytes();s=before[f].decode()
 if f.endswith('Crm/ChatLeadCapture.php'):
  if 'message_key' in s:raise RuntimeError('Review existing capture key changes')
  source=(src/f).read_text();start=source.index('        $providerId =');end=source.index('            $lead = CrmLead::firstOrCreate(')
  old='        [$lead, $created] = DB::transaction(function () use ($data) {\n'
  s=once(s,old,source[start:end])
  s=once(s,"'provider_id' => 'site-chat:'.$data['external_id']","'provider_id' => $providerId")
 elif f.endswith('Controllers/ReportController.php'):
  if 'X-Robots-Tag' in s:raise RuntimeError('Review existing report privacy changes')
  s=once(s,'use Illuminate\\Http\\RedirectResponse;','use Illuminate\\Http\\RedirectResponse;\nuse Illuminate\\Http\\Response;')
  s=once(s,'public function show(string $token): View','public function show(string $token): Response')
  s=once(s,"return view('pages.report', ['report' => $report]);","return response()->view('pages.report', ['report' => $report])\n            ->header('Cache-Control', 'private, no-store')\n            ->header('Referrer-Policy', 'no-referrer')\n            ->header('X-Robots-Tag', 'noindex, nofollow');")
 elif f.endswith('Controllers/Account/AccountController.php'):
  if '$checkoutRecovery' in s:raise RuntimeError('Recovery already integrated')
  s=once(s,'        $todo = [];',"        $checkoutRecovery = app(\\App\\Services\\Marketing\\CheckoutRecovery::class)->forCustomer((int) $customer->id);\n        $recoveryIds = $checkoutRecovery->pluck('invoice_id')->all();\n        $todo = [];")
  s=once(s,'        foreach ($openInvoices as $inv) {',"        foreach ($openInvoices as $inv) {\n            if (in_array((int) $inv->id, $recoveryIds, true)) {\n                continue;\n            }")
  s=once(s,"            'todo'         => $todo,","            'todo'         => $todo,\n            'checkoutRecovery' => $checkoutRecovery,")
 elif f.endswith('pages/report.blade.php'):
  s=once(s,"@section('noindex', '1')","@section('noindex', '1')\n@section('private_report', '1')")
  s=once(s,"    'printUrl'   => $report->url(),\n])","    'printUrl'   => $report->url(),\n])\n\n@include('partials.report-consultation')")
 elif f.endswith('layouts/site.blade.php'):
  for part in ['gtm-head','gtm-body','chat']:
   a="@include('partials."+part+"')";s=once(s,a,"@unless(\\Illuminate\\Support\\Facades\\View::hasSection('private_report'))"+a+'@endunless')
 elif f.endswith('account/home.blade.php'):
  s=once(s,'{{-- ══ کارهای معلق',"@include('account.partials.checkout-recovery')\n\n{{-- ══ کارهای معلق")
 else:
  if "name('report.consultation')" in s:raise RuntimeError('Consultation route exists')
  a="    Route::get('/report/{token}', [ReportController::class, 'show'])\n        ->name('report')->where('token', '[a-z0-9]{16,40}');"
  s=once(s,a,a+"\n    Route::post('/report/{token}/consultation', [\\App\\Http\\Controllers\\ReportConsultationController::class, 'store'])\n        ->name('report.consultation')->where('token', '[a-z0-9]{16,40}')->middleware('throttle:3,1');",(1,2))
 output[f]=s.encode()
if not execute:
 print(json.dumps({'status':'preflight_ok','files':len(output),'new':len(new),'writes':0}));raise SystemExit(0)
stage.mkdir(parents=True,mode=0o700)
manifest={}
for f,data in output.items():
 p=stage/'staged'/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
 subprocess.run([php,'-l',str(p)],check=True,stdout=subprocess.DEVNULL)
 if before[f] is not None:
  p=stage/'before'/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before[f])
 manifest[f]={'before':sha(before[f]) if before[f] is not None else None,'after':sha(data)}
(stage/'manifest.json').write_text(json.dumps(manifest,indent=2))
installed=[]
try:
 for f,data in output.items():
  p=live/f
  if (p.read_bytes() if p.exists() else None)!=before[f]:raise RuntimeError('Live drift: '+f)
  p.parent.mkdir(parents=True,exist_ok=True);temp=p.with_name(p.name+'.conversion-staged')
  if temp.exists():raise RuntimeError('Stale staged sibling')
  temp.write_bytes(data);os.replace(temp,p);installed.append(f)
 subprocess.run([php,'artisan','view:cache'],cwd=live,check=True,stdout=subprocess.DEVNULL)
 if not all((live/f).read_bytes()==data for f,data in output.items()):raise RuntimeError('Hash mismatch')
 result={'status':'installed','files':len(installed),'schema_changed':False,'customer_send':False}
 (stage/'receipt.json').write_text(json.dumps(result));print(json.dumps(result))
except BaseException:
 for f in reversed(installed):
  p=live/f
  if p.read_bytes()!=output[f]:raise RuntimeError('Later drift; rollback requires review: '+f)
  if before[f] is None:p.unlink()
  else:p.write_bytes(before[f])
 (stage/'receipt.json').write_text(json.dumps({'status':'rolled_back','files':len(installed)}));raise
