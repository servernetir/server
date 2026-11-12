from pathlib import Path
import re, json, csv, collections, subprocess
ROOT=Path('/workspace/scratch/0a7fa9ee50cd/source')
OUT=ROOT/'docs/project/UI-REDESIGN-2026-11-03'
# Small source lexer: strings and comments stay opaque, so PHP/JS inside handlers cannot change nesting.
TOKEN=re.compile(r"(?P<comment>/\*.*?\*/|//[^\n]*|\#[^\n]*)|(?P<string>'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\")|(?P<space>\s+)|(?P<id>\$?[A-Za-z_\x80-\uffff][A-Za-z0-9_\x80-\uffff]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*)|(?P<op>::|->|=>|\?\?|===|!==|==|!=)|(?P<other>.)",re.S)
def lex(s):
 return [(m.group(),m.start(),m.end(),m.lastgroup) for m in TOKEN.finditer(s) if m.lastgroup not in ('comment','space')]
def matching(ts):
 stack=[]; pairs={}
 for i,t in enumerate(ts):
  v=t[0]
  if v in ('(','[','{'):stack.append(i)
  elif v in (')',']','}'):
   if stack and ts[stack[-1]][0]=={')':'(',']':'[','}':'{'}[v]:
    j=stack.pop();pairs[j]=i;pairs[i]=j
 return pairs
def splitargs(ts, a,b,pairs):
 ans=[];st=a;i=a
 while i<b:
  if ts[i][0] in ('(','[','{'):i=pairs.get(i,i)+1;continue
  if ts[i][0]==',':ans.append((st,i));st=i+1
  i+=1
 if st<b:ans.append((st,b))
 return ans
class File:
 def __init__(self,path):
  self.path=path;self.s=(ROOT/path).read_text();self.ts=lex(self.s);self.pairs=matching(self.ts);self.chains=[];self.groups=[];self.closures=[];self.loops=[]
  self.aliases=dict(re.findall(r'^use\s+([\\\w]+)(?:\s+as\s+(\w+))?;',self.s,re.M)) if False else {}
  for full,alias in re.findall(r'^use\s+([\\\w]+)(?:\s+as\s+(\w+))?;',self.s,re.M):self.aliases[alias or full.split('\\')[-1]]=full
  for i,t in enumerate(self.ts):
   if t[0] in ('Route','\\Illuminate\\Support\\Facades\\Route','Illuminate\\Support\\Facades\\Route') and self.val(i+1)=='::':
    calls=[];j=i+2
    while j+1<len(self.ts) and self.val(j+1)=='(':
     k=self.pairs.get(j+1)
     if k is None:break
     args=splitargs(self.ts,j+2,k,self.pairs)
     calls.append({'method':self.val(j),'args':args,'open':j+1,'close':k});j=k+1
     if self.val(j)!='->':break
     j+=1
    if not calls:continue
    c={'i':i,'end':j,'calls':calls};self.chains.append(c)
    g=next((x for x in calls if x['method']=='group'),None)
    if g:
     fs=[k for k in range(g['open']+1,g['close']) if self.val(k)=='function']
     if fs:
      bo=next((k for k in range(fs[0],g['close']) if self.val(k)=='{'),None)
      if bo is not None:self.groups.append((bo,self.pairs[bo],c))
  # Named closures and literal foreach families.
  for i,t in enumerate(self.ts):
   if t[0].startswith('$') and self.val(i+1)=='=' and self.val(i+2)=='function':
    bo=next(k for k in range(i+2,len(self.ts)) if self.val(k)=='{');self.closures.append((bo,self.pairs[bo],t[0]))
   if t[0]=='foreach' and self.val(i+1)=='(':
    pe=self.pairs[i+1];bo=pe+1
    if self.val(bo)=='{':self.loops.append((bo,self.pairs[bo],i+2,pe))
 def val(self,i):return self.ts[i][0] if i<len(self.ts) else ''
 def raw(self,a,b):return self.s[self.ts[a][1]:self.ts[b-1][2]] if a<b else ''
 def clean(self,a,b):return ''.join(t[0] for t in self.ts[a:b])
 def line(self,i):return self.s.count('\n',0,self.ts[i][1])+1
 def class_name(self,x):
  x=x.strip().removesuffix('::class').lstrip('\\');parts=x.split('\\');return self.aliases.get(parts[0],parts[0])+('\\'+'\\'.join(parts[1:]) if len(parts)>1 else '')
 def eval(self,a,b,env):
  if a>=b:return ''
  s=self.clean(a,b)
  if s in env:return env[s]
  if b-a==1 and self.ts[a][3]=='string':return self.val(a)[1:-1]
  if s.endswith('::class'):return self.class_name(s)
  if self.val(a)=='[' and self.pairs.get(a)==b-1:
   return [self.eval(x,y,env) for x,y in splitargs(self.ts,a+1,b-1,self.pairs)]
  # Concatenation only, never execute application code/config.
  depth=0;chunks=[];st=a;i=a
  while i<b:
   if self.val(i) in ('(','[','{'):i=self.pairs.get(i,i)+1;continue
   if self.val(i)=='.':chunks.append((st,i));st=i+1
   i+=1
  if chunks:
   chunks.append((st,b));vals=[self.eval(x,y,env) for x,y in chunks]
   if all(isinstance(v,str) and not v.startswith('EXPR:') for v in vals):return ''.join(vals)
  return 'EXPR:'+self.raw(a,b)
 def vars(self,pos,env):
  env=env.copy()
  for i in range(pos):
   if self.val(i).startswith('$') and self.val(i+1)=='=':
    try:end=next(j for j in range(i+2,pos) if self.val(j)==';')
    except StopIteration:continue
    s=self.clean(i+2,end)
    if s.endswith('::class') and not any(x in s for x in ['(','=>']):env[self.val(i)]=self.class_name(s)
  return env
 def attrs(self,c,env):
  d={'prefix':'','name':'','middleware':[],'without':[],'constraints':[],'domain':None}
  for x in c['calls']:
   m=x['method'];v=[self.eval(a,b,env) for a,b in x['args']]
   if m in ('prefix','name','domain') and v:d[m]=v[0]
   if m in ('middleware','withoutMiddleware') and v:
    w=v[0] if isinstance(v[0],list) else [v[0]];d['middleware' if m=='middleware' else 'without']+=w
   if m.startswith('where'):d['constraints'].append(m+'('+', '.join(self.raw(a,b) for a,b in x['args'])+')')
  return d
files={p:File(p) for p in ['website/routes/web.php','website/routes/identity-api.php','website/routes/urmia-auth.php','website/routes/identity-site.php','website/routes/ai-growth.php','website/routes/madar-admin.php','website/routes/madar-account.php']}
web=files['website/routes/web.php']
route_methods={'get','post','put','patch','delete','options','head','match','any','view','redirect','permanentRedirect','fallback'}
def base_variants(f,c):
 # Includes inherit their exact source call site and locale envelope.
 if f.path in ['website/routes/identity-site.php','website/routes/madar-account.php']:
  include_line=42 if 'identity-site' in f.path else 407
  include_pos=next(i for i,t in enumerate(web.ts) if web.line(i)==include_line and t[0]=='require')
  ctx=[x[2] for x in sorted(web.groups) if x[0]<include_pos<x[1]]
  return [(lc,{'$locale':lc},ctx,lc) for lc in ['fa','en','tr']]
 cl=next((n for a,b,n in web.closures if a<c['i']<b),None) if f==web else None
 if cl=='$site':return [(lc,{'$locale':lc},[],lc) for lc in ['fa','en','tr']]
 if cl=='$urmia':return [('fa',{'$locale':'fa'},[],'fa')]
 if cl=='$urmiaGone':return [(lc,{'$locale':lc},[],lc) for lc in ['en','tr']]
 # Loop env expanded further below.
 return [(None,{},[],None)]
def expandloops(f,c,env):
 variants=[env]
 for bo,be,a,b in f.loops:
  if not bo<c['i']<be:continue
  ai=next((i for i in range(a,b) if f.val(i)=='as'),None)
  if ai is None:continue
  if f.val(a)!='[':continue
  arr_end=f.pairs[a]
  entries=splitargs(f.ts,a+1,arr_end,f.pairs);vars_=[f.val(i) for i in range(ai+1,b) if f.val(i).startswith('$')]
  more=[]
  for old in variants:
   for x,y in entries:
    ar=next((i for i in range(x,y) if f.val(i)=='=>'),None)
    new=old.copy()
    if ar is not None and len(vars_)==2:new[vars_[0]]=f.eval(x,ar,old);new[vars_[1]]=f.eval(ar+1,y,old)
    elif len(vars_)==1:new[vars_[0]]=f.eval(x,y,old)
    if '$prefix' in new:new['$locale']='fa' if new['$prefix']=='' else new['$prefix'].strip('/')
    more.append(new)
  variants=more
 return variants
rows=[];decls=[]
for path,f in files.items():
 for c in f.chains:
  mc=next((x for x in c['calls'] if x['method'] in route_methods),None)
  if not mc:continue
  decls.append({'source':path,'line':f.line(c['i']),'method':mc['method']})
  for lc,env,external,locale_envelope in base_variants(f,c):
   for ev in expandloops(f,c,env):
    ev=f.vars(c['i'],ev)
    groups=[(f,g) for a,b,g in sorted(f.groups) if a<c['i']<b]
    attrs=[]
    if locale_envelope:
     attrs.append({'prefix':'' if lc=='fa' else lc,'name':'' if lc=='fa' else lc+'.','middleware':['locale:'+lc],'without':[],'constraints':[],'domain':None})
    attrs += [web.attrs(g,ev) for g in external]
    attrs += [ff.attrs(g,ev) for ff,g in groups]
    attrs += [f.attrs(c,ev)]
    pref='/'.join(str(x['prefix']).strip('/') for x in attrs if x['prefix'])
    name=''.join(str(x['name']) for x in attrs if x['name']) or None
    mw=['web'];without=[];constraints=[]
    for x in attrs:mw+=x['middleware'];without+=x['without'];constraints+=x['constraints']
    mw=list(dict.fromkeys(x for x in mw if x not in without));without=list(dict.fromkeys(without))
    ar=[f.eval(a,b,ev) for a,b in mc['args']]
    methods=mc['method'].upper()
    if methods in ('GET','VIEW'):methods='GET|HEAD'
    elif methods in ('REDIRECT','PERMANENTREDIRECT','ANY','FALLBACK'):methods='GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS'
    elif methods=='MATCH':methods='|'.join(x.upper() for x in ar.pop(0))
    route_path=ar[0] if ar else '{fallback}'
    if not isinstance(route_path,str):route_path=str(route_path)
    full='/'+('/'.join([pref.strip('/'),route_path.strip('/')])).strip('/')
    full=re.sub('/+','/',full)
    act=ar[1] if len(ar)>1 else ''
    action_kind='controller' if isinstance(act,list) or (isinstance(act,str) and act.startswith('App\\')) else 'closure'
    views=[]
    if mc['method']=='view':views=[act];action_kind='view'
    if mc['method'] in ('redirect','permanentRedirect'):action_kind='redirect'
    direct=f.raw(*mc['args'][1]) if len(mc['args'])>1 else ''
    views += re.findall(r"\bview\(\s*['\"]([^'\"]+)['\"]",direct)
    if isinstance(act,list):action='@'.join(str(v) for v in act)
    elif action_kind=='controller':action=act+'@__invoke'
    else:action=direct[:500] if direct else 'fallback'
    effective_lc=lc or ev.get('$locale') or next((x.split(':',1)[1] for x in mw if x.startswith('locale:')),None) or 'unprefixed/default'
    row={'id':len(rows)+1,'methods':methods,'path':full,'name':name,'domain':'* (host policy: ConsoleHost + LegacyDomain middleware)','middleware':mw,'without_middleware':without,'locale':effective_lc,'access':'','module':'','view':list(dict.fromkeys(views)),'layout':[],'source':path,'source_line':f.line(c['i']),'action_kind':action_kind,'action':action,'constraints':constraints,'seo_class':'','phase':'','dynamic_notes':[],'source_declaration_method':mc['method']}
    if '$' in str(ar) or any('EXPR:' in str(v) for v in [full,name,mw,action]):row['dynamic_notes'].append('Source expression retained; not executed.')
    if any(bo<c['i']<be for bo,be,a,b in f.loops):row['dynamic_notes'].append('Literal foreach expanded from source.')
    if locale_envelope:row['dynamic_notes'].append('Shared closure/include expanded for '+effective_lc+'.')
    rows.append(row)
# Include Laravel health route generated from bootstrap, not from HTTP route source declarations.
rows.append({'id':len(rows)+1,'methods':'GET|HEAD','path':'/up','name':None,'domain':'* (global host middleware)','middleware':[],'without_middleware':[],'locale':'unprefixed/default','access':'public-health','module':'system/health','view':[],'layout':[],'source':'website/bootstrap/app.php','source_line':12,'action_kind':'framework-health','action':'Application::withRouting(health: /up)','constraints':[],'seo_class':'non-HTML/utility; exclude from public SEO','phase':'P4 utility regression','dynamic_notes':['Framework generated; effective health middleware requires runtime verification.'],'source_declaration_method':'framework-health'})
# Raw JSON first, enriching pass supplies controller/view provenance.
(OUT/'_route_raw.json').write_text(json.dumps({'rows':rows,'declarations':decls},ensure_ascii=False,indent=2))
print(json.dumps({'declarations':len(decls),'expanded_rows':len(rows),'files':collections.Counter(x['source'] for x in rows),'unresolved_paths':[x['path'] for x in rows if 'EXPR:' in x['path']],'unresolved_names':[x['name'] for x in rows if x['name'] and 'EXPR:' in x['name']]},ensure_ascii=False,indent=2))
