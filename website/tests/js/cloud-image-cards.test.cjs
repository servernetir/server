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
test('family cards group versions and hide incompatible options',()=>{
  const {form,cards}=setup();
  assert.equal(form.querySelectorAll('.cvb-image-card').length,2);
  assert.equal(form.querySelectorAll('.cvb-image-card.is-selected').length,1);
  const version=form.querySelector('.cvb-image-version');
  assert.equal(version.value,'u24');
  assert.equal(version.querySelector('[value="u26"]').disabled,true);
  assert.equal(version.querySelector('[value="u26"]').hidden,true);
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
  assert.ok(version.closest('.cvb-image-bottom').classList.contains('is-single'));
});
test('a family with no available images disappears',()=>{
  const {form,cards}=setup();
  form.querySelectorAll('[data-fam="ubuntu"] .cvb-img').forEach(n=>n.hidden=true);
  cards.refresh();
  const card=[...form.querySelectorAll('.cvb-image-card')].find(n=>n.textContent.includes('Ubuntu'));
  assert.equal(card.hidden,true);
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
