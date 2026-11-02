const {test, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname,'../..');
const supplied = process.env.DOMAIN_DISCOVERY_FIXTURES;
const fixtures = supplied || fs.mkdtempSync(path.join(os.tmpdir(),'servernet-domain-home-test-'));
if (!supplied) for (const locale of ['fa','en','tr']) execFileSync(process.env.PHP_BINARY || 'php',[path.join(root,'tests/Fixtures/DomainDiscoveryPreview.php'),locale,fixtures]);
after(()=>{if(!supplied)fs.rmSync(fixtures,{recursive:true,force:true});});
const code = fs.readFileSync(path.join(root,'public/assets/js/domain-home-discovery.js'),'utf8');
const shared = fs.readFileSync(path.join(root,'public/assets/js/site.js'),'utf8');
const legacy = shared.slice(shared.indexOf('  /* ---- TLD CHIPS:'),shared.indexOf('  /* ---- BILLING TOGGLE ---- */'));
const tick = ()=>new Promise(resolve=>setImmediate(resolve));
const settle = async()=>{await tick();await tick();};
function setup(fetcher,locale='en') {
 const dom=new JSDOM('<!doctype html><html lang="'+locale+'" dir="'+(locale==='fa'?'rtl':'ltr')+'"><head><meta name="csrf-token" content="synthetic"></head><body>'+fs.readFileSync(path.join(fixtures,'home-partial-'+locale+'.html'),'utf8')+'</body></html>',{url:'https://servernet.cloud/'+(locale==='fa'?'':locale+'/'),runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window;w.fetch=fetcher;w.eval('(function(){const faDigits=s=>String(s);'+legacy+'})();');w.eval(code);
 return{dom,w,doc:w.document};
}
function submit(e,term='brand.com'){e.doc.getElementById('discovery-domain-input').value=term;e.doc.getElementById('discovery-domain-form').dispatchEvent(new e.w.Event('submit',{cancelable:true}));}
const response=(extra={},top={})=>({ok:true,json:async()=>({result:{domain:'brand.com',state:'free',available:true,price:'€12.50',cart_url:'/en/domains?q=brand.com',...extra},suggestions:[],lookup_ok:true,...top})});
test('legacy envelope works without any promo fields; shared script and duplicate module do not double-submit',async()=>{
 const calls=[];const e=setup(async(_u,opt)=>{calls.push(JSON.parse(opt.body));return response();});
 try{
  e.w.eval(code);submit(e);await settle();assert.equal(calls.length,1);assert.deepEqual(calls[0],{domain:'brand.com'});
  assert.equal(e.doc.getElementById('domain-form'),null);assert.ok(e.doc.querySelector('.ddh-cart'));assert.equal(e.doc.querySelector('del'),null);
  assert.equal(e.doc.querySelector('.ddh-cart').href,'https://servernet.cloud/en/domains?q=brand.com');
  assert.equal(e.doc.querySelector('.ddh-ideas').href,'https://servernet.cloud/en/domains?q=brand.com');
  assert.equal(e.doc.getElementById('discovery-domain-result').getAttribute('aria-busy'),'false');
 }finally{e.w.close();}
});
test('suggestions CTA preserves captured exact submitted FQDN, ignoring legacy more_url and later typing',async()=>{
 let finish;const e=setup(()=>new Promise(resolve=>finish=resolve));
 try{
  submit(e,'brand.co.uk');e.doc.getElementById('discovery-domain-input').value='different.com';
  finish(response({domain:'brand.co.uk',cart_url:'/en/account/domains?register=brand.co.uk'},{more_url:'/en/domains?q=brand'}));await settle();
  assert.equal(new URL(e.doc.querySelector('.ddh-ideas').href).searchParams.get('q'),'brand.co.uk');
  assert.equal(e.doc.querySelector('.ddh-cart').href,'https://servernet.cloud/en/account/domains?register=brand.co.uk');
 }finally{e.w.close();}
});
test('old response cannot overwrite new result or turn off new spinner',async()=>{
 const pending=[];const e=setup((_url,opt)=>new Promise(resolve=>pending.push({resolve,opt})));
 try{
  submit(e,'old.com');submit(e,'new.net');assert.equal(pending[0].opt.signal.aborted,true);
  pending[0].resolve(response({domain:'old.com'}));await settle();assert.equal(e.doc.getElementById('discovery-domain-result').getAttribute('aria-busy'),'true');
  pending[1].resolve(response({domain:'new.net',cart_url:'/en/domains?q=new.net'}));await settle();
  assert.equal(e.doc.querySelector('.dr-domain').textContent,'new.net');assert.equal(new URL(e.doc.querySelector('.ddh-ideas').href).searchParams.get('q'),'new.net');
 }finally{e.w.close();}
});
test('local suffix chip fills the new input without submitting or inventing a brand',async()=>{
 let calls=0;const e=setup(async()=>{calls++;return response();});
 try{
  const input=e.doc.getElementById('discovery-domain-input');input.value='brand.co.uk';
  e.doc.querySelector('#discovery-tld-strip [data-tld=".net"]').click();assert.equal(input.value,'brand.net');assert.equal(calls,0);
  input.value='';e.doc.querySelector('#discovery-tld-strip [data-tld=".com"]').click();assert.equal(input.value,'');assert.equal(calls,0);
 }finally{e.w.close();}
});
test('unavailable legacy response keeps supplied suggestion URLs and adds a separate exact-query ideas CTA',async()=>{
 const e=setup(async()=>response({state:'taken',available:false,price:null},{suggestions:[{domain:'brand.net',price:'€9.00',cart_url:'/en/account/domains?register=brand.net'}]}));
 try{submit(e);await settle();assert.equal(e.doc.querySelector('.ddh-cart'),null);assert.equal(e.doc.querySelector('.dr-alt').href,'https://servernet.cloud/en/account/domains?register=brand.net');assert.ok(e.doc.querySelector('.ddh-ideas'));}
 finally{e.w.close();}
});
test('transport failure keeps discovery action and never labels domain taken; invalid URLs are not actionable',async()=>{
 let fail=true;const e=setup(async()=>{if(fail)throw new Error('synthetic unavailable');return response({cart_url:'javascript:alert(1)'});});
 try{submit(e);await settle();assert.ok(e.doc.querySelector('.ddh-ideas'));assert.equal(e.doc.getElementById('discovery-domain-result').className,'domain-result warn');fail=false;submit(e);await settle();assert.equal(e.doc.querySelector('.ddh-cart'),null);assert.equal(e.doc.querySelector('a[href^="javascript:"]'),null);}
 finally{e.w.close();}
});
test('only verified future promo reference renders and expiry disables the cart',async()=>{
 const now=Date.now();const data={is_promotion:true,regular_price:'€25.00',discount_percent:50,promotion_ends_at:new Date(now+60000).toISOString()};
 const e=setup(async()=>response(data));const timers=[];e.w.setTimeout=(fn,delay)=>{timers.push({fn,delay});return timers.length;};e.w.clearTimeout=()=>{};
 try{submit(e);await settle();assert.equal(e.doc.querySelector('del').textContent,'€25.00');assert.equal(e.doc.querySelector('.ddh-discount').textContent,'50% off');e.w.Date.now=()=>now+70000;timers[0].fn();assert.equal(e.doc.querySelector('.ddh-cart'),null);assert.equal(e.doc.querySelector('del'),null);assert.ok(e.doc.querySelector('.ddh-ideas'));}
 finally{e.w.close();}
});
test('all locales render real labels and localized routes with no undeclared backend dependency',async()=>{
 for(const locale of ['fa','en','tr']){const e=setup(async()=>response(),locale);try{assert.doesNotMatch(e.doc.body.textContent,/domain-discovery\.|ui\.dsr_/);submit(e,'brand.dev');await settle();assert.equal(new URL(e.doc.querySelector('.ddh-ideas').href).pathname,locale==='fa'?'/domains':'/'+locale+'/domains');assert.equal(e.doc.querySelector('input').dir,'ltr');assert.ok(e.doc.querySelector('.ddh-ideas').textContent.trim());}finally{e.w.close();}}
});

test('an incomplete legacy response remains unchecked rather than claiming the name is taken',async()=>{
 const e=setup(async()=>({ok:true,json:async()=>({result:{domain:'brand.com'},suggestions:[]})}));
 try{submit(e);await settle();assert.equal(e.doc.getElementById('discovery-domain-result').className,'domain-result warn');assert.equal(e.doc.querySelector('.ddh-cart'),null);assert.ok(e.doc.querySelector('.ddh-ideas'));}
 finally{e.w.close();}
});
