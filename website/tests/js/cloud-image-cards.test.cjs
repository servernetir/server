const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync(path.join(__dirname,'../../public/assets/js/cloud-image-cards.js'),'utf8');
const words = {os:'Operating system',app:'Application',version:'Version',selected:'Selected',ready:'Ready to install',continue:'Continue'};
function row(key,label,checked=false,hidden=false) {
  return `<label class="cvb-img" data-key="${key}" ${hidden?'hidden':''}><input type="radio" name="image" value="${key}" ${checked?'checked':''}><img src="/assets/os/linux.svg"><b>${label}</b></label>`;
}
function setup(extra='') {
  const dom = new JSDOM(`<form><input name="plan" value="untouched"><div data-pane="os"><div class="cvb-fam" data-fam="ubuntu">${row('u24','24.04',true)}${row('u22','22.04')}${row('u26','26.04',false,true)}</div><div class="cvb-fam" data-fam="arvan">${row('opensuse','OpenSUSE')}</div></div>${extra}</form>`,{runScripts:'outside-only'});
  dom.window.eval(script);
  const form=dom.window.document.querySelector('form');
  return {dom,form,cards:dom.window.ServerNetImageCards.mount(form,words)};
}
test('family cards group versions and retain disabled incompatible options',()=>{
  const {form,cards}=setup();
  assert.equal(form.querySelectorAll('.cvb-image-card').length,2);
  assert.equal(form.querySelectorAll('.cvb-image-card.is-selected').length,1);
  const version=form.querySelector('.cvb-image-version');
  assert.equal(version.value,'u24');
  assert.equal(version.querySelector('[value="u26"]').disabled,true);
  assert.equal(version.querySelector('[value="u26"]').hidden,false);
  assert.equal(cards.labels.u24,'Ubuntu 24.04');
  assert.equal(cards.logos.opensuse,'/assets/os/opensuse.svg');
  assert.ok(!form.querySelector('.cvb-image-grid').textContent.includes('Arvan'));
});
test('version changes dispatch the original change and submit exactly one original key',()=>{
  const {dom,form}=setup();
  let changes=0;form.addEventListener('change',event=>{if(event.target.name==='image')changes++;});
  const version=form.querySelector('.cvb-image-version');
  version.value='u22';version.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
  assert.equal(changes,1);
  const data=new dom.window.FormData(form);
  assert.deepEqual(data.getAll('image'),['u22']);
  assert.equal(data.get('plan'),'untouched');
  assert.equal(form.querySelector('.cvb-image-version').hasAttribute('name'),false);
});
test('clicking a different card selects its remembered valid version and updates accessibility',()=>{
  const {dom,form}=setup();
  const button=[...form.querySelectorAll('.cvb-image-pick')].find(b=>b.textContent.includes('openSUSE'));
  button.click();
  assert.deepEqual(new dom.window.FormData(form).getAll('image'),['opensuse']);
  assert.equal(button.getAttribute('aria-pressed'),'true');
  assert.equal(form.querySelectorAll('[aria-pressed="true"]').length,1);
});
test('plan synchronization replaces unavailable selections without stale versions',()=>{
  const {form,cards}=setup();
  form.querySelector('[data-key="u24"]').hidden=true;
  form.querySelector('[value="u24"]').checked=false;
  form.querySelector('[data-key="u22"] input').checked=true;
  cards.refresh();
  const version=form.querySelector('.cvb-image-version');
  assert.equal(version.value,'u22');
  assert.equal(version.querySelector('[value="u24"]').disabled,true);
  assert.equal(version.closest('.cvb-image-bottom').classList.contains('is-single'),false);
});
test('a family with no available images remains disabled',()=>{
  const {form,cards}=setup();
  form.querySelectorAll('[data-fam="ubuntu"] .cvb-img').forEach(n=>n.hidden=true);
  cards.refresh();
  const card=[...form.querySelectorAll('.cvb-image-card')].find(n=>n.textContent.includes('Ubuntu'));
  assert.equal(card.hidden,false);
  assert.equal(card.getAttribute('aria-disabled'),'true');
  assert.equal(card.querySelector('button').disabled,true);
  assert.equal(card.querySelector('select').disabled,true);
});
test('continue is a separate non-submitting action and hides for an empty catalog',()=>{
  const {form,cards}=setup();
  const next=form.querySelector('[data-image-continue]');
  assert.equal(next.type,'button');
  form.querySelectorAll('.cvb-img').forEach(n=>n.hidden=true);cards.refresh();
  assert.equal(next.hidden,true);
});
test('application versions use the same card behavior',()=>{
  const extra=`<div data-pane="app"><div class="cvb-fam" data-fam="docker">${row('d27','Docker 27')}${row('d26','Docker 26')}</div></div>`;
  const {form}=setup(extra);
  const pane=form.querySelector('[data-pane="app"]');
  assert.equal(pane.querySelectorAll('.cvb-image-card').length,1);
  assert.equal(pane.querySelector('select').getAttribute('aria-label'),'Version — Docker');
  assert.equal(pane.querySelector('.cvb-image-kind').textContent,'Application');
});
test('existing GPU app cards are preserved',()=>{
  const extra=`<div data-pane="app"><div class="cvb-fam"><label class="cvb-img cvb-gpu-app"><input type="radio" name="image" value="gpu-jupyter"><b>Jupyter</b></label></div></div>`;
  const {form}=setup(extra);
  const pane=form.querySelector('[data-pane="app"]');
  assert.equal(pane.querySelectorAll('.cvb-image-card').length,0);
  assert.equal(pane.classList.contains('cvb-images-enhanced'),false);
  assert.equal(pane.querySelector('.cvb-gpu-app').hidden,false);
});

test('animated popup exposes disabled incompatible versions and submits the original image key',()=>{
  const {dom,form}=setup();
  const trigger=form.querySelector('.cvb-version-trigger');
  assert.equal(trigger.type,'button');
  trigger.click();
  const menu=dom.window.document.querySelector('[role="listbox"]');
  assert.equal(menu.parentElement,dom.window.document.body);
  assert.equal(trigger.getAttribute('aria-expanded'),'true');
  assert.deepEqual([...menu.querySelectorAll('[role="option"]')].map(n=>n.textContent),['26.04','24.04','22.04']);
  assert.equal(menu.querySelector('[role="option"]').disabled,true);
  menu.querySelectorAll('[role="option"]')[2].click();
  assert.equal(dom.window.document.querySelector('[role="listbox"]'),null);
  assert.equal(trigger.getAttribute('aria-expanded'),'false');
  assert.deepEqual(new dom.window.FormData(form).getAll('image'),['u22']);
  assert.equal(dom.window.document.activeElement,trigger);
});

test('keyboard navigation, Escape, outside click and plan refresh close the popup safely',()=>{
  const {dom,form,cards}=setup();
  const doc=dom.window.document, trigger=form.querySelector('.cvb-version-trigger');
  const key=(target,value)=>target.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:value,bubbles:true,cancelable:true}));
  key(trigger,'ArrowDown');
  assert.equal(doc.activeElement.textContent,'24.04');
  key(doc.activeElement,'End');
  assert.equal(doc.activeElement.textContent,'22.04');
  key(doc.activeElement,'Escape');
  assert.equal(doc.activeElement,trigger);
  assert.equal(doc.querySelector('[role="listbox"]'),null);
  trigger.click();
  doc.body.dispatchEvent(new dom.window.Event('pointerdown',{bubbles:true}));
  assert.equal(doc.querySelector('[role="listbox"]'),null);
  trigger.click();
  const stale=doc.querySelectorAll('[role="option"]')[1];
  form.querySelector('[data-key="u22"]').hidden=true;
  cards.refresh();
  stale.click();
  assert.deepEqual(new dom.window.FormData(form).getAll('image'),['u24']);
  assert.equal(doc.querySelector('[role="listbox"]'),null);
});

test('disabled source radios stay visible with reasons and cannot be selected by cards or version menus',()=>{
  const {form,cards}=setup();
  const row=form.querySelector('[data-key="u22"]');
  row.querySelector('input').disabled=true;
  const reason=form.ownerDocument.createElement('small');reason.className='cvb-choice-reason';reason.textContent='Requires 4096 MB RAM';row.appendChild(reason);
  cards.refresh();
  const option=form.querySelector('.cvb-image-version [value="u22"]');
  assert.equal(option.hidden,false);assert.equal(option.disabled,true);assert.match(option.textContent,/4096/);
  form.querySelector('.cvb-version-trigger').click();
  const disabled=[...form.ownerDocument.querySelectorAll('.cvb-version-option')].find(o=>o.textContent.includes('4096'));
  assert.equal(disabled.disabled,true);disabled.click();
  assert.equal(row.querySelector('input').checked,false);
});
