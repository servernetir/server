const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/cloud-store-experience.js'), 'utf8');

function fixture(t, { withUnavailable = false } = {}) {
  const runtimeErrors = [];
  const virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', error => runtimeErrors.push(error));
  const dom = new JSDOM(`<!doctype html><html lang="fa" dir="rtl"><body>
    <button id="outside">Outside the form</button>
    <form class="cvx-store">
      <input type="hidden" name="location" value="ir-shiraz">
      <button type="button" data-spine="1">Location step</button>
      <h3 id="location-heading" tabindex="-1">Choose a destination</h3>
      <input type="search" id="cvx-search" aria-controls="cvx-results">
      <button type="button" data-cvx-region="all" aria-pressed="true">Worldwide</button>
      <button type="button" data-cvx-region="eu" aria-pressed="false">Europe</button>
      <button type="button" data-cvx-region="me" aria-pressed="false">Middle East</button>
      <span id="cvx-count"></span>
      <div id="cvx-results" tabindex="0">
        <details class="cvx-country" data-region="eu" data-country="DE">
          <summary data-label="آلمان">آلمان</summary>
          ${withUnavailable ? '<a data-city="de-unavailable" data-city-label="Berlin" data-label="آلمان — برلین" data-approximate="false" role="link" aria-disabled="true" tabindex="-1">برلین — موجودی این پلن تمام شده</a>' : ''}
          <a href="?location=de-fsn" data-city="de-fsn" data-city-label="Falkenstein" data-label="آلمان — فالکن‌اشتاین" data-approximate="false">فالکن‌اشتاین</a>
          <a href="?location=de-ref" data-city="de-ref" data-city-label="" data-label="آلمان — سایر مکان‌ها" data-approximate="true">سایر مکان‌ها</a>
        </details>
        <details class="cvx-country" data-region="eu" data-country="CH">
          <summary data-label="سوئیس">سوئیس</summary>
          <a href="?location=ch-z-rich" data-city="ch-z-rich" data-city-label="Zürich" data-label="سوئیس — زوریخ" data-approximate="false">زوریخ</a>
        </details>
        <details class="cvx-country" data-region="me" data-country="IR" open>
          <summary data-label="ایران">ایران</summary>
          <a href="?location=ir-shiraz" data-city="ir-shiraz" data-city-label="Shiraz" data-label="ایران — شیراز" data-approximate="false" aria-current="true">شیراز</a>
          <a href="?location=ir-urm" data-city="ir-urm" data-city-label="Urmia" data-label="ایران — ارومیه" data-approximate="false">ارومیه</a>
        </details>
        <details class="cvx-country" data-region="me" data-country="TR">
          <summary data-label="ترکیه">ترکیه</summary>
          <a href="?location=tr-ist" data-city="tr-ist" data-city-label="Istanbul" data-label="ترکیه — استانبول" data-approximate="false">استانبول</a>
        </details>
        ${withUnavailable ? '<details class="cvx-country" data-region="eu" data-country="AT"><summary data-label="اتریش">اتریش</summary><a data-city="at-innsbruck" data-city-label="Innsbruck" data-label="اتریش — اینسبروک" data-approximate="false" role="link" aria-disabled="true" tabindex="-1">اینسبروک — موجودی این پلن تمام شده</a></details>' : ''}
        <div id="cvx-empty" hidden>No destinations found</div>
      </div>
    </form>
  </body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole, url: 'https://console.servernet.cloud/account/cloud-store' });
  t.after(async () => {
    // Native details disclosures queue toggle events. Let those run before
    // closing the document so teardown cannot create a false script error.
    await new Promise(resolve => setTimeout(resolve, 0));
    dom.window.close();
    assert.deepEqual(runtimeErrors, [], 'The real enhancement script must not raise browser errors');
  });
  const { window } = dom;
  const { document } = window;
  const frames = [];
  window.requestAnimationFrame = callback => { frames.push(callback); return frames.length; };
  const previews = [];
  document.addEventListener('cvx:location', event => previews.push(JSON.parse(JSON.stringify(event.detail))));
  window.eval(source);
  const input = document.getElementById('cvx-search');
  return {
    window, document, frames, previews, input,
    search(query) { input.value = query; input.dispatchEvent(new window.Event('input', { bubbles: true })); },
    region(code) { document.querySelector(`[data-cvx-region="${code}"]`).click(); },
    country(code) { return document.querySelector(`[data-country="${code}"]`); },
    city(code) { return document.querySelector(`[data-city="${code}"]`); },
    visibleCountries() { return [...document.querySelectorAll('.cvx-country')].filter(element => !element.hidden).map(element => element.dataset.country); }
  };
}

test('a localized country search keeps every datacentre in that country reachable', t => {
  const page = fixture(t);
  page.search('آلمان');
  assert.deepEqual(page.visibleCountries(), ['DE']);
  assert.equal(page.country('DE').open, true);
  assert.equal(page.city('de-fsn').hidden, false);
  assert.equal(page.city('de-ref').hidden, false);
  assert.equal(page.document.getElementById('cvx-empty').hidden, true);
  assert.deepEqual(page.previews.at(-1), { country: 'DE', code: '', city: '', label: 'آلمان', approximate: true });
});

test('canonical English city search works when the localized label and abbreviated code cannot match it', t => {
  const page = fixture(t);
  page.search('Zurich');
  assert.deepEqual(page.visibleCountries(), ['CH']);
  assert.equal(page.city('ch-z-rich').hidden, false);
  assert.equal(page.previews.at(-1).city, 'Zürich');
  page.search('Istanbul');
  assert.deepEqual(page.visibleCountries(), ['TR']);
  assert.equal(page.city('tr-ist').hidden, false);
  assert.equal(page.previews.at(-1).code, 'tr-ist');
});

test('Arabic letter variants match the Persian country and city labels', t => {
  const page = fixture(t);
  page.search('ايران');
  assert.deepEqual(page.visibleCountries(), ['IR']);
  assert.equal(page.city('ir-urm').hidden, false);
  page.search('شيراز');
  assert.deepEqual(page.visibleCountries(), ['IR']);
  assert.equal(page.city('ir-shiraz').hidden, false);
  assert.equal(page.city('ir-urm').hidden, true);
  page.search('تركیه');
  assert.deepEqual(page.visibleCountries(), ['TR']);
});

test('region filtering combines with search, exposes an empty state, and restores the selected disclosure on clear', t => {
  const page = fixture(t);
  page.region('me');
  assert.deepEqual(page.visibleCountries(), ['IR', 'TR']);
  assert.equal(page.document.querySelector('[data-cvx-region="me"]').getAttribute('aria-pressed'), 'true');
  page.search('Zurich');
  assert.deepEqual(page.visibleCountries(), []);
  assert.equal(page.document.getElementById('cvx-empty').hidden, false);
  page.region('all');
  assert.deepEqual(page.visibleCountries(), ['CH']);
  assert.equal(page.document.getElementById('cvx-empty').hidden, true);
  page.search('');
  assert.deepEqual(page.visibleCountries(), ['DE', 'CH', 'IR', 'TR']);
  assert.equal(page.country('IR').open, true);
  assert.equal(page.country('CH').open, false);
});

test('city and generic previews emit geography events without changing the server-owned selected location', t => {
  const page = fixture(t);
  const location = page.document.querySelector('input[name="location"]');
  page.city('ch-z-rich').dispatchEvent(new page.window.Event('pointerenter'));
  assert.deepEqual(page.previews.at(-1), { country: 'CH', code: 'ch-z-rich', city: 'Zürich', label: 'سوئیس — زوریخ', approximate: false });
  assert.equal(location.value, 'ir-shiraz');
  page.city('de-ref').dispatchEvent(new page.window.Event('focus'));
  assert.deepEqual(page.previews.at(-1), { country: 'DE', code: 'de-ref', city: '', label: 'آلمان — سایر مکان‌ها', approximate: true });
  assert.equal(location.value, 'ir-shiraz');
  page.document.dispatchEvent(new page.window.CustomEvent('cvx:globe-ready'));
  assert.equal(page.previews.at(-1).code, 'ir-shiraz');
  assert.equal(location.value, 'ir-shiraz');
  assert.equal(page.document.querySelectorAll('input[name="location"]').length, 1);
});

test('choosing a catalogue country on the globe reveals its cities and resets search filters without changing the order', t => {
  const page = fixture(t);
  page.region('me');
  page.search('شيراز');
  const results = page.document.getElementById('cvx-results');
  results.scrollTop = 180;
  const originalUrl = page.window.location.href;
  const location = page.document.querySelector('input[name="location"]');
  let followedLinks = 0;
  page.document.querySelectorAll('[data-city]').forEach(link => link.addEventListener('click', () => followedLinks++));

  page.document.dispatchEvent(new page.window.CustomEvent('cvx:country-select', { detail: { country: 'de' } }));

  assert.equal(page.input.value, 'آلمان');
  assert.deepEqual(page.visibleCountries(), ['DE']);
  assert.equal(page.country('DE').open, true);
  assert.equal(page.city('de-fsn').hidden, false);
  assert.equal(page.city('de-ref').hidden, false);
  assert.equal(page.document.getElementById('cvx-empty').hidden, true);
  assert.equal(results.scrollTop, 0);
  for (const control of page.document.querySelectorAll('[data-cvx-region]')) {
    assert.equal(control.getAttribute('aria-pressed'), String(control.dataset.cvxRegion === 'all'));
  }
  assert.deepEqual(page.previews.at(-1), { country: 'DE', code: '', city: '', label: 'آلمان', approximate: true });
  assert.equal(location.value, 'ir-shiraz');
  assert.equal(page.document.querySelector('[data-city][aria-current="true"]'), page.city('ir-shiraz'));
  assert.equal(page.document.querySelectorAll('input[name="location"]').length, 1);
  assert.equal(followedLinks, 0);
  assert.equal(page.window.location.href, originalUrl);
});

test('globe country browsing ignores countries outside the actual catalogue and missing country details', t => {
  const page = fixture(t);
  page.region('me');
  page.search('شيراز');
  const results = page.document.getElementById('cvx-results');
  results.scrollTop = 90;
  const before = {
    query: page.input.value,
    countries: page.visibleCountries(),
    controls: [...page.document.querySelectorAll('[data-cvx-region]')].map(control => control.getAttribute('aria-pressed')),
    disclosures: [...page.document.querySelectorAll('.cvx-country')].map(country => country.open),
    previews: page.previews.length,
    location: page.document.querySelector('input[name="location"]').value,
    url: page.window.location.href,
  };
  // US is a real country but has no server location in this catalogue.
  page.document.dispatchEvent(new page.window.CustomEvent('cvx:country-select', { detail: { country: 'US' } }));
  page.document.dispatchEvent(new page.window.CustomEvent('cvx:country-select'));
  assert.equal(page.input.value, before.query);
  assert.deepEqual(page.visibleCountries(), before.countries);
  assert.deepEqual([...page.document.querySelectorAll('[data-cvx-region]')].map(control => control.getAttribute('aria-pressed')), before.controls);
  assert.deepEqual([...page.document.querySelectorAll('.cvx-country')].map(country => country.open), before.disclosures);
  assert.equal(page.previews.length, before.previews);
  assert.equal(results.scrollTop, 90);
  assert.equal(page.document.querySelector('input[name="location"]').value, before.location);
  assert.equal(page.window.location.href, before.url);
});

test('Enter in destination search reviews a matching city without advancing or changing the order', t => {
  const page = fixture(t);
  let submitted = 0;
  page.document.querySelector('form').addEventListener('submit', event => { submitted++; event.preventDefault(); });
  page.search('Zurich');
  page.input.focus();
  const enter = new page.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
  page.input.dispatchEvent(enter);
  assert.equal(enter.defaultPrevented, true);
  assert.equal(page.document.activeElement, page.city('ch-z-rich'));
  assert.equal(page.document.querySelector('input[name="location"]').value, 'ir-shiraz');
  assert.equal(page.window.location.search, '');
  assert.equal(submitted, 0);
  page.search('NoSuchCity');
  page.input.focus();
  const emptyEnter = new page.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
  page.input.dispatchEvent(emptyEnter);
  assert.equal(emptyEnter.defaultPrevented, true);
  assert.equal(page.document.activeElement, page.input);
});

test('destination keyboard navigation skips visible unavailable cities and reviews an available city', t => {
  const page = fixture(t, { withUnavailable: true });
  page.search('آلمان');
  const unavailable = page.city('de-unavailable');
  assert.equal(unavailable.hidden, false, 'Unavailable capacity remains visible for context');
  assert.equal(unavailable.hasAttribute('href'), false);
  assert.equal(unavailable.getAttribute('aria-disabled'), 'true');
  assert.equal(unavailable.tabIndex, -1);
  const press = (element, key) => element.dispatchEvent(new page.window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
  page.input.focus();
  press(page.input, 'Enter');
  assert.equal(page.document.activeElement, page.city('de-fsn'));
  press(page.document.activeElement, 'Home');
  assert.equal(page.document.activeElement, page.country('DE').querySelector('summary'));
  press(page.document.activeElement, 'ArrowDown');
  assert.equal(page.document.activeElement, page.city('de-fsn'));
  press(page.document.activeElement, 'ArrowUp');
  assert.equal(page.document.activeElement, page.country('DE').querySelector('summary'));
  press(page.document.activeElement, 'End');
  assert.equal(page.document.activeElement, page.city('de-ref'));
  assert.equal(page.document.querySelector('input[name="location"]').value, 'ir-shiraz');
  assert.equal(page.window.location.search, '');
});

test('an unavailable-only country remains searchable and previews on the globe without offering a purchase link', t => {
  const page = fixture(t, { withUnavailable: true });
  const country = page.country('AT');
  const unavailable = page.city('at-innsbruck');
  for (const query of ['Austria', 'Innsbruck']) {
    page.search(query);
    assert.deepEqual(page.visibleCountries(), ['AT']);
    assert.equal(country.open, true);
    assert.equal(unavailable.hidden, false);
    page.input.focus();
    page.input.dispatchEvent(new page.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    assert.equal(page.document.activeElement, country.querySelector('summary'));
  }
  unavailable.dispatchEvent(new page.window.Event('pointerenter'));
  assert.equal(page.previews.at(-1).country, 'AT');
  assert.equal(page.previews.at(-1).city, 'Innsbruck');
  page.document.dispatchEvent(new page.window.CustomEvent('cvx:country-select', { detail: { country: 'AT' } }));
  assert.equal(page.input.value, 'اتریش');
  assert.equal(country.hidden, false);
  assert.equal(unavailable.hasAttribute('href'), false);
  assert.equal(page.document.querySelector('input[name="location"]').value, 'ir-shiraz');
  assert.equal(page.window.location.search, '');
});

test('Ctrl K leaves focus in search after the navigation helper schedules its own heading focus', t => {
  const page = fixture(t);
  const heading = page.document.getElementById('location-heading');
  page.document.querySelector('[data-spine="1"]').addEventListener('click', () => {
    page.window.requestAnimationFrame(() => heading.focus({ preventScroll: true }));
  });
  page.document.getElementById('outside').focus();
  const shortcut = new page.window.KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true, cancelable: true });
  page.document.activeElement.dispatchEvent(shortcut);
  assert.equal(shortcut.defaultPrevented, true);
  assert.equal(page.frames.length, 2);
  page.frames.shift()(16);
  assert.equal(page.document.activeElement, heading);
  page.frames.shift()(16);
  assert.equal(page.document.activeElement, page.input);
  assert.equal(page.frames.length, 0);
  page.input.dispatchEvent(new page.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
  assert.equal(page.document.activeElement, page.country('DE').querySelector('summary'));
});
