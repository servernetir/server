const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/site.js'), 'utf8');

function setup(dir = 'rtl') {
  const dom = new JSDOM(`<!doctype html><html dir="${dir}"><head><style>.drawer:not(.open){visibility:hidden}</style></head><body style="overflow:auto">
    <div class="header-wrap"><a href="/" id="logo">Home</a><button id="hamburger" aria-expanded="false">Menu</button></div>
    <main><a id="outside" href="/product">Product</a></main><div id="existing-inert" inert>Private panel</div>
    <div id="drawer-backdrop" aria-hidden="true"></div>
    <aside id="drawer" class="drawer" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1" inert>
      <a href="/" id="drawer-logo">Home</a><button id="drawer-close">Close</button>
      <div class="acc"><button class="acc-head" id="products">Products</button><div class="acc-body"><a id="product-link" href="/hosting">Hosting</a></div></div>
      <div class="acc"><button class="acc-head" id="services">Services</button><div class="acc-body"><a id="service-link" href="/support">Support</a></div></div>
      <button disabled id="disabled">Disabled</button><a href="/contact" id="last">Contact</a>
    </aside></body></html>`, {url:'https://servernet.test/', runScripts:'outside-only', pretendToBeVisual:true});
  const w = dom.window, doc = w.document;
  Object.defineProperty(w, 'innerWidth', {value:390, writable:true});
  w.matchMedia = () => ({matches:true});
  w.IntersectionObserver = class {observe(){} unobserve(){}};
  const frames = [];
  w.requestAnimationFrame = callback => {frames.push(callback); return frames.length;};
  w.HTMLElement.prototype.getClientRects = function () {return this.hidden ? [] : [{width:100,height:40}];};
  w.HTMLElement.prototype.scrollIntoView = () => {};
  w.eval(source);
  const click = id => doc.getElementById(id).click();
  const key = (key, shiftKey = false) => {
    const e = new w.KeyboardEvent('keydown', {key,shiftKey,bubbles:true,cancelable:true});
    doc.activeElement.dispatchEvent(e); return e;
  };
  const open = () => {doc.getElementById('hamburger').focus(); click('hamburger');};
  const frame = () => frames.splice(0).forEach(callback => callback(0));
  return {dom,w,doc,click,key,open,frame};
}

test('open is modal, moves focus inside, and Escape restores prior focus and scroll state', t => {
  const x = setup(); t.after(() => x.dom.window.close()); x.open();
  const drawer = x.doc.getElementById('drawer');
  assert.equal(x.doc.activeElement.id,'drawer-close');
  assert.equal(drawer.hasAttribute('inert'),false); assert.equal(drawer.getAttribute('aria-hidden'),'false');
  assert.equal(x.doc.getElementById('hamburger').getAttribute('aria-expanded'),'true');
  assert.equal(x.doc.querySelector('main').hasAttribute('inert'),true);
  assert.equal(x.doc.body.style.overflow,'hidden');
  assert.equal(x.key('Escape').defaultPrevented,true);
  assert.equal(x.doc.activeElement.id,'hamburger'); assert.equal(drawer.hasAttribute('inert'),true);
  assert.equal(drawer.getAttribute('aria-hidden'),'true'); assert.equal(x.doc.body.style.overflow,'auto');
  assert.equal(x.doc.querySelector('main').hasAttribute('inert'),false);
  assert.equal(x.doc.getElementById('existing-inert').hasAttribute('inert'),true);
});

test('keyboard focus wraps within the visible drawer in RTL and LTR, and rejects background focus', t => {
  for (const dir of ['rtl','ltr']) {
    const x = setup(dir); t.after(() => x.dom.window.close()); x.open();
    x.doc.getElementById('last').focus(); assert.equal(x.key('Tab').defaultPrevented,true);
    assert.equal(x.doc.activeElement.id,'drawer-logo');
    assert.equal(x.key('Tab',true).defaultPrevented,true); assert.equal(x.doc.activeElement.id,'last');
    x.doc.getElementById('outside').focus(); assert.equal(x.doc.activeElement.id,'drawer-close');
  }
});

test('collapsed accordion links stay inert; opening another section updates the accessible state', t => {
  const x = setup(); t.after(() => x.dom.window.close()); x.open();
  const head = x.doc.getElementById('products'), body = x.doc.getElementById(head.getAttribute('aria-controls'));
  assert.equal(head.getAttribute('aria-expanded'),'false'); assert.equal(body.hasAttribute('inert'),true);
  x.click('products'); assert.equal(head.getAttribute('aria-expanded'),'true'); assert.equal(body.hasAttribute('inert'),false);
  x.click('services'); assert.equal(head.getAttribute('aria-expanded'),'false'); assert.equal(body.hasAttribute('inert'),true);
  assert.equal(x.doc.getElementById('services').getAttribute('aria-expanded'),'true');
});

test('close button and backdrop both restore state; resizing to desktop releases the modal', t => {
  const x = setup(); t.after(() => x.dom.window.close());
  for (const control of ['drawer-close','drawer-backdrop']) {
    x.open(); x.click(control); assert.equal(x.doc.getElementById('drawer').classList.contains('open'),false);
    assert.equal(x.doc.activeElement.id,'hamburger'); assert.equal(x.doc.querySelector('main').hasAttribute('inert'),false);
  }
  x.open(); x.w.innerWidth = 1200; x.w.dispatchEvent(new x.w.Event('resize'));
  assert.equal(x.doc.getElementById('drawer').hasAttribute('inert'),true);
  assert.equal(x.doc.getElementById('hamburger').getAttribute('aria-expanded'),'false');
  assert.equal(x.doc.activeElement.id,'logo'); assert.equal(x.doc.body.style.overflow,'auto');
});

test('opening recovers focus after a hidden animation frame, but never steals a choice inside', t => {
  const x = setup(); t.after(() => x.dom.window.close());
  const nativeFocus = x.w.HTMLElement.prototype.focus;
  let rendered = false;
  x.w.HTMLElement.prototype.focus = function (...args) {
    if (this.closest('#drawer') && !rendered) return;
    return nativeFocus.apply(this,args);
  };
  x.open(); assert.equal(x.doc.activeElement.id,'hamburger');
  rendered = true; x.frame(); assert.equal(x.doc.activeElement.id,'drawer-close');
  x.doc.getElementById('last').focus();
  x.doc.getElementById('drawer').dispatchEvent(new x.w.Event('transitionend',{bubbles:true}));
  assert.equal(x.doc.activeElement.id,'last');
});

test('transition completion recovers a longer visibility delay and ignores a drawer closed before its frame', t => {
  const x = setup(); t.after(() => x.dom.window.close());
  const nativeFocus = x.w.HTMLElement.prototype.focus;
  let rendered = false;
  x.w.HTMLElement.prototype.focus = function (...args) {
    if (this.closest('#drawer') && !rendered) return;
    return nativeFocus.apply(this,args);
  };
  x.open(); x.frame(); assert.equal(x.doc.activeElement.id,'hamburger');
  rendered = true;
  x.doc.getElementById('drawer').dispatchEvent(new x.w.Event('transitionend',{bubbles:true}));
  assert.equal(x.doc.activeElement.id,'drawer-close');
  x.click('drawer-close'); x.open(); x.click('drawer-close'); x.frame();
  assert.equal(x.doc.activeElement.id,'hamburger');
});
