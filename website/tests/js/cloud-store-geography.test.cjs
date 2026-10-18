const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const context = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/cloud-store-geography.js'), 'utf8'), context);
const geo = context.window.SNCloudStoreGeography;
const resolve = (location) => JSON.parse(JSON.stringify(geo.resolve(location)));

test('a trusted named city points to that city within its country', () => {
  const point = resolve({ country: 'de', city: 'Frankfurt', code: 'de-frankfurt', approximate: false });
  assert.equal(point.country, 'DE');
  assert.equal(point.city, 'frankfurt');
  assert.equal(point.approximate, false);
  assert.equal(point.latitude, 50.1109);
  assert.equal(point.longitude, 8.6821);
});

test('an unknown city stays at the country centre and is explicitly approximate', () => {
  const generic = resolve({ country: 'IR', city: '', code: 'ir-ref', approximate: true });
  const unknown = resolve({ country: 'IR', city: 'Unverified datacentre', code: 'ir-tehran' });
  assert.deepEqual(unknown, generic);
  assert.equal(generic.city, '');
  assert.equal(generic.approximate, true);
  assert.equal(generic.latitude, 32.427908);
  assert.equal(generic.longitude, 53.688046);
});

test('the location code never invents a city or a capital', () => {
  for (const code of ['ir-tehran', 'ir-ref', 'de-frankfurt', 'global-gpu']) {
    const point = resolve({ country: 'IR', code });
    assert.equal(point.city, '');
    assert.equal(point.approximate, true);
    assert.equal(point.latitude, 32.427908);
  }
});

test('both boolean and serialized approximate flags override a plausible city name', () => {
  for (const approximate of [true, 'true']) {
    const point = resolve({ country: 'IR', city: 'Tehran', code: 'ir-ref', approximate });
    assert.equal(point.city, '');
    assert.equal(point.approximate, true);
  }
});

test('a city from another country cannot move the pin across borders', () => {
  const point = resolve({ country: 'DE', city: 'Tehran', approximate: false });
  assert.equal(point.country, 'DE');
  assert.equal(point.city, '');
  assert.equal(point.approximate, true);
  assert.equal(point.latitude, 51.165691);
});

test('known spelling and numbered catalogue aliases preserve the same named city', () => {
  for (const city of ['Nürnberg', 'Nuernberg', 'Nuremberg II', 'Nuremberg-2']) {
    assert.equal(resolve({ country: 'DE', city }).city, 'nuremberg');
    assert.equal(resolve({ country: 'DE', city }).approximate, false);
  }
  assert.equal(geo.cityKey('Zürich'), 'zurich');
  assert.equal(geo.cityKey('Z-rich'), 'zurich');
  for (const city of ['Zurich', 'Zürich', 'Z-rich']) {
    assert.equal(resolve({ country: 'CH', city }).city, 'zurich');
    assert.equal(resolve({ country: 'CH', city }).approximate, false);
  }
});

test('unknown and GPU pseudo countries have no fabricated geographic position', () => {
  for (const country of ['', 'XX', 'GLOBAL', 'global-gpu']) {
    assert.equal(geo.resolve({ country, city: 'Frankfurt' }), null);
  }
  assert.equal(geo.resolve(null), null);
});
