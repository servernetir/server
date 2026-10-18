const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/cloud-store-globe.js'), 'utf8');
const geography = fs.readFileSync(path.join(__dirname, '../../public/assets/js/cloud-store-geography.js'), 'utf8');

function fixture(options = {}) {
  let now = 0, frameId = 0, intersection;
  const frames = new Map(), canvases = [];
  const target = extra => Object.assign({
    listeners: {},
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); },
    dispatchEvent(event) { (this.listeners[event.type] || []).forEach(callback => callback(event)); },
    emit(type, properties = {}) { const event = { type, preventDefault() { this.defaultPrevented = true; }, ...properties }; this.dispatchEvent(event); return event; }
  }, extra);
  function canvas() {
    const calls = [];
    const methods = ['beginPath', 'arc', 'ellipse', 'fill', 'stroke', 'clearRect', 'drawImage', 'setTransform', 'lineTo', 'moveTo', 'save', 'restore', 'translate', 'rotate', 'fillText'];
    const ctx = Object.fromEntries(methods.map(name => [name, (...args) => calls.push({ name, args, alpha: ctx.globalAlpha, color: ctx.strokeStyle, lineWidth: ctx.lineWidth })]));
    const node = { calls, ctx, style: {}, getContext: () => ctx }; canvases.push(node); return node;
  }
  const displayedCanvas = canvas(), fallback = { hidden: false }, classes = new Set();
  const countryCodes = options.countries || ['IR', 'DE', 'TR', 'SG', 'US', 'GB', 'FR', 'NL', 'AU', 'AE', 'FI', 'CH', 'JP', 'CA', 'BR', 'ZA'];
  const catalogueNodes = countryCodes.map(country => ({ dataset: { country } }));
  const host = target({
    dataset: { country: 'IR', city: 'Tehran', code: 'ir-thr', label: 'Tehran', ...(options.dataset || {}) },
    classList: { add: name => classes.add(name), remove: name => classes.delete(name), toggle(name, value) { if (value) classes.add(name); else classes.delete(name); } },
    getBoundingClientRect: () => ({ left: 0, top: 0, width: 500, height: 500, ...(options.rect || {}) }),
    querySelector: selector => selector === 'canvas' ? displayedCanvas : fallback,
    setPointerCapture() {}, hasPointerCapture: () => true, releasePointerCapture() {}
  });
  const labels = Object.fromEntries(['cvx-globe-name', 'cvx-globe-detail', 'cvx-globe-coordinates'].map(id => [id, {}]));
  const document = target({
    readyState: options.pendingBoot ? 'loading' : 'complete', hidden: false,
    documentElement: { lang: 'fa', getAttribute: () => 'dark' },
    getElementById: id => id === 'cvx-globe' ? host : labels[id],
    querySelectorAll: () => catalogueNodes,
    createElement: name => { assert.equal(name, 'canvas'); return canvas(); }
  });
  const motion = target({ matches: !!options.reduced });
  const requestAnimationFrame = callback => { const id = ++frameId; frames.set(id, callback); return id; };
  const cancelAnimationFrame = id => frames.delete(id);
  class MutationObserver { observe() {} }
  class IntersectionObserver { constructor(callback) { intersection = callback; } observe() {} }
  const window = target({
    devicePixelRatio: 3, matchMedia: () => motion, requestAnimationFrame, cancelAnimationFrame,
    SNAtlasLand: {
      points: [[50, 35], [10, 50], [0, 0]],
      coasts: [[[0, -70], [110, -70], [110, 75], [0, 75], [0, -70]]]
    }
  });
  if (options.intersection) window.IntersectionObserver = IntersectionObserver;
  const selections = [];
  document.addEventListener('cvx:country-select', event => selections.push(JSON.parse(JSON.stringify(event.detail))));
  const context = vm.createContext({ document, window, MutationObserver, IntersectionObserver, performance: { now: () => now },
    getComputedStyle: () => ({ getPropertyValue: () => '' }), requestAnimationFrame, cancelAnimationFrame,
    CustomEvent: class { constructor(type, props) { this.type = type; Object.assign(this, props); } }
  });
  vm.runInContext(geography, context); vm.runInContext(source, context);
  return { host, document, window, motion, frames, canvases, selections, labels,
    step(time) { now = time; const callbacks = [...frames.values()]; frames.clear(); callbacks.forEach(callback => callback(time)); },
    intersect(value) { intersection([{ isIntersecting: value }]); },
    pointer(type, x, y, props = {}) { return host.emit(type, { pointerId: 1, pointerType: 'mouse', button: 0, clientX: x, clientY: y, ...props }); }
  };
}

test('Atlas renders a large local globe, a rich catalogue network and animated packets from cached terrain', () => {
  const page = fixture(); page.step(0);
  assert.equal(page.host.dataset.globeState, 'ready');
  assert.ok(Number(page.host.dataset.globeRoutes) > 25, 'more links than the homepage network');
  assert.ok(page.canvases[0].width / parseFloat(page.canvases[0].style.width) <= 1.5, 'DPR is capped at 1.5 on the padded drawing surface');
  const sphere = page.canvases[1].calls.find(call => call.name === 'arc');
  assert.ok(sphere.args[2] >= 220 && sphere.args[2] <= 250, 'globe fills approximately 90% of the panel');
  const firstPackets = page.canvases[0].calls.filter(call => call.name === 'arc' && call.args[2] < 2).map(call => call.args);
  const terrainPaints = page.canvases[1].calls.filter(call => call.name === 'clearRect').length;
  page.canvases[0].calls.length = 0; page.step(300);
  const nextPackets = page.canvases[0].calls.filter(call => call.name === 'arc' && call.args[2] < 2).map(call => call.args);
  assert.notDeepEqual(firstPackets, nextPackets, 'packets move smoothly between frames');
  assert.equal(page.canvases[1].calls.filter(call => call.name === 'clearRect').length, terrainPaints, 'stationary land is reused');
});

function orbitalSegments(calls) {
  let path = [];
  const segments = [];
  for (const call of calls) {
    if (call.name === 'beginPath') path = [];
    else if (call.name === 'moveTo' || call.name === 'lineTo') path.push(call);
    else if (call.name === 'stroke' && call.color === '#5de5ed' && call.lineWidth === .8) {
      for (let i = 1; i < path.length; i++) if (path[i].name === 'lineTo') segments.push({ a: path[i - 1].args, b: path[i].args, alpha: call.alpha });
    }
  }
  return segments;
}

test('raised orbital arches extend beyond the sphere and original panel without moving marker hit targets', () => {
  const page = fixture(); page.step(0);
  const sphere = page.canvases[1].calls.find(call => call.name === 'arc').args;
  const segments = orbitalSegments(page.canvases[1].calls);
  const vertices = segments.flatMap(segment => [segment.a, segment.b]);
  const furthest = Math.max(...vertices.map(([x, y]) => Math.hypot(x - sphere[0], y - sphere[1])));
  assert.ok(furthest > sphere[2] * 1.3, 'high satellite arches visibly stand off the surface');
  assert.ok(vertices.some(([x, y]) => x < 0 || x > 500 || y < 0 || y > 500), 'the extended canvas preserves off-panel route geometry');
  assert.ok(parseFloat(page.canvases[0].style.left) < 0);
  assert.ok(parseFloat(page.canvases[0].style.width) > 500);
  assert.ok(new Set(segments.map(segment => segment.alpha)).size > 1, 'back-side or horizon routes use quieter opacity');
  page.pointer('pointerdown', 250, 250); page.pointer('pointerup', 250, 250);
  assert.deepEqual(page.selections, [{ country: 'IR' }], 'drawing padding does not shift the catalogue marker hit box');
});

test('a narrow tall globe keeps its 24px top inset and accurate city hit target with orbital overscan', () => {
  const page = fixture({ rect: { width: 350, height: 650 } }); page.step(0);
  const sphere = page.canvases[1].calls.find(call => call.name === 'arc').args;
  assert.ok(Math.abs(sphere[1] - sphere[2] - 24) < .001, 'the large globe does not return to the empty vertical centre');
  page.pointer('pointerdown', sphere[0], sphere[1]); page.pointer('pointerup', sphere[0], sphere[1]);
  assert.deepEqual(page.selections, [{ country: 'IR' }]);
});

test('animated packets follow the rendered elevated segments rather than the old surface routes', () => {
  const page = fixture(); page.step(0);
  const segments = orbitalSegments(page.canvases[1].calls);
  page.canvases[0].calls.length = 0; page.step(1300);
  const packets = page.canvases[0].calls.filter(call => call.name === 'arc' && call.args[2] < 2);
  function distanceToSegment([x, y], { a, b }) {
    const dx = b[0] - a[0], dy = b[1] - a[1];
    const t = Math.max(0, Math.min(1, ((x - a[0]) * dx + (y - a[1]) * dy) / (dx * dx + dy * dy || 1)));
    return Math.hypot(x - a[0] - t * dx, y - a[1] - t * dy);
  }
  assert.ok(packets.length > 10);
  const aligned = packets.filter(packet => Math.min(...segments.map(segment => distanceToSegment(packet.args, segment))) < .25);
  assert.ok(aligned.length / packets.length > .95, 'packets share the exact cached orbital geometry, allowing a soft limb crossing');
  assert.ok(packets.some(packet => Math.hypot(packet.args[0] - 250, packet.args[1] - 250) > 226 * 1.2), 'packets also travel above the limb');
});

test('a country tap emits only browsing intent and preserves the current city preview', () => {
  const page = fixture(); page.step(0);
  page.pointer('pointerdown', 250, 250); page.pointer('pointerup', 250, 250);
  assert.deepEqual(page.selections, [{ country: 'IR' }]);
  assert.equal(page.host.dataset.locationCode, 'ir-thr');
  assert.equal(page.host.dataset.latitude, 35.6892, 'the selected city remains precise');
  page.pointer('pointerdown', 5, 5); page.pointer('pointerup', 5, 5);
  assert.equal(page.selections.length, 1, 'empty canvas does not select the nearest country');
});

test('dragging and pointer cancellation rotate safely without triggering country selection', () => {
  const page = fixture(); page.step(0);
  const initial = Number(page.host.dataset.rotation);
  page.pointer('pointerdown', 250, 250); page.pointer('pointermove', 350, 270); page.step(300);
  assert.notEqual(Number(page.host.dataset.rotation), initial);
  assert.equal(page.host.dataset.globeSettled, 'false');
  page.pointer('pointerup', 350, 270); page.step(600);
  assert.deepEqual(page.selections, []);
  page.pointer('pointerdown', 250, 250); page.pointer('pointercancel', 250, 250); page.step(900);
  assert.equal(page.host.dataset.globeSettled, 'true');
  assert.deepEqual(page.selections, []);
});

test('vertical touch scrolling preserves orientation while horizontal touch dragging rotates', () => {
  const page = fixture(); page.step(0);
  const initial = page.host.dataset.rotation;
  page.pointer('pointerdown', 250, 250, { pointerType: 'touch' });
  page.pointer('pointermove', 251, 310, { pointerType: 'touch' }); page.step(300);
  assert.equal(page.host.dataset.rotation, initial);
  page.pointer('pointerdown', 250, 250, { pointerType: 'touch' });
  page.pointer('pointermove', 320, 252, { pointerType: 'touch' }); page.step(600);
  assert.notEqual(page.host.dataset.rotation, initial);
  page.pointer('pointerup', 320, 252, { pointerType: 'touch' });
  assert.deepEqual(page.selections, []);
});

test('country changes ease over time and take the short path across the dateline', () => {
  const page = fixture({ dataset: { country: 'FJ', city: '', approximate: 'true' }, countries: ['FJ', 'WS'] }); page.step(0);
  assert.ok(Number(page.host.dataset.rotation) > 170);
  page.window.SNCloudStoreGlobe.select({ country: 'WS', approximate: true });
  page.step(300);
  const middle = Number(page.host.dataset.rotation);
  assert.ok(Math.abs(middle) > 170, 'camera stays close to the dateline instead of crossing Greenwich');
  assert.equal(page.host.dataset.globeSettled, 'false');
  page.step(1200);
  assert.ok(Math.abs(Number(page.host.dataset.rotation) + 172.104629) < .001);
  assert.equal(page.host.dataset.globeSettled, 'true');
});

test('keyboard arrows rotate, Home restores the selected city, and Enter browses a real marker', () => {
  const page = fixture(); page.step(0);
  const initial = Number(page.host.dataset.rotation);
  assert.equal(page.host.emit('keydown', { key: 'ArrowRight' }).defaultPrevented, true); page.step(350);
  assert.equal(Number(page.host.dataset.rotation), Number((initial + 14).toFixed(3)));
  page.host.emit('keydown', { key: 'Home' }); page.step(700);
  assert.equal(Number(page.host.dataset.rotation), initial);
  page.host.emit('keydown', { key: 'Enter' });
  assert.deepEqual(page.selections, [{ country: 'IR' }]);
});

test('reduced motion draws once per interaction without packet loops or inertia', () => {
  const page = fixture({ reduced: true }); page.step(0);
  assert.equal(page.frames.size, 0);
  page.window.SNCloudStoreGlobe.select({ country: 'DE', city: 'Frankfurt', code: 'de-fra' }); page.step(300);
  assert.equal(page.host.dataset.rotation, '8.682');
  assert.equal(page.host.dataset.approximate, 'false');
  assert.equal(page.frames.size, 0);
  page.pointer('pointerdown', 250, 250); page.pointer('pointermove', 320, 250); page.pointer('pointerup', 320, 250); page.step(600);
  assert.equal(page.host.dataset.globeSettled, 'true');
  assert.equal(page.frames.size, 0);
});

test('offscreen, hidden and pagehide suspend the animation and pageshow resumes it', () => {
  const page = fixture({ intersection: true });
  assert.equal(page.frames.size, 0); page.intersect(true); page.step(0);
  assert.equal(page.frames.size, 1); page.intersect(false); assert.equal(page.frames.size, 0);
  page.intersect(true); page.step(300); page.document.hidden = true; page.document.emit('visibilitychange'); assert.equal(page.frames.size, 0);
  page.document.hidden = false; page.document.emit('visibilitychange'); page.step(600);
  page.window.emit('pagehide'); assert.equal(page.frames.size, 0);
  page.window.emit('pageshow', { persisted: true }); assert.equal(page.frames.size, 1);
});

test('pending form selection and generic coordinates remain honest, unknown pseudo locations have no fabricated pin', () => {
  const page = fixture({ pendingBoot: true });
  page.document.emit('cvx:location', { detail: { country: 'IR', city: 'Tehran', code: 'ir-ref', approximate: true } });
  page.document.emit('DOMContentLoaded'); page.step(0);
  assert.equal(page.host.dataset.latitude, 32.427908);
  assert.equal(page.host.dataset.approximate, 'true');
  assert.match(page.labels['cvx-globe-coordinates'].textContent, /^≈ /);
  page.window.SNCloudStoreGlobe.select({ country: 'GLOBAL', code: 'global-gpu' }); page.step(300);
  assert.equal(page.host.dataset.focus, '');
  assert.equal(page.host.dataset.latitude, undefined);
  assert.equal(page.labels['cvx-globe-coordinates'].textContent, '—');
});

test('markers and links only use valid catalogue countries and duplicate entries are ignored', () => {
  const page = fixture({ countries: ['IR', 'IR', 'DE', 'GLOBAL', 'XX'] }); page.step(0);
  assert.equal(page.host.dataset.globeRoutes, '1');
  page.pointer('pointerdown', 250, 250); page.pointer('pointerup', 250, 250);
  assert.deepEqual(page.selections, [{ country: 'IR' }]);
});
