const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const api = new Function('module',fs.readFileSync(path.join(__dirname,'../../public/assets/js/hourly-explorer.js'),'utf8')+';return module.exports;')({exports:{}});
const plan = {id:1,hourly:2300,monthly:1630000,ram:4096,cpu:2,country:'FI'};
test('short usage and starting credit are different amounts', () => {
  assert.deepEqual(api.quote(plan,6,24), {total:13800,credit:55200,monthly:1630000,breakEven:1630000/2300});
});
test('720 reserved hours do not become a monthly tariff or cap', () => {
  const q=api.quote(plan,720,24);assert.equal(q.total,1656000);assert.equal(q.monthly,1630000);assert.notEqual(q.total,q.monthly);
});
test('EUR quotes retain the displayed cents without reconverting IRT', () => {
  assert.equal(api.quote({...plan,hourly:3,monthly:570},72,24).total,216);
});
test('invalid and fractional hours are not silently rounded or clamped', () => {
  for(const h of ['', ' ',0,-1,721,2.5,'abc',null,Infinity]) assert.equal(api.hours(h),null);
  assert.equal(api.quote(plan,'',24).total,null);
  assert.equal(api.hours('168'),168);assert.equal(api.hours(1),1);assert.equal(api.hours(720),720);
});
test('unpriced plans are not quoted and absent monthly rates have no break-even', () => {
  assert.equal(api.quote({...plan,hourly:0},24,24),null);
  assert.equal(api.quote({...plan,monthly:0},24,24).breakEven,null);
});
test('resource suggestions choose the cheapest qualifying sample only', () => {
  const plans=[plan,{...plan,id:2,hourly:1500,ram:2048,cpu:1},{...plan,id:3,hourly:3400,ram:8192,cpu:4}];
  assert.equal(api.recommend(plans,4096,2).id,1);assert.equal(api.recommend(plans,8192,4).id,3);
  assert.equal(api.recommend(plans,16384,8),null);
});
test('filters combine country and minimum RAM', () => {
  assert.equal(api.matches(plan,'FI',4096),true);assert.equal(api.matches(plan,'DE',0),false);assert.equal(api.matches(plan,'',8192),false);
});
test('country search normalizes Arabic/Persian letter forms', () => {
  assert.equal(api.normalize('  تركيه '),api.normalize('ترکیه'));
});
