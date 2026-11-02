const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/site.js'), 'utf8');
const code = source.slice(source.indexOf('  const dForm ='), source.indexOf('  /* ---- BILLING TOGGLE ---- */'));
const tick = () => new Promise(resolve => setImmediate(resolve));
function setup(fetcher) {
  const dom = new JSDOM('<html lang="en"><head><meta name="csrf-token" content="synthetic"></head><body><form id="domain-form" action="/en/domains" data-endpoint="/en/domain-check"><input id="domain-input" value="example.com" data-suggestions-url="/en/domains" data-i18n-ideas="Discover alternatives" data-i18n-cart="Add to cart" data-i18n-free="Available" data-i18n-discount=":percent% off" data-i18n-regular="Regular price" data-i18n-error="Try again"></form><div id="domain-result" hidden></div></body></html>', {url:'https://servernet.cloud/en',runScripts:'outside-only'});
  dom.window.fetch = fetcher;
  dom.window.eval('(function(){const faDigits=s=>String(s);'+code+'})();');
  return {dom, w:dom.window, doc:dom.window.document};
}
function submit(e, term='example.com') {
  e.doc.getElementById('domain-input').value=term;
  e.doc.getElementById('domain-form').dispatchEvent(new e.w.Event('submit',{cancelable:true}));
}
const response = extra => ({ok:true,json:async()=>({result:{domain:'example.com',state:'free',available:true,price:'€10.00',cart_url:'/en/account/domains/checkout/42',...extra},more_url:'/en/domains?q=example.com'})});
test('available result has cart and a separate same-query suggestions link',async()=>{
  const e=setup(async()=>response());try{submit(e);await tick();await tick();
    assert.ok(e.doc.querySelector('a[href="/en/account/domains/checkout/42"]'));
    assert.equal(e.doc.querySelector('.dr-ideas').href,'https://servernet.cloud/en/domains?q=example.com');
    assert.equal(e.doc.getElementById('domain-result').getAttribute('aria-busy'),'false');
  }finally{e.w.close();}
});
test('only a verified, unexpired promotion shows reference and saving',async()=>{
  let payload={is_promotion:true,regular_price:'€20.00',discount_percent:50,promotion_ends_at:new Date(Date.now()+60000).toISOString()};
  const e=setup(async()=>response(payload));try{submit(e);await tick();await tick();
    assert.equal(e.doc.querySelector('del').textContent,'€20.00');
    assert.equal(e.doc.querySelector('.dr-discount').textContent,'50% off');
    payload={...payload,promotion_ends_at:new Date(Date.now()-1000).toISOString()};submit(e);await tick();await tick();
    assert.equal(e.doc.querySelector('del'),null);
    assert.equal(e.doc.querySelector('a[href="/en/account/domains/checkout/42"]'),null);
  }finally{e.w.close();}
});
test('a late old response cannot replace the newer search or its link',async()=>{
  const pending=[];const e=setup((_url,opts)=>new Promise(resolve=>pending.push({resolve,opts})));
  try{submit(e,'old.com');submit(e,'new.com');assert.equal(pending[0].opts.signal.aborted,true);
    pending[1].resolve({ok:true,json:async()=>({result:{domain:'new.com',state:'taken'},more_url:'/en/domains?q=new.com'})});await tick();await tick();
    pending[0].resolve(response());await tick();await tick();
    assert.ok(e.doc.getElementById('domain-result').textContent.includes('new.com'));
    assert.ok(e.doc.querySelector('.dr-ideas').href.endsWith('q=new.com'));
  }finally{e.w.close();}
});
