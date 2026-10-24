/* Behavioral checks for the shipped Atlas source and compact bundle. */
async function runAtlasChecks(readAsset, report) {
  let assertions = 0;
  function check(value, message) { if (!value) throw new Error(message); assertions++; }
  function environment(code, mobile = false, reduced = false) {
    let clock = 0, paints = 0, allocations = 0, nextId = 0;
    const frames = new Map(), observers = [];
    class Target {
      constructor() { this.events = {}; this.dataset = {}; this.attributes = {}; }
      addEventListener(type, fn) { (this.events[type] ||= []).push(fn); }
      dispatchEvent(event) { for (const fn of [...(this.events[event.type] || [])]) fn(event); return true; }
      setAttribute(key, value) { this.attributes[key] = value; }
      setPointerCapture() {}
    }
    function classes() {
      const values = new Set();
      return { contains: x => values.has(x), add: x => values.add(x), remove: x => values.delete(x), toggle(x, on) { on ? values.add(x) : values.delete(x); } };
    }
    const context = new Proxy({
      clearRect() { paints++; },
      createRadialGradient() { return { addColorStop() {} }; },
    }, { get: (obj, key) => key in obj ? obj[key] : () => {} });
    const group = new Target(), host = new Target(), label = {}, description = {}, link = {};
    const points = { IR: [32.4, 53.6], DE: [51.2, 10.4], US: [39, -98] };
    group.dataset.snSelected = 'IR';
    group.dataset.snNodes = JSON.stringify(Object.fromEntries(Object.entries(points).map(([iso, position]) => [iso, { position }])));
    const buttons = Object.keys(points).map(iso => {
      const b = new Target();
      Object.assign(b.dataset, { snRegion: iso, label: iso, description: iso + ' description', url: '/' + iso });
      return b;
    });
    group.querySelectorAll = () => buttons;
    group.querySelector = selector => ({ '[data-sn-region-label]': label, '[data-sn-region-description]': description, '[data-sn-region-link]': link })[selector] || null;
    host.classList = classes();
    host.dataset.landSrc = '/atlas-land.js';
    host.closest = () => group;
    host.getBoundingClientRect = () => ({ width: 600, height: 480 });
    const canvas = { width: 0, getContext: () => context };
    host.querySelector = () => canvas;
    const document = new Target();
    document.readyState = 'loading'; document.hidden = false;
    document.documentElement = new Target(); document.body = { classList: classes() };
    document.querySelector = selector => selector === '[data-sn-region-group]' ? group : selector === '[data-sn-globe]' ? host : null;
    document.querySelectorAll = selector => selector === '[data-sn-region-group]' ? [group] : selector === '[data-sn-globe]' ? [host] : [];
    const window = new Target();
    const media = { mobile: new Target(), reduced: new Target() };
    media.mobile.matches = mobile; media.reduced.matches = reduced;
    window.matchMedia = query => query.includes('reduced-motion') ? media.reduced : media.mobile;
    window.SNAtlasLand = { points: [[0, 0], [20, 30], [40, 50]], coasts: [[]] };
    class IO {
      constructor(fn) { this.fn = fn; this.targets = new Set(); observers.push(this); }
      observe(target) { this.targets.add(target); }
      disconnect() { this.targets.clear(); }
    }
    class Observer { observe() {} disconnect() {} }
    const requestAnimationFrame = fn => { frames.set(++nextId, fn); return nextId; };
    const cancelAnimationFrame = id => frames.delete(id);
    window.IntersectionObserver = IO;
    const globals = {
      window, document, performance: { now: () => clock },
      getComputedStyle: () => ({ getPropertyValue: () => '' }),
      requestAnimationFrame, cancelAnimationFrame, IntersectionObserver: IO, ResizeObserver: Observer, MutationObserver: Observer,
      Path2D: class { moveTo() {} arc() {} }, devicePixelRatio: 1,
      CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
      Float32Array: class extends Float32Array { constructor(values) { super(values); allocations++; } },
    };
    new Function(...Object.keys(globals), code)(...Object.values(globals));
    function step(timestamp) {
      clock = timestamp;
      const pending = [...frames.values()]; frames.clear();
      for (const fn of pending) fn(timestamp);
    }
    function visible(value) {
      for (const observer of observers) if (observer.targets.has(host)) observer.fn([{ target: host, isIntersecting: value }]);
    }
    return {
      group, host, document, window, label, description, link, buttons, step, visible,
      click(iso) { buttons.find(b => b.dataset.snRegion === iso).dispatchEvent({ type: 'click' }); },
      get paints() { return paints; }, get pending() { return frames.size; }, get allocations() { return allocations; },
    };
  }
  let passed = 0;
  for (const filename of ['atlas.js', 'atlas.min.js']) {
    const beforeAssertions = assertions;
    const code = readAsset(filename);
    new Function(code);
    const desktop = environment(code);
    desktop.click('IR'); await Promise.resolve(); await Promise.resolve();
    desktop.visible(true);
    for (let i = 0; i <= 60; i++) desktop.step(i * 1000 / 60);
    check(desktop.paints >= 25 && desktop.paints <= 32, filename + ': decorative desktop render budget exceeded: ' + desktop.paints);
    const before = desktop.allocations;
    desktop.click('DE'); desktop.click('US');
    check(desktop.label.textContent === 'US' && desktop.link.href === '/US', filename + ': latest selection must update immediately');
    check(desktop.buttons[2].attributes['aria-pressed'] === 'true' && desktop.buttons[1].attributes['aria-pressed'] === 'false', filename + ': selected accessibility state');
    check(desktop.allocations === before, filename + ': click handler rebuilt geographic geometry');
    check(desktop.pending === 1, filename + ': click events created duplicate animation loops');
    desktop.step(1100);
    check(desktop.allocations > before, filename + ': pending geometry was never rendered');
    desktop.document.hidden = true; desktop.document.dispatchEvent({ type: 'visibilitychange' });
    const hiddenPaints = desktop.paints;
    desktop.step(1200);
    check(desktop.paints === hiddenPaints && desktop.pending === 0, filename + ': hidden tab still draws');
    desktop.document.hidden = false; desktop.document.dispatchEvent({ type: 'visibilitychange' });
    desktop.step(1300);
    check(desktop.paints > hiddenPaints, filename + ': visible tab did not resume');
    desktop.visible(false);
    const offscreenPaints = desktop.paints; desktop.step(1400);
    check(desktop.paints === offscreenPaints && desktop.pending === 0, filename + ': offscreen globe still draws');
    desktop.visible(true); desktop.step(1500);
    desktop.window.dispatchEvent({ type: 'pagehide' });
    check(desktop.pending === 0, filename + ': pagehide leaked animation');
    desktop.window.dispatchEvent({ type: 'pageshow', persisted: true });
    desktop.visible(true); desktop.step(1600);
    check(desktop.paints > offscreenPaints, filename + ': back-forward cache restore did not resume');

    const mobile = environment(code, true);
    mobile.click('IR'); await Promise.resolve(); await Promise.resolve();
    mobile.visible(true); mobile.step(0);
    check(mobile.pending === 0, filename + ': mobile should rest when selection has settled');
    mobile.click('DE');
    check(mobile.label.textContent === 'DE', filename + ': mobile label waits for animation');
    for (let i = 1; i <= 240 && mobile.pending; i++) mobile.step(i * 1000 / 60);
    check(mobile.pending === 0, filename + ': mobile transition never settled');
    check(Math.abs(Number(mobile.host.dataset.rotation) - 10.4) < .1, filename + ': mobile selected longitude was not reached');
    const settledPaints = mobile.paints; mobile.step(10000);
    check(mobile.paints === settledPaints, filename + ': mobile idle painting resumed');

    const reduced = environment(code, false, true);
    reduced.click('IR'); await Promise.resolve(); await Promise.resolve(); reduced.visible(true);
    reduced.click('DE');
    check(reduced.pending === 0 && reduced.paints > 0 && reduced.label.textContent === 'DE', filename + ': reduced motion selection failed');
    check(Math.abs(Number(reduced.host.dataset.rotation) - 10.4) < .01, filename + ': reduced motion should jump directly to selected longitude');
    reduced.host.dispatchEvent({ type: 'keydown', key: 'ArrowRight', preventDefault() {} });
    await Promise.resolve(); await Promise.resolve();
    check(Number(reduced.host.dataset.rotation) > 10.4 && reduced.pending === 0, filename + ': reduced motion keyboard control failed');
    passed += assertions - beforeAssertions;
    report(filename + ': ' + (assertions - beforeAssertions) + ' behavioral assertions passed');
  }
  return passed;
}
if (typeof module !== 'undefined') {
  module.exports = runAtlasChecks;
  if (require.main === module) {
    const fs = require('node:fs');
    const path = require('node:path');
    runAtlasChecks(name => fs.readFileSync(path.join(__dirname, '../../public/assets/js', name), 'utf8'), console.log)
      .then(count => console.log(count + ' assertions passed'))
      .catch(error => { console.error(error); process.exitCode = 1; });
  }
}
