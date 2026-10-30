#!/usr/bin/env node
'use strict';

// Offline, MERGE ONLY. Read a fresh authorized export; never call Google or
// generate replacement account/container/entity IDs. Preview remains required.
const fs = require('node:fs');
const path = require('node:path');
const TARGET = Object.freeze({containerPublicId: 'GTM-MRSC7BF7', measurementId: 'G-W90N0G8Y2M'});

function check(condition, message) {
  if (!condition) throw new Error(message);
}
function normalized(value) { return String(value || '').replace(/_/g, '').toUpperCase(); }
function parameters(entity) {
  const entries = entity.parameter || [];
  check(Array.isArray(entries), 'Expected a parameter array.');
  const result = Object.create(null);
  for (const entry of entries) {
    check(entry && typeof entry.key === 'string' && !Object.hasOwn(result, entry.key),
      'Duplicate or malformed parameter; review the export manually.');
    result[entry.key] = entry;
  }
  return result;
}
function literal(value, variables) {
  if (typeof value !== 'string') return undefined;
  const ref = /^\{\{([^{}]+)\}\}$/.exec(value);
  if (!ref) return value.includes('{{') ? undefined : value;
  const found = variables.filter(v => v.name === ref[1] && v.type === 'c');
  const resolved = found.length === 1 ? parameters(found[0]).value?.value : undefined;
  return typeof resolved === 'string' && !resolved.includes('{{') ? resolved : undefined;
}
function booleanFlag(parameter) {
  if (!parameter) return false;
  check(parameter.type === 'BOOLEAN' && ['true', 'false'].includes(parameter.value),
    'Computed or malformed condition flag requires manual review.');
  return parameter.value === 'true';
}
function isEventReference(value, version) {
  if (['{{Event}}', '{{_event}}'].includes(value)) return true;
  const names = [...(version.variable || []), ...(version.builtInVariable || [])]
    .filter(v => ['E', 'EVENT'].includes(normalized(v.type))).map(v => `{{${v.name}}}`);
  return names.includes(value);
}
function triggerCanMatchPurchase(trigger, version) {
  check(normalized(trigger.type) === 'CUSTOMEVENT',
    `Unfamiliar purchase-capable trigger: ${trigger.name || trigger.triggerId}`);
  const conditions = trigger.customEventFilter || [];
  check(Array.isArray(conditions), 'Expected custom event conditions.');
  // Host/page filters and blockers cannot prove that two routes never overlap.
  return conditions.every(condition => {
    const p = parameters(condition);
    check(isEventReference(p.arg0?.value, version), 'Computed custom event condition requires manual review.');
    const expected = p.arg1?.value;
    check(typeof expected === 'string' && !expected.includes('{{'), 'Computed event condition requires manual review.');
    const ignoreCase = booleanFlag(p.ignore_case), negate = booleanFlag(p.negate);
    let matches;
    switch (normalized(condition.type)) {
      case 'EQUALS': matches = expected === 'purchase'; break;
      case 'MATCHREGEX':
        try { matches = new RegExp(expected, ignoreCase ? 'i' : '').test('purchase'); }
        catch { throw new Error('Invalid custom event regex; review manually.'); }
        break;
      default: throw new Error('Unsupported custom event condition; review manually.');
    }
    return negate ? !matches : matches;
  });
}

function buildRepair(source, target = TARGET, exportTime = new Date().toISOString()) {
  check(Number(source?.exportFormatVersion) === 2, 'Expected a fresh authorized GTM JSON export, format 2.');
  const version = source.containerVersion, container = version?.container;
  check(container?.publicId === target.containerPublicId, 'Container public ID does not match.');
  check((container.usageContext || []).some(v => normalized(v) === 'WEB'), 'Expected a web container.');
  const identity = {accountId: container.accountId, containerId: container.containerId};
  check(Object.values(identity).every(v => typeof v === 'string' && /^\d+$/.test(v)),
    'Account/container IDs must come from the authorized export.');
  for (const key of Object.keys(identity)) {
    check(!version[key] || version[key] === identity[key], `Inconsistent ${key} in export.`);
  }
  const tags = version.tag || [], triggers = version.trigger || [], variables = version.variable || [];
  for (const [entities, idKey] of [[tags, 'tagId'], [triggers, 'triggerId'], [variables, 'variableId']]) {
    check(Array.isArray(entities), 'Expected entity arrays.');
    const ids = new Set();
    for (const entity of entities) {
      check(typeof entity[idKey] === 'string' && /^\d+$/.test(entity[idKey]) && !ids.has(entity[idKey]),
        `Duplicate or missing ${idKey}.`);
      ids.add(entity[idKey]);
      check(Object.keys(identity).every(key => !entity[key] || entity[key] === identity[key]),
        'Entity belongs to another account/container.');
      parameters(entity);
      check(entity.paused === undefined || typeof entity.paused === 'boolean', 'Malformed paused state requires manual review.');
    }
  }
  check(tags.some(t => t.type === 'googtag' && !t.paused &&
    literal(parameters(t).tagId?.value, variables) === target.measurementId),
  'An active Google tag for the target measurement ID is required.');
  const sameProperty = tags.filter(t => {
    if (t.type !== 'gaawe' || t.paused) return false;
    const measurement = literal(parameters(t).measurementIdOverride?.value, variables);
    check(measurement !== undefined, 'Computed/implicit GA4 measurement ID requires manual review.');
    return measurement === target.measurementId;
  });
  const candidates = sameProperty.filter(t => literal(parameters(t).eventName?.value, variables) === 'purchase');
  check(candidates.length === 1, 'Expected one unique active purchase tag for the target property.');
  const purchase = candidates[0], p = parameters(purchase);
  check(tags.filter(t => t.name === purchase.name).length === 1, 'Purchase tag name must be unique for MERGE.');
  check(p.sendEcommerceData?.type === 'BOOLEAN' && p.sendEcommerceData.value === 'true',
    'Purchase must already enable ecommerce; review a different template manually.');
  check(!p.getEcommerceDataFrom && !p.ecommerceMacroData,
    'Purchase already has an ecommerce source; review instead of overwriting it.');
  check(!p.eventSettingsVariable, 'Purchase event settings variable requires manual review.');
  if (p.eventSettingsTable) {
    check(p.eventSettingsTable.type === 'LIST' && Array.isArray(p.eventSettingsTable.list),
      'Unknown purchase event settings template.');
    for (const row of p.eventSettingsTable.list) {
      check(row.type === 'MAP' && Array.isArray(row.map), 'Unknown purchase event settings row.');
      const name = row.map.find(v => v.key === 'parameter')?.value;
      check(typeof name === 'string' && !name.includes('{{'), 'Computed event parameter requires manual review.');
      check(!['value', 'currency', 'items', 'transaction_id', 'tax', 'shipping', 'affiliation', 'coupon'].includes(name),
        'Explicit commerce parameter may override Data Layer; review manually.');
    }
  }
  check(Array.isArray(purchase.firingTriggerId) && purchase.firingTriggerId.length === 1,
    'Purchase must have one exact custom event trigger.');
  const purchaseTrigger = triggers.find(t => t.triggerId === purchase.firingTriggerId[0]);
  check(purchaseTrigger && normalized(purchaseTrigger.type) === 'CUSTOMEVENT', 'Purchase trigger is missing or unfamiliar.');
  const conditions = purchaseTrigger.customEventFilter || [];
  check(conditions.length === 1 && normalized(conditions[0].type) === 'EQUALS', 'Purchase trigger must match exactly purchase.');
  const condition = parameters(conditions[0]);
  check(isEventReference(condition.arg0?.value, version) && condition.arg1?.value === 'purchase'
    && !booleanFlag(condition.negate), 'Purchase trigger must match exactly purchase.');

  for (const tag of sameProperty) {
    if (tag === purchase) continue;
    const event = parameters(tag).eventName?.value, name = literal(event, variables);
    if (name !== undefined && !isEventReference(event, version)) continue;
    check(isEventReference(event, version), 'Computed GA4 event name requires manual review.');
    check(Array.isArray(tag.firingTriggerId) && tag.firingTriggerId.length > 0,
      'Dynamic GA4 event tag without known triggers requires manual review.');
    for (const id of tag.firingTriggerId) {
      const trigger = triggers.find(t => t.triggerId === id);
      check(trigger, 'GA4 firing trigger is missing from the export.');
      check(!triggerCanMatchPurchase(trigger, version), 'Another active GA4 route can send purchase.');
    }
  }
  for (const tag of tags.filter(t => !t.paused)) {
    for (const step of [...(tag.setupTag || []), ...(tag.teardownTag || [])]) {
      check(typeof step.tagName === 'string' && !step.tagName.includes('{{'),
        'Computed tag sequencing requires manual review.');
      check(step.tagName !== purchase.name, 'Purchase is also invoked by tag sequencing; review manually.');
    }
  }
  // Copy the field's known shape from a same-property active ecommerce tag.
  const reference = sameProperty.find(t => t !== purchase &&
    parameters(t).sendEcommerceData?.type === 'BOOLEAN' && parameters(t).sendEcommerceData.value === 'true' &&
    parameters(t).getEcommerceDataFrom?.type === 'TEMPLATE' && parameters(t).getEcommerceDataFrom.value === 'dataLayer');
  check(reference, 'An active same-property Data Layer ecommerce template is required.');
  const repaired = structuredClone(purchase);
  repaired.parameter.push(structuredClone(parameters(reference).getEcommerceDataFrom));
  return {
    exportFormatVersion: 2, exportTime,
    containerVersion: {
      ...identity,
      ...(version.containerVersionId ? {containerVersionId: version.containerVersionId} : {}),
      container: {...identity, name: container.name, publicId: container.publicId, usageContext: container.usageContext},
      tag: [repaired], trigger: [], variable: []
    }
  };
}

function writeRepair(input, output, target = TARGET) {
  check(path.resolve(input) !== path.resolve(output), 'Input and output must be different files.');
  const result = buildRepair(JSON.parse(fs.readFileSync(input, 'utf8')), target);
  fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n', {flag: 'wx', mode: 0o600});
}

if (require.main === module) {
  try {
    const [input, output, ...extra] = process.argv.slice(2);
    check(input && output && !extra.length,
      'Usage: node scripts/prepare-purchase-gtm-repair.cjs /private/fresh-export.json /private/purchase-repair-merge.json');
    writeRepair(input, output);
    console.log('Prepared offline MERGE ONLY repair: 1 modified existing tag, 0 additions, 0 deletions.');
    console.log('Verify Detailed Changes, then Preview/DebugView before publishing. No Google changes made.');
  } catch (error) {
    console.error(error.message); process.exitCode = 1;
  }
}
module.exports = {buildRepair, writeRepair};
