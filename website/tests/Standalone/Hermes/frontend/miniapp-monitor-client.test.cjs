'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const assert = require('node:assert/strict');

const view = fs.readFileSync(path.join(__dirname, '../../../../resources/views/bale/hermes.blade.php'), 'utf8');
const begin = '// Hermes monitoring panel (read-only).';
const end = '// End Hermes monitoring panel.';
const start = view.indexOf(begin);
const finish = view.indexOf(end, start);
assert.ok(start >= 0 && finish > start, 'monitoring panel markers must exist');
const fragment = view.slice(start, finish + end.length);

class Element {
  constructor(tag='div') { this.tagName=tag; this.children=[]; this._text=''; this.className=''; this.dir=''; }
  set textContent(value) { this._text=String(value); this.children=[]; }
  get textContent() { return this._text + this.children.map(child=>child.textContent).join(''); }
  set innerHTML(value) { throw new Error('HTML injection API must not be used'); }
  insertAdjacentHTML() { throw new Error('HTML injection API must not be used'); }
  append(...elements) { this.children.push(...elements); }
  replaceChildren(...elements) { this._text=''; this.children=[...elements]; }
}

function fixture({lang='fa',authorized=true,api}={}) {
  const nodes=new Map();
  const calls=[];
  const created=[];
  const context={
    lang,authorized,
    $:id=>{if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id);},
    document:{createElement:tag=>{const element=new Element(tag);created.push(element);return element;}},
    number:value=>'N('+value+')',
    api:(...args)=>{calls.push(args);return api ? api(...args) : Promise.resolve(args[0]==='automation' ? {ok:true,automation:{enabled:true,last_run_at:1792587600,sent_replies:3}} : {ok:true,errors:[]});},
    module:{exports:{}}
  };
  vm.runInNewContext(fragment+'\nmodule.exports={monitor,monitorAutomation,monitorErrors,monitorDate};',context,{filename:'hermes-monitor-panel.js'});
  return {context,nodes,calls,created,...context.module.exports};
}
const fulfilled=value=>({status:'fulfilled',value});
const rejected=reason=>({status:'rejected',reason});
const automation=overrides=>({ok:true,automation:{enabled:true,last_run_at:1792587600,sent_replies:3,...overrides}});
const errorRows=count=>Array.from({length:count},(_,index)=>({utc:1792587600-index,code:'error_'+index,count:index+1,request_id:'request_'+index}));

for (const [lang,labels] of Object.entries({
  fa:{title:'پایش و گزارش خطا',hourly:'بررسی تیکت‌ها هر ساعت',last:'آخرین بررسی',sent:'پاسخ‌های ارسال‌شده',errors:'۵ خطای اخیر',enabled:'فعال',disabled:'غیرفعال'},
  en:{title:'Monitoring and error log',hourly:'Hourly ticket review',last:'Last review',sent:'Replies sent',errors:'5 recent errors',enabled:'Enabled',disabled:'Disabled'},
  tr:{title:'İzleme ve hata kaydı',hourly:'Saatlik talep kontrolü',last:'Son kontrol',sent:'Gönderilen yanıtlar',errors:'Son 5 hata',enabled:'Etkin',disabled:'Devre dışı'}
})) {
  test(lang+' labels and automation values render through text nodes',()=>{
    const f=fixture({lang});
    for(const [id,key] of Object.entries({monitorTitle:'title',monitorEnabledLabel:'hourly',monitorLastLabel:'last',monitorSentLabel:'sent',monitorErrorsTitle:'errors'}))assert.equal(f.nodes.get(id).textContent,labels[key]);
    f.monitorAutomation(fulfilled(automation()));
    assert.equal(f.nodes.get('monitorEnabled').textContent,labels.enabled);
    assert.equal(f.nodes.get('monitorSent').textContent,'N(3)');
    assert.notEqual(f.nodes.get('monitorLast').textContent,'—');
    assert.ok(f.nodes.get('monitorLast').textContent.length>5);
    assert.equal(f.nodes.get('monitorAutomationState').textContent,'');
    f.monitorAutomation(fulfilled(automation({enabled:false,last_run_at:null,sent_replies:0})));
    assert.equal(f.nodes.get('monitorEnabled').textContent,labels.disabled);
    assert.equal(f.nodes.get('monitorLast').textContent,'—');
    assert.equal(f.nodes.get('monitorSent').textContent,'N(0)');
  });
}

test('only the five most recent supplied errors are rendered',()=>{
  const f=fixture({lang:'en'});
  f.monitorErrors(fulfilled({ok:true,errors:errorRows(8)}));
  const list=f.nodes.get('monitorErrors');
  assert.equal(list.children.length,5);
  assert.match(list.children[0].textContent,/error_0/);
  assert.match(list.children[4].textContent,/error_4/);
  assert.doesNotMatch(list.textContent,/error_5|error_6|error_7/);
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'');
  assert.equal(f.created.filter(node=>node.tagName==='article').length,5);
});

test('untrusted error contents cannot become HTML or leak raw message bodies',()=>{
  const f=fixture({lang:'en'});
  f.monitorErrors(fulfilled({ok:true,errors:[{utc:1792587600,code:'<img src=x onerror=alert(1)>',request_id:'<script>secret</script>',count:-9,message:'Authorization: Bearer private-key'}]}));
  const text=f.nodes.get('monitorErrors').textContent;
  assert.match(text,/unknown/);
  assert.match(text,/Request ID: —/);
  assert.match(text,/Count: N\(1\)/);
  assert.doesNotMatch(text,/<img|<script|private-key|Authorization|Bearer/);
  assert.equal(f.nodes.get('monitorErrors').children[0].children[0].dir,'auto');
});

test('an empty error log and an unavailable error log remain distinct',()=>{
  const f=fixture({lang:'en'});
  f.monitorErrors(fulfilled({ok:true,errors:errorRows(2)}));
  assert.equal(f.nodes.get('monitorErrors').children.length,2);
  f.monitorErrors(fulfilled({ok:true,errors:[]}));
  assert.equal(f.nodes.get('monitorErrors').children.length,0);
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'No errors recorded.');
  f.monitorErrors(rejected(new Error('upstream unavailable')));
  assert.equal(f.nodes.get('monitorErrors').children.length,0);
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'Status unavailable. Refresh to retry.');
  f.monitorErrors(fulfilled({ok:false,errors:[]}));
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'Status unavailable. Refresh to retry.');
});

test('failed automation status does not block successful error loading',async()=>{
  const f=fixture({lang:'en',api:route=>route==='automation'?Promise.reject(new Error('automation unavailable')):Promise.resolve({ok:true,errors:errorRows(1)})});
  await f.monitor();
  assert.equal(f.nodes.get('monitorAutomationState').textContent,'Status unavailable. Refresh to retry.');
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'');
  assert.equal(f.nodes.get('monitorErrors').children.length,1);
});

test('failed error loading does not block successful automation status',async()=>{
  const f=fixture({lang:'en',api:route=>route==='errors'?Promise.reject(new Error('errors unavailable')):Promise.resolve(automation({sent_replies:7}))});
  await f.monitor();
  assert.equal(f.nodes.get('monitorAutomationState').textContent,'');
  assert.equal(f.nodes.get('monitorEnabled').textContent,'Enabled');
  assert.equal(f.nodes.get('monitorSent').textContent,'N(7)');
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'Status unavailable. Refresh to retry.');
});

test('refresh performs read-only calls, supports refreshing again, and does not POST',async()=>{
  const f=fixture({lang:'en'});
  await f.monitor();
  await f.monitor();
  assert.deepEqual(f.calls.map(args=>args[0]),['automation','errors','automation','errors']);
  assert.ok(f.calls.every(args=>args.length===1),'no request method/body is passed; api defaults to GET');
});

test('overlapping refreshes are suppressed while both read-only requests settle',async()=>{
  const pending={};
  const f=fixture({lang:'en',api:route=>new Promise(resolve=>{pending[route]=resolve;})});
  const first=f.monitor();
  assert.equal(f.nodes.get('monitorAutomationState').textContent,'Loading status…');
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'Loading status…');
  await f.monitor();
  assert.equal(f.calls.length,2);
  pending.automation(automation());
  pending.errors({ok:true,errors:[]});
  await first;
  assert.equal(f.nodes.get('monitorAutomationState').textContent,'');
  assert.equal(f.nodes.get('monitorErrorsState').textContent,'No errors recorded.');
});

test('monitoring is not requested before authorization',async()=>{
  const f=fixture({authorized:false});
  await f.monitor();
  assert.equal(f.calls.length,0);
});

test('malformed automation envelopes show unavailable rather than an enabled state',()=>{
  const f=fixture({lang:'en'});
  for(const value of [{},{ok:false,automation:{enabled:true}},{ok:true,automation:{enabled:'true'}}]){
    f.monitorAutomation(fulfilled(value));
    assert.equal(f.nodes.get('monitorAutomationState').textContent,'Status unavailable. Refresh to retry.');
  }
});
