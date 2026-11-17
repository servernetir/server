const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const sandbox = {module: {exports: {}}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/gpu-live-capacity.js'), 'utf8'), sandbox);
const {classify} = sandbox.module.exports;
const now = Date.now();
const valid = {ok: true, available: true, count: 3, checked_at: new Date(now).toISOString()};

test('reports only consistent, fresh capacity for the selected profile', () => {
  assert.equal(classify(valid, now), 'available');
  assert.equal(classify({...valid, count: 0, available: false}, now), 'waiting');
});
test('unknown, malformed and contradictory responses never claim availability', () => {
  for (const data of [null, {}, {...valid, ok: false}, {...valid, count: -1}, {...valid, count: '3'},
    {...valid, available: false}, {...valid, checked_at: 'invalid'}, {...valid, count: null}]) {
    assert.equal(classify(data, now), 'unknown');
  }
});
test('old or implausibly future timestamps cannot retain an available badge', () => {
  for (const offset of [-120001, 120001]) {
    assert.equal(classify({...valid, checked_at: new Date(now + offset).toISOString()}, now), 'unknown');
  }
});

function browserHarness(plans, responseFor) {
  let clock = now, tick, search, calls = [];
  const cards = plans.map(plan => ({hidden: false, dataset: {gpuName: plan}}));
  const nodes = plans.map((plan, i) => ({
    isConnected: true, dataset: {capacityUrl: '/gpu/capacity', plan, available: '{count} available',
      waiting: 'Waiting', unknown: 'Unknown', checked: 'Checked'},
    label: {textContent: ''}, time: {textContent: '', removeAttribute() {}},
    querySelector(s) {return s.includes('label') ? this.label : this.time;},
    closest() {return cards[i].hidden ? cards[i] : null;},
    getBoundingClientRect() {return {width: 100, height: 30, top: 20, bottom: 50};},
  }));
  const input = {value: '', addEventListener(_, fn) {search = fn;}};
  const document = {hidden: false, documentElement: {lang: 'en'}, addEventListener() {},
    querySelectorAll(s) {return s === '[data-gpu-live-capacity]' ? nodes : s === '[data-gpu-search]' ? [input] : cards;}};
  class Clock extends Date {static now() {return clock;}}
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/gpu-live-capacity.js'), 'utf8'), {
    document, window: {innerHeight: 800, location: new URL('http://localhost/')}, URL, Date: Clock, AbortController,
    setInterval(fn) {tick = fn;}, setTimeout() {return 1;}, clearTimeout() {},
    async fetch(url) {calls.push(url); return {ok: true, async json() {return responseFor(new URL(url).searchParams.get('plan'), clock);}};},
  });
  return {nodes, cards, calls, document, input, search: () => search(), tick: () => tick(),
    advance(ms) {clock += ms;}, flush: () => new Promise(resolve => setImmediate(resolve))};
}

test('one request updates duplicate cards and distinguishes zero capacity from unknown', async () => {
  const ui = browserHarness(['RTX 4090', 'RTX 4090', 'GTX 1650', 'RTX 3050'], (plan, time) =>
    plan === 'RTX 3050' ? {ok: false} : {ok: true, available: plan !== 'GTX 1650', count: plan === 'GTX 1650' ? 0 : 2,
      checked_at: new Date(time).toISOString()});
  await ui.flush(); await ui.tick(); await ui.tick();
  assert.equal(ui.calls.length, 3);
  assert.deepEqual(ui.nodes.map(n => n.dataset.state), ['available', 'available', 'waiting', 'unknown']);
});

test('stale availability is removed while hidden and hidden pages make no new requests', async () => {
  const ui = browserHarness(['RTX 4090'], (_, time) => ({ok: true, available: true, count: 1, checked_at: new Date(time).toISOString()}));
  await ui.flush(); assert.equal(ui.nodes[0].dataset.state, 'available');
  ui.document.hidden = true; ui.advance(60001); await ui.tick();
  assert.equal(ui.nodes[0].dataset.state, 'unknown'); assert.equal(ui.calls.length, 1);
  ui.document.hidden = false; await ui.tick();
  assert.equal(ui.calls.length, 2); assert.equal(ui.nodes[0].dataset.state, 'available');
});

test('search skips hidden cards and polling stays below the API request budget', async () => {
  const ui = browserHarness(Array.from({length: 30}, (_, i) => 'GPU '+i), (_, time) =>
    ({ok: true, available: true, count: 1, checked_at: new Date(time).toISOString()}));
  await ui.flush();
  ui.input.value = 'GPU 29'; ui.search(); await ui.flush();
  assert.equal(new URL(ui.calls[1]).searchParams.get('plan'), 'GPU 29');
  assert.equal(ui.cards.filter(c => !c.hidden).length, 1);
  ui.input.value = ''; ui.search(); await ui.flush();
  for (let i = 0; i < 40; i++) {await ui.tick();}
  assert.equal(ui.calls.length, 20);
});
