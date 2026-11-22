const {test, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const supplied = process.env.STUDIO_HOME_FIXTURES;
const fixtures = supplied || fs.mkdtempSync(path.join(os.tmpdir(), 'servernet-studio-home-'));
if (!supplied) for (const locale of ['fa', 'en', 'tr']) execFileSync(process.env.PHP_BINARY || 'php', [path.join(root, 'tests/Fixtures/StudioHomePreview.php'), locale, fixtures]);
after(() => { if (!supplied) fs.rmSync(fixtures, {recursive: true, force: true}); });
const code = fs.readFileSync(path.join(root, 'public/assets/js/studio-carousel.js'), 'utf8');
function setup(locale='fa', reduced=false) {
  const dom = new JSDOM(fs.readFileSync(path.join(fixtures, 'partial-' + locale + '.html'), 'utf8'), {runScripts:'outside-only', pretendToBeVisual:true, url:'https://servernet.cloud/'});
  const w=dom.window, doc=w.document, timers=new Map(); let now=0, serial=0, visibility=false;
  const media={matches:reduced,addEventListener(){}};
  const observers=[];
  w.matchMedia=()=>media;
  w.IntersectionObserver=class {constructor(callback){this.callback=callback;observers.push(this);}observe(){}};
  w.setTimeout=(fn,delay)=>{const id=++serial;timers.set(id,{fn,at:now+delay});return id;};
  w.clearTimeout=id=>timers.delete(id);
  w.fetch=()=>{throw new Error('Marketing previews must not call inference');};
  w.XMLHttpRequest=function(){throw new Error('Marketing previews must not call inference');};
  Object.defineProperty(doc,'hidden',{get:()=>visibility,configurable:true});
  const tick=duration=>{const end=now+duration;for(;;){const entry=Array.from(timers).filter(([,t])=>t.at<=end).sort((a,b)=>a[1].at-b[1].at)[0];if(!entry)break;now=entry[1].at;timers.delete(entry[0]);entry[1].fn();}now=end;};
  w.eval(code);
  return {dom,w,doc,tick,observers,media,root:doc.querySelector('[data-hero-carousel]'),
    slide:()=>doc.querySelector('[data-hero-slide].is-active').dataset.heroSlide,
    select:name=>doc.querySelector('[data-hero-target="'+name+'"]').click(),
    hidden:value=>{visibility=value;doc.dispatchEvent(new w.Event('visibilitychange'));}};
}
for (const locale of ['fa','en','tr']) {
  test(locale+': original globe is first; Studio appears after seven seconds and cycles back',()=>{
    const e=setup(locale);try{
      assert.equal(e.slide(),'infrastructure');assert.equal(e.doc.getElementById('studio').inert,true);
      e.tick(6999);assert.equal(e.slide(),'infrastructure');e.tick(1);assert.equal(e.slide(),'studio');
      assert.equal(e.doc.getElementById('hero-infrastructure').inert,true);
      assert.equal(e.doc.getElementById('studio').inert,false);
      assert.equal(e.doc.getElementById('studio').getAttribute('aria-hidden'),'false');
      e.tick(7000);assert.equal(e.slide(),'infrastructure');
      assert.doesNotMatch(e.doc.body.textContent,/studio_carousel\./);
      assert.equal(e.doc.querySelector('.sn-studio-launch').href,'https://studio.servernet.cloud/');
      assert.equal(e.doc.querySelector('.sn-studio-install').getAttribute('href'),(locale==='fa'?'':'/'+locale)+'/studio/download#studio-web-install');
    }finally{e.w.close();}
  });
}
test('manual selection pauses rotation; play works with the play control still focused',()=>{
  const e=setup();try{
    e.select('studio');e.tick(30000);assert.equal(e.slide(),'studio');
    const toggle=e.doc.querySelector('[data-hero-autoplay]');
    assert.equal(toggle.getAttribute('aria-pressed'),'true');
    toggle.focus();toggle.click();assert.ok(e.root.classList.contains('is-playing'));
    e.tick(7000);assert.equal(e.slide(),'infrastructure');
  }finally{e.w.close();}
});
test('focus in slide content pauses; leaving the carousel starts a fresh interval',()=>{
  const e=setup();try{
    e.doc.querySelector('#hero-infrastructure a').focus();e.tick(20000);assert.equal(e.slide(),'infrastructure');
    e.doc.activeElement.blur();e.tick(6999);assert.equal(e.slide(),'infrastructure');e.tick(1);assert.equal(e.slide(),'studio');
  }finally{e.w.close();}
});
test('hidden documents and off-screen heroes do not advance',()=>{
  const e=setup();try{
    e.hidden(true);e.tick(20000);assert.equal(e.slide(),'infrastructure');
    e.hidden(false);e.tick(7000);assert.equal(e.slide(),'studio');
    e.observers[0].callback([{isIntersecting:false}]);e.tick(30000);assert.equal(e.slide(),'studio');
    e.observers[0].callback([{isIntersecting:true}]);e.tick(7000);assert.equal(e.slide(),'infrastructure');
  }finally{e.w.close();}
});
test('reduced motion defaults to manual selection without disabling requested play',()=>{
  const e=setup('en',true);try{
    e.tick(30000);assert.equal(e.slide(),'infrastructure');
    assert.equal(e.doc.querySelector('[data-hero-autoplay]').getAttribute('aria-pressed'),'true');
    e.select('studio');assert.equal(e.slide(),'studio');assert.equal(e.root.classList.contains('is-transitioning'),false);
    e.doc.querySelector('[data-hero-autoplay]').click();e.tick(7000);assert.equal(e.slide(),'infrastructure');
  }finally{e.w.close();}
});
test('four real examples switch locally and stop automatic slide changes',()=>{
  const e=setup();try{
    e.tick(7000);
    for(const button of e.doc.querySelectorAll('[data-studio-mode]')){
      assert.equal(button.hidden,false);button.click();
      const visible=e.doc.querySelectorAll('[data-studio-panel]:not([hidden])');
      assert.equal(visible.length,1);assert.equal(visible[0].id,button.getAttribute('aria-controls'));
      assert.equal(e.doc.querySelectorAll('[data-studio-mode][aria-pressed="true"]').length,1);
    }
    e.tick(20000);assert.equal(e.slide(),'studio');assert.equal(e.doc.querySelector('[data-hero-autoplay]').getAttribute('aria-pressed'),'true');
  }finally{e.w.close();}
});
test('hovering controls pauses and a pointer leave resumes the remaining experience',()=>{
  const e=setup();try{
    const controls=e.doc.querySelector('.sn-hero-controls');
    controls.dispatchEvent(new e.w.Event('pointerenter'));e.tick(20000);assert.equal(e.slide(),'infrastructure');
    controls.dispatchEvent(new e.w.Event('pointerleave'));e.tick(7000);assert.equal(e.slide(),'studio');
  }finally{e.w.close();}
});
test('re-evaluating the module does not duplicate timers or handlers',()=>{
  const e=setup();try{
    e.w.eval(code);e.tick(7000);assert.equal(e.slide(),'studio');e.tick(7000);assert.equal(e.slide(),'infrastructure');
    e.doc.querySelector('[data-hero-autoplay]').click();assert.equal(e.doc.querySelector('[data-hero-autoplay]').getAttribute('aria-pressed'),'true');
  }finally{e.w.close();}
});
test('the static first frame remains useful before scripts initialize',()=>{
  const dom=new JSDOM(fs.readFileSync(path.join(fixtures,'partial-fa.html'),'utf8'));try{
    const d=dom.window.document;
    assert.equal(d.querySelector('[data-hero-slide]').id,'hero-infrastructure');
    assert.equal(d.getElementById('studio').getAttribute('aria-hidden'),'true');
    assert.equal(d.querySelectorAll('[data-studio-mode]:not([hidden])').length,0);
    assert.equal(d.querySelectorAll('form,input,textarea').length,0);
    assert.equal(d.querySelector('noscript a').href,'https://studio.servernet.cloud/');
  }finally{dom.window.close();}
});
test('unrelated pages do not need a carousel',()=>{
  const dom=new JSDOM('<main>Other page</main>',{runScripts:'outside-only'});
  try{assert.doesNotThrow(()=>dom.window.eval(code));}finally{dom.window.close();}
});
