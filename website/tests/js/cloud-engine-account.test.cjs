const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(__dirname + '/../../public/assets/js/cloud-engine-account.js', 'utf8');

function setup(fetcher, endpoint = '/account/cloud/1/engine/status', locale = 'en', metricsUrl = null) {
  const dom = new JSDOM('<html lang="' + locale + '"><body><section data-engine-panel><b data-engine-operation-action>Power</b><span data-engine-operation-status>Queued</span><b data-engine-coverage>Unknown</b><span data-engine-in>0</span><span data-engine-out>0</span><b data-engine-uptime>—</b><b data-engine-boot>—</b><b data-engine-cpu>—</b><b data-engine-memory>—</b><b data-engine-disk>—</b><b data-engine-guest-disk>Not measured</b><b data-engine-net-in>—</b><b data-engine-net-out>—</b><button data-engine-mutation>Reboot</button></section></body></html>', {url: 'https://panel.example.test/account/cloud/1', runScripts: 'outside-only', pretendToBeVisual: true});
  const w = dom.window, panel = w.document.querySelector('section');
  panel.dataset.statusUrl = endpoint;
  if (metricsUrl) panel.dataset.metricsUrl = metricsUrl;
  panel.dataset.timeZone = 'Asia/Tehran';
  panel.dataset.labels = JSON.stringify({statuses: {pending: 'Queued', succeeded: 'Completed', pending_review: 'Review'}, actions: {power: 'Power'}, coverage: {unknown: 'Unknown', incomplete: 'Gaps', observed_intervals_only: 'Observed'}, none: 'None'});
  const callbacks = {};
  let cleared = false;
  w.fetch = fetcher;
  w.setInterval = (fn, ms) => {callbacks[ms] = fn; return ms;};
  w.clearInterval = () => {cleared = true;};
  w.eval(source);
  return {dom, w, panel, refresh: () => callbacks[5000]?.(), metrics: () => callbacks[30000]?.(), scheduled: () => typeof callbacks[5000] === 'function', cleared: () => cleared};
}

test('poll uses the session route, preserves large decimal byte totals and renders observed operation state', async t => {
  let request;
  const x = setup(async (url, options) => {
    request = {url, options};
    return {ok: true, json: async () => ({ok: true, data: {operation: {action: 'power', status: 'pending'}, busy: true, traffic: {coverage: 'incomplete', in_bytes: '9007199254740993', out_bytes: '1048576'}}})};
  });
  t.after(() => x.dom.window.close());
  await x.refresh();
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(new URL(request.url).origin, 'https://panel.example.test');
  assert.equal(x.panel.querySelector('[data-engine-in]').textContent, '9,007,199,254,740,993');
  assert.equal(x.panel.querySelector('[data-engine-operation-status]').textContent, 'Queued');
  assert.equal(x.panel.querySelector('[data-engine-coverage]').textContent, 'Gaps');
  assert.equal(x.panel.querySelector('button').disabled, true);
});

test('HTTP and malformed polling responses retain the last observed state instead of claiming completion', async t => {
  let ok = false;
  const x = setup(async () => ({ok, json: async () => ({ok: true, data: {operation: {action: '<img src=x>', status: 'unknown'}, traffic: {coverage: 'unknown', in_bytes: 9007199254740992, out_bytes: '<img>'}}})}));
  t.after(() => x.dom.window.close());
  await x.refresh();
  assert.equal(x.panel.querySelector('[data-engine-operation-status]').textContent, 'Queued');
  ok = true;
  await x.refresh();
  assert.equal(x.panel.querySelector('[data-engine-operation-action]').textContent, 'None');
  assert.equal(x.panel.querySelector('[data-engine-operation-status]').textContent, 'Review');
  assert.equal(x.panel.querySelector('[data-engine-in]').textContent, '0');
  assert.equal(x.panel.querySelectorAll('img').length, 0);
});

test('billing polling preserves exact contract amounts and renders labels as text', async t => {
  const x = setup(async () => ({ok: true, json: async () => ({ok: true, data: {
    operation: null, traffic: {coverage: 'unknown', in_bytes: '0', out_bytes: '0'},
    billing: {label: '<img src=x onerror=alert(1)>', amount: '9007199254740993', next_due_at: '2027-01-31'}
  }})}));
  t.after(() => x.dom.window.close());
  x.panel.insertAdjacentHTML('beforeend', '<b data-engine-billing-cycle></b><b data-engine-billing-amount></b><b data-engine-billing-due></b>');
  await x.refresh();
  assert.equal(x.panel.querySelector('[data-engine-billing-amount]').textContent, '9,007,199,254,740,993');
  assert.equal(x.panel.querySelector('[data-engine-billing-cycle]').textContent, '<img src=x onerror=alert(1)>');
  assert.equal(x.panel.querySelector('[data-engine-billing-due]').textContent, '2027-01-31');
  assert.equal(x.panel.querySelectorAll('img').length, 0);
});

test('cross-origin endpoints are rejected and Persian digits preserve the full integer', async t => {
  const rejected = setup(async () => {throw Error('must not send');}, 'https://other.example.test/status');
  t.after(() => rejected.dom.window.close());
  assert.equal(rejected.scheduled(), false);
  const x = setup(async () => ({ok: true, json: async () => ({ok: true, data: {operation: null, traffic: {coverage: 'unknown', in_bytes: '9007199254740993', out_bytes: '0'}}})}), '/account/cloud/1/engine/status', 'fa');
  t.after(() => x.dom.window.close());
  await x.refresh();
  assert.equal(x.panel.querySelector('[data-engine-in]').textContent, '۹,۰۰۷,۱۹۹,۲۵۴,۷۴۰,۹۹۳');
  x.w.dispatchEvent(new x.w.Event('pagehide'));
  assert.equal(x.cleared(), true);
});

test('boot estimate comes from observed uptime, uses the customer timezone and never substitutes creation time', async t => {
  let current = {uptime_seconds: 3600, last_boot_at: '2026-11-03T00:00:00+00:00', boot_time_estimated: true, observed_at: '2026-11-03T01:00:00+00:00', cpu_fraction: 0.25, memory_bytes: 1048576, disk_allocated_bytes: 21474836480, guest_filesystem_used_bytes: null};
  const x = setup(async () => ({ok: true, json: async () => ({ok: true, current, series: {net_in: [[100, 125.5]], net_out: [[100, 250]]}})}), '/account/cloud/1/engine/status', 'en', '/account/cloud/1/metrics');
  t.after(() => x.dom.window.close());
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(x.panel.querySelector('[data-engine-uptime]').textContent, '3600');
  assert.match(x.panel.querySelector('[data-engine-boot]').textContent, /3:30:00/);
  assert.equal(x.panel.querySelector('[data-engine-boot]').title, '2026-11-03T01:00:00+00:00');
  assert.equal(x.panel.querySelector('[data-engine-cpu]').textContent, '25%');
  assert.equal(x.panel.querySelector('[data-engine-memory]').textContent, '1,048,576');
  assert.equal(x.panel.querySelector('[data-engine-disk]').textContent, '21,474,836,480');
  assert.equal(x.panel.querySelector('[data-engine-guest-disk]').textContent, 'Not measured');
  assert.equal(x.panel.querySelector('[data-engine-net-in]').textContent, '125.5');
  current = {uptime_seconds: null, last_boot_at: null, boot_time_estimated: false, created_at: '2026-11-03T00:00:00+00:00'};
  await x.metrics();
  assert.equal(x.panel.querySelector('[data-engine-uptime]').textContent, '—');
  assert.equal(x.panel.querySelector('[data-engine-boot]').textContent, '—');
});
