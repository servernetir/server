const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sandbox = {module:{exports:{}}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/assets/js/datacenter-planner.js'),'utf8'),sandbox);
// Normalize cross-realm objects for strict assertions.
const c = Object.fromEntries(Object.entries(sandbox.module.exports).map(([name,fn])=>[name, input=>JSON.parse(JSON.stringify(fn(input)))]));
test('RAID capacity and constraints',()=>{
  assert.deepEqual(c.raid({drives:4,disk_tb:4,raid_mode:5}),{raw:16,usable:12});
  assert.deepEqual(c.raid({drives:6,disk_tb:4,raid_mode:6}),{raw:24,usable:16});
  assert.deepEqual(c.raid({drives:4,disk_tb:4,raid_mode:10}),{raw:16,usable:8});
  assert.deepEqual(c.raid({drives:2,disk_tb:1.92,raid_mode:1}),{raw:3.84,usable:1.92});
  for(const [drives,raid_mode] of [[2,5],[3,6],[5,10],[4,7],[2.2,1]]) assert.throws(()=>c.raid({drives,disk_tb:4,raid_mode}));
  assert.throws(()=>c.raid({drives:4,disk_tb:'',raid_mode:5}));
});
test('rack and UPS calculations do not infer physical compatibility or runtime',()=>{
  assert.deepEqual(c.rack({used_u:18,growth:20}),{minimum_u:22,rack_option:22});
  assert.deepEqual(c.rack({used_u:48,growth:20}),{minimum_u:58,rack_option:null});
  assert.deepEqual(c.ups({load:1000,pf:.8,growth:20}),{minimum_w:1200,minimum_va:1500});
  assert.throws(()=>c.ups({load:1000,pf:0,growth:20}));
  assert.throws(()=>c.ups({load:-1,pf:.8,growth:20}));
});
test('VM demand and cost without currency conversion',()=>{
  assert.deepEqual(c.vm({vms:10,ram_per:4,vcpu_per:2,growth:20}),{ram_sum:48,vcpu_sum:20});
  assert.deepEqual(c.cost({purchase:1000,maintenance:40,rent:90,months:36}),{owned_total:2440,rent_total:3240});
  assert.deepEqual(c.cost({purchase:0.1,maintenance:0.2,rent:0.3,months:3}),{owned_total:0.7,rent_total:0.9});
  assert.throws(()=>c.cost({purchase:0,maintenance:0,rent:0,months:0}));
});
