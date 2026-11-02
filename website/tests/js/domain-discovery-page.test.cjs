const {test, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const supplied = process.env.DOMAIN_DISCOVERY_FIXTURES;
const fixtures = supplied || fs.mkdtempSync(path.join(os.tmpdir(), 'servernet-domain-test-'));
if (!supplied) for (const locale of ['fa','en','tr']) execFileSync(process.env.PHP_BINARY || 'php', [path.join(root,'tests/Fixtures/DomainDiscoveryPreview.php'),locale,fixtures]);
after(() => {if (!supplied) fs.rmSync(fixtures,{recursive:true,force:true});});
const tick = () => new Promise(resolve => setImmediate(resolve));
const settle = async () => { await tick(); await tick(); };
const response = (results = [], more = {}) => ({ok:true,json:async()=>({ok:true,lookup_ok:true,results,...more})});
const row = (name='brand.com', extra={}) => ({domain:name,tld:name.split('.').slice(1).join('.'),primary:name==='brand.com',state:'free',available:true,orderable:true,price_toman:1000000,period_years:1,quote_id:'quote-fixture',...extra});
function setup(fetcher,locale='en',query='',options={}) {
 const dom = new JSDOM('<!doctype html><html lang="'+locale+'" dir="'+(locale==='fa'?'rtl':'ltr')+'"><head><meta name="csrf-token" content="fixture"></head><body>'+fs.readFileSync(path.join(fixtures,`partial-${locale}.html`),'utf8')+'</body></html>',{url:'https://servernet.cloud/'+(locale==='fa'?'':locale+'/')+'domains'+query,runScripts:'outside-only',pretendToBeVisual:true});
 const w = dom.window; w.fetch = fetcher; const scrolls=[];
 w.HTMLElement.prototype.scrollIntoView=function(options){scrolls.push({element:this,options});};
 let now=Date.now(),sequence=0,hidden=!!options.hidden; const timers=new Map();
 w.Date.now=()=>now;
 w.setTimeout=(fn,delay=0,...args)=>{const id=++sequence,ms=Math.max(0,Number(delay)||0);timers.set(id,{id,at:now+ms,delay:ms,fn:()=>fn(...args)});return id;};
 w.clearTimeout=id=>timers.delete(id);
 w.requestAnimationFrame=fn=>w.setTimeout(()=>fn(now),16); w.cancelAnimationFrame=w.clearTimeout;
 w.matchMedia=media=>({media,matches:media.includes('prefers-reduced-motion')&&!!options.reducedMotion,addEventListener(){},removeEventListener(){},addListener(){},removeListener(){}});
 Object.defineProperty(w.document,'hidden',{configurable:true,get:()=>hidden});
 Object.defineProperty(w.document,'visibilityState',{configurable:true,get:()=>hidden?'hidden':'visible'});
 const clock={
  now:()=>now,
  pending:()=>[...timers.values()].sort((a,b)=>a.at-b.at||a.id-b.id),
  async advance(ms){
   const end=now+ms;let count=0;
   while(true){const task=clock.pending()[0];if(!task||task.at>end)break;if(++count>1000)throw new Error('Unbounded browser timer loop');timers.delete(task.id);now=task.at;task.fn();await settle();}
   now=end;await settle();
  },
  async next(){const task=clock.pending()[0];if(!task)throw new Error('No browser timer is scheduled');await clock.advance(Math.max(0,task.at-now));},
  setHidden(value){hidden=value;w.document.dispatchEvent(new w.Event('visibilitychange'));},
 };
 // Both scripts come from the real compiled Blade view (translations and behavior).
 for (const script of w.document.querySelectorAll('script')) {
  w.eval(script.textContent);
  if (w.T && options.tlds) {w.T.tld_first=options.tlds.first;w.T.tld_rest=options.tlds.rest;}
 }
 return {dom,w,doc:w.document,clock,scrolls};
}
function search(env,term) {env.doc.getElementById('dm-q').value=term;env.doc.getElementById('dm-form').dispatchEvent(new env.w.Event('submit',{cancelable:true}));}
test('automatic queue requests one unique suffix at a time, respects the interval, and never overlaps',async()=>{
 const calls=[],pending=[];let active=0,maxActive=0;
 const e=setup((_url,opt)=>{const body=JSON.parse(opt.body);calls.push({body,at:e.clock.now()});active++;maxActive=Math.max(maxActive,active);return new Promise(resolve=>pending.push(results=>{active--;resolve(response(results));}));},'en','',{tlds:{first:['net','com'],rest:[['org','net'],['dev']]}});
 try{
  search(e,'brand.co.uk');assert.equal(calls.length,1);assert.deepEqual(calls[0].body,{q:'brand',tlds:['co.uk']});
  await e.clock.advance(30000);assert.equal(calls.length,1,'an unresolved lookup must not start another suffix');
  pending.shift()([row('brand.co.uk',{primary:false})]);await settle();
  assert.equal(e.doc.querySelector('.dsx-row').dataset.primary,'1','requested multi-label suffix is pinned without an API primary flag');
  for(const suffix of ['net','com','org','dev']){
   await e.clock.advance(1799);assert.notEqual(calls.at(-1).body.tlds[0],suffix,'next request must wait after the last response');
   await e.clock.advance(1);assert.equal(calls.at(-1).body.q,'brand');assert.deepEqual(calls.at(-1).body.tlds,[suffix]);
   assert.equal(active,1);await e.clock.advance(10000);assert.equal(active,1,'slow responses must never overlap');
   pending.shift()([row('brand.'+suffix,{primary:false})]);await settle();
  }
  await e.clock.advance(100000);assert.equal(calls.length,5);assert.equal(maxActive,1);assert.equal(active,0);
  assert.equal(new Set(calls.map(c=>c.body.tlds[0])).size,5);assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'done');
  assert.equal(e.doc.querySelectorAll('.dsx-row').length,5);assert.equal(e.doc.getElementById('dm-more').hidden,true);
 }finally{e.w.close();}
});
test('stale responses cannot replace new results or clear a newer loading state', async()=>{
 const pending=[];const e=setup((_url,opt)=>new Promise(resolve=>pending.push({resolve,opt})));
 try{
  search(e,'old.com');search(e,'new.com');assert.equal(pending.length,2);assert.equal(pending[0].opt.signal.aborted,true);
  pending[0].resolve(response([row('old.com')]));await settle();assert.equal(e.doc.getElementById('dm-results').getAttribute('aria-busy'),'true');assert.equal(e.doc.querySelectorAll('.dsx-row').length,0);
  pending[1].resolve(response([row('new.com',{primary:true})]));await settle();assert.equal(e.doc.getElementById('dm-results').getAttribute('aria-busy'),'false');assert.equal(e.doc.querySelectorAll('.dsx-row').length,1);assert.match(e.doc.querySelector('.dsx-row').textContent,/new\.com/);
 }finally{e.dom.window.close();}
});
test('exact requested name and unchecked state remain visible with every filter',async()=>{
 const e=setup(async()=>response([row('brand.com',{state:'taken',available:false,orderable:false,is_promotion:false}),row('brand.net',{primary:false,state:'unchecked',status:'unknown',available:false,orderable:false,is_promotion:false}),row('brand.org',{primary:false,is_promotion:false})]));
 try{
  search(e,'brand.com');await settle();for(const id of ['f-taken','f-premium','f-unavail','f-deals']){const el=e.doc.getElementById(id);el.checked=true;el.dispatchEvent(new e.w.Event('change'));}
  assert.equal(e.doc.querySelector('[data-primary]').classList.contains('is-hidden'),false);
  assert.equal(e.doc.querySelector('[data-state="unchecked"]').classList.contains('is-hidden'),false);
  assert.equal(e.doc.querySelector('[data-tld="org"]').classList.contains('is-hidden'),true);
 }finally{e.dom.window.close();}
});
test('only a verified future offer shows original price; expiry preserves nodes and removes the expired buy action',async()=>{
 const realNow=Date.now(),future=new Date(realNow+120000).toISOString();const e=setup(async()=>response([row('brand.com',{is_promotion:true,regular_price_toman:2000000,discount_percent:50,promotion_ends_at:future}),row('brand.net',{primary:false,is_promotion:false,regular_price_toman:9000000,promotion_ends_at:future}),row('brand.org',{primary:false,is_promotion:true,regular_price_toman:9000000,promotion_ends_at:new Date(realNow-1).toISOString()})]));
 try{
  search(e,'brand.com');await settle();assert.equal(e.doc.querySelectorAll('.dsx-price del').length,1);assert.match(e.doc.querySelector('.dsx-offer-badge').textContent,/50/);
  const primary=e.doc.querySelector('[data-tld="com"]'),normal=e.doc.querySelector('[data-tld="net"]'),focused=normal.querySelector('.dsx-buy');focused.focus();
  assert.equal(e.doc.querySelector('[data-tld="org"] .dsx-buy'),null);assert.equal(normal.querySelector('del'),null);
  e.doc.getElementById('dm-load-more').click();assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'paused');
  await e.clock.advance(130000);
  assert.equal(e.doc.querySelectorAll('.dsx-price del').length,0);assert.equal(primary.querySelector('.dsx-buy'),null);assert.equal(primary.dataset.state,'no_price');
  assert.equal(e.doc.querySelector('[data-tld="com"]'),primary);assert.equal(e.doc.querySelector('[data-tld="net"]'),normal);assert.equal(e.doc.activeElement,focused);
 }finally{e.w.close();}
});
test('name suggestions are unchecked text and only query after a click; query prefill runs once',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{calls.push(JSON.parse(opt.body));return response([row()],{suggestion_names:[{name:'brandstudio.com'},{name:'x<script>alert(1)</script>.com'}]});},'en','?q=brand.com');
 try{
  await settle();assert.equal(calls.length,1);assert.equal(e.doc.getElementById('dm-q').value,'brand.com');assert.equal(e.doc.querySelectorAll('#dm-idea-chips button').length,2);assert.equal(e.doc.querySelector('#dm-idea-chips script'),null);assert.equal(e.doc.querySelector('#dm-idea-chips .dsx-pill'),null);
  e.doc.querySelector('#dm-idea-chips button').click();await settle();assert.equal(calls.length,2);assert.equal(calls[1].q,'brandstudio');assert.deepEqual(calls[1].tlds,['com']);
 }finally{e.dom.window.close();}
});
test('a failed automatic lookup stops, preserves resolved nodes, and retries the same suffix only on user action',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);if(calls.length===2)throw new Error('synthetic unavailable');return response([row('brand.'+body.tlds[0],{primary:false})]);},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'brand.com');await settle();const original=e.doc.querySelector('[data-tld="com"]');
  await e.clock.advance(1800);assert.equal(calls.length,2);assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'error');
  assert.equal(e.doc.querySelector('[data-tld="com"]'),original);assert.equal(e.doc.querySelectorAll('.dsx-row').length,1);
  await e.clock.advance(300000);assert.equal(calls.length,2,'failure must not create an automatic retry loop');
  const retry=e.doc.getElementById('dm-load-more');assert.equal(retry.hidden,false);assert.equal(retry.disabled,false);retry.click();await e.clock.advance(0);
  assert.deepEqual(calls[1],calls[2]);assert.equal(e.doc.querySelector('[data-tld="com"]'),original);assert.equal(e.doc.querySelectorAll('.dsx-row').length,2);
  await e.clock.advance(1800);assert.equal(calls[3].tlds[0],'org');assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'done');
 }finally{e.w.close();}
});
test('all locales render translated controls, correct direction, keyboard form, and term-specific prices',async()=>{
 for(const locale of ['fa','en','tr']){
  const e=setup(async()=>response([row('brand.com',{period_years:2,regular_price_toman:3000000,is_promotion:true,promotion_ends_at:new Date(Date.now()+3600000).toISOString()})]),locale);
  try{
   assert.doesNotMatch(e.doc.body.textContent,/domain-discovery\.[a-z_]+|ui\.dsr_/);assert.equal(e.doc.documentElement.dir,locale==='fa'?'rtl':'ltr');assert.equal(e.doc.querySelector('input[name="q"]').getAttribute('aria-describedby'),'dm-search-hint');
   search(e,'brand.com');await settle();assert.match(e.doc.querySelector('.dsx-price small').textContent,locale==='fa'?/۲ ساله/:locale==='tr'?/2 yıllık/:/2-year/);assert.equal(e.doc.querySelectorAll('.dsx-price del').length,1);assert.ok(e.doc.querySelector('style').textContent.includes('prefers-reduced-motion'));
  }finally{e.dom.window.close();}
 }
});

test('legacy search envelope with missing promo and suggestion metadata gets bounded unchecked ASCII ideas',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{calls.push(JSON.parse(opt.body));return response([row('brand.co.uk',{period_years:undefined,is_promotion:undefined,regular_price_toman:undefined})]);});
 try{
  search(e,'brand.co.uk');await settle();assert.equal(calls.length,1);assert.equal(e.doc.querySelector('del'),null);assert.equal(e.doc.querySelector('.dsx-price small').textContent,'€');assert.equal(e.doc.querySelector('.dsx-deal-filter').hidden,true);assert.equal(e.doc.getElementById('dm-deals-count').hidden,true);
  const buttons=[...e.doc.querySelectorAll('#dm-idea-chips button')];assert.ok(buttons.length>0&&buttons.length<=6);assert.ok(buttons.every(b=>b.textContent.endsWith('.co.uk')));assert.equal(e.doc.querySelector('#dm-ideas .dsx-pill'),null);
  buttons[0].click();await settle();assert.equal(calls.length,2);
 }finally{e.w.close();}
});
test('legacy fallback never mutates non-ASCII or punycode identities and respects an explicit empty server list',async()=>{
 for(const [query,metadata] of [['xn--mgbh0fb.com',{}],['نمونه.com',{}],['brand.com',{suggestion_names:[]}],['x.com',{}]]){
  const e=setup(async()=>response([row()],metadata));try{search(e,query);await settle();assert.equal(e.doc.querySelectorAll('#dm-idea-chips button').length,0,query);}finally{e.w.close();}
 }
});


test('registration term is displayed only when explicitly valid; unknown periods show currency without separator',async()=>{
 const cases=[
  {tld:'one',period_years:1,expected:'€ · 1-year registration'},
  {tld:'two',period_years:2,expected:'€ · 2-year registration'},
  {tld:'minimum',period_years:undefined,min_years:2,expected:'€ · 2-year registration'},
  {tld:'missing',period_years:undefined,expected:'€'},
  {tld:'zero',period_years:0,expected:'€'},
  {tld:'negative',period_years:-1,expected:'€'},
  {tld:'fraction',period_years:1.5,expected:'€'},
  {tld:'boolean',period_years:true,expected:'€'},
  {tld:'invalid',period_years:'unknown',expected:'€'}
 ];
 const e=setup(async()=>response(cases.map(c=>row('brand.'+c.tld,{primary:false,period_years:c.period_years,min_years:c.min_years}))));
 try{search(e,'brand.com');await settle();for(const c of cases)assert.equal(e.doc.querySelector('[data-tld="'+c.tld+'"] .dsx-price small').textContent,c.expected,c.tld);}
 finally{e.w.close();}
});

function orderedSuffixes(e) {return [...e.doc.querySelectorAll('#dm-results > .dsx-row')].filter(el=>!el.classList.contains('is-hidden')).map(el=>el.dataset.tld);}
function sortBy(e,value) {const el=e.doc.getElementById('f-sort');el.value=value;el.dispatchEvent(new e.w.Event('change'));}
function cssRules(e) {const out=[];function collect(rules){for(const rule of rules){out.push(rule);if(rule.cssRules)collect(rule.cssRules);}}for(const sheet of e.doc.styleSheets)collect(sheet.cssRules);return out;}

test('progressive arrivals retain live row nodes and entrance animation is limited to new cards',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);return response([row('brand.'+body.tlds[0],{primary:false})]);},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'brand.com');await settle();const first=e.doc.querySelector('[data-tld="com"]');assert.ok(first.classList.contains('dsx-new-row'));
  await e.clock.advance(900);assert.equal(first.classList.contains('dsx-new-row'),false);
  await e.clock.advance(900);const second=e.doc.querySelector('[data-tld="net"]');assert.ok(second.classList.contains('dsx-new-row'));
  assert.equal(e.doc.querySelector('[data-tld="com"]'),first);assert.equal(first.classList.contains('dsx-new-row'),false);
  await e.clock.advance(900);await e.clock.advance(900);
  assert.equal(e.doc.querySelector('[data-tld="com"]'),first);assert.equal(e.doc.querySelector('[data-tld="net"]'),second);
  assert.equal(second.classList.contains('dsx-new-row'),false);assert.ok(e.doc.querySelector('[data-tld="org"]').classList.contains('dsx-new-row'));
  const rules=cssRules(e),entrance=rules.filter(r=>r.selectorText&&r.selectorText.includes('.dsx-new-row')).map(r=>r.style.getPropertyValue('animation')).join(' ');
  const fade=rules.find(r=>r.name&&entrance.includes(r.name)&&r.cssRules&&[...r.cssRules].some(f=>f.style&&f.style.opacity==='0')&&[...r.cssRules].some(f=>f.style&&f.style.opacity==='1'));
  assert.ok(fade,'the real rendered stylesheet must animate new cards from transparent to visible');
  for(const rule of rules.filter(r=>r.selectorText&&r.selectorText.split(',').some(s=>s.trim()==='.dsx-row')))assert.ok(!rule.style.getPropertyValue('animation')||rule.style.getPropertyValue('animation')==='none','ordinary row reordering must not restart entrance animation');
 }finally{e.w.close();}
});

test('price sorting stays correct across arrivals, puts unpriced stale amounts last, and preserves pinned or focused nodes',async()=>{
 const prices={com:9000000,net:1000000,org:3000000,dev:5000000,info:1,biz:999000000,xyz:700000000};
 const extra={info:{state:'no_price',orderable:false},biz:{state:'taken',available:false,orderable:false},xyz:{is_promotion:true,regular_price_toman:900000000,promotion_ends_at:new Date(Date.now()-1).toISOString()}};
 const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body),suffix=body.tlds[0];return response([row('brand.'+suffix,{primary:false,price_toman:prices[suffix],...extra[suffix]})]);},'en','',{tlds:{first:['com','net','org','dev','info','biz','xyz'],rest:[]}});
 try{
  search(e,'brand.com');await settle();const primary=e.doc.querySelector('[data-tld="com"]');
  for(const id of ['f-taken','f-unavail']){const el=e.doc.getElementById(id);el.checked=false;el.dispatchEvent(new e.w.Event('change'));}
  sortBy(e,'price');await e.clock.advance(1800);const net=e.doc.querySelector('[data-tld="net"]'),focused=net.querySelector('.dsx-buy');focused.focus();
  await e.clock.advance(1800);assert.deepEqual(orderedSuffixes(e),['com','net','org']);assert.equal(e.doc.querySelector('[data-tld="net"]'),net);assert.equal(e.doc.activeElement,focused,'arriving results must not steal focus');
  sortBy(e,'-price');assert.deepEqual(orderedSuffixes(e),['com','org','net']);await e.clock.advance(1800);assert.deepEqual(orderedSuffixes(e),['com','dev','org','net']);
  for(let i=0;i<3;i++){await e.clock.advance(1800);assert.deepEqual(orderedSuffixes(e).slice(0,4),['com','dev','org','net']);}
  assert.deepEqual(new Set(orderedSuffixes(e).slice(4)),new Set(['info','biz','xyz']));
  assert.equal(e.doc.querySelector('[data-tld="com"]'),primary);assert.equal(e.doc.querySelector('[data-tld="net"]'),net);
  sortBy(e,'price');assert.deepEqual(orderedSuffixes(e).slice(0,4),['com','net','org','dev']);assert.deepEqual(new Set(orderedSuffixes(e).slice(4)),new Set(['info','biz','xyz']));
 }finally{e.w.close();}
});

test('a new search cancels the old automatic queue and stale work cannot resume its suffixes',async()=>{
 const calls=[],pending=[];const e=setup((_url,opt)=>{calls.push({body:JSON.parse(opt.body),signal:opt.signal});return new Promise(resolve=>pending.push(resolve));},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'old.com');pending.shift()(response([row('old.com',{primary:false})]));await settle();await e.clock.advance(1800);assert.equal(calls[1].body.q,'old');
  search(e,'new.org');assert.equal(calls[1].signal.aborted,true);assert.deepEqual(calls[2].body,{q:'new',tlds:['org']});
  pending.shift()(response([row('old.net',{primary:false})]));await settle();await e.clock.advance(30000);
  assert.equal(calls.length,3,'neither the cancelled queue nor a pending new first request may fan out');assert.equal(e.doc.querySelectorAll('.dsx-row').length,0);
  assert.equal(e.doc.getElementById('dm-results').getAttribute('aria-busy'),'true');
  pending.shift()(response([row('new.org',{primary:false})]));await settle();assert.deepEqual(orderedSuffixes(e),['org']);await e.clock.advance(1800);
  assert.deepEqual(calls[3].body,{q:'new',tlds:['com']});assert.equal(e.doc.querySelector('[data-tld="net"]'),null);
 }finally{e.w.close();}
});

test('429 honors Retry-After, keeps resolved results, and never retries automatically when cooldown ends',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);return calls.length===2?{ok:false,status:429,headers:{get:name=>name.toLowerCase()==='retry-after'?'5':null}}:response([row('brand.'+body.tlds[0],{primary:false})]);},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'brand.com');await settle();const original=e.doc.querySelector('[data-tld="com"]');await e.clock.advance(1800);assert.equal(calls.length,2);assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'error');
  const retry=e.doc.getElementById('dm-load-more');retry.click();await settle();assert.equal(calls.length,2);await e.clock.advance(4999);retry.click();await settle();assert.equal(calls.length,2);
  await e.clock.advance(6);assert.equal(calls.length,2,'cooldown is not consent to a provider retry');assert.equal(retry.disabled,false);retry.click();await e.clock.advance(0);
  assert.equal(calls.length,3);assert.deepEqual(calls[1],calls[2]);assert.equal(e.doc.querySelector('[data-tld="com"]'),original);assert.equal(e.doc.querySelector('[data-tld="net"]').dataset.state,'free');
 }finally{e.w.close();}
});

test('provider lookup failure stays unknown, stops automatic discovery, and explicit retry updates only that row',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);return calls.length===2?response([row('brand.net',{primary:false,state:'unchecked',status:'unknown',available:false,orderable:false})],{lookup_ok:false}):response([row('brand.'+body.tlds[0],{primary:false})]);},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'brand.com');await settle();const original=e.doc.querySelector('[data-tld="com"]');await e.clock.advance(1800);const unknown=e.doc.querySelector('[data-tld="net"]');
  assert.equal(unknown.dataset.state,'unchecked');assert.equal(unknown.querySelector('.dsx-buy'),null);assert.equal(e.doc.getElementById('dm-warn').hidden,false);assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'error');
  await e.clock.advance(100000);assert.equal(calls.length,2);e.doc.getElementById('dm-load-more').click();await e.clock.advance(0);
  assert.deepEqual(calls[1],calls[2]);assert.equal(e.doc.querySelector('[data-tld="com"]'),original);assert.equal(e.doc.querySelector('[data-tld="net"]'),unknown);assert.equal(unknown.dataset.state,'free');assert.ok(unknown.querySelector('.dsx-buy'));
 }finally{e.w.close();}
});

test('pause/resume controls and hidden tabs hold the next suffix without aborting or overlapping current work',async()=>{
 const calls=[],pending=[];const e=setup((_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);return new Promise(resolve=>pending.push(resolve));},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'brand.com');pending.shift()(response([row('brand.com',{primary:false})]));await settle();const toggle=e.doc.getElementById('dm-load-more');
  toggle.click();assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'paused');await e.clock.advance(60000);assert.equal(calls.length,1);
  toggle.click();await settle();if(calls.length===1)await e.clock.advance(1800);assert.equal(calls.length,2);
  e.clock.setHidden(true);pending.shift()(response([row('brand.net',{primary:false})]));await settle();await e.clock.advance(60000);assert.equal(calls.length,2);assert.equal(e.doc.querySelectorAll('.dsx-row').length,2);
  e.clock.setHidden(false);await settle();if(calls.length===2)await e.clock.advance(1800);assert.equal(calls.length,3);assert.deepEqual(calls[2].tlds,['org']);
  pending.shift()(response([row('brand.org',{primary:false})]));await settle();assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'done');
 }finally{e.w.close();}
});

test('reduced motion disables card animation and smooth scrolling without disabling automatic results',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);return response([row('brand.'+body.tlds[0],{primary:false})]);},'en','',{reducedMotion:true,tlds:{first:['com','net'],rest:[]}});
 try{
  search(e,'brand.com');await settle();assert.ok(e.scrolls.length);assert.ok(e.scrolls.every(s=>s.options.behavior==='auto'));
  const reduced=cssRules(e).filter(r=>String(r.conditionText||(r.media&&r.media.mediaText)||'').replace(/\s/g,'').includes('prefers-reduced-motion:reduce'));
  assert.ok(reduced.some(r=>[...r.cssRules].some(rule=>rule.selectorText&&rule.selectorText.includes('.dsx *')&&rule.style.getPropertyValue('animation')==='none')),'rendered reduced-motion media rule must suppress the row animation');
  await e.clock.advance(1800);assert.equal(calls.length,2);assert.equal(e.doc.querySelectorAll('.dsx-row').length,2);
 }finally{e.w.close();}
});


test('URL, case and multi-label suffix normalization send the exact bare stem once and ignore bogus primary flags',async()=>{
 const examples=[['HTTPS://WWW.Brand.CO.UK/path?ref=1#part','brand','co.uk'],[' Brand.CoM. ','brand','com'],['https://Brand.NET/a','brand','net'],['BRAND','brand','com']];
 for(const [query,stem,suffix] of examples){
  const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);return response([row(stem+'.wrong',{primary:true}),row(stem+'.'+body.tlds[0],{primary:false})]);},'en','',{tlds:{first:['com','net'],rest:[['co.uk']]}});
  try{
   search(e,query);await settle();assert.deepEqual(calls[0],{q:stem,tlds:[suffix]},query);
   const pinned=e.doc.querySelectorAll('[data-primary]');assert.equal(pinned.length,1,query);assert.equal(pinned[0].dataset.tld,suffix,query);assert.equal(e.doc.querySelector('[data-tld="wrong"]').hasAttribute('data-primary'),false);
   await e.clock.advance(1800);assert.equal(calls.length,2);assert.ok(calls.every(c=>c.q===stem&&c.tlds.length===1));assert.equal(e.doc.querySelectorAll('[data-primary]').length,1);
  }finally{e.w.close();}
 }
});


test('transport timeout aborts the active suffix, releases loading, and permits only a manual retry',async()=>{
 const calls=[];let active=0,maxActive=0;
 const e=setup((_url,opt)=>{const body=JSON.parse(opt.body);calls.push({body,signal:opt.signal});active++;maxActive=Math.max(maxActive,active);if(calls.length>1){active--;return Promise.resolve(response([row('brand.'+body.tlds[0],{primary:false})]));}return new Promise((_resolve,reject)=>opt.signal.addEventListener('abort',()=>{active--;reject(new e.w.DOMException('Synthetic aborted lookup','AbortError'));},{once:true}));},'en','',{tlds:{first:['com','net'],rest:[]}});
 try{
  search(e,'brand.com');await e.clock.advance(34999);assert.equal(calls.length,1);assert.equal(calls[0].signal.aborted,false);
  await e.clock.advance(1);assert.equal(calls[0].signal.aborted,true);assert.equal(active,0);assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'error');assert.equal(e.doc.getElementById('dm-results').getAttribute('aria-busy'),'false');
  await e.clock.advance(100000);assert.equal(calls.length,1);e.doc.getElementById('dm-load-more').click();await e.clock.advance(0);
  assert.equal(calls.length,2);assert.deepEqual(calls[0].body,calls[1].body);assert.equal(maxActive,1);assert.equal(e.doc.querySelector('[data-tld="com"]').dataset.state,'free');
 }finally{e.w.close();}
});

test('a late successful response after its timeout is never accepted as a confirmed result',async()=>{
 const pending=[],calls=[];const e=setup((_url,opt)=>{calls.push(opt);return new Promise(resolve=>pending.push(resolve));},'en','',{tlds:{first:['com','net'],rest:[]}});
 try{
  search(e,'brand.com');await e.clock.advance(35000);assert.equal(calls[0].signal.aborted,true);
  pending.shift()(response([row('brand.com')]));await settle();assert.equal(e.doc.querySelectorAll('.dsx-row').length,0);assert.equal(e.doc.getElementById('dm-load-wrap').dataset.state,'error');
  await e.clock.advance(100000);assert.equal(calls.length,1);assert.equal(e.doc.getElementById('dm-results').getAttribute('aria-busy'),'false');
 }finally{e.w.close();}
});

test('invalid replacement input cancels pending old work and keeps the already resolved cards intact',async()=>{
 const pending=[],calls=[];const e=setup((_url,opt)=>{calls.push(opt);return new Promise(resolve=>pending.push(resolve));},'en','',{tlds:{first:['com','net','org'],rest:[]}});
 try{
  search(e,'brand.com');pending.shift()(response([row('brand.com')]));await settle();const original=e.doc.querySelector('[data-tld="com"]');await e.clock.advance(1800);assert.equal(calls.length,2);
  search(e,'!!!');assert.equal(calls[1].signal.aborted,true);assert.equal(e.doc.getElementById('dm-error').hidden,false);assert.equal(e.doc.getElementById('dm-results').getAttribute('aria-busy'),'false');
  pending.shift()(response([row('brand.net',{primary:false})]));await settle();await e.clock.advance(100000);
  assert.equal(calls.length,2);assert.equal(e.doc.querySelectorAll('.dsx-row').length,1);assert.equal(e.doc.querySelector('[data-tld="com"]'),original);assert.notEqual(e.doc.getElementById('dm-load-wrap').dataset.state,'running');
 }finally{e.w.close();}
});

test('long numeric and HTTP-date Retry-After windows are not truncated to a shorter client cooldown',async()=>{
 for(const kind of ['seconds','http-date']){
  const calls=[];const e=setup(async(_url,opt)=>{const body=JSON.parse(opt.body);calls.push(body);const header=kind==='seconds'?'600':new Date(e.clock.now()+600000).toUTCString();return calls.length===2?{ok:false,status:429,headers:{get:()=>header}}:response([row('brand.'+body.tlds[0],{primary:false})]);},'en','',{tlds:{first:['com','net'],rest:[]}});
  try{
   search(e,'brand.com');await settle();await e.clock.advance(1800);const retry=e.doc.getElementById('dm-load-more');
   await e.clock.advance(300000);retry.click();await e.clock.advance(0);assert.equal(calls.length,2,kind+' must preserve the full upstream cooldown');
   await e.clock.advance(300010);assert.equal(calls.length,2);retry.click();await e.clock.advance(0);assert.equal(calls.length,3);assert.deepEqual(calls[1],calls[2]);
  }finally{e.w.close();}
 }
});


test('duplicate normalized submits reuse the running or hidden lookup while a changed query cancels it',async()=>{
 const calls=[];const e=setup((_url,opt)=>{calls.push({body:JSON.parse(opt.body),signal:opt.signal});return new Promise(()=>{});},'en','',{tlds:{first:['com','net'],rest:[]}});
 try{
  search(e,'brand.com');search(e,'https://BRAND.com/');assert.equal(calls.length,1);assert.equal(calls[0].signal.aborted,false);
  e.clock.setHidden(true);search(e,'Brand.COM');assert.equal(calls.length,1);assert.equal(calls[0].signal.aborted,false);
  e.clock.setHidden(false);search(e,'new.net');assert.equal(calls.length,2);assert.equal(calls[0].signal.aborted,true);assert.deepEqual(calls[1].body,{q:'new',tlds:['net']});
 }finally{e.w.close();}
});
