const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/gpu-request-expiry.js'), 'utf8');

function countdown(overrides = {}) {
  const attributes = {
    'data-deadline-ms': '1761530400000',
    'data-server-now-ms': '1761444000000',
    'data-locale': 'en',
    'data-remaining-format': ':hours h :minutes min remaining',
    'data-passed-label': 'The funding window has ended. Status is being checked.',
    ...overrides,
  };
  let now = 0, network = 0, formWrites = 0;
  const output = { textContent: 'server-rendered countdown' };
  const notice = {
    hasAttribute: (name) => Object.hasOwn(attributes, name),
    getAttribute: (name) => attributes[name] ?? null,
    setAttribute(name, value) { attributes[name] = value; },
    querySelector(selector) { assert.equal(selector, '[data-expiry-countdown]'); return output; },
  };
  const intervals = new Map(), events = {};
  const context = vm.createContext({
    Intl, performance: { now: () => now },
    Date: { now: () => { throw Error('Client wall clock must not control expiry'); } },
    document: {
      readyState: 'complete', hidden: false,
      querySelectorAll(selector) { assert.equal(selector, '[data-gpu-request-expiry]'); return [notice]; },
      addEventListener(name, callback) { events[name] = callback; },
      createElement() { formWrites++; throw Error('No forms may be written'); },
    },
    setInterval(callback) { const id = intervals.size + 1; intervals.set(id, callback); return id; },
    clearInterval(id) { intervals.delete(id); },
    fetch() { network++; throw Error('No request may be sent'); },
    XMLHttpRequest() { network++; throw Error('No request may be sent'); },
  });
  const execute = () => vm.runInContext(source, context);
  execute();
  return {
    output, attributes, intervals, events, execute,
    tick(milliseconds) { now = milliseconds; [...intervals.values()].forEach(callback => callback()); },
    get network() { return network; }, get formWrites() { return formWrites; },
  };
}

test('uses the server clock and rounds remaining minutes up through the exact deadline', () => {
  const w = countdown();
  assert.equal(w.output.textContent, '24 h 0 min remaining');
  w.tick(86400000 - 1);
  assert.equal(w.output.textContent, '0 h 1 min remaining');
  w.tick(86400000);
  assert.equal(w.output.textContent, w.attributes['data-passed-label']);
  assert.equal(w.intervals.size, 0);
});

test('client wall clock skew cannot change the countdown; visibility refresh uses elapsed time', () => {
  const w = countdown();
  w.tick(3600000);
  w.events.visibilitychange();
  assert.equal(w.output.textContent, '23 h 0 min remaining');
});

test('Persian and Turkish countdowns use their locale and translated formats', () => {
  const fa = countdown({ 'data-locale': 'fa', 'data-remaining-format': ':hours ساعت و :minutes دقیقه باقی‌مانده' });
  assert.equal(fa.output.textContent, '۲۴ ساعت و ۰ دقیقه باقی‌مانده');
  const tr = countdown({ 'data-locale': 'tr', 'data-remaining-format': ':hours sa :minutes dk kaldı' });
  assert.equal(tr.output.textContent, '24 sa 0 dk kaldı');
});

test('an already elapsed window shows the server-provided informational label', () => {
  const w = countdown({ 'data-deadline-ms': '1761444000000' });
  assert.equal(w.output.textContent, w.attributes['data-passed-label']);
  assert.equal(w.intervals.size, 0);
  assert.equal(w.network, 0);
  assert.equal(w.formWrites, 0);
});

test('invalid timestamps preserve the server-rendered fallback without starting a timer', () => {
  for (const invalid of ['', 'tomorrow', 'NaN', '-1', '1e12', '9999999999999999999']) {
    const w = countdown({ 'data-deadline-ms': invalid });
    assert.equal(w.output.textContent, 'server-rendered countdown');
    assert.equal(w.intervals.size, 0);
    assert.equal(w.attributes['data-expiry-mounted'], undefined);
  }
});

test('mounting twice is idempotent and translated text is rendered literally', () => {
  const w = countdown({ 'data-remaining-format': '<strong>:hours</strong> :minutes' });
  w.execute();
  assert.equal(w.intervals.size, 1);
  assert.equal(w.output.textContent, '<strong>24</strong> 0');
});

test('expiry does not issue a network call or modify a form or request state', () => {
  const w = countdown();
  w.tick(86400001);
  w.events.visibilitychange();
  assert.equal(w.network, 0);
  assert.equal(w.formWrites, 0);
  assert.equal(w.attributes['data-request-status'], undefined);
  assert.equal(w.attributes['data-cancelled'], undefined);
});
