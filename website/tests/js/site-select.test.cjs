const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
const path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../../public/assets/js/site-select.js'),'utf8');
function setup(extra='',ready=true){
  const dom=new JSDOM('<!doctype html><html><head>'+(ready?'<style>:root{--sn-select-ready:1}</style>':'')+'</head><body><main class="pnl-wrap"><form><label>Account<select id="pick" name="choice"><option value="a" selected>Alpha</option><option value="b" disabled>Blocked</option><option value="c" hidden>Concealed</option><optgroup label="Disabled group" disabled><option value="d">Disabled child</option></optgroup><option value="e">Echo</option></select></label><button id="save">Save</button>'+extra+'</form></main></body></html>',{runScripts:'outside-only',pretendToBeVisual:true,url:'https://example.test/account/profile'});
  dom.window.HTMLElement.prototype.getBoundingClientRect=()=>({left:20,right:300,top:100,bottom:144,width:280,height:44,x:20,y:100});
  dom.window.HTMLElement.prototype.scrollIntoView=()=>{};
  dom.window.eval(script);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  const doc=dom.window.document,select=doc.querySelector('#pick'),form=doc.querySelector('form');
  const trigger=s=>(s||select).closest('.sn-select').querySelector('.sn-select-trigger');
  const key=k=>trigger().dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:k,bubbles:true}));
  const tick=()=>new Promise(resolve=>dom.window.setTimeout(resolve,5));
  return {dom,doc,select,form,trigger,key,tick};
}
test('selection preserves original FormData, labels and one input/change pair',t=>{
  const x=setup();t.after(()=>x.dom.window.close());
  assert.match(x.trigger().getAttribute('aria-label'),/Account/);
  let input=0,change=0;x.select.addEventListener('input',()=>input++);x.select.addEventListener('change',()=>change++);
  x.trigger().click();assert.equal(x.doc.querySelector('.sn-select-menu').parentNode,x.doc.body);
  x.doc.querySelector('[data-index="4"]').click();
  assert.deepEqual(new x.dom.window.FormData(x.form).getAll('choice'),['e']);
  assert.deepEqual({input,change},{input:1,change:1});assert.equal(x.form.querySelectorAll('[name="choice"]').length,1);
});
test('keyboard skips disabled, hidden and disabled-group options and commits on Enter',t=>{
  const x=setup();t.after(()=>x.dom.window.close());x.key('ArrowDown');x.key('ArrowDown');
  const id=x.trigger().getAttribute('aria-activedescendant');assert.equal(x.doc.getElementById(id).dataset.index,'4');
  assert.equal(x.select.value,'a');x.key('Enter');assert.equal(x.select.value,'e');
});
test('Escape, Tab and outside press close without changing the field',t=>{
  const x=setup();t.after(()=>x.dom.window.close());
  for(const key of ['Escape','Tab']){x.trigger().click();x.key('ArrowDown');x.key(key);assert.equal(x.doc.querySelector('.sn-select-menu'),null);assert.equal(x.select.value,'a');}
  x.trigger().click();x.doc.body.dispatchEvent(new x.dom.window.Event('pointerdown',{bubbles:true}));assert.equal(x.doc.querySelector('.sn-select-menu'),null);
});
test('required native validation focuses the visible control and clears after a valid choice',t=>{
  const x=setup('<label>Country<select id="required" name="country" required><option value="" selected disabled>Select</option><option value="IR">Iran</option></select></label>');t.after(()=>x.dom.window.close());
  const field=x.doc.querySelector('#required');assert.equal(x.form.reportValidity(),false);
  assert.equal(x.doc.activeElement,x.trigger(field));assert.ok(x.doc.querySelector('[role="alert"]'));
  x.trigger(field).click();x.doc.querySelector('[data-index="1"]').click();
  assert.equal(x.doc.querySelector('[role="alert"]'),null);assert.equal(x.trigger(field).getAttribute('aria-invalid'),'false');
});
test('native reset restores the selected label as well as the submitted value',async t=>{
  const x=setup();t.after(()=>x.dom.window.close());x.trigger().click();x.doc.querySelector('[data-index="4"]').click();x.form.reset();await x.tick();
  assert.equal(x.select.value,'a');assert.equal(x.trigger().querySelector('.sn-select-value').textContent,'Alpha');
});
test('option updates, selected values and disabled states stay synchronized',async t=>{
  const x=setup();t.after(()=>x.dom.window.close());x.select.value='e';x.dom.window.ServerNetSelects.refresh(x.select);
  assert.equal(x.trigger().querySelector('.sn-select-value').textContent,'Echo');
  x.select.options[4].textContent='Edited option';await x.tick();assert.equal(x.trigger().querySelector('.sn-select-value').textContent,'Edited option');
  x.trigger().click();x.select.disabled=true;await x.tick();assert.equal(x.trigger().disabled,true);assert.equal(x.doc.querySelector('.sn-select-menu'),null);
});
test('existing special pickers and listboxes are left untouched; new selects mount once',async t=>{
  const x=setup('<select multiple id="multi"><option>a</option></select><select size="3" id="list"><option>a</option></select><select class="cvb-image-version" id="os"><option>a</option></select><select class="jd-month" id="date"><option>a</option></select><select data-native-select id="native"><option>a</option></select>');t.after(()=>x.dom.window.close());
  for(const id of ['multi','list','os','date','native'])assert.equal(x.doc.getElementById(id).closest('.sn-select'),null);
  const field=x.doc.createElement('select');field.innerHTML='<option value="fresh">Fresh</option>';x.form.appendChild(field);await x.tick();x.dom.window.ServerNetSelects.enhance(x.doc);
  assert.equal(field.closest('.sn-select').querySelectorAll('.sn-select-trigger').length,1);
});
test('missing stylesheet keeps the native fallback intact',t=>{
  const x=setup('',false);t.after(()=>x.dom.window.close());assert.equal(x.doc.querySelector('.sn-select-trigger'),null);assert.equal(x.select.hasAttribute('aria-hidden'),false);
});
test('typeahead and option labels treat text as text',t=>{
  const x=setup();t.after(()=>x.dom.window.close());x.key('e');x.key('Enter');assert.equal(x.select.value,'e');
  x.select.options[4].textContent='<img src=x onerror=alert(1)>';x.trigger().click();
  assert.equal(x.doc.querySelector('.sn-select-menu img'),null);assert.ok(x.doc.querySelector('.sn-select-menu').textContent.includes('<img'));
});
