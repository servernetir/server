const {test}=require('node:test');
const assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const fs=require('node:fs');
const path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../../public/assets/js/choice-hints.js'),'utf8');
function setup(){
  const dom=new JSDOM(`<html dir="rtl"><body><form id="cvb-form"><label class="cvb-seg" aria-disabled="true" title="This cycle is unavailable"><input type="radio" name="cycle" value="quarterly" disabled><span>Quarterly</span><small class="cvb-cycle-reason">This cycle is unavailable</small></label><div class="cvb-image-card" aria-disabled="true"><button disabled>Windows</button><small class="cvb-choice-reason">Requires 4096 MB RAM</small></div></form></body></html>`,{runScripts:'outside-only',pretendToBeVisual:true});
  dom.window.eval(script);
  return {dom,doc:dom.window.document,cycle:dom.window.document.querySelector('.cvb-seg'),os:dom.window.document.querySelector('.cvb-image-card'),tip:dom.window.document.querySelector('[role="tooltip"]')};
}
test('disabled cycle supports focus, an accessible tooltip and Escape without selecting it',()=>{
  const {dom,doc,cycle,tip}=setup();assert.equal(tip.hidden,true);assert.equal(cycle.tabIndex,0);assert.equal(cycle.title,'');
  cycle.focus();assert.equal(doc.activeElement,cycle);assert.equal(tip.hidden,false);assert.equal(tip.textContent,'This cycle is unavailable');
  assert.equal(cycle.getAttribute('aria-describedby'),tip.id);
  cycle.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
  assert.equal(tip.hidden,true);assert.equal(cycle.hasAttribute('aria-describedby'),false);assert.equal(cycle.querySelector('input').checked,false);
});
test('hover and touch-like clicks explain disabled OS choices without running selection handlers',()=>{
  const {dom,os,tip}=setup();let selected=false;os.addEventListener('click',()=>selected=true);
  os.dispatchEvent(new dom.window.Event('pointerover',{bubbles:true}));assert.equal(tip.textContent,'Requires 4096 MB RAM');
  const event=new dom.window.MouseEvent('click',{bubbles:true,cancelable:true});os.dispatchEvent(event);
  assert.equal(event.defaultPrevented,true);assert.equal(selected,false);assert.equal(tip.hidden,false);
});
test('changing to a compatible plan removes the stale tooltip and extra tab stop',async()=>{
  const {dom,os,tip}=setup();os.focus();assert.equal(tip.hidden,false);
  os.setAttribute('aria-disabled','false');await new Promise(resolve=>dom.window.setTimeout(resolve,30));
  assert.equal(tip.hidden,true);assert.equal(os.hasAttribute('data-choice-hint'),false);assert.equal(os.hasAttribute('tabindex'),false);
  
});
test('portalled version choices retain reasons and are keyboard discoverable',async()=>{
  const {dom,doc,tip}=setup();const option=doc.createElement('button');option.className='cvb-version-option';option.type='button';option.tabIndex=-1;
  option.setAttribute('aria-disabled','true');option.setAttribute('data-choice-hint','Architecture is incompatible');option.textContent='2025';doc.body.appendChild(option);
  await new Promise(resolve=>dom.window.setTimeout(resolve,30));option.focus();
  assert.equal(option.tabIndex,0);assert.equal(tip.textContent,'Architecture is incompatible');assert.equal(tip.hidden,false);
  option.remove();await new Promise(resolve=>dom.window.setTimeout(resolve,30));assert.equal(tip.hidden,true);
});
