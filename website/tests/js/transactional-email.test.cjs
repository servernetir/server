const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/assets/js/transactional-email.js'), 'utf8');
function mount(currency, success = false) {
  const events = new Map(), tracks = [];
  const select = {value: '50000', addEventListener: (e,f)=>events.set('select:'+e,f)};
  const output = {textContent: ''};
  const button = {disabled: false, setAttribute(){}, removeAttribute(){}};
  const form = {elements:{volume:{value:50000}},checkValidity:()=>true,addEventListener:(e,f)=>events.set('form:'+e,f),querySelector:()=>button};
  const plan = {dataset:{emailPlan:'10000'},addEventListener:(e,f)=>events.set('plan:'+e,f)};
  const estimate = {addEventListener:(e,f)=>events.set('estimate:'+e,f)};
  const root = {querySelector(s){return {'[data-email-estimator]':{dataset:{emailEstimator:JSON.stringify({plans:[{volume:10000,usd_cents:490},{volume:50000,usd_cents:1490},{volume:100000,usd_cents:2490}],extra:25,currency,usdRate:165000,eurRate:180000,locale:'en',unit:currency})}},'#email-volume':select,'#email-estimate':output,'.tx-form':form,'[data-email-estimate-cta]':estimate,'.tx-success':success?{}:null}[s]||null},querySelectorAll(s){return s==='[data-email-plan]'?[plan]:[]}};
  const window = {location:{pathname:'/en/transactional-email'},ServerNetAnalytics:{track:(...a)=>tracks.push(a)},addEventListener:(e,f)=>events.set('window:'+e,f)};
  vm.runInNewContext(source,{document:{querySelector:()=>root},window,Intl,JSON,Math,Number});
  return {events,tracks,select,output,form,plan,button};
}
test('volume changes update the real estimator and quote form in all currencies',()=>{
  for(const [currency,expected] of [['USD','62.40 USD'],['EUR','57.20 EUR'],['IRT','10,296,000 IRT']]) {
    const app=mount(currency);app.select.value='250000';app.events.get('select:change')();
    assert.equal(app.output.textContent,expected);assert.equal(app.form.elements.volume.value,250000);
    app.events.get('plan:click').call(app.plan);assert.equal(app.form.elements.volume.value,10000);
  }
});
test('only a confirmed server receipt emits a lead event, without contact data',()=>{
  assert.equal(mount('USD').tracks.length,0);
  const app=mount('USD',true);assert.equal(app.tracks.length,1);assert.equal(app.tracks[0][0],'generate_lead');
  assert.deepEqual(Object.keys(app.tracks[0][1]).sort(),['content_language','product_line','source_page']);
});
test('submit protects against double clicks and browser back re-enables the form',()=>{
  const app=mount('USD');app.events.get('form:submit')();assert.equal(app.button.disabled,true);
  app.events.get('window:pageshow')();assert.equal(app.button.disabled,false);
});
