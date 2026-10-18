import {test} from 'node:test';
import assert from 'node:assert/strict';
import {runInNewContext} from 'node:vm';
import {handle} from './worker.mjs';

async function runClient({historyFailure=false, pending=false, legacy=true, binding='d'.repeat(64)}={}) {
 let js=await (await handle(new Request('https://vnc.servernet.cloud/console.js'),{CLIENT_LEGACY:legacy?'1':'0'})).text();
 if(!legacy)js='(async()=>{'+js.replace("import RFB from '/novnc/core/rfb.js';",'')+'})();';
 const elements=Object.fromEntries(['status','screen','message','cad','full','legacy-canvas'].map(x=>[x,{textContent:'',disabled:true}]));
 let calls=0, connected=0, disconnected=0, abort, rfbOptions, timerId=0,socketPath;
 const timers=new Map(),events=new Map();
 const completion=runInNewContext(js,{
  document:{getElementById:id=>elements[id],body:{classList:{add(){}}}},
  location:{hash:'#'+'b'.repeat(64),pathname:'/',hostname:'vnc.servernet.cloud',host:'vnc.servernet.cloud'},
  history:{replaceState(){if(historyFailure)throw Error('history unavailable');}},
  setTimeout(callback,ms){timers.set(++timerId,{callback,ms});return timerId;},
  clearTimeout(id){timers.delete(id);},
  AbortSignal:{timeout(ms){assert.equal(ms,15000);const controller=new AbortController();abort=()=>controller.abort();return controller.signal;}},
  fetch:async(path,options)=>{
   calls++; assert.equal(path,'/launch');assert.equal(options.credentials,'same-origin');
   assert.equal(JSON.parse(options.body).ticket,'b'.repeat(64));
   if(pending)return new Promise((resolve,reject)=>options.signal?.addEventListener('abort',()=>reject(Error('timeout'))));
   return {ok:true,json:async()=>({binding})};
  },
  WebUtil:{init_logging(){}},
  RFB:class{
   constructor(options,url){rfbOptions=legacy?options:null;if(!legacy){connected++;socketPath=url;}}
   connect(host,port,password,path){connected++;socketPath=path;}
   addEventListener(name,callback){events.set(name,callback);}
   disconnect(){disconnected++;if(legacy)rfbOptions.updateState(this,'disconnected');else events.get('disconnect')?.();}
  },
 });
 await Promise.resolve();
 return {elements,completion,abort,
  connect(){if(legacy)rfbOptions.updateState(null,'normal');else events.get('connect')();},
  tick(ms){for(const [id,timer] of [...timers])if(timer.ms<=ms){timers.delete(id);timer.callback();}},
  get timerCount(){return timers.size;},get calls(){return calls;},get connected(){return connected;},get disconnected(){return disconnected;},get socketPath(){return socketPath;}};
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

for(const legacy of [true,false]){
 const name=legacy?'legacy':'modern';
 test(name+' client binds its socket to the fingerprint returned to this tab',async()=>{
  const binding='e'.repeat(64),c=await runClient({legacy,binding});await c.completion;
  assert.equal(c.socketPath,(legacy?'':'wss://vnc.servernet.cloud/')+'socket?binding='+binding);
 });
 test(name+' client fails closed on a missing or malformed launch binding',async()=>{
  for(const binding of [undefined,'bad','f'.repeat(64)+'&token=bad']){
   const c=await runClient({legacy,binding:binding??null});await c.completion;
   assert.equal(c.connected,0);assert.ok(c.elements.message.textContent);assert.equal(c.elements.cad.disabled,true);
  }
 });
 test(name+' client stops a stalled RFB connection and ignores a late connect',async()=>{
  const c=await runClient({legacy});await c.completion;
  assert.equal(c.timerCount,1);c.tick(20000);
  assert.ok(c.elements.message.textContent);assert.equal(c.elements.cad.disabled,true);
  assert.equal(c.disconnected,1);assert.equal(c.timerCount,0);
  c.connect();assert.equal(c.elements.cad.disabled,true);assert.ok(c.elements.message.textContent);
 });
 test(name+' client cancels the connection deadline after a healthy handshake',async()=>{
  const c=await runClient({legacy});await c.completion;c.connect();
  assert.equal(c.timerCount,0);c.tick(60000);
  assert.equal(c.elements.cad.disabled,false);assert.equal(c.elements.status.textContent,'متصل');
  assert.equal(c.disconnected,0);
 });
}
