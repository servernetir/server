const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const view = fs.readFileSync(path.join(__dirname, '../../resources/views/pages/cloud.blade.php'), 'utf8');
const script = view.slice(view.lastIndexOf('<script>') + '<script>'.length, view.lastIndexOf('</script>'));

function setup(locale = 'en', run = true) {
  const rows = Array.from({length:123}, (_, index) => {
    const n = index + 1;
    return `<tr id="r${n}" data-row="1" data-c="${n <= 60 ? 'DE' : 'FI'}" data-v="${n % 4 + 1}" data-r="${n % 2 ? 1024 : 2048}" data-p="${n * 1000}"><td><a href="/buy?plan=${n}">Plan ${n}</a></td></tr>`;
  }).join('');
  const dom = new JSDOM(`<!doctype html><html lang="${locale}" dir="${locale === 'fa' ? 'rtl' : 'ltr'}"><body>
    <h2 id="clp-results-title" tabindex="-1">All plans</h2><div id="clp"><select id="clp-country"><option value="">All</option><option>DE</option><option>FI</option><option>ZZ</option></select>
    <select id="clp-cpu"><option value="">All</option><option>4</option></select>
    <select id="clp-ram"><option value="">All</option><option>2048</option></select>
    <select id="clp-sort"><option value="price-asc">Price asc</option><option value="price-desc">Price desc</option><option value="cpu">CPU</option><option value="ram">RAM</option></select>
    <span id="clp-count" data-tpl=":n matching plans"></span><p id="clp-scroll-hint">Scroll horizontally</p><div class="cvps-tw" tabindex="0" role="region" aria-labelledby="clp-results-title" aria-describedby="clp-scroll-hint"><table><tbody id="clp-body">${rows}</tbody></table></div><p id="clp-none" style="display:none">No match</p>
    <nav id="clp-pager" hidden><button id="clp-prev" type="button">Previous</button><span id="clp-page" role="status" tabindex="-1" data-tpl="Page :page of :pages · plans :from–:to of :total"></span><button id="clp-next" type="button">Next</button></nav></div>
  </body></html>`, {runScripts:'outside-only', pretendToBeVisual:true});
  const {window:w} = dom, doc = w.document, body = doc.getElementById('clp-body');
  const appends = [], scrolls = [];
  w.HTMLElement.prototype.scrollIntoView = function(options) {scrolls.push({id:this.id, options});};
  const nativeAppend = body.appendChild.bind(body);
  body.appendChild = node => {appends.push(node.nodeType); return nativeAppend(node);};
  const visible = () => [...body.children].filter(row => !row.hidden);
  const change = (id, value) => {const el = doc.getElementById(id); el.value = value; el.dispatchEvent(new w.Event('change'));};
  const click = id => {doc.getElementById(id).focus(); doc.getElementById(id).click();};
  if (run) w.eval(script);
  return {dom,w,doc,body,visible,change,click,appends,scrolls};
}

test('no-JS HTML keeps all plans and purchase links; JS starts with50 without reparenting server order', t => {
  const x = setup('en', false); t.after(() => x.dom.window.close());
  assert.equal(x.visible().length,123); assert.equal(x.body.querySelectorAll('a').length,123);
  const region = x.doc.querySelector('.cvps-tw');
  region.focus(); assert.equal(x.doc.activeElement,region);
  assert.equal(region.getAttribute('aria-labelledby'),'clp-results-title');
  assert.equal(region.getAttribute('aria-describedby'),'clp-scroll-hint');
  assert.equal(x.doc.getElementById('clp-pager').hidden,true);
  x.w.eval(script);
  assert.equal(x.visible().length,50); assert.equal(x.visible()[0].id,'r1'); assert.equal(x.visible().at(-1).id,'r50');
  assert.equal(x.body.children.length,123); assert.deepEqual(x.appends,[]);
  assert.equal(x.doc.getElementById('clp-count').textContent,'123 matching plans');
  assert.equal(x.doc.getElementById('clp-page').textContent,'Page 1 of 3 · plans 1–50 of 123');
});

test('paging scrolls and focuses the result start in both directions, including boundaries', t => {
  const x = setup(); t.after(() => x.dom.window.close());
  assert.equal(x.doc.getElementById('clp-prev').disabled,true);
  x.click('clp-next'); assert.equal(x.visible()[0].id,'r51'); assert.equal(x.visible().length,50);
  assert.equal(x.doc.activeElement.id,'clp-results-title');
  x.click('clp-next'); assert.equal(x.visible()[0].id,'r101'); assert.equal(x.visible().length,23);
  assert.equal(x.doc.getElementById('clp-next').disabled,true); assert.equal(x.doc.activeElement.id,'clp-results-title');
  assert.equal(x.doc.getElementById('clp-count').textContent,'123 matching plans');
  x.click('clp-prev'); x.click('clp-prev');
  assert.equal(x.visible()[0].id,'r1'); assert.equal(x.doc.activeElement.id,'clp-results-title');
  assert.equal(x.scrolls.length,4);
  for(const scroll of x.scrolls) {
    assert.equal(scroll.id,'clp-results-title');
    assert.equal(scroll.options.block,'start'); assert.equal(scroll.options.behavior,'instant');
  }
  assert.deepEqual(x.appends,[]);
});

test('country/resources filters inspect every plan and reset the page without changing selection URLs', t => {
  const x = setup(); t.after(() => x.dom.window.close());
  x.click('clp-next'); x.click('clp-next');
  x.doc.getElementById('clp-country').focus();
  const scrolls = x.scrolls.length;
  x.change('clp-country','FI');
  assert.equal(x.doc.activeElement.id,'clp-country'); assert.equal(x.scrolls.length,scrolls);
  assert.equal(x.visible()[0].id,'r61'); assert.equal(x.visible().length,50);
  assert.equal(x.doc.getElementById('clp-count').textContent,'63 matching plans');
  assert.match(x.doc.getElementById('clp-page').textContent,/Page 1 of 2/);
  x.click('clp-next'); assert.equal(x.visible()[0].id,'r111'); assert.equal(x.visible().length,13);
  x.change('clp-cpu','4'); x.change('clp-ram','2048');
  assert.equal(x.visible().length,0); assert.equal(x.doc.getElementById('clp-none').style.display,'block');
  assert.equal(x.doc.getElementById('clp-pager').hidden,true);
  x.change('clp-ram',''); assert.equal(x.visible().length,16);
  assert.equal(x.visible()[0].querySelector('a').getAttribute('href'),'/buy?plan=63');
  x.change('clp-country','ZZ'); assert.equal(x.visible().length,0);
  x.change('clp-country',''); x.change('clp-cpu','');
  assert.equal(x.visible().length,50); assert.equal(x.doc.getElementById('clp-none').style.display,'none');
  assert.equal(x.body.children.length,123); assert.deepEqual(x.appends,[]);
});

test('sort resets paging and batches changed order once; repeated identical sort does not move rows', t => {
  const x = setup(); t.after(() => x.dom.window.close());
  x.click('clp-next'); x.change('clp-sort','price-desc');
  assert.equal(x.visible()[0].id,'r123'); assert.equal(x.visible().at(-1).id,'r74');
  assert.deepEqual(x.appends,[11]);
  x.change('clp-sort','price-desc'); assert.deepEqual(x.appends,[11]);
  x.change('clp-sort','cpu'); assert.equal(x.visible()[0].id,'r3');
  assert.deepEqual(x.appends,[11,11]);
  x.change('clp-sort','ram'); assert.equal(x.visible()[0].id,'r2');
  x.change('clp-sort','price-asc'); assert.equal(x.visible()[0].id,'r1');
  assert.equal(x.body.children.length,123);
});

test('RTL Persian digits and LTR locales retain the same page/filter behavior', t => {
  for(const locale of ['fa','en','tr']) {
    const x = setup(locale); t.after(() => x.dom.window.close());
    x.click('clp-next'); x.change('clp-country','DE');
    assert.equal(x.visible()[0].id,'r1'); assert.equal(x.visible().length,50);
    const summary = x.doc.getElementById('clp-page').textContent;
    assert.equal(summary.includes(locale === 'fa' ? '۶۰' : '60'),true);
    if(locale === 'fa') assert.equal(/[0-9]/.test(summary),false);
    assert.equal(x.doc.documentElement.dir,locale === 'fa' ? 'rtl' : 'ltr');
  }
});
