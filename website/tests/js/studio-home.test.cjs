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
function setup(locale='fa', reduced=false, withoutStudio=false) {
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
  if(withoutStudio){doc.getElementById('studio').remove();doc.querySelector('[data-hero-target=studio]').remove();}
  w.eval(code);
  const pointer=(target,type,id=1)=>{const event=new w.Event(type,{bubbles:true});Object.defineProperty(event,'pointerId',{value:id});target.dispatchEvent(event);};
  return {dom,w,doc,tick,observers,media,pointer,root:doc.querySelector('[data-hero-carousel]'),
    slide:()=>doc.querySelector('[data-hero-slide].is-active').dataset.heroSlide,
    select:name=>doc.querySelector('[data-hero-target="'+name+'"]').click(),
    hidden:value=>{visibility=value;doc.dispatchEvent(new w.Event('visibilitychange'));}};
}
for (const locale of ['fa','en','tr']) {
  test(locale+': original globe is first; GPU appears after six seconds; seven slides loop in order',()=>{
    const e=setup(locale);try{
      assert.equal(e.slide(),'infrastructure');assert.equal(e.doc.getElementById('studio').inert,true);
      let previous='infrastructure';
      for(const name of ['gpu','studio','n8n','hosting','email','parts','infrastructure']){
        e.tick(5999);assert.equal(e.slide(),previous);e.tick(1);assert.equal(e.slide(),name);
        const active=e.doc.querySelector('[data-hero-slide="'+name+'"]');
        assert.equal(active.inert,false);assert.equal(active.getAttribute('aria-hidden'),'false');
        previous=name;
      }
      assert.equal(e.doc.querySelectorAll('[data-hero-target]').length,7);
      assert.equal(e.doc.querySelector('#hero-email .btn-primary').getAttribute('href'),(locale==='fa'?'':'/'+locale)+'/transactional-email');
      assert.doesNotMatch(e.doc.body.textContent,/studio_carousel\./);
      assert.equal(e.doc.querySelector('.sn-studio-launch').href,'https://studio.servernet.cloud/');
      assert.equal(e.doc.querySelector('.sn-studio-install').getAttribute('href'),(locale==='fa'?'':'/'+locale)+'/studio/download#studio-web-install');
    }finally{e.w.close();}
  });
}
test('manual selection automatically resumes rotation exactly two seconds after the click',()=>{
  const e=setup();try{
    const toggle=e.doc.querySelector('[data-hero-autoplay]');
    e.select('studio');e.tick(1999);assert.equal(e.slide(),'studio');
    assert.equal(toggle.getAttribute('aria-pressed'),'true');assert.equal(e.root.classList.contains('is-playing'),false);
    e.tick(1);assert.equal(toggle.getAttribute('aria-pressed'),'false');assert.ok(e.root.classList.contains('is-playing'));
    e.tick(5999);assert.equal(e.slide(),'studio');e.tick(1);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('hardware fit reserves the measured header and rails, with a width fallback and natural mobile flow',()=>{
  const markup=fs.readFileSync(path.join(fixtures,'partial-fa.html'),'utf8');
  const dom=new JSDOM('<div id="header-wrap"></div><main id="main">'+markup+'</main>',{runScripts:'outside-only',pretendToBeVisual:true,url:'https://servernet.cloud/'});
  const w=dom.window,d=w.document,desktop={matches:true,addEventListener(){}};let resized;
  const box=(selector,values,style='')=>{const el=d.querySelector(selector);Object.keys(values).forEach(k=>Object.defineProperty(el,k,{get:()=>values[k],configurable:true}));el.style.cssText=style;return values;};
  w.matchMedia=query=>query.includes('min-width')?desktop:{matches:true,addEventListener(){}};
  w.CSS={supports:()=>true};w.requestAnimationFrame=fn=>{fn();return 1;};
  w.ResizeObserver=class{constructor(callback){resized=callback;}observe(){}};
  d.documentElement.style.setProperty('--header-h','112px');
  const header=box('#header-wrap',{offsetHeight:140}), copy=box('.sn-parts-copy',{offsetHeight:360});
  box('.sn-parts-scene',{clientWidth:620});box('.sn-parts-scene-head',{offsetHeight:36},'margin-bottom:7px');
  box('.sn-parts-scene-foot',{offsetHeight:40},'margin-top:9px');box('#hero-parts',{},'padding:12px 0');
  box('.sn-hero-controls',{offsetHeight:60});box('.sn-proof-rail',{offsetHeight:74});
  const visual=box('.sn-parts-visual',{clientHeight:400},'padding:5px 0 7px');
  d.querySelectorAll('.sn-parts-hotspot-label').forEach(el=>Object.defineProperty(el,'offsetHeight',{value:20}));
  const root=d.querySelector('.sn-home-intro'), main=d.getElementById('main'), art=d.querySelector('.sn-parts-visual');
  try{
    w.eval(code);
    assert.equal(main.style.getPropertyValue('--sn-home-header-space'),'140px');
    assert.equal(root.style.getPropertyValue('--sn-home-parts-min-intro'),'666px');
    assert.equal(art.style.getPropertyValue('--sn-parts-art-limit'),'582px');
    header.offsetHeight=150;copy.offsetHeight=410;visual.clientHeight=350;resized();
    assert.equal(main.style.getPropertyValue('--sn-home-header-space'),'150px');
    assert.equal(root.style.getPropertyValue('--sn-home-parts-min-intro'),'726px');
    assert.equal(art.style.getPropertyValue('--sn-parts-art-limit'),'507px');
    desktop.matches=false;w.dispatchEvent(new w.Event('resize'));
    assert.equal(main.style.getPropertyValue('--sn-home-header-space'),'');
    assert.equal(root.style.getPropertyValue('--sn-home-parts-min-intro'),'');
    assert.equal(art.style.getPropertyValue('--sn-parts-art-limit'),'');
    assert.equal(d.querySelector('[data-hero-slide].is-active').dataset.heroSlide,'infrastructure');
  }finally{w.close();}
});
test('each new click postpones the resume deadline by another two seconds',()=>{
  const e=setup();try{
    e.select('gpu');e.tick(1500);e.select('studio');e.tick(1500);
    e.doc.querySelector('[data-studio-mode]').click();e.tick(1999);
    assert.equal(e.slide(),'studio');assert.equal(e.root.classList.contains('is-playing'),false);
    e.tick(1);assert.ok(e.root.classList.contains('is-playing'));
    e.tick(6000);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('a real mouse click does not leave playback blocked by its retained hover or focus',()=>{
  const e=setup();try{
    const button=e.doc.querySelector('[data-hero-target=parts]');
    e.doc.querySelector('.sn-hero-controls').dispatchEvent(new e.w.Event('pointerenter'));
    e.pointer(button,'pointerdown');button.focus();e.pointer(button,'pointerup');
    button.dispatchEvent(new e.w.MouseEvent('click',{bubbles:true,detail:1}));
    assert.equal(e.slide(),'parts');assert.equal(e.doc.activeElement,button);
    e.tick(1999);assert.equal(e.root.classList.contains('is-playing'),false);
    e.tick(1);assert.ok(e.root.classList.contains('is-playing'));assert.equal(e.doc.activeElement,button);
    e.tick(6000);assert.equal(e.slide(),'infrastructure');
  }finally{e.w.close();}
});
test('a dedicated manual pause persists through selection; play works while its control is focused',()=>{
  const e=setup();try{
    const toggle=e.doc.querySelector('[data-hero-autoplay]');
    toggle.click();e.select('studio');e.tick(30000);assert.equal(e.slide(),'studio');
    assert.equal(toggle.getAttribute('aria-pressed'),'true');
    toggle.focus();toggle.click();assert.ok(e.root.classList.contains('is-playing'));
    e.tick(6000);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('the Play control can resume immediately during the temporary pause',()=>{
  const e=setup();try{
    e.select('studio');e.tick(1000);
    e.doc.querySelector('[data-hero-autoplay]').click();assert.ok(e.root.classList.contains('is-playing'));
    e.tick(5999);assert.equal(e.slide(),'studio');e.tick(1);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('focus in slide content pauses; leaving the carousel starts a fresh interval',()=>{
  const e=setup();try{
    e.doc.querySelector('#hero-infrastructure a').focus();e.tick(20000);assert.equal(e.slide(),'infrastructure');
    e.doc.activeElement.blur();e.tick(5999);assert.equal(e.slide(),'infrastructure');e.tick(1);assert.equal(e.slide(),'gpu');
  }finally{e.w.close();}
});
test('keyboard navigation keeps focused slide controls available even after activation',()=>{
  const e=setup();try{
    e.select('studio');e.tick(2000);
    const button=e.doc.querySelector('[data-studio-mode]');button.focus();button.click();
    e.tick(20000);assert.equal(e.slide(),'studio');assert.equal(e.doc.activeElement,button);
    button.blur();e.tick(6000);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('hidden documents and off-screen heroes do not advance',()=>{
  const e=setup();try{
    e.hidden(true);e.tick(20000);assert.equal(e.slide(),'infrastructure');
    e.hidden(false);e.tick(6000);assert.equal(e.slide(),'gpu');
    e.observers[0].callback([{isIntersecting:false}]);e.tick(30000);assert.equal(e.slide(),'gpu');
    e.observers[0].callback([{isIntersecting:true}]);e.tick(6000);assert.equal(e.slide(),'studio');
  }finally{e.w.close();}
});
test('the idle deadline cannot restart off-screen or hidden playback',()=>{
  const e=setup();try{
    e.select('parts');e.hidden(true);e.tick(20000);assert.equal(e.slide(),'parts');
    assert.equal(e.root.classList.contains('is-playing'),false);
    e.observers[0].callback([{isIntersecting:false}]);e.hidden(false);e.tick(20000);assert.equal(e.slide(),'parts');
    e.observers[0].callback([{isIntersecting:true}]);e.tick(6000);assert.equal(e.slide(),'infrastructure');
  }finally{e.w.close();}
});
test('reduced motion defaults to manual selection without disabling requested play',()=>{
  const e=setup('en',true);try{
    e.tick(30000);assert.equal(e.slide(),'infrastructure');
    assert.equal(e.doc.querySelector('[data-hero-autoplay]').getAttribute('aria-pressed'),'true');
    e.select('studio');assert.equal(e.slide(),'studio');assert.equal(e.root.classList.contains('is-transitioning'),false);
    e.doc.querySelector('[data-hero-autoplay]').click();e.tick(6000);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('four real examples switch locally and resume automatic slide changes when idle',()=>{
  const e=setup();try{
    e.select('studio');
    for(const button of e.doc.querySelectorAll('[data-studio-mode]')){
      assert.equal(button.hidden,false);button.click();
      const visible=e.doc.querySelectorAll('[data-studio-panel]:not([hidden])');
      assert.equal(visible.length,1);assert.equal(visible[0].id,button.getAttribute('aria-controls'));
      assert.equal(e.doc.querySelectorAll('[data-studio-mode][aria-pressed="true"]').length,1);
    }
    e.tick(1999);assert.equal(e.slide(),'studio');assert.equal(e.doc.querySelector('[data-hero-autoplay]').getAttribute('aria-pressed'),'true');
    e.tick(1);assert.ok(e.root.classList.contains('is-playing'));e.tick(6000);assert.equal(e.slide(),'n8n');
  }finally{e.w.close();}
});
test('hovering controls pauses and a pointer leave resumes the remaining experience',()=>{
  const e=setup();try{
    const controls=e.doc.querySelector('.sn-hero-controls');
    controls.dispatchEvent(new e.w.Event('pointerenter'));e.tick(20000);assert.equal(e.slide(),'infrastructure');
    controls.dispatchEvent(new e.w.Event('pointerleave'));e.tick(6000);assert.equal(e.slide(),'gpu');
  }finally{e.w.close();}
});
test('re-evaluating the module does not duplicate timers or handlers',()=>{
  const e=setup();try{
    e.w.eval(code);e.tick(6000);assert.equal(e.slide(),'gpu');e.tick(6000);assert.equal(e.slide(),'studio');
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
    assert.deepEqual([...d.querySelectorAll('noscript a')].map(a=>a.getAttribute('href')),['/gpu','/cloud/n8n','/hosting/linux','/transactional-email','https://studio.servernet.cloud/','/parts']);
    assert.equal(d.querySelector('#hero-parts img').hasAttribute('src'),false);
  }finally{dom.window.close();}
});
test('unrelated pages do not need a carousel',()=>{
  const dom=new JSDOM('<main>Other page</main>',{runScripts:'outside-only'});
  try{assert.doesNotThrow(()=>dom.window.eval(code));}finally{dom.window.close();}
});

test('every service can be selected directly with a single matching visible and accessible slide',()=>{
  const e=setup();try{
    for(const name of ['parts','email','hosting','n8n','studio','gpu','infrastructure']){
      e.select(name);e.tick(1200);assert.equal(e.slide(),name);
      assert.equal(e.doc.querySelectorAll('[data-hero-slide][aria-hidden="false"]').length,1);
      assert.equal(e.doc.querySelectorAll('[data-hero-target][aria-pressed="true"]').length,1);
      for(const slide of e.doc.querySelectorAll('[data-hero-slide]')) assert.equal(slide.inert,slide.dataset.heroSlide!==name);
    }
  }finally{e.w.close();}
});
test('unconfigured Studio leaves a working six-slide loop',()=>{
  const e=setup('fa',false,true);try{
    assert.equal(e.slide(),'infrastructure');e.tick(6000);assert.equal(e.slide(),'gpu');e.tick(6000);assert.equal(e.slide(),'n8n');
    e.tick(6000);assert.equal(e.slide(),'hosting');e.tick(6000);assert.equal(e.slide(),'email');e.tick(6000);assert.equal(e.slide(),'parts');e.tick(6000);assert.equal(e.slide(),'infrastructure');
  }finally{e.w.close();}
});

test('dragging the production homepage globe holds playback until release and two seconds of inactivity',()=>{
  const e=setup();try{
    const globe=e.doc.querySelector('.sn-globe');globe.setAttribute('data-sn-home-globe','');
    e.pointer(globe,'pointerdown');e.tick(20000);
    assert.equal(e.slide(),'infrastructure');
    assert.equal(e.doc.querySelector('[data-hero-autoplay]').getAttribute('aria-pressed'),'true');
    e.pointer(e.doc.body,'pointerup');e.tick(1999);assert.equal(e.root.classList.contains('is-playing'),false);
    e.tick(1);assert.ok(e.root.classList.contains('is-playing'));e.tick(6000);assert.equal(e.slide(),'gpu');
  }finally{e.w.close();}
});
test('a cancelled pointer interaction also resumes after the idle period',()=>{
  const e=setup();try{
    const globe=e.doc.querySelector('.sn-globe');e.pointer(globe,'pointerdown');e.tick(20000);
    e.pointer(globe,'pointercancel');e.tick(2000);assert.ok(e.root.classList.contains('is-playing'));
    e.tick(6000);assert.equal(e.slide(),'gpu');
  }finally{e.w.close();}
});

for (const locale of ['fa','en','tr']) {
  test(locale+': hardware artwork loads only on activation and all links lead to real localized catalog routes',()=>{
    const e=setup(locale);try{
      const slide=e.doc.getElementById('hero-parts');
      const img=slide.querySelector('[data-parts-chassis-art]');
      const images=[...slide.querySelectorAll('img[data-hero-image-src]')];
      const prefix=locale==='fa'?'':'/'+locale;
      assert.equal(images.length,5);assert.equal(images[0],img);
      assert.ok(images.every(layer=>!layer.hasAttribute('src')));
      e.tick(35999);assert.equal(e.slide(),'email');assert.ok(images.every(layer=>!layer.hasAttribute('src')));
      e.tick(1);assert.equal(e.slide(),'parts');assert.ok(images.every(layer=>layer.getAttribute('src')==='assets/img/home-parts-exploded.webp'));
      assert.equal(slide.querySelector('.btn-primary').getAttribute('href'),prefix+'/parts');
      for (const category of ['cpu','ram','disk','raid']) {
        const link=slide.querySelector('[data-parts-category='+category+']');
        assert.equal(link.getAttribute('href'),prefix+'/parts/'+category);assert.ok(link.getAttribute('aria-label'));
        assert.ok(link.querySelectorAll('.sn-parts-piece img').length===1);assert.equal(link.querySelector('.sn-parts-piece').getAttribute('aria-hidden'),'true');
      }
      assert.deepEqual([...slide.querySelectorAll('.sn-parts-gen-links a')].map(a=>a.getAttribute('href')),[8,9,10,11,12].map(n=>prefix+'/servers/hp/gen'+n));
      assert.equal(slide.querySelector('.sn-parts-chassis').getAttribute('href'),prefix+'/parts/chassis');
      assert.equal(slide.querySelector('[class*="sn-parts-frame"]'),null);
      assert.equal(slide.querySelector('.sn-parts-halo').parentElement,slide);
      slide.querySelector('[data-parts-explore]').dispatchEvent(new e.w.Event('pointerenter'));e.tick(30000);assert.equal(e.slide(),'parts');
      slide.querySelector('[data-parts-explore]').dispatchEvent(new e.w.Event('pointerleave'));e.tick(6000);assert.equal(e.slide(),'infrastructure');
    }finally{e.w.close();}
  });
}
test('the extraction waits for the shared artwork to decode and is ready on the next visit',async()=>{
  const e=setup();try{
    const slide=e.doc.getElementById('hero-parts'),chassis=slide.querySelector('[data-parts-chassis-art]'),images=[...slide.querySelectorAll('img[data-hero-image-src]')];
    let width=0;const decode=[];
    Object.defineProperty(chassis,'complete',{get:()=>false});Object.defineProperty(chassis,'naturalWidth',{get:()=>width});
    for(const image of images)image.decode=()=>new Promise(resolve=>decode.push(resolve));
    e.select('parts');assert.equal(slide.dataset.partsArt,'pending');assert.equal(decode.length,0);
    width=1200;chassis.dispatchEvent(new e.w.Event('load'));assert.equal(decode.length,5);
    decode.slice(0,4).forEach(resolve=>resolve());await new Promise(resolve=>setImmediate(resolve));assert.equal(slide.dataset.partsArt,'pending');
    decode[4]();await new Promise(resolve=>setImmediate(resolve));assert.equal(slide.dataset.partsArt,'ready');
    e.select('email');e.select('parts');assert.equal(slide.dataset.partsArt,'ready');assert.equal(decode.length,5);
  }finally{e.w.close();}
});
test('a failed artwork load leaves the category links available instead of an endless loading animation',()=>{
  const e=setup();try{
    const slide=e.doc.getElementById('hero-parts'),chassis=slide.querySelector('[data-parts-chassis-art]');
    Object.defineProperty(chassis,'complete',{get:()=>false});Object.defineProperty(chassis,'naturalWidth',{get:()=>0});
    e.select('parts');assert.equal(slide.dataset.partsArt,'pending');chassis.dispatchEvent(new e.w.Event('error'));
    assert.equal(slide.dataset.partsArt,'unavailable');assert.equal(slide.querySelectorAll('[data-parts-category]').length,4);assert.equal(slide.inert,false);
  }finally{e.w.close();}
});
test('only deliberate Pause freezes the hovering motion, while hidden or off-screen motion is suspended',()=>{
  const e=setup();try{
    const toggle=e.doc.querySelector('[data-hero-autoplay]');
    e.select('parts');assert.equal(e.root.hasAttribute('data-hero-motion-still'),false);
    e.tick(2000);toggle.click();assert.equal(e.root.hasAttribute('data-hero-motion-still'),true);
    toggle.click();assert.equal(e.root.hasAttribute('data-hero-motion-still'),false);
    e.hidden(true);assert.equal(e.root.hasAttribute('data-hero-motion-away'),true);
    e.hidden(false);assert.equal(e.root.hasAttribute('data-hero-motion-away'),false);
    e.observers[0].callback([{isIntersecting:false}]);assert.equal(e.root.hasAttribute('data-hero-motion-away'),true);
    e.observers[0].callback([{isIntersecting:true}]);assert.equal(e.root.hasAttribute('data-hero-motion-away'),false);
  }finally{e.w.close();}
});
test('the chassis has a single silhouette; four independent crops have staggered entry and no cover',()=>{
  const mask=new JSDOM(fs.readFileSync(path.join(root,'public/assets/img/home-parts-cutout.svg'),'utf8'),{contentType:'image/svg+xml'});
  try{assert.equal(mask.window.document.querySelectorAll('path').length,1);assert.match(mask.window.document.querySelector('path').getAttribute('d'),/^M46 561 /);}finally{mask.window.close();}
  const style=fs.readFileSync(path.join(root,'public/assets/css/home-parts-slide.css'),'utf8');
  const delays=[...style.matchAll(/--rise-delay:([\d.]+)s/g)].map(m=>Number(m[1]));
  assert.equal(delays.length,4);assert.ok(delays.every((delay,i)=>!i||delay>delays[i-1]));
  assert.equal([...style.matchAll(/--part-mask:url\(/g)].length,4);
});
