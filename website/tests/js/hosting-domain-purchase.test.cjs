const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const fixtures = process.env.HOSTING_DOMAIN_FIXTURES;
if (!fixtures) throw new Error('HOSTING_DOMAIN_FIXTURES must point to the real rendered Blade fixtures');
const settle = async () => { await new Promise(resolve=>setImmediate(resolve)); await new Promise(resolve=>setImmediate(resolve)); };
function setup(fetch, locale='en') {
 const partial = fs.readFileSync(path.join(fixtures,`hosting-domain-${locale}.html`),'utf8');
 const d = new JSDOM(`<form id="co-form"><input name="_token" value="synthetic-csrf"><input type="radio" name="domain_mode" value="have" checked><input type="radio" name="domain_mode" value="buy"><input name="domain_buy">${partial}<button type="submit">Continue</button></form>`,{url:`https://console.servernet.cloud/${locale==='fa'?'':locale+'/'}account/order/synthetic`,runScripts:'outside-only'});
 const w=d.window,doc=w.document; Object.defineProperty(doc,'readyState',{value:'complete',configurable:true});w.fetch=fetch;
 const totals=[];doc.getElementById('co-form').addEventListener('servernet:hosting-domain-price',e=>totals.push(e.detail.total_toman));
 for(const script of doc.scripts) w.eval(script.textContent);
 return {w,doc,totals,input:doc.querySelector('[name="domain_buy"]'),submit:doc.querySelector('[type="submit"]'),check:doc.getElementById('hosting-domain-check'),id:doc.getElementById('hosting-domain-quote-id')};
}
function mode(e,value) { const r=e.doc.querySelector(`[name="domain_mode"][value="${value}"]`);r.checked=true;r.dispatchEvent(new e.w.Event('change')); }
function input(e,name) {e.input.value=name;e.input.dispatchEvent(new e.w.Event('input'));}
const quote = name=>({ok:true,json:async()=>({ok:true,domain:name,quote_id:22,price_toman:1000000,tax_toman:100000,total_toman:1100000,period_years:1,expires_at:new Date(Date.now()+900000).toISOString()})});
test('every language uses its protected same-origin endpoint and adds the tax-inclusive quote only in buy mode',async()=>{
 for(const locale of ['fa','en','tr']){
  const calls=[];const e=setup(async(url,o)=>{calls.push({url,o});return quote(JSON.parse(o.body).domain);},locale);
  try{
   assert.equal(e.submit.disabled,false);mode(e,'buy');input(e,'sample.com');assert.equal(e.submit.disabled,true);
   e.check.click();await settle();assert.equal(calls.length,1);
   assert.equal(calls[0].url,(locale==='fa'?'':'/'+locale)+'/account/order-domain-quote');
   assert.equal(calls[0].o.headers['X-CSRF-TOKEN'],'synthetic-csrf');
   assert.equal(e.id.value,'22');assert.equal(e.submit.disabled,false);assert.equal(e.totals.at(-1),1100000);
   assert.doesNotMatch(e.doc.getElementById('hosting-domain-status').textContent,/hosting-domains\.|:price|:tax|:years/);
   mode(e,'have');assert.equal(e.totals.at(-1),0);assert.equal(e.id.value,'');assert.equal(e.submit.disabled,false);
   mode(e,'buy');assert.equal(e.id.value,'22');assert.equal(e.totals.at(-1),1100000);
  }finally{e.w.close();}
 }
});
test('a stale response cannot restore a quote after the name changes',async()=>{
 const pending=[];const e=setup((_url,o)=>new Promise(resolve=>pending.push({o,resolve})));
 try{
  mode(e,'buy');input(e,'old.com');e.check.click();input(e,'new.com');assert.equal(pending[0].o.signal.aborted,true);
  e.check.click();pending[0].resolve(quote('old.com'));await settle();assert.equal(e.id.value,'');assert.equal(e.submit.disabled,true);
  pending[1].resolve(quote('new.com'));await settle();assert.equal(e.id.value,'22');assert.equal(e.submit.disabled,false);
 }finally{e.w.close();}
});
test('switching to an owned domain aborts a lookup and leaves hosting-only checkout enabled',async()=>{
 let pending;const e=setup((_url,o)=>new Promise(resolve=>{pending={o,resolve};}));
 try{
  mode(e,'buy');input(e,'sample.com');e.check.click();mode(e,'have');assert.equal(pending.o.signal.aborted,true);
  pending.resolve(quote('sample.com'));await settle();assert.equal(e.id.value,'');assert.equal(e.submit.disabled,false);assert.equal(e.totals.at(-1),0);
 }finally{e.w.close();}
});
test('failed or expired quotes never enable the domain purchase',async()=>{
 for(const response of [{ok:false,json:async()=>({ok:false})},{ok:true,json:async()=>({...await quote('sample.com').json(),expires_at:new Date(Date.now()-1).toISOString()})}]){
  const e=setup(async()=>response);
  try{mode(e,'buy');input(e,'sample.com');e.check.click();await settle();assert.equal(e.id.value,'');assert.equal(e.submit.disabled,true);assert.equal(e.totals.at(-1),0);}
  finally{e.w.close();}
 }
});
