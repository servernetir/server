const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const sandbox={exports:{}};
vm.runInThisContext('(function(module){'+fs.readFileSync(path.join(__dirname,'../../public/assets/js/ai-workspace.js'),'utf8')+'})')(sandbox);
const {estimate,normaliseSelection,toggleSelection}=sandbox.exports;
const rates={inputRate:'31050',outputRate:'54000',context:128000,outputMax:4096,chat:true};
test('planning uses integer per-request rounding and multiplies request count',()=>{
  assert.deepEqual(estimate(rates,'1000','500','10000'),{perRequest:59n,total:590000n});
  assert.equal(estimate(rates,'0','0','1').total,0n);
  assert.equal(estimate({...rates,inputRate:'9007199254740993'},'100000','0','10000000').total,9007199254741000000000n);
});
test('invalid or out-of-contract token counts never produce an estimate',()=>{
  for(const v of [['1.5','0','1'],['-1','0','1'],['1','0','0'],['1','4097','1'],['128000','1','1'],['1','0','10000001'],['','0','1']]){
    assert.throws(()=>estimate(rates,...v),RangeError);
  }
  assert.throws(()=>estimate({...rates,inputRate:'unknown'},'1','0','1'),TypeError);
});
test('input-only operations never acquire output usage',()=>{
  const inputOnly={...rates,outputMax:0,chat:false};
  assert.equal(estimate(inputOnly,'1000','0','2').total,64n);
  assert.throws(()=>estimate(inputOnly,'1000','1','2'),RangeError);
});
test('comparison storage rejects malformed values and preserves at most three unique ids',()=>{
  assert.deepEqual(normaliseSelection(['a','b','a','<script>','c','d',null]),['a','b','c']);
  assert.deepEqual(normaliseSelection({models:['a']}),[]);
  assert.deepEqual(toggleSelection(['a','b'],'a'),['b']);
  assert.deepEqual(toggleSelection(['a','b'],'c'),['a','b','c']);
  assert.throws(()=>toggleSelection(['a','b','c'],'d'),RangeError);
  assert.throws(()=>toggleSelection([],''),TypeError);
});