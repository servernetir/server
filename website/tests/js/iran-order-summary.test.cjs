const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/pages/order-summary.blade.php'), 'utf8');
const script = [...blade.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1])
  .find(source => source.includes('var cfg = @json($osCfg);'));
assert.ok(script, 'Exercise the actual order-summary interaction script');

function mount(t, restricted) {
  const href = cycle => restricted ? '' : `/go/pay?sku=synthetic&cycle=${cycle}`;
  const dom = new JSDOM(`<form id="os-form">
    <label class="os-opt"><input name="cycle" type="radio" value="monthly" data-href="${href('monthly')}" data-total="Monthly total" data-first="Monthly first" data-label="Monthly" data-saving="0" checked></label>
    <label class="os-opt"><input name="cycle" type="radio" value="yearly" data-href="${href('yearly')}" data-total="Yearly total" data-first="Yearly first" data-label="Yearly" data-saving="20"></label>
    <span id="os-cycle-name"></span><b id="os-total"></b>
    ${restricted ? '<p id="os-iran-note">Verification required</p>' : '<a id="os-cta" href="/go/pay"><span id="os-cta-text"></span></a>'}
  </form>`, { runScripts: 'outside-only', url: 'https://servernet.example/order/synthetic' });
  t.after(() => dom.window.close());
  const config = { cta: ':cycle · :total', beacon: '/fake-beacon', sku: 'synthetic', first: false, ourHost: 'servernet.example' };
  dom.window.eval(script.replace('var cfg = @json($osCfg);', `var cfg = ${JSON.stringify(config)};`)
    .replace(/var SELLS = [^;]+;/, 'var SELLS = true;')
    .replace("var QUOTE_TEXT = @json(__('ui.os_cta_quote'));", 'var QUOTE_TEXT = "Quote";'));
  return dom.window;
}

test('restricted order cycle changes update informational prices without creating or restoring a purchase link', t => {
  const window = mount(t, true);
  const yearly = window.document.querySelector('input[value="yearly"]');
  yearly.checked = true;
  yearly.dispatchEvent(new window.Event('change', { bubbles: true }));
  assert.equal(window.document.getElementById('os-total').textContent, 'Yearly total');
  assert.equal(window.document.getElementById('os-cycle-name').textContent, 'Yearly');
  assert.equal(window.document.getElementById('os-cta'), null);
  assert.equal(window.document.querySelectorAll('a').length, 0);
  assert.equal(window.document.getElementById('os-iran-note').textContent, 'Verification required');
});

test('eligible order cycle changes retain the existing signed-handoff entry point', t => {
  const window = mount(t, false);
  const yearly = window.document.querySelector('input[value="yearly"]');
  yearly.checked = true;
  yearly.dispatchEvent(new window.Event('change', { bubbles: true }));
  const url = new URL(window.document.getElementById('os-cta').href);
  assert.equal(url.pathname, '/go/pay');
  assert.equal(url.searchParams.get('cycle'), 'yearly');
  assert.ok(url.searchParams.get('sid'));
  assert.equal(window.document.getElementById('os-cta-text').textContent, 'Yearly · Yearly total');
});
