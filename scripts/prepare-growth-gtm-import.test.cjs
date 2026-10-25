'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {buildImport} = require('./prepare-growth-gtm-import.cjs');
const spec = require('../seo/analytics/growth-events.json');

// Synthetic format-2 fixture. These numeric IDs are not a customer account/export.
function fixture() {
  return {exportFormatVersion: 2, containerVersion: {
    accountId: '123', containerId: '456', containerVersionId: '8',
    container: {accountId: '123', containerId: '456', publicId: spec.containerPublicId, name: 'Synthetic test', usageContext: ['WEB']},
    tag: [
      {tagId: '21', name: 'Existing Google tag', type: 'googtag', parameter: [{key: 'tagId', type: 'TEMPLATE', value: spec.measurementId}]},
      {tagId: '11', name: 'Existing configure event', type: 'gaawe', parameter: [
        {key: 'eventName', type: 'TEMPLATE', value: 'configure_product'},
        {key: 'measurementIdOverride', type: 'TEMPLATE', value: spec.measurementId},
        {key: 'eventSettingsTable', type: 'LIST', list: [{type: 'MAP', map: [{key: 'parameter', type: 'TEMPLATE', value: 'cpu_cores'}, {key: 'parameterValue', type: 'TEMPLATE', value: '{{Existing CPU}}'}]}]}
      ], consentSettings: {consentStatus: 'NEEDED', consentType: {type: 'LIST', list: [{type: 'TEMPLATE', value: 'analytics_storage'}]}}}
    ],
    trigger: [{triggerId: '12', name: 'Existing event set', type: 'CUSTOM_EVENT', customEventFilter: [{type: 'MATCH_REGEX', parameter: [
      {key: 'arg0', type: 'TEMPLATE', value: '{{_event}}'}, {key: 'arg1', type: 'TEMPLATE', value: '^(purchase|sign_up|configure_product)$'}
    ]}]}], variable: []
  }};
}
const values = entity => Object.fromEntries(entity.parameter.map(p => [p.key, p]));

test('adds only two exact event routes and six shared variables, preserving source and consent', () => {
  const source = fixture(), before = structuredClone(source), result = buildImport(source);
  assert.deepEqual(source, before);
  const v = result.containerVersion;
  assert.equal(v.tag.length, 2); assert.equal(v.trigger.length, 2); assert.equal(v.variable.length, 6);
  assert.ok(v.tag.every(tag => tag.type === 'gaawe' && tag.tagFiringOption === 'ONCE_PER_EVENT'));
  const ids = [...v.tag.map(x => x.tagId), ...v.trigger.map(x => x.triggerId), ...v.variable.map(x => x.variableId)];
  assert.equal(new Set(ids).size, 10); assert.ok(ids.every(id => Number(id) > 21));
  for (const [index, event] of spec.events.entries()) {
    const tag = v.tag[index], p = values(tag), trigger = v.trigger[index];
    assert.deepEqual(tag.consentSettings, source.containerVersion.tag[1].consentSettings);
    assert.equal(p.eventName.value, event.name);
    assert.equal(p.measurementIdOverride.value, spec.measurementId);
    assert.equal(p.sendEcommerceData.value, 'false');
    assert.deepEqual(tag.firingTriggerId, [trigger.triggerId]);
    assert.equal(trigger.customEventFilter[0].type, 'EQUALS');
    assert.equal(values(trigger.customEventFilter[0]).arg1.value, event.name);
    assert.deepEqual(p.eventSettingsTable.list.map(row => row.map[0].value), event.parameters);
    for (const row of p.eventSettingsTable.list) {
      const reference = row.map[1].value.slice(2, -2);
      const variable = v.variable.find(x => x.name === reference);
      assert.equal(values(variable).name.value, row.map[0].value);
      assert.equal(values(variable).dataLayerVersion.value, '2');
    }
  }
  assert.ok(!JSON.stringify(v.tag).includes('purchase'));
});
test('does not import an incorrect container or invent missing account metadata', () => {
  for (const mutate of [
    s => {s.containerVersion.container.publicId = 'GTM-WRONG';},
    s => {delete s.containerVersion.container.accountId;},
    s => {s.containerVersion.accountId = '999';},
    s => {s.containerVersion.container.usageContext = ['SERVER'];},
    s => {s.containerVersion.tag[0].parameter[0].value = 'G-WRONG';}
  ]) { const source = fixture(); mutate(source); assert.throws(() => buildImport(source)); }
});
test('resolves an existing Google tag measurement ID only through a constant variable', () => {
  const source = fixture();
  source.containerVersion.variable.push({variableId: '24', name: 'GA4 ID', type: 'c', parameter: [{key: 'value', value: spec.measurementId}]});
  source.containerVersion.tag[0].parameter[0].value = '{{GA4 ID}}';
  assert.equal(buildImport(source).containerVersion.variable.length, 6);
  source.containerVersion.variable[0].type = 'jsm';
  assert.throws(() => buildImport(source), /Google tag/);
});
test('refuses already present, overlapping, or dynamic routes instead of doubling events', () => {
  for (const pattern of ['.*', '^product_cta_.*$', '^(purchase|hourly_vps_interaction)$', '{{Unknown pattern}}']) {
    const source = fixture(); source.containerVersion.trigger[0].customEventFilter[0].parameter[1].value = pattern;
    assert.throws(() => buildImport(source));
  }
  const source = fixture(); source.containerVersion.tag[1].parameter[0].value = 'product_cta_click';
  assert.throws(() => buildImport(source), /already mentioned/);
});
test('refuses an existing component name and a changed reference template or sequence', () => {
  for (const mutate of [
    s => {s.containerVersion.variable.push({name: 'SEO Growth - DLV - interaction_type'});},
    s => {s.containerVersion.tag[1].parameter = s.containerVersion.tag[1].parameter.filter(p => p.key !== 'eventSettingsTable');},
    s => {s.containerVersion.tag[1].setupTag = [{tagName: 'Other setup'}];}
  ]) { const source = fixture(); mutate(source); assert.throws(() => buildImport(source)); }
});
