const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const script=fs.readFileSync(path.join(__dirname,'../../public/assets/js/vps-catalog.js'),'utf8');
function setup(n=4){
  const rows=Array.from({length:n},(_,i)=>`<tr data-vps-row data-country="${i%2?'US':'TR'}" data-city="${i%2?'us-new-york':'tr-istanbul'}" data-monthly="${(n-i)*100}" data-hourly="${i%2?'20':'0'}" data-cpu="${i%2?'4':'2'}" data-ram="${i%2?'4096':'2048'}"><td>Plan ${i}</td></tr>`).join('');
  const dom=new JSDOM(`<html lang="en"><body><div data-vps-catalog><form data-vps-filters><input data-vps-search><select data-vps-country><option value="">All</option><option>US</option><option>TR</option></select><select data-vps-city><option value="">All</option><option value="us-new-york" data-country="US">New York</option><option value="tr-istanbul" data-country="TR">Istanbul</option></select><select data-vps-cpu><option value="">All</option><option>2</option><option>4</option></select><select data-vps-ram><option value="">All</option><option>2048</option><option>4096</option></select><select data-vps-billing><option value="">All</option><option value="hourly">Hourly</option><option value="monthly">Monthly only</option></select><select data-vps-sort><option value="monthly">Monthly</option><option value="hourly">Hourly</option></select></form><span data-vps-count data-vps-count-template=":shown of :total plans"></span><table><tbody>${rows}</tbody></table><p data-vps-empty hidden></p><button data-vps-more hidden>More</button></div></body></html>`,{runScripts:'outside-only'});
  dom.window.eval(script);const doc=dom.window.document;
  const set=(selector,value)=>{const el=doc.querySelector(selector);el.value=value;el.dispatchEvent(new dom.window.Event('change',{bubbles:true}));};
  const visible=()=>[...doc.querySelectorAll('[data-vps-row]')].filter(r=>!r.hidden);
  return {dom,doc,set,visible};
}
test('all offers remain in the document while the large table shows 50 at a time',()=>{
  const {doc,visible}=setup(61);assert.equal(visible().length,50);assert.equal(doc.querySelectorAll('[data-vps-row]').length,61);
  doc.querySelector('[data-vps-more]').click();assert.equal(visible().length,61);assert.equal(doc.querySelector('[data-vps-more]').hidden,true);
});
test('monthly-only plans remain visible and can be filtered independently',()=>{
  const {set,visible}=setup();set('[data-vps-billing]','monthly');assert.equal(visible().length,2);assert.ok(visible().every(r=>r.dataset.hourly==='0'));
  set('[data-vps-billing]','hourly');assert.equal(visible().length,2);assert.ok(visible().every(r=>Number(r.dataset.hourly)>0));
});
test('changing country resets a stale city and resource filters work together',()=>{
  const {doc,set,visible}=setup();set('[data-vps-city]','tr-istanbul');set('[data-vps-country]','US');
  assert.equal(doc.querySelector('[data-vps-city]').value,'');assert.equal(visible().length,2);
  set('[data-vps-cpu]','4');set('[data-vps-ram]','2048');assert.equal(visible().length,0);assert.equal(doc.querySelector('[data-vps-empty]').hidden,false);
});
test('hourly sorting puts monthly-only offers after real hourly rates and search resets pagination',()=>{
  const {doc,set,visible}=setup();set('[data-vps-sort]','hourly');assert.equal(visible()[0].dataset.hourly,'20');assert.equal(visible().at(-1).dataset.hourly,'0');
  set('[data-vps-search]','Plan 2');assert.equal(visible().length,1);assert.match(visible()[0].textContent,/Plan 2/);
  assert.equal(doc.querySelector('[data-vps-count]').textContent,'1 of 4 plans');
});
