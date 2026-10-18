const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/account/cloud-store.blade.php'), 'utf8');
const inlineScripts = [...blade.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]);
const mainScript = inlineScripts.find(script => script.includes('var D = @json($jsData);'));
assert.ok(mainScript, 'Run the actual storefront wizard script from its Blade source');
const sshScript = inlineScripts.find(script => script.includes("var pick = document.getElementById('cvb-ssh-pick');"));
assert.ok(sshScript, 'Include the actual SSH checkout handler when testing early submission');
const cardsScript = fs.readFileSync(path.join(__dirname, '../../public/assets/js/cloud-image-cards.js'), 'utf8');

function fixture(t, options = {}) {
  const gpu = !!options.gpu;
  const steps = gpu ? [3, 4] : [1, 3, 4];
  const words = { resources: 'Continue to resources', image: gpu ? 'Continue to application' : 'Continue to operating system', pay: gpu ? 'Request GPU capacity' : 'Pay and create server' };
  const data = {
    gpu, cta: words, openStep: options.openStep || steps[0],
    gpuCapacityUrl: '/synthetic-capacity', gpuCapacityUnknown: 'Checking capacity',
    prices: { plan: { monthly: { cycle: 570000, setup: 0, save: 0 } }, empty: { monthly: { cycle: 570000, setup: 0, save: 0 } } },
    images: { plan: { os: ['u24', 'u22'], app: ['d27'] }, empty: { os: [], app: [] } },
    cycles: { monthly: 'Monthly' }, months: { monthly: 1 }, plans: { plan: 'CV-2-4', empty: 'No compatible image' },
    specs: {}, imgLbl: {}, imgLogo: {}, metal: {}, addon: {}, hourly: {},
    imageCardWords: { os: 'Operating system', app: 'Application', version: 'Version', selected: 'Selected', ready: 'Ready', continue: 'Continue' },
    extraIp: 0, tax: 10, credit: 10000000, rate: 120000, fa: false, auto: 'server', hPer: '/hour', hLbl: 'Hourly', amtLbl: 'Amount',
  };
  if (options.unpriced) data.prices.plan = {};
  if (options.pricing) {
    data.prices = {
      plan: { monthly: { cycle: 2240000, setup: 0, save: 0 }, semiannual: { cycle: 12720000, setup: 0, save: 5 }, yearly: { cycle: 25440000, setup: 0, save: 5 } },
      empty: { monthly: { cycle: 4000000, setup: 0, save: 0 }, semiannual: { cycle: 21600000, setup: 0, save: 10 }, yearly: { cycle: 38400000, setup: 0, save: 20 } },
    };
    data.images.empty = data.images.plan;
    data.cycles = { monthly: 'Monthly', semiannual: 'Six months', yearly: 'Yearly' };
    data.months = { monthly: 1, semiannual: 6, yearly: 12 };
    data.addon = { plan: true, empty: true };
    data.extraIp = 400000;
    data.addonTotals = { monthly: [0, 400000, 800000, 1200000, 1600000, 2000000],
      semiannual: [0, 2180000, 4360000, 6540000, 8710000, 10890000],
      yearly: [0, 4360000, 8710000, 13070000, 17420000, 21770000] };
    data.rate = 1;
    data.saveSuffix = '% saving';
    if (options.missingAddonQuote) delete data.addonTotals.semiannual[2];
  }
  if (options.locationLinks) {
    data.hourly = { plan: { rate: 100, min: 2400 }, empty: { rate: 200, min: 4800 } };
  }
  const runtimeErrors = [];
  const virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', error => runtimeErrors.push(error));
  const dom = new JSDOM(`<!doctype html><html lang="en"><body>
    <form id="cvb-form" class="cvx-store">
      <input type="hidden" name="location" value="${options.noLocation ? '' : 'de-frankfurt'}">
      ${options.locationLinks ? `<label class="cvb-seg cvb-seg-h"><input id="cvb-hourly" type="checkbox" name="billing_mode" value="hourly"></label>
        <a id="same-city" class="cvb-city" data-city="de-frankfurt" href="?location=de-frankfurt&plan=old&cycle=monthly&image=old&billing_mode=hourly">Current city</a>
        <a id="next-city" class="cvb-city" data-city="fi-helsinki" href="?location=fi-helsinki&plan=old&cycle=monthly&image=old&billing_mode=hourly">New city</a>
        <a id="closed-city" class="cvb-city" data-city="de-closed" aria-disabled="true" tabindex="-1">Unavailable city</a>` : ''}
      <input id="cvb-quoted-hourly" type="hidden" name="quoted_hourly_irt" value="0">
      ${Object.keys(data.cycles).map(cycle => `<label class="cvb-seg cvb-seg-c"><input type="radio" name="cycle" value="${cycle}" ${cycle === 'monthly' ? 'checked' : ''}><em data-cycle-saving="${cycle}" hidden></em></label>`).join('')}
      ${options.pricing ? '<div id="cvb-ip-box"><select id="cvb-extra-ip" name="extra_ipv4"><option value="2" selected>Two additional IPs</option></select></div>' : ''}
      <div class="cvx-workspace-header"><nav class="cvb-spine">${steps.map(step => `<button type="button" class="cvb-sp" data-go="${step}" data-spine="${step}"><span data-sp-v="${step}"></span>${step}</button>`).join('')}</nav></div>
      <main class="cvb-main">${steps.map(step => `<section class="cvb-step" id="cvb-step-${step}" data-step="${step}"><button type="button" class="cvb-step-h">Stage ${step}</button><div class="cvb-step-i"><h3 class="cvb-q">Stage ${step} heading</h3></div></section>`).join('')}
        <div id="cvb-plans"><label class="cvb-plan" data-slug="plan"><input type="radio" name="plan" value="plan" checked><span data-pp></span></label><label class="cvb-plan" data-slug="empty"><input type="radio" name="plan" value="empty"><span data-pp></span></label></div>
        <div data-pane="os"><div class="cvb-fam" data-fam="ubuntu"><label class="cvb-img" data-key="u24"><input type="radio" name="image" value="u24" checked><b>Ubuntu 24.04</b></label><label class="cvb-img" data-key="u22"><input type="radio" name="image" value="u22"><b>Ubuntu 22.04</b></label></div></div>
        <div data-pane="app" hidden><div class="cvb-fam" data-fam="docker"><label class="cvb-img" data-key="d27"><input type="radio" name="image" value="d27"><b>Docker 27</b></label></div></div>
        <select id="cvb-ssh-pick" name="ssh_key_id"><option value="__new" selected>New SSH key</option><option value="">Password</option></select>
        <div id="cvb-ssh-new" hidden><textarea name="ssh_key_new">synthetic-public-key</textarea></div>
      </main>
      <aside class="cvb-slip"><div id="cvb-s-first"></div><div id="cvx-name-home"><div id="cvx-name-fields"><label for="cvb-label">Server name</label><input id="cvb-label" name="label" value="my-server"></div></div><button id="cvb-submit" type="submit"><span data-cvx-action-label>${words.pay}</span></button></aside>
      <div class="cvb-dock"><div id="cvb-d-first"></div><div id="cvx-mobile-name"></div><button id="cvb-submit-2" type="submit"><span data-cvx-action-label>${words.pay}</span></button></div>
    </form></body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole, url: 'https://console.servernet.cloud/account/cloud-store' });
  const { window } = dom;
  const { document } = window;
  const frames = new Map();
  let frameId = 0;
  window.requestAnimationFrame = callback => { frames.set(++frameId, callback); return frameId; };
  window.cancelAnimationFrame = id => frames.delete(id);
  window.HTMLElement.prototype.scrollIntoView = function () {};
  const mobileListeners = [];
  const mobileQuery = { matches: !!options.mobile, addEventListener: (_, listener) => mobileListeners.push(listener) };
  window.matchMedia = query => query.includes('max-width: 1000px') ? mobileQuery : { matches: false, addEventListener() {} };
  window.fetch = async () => ({ ok: true, json: async () => ({ label: 'Synthetic capacity' }) });
  window.eval(cardsScript);
  window.eval(mainScript.replace('var D = @json($jsData);', `var D = ${JSON.stringify(data)};`));
  window.eval(sshScript.replace("@json(__('ui.svc_name_vps', ['label' => '']))", '"Virtual server"'));
  t.after(async () => {
    await new Promise(resolve => setTimeout(resolve, 0));
    window.close();
    assert.deepEqual(runtimeErrors, [], 'The actual wizard and image-card scripts must not raise browser errors');
  });
  const form = document.getElementById('cvb-form');
  return {
    window, document, form, words,
    primary: document.getElementById('cvb-submit'), dock: document.getElementById('cvb-submit-2'),
    label: document.getElementById('cvb-label'), nameFields: document.getElementById('cvx-name-fields'),
    flushFocus() { for (const [id, callback] of [...frames]) { frames.delete(id); callback(16); } },
    go(step) { document.querySelector(`[data-go="${step}"]`).click(); },
    mobile(value) { mobileQuery.matches = value; mobileListeners.forEach(listener => listener({ matches: value })); },
    submit() { const event = new window.Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(event); return event; },
  };
}

test('one primary action advances location to resources and software before exposing payment', t => {
  const page = fixture(t);
  for (const button of [page.primary, page.dock]) {
    assert.equal(button.type, 'button');
    assert.equal(button.disabled, false, 'The hidden selected location must unlock Continue');
    assert.equal(button.textContent, page.words.resources);
  }
  assert.equal(page.nameFields.hidden, true);
  const main = page.document.querySelector('.cvb-main');
  main.scrollTop = 360;
  page.primary.click(); page.flushFocus();
  assert.equal(page.form.dataset.activeStep, '3');
  assert.equal(main.scrollTop, 0, 'Changing stage must reset the nested desktop workspace scroll');
  assert.equal(page.document.activeElement, page.document.querySelector('#cvb-step-3 .cvb-q'));
  assert.equal(page.primary.textContent, page.words.image);
  assert.equal(page.dock.type, 'button');
  main.scrollTop = 520;
  page.dock.click(); page.flushFocus();
  assert.equal(page.form.dataset.activeStep, '4');
  assert.equal(main.scrollTop, 0);
  assert.equal(page.document.activeElement, page.document.querySelector('#cvb-step-4 .cvb-q'));
  assert.equal(page.nameFields.hidden, false);
  for (const button of [page.primary, page.dock]) {
    assert.equal(button.type, 'submit');
    assert.equal(button.textContent, page.words.pay);
  }
  page.go(3);
  assert.equal(page.primary.type, 'button');
  assert.equal(page.primary.textContent, page.words.image);
  assert.equal(page.nameFields.hidden, true);
});

test('early form submission advances without reaching checkout handlers or changing a pending new SSH key', t => {
  const page = fixture(t);
  let finalSubmissions = 0;
  page.form.addEventListener('submit', () => { finalSubmissions++; });
  assert.equal(page.submit().defaultPrevented, true);
  assert.equal(page.form.dataset.activeStep, '3');
  assert.equal(page.submit().defaultPrevented, true);
  assert.equal(page.form.dataset.activeStep, '4');
  assert.equal(finalSubmissions, 0);
  assert.equal(page.document.getElementById('cvb-ssh-pick').value, '__new');
  assert.equal(page.submit().defaultPrevented, false);
  assert.equal(finalSubmissions, 1);
  assert.equal(page.document.getElementById('cvb-ssh-pick').value, '');
});

test('server name moves between desktop and mobile checkout as the same field and preserves one payload', t => {
  const page = fixture(t, { openStep: 4 });
  const input = page.label;
  assert.equal(page.nameFields.parentNode.id, 'cvx-name-home');
  input.value = 'production-eu';
  page.mobile(true);
  assert.equal(page.nameFields.parentNode.id, 'cvx-mobile-name');
  assert.equal(page.document.getElementById('cvb-label'), input);
  assert.deepEqual(new page.window.FormData(page.form).getAll('label'), ['production-eu']);
  page.mobile(false);
  assert.equal(page.nameFields.parentNode.id, 'cvx-name-home');
  assert.equal(page.document.getElementById('cvb-label'), input);
  assert.deepEqual(new page.window.FormData(page.form).getAll('label'), ['production-eu']);
});

test('published image cards and version selection stay in the final stage and submit the original image key', t => {
  const page = fixture(t, { openStep: 4 });
  const version = page.document.querySelector('[data-pane="os"] .cvb-image-version');
  version.value = 'u22';
  version.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  assert.equal(page.form.dataset.activeStep, '4');
  assert.equal(page.primary.type, 'submit');
  assert.equal(page.primary.textContent, page.words.pay);
  assert.deepEqual(new page.window.FormData(page.form).getAll('image'), ['u22']);
  assert.equal(page.document.querySelectorAll('.cvb-image-card.is-selected').length, 1);
});

test('GPU keeps a two-stage application flow and its capacity request as the final action', t => {
  const page = fixture(t, { gpu: true });
  assert.equal(page.form.dataset.activeStep, '3');
  assert.equal(page.document.querySelectorAll('.cvb-step').length, 2);
  assert.equal(page.primary.textContent, page.words.image);
  assert.equal(page.primary.type, 'button');
  page.primary.click();
  assert.equal(page.form.dataset.activeStep, '4');
  assert.equal(page.dock.textContent, 'Request GPU capacity');
  assert.equal(page.dock.type, 'submit');
});

test('missing location or price keeps both primary actions disabled', t => {
  const noLocation = fixture(t, { noLocation: true });
  assert.equal(noLocation.primary.disabled, true);
  assert.equal(noLocation.dock.disabled, true);
  const unpriced = fixture(t, { unpriced: true, openStep: 4 });
  assert.equal(unpriced.primary.disabled, true);
  assert.equal(unpriced.dock.disabled, true);
});

test('a priced plan with no compatible image cannot expose an enabled final checkout', t => {
  const page = fixture(t, { openStep: 4 });
  const unavailable = page.document.querySelector('input[name="plan"][value="empty"]');
  unavailable.checked = true;
  unavailable.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  assert.equal(page.primary.disabled, true);
  assert.equal(page.dock.disabled, true);
});

test('real cycle savings refresh per selected plan and exact server-owned IP totals reach both checkout prices', t => {
  const page = fixture(t, { openStep: 4, pricing: true });
  const badge = cycle => page.document.querySelector(`[data-cycle-saving="${cycle}"]`);
  const choose = (name, value) => {
    const input = page.document.querySelector(`input[name="${name}"][value="${value}"]`);
    input.checked = true;
    input.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  };
  assert.equal(badge('monthly').hidden, true);
  assert.equal(badge('semiannual').textContent, '5% saving');
  assert.equal(badge('yearly').textContent, '5% saving');
  choose('cycle', 'semiannual');
  // Server 12.72m + two IPs at their independent cost cap (4.36m) + 10% tax.
  assert.equal(page.document.getElementById('cvb-s-first').textContent, '€18788000.00');
  assert.equal(page.document.getElementById('cvb-d-first').textContent, '€18788000.00');
  choose('plan', 'empty');
  assert.equal(badge('semiannual').textContent, '10% saving');
  assert.equal(badge('yearly').textContent, '20% saving');
  assert.equal(page.document.getElementById('cvb-s-first').textContent, '€28556000.00');
  choose('cycle', 'yearly');
  assert.equal(page.document.getElementById('cvb-s-first').textContent, '€51821000.00');
  choose('plan', 'plan');
  assert.equal(badge('yearly').textContent, '5% saving');
  assert.equal(page.document.getElementById('cvb-s-first').textContent, '€37565000.00');
});

test('a nonzero IP selection with no public quote cannot become a free add-on or an enabled checkout', t => {
  const page = fixture(t, { openStep: 4, pricing: true, missingAddonQuote: true });
  const cycle = page.document.querySelector('input[name="cycle"][value="semiannual"]');
  cycle.checked = true;
  cycle.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  assert.equal(page.document.getElementById('cvb-s-first').textContent, '—');
  assert.equal(page.document.getElementById('cvb-d-first').textContent, '—');
  assert.equal(page.primary.disabled, true);
  assert.equal(page.dock.disabled, true);
});

test('a new location never carries the source plan, image or hourly contract after live form changes', t => {
  const page = fixture(t, { pricing: true, locationLinks: true });
  for (const [name, value] of [['plan', 'empty'], ['cycle', 'yearly'], ['image', 'u22']]) {
    const input = page.document.querySelector(`input[name="${name}"][value="${value}"]`);
    input.checked = true;
    input.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  }
  page.document.getElementById('cvb-hourly').checked = true;
  const link = page.document.getElementById('next-city');
  link.addEventListener('click', event => event.preventDefault());
  link.dispatchEvent(new page.window.MouseEvent('click', { bubbles: true, cancelable: true }));
  assert.deepEqual([...new URL(link.href).searchParams], [['location', 'fi-helsinki'], ['cycle', 'yearly']]);
  assert.equal(page.form.elements.location.value, 'de-frankfurt', 'Browsing must not mutate the current order payload');
});

test('same-location navigation keeps the latest valid choices while closed rows never gain a purchase URL', t => {
  const page = fixture(t, { pricing: true, locationLinks: true });
  const plan = page.document.querySelector('input[name="plan"][value="empty"]');
  plan.checked = true;
  plan.dispatchEvent(new page.window.Event('change', { bubbles: true }));
  page.document.getElementById('cvb-hourly').checked = true;
  const same = page.document.getElementById('same-city');
  same.addEventListener('click', event => event.preventDefault());
  same.dispatchEvent(new page.window.MouseEvent('click', { bubbles: true, cancelable: true }));
  const query = new URL(same.href).searchParams;
  assert.equal(query.get('plan'), 'empty');
  assert.equal(query.get('image'), 'u24');
  assert.equal(query.get('billing_mode'), 'hourly');
  const closed = page.document.getElementById('closed-city');
  closed.dispatchEvent(new page.window.MouseEvent('click', { bubbles: true, cancelable: true }));
  assert.equal(closed.hasAttribute('href'), false);
});
