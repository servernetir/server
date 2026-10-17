import {test} from 'node:test';
import assert from 'node:assert/strict';
import {runInNewContext} from 'node:vm';
import {handle} from './worker.mjs';

async function runClient({historyFailure=false, pending=false}={}) {
 const js=await (await handle(new Request('https://vnc.servernet.cloud/console.js'),{CLIENT_LEGACY:'1'})).text();
 const elements=Object.fromEntries(['status','screen','message','cad','full','legacy-canvas'].map(x=>[x,{textContent:'',disabled:true}]));
 let calls=0, connected=0, abort;
 const completion=runInNewContext(js,{
  document:{getElementById:id=>elements[id]},
  location:{hash:'#'+'b'.repeat(64),pathname:'/',hostname:'vnc.servernet.cloud'},
  history:{replaceState(){if(historyFailure)throw Error('history unavailable');}},
  AbortSignal:{timeout(ms){assert.equal(ms,15000);const controller=new AbortController();abort=()=>controller.abort();return controller.signal;}},
  fetch:async(path,options)=>{
   calls++; assert.equal(path,'/launch');assert.equal(options.credentials,'same-origin');
   assert.equal(JSON.parse(options.body).ticket,'b'.repeat(64));
   if(pending)return new Promise((resolve,reject)=>options.signal?.addEventListener('abort',()=>reject(Error('timeout'))));
   return {ok:true};
  },
  WebUtil:{init_logging(){}},
  RFB:class{connect(){connected++;}},
 });
 await Promise.resolve();
 return {elements,completion,abort,get calls(){return calls;},get connected(){return connected;}};
}
test('legacy client reaches launch and connects with a valid synthetic ticket',async()=>{
 const c=await runClient();await c.completion;assert.equal(c.calls,1);assert.equal(c.connected,1);
});
test('history failure is contained and shows an error before broker exchange',async()=>{
 const c=await runClient({historyFailure:true});await c.completion;
 assert.equal(c.calls,0);assert.ok(c.elements.message.textContent);assert.equal(c.elements.cad.disabled,true);
});
test('stalled launch has an abort deadline and becomes a visible failure',async()=>{
 const c=await runClient({pending:true});assert.equal(typeof c.abort,'function');c.abort();await c.completion;
 assert.ok(c.elements.message.textContent);assert.equal(c.connected,0);
});
