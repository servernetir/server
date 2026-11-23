const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const sandbox={exports:{}};
vm.runInThisContext('(function(module){'+fs.readFileSync(path.join(__dirname,'../../public/assets/js/n8n-configurator.js'),'utf8')+'})')(sandbox);
const {selection}=sandbox.exports;
const cards=[{country:'DE',hourly:8000},{country:'IR',hourly:4000},{country:'TR',hourly:0}];
test('a country change clears a package from another country',()=>{
  assert.equal(selection(cards,'IR',0,'yearly',true).plan,null);
  assert.equal(selection(cards,'IR',0,'yearly',true).hourly,false);
});
test('only the selected supported package can enable hourly billing',()=>{
  assert.equal(selection(cards,'DE',0,'monthly',true).hourly,true);
  assert.equal(selection(cards,'IR',1,'monthly',true).hourly,true);
  assert.equal(selection(cards,'TR',2,'yearly',true).hourly,false);
  assert.equal(selection([{country:'TR',hourly:99}],'TR',0,'yearly',true).hourly,false);
});
test('invalid package identities cannot produce a purchasable selection',()=>{
  for(const index of [-1,99,NaN,0.5,'0']) assert.equal(selection(cards,'DE',index,'monthly',true).plan,null);
});
test('the period is retained across valid changes and unknown periods reset safely',()=>{
  for(const cycle of ['monthly','quarterly','semiannual','yearly']) assert.equal(selection(cards,'DE',0,cycle,false).cycle,cycle);
  assert.equal(selection(cards,'DE',0,'weekly',false).cycle,'monthly');
});
