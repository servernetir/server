'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {buildRepair, writeRepair} = require('./prepare-purchase-gtm-repair.cjs');

// Deliberately synthetic metadata, not an export or IDs from an actual account.
const target = {containerPublicId: 'GTM-SYNTHETIC', measurementId: 'G-SYNTHETIC'};
const identity = {accountId: '100000000001', containerId: '200000000002'};
const parameter = (key, value, type = 'TEMPLATE') => ({type, key, value});
function fixture() {
  return {exportFormatVersion: 2, containerVersion: {
    ...identity, containerVersionId: '999',
    container: {...identity, name: 'SYNTHETIC TEST ONLY', publicId: target.containerPublicId, usageContext: ['WEB']},
    tag: [
      {...identity, tagId: '1', name: 'TEST Google tag', type: 'googtag', parameter: [parameter('tagId', target.measurementId)]},
      {...identity, tagId: '2', name: 'TEST Purchase', type: 'gaawe',
        parameter: [parameter('measurementIdOverride', target.measurementId), parameter('eventName', 'purchase'),
          parameter('sendEcommerceData', 'true', 'BOOLEAN')], firingTriggerId: ['10'], tagFiringOption: 'ONCE_PER_EVENT',
        consentSettings: {consentStatus: 'NEEDED', consentType: {type: 'LIST', list: [parameter('', 'analytics_storage')]}},
        blockingTriggerId: ['12'], setupTag: [{tagName: 'TEST Setup', stopOnSetupFailure: true}],
        teardownTag: [{tagName: 'TEST Teardown', stopTeardownOnFailure: false}], notes: 'Keep existing metadata'},
      {...identity, tagId: '3', name: 'TEST Ecommerce events', type: 'gaawe',
        parameter: [parameter('measurementIdOverride', target.measurementId), parameter('eventName', '{{Event}}'),
          parameter('sendEcommerceData', 'true', 'BOOLEAN'), parameter('getEcommerceDataFrom', 'dataLayer')], firingTriggerId: ['11']}
    ],
    trigger: [
      {...identity, triggerId: '10', name: 'TEST purchase only', type: 'CUSTOM_EVENT', customEventFilter: [
        {type: 'EQUALS', parameter: [parameter('arg0', '{{_event}}'), parameter('arg1', 'purchase')]}]},
      {...identity, triggerId: '11', name: 'TEST non-purchase events', type: 'CUSTOM_EVENT', customEventFilter: [
        {type: 'MATCH_REGEX', parameter: [parameter('arg0', '{{_event}}'), parameter('arg1', '^(view_item|begin_checkout|wallet_topup_click)$')]}]},
      {...identity, triggerId: '12', name: 'TEST existing blocker', type: 'PAGEVIEW'}
    ], variable: []
  }};
}
function repair(source = fixture()) { return buildRepair(source, target, '2026-10-31T00:00:00Z'); }
function rejected(change, pattern) { const s = fixture(); change(s.containerVersion); assert.throws(() => repair(s), pattern); }

test('repairs only the existing tag source, preserving IDs, routing, consent and sequencing', () => {
  const source = fixture(), before = structuredClone(source), result = repair(source);
  assert.deepEqual(source, before);
  assert.equal(result.exportFormatVersion, 2);
  assert.equal(result.containerVersion.tag.length, 1);
  assert.deepEqual(result.containerVersion.trigger, []);
  assert.deepEqual(result.containerVersion.variable, []);
  const expected = structuredClone(source.containerVersion.tag[1]);
  expected.parameter.push(parameter('getEcommerceDataFrom', 'dataLayer'));
  assert.deepEqual(result.containerVersion.tag[0], expected);
  assert.equal(result.containerVersion.accountId, identity.accountId);
  assert.equal(result.containerVersion.containerId, identity.containerId);
});
test('rejects wrong container, missing identities and cross-container entities', () => {
  rejected(v => { v.container.publicId = 'GTM-OTHER'; }, /public ID/);
  rejected(v => { delete v.container.accountId; }, /IDs must come/);
  rejected(v => { v.tag[1].containerId = '777'; }, /another account\/container/);
});
test('requires the active Google tag and same-property ecommerce reference', () => {
  rejected(v => { v.tag[0].paused = true; }, /active Google tag/);
  rejected(v => { v.tag[2].parameter[0].value = 'G-OTHER'; }, /template is required/);
  rejected(v => { v.tag[2].parameter.find(p => p.key === 'getEcommerceDataFrom').type = 'BOOLEAN'; }, /template is required/);
});
test('supports a measurement ID from an exported constant without inventing it', () => {
  const source = fixture(), v = source.containerVersion;
  v.variable.push({...identity, variableId: '30', name: 'TEST Measurement ID', type: 'c', parameter: [parameter('value', target.measurementId)]});
  for (const tag of v.tag) for (const p of tag.parameter) if (['tagId', 'measurementIdOverride'].includes(p.key)) p.value = '{{TEST Measurement ID}}';
  assert.equal(repair(source).containerVersion.tag[0].parameter[0].value, '{{TEST Measurement ID}}');
});
test('rejects duplicate purchase tags even with additional page filters', () => {
  rejected(v => { v.tag.push({...structuredClone(v.tag[1]), tagId: '4', name: 'TEST Duplicate'}); }, /unique active purchase/);
  rejected(v => { v.trigger[1].customEventFilter[0].parameter[1].value = '.*'; v.trigger[1].filter = [{type: 'EQUALS', parameter: [parameter('arg0', '{{Page Path}}'), parameter('arg1', '/checkout')]}]; }, /Another active GA4 route/);
});
test('ignores a paused duplicate but keeps the one active repair target', () => {
  const s = fixture(); s.containerVersion.tag.push({...structuredClone(s.containerVersion.tag[1]), tagId: '4', name: 'TEST Paused Duplicate', paused: true});
  assert.equal(repair(s).containerVersion.tag[0].tagId, '2');
});
test('rejects computed names, computed trigger patterns and unknown trigger types', () => {
  rejected(v => { v.tag[2].parameter.find(p => p.key === 'eventName').value = '{{Computed Event}}'; }, /Computed GA4 event/);
  rejected(v => { v.trigger[1].customEventFilter[0].parameter[1].value = '{{Computed Pattern}}'; }, /Computed event condition/);
  rejected(v => { v.trigger[1].type = 'PAGEVIEW'; }, /Unfamiliar purchase-capable trigger/);
  rejected(v => { v.trigger[1].customEventFilter[0].parameter.push(parameter('ignore_case', '{{Flag}}', 'BOOLEAN')); }, /condition flag/);
  rejected(v => { v.tag[2].paused = 'false'; }, /Malformed paused state/);
  rejected(v => {
    v.variable.push({...identity, variableId: '30', name: 'TEST Indirect', type: 'c', parameter: [parameter('value', '{{Computed}}')]});
    v.tag[2].parameter.find(p => p.key === 'eventName').value = '{{TEST Indirect}}';
  }, /Computed GA4 event/);
});
test('rejects empty, malformed and invalid-regex dynamic routes', () => {
  rejected(v => { v.trigger[1].customEventFilter = []; }, /Another active GA4 route/);
  rejected(v => { v.trigger[1].customEventFilter[0].parameter[1].value = '['; }, /Invalid custom event regex/);
  rejected(v => { v.tag[2].firingTriggerId = ['999']; }, /missing from the export/);
});
test('requires a single exact purchase trigger', () => {
  rejected(v => { v.tag[1].firingTriggerId.push('11'); }, /one exact/);
  rejected(v => { v.trigger[0].customEventFilter[0].type = 'MATCH_REGEX'; }, /match exactly/);
  rejected(v => { v.trigger[0].customEventFilter[0].parameter.push(parameter('negate', 'true', 'BOOLEAN')); }, /match exactly/);
});
test('does not overwrite an already configured source or disabled ecommerce', () => {
  rejected(v => { v.tag[1].parameter.push(parameter('getEcommerceDataFrom', 'dataLayer')); }, /already has an ecommerce source/);
  rejected(v => { v.tag[1].parameter.push(parameter('ecommerceMacroData', '{{Custom Commerce}}')); }, /already has an ecommerce source/);
  rejected(v => { v.tag[1].parameter.find(p => p.key === 'sendEcommerceData').value = 'false'; }, /already enable ecommerce/);
});
test('preserves unrelated explicit parameters but refuses commerce overrides', () => {
  const s = fixture(); s.containerVersion.tag[1].parameter.push({key: 'eventSettingsTable', type: 'LIST', list: [
    {type: 'MAP', map: [parameter('parameter', 'billing_cycle'), parameter('parameterValue', '{{TEST Cycle}}')]}]});
  assert.deepEqual(repair(s).containerVersion.tag[0].parameter[3], s.containerVersion.tag[1].parameter[3]);
  s.containerVersion.tag[1].parameter[3].list[0].map[0].value = 'currency';
  assert.throws(() => repair(s), /override Data Layer/);
  rejected(v => { v.tag[1].parameter.push(parameter('eventSettingsVariable', '{{Settings}}')); }, /settings variable/);
});
test('rejects identity collisions and duplicate parameter keys', () => {
  rejected(v => { v.tag[2].tagId = '2'; }, /Duplicate or missing tagId/);
  rejected(v => { v.tag[2].name = v.tag[1].name; }, /name must be unique/);
  rejected(v => { v.tag[1].parameter.push(parameter('eventName', 'purchase')); }, /Duplicate or malformed parameter/);
});
test('rejects a second route that invokes purchase through sequencing', () => {
  rejected(v => { v.tag[2].setupTag = [{tagName: 'TEST Purchase'}]; }, /invoked by tag sequencing/);
  rejected(v => { v.tag[2].teardownTag = [{tagName: '{{Computed Tag}}'}]; }, /Computed tag sequencing/);
});
test('file writer creates a private merge artifact and refuses input/output overwrite', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'snet-gtm-repair-test-'));
  try {
    const input = path.join(dir, 'synthetic-input.json'), output = path.join(dir, 'private-output.json');
    fs.writeFileSync(input, JSON.stringify(fixture()));
    writeRepair(input, output, target);
    const contents = fs.readFileSync(output, 'utf8');
    assert.equal(JSON.parse(contents).containerVersion.tag.length, 1);
    if (process.platform !== 'win32') assert.equal(fs.statSync(output).mode & 0o777, 0o600);
    assert.throws(() => writeRepair(input, output, target), /EEXIST/);
    assert.equal(fs.readFileSync(output, 'utf8'), contents);
    assert.throws(() => writeRepair(input, input, target), /different files/);
    assert.deepEqual(JSON.parse(fs.readFileSync(input, 'utf8')), fixture());
  } finally { fs.rmSync(dir, {recursive: true, force: true}); }
});
test('CLI refuses untrusted metadata and changing the input', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'snet-gtm-repair-test-'));
  try {
    const input = path.join(dir, 'synthetic-input.json'), output = path.join(dir, 'existing-output.json');
    fs.writeFileSync(input, JSON.stringify(fixture())); fs.writeFileSync(output, 'preserve');
    // CLI has the real public target fixed; this synthetic file must fail closed.
    const child = spawnSync(process.execPath, [path.join(__dirname, 'prepare-purchase-gtm-repair.cjs'), input, output], {encoding: 'utf8'});
    assert.equal(child.status, 1);
    assert.match(child.stderr, /public ID does not match/);
    assert.equal(fs.readFileSync(output, 'utf8'), 'preserve');
    const same = spawnSync(process.execPath, [path.join(__dirname, 'prepare-purchase-gtm-repair.cjs'), input, input], {encoding: 'utf8'});
    assert.equal(same.status, 1); assert.match(same.stderr, /different files/);
    assert.deepEqual(JSON.parse(fs.readFileSync(input, 'utf8')), fixture());
  } finally { fs.rmSync(dir, {recursive: true, force: true}); }
});
