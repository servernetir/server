const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/growth-cta.js'), 'utf8');
function environment(page = '/en/vps/hourly', enabled = true) {
  const handlers = {}, events = [];
  const document = {documentElement: {lang: 'en'}, addEventListener(name, callback) { (handlers[name] ||= []).push(callback); }};
  const window = {location: new URL('https://servernet.cloud' + page)};
  if (enabled) window.ServerNetAnalytics = {track(name, params) { events.push({name, params}); }};
  const context = vm.createContext({window, document, URL});
  vm.runInContext(source, context);
  function click(href, prevented = false) {
    const anchor = {href, closest() {return null;}};
    const event = {defaultPrevented: prevented, target: {closest() {return anchor;}}};
    for (const callback of handlers.click || []) callback(event);
  }
  return {window, handlers, events, context, click};
}
test('one order event per click, with no query or token in its payload', () => {
  const e = environment();
  vm.runInContext(source, e.context); // duplicate inclusion must not bind twice
  e.click('https://console.servernet.cloud/en/account/cloud-store?billing_mode=hourly&token=private');
  assert.equal(e.events.length, 1);
  assert.equal(e.events[0].name, 'product_cta_click');
  assert.equal(e.events[0].params.product_line, 'hourly_vps');
  assert.equal(e.events[0].params.cta_action, 'order');
  assert.equal(e.events[0].params.source_page, '/en/vps/hourly');
  assert.ok(!JSON.stringify(e.events).includes('private'));
});
test('unrelated, lookalike and prevented links are ignored', () => {
  const e = environment();
  e.click('https://example.com/order/a');
  e.click('https://console.servernet.cloud.example.com/order/a');
  e.click('mailto:someone@example.com');
  e.click('https://console.servernet.cloud/order/a', true);
  e.click('https://servernet.cloud/en/blog/test');
  assert.equal(e.events.length, 0);
});
test('GPU navigation and a valid request are different steps, without a submit-button click duplicate', () => {
  const e = environment('/en/gpu');
  e.click('https://servernet.cloud/en/gpu#gpu-configure');
  for (const callback of e.handlers.submit) {
    callback({defaultPrevented: false, target: {id: 'gpu-configure', checkValidity: () => false}});
    callback({defaultPrevented: true, target: {id: 'gpu-configure', checkValidity: () => true}});
    callback({defaultPrevented: false, target: {id: 'gpu-configure', checkValidity: () => true}});
  }
  assert.deepEqual(e.events.map(event => event.params.cta_action), ['configure', 'request_gpu']);
});
test('disabled analytics and non-target pages cause no events or navigation changes', () => {
  const e = environment('/en/vps/hourly', false);
  assert.doesNotThrow(() => e.click('https://console.servernet.cloud/account/cloud-store'));
  assert.equal(e.events.length, 0);
  const other = environment('/en/account/home');
  assert.equal(Object.keys(other.handlers).length, 0);
});
