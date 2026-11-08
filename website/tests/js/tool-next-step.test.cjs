const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const partial = fs.readFileSync(path.resolve(__dirname, '../../resources/views/partials/tool-next-step.blade.php'), 'utf8');
const source = partial.match(/<script>([\s\S]*?)<\/script>/)[1];

function mount({ analytics = true, linkExists = true } = {}) {
  const events = [];
  const handlers = {};
  const link = {
    dataset: { toolSlug: 'jwt-decoder', toolProduct: 'vps' },
    addEventListener(name, listener) { handlers[name] = listener; },
  };
  const window = {
    location: { href: 'https://servernet.cloud/webtools/jwt-decoder?token=private-token', search: '?token=private-token' },
  };
  if (analytics) window.ServerNetAnalytics = { track(name, params) { events.push(JSON.parse(JSON.stringify({ name, params }))); } };
  const document = {
    querySelector(selector) {
      assert.equal(selector, '[data-tool-product]', 'CTA tracking may only read its own product link');
      return linkExists ? link : null;
    },
  };
  vm.runInNewContext(source, { window, document });
  return { events, handlers };
}

const enabled = mount();
assert.equal(enabled.events.length, 0, 'Rendering alone is not a product click');
enabled.handlers.click();
assert.deepEqual(enabled.events, [{ name: 'tool_product_click', params: { tool_slug: 'jwt-decoder', product_family: 'vps' } }]);
assert.ok(!JSON.stringify(enabled.events).includes('private-token'));

const disabled = mount({ analytics: false });
assert.doesNotThrow(() => disabled.handlers.click());
assert.deepEqual(disabled.events, []);
assert.deepEqual(mount({ linkExists: false }).handlers, {});

console.log('Tool CTA tracking: click-only event, fixed metadata, no input/query data, safe without analytics (passed)');
