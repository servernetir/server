const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');

const assets = path.resolve(__dirname, '../../public/assets/js');
const source = name => fs.readFileSync(path.join(assets, name), 'utf8');
const land = {points:[[35,32],[40,34],[41,35]],coasts:[[[20,20],[60,20],[60,50],[20,50],[20,20]]]};

function setup({mobile=false, hiddenSlide=false, preloadLand=false}={}) {
  const dom = new JSDOM('<section data-hero-slide="infrastructure" aria-hidden="'+hiddenSlide+'"><div data-sn-region-group data-sn-nodes="{}"><div data-sn-home-globe data-country="IR" data-countries=\'["IR","DE"]\' data-land-src="/assets/js/atlas-land.js" tabindex="0"><img class="sn-globe-fallback" src="/fallback.svg"><canvas></canvas></div></div></section>', {runScripts:'outside-only',pretendToBeVisual:true,url:'https://servernet.cloud/'});
  const w = dom.window, doc = w.document, host = doc.querySelector('[data-sn-home-globe]'), slide = host.closest('section');
  let serial=0, intersection, frames=new Map(), idle=[];
  w.matchMedia = query => ({matches:query.includes('max-width') && mobile,addEventListener(){}});
  Object.defineProperty(doc,'readyState',{get:()=> 'complete',configurable:true});
  w.IntersectionObserver = class {constructor(cb){intersection=cb;}observe(){}};
  w.ResizeObserver = class {observe(){}};
  w.requestIdleCallback = fn => {idle.push(fn);return idle.length;};
  w.requestAnimationFrame = cb => {frames.set(++serial,cb);return serial;};
  w.cancelAnimationFrame = id => frames.delete(id);
  w.HTMLCanvasElement.prototype.getContext = () => new Proxy({}, {get:(target,key)=> target[key] || (()=>{})});
  host.getBoundingClientRect = () => ({width:640,height:640,left:0,top:0});
  if (preloadLand) w.SNAtlasLand=land;
  w.eval(source('cloud-store-geography.js'));
  w.eval(source('atlas-home-globe.js'));
  intersection([{isIntersecting:true}]);
  return {dom,w,doc,host,slide,
    idle:()=>{const callbacks=idle.splice(0);callbacks.forEach(fn=>fn());},
    scripts:()=>[...doc.querySelectorAll('script[src$="atlas-land.js"]')],
    region:country=>host.parentElement.dispatchEvent(new w.CustomEvent('sn-atlas-region',{detail:country})),
    frames:()=>frames.size,
    tick:(time=1000)=>{const callbacks=[...frames.values()];frames.clear();callbacks.forEach(fn=>fn(time));},
  };
}

test('mobile poster is sufficient until the visitor chooses a region',()=>{
  const e=setup({mobile:true});try{
    e.idle();assert.equal(e.scripts().length,0);assert.equal(e.frames(),0);
    assert.equal(e.host.dataset.globeState,'waiting');assert.equal(e.host.querySelector('img').hidden,false);
    e.region('DE');e.region('IR');assert.equal(e.scripts().length,1);
    assert.equal(e.scripts()[0].getAttribute('fetchpriority'),'low');
    e.w.SNAtlasLand=land;e.scripts()[0].onload();e.tick();
    assert.equal(e.host.dataset.globeState,'ready');assert.equal(e.host.querySelector('img').hidden,true);
  }finally{e.w.close();}
});
test('desktop enhances after idle and stops the actual frame loop when the slide changes',async()=>{
  const e=setup({preloadLand:true});try{
    assert.equal(e.frames(),0);e.idle();assert.equal(e.frames(),1);e.tick(1000);
    assert.equal(e.host.dataset.globeState,'ready');assert.equal(e.frames(),1);
    e.slide.setAttribute('aria-hidden','true');await Promise.resolve();
    assert.equal(e.frames(),0);assert.equal(e.host.dataset.autoRotating,'false');
    e.tick(1500);assert.equal(e.frames(),0);
    e.slide.setAttribute('aria-hidden','false');await Promise.resolve();
    assert.equal(e.frames(),1);e.tick(2000);assert.equal(e.frames(),1);
  }finally{e.w.close();}
});
test('a hidden slide does not fetch decorative geography, including an already queued idle task',async()=>{
  const e=setup();try{
    e.slide.setAttribute('aria-hidden','true');await Promise.resolve();e.idle();
    assert.equal(e.scripts().length,0);assert.equal(e.frames(),0);
    e.slide.setAttribute('aria-hidden','false');await Promise.resolve();e.idle();
    assert.equal(e.scripts().length,1);
  }finally{e.w.close();}
});
