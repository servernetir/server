import {test} from 'node:test';
import assert from 'node:assert/strict';
import {runInNewContext} from 'node:vm';
import {handle} from './worker.mjs';

async function runClient({historyFailure=false, pending=false, legacy=true, binding='d'.repeat(64),
 ticket='b'.repeat(64),assetsPending=false,assetsFailure=false,missingClient=false,assetDelays=[]}={}) {
 let js=await (await handle(new Request('https://vnc.servernet.cloud/console.js'),{CLIENT_LEGACY:legacy?'1':'0'})).text();
 if(!legacy){assert.ok(js.includes("import('/novnc/core/rfb.js')"));js=js.replace("import('/novnc/core/rfb.js')",'loadRfbModule()');}
 const elements=Object.fromEntries(['status','screen','message','cad','full','legacy-canvas'].map(x=>[x,{textContent:'',disabled:true}]));
 let calls=0, connected=0, disconnected=0, abort, rfbOptions, timerId=0,socketPath,historyCleared=false,moduleFinish,removedScripts=0;
 const assetRequests=[],assetExecutions=[],assetFinishers=[],orderedScripts=[];
 const timers=new Map(),events=new Map();
 class FakeRFB{
  constructor(options,url){rfbOptions=legacy?options:null;if(!legacy){connected++;socketPath=url;}}
  connect(host,port,password,path){connected++;socketPath=path;}
  addEventListener(name,callback){events.set(name,callback);}
  disconnect(){disconnected++;if(legacy)rfbOptions.updateState(this,'disconnected');else events.get('disconnect')?.();}
 }
 function assetRequested(path){assert.equal(historyCleared,true,'hash must be removed before asset loading');assetRequests.push(path);}
 const completion=runInNewContext(js,{
  document:{getElementById:id=>elements[id],body:{classList:{add(){}}},
   createElement(name){assert.equal(name,'script');return {remove(){removedScripts++;}};},
   head:{appendChild(script){
    assert.equal(script.async,false,'dynamic classic scripts must execute in insertion order');
    assetRequested(script.src);const entry={script,ready:false};orderedScripts.push(entry);
    const finish=()=>{entry.ready=true;queueMicrotask(()=>{while(orderedScripts[0]?.ready){const current=orderedScripts.shift().script;if(assetsFailure)current.onerror?.();else if(current.onload){assetExecutions.push(current.src);current.onload();}}});};
    assetFinishers.push(finish);const delay=assetDelays[assetRequests.length-1]||0;
    if(!assetsPending){if(delay)timers.set(++timerId,{callback:finish,ms:delay});else finish();}
   }}},
  location:{hash:ticket?'#'+ticket:'',pathname:'/',hostname:'vnc.servernet.cloud',host:'vnc.servernet.cloud'},
  history:{replaceState(){if(historyFailure)throw Error('history unavailable');historyCleared=true;}},
  setTimeout(callback,ms){timers.set(++timerId,{callback,ms});return timerId;},
  clearTimeout(id){timers.delete(id);},
  AbortSignal:{timeout(ms){assert.equal(ms,15000);const controller=new AbortController();abort=()=>controller.abort();return controller.signal;}},
  fetch:async(path,options)=>{
   calls++; assert.equal(path,'/launch');assert.equal(options.credentials,'same-origin');
   assert.equal(JSON.parse(options.body).ticket,ticket);
   if(pending)return new Promise((resolve,reject)=>options.signal?.addEventListener('abort',()=>reject(Error('timeout'))));
   return {ok:true,json:async()=>({binding})};
  },
  loadRfbModule(){assetRequested('/novnc/core/rfb.js');return new Promise((resolve,reject)=>{const finish=()=>assetsFailure?reject(Error('dummy asset error secret must not appear')):resolve({default:missingClient?null:FakeRFB});if(assetsPending)moduleFinish=finish;else finish();});},
  WebUtil:{init_logging(){}},
  RFB:missingClient?undefined:FakeRFB,
 });
 for(let i=0;i<32;i++)await Promise.resolve();
 return {elements,completion,abort,assetRequests,assetExecutions,finishAssets(){for(const finish of assetFinishers)finish();moduleFinish?.();},
  connect(){if(legacy)rfbOptions.updateState(null,'normal');else events.get('connect')();},
  tick(ms){for(const [id,timer] of [...timers])if(timer.ms<=ms){timers.delete(id);timer.callback();}},
  get timerCount(){return timers.size;},get calls(){return calls;},get connected(){return connected;},get disconnected(){return disconnected;},get socketPath(){return socketPath;},get removedScripts(){return removedScripts;}};
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
 test(name+' rejects absent or malformed ticket without loading assets or calling launch',async()=>{
  for(const ticket of ['', 'bad', 'a'.repeat(64)+'?secret']){
   const c=await runClient({legacy,ticket});await c.completion;
   assert.deepEqual(c.assetRequests,[]);assert.equal(c.calls,0);assert.equal(c.connected,0);assert.equal(c.timerCount,0);
   assert.ok(c.elements.message.textContent);assert.equal(c.elements.cad.disabled,true);
  }
 });
 test(name+' loads only fixed same-origin assets before exactly one ticket redemption',async()=>{
  const c=await runClient({legacy});await c.completion;
  assert.deepEqual(c.assetRequests,legacy?['util','webutil','base64','websock','des','input','display','jsunzip','rfb'].map(x=>'/legacy/'+x+'.js'):['/novnc/core/rfb.js']);
  assert.equal(c.calls,1);assert.equal(c.connected,1);
 });
 test(name+' asset failure is visible without redeeming a grant or exposing raw errors',async()=>{
  const c=await runClient({legacy,assetsFailure:true});await c.completion;
  assert.equal(c.calls,0);assert.equal(c.connected,0);assert.equal(c.timerCount,0);
  assert.ok(c.elements.message.textContent);assert.ok(!c.elements.message.textContent.includes('secret'));assert.equal(c.elements.cad.disabled,true);
 });
 test(name+' stalls no longer than fifteen seconds on client dependencies and ignores late completion',async()=>{
  const c=await runClient({legacy,assetsPending:true});assert.equal(c.calls,0);assert.equal(c.assetRequests.length,legacy?9:1);
  c.tick(15000);await c.completion;
  assert.equal(c.timerCount,0);assert.ok(c.elements.message.textContent);assert.equal(c.connected,0);
  if(legacy)assert.equal(c.removedScripts,9);
  c.finishAssets();for(let i=0;i<32;i++)await Promise.resolve();
  assert.equal(c.calls,0);assert.equal(c.connected,0);assert.equal(c.assetRequests.length,legacy?9:1);assert.equal(c.elements.cad.disabled,true);
 });
 test(name+' missing client export fails before consuming a launch ticket',async()=>{
  const c=await runClient({legacy,missingClient:true});await c.completion;
  assert.equal(c.calls,0);assert.equal(c.connected,0);assert.ok(c.elements.message.textContent);
 });
 test(name+' history failure prevents dependency reads as well as ticket redemption',async()=>{
  const c=await runClient({legacy,historyFailure:true});await c.completion;
  assert.deepEqual(c.assetRequests,[]);assert.equal(c.calls,0);assert.equal(c.connected,0);assert.ok(c.elements.message.textContent);
 });
 test(name+' stalled launch stays bounded after client dependencies are ready',async()=>{
  const c=await runClient({legacy,pending:true});assert.equal(c.calls,1);assert.equal(typeof c.abort,'function');
  c.abort();await c.completion;
  assert.equal(c.calls,1);assert.equal(c.connected,0);assert.equal(c.timerCount,0);assert.ok(c.elements.message.textContent);
 });
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

test('legacy starts all cold downloads together instead of spending two seconds per dependency',async()=>{
 const c=await runClient({legacy:true,assetDelays:Array(9).fill(2000)});
 assert.equal(c.assetRequests.length,9);assert.equal(c.assetExecutions.length,0);assert.equal(c.calls,0);
 c.tick(2000);await c.completion;
 assert.deepEqual(c.assetExecutions,c.assetRequests);assert.equal(c.calls,1);assert.equal(c.connected,1);
 assert.equal(c.timerCount,1);c.connect();assert.equal(c.timerCount,0);
});
test('legacy requests ordered execution even when later scripts download first',async()=>{
 const c=await runClient({legacy:true,assetDelays:[9000,8000,7000,6000,5000,4000,3000,2000,1000]});
 assert.equal(c.assetRequests.length,9);c.tick(8000);for(let i=0;i<32;i++)await Promise.resolve();
 assert.deepEqual(c.assetExecutions,[]);assert.equal(c.calls,0);
 c.tick(9000);await c.completion;
 assert.deepEqual(c.assetExecutions,c.assetRequests);assert.equal(c.calls,1);assert.equal(c.connected,1);
});
