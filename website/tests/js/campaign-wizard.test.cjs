const assert = require('node:assert/strict');
const fs = require('node:fs'); const vm = require('node:vm'); const path = require('node:path');
const context = {module:{exports:{}}}; vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/assets/js/campaign-wizard.js'),'utf8'),context); const {init}=context.module.exports;
function control() {
  return { hidden:false, listeners:{}, attrs:{}, addEventListener(k,fn){this.listeners[k]=fn;},
    setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];} };
}
let inputs = Array.from({length:3},()=>({valid:true,reports:0,checkValidity(){return this.valid;},reportValidity(){this.reports++;}}));
let panels = inputs.map(input=>({hidden:false, legend:{setAttribute(){},focus(){}},querySelectorAll(){return [input];},querySelector(){return this.legend;}}));
let steps = panels.map(control), prev=control(), next=control(),save=control(),root=control();
root.querySelectorAll=s=>s==='fieldset'?panels:steps;
root.querySelector=s=>({'[data-prev]':prev,'[data-next]':next,'[data-save]':save})[s];
init(root);
assert.deepEqual(panels.map(p=>p.hidden),[false,true,true]);
assert.equal(prev.hidden,true);assert.equal(save.hidden,true);
inputs[0].valid=false;next.listeners.click();
assert.equal(panels[0].hidden,false);assert.equal(inputs[0].reports,1);
inputs[0].valid=true;next.listeners.click();next.listeners.click();
assert.equal(next.hidden,true);assert.equal(save.hidden,false);
inputs[0].valid=false;let prevented=false;root.listeners.submit({preventDefault(){prevented=true;}});
assert.equal(prevented,true);assert.equal(panels[0].hidden,false);assert.equal(steps[0].attrs['aria-current'],'step');
inputs[0].valid=true;prevented=false;root.listeners.submit({preventDefault(){prevented=true;}});
assert.equal(prevented,false);
console.log('Campaign wizard: navigation, invalid fields and safe final submit passed.');
