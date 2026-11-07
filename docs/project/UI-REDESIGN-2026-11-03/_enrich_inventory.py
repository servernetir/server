exec(open('/workspace/scratch/0a7fa9ee50cd/source/docs/project/UI-REDESIGN-2026-11-03/_inventory_parser.py').read().split('rows=[];decls=[]')[0])
data=json.loads((OUT/'_route_raw.json').read_text());rows=data['rows']
controller_methods={};controller_fail=[]
classes=sorted({r['action'].split('@')[0] for r in rows if r['action_kind']=='controller'})
for cl in classes:
 p='website/app/'+cl.removeprefix('App\\').replace('\\','/')+'.php'
 local=ROOT/p
 if local.exists():s=local.read_text()
 else:
  cp=subprocess.run(['git','show','HEAD:'+p],cwd=ROOT,capture_output=True,text=True,timeout=20)
  if cp.returncode:controller_fail.append(p);continue
  s=cp.stdout
 ts=lex(s);pairs=matching(ts);methods={}
 for i,t in enumerate(ts):
  if t[0]=='function' and i+1<len(ts) and re.match(r'\w',ts[i+1][0]):
   n=ts[i+1][0];j=i+2
   while j<len(ts) and ts[j][0] not in ('{',';'):j+=1
   if j>=len(ts) or ts[j][0]!='{':continue
   k=pairs.get(j)
   if k is None:continue
   body=s[ts[j][1]:ts[k][2]]
   views=re.findall(r"\bview\(\s*['\"]([\w./-]+)['\"](?=\s*[,\)])",body)
   dynamic=re.findall(r"\bview\(\s*([^\n]+)",body)
   # Locate calls to same-controller helpers; recurse later only for returned render helpers.
   helpers=re.findall(r'return\s+\$this->(\w+)\(',body)
   methods[n]={'source':p,'line':s.count('\n',0,ts[i][1])+1,'views':list(dict.fromkeys(views)),'helpers':helpers,'body':body,'view_expressions':[x[:180] for x in dynamic if not x.lstrip().startswith(')') and not re.match(r"['\"][\w./-]+['\"]\s*[,\)]",x)]}
 controller_methods[cl]=methods
print('Controller sources read:',len(controller_methods),'missing:',controller_fail,flush=True)
viewinfo={}
for p in (ROOT/'website/resources/views').rglob('*.blade.php'):
 s=p.read_text();name=str(p.relative_to(ROOT/'website/resources/views')).removesuffix('.blade.php').replace('/','.')
 extends=re.findall(r"@extends\(\s*['\"]([^'\"]+)['\"]",s)
 viewinfo[name]={'path':str(p.relative_to(ROOT)),'extends':extends,'noindex':bool(re.search(r"@section\(\s*['\"]noindex",s) or re.search(r'<meta[^>]+name=[\'\"]robots[\'\"][^>]+noindex',s)),'noindex_source_line':next((i for i,l in enumerate(s.splitlines(),1) if 'noindex' in l),None)}
def ancestry(name,seen=None):
 seen=seen or set()
 if name in seen:return []
 seen.add(name);v=viewinfo.get(name,{})
 return list(dict.fromkeys(v.get('extends',[])+[a for par in v.get('extends',[]) for a in ancestry(par,seen)]))
def method_views(cl,m,depth=0):
 x=controller_methods.get(cl,{}).get(m,{})
 out=x.get('views',[]).copy()
 if depth<3:
  for h in x.get('helpers',[]):out+=method_views(cl,h,depth+1)
 return list(dict.fromkeys(out))
for r in rows:
 evidence=[];resolution='direct route source' if r['view'] else 'not a rendered-view declaration'
 if r['action_kind']=='controller':
  cl,m=r['action'].split('@',1);method=controller_methods.get(cl,{}).get(m)
  views=method_views(cl,m)
  if cl=='App\\Http\\Controllers\\Account\\SectionController' and m in ('hosting','servers','other'):
   views=['account.'+m]
  if cl=='App\\Http\\Controllers\\Admin\\MarketingController' and m=='show':
   views=['admin.marketing.email-lead','admin.marketing.lead']
  r['view']+=views;r['view']=list(dict.fromkeys(r['view']))
  if method:
   evidence.append({'source':method['source'],'line':method['line'],'method':m})
   r['view_expressions']=method['view_expressions']
   resolution='literal view() or statically traced helper argument/conditional view branch in target method' if views else ('dynamic view expression: manual/runtime resolution required' if method['view_expressions'] else 'no literal HTML view found in target method')
   r['response_evidence']=[x for x,p in [('html-view',r'\bview\('),('json',r'response\(\)->json\('),('redirect',r'\bredirect\('),('download',r'\bdownload\(|\bstreamDownload\('),('raw-response',r'\bresponse\(')] if re.search(p,method['body'])]
   if method['view_expressions']:r['dynamic_notes'].append('Dynamic view selection retained in view_expressions.')
  else:resolution='controller method inherited/not located in source scan'
 if r['action_kind']=='closure' and "app(SiteController::class)->page(" in r['action']:
  r['view']=['pages.content'];resolution='SiteController::page common page template; content key retained in action'
 r['layout']=list(dict.fromkeys(a for v in r['view'] for a in ancestry(v)))
 r['view_resolution']=resolution;r['view_sources']=evidence
 r['view_files']=[viewinfo[v]['path'] for v in r['view'] if v in viewinfo]
 r['layout_files']=[viewinfo[v]['path'] for v in r['layout'] if v in viewinfo]
 path=re.sub(r'^/(?:en|tr)(?=/|$)','',r['path']) or '/';seg=path.strip('/').split('/');first=seg[0]
 mw=r['middleware'];allmw=' '.join(mw)
 if first=='account':r['access']='customer session + active/IP/birthday middleware';r['module']='customer/'+(seg[1] if len(seg)>1 else 'dashboard')
 elif first=='admin':
  r['access']='staff session + '+('admin role' if 'admin' in mw else 'staff role' if 'staff' in mw else 'controller policy/author role (verify)') if 'auth:web' in mw else 'admin authentication/bootstrap (controller guards)'
  r['module']='admin/'+(seg[1] if len(seg)>1 else 'dashboard')
 elif first in ('login','register','logout','password'):r['access']='customer authentication/guest flow';r['module']='auth/customer'
 elif first=='system':r['access']='operational endpoint; handler token/guard must be verified';r['module']='system/operations'
 elif first in ('api','v1','agent'):
  r['access']='API/agent; middleware and controller bearer/HMAC/token contract';r['module']=first+'/'+('/'.join(seg[1:3]) if len(seg)>1 else '')
 elif first in ('payment','bale','cloud-phone'):
  r['access']='payment or provider callback; handler signature/token verification';r['module']='integration/'+first
 else:
  r['access']='public browser/utility (handler constraints may apply)';r['module']='public/'+(first if first else 'home')
 direct_noindex=[v for v in r['view'] if viewinfo.get(v,{}).get('noindex')]
 layout_noindex=[v for v in r['layout'] if v!='layouts.site' and viewinfo.get(v,{}).get('noindex')]
 r['noindex_evidence']=[]
 for v in direct_noindex+layout_noindex:r['noindex_evidence'].append(viewinfo[v]['path']+':'+str(viewinfo[v]['noindex_source_line']))
 if first in ('account','admin','login','register','logout','password','payment','system','api','v1','agent'):
  r['seo_class']='private/operational; noindex required via console host/meta/header; excluded from public SEO'
  if first in ('account','admin'):r['noindex_evidence'].append('website/app/Http/Middleware/ConsoleHost.php:190 (console response header; verify host/routing)')
 elif r['action_kind']=='redirect' or 'redirect(' in r['action'] or re.search(r'(?:abort\(410\)|response\(\s*\x27\x27\s*,\s*410\))',r['action']):r['seo_class']='redirect/gone; preserve status and target; no SEO content redesign'
 elif direct_noindex or layout_noindex:r['seo_class']='noindex or conditional noindex from view; inspect condition before SEO changes'
 elif not r['view']:r['seo_class']='utility/API/non-view or unresolved render; inspect response; exclude from public SEO by default'
 else:r['seo_class']='public SEO candidate; preserve canonical/hreflang/schema and conditional noindex'
 if first=='account':r['phase']='P0 dashboard preview' if len(seg)==1 else 'P1 customer console families'
 elif path.startswith('/hosting'):r['phase']='P0 hosting preview'
 elif first=='admin':r['phase']='P3 admin/operations families'
 elif first in ('login','register','password','logout'):r['phase']='P1 authentication/access shell'
 elif r['view']:r['phase']='P2 public product/content/tool families'
 else:r['phase']='P4 utility/API/redirect regression only'
 # Width policy distinguishes inherited public override from content readability caps.
 if 'panel.layout' in r['layout']:r['width_policy']='boxed customer console: base 1200px container; 248px sidebar + 22px gap'
 elif 'auth.shell' in r['layout']:r['width_policy']='focused auth: 900px shell; preserve form readability'
 elif 'admin.layout' in r['layout']:r['width_policy']='viewport admin shell: 250px sidebar + flexible main'
 elif 'layouts.site' in r['layout']:
  excluded=bool(re.match(r'^/(?:account|admin|login|register|password|checkout|cart|order|payment|oauth|system|api)(?:/|$)',path))
  r['width_policy']='base site container unless page override; excluded from sn-public Atlas scope' if excluded else 'public fluid Atlas shell; inspect component-specific readability caps'
 elif r['view']:r['width_policy']='standalone view/partial; inspect purpose-specific width'
 else:r['width_policy']='not an identified HTML page'
# Source registrations may collide; preserve each with explicit diagnostics, never pretend these are runtime effective routes.
dups=collections.defaultdict(list)
for r in rows:dups[(r['domain'],r['methods'],r['path'])].append(r)
conflicts=[]
for key,rr in dups.items():
 if len(rr)>1:
  ids=[x['id'] for x in rr];conflicts.append({'methods':key[1],'path':key[2],'row_ids':ids,'note':'Same declared method/path; later registration may replace earlier action. Runtime route:list required for effective collection.'})
  for x in rr:x['dynamic_notes'].append('Duplicate source registration for method/path; see duplicate_registrations.')
overlaps=[]
pathrows=collections.defaultdict(list)
for r in rows:pathrows[(r['domain'],r['path'])].append(r)
for (_,path),rr in pathrows.items():
 if len(rr)>1:
  for i,a in enumerate(rr):
   for b in rr[i+1:]:
    common=sorted(set(a['methods'].split('|'))&set(b['methods'].split('|')))
    if common:overlaps.append({'path':path,'shared_methods':common,'row_ids':[a['id'],b['id']],'source_locations':[a['source']+':'+str(a['source_line']),b['source']+':'+str(b['source_line'])]})
# Route declarations in other PHP files checked by constrained app source search; console is scheduling, not HTTP.
counts={'source_http_declarations':len(data['declarations']),'expanded_source_registrations_including_framework_health':len(rows),'expanded_http_rows_without_framework_health':len(rows)-1,'unique_declaration_signatures_methodset_path_domain':len(dups),'duplicate_signatures':len(conflicts),'overlapping_method_path_pairs':len(overlaps),'distinct_views_mapped':len({v for r in rows for v in r['view']}),'distinct_action_classes':len(classes),'action_class_sources_read':len(controller_methods),'blade_source_files':len(viewinfo),'by_source':dict(collections.Counter(r['source'] for r in rows)),'by_locale':dict(collections.Counter(r['locale'] for r in rows)),'by_phase':dict(collections.Counter(r['phase'] for r in rows)),'by_action_kind':dict(collections.Counter(r['action_kind'] for r in rows)),'by_seo_class':dict(collections.Counter(r['seo_class'] for r in rows))}
meta={'title':'ServerNet Phase 0 static route and layout inventory','base_branch':'develop','base_sha':'31a311e904741f8b58f462824a83c9745731b23f','date':'2026-11-03','status':'preview-only; no application/backend changes, no production access or deployment','method':'Static PHP source lexical scan, explicit literal foreach and shared-closure locale expansion; controller view literals/returned helpers and Blade inheritance scan. Not php artisan route:list; no Laravel boot, migrations, provider calls, or runtime requests.','counts':counts,'source_files':list(files)+['website/bootstrap/app.php'],'excluded_source':'website/routes/console.php contains commands/schedules, not HTTP routes; no routes/api.php in tracked active app tree.','unresolved':["Effective API throttles are configuration expressions: 'throttle:'.($rate['read'] ?? '120,1'), check fallback 60,1, write fallback 20,1; retained as EXPR values.",'The SectionController::room($view) helper is resolved from literal caller arguments (account.hosting/account.servers/account.other); Admin\\MarketingController::show maps both conditional view branches.',
'Controller authorization, form/CSRF behavior, host redirects and data-dependent view branches require isolated runtime verification.','Dynamic view expressions remain in per-row view_expressions; helper scan does not prove all runtime branches.','No Route::domain groups found. Host policy is middleware-defined; domain field is policy context, not an explicit route domain constraint.','Route::redirect declarations cover all Laravel redirect methods; GET routes include implied HEAD. Runtime framework/version handling and automatic OPTIONS are not boot-verified.','Global middleware is recorded once in architecture below, not duplicated into each route row.'], 'global_middleware':{'source':'website/bootstrap/app.php:14','prepend':['LegacyDomain','ConsoleHost'],'append':['SecurityHeaders','TrackNotFound'],'web_append':['PageCache'],'aliases':{'locale':'SetLocale','admin':'EnsureAdmin','staff':'EnsureStaff','support.ticket.api':'SupportTicketApiToken'},'csrf_exceptions':'See bootstrap/app.php:83 onward; per-route exclusions retained independently.'},'duplicate_registrations':conflicts,'overlapping_method_registrations':overlaps,'routes':rows}
(OUT/'route-inventory.json').write_text(json.dumps(meta,ensure_ascii=False,indent=2)+'\n')
cols=['id','methods','path','name','domain','middleware','without_middleware','locale','access','module','view','layout','source','source_line','action_kind','action','constraints','seo_class','phase','width_policy','view_resolution','dynamic_notes']
with (OUT/'route-inventory.csv').open('w',newline='') as f:
 w=csv.DictWriter(f,fieldnames=cols);w.writeheader()
 for r in rows:w.writerow({k:json.dumps(r.get(k),ensure_ascii=False) if isinstance(r.get(k),(list,dict)) else r.get(k) for k in cols})
print(json.dumps(counts,ensure_ascii=False,indent=2))
print('duplicates',json.dumps(conflicts,ensure_ascii=False))
print('missing controller methods',sorted({r['action'] for r in rows if r['view_resolution']=='controller method inherited/not located in source scan'}))
print('dynamic view expressions',[(r['path'],r.get('view_expressions')) for r in rows if r.get('view_expressions')][:18])
