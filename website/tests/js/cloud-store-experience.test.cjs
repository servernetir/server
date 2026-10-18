const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM, VirtualConsole } = require('jsdom');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/cloud-store-experience.js'), 'utf8');

function fixture(t) {
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
