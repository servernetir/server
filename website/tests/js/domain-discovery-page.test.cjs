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
function setup(fetcher,locale='en',query='') {
 const dom = new JSDOM('<!doctype html><html lang="'+locale+'" dir="'+(locale==='fa'?'rtl':'ltr')+'"><head><meta name="csrf-token" content="fixture"></head><body>'+fs.readFileSync(path.join(fixtures,`partial-${locale}.html`),'utf8')+'</body></html>',{url:'https://servernet.cloud/'+(locale==='fa'?'':locale+'/')+'domains'+query,runScripts:'outside-only',pretendToBeVisual:true});
 const w = dom.window; w.fetch = fetcher;
 // Both scripts come from the real compiled Blade view (translations and behavior).
 for (const script of w.document.querySelectorAll('script')) w.eval(script.textContent);
 return {dom,w,doc:w.document};
}
function search(env,term) {env.doc.getElementById('dm-q').value=term;env.doc.getElementById('dm-form').dispatchEvent(new env.w.Event('submit',{cancelable:true}));}
test('first search is one bounded batch; each load-more click adds one batch and preserves primary', async()=>{
 const calls=[]; const e=setup(async (_url,opt)=>{const data=JSON.parse(opt.body);calls.push(data);return response(calls.length===1?[row()]:[row('brand.'+data.tlds[0],{primary:false})]);});
 try {
  search(e,'brand.com');await settle();assert.equal(calls.length,1);assert.ok(calls[0].tlds.length<=10);
  assert.equal(e.doc.getElementById('dm-more').hidden,true);assert.equal(e.doc.getElementById('dm-load-more').hidden,false);
  e.doc.getElementById('dm-load-more').click();await settle();assert.equal(calls.length,2);assert.ok(calls[1].tlds.length<=10);
  assert.equal(e.doc.querySelectorAll('.dsx-row').length,2);assert.equal(e.doc.querySelector('.dsx-row').dataset.primary,'1');
  assert.match(e.doc.querySelector('.dsx-row').textContent,/brand\.com/);
 }finally{e.dom.window.close();}
});
test('stale responses cannot replace new results or clear a newer loading state', async()=>{
 const pending=[];const e=setup((_url,opt)=>new Promise(resolve=>pending.push({resolve,opt})));
 try{
  search(e,'old.com');search(e,'new.com');assert.equal(pending.length,2);assert.equal(pending[0].opt.signal.aborted,true);
  pending[0].resolve(response([row('old.com')]));await settle();assert.equal(e.doc.getElementById('dm-go').disabled,true);assert.equal(e.doc.querySelectorAll('.dsx-row').length,0);
  pending[1].resolve(response([row('new.com',{primary:true})]));await settle();assert.equal(e.doc.getElementById('dm-go').disabled,false);assert.equal(e.doc.querySelectorAll('.dsx-row').length,1);assert.match(e.doc.querySelector('.dsx-row').textContent,/new\.com/);
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
test('only a verified future offer shows original price; expiry removes old price and buy action',async()=>{
 const realNow=Date.now(),future=new Date(realNow+120000).toISOString();const e=setup(async()=>response([row('brand.com',{is_promotion:true,regular_price_toman:2000000,discount_percent:50,promotion_ends_at:future}),row('bad.net',{primary:false,is_promotion:false,regular_price_toman:9000000,promotion_ends_at:future}),row('old.org',{primary:false,is_promotion:true,regular_price_toman:9000000,promotion_ends_at:new Date(realNow-1).toISOString()})]));
 const scheduled=[];e.w.setTimeout=(fn,ms)=>{scheduled.push({fn,ms});return scheduled.length;};e.w.clearTimeout=()=>{};
 try{
  search(e,'brand.com');await settle();assert.equal(e.doc.querySelectorAll('.dsx-price del').length,1);assert.match(e.doc.querySelector('.dsx-offer-badge').textContent,/50/);
  assert.equal(e.doc.querySelector('[data-tld="org"] .dsx-buy'),null);assert.equal(e.doc.querySelector('[data-tld="net"] del'),null);
  assert.equal(scheduled.length,1);e.w.Date.now=()=>realNow+130000;scheduled[0].fn();
  assert.equal(e.doc.querySelectorAll('.dsx-price del').length,0);assert.equal(e.doc.querySelector('[data-primary] .dsx-buy'),null);assert.equal(e.doc.querySelector('[data-primary]').dataset.state,'no_price');
 }finally{e.dom.window.close();}
});
test('name suggestions are unchecked text and only query after a click; query prefill runs once',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{calls.push(JSON.parse(opt.body));return response([row()],{suggestion_names:[{name:'brandstudio.com'},{name:'x<script>alert(1)</script>.com'}]});},'en','?q=brand.com');
 try{
  await settle();assert.equal(calls.length,1);assert.equal(e.doc.getElementById('dm-q').value,'brand.com');assert.equal(e.doc.querySelectorAll('#dm-idea-chips button').length,2);assert.equal(e.doc.querySelector('#dm-idea-chips script'),null);assert.equal(e.doc.querySelector('#dm-idea-chips .dsx-pill'),null);
  e.doc.querySelector('#dm-idea-chips button').click();await settle();assert.equal(calls.length,2);assert.equal(calls[1].q,'brandstudio.com');
 }finally{e.dom.window.close();}
});
test('failed load-more preserves current rows and can be retried without skipping a batch',async()=>{
 const calls=[];const e=setup(async(_url,opt)=>{calls.push(JSON.parse(opt.body));if(calls.length===2)throw new Error('synthetic unavailable');return response(calls.length===1?[row()]:[row('brand.net',{primary:false})]);});
 try{
  search(e,'brand.com');await settle();e.doc.getElementById('dm-load-more').click();await settle();assert.equal(e.doc.querySelectorAll('.dsx-row').length,1);assert.equal(e.doc.getElementById('dm-more').hidden,true);assert.equal(e.doc.getElementById('dm-load-more').disabled,false);
  e.doc.getElementById('dm-load-more').click();await settle();assert.deepEqual(calls[1].tlds,calls[2].tlds);assert.equal(e.doc.querySelectorAll('.dsx-row').length,2);
 }finally{e.dom.window.close();}
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
