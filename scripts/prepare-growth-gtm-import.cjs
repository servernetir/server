#!/usr/bin/env node
'use strict';

// Offline only. Build an additive import from a fresh, authorized GTM export.
// This does not call Google, modify the source export, or publish a container.
const fs = require('node:fs');
const path = require('node:path');
const defaultSpec = require('../seo/analytics/growth-events.json');

function requireValue(condition, message) {
  if (!condition) throw new Error(message);
}
function params(entity) {
  return Object.fromEntries((entity.parameter || []).map(p => [p.key, p]));
}
function normalized(value) { return String(value || '').replace(/_/g, '').toUpperCase(); }
function literal(value, variables) {
  if (typeof value !== 'string') return undefined;
  const reference = /^\{\{([^{}]+)\}\}$/.exec(value);
  if (!reference) return value;
  const found = variables.find(v => v.name === reference[1] && v.type === 'c');
  return found ? params(found).value?.value : undefined;
}
function customTriggerMatches(trigger, event) {
  // Fail closed on an unfamiliar condition, including computed event names.
  // Extra page/hostname filters are deliberately ignored for this overlap guard.
  return (trigger.customEventFilter || []).every(condition => {
    const values = params(condition);
    requireValue(['{{_event}}', '{{Event}}'].includes(values.arg0?.value),
      `Review custom event condition manually: ${trigger.name}`);
    const expected = values.arg1?.value;
    requireValue(typeof expected === 'string' && !expected.includes('{{'),
      `Review computed event condition manually: ${trigger.name}`);
    let match;
    switch (normalized(condition.type)) {
      case 'EQUALS': match = event === expected; break;
      case 'MATCHREGEX':
        match = new RegExp(expected, values.ignore_case?.value === 'true' ? 'i' : '').test(event);
        break;
      default: throw new Error(`Unsupported custom event condition: ${trigger.name}`);
    }
    return values.negate?.value === 'true' ? !match : match;
  });
}

function buildImport(source, spec = defaultSpec, exportTime = new Date().toISOString()) {
  requireValue(Number(source.exportFormatVersion) === 2, 'Expected a GTM JSON export, format 2.');
  const version = source.containerVersion, container = version?.container;
  requireValue(container?.publicId === spec.containerPublicId, 'Container public ID does not match.');
  requireValue((container.usageContext || []).some(v => normalized(v) === 'WEB'), 'Expected a web container.');
  const accountId = container.accountId, containerId = container.containerId;
  requireValue(/^\d+$/.test(accountId || '') && /^\d+$/.test(containerId || ''),
    'Numeric account/container IDs must come from the authorized export.');
  for (const key of ['accountId', 'containerId']) {
    requireValue(!version[key] || version[key] === container[key], `Inconsistent ${key} in export.`);
  }
  const tags = version.tag || [], triggers = version.trigger || [], variables = version.variable || [];
  const all = [...tags, ...triggers, ...variables];
  const existingNames = new Set(all.map(entity => entity.name));
  const names = spec.events.map(event => event.name);
  requireValue(names.length === new Set(names).size, 'Duplicate events in specification.');
  const parameterNames = [...new Set(spec.events.flatMap(event => event.parameters))];
  requireValue([...names, ...parameterNames].every(name => /^[a-z][a-z0-9_]{1,39}$/.test(name)),
    'Invalid event or parameter name in specification.');
  for (const event of names) {
    requireValue(!JSON.stringify(all).includes(event), `Event already mentioned in export; review instead of duplicating: ${event}`);
    for (const trigger of triggers.filter(t => normalized(t.type) === 'CUSTOMEVENT')) {
      requireValue(!customTriggerMatches(trigger, event), `Existing trigger can match ${event}: ${trigger.name}`);
    }
  }
  const googleTag = tags.find(tag => tag.type === 'googtag' && !tag.paused &&
    literal(params(tag).tagId?.value, variables) === spec.measurementId);
  requireValue(googleTag, 'An active existing Google tag for the measurement ID is required.');
  const reference = tags.find(tag => tag.type === 'gaawe' && !tag.paused &&
    literal(params(tag).measurementIdOverride?.value, variables) === spec.measurementId &&
    params(tag).eventSettingsTable?.type === 'LIST' &&
    params(tag).eventSettingsTable.list?.some(row => row.type === 'MAP' &&
      row.map?.some(p => p.key === 'parameter') && row.map.some(p => p.key === 'parameterValue')));
  requireValue(reference, 'Export must contain a same-property GA4 Event tag with eventSettingsTable; review a changed template manually.');
  requireValue(!(reference.blockingTriggerId?.length || reference.setupTag?.length || reference.teardownTag?.length),
    'Reference tag has extra blocking/sequencing rules; review those rules manually.');
  // Preserve the reference tag's additional consent requirements, if configured.
  const consent = reference.consentSettings ? structuredClone(reference.consentSettings) : undefined;
  let nextId = Math.max(0, ...all.flatMap(entity => ['tagId', 'triggerId', 'variableId']
    .filter(key => /^\d+$/.test(entity[key] || '')).map(key => Number(entity[key])))) + 1;
  requireValue(Number.isSafeInteger(nextId) && nextId < 2147480000, 'Unexpected entity ID range.');
  const uniqueName = name => {
    requireValue(!existingNames.has(name), `Name collision; review existing component: ${name}`);
    existingNames.add(name); return name;
  };
  const identity = () => ({accountId, containerId});
  const template = (key, value) => ({type: 'TEMPLATE', key, value});
  const variableName = name => `${spec.namePrefix}DLV - ${name}`;
  const addedVariables = parameterNames.map(name => ({
    ...identity(), variableId: String(nextId++), name: uniqueName(variableName(name)), type: 'v',
    parameter: [template('name', name), {type: 'INTEGER', key: 'dataLayerVersion', value: '2'}]
  }));
  const addedTriggers = spec.events.map(event => ({
    ...identity(), triggerId: String(nextId++), name: uniqueName(`${spec.namePrefix}Event - ${event.name}`),
    type: 'CUSTOM_EVENT', customEventFilter: [{type: 'EQUALS', parameter: [
      template('arg0', '{{_event}}'), template('arg1', event.name)
    ]}]
  }));
  const addedTags = spec.events.map((event, index) => ({
    ...identity(), tagId: String(nextId++), name: uniqueName(`${spec.namePrefix}GA4 - ${event.name}`),
    type: 'gaawe', parameter: [
      template('measurementIdOverride', spec.measurementId), template('eventName', event.name),
      {type: 'BOOLEAN', key: 'sendEcommerceData', value: 'false'},
      {type: 'LIST', key: 'eventSettingsTable', list: event.parameters.map(name => ({
        type: 'MAP', map: [template('parameter', name), template('parameterValue', `{{${variableName(name)}}}`)]
      }))}
    ],
    firingTriggerId: [addedTriggers[index].triggerId], tagFiringOption: 'ONCE_PER_EVENT',
    ...(consent ? {consentSettings: structuredClone(consent)} : {})
  }));
  return {
    exportFormatVersion: 2, exportTime,
    containerVersion: {
      ...identity(),
      ...(version.containerVersionId ? {containerVersionId: version.containerVersionId} : {}),
      container: {
        ...identity(), name: container.name, publicId: container.publicId, usageContext: container.usageContext
      },
      tag: addedTags, trigger: addedTriggers, variable: addedVariables
    }
  };
}

if (require.main === module) {
  try {
    const [input, output, ...extra] = process.argv.slice(2);
    requireValue(input && output && !extra.length,
      'Usage: node scripts/prepare-growth-gtm-import.cjs /private/current-gtm-export.json /private/growth-events-merge.json');
    requireValue(path.resolve(input) !== path.resolve(output), 'Input and output must be different files.');
    const result = buildImport(JSON.parse(fs.readFileSync(input, 'utf8')));
    fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n', {flag: 'wx', mode: 0o600});
    console.log('Prepared local MERGE ONLY import: 2 tags, 2 exact triggers, 6 variables. No Google changes made.');
    console.log('In GTM detailed changes require exactly 10 additions, 0 modifications, 0 deletions; then Preview before publishing.');
  } catch (error) {
    console.error(error.message); process.exitCode = 1;
  }
}
module.exports = {buildImport};
