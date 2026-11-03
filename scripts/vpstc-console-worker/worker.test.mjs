import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {execFileSync} from 'node:child_process';
import {handle, upstream, binding, signature, bridge, JS, html} from './worker.mjs';
import {NOVNC_PATH,NOVNC_SOURCE} from './novnc-source.mjs';
const origin = 'https://vps-vnc.servernet.cloud';
const secret = 'a'.repeat(64), token = 'b'.repeat(64), session = 'c'.repeat(64);
const env = {ENABLED:'1',BROKER_KEY:secret};
const grant = {origin:'https://console.whitelabelservices.us',path:'/api2/json/nodes/fixture-node/qemu/123/vncwebsocket',
  port:5901,cookie:'PVE:fixture@pve:ABC::cookie',vnc:'PVEVNC:fixture-console'};
const json = data => new Response(JSON.stringify(data),{headers:{'Content-Type':'application/json'}});
const launch = (extra = {}) => new Request(origin+'/launch',{method:'POST',headers:{Origin:origin,'Content-Type':'application/json',...extra},body:JSON.stringify({ticket:token})});

test('vendored browser module is served locally without upstream module fetches',async()=>{
  const r=await handle(new Request(origin+NOVNC_PATH),{},()=>{throw Error('unexpected upstream request');});
  assert.equal(r.status,200);assert.equal(await r.text(),NOVNC_SOURCE);
  assert.match(r.headers.get('Cache-Control'),/immutable/);
  assert.ok(JS.includes("import('"+NOVNC_PATH+"')"));
  assert.ok(NOVNC_SOURCE.includes('Mozilla')&&NOVNC_SOURCE.includes('Permission is hereby granted'));
});
test('the actual browser response parses as an ES module including license notices',()=>{
  assert.doesNotThrow(()=>execFileSync(process.execPath,['--check','--input-type=module'],{input:NOVNC_SOURCE,stdio:['pipe','pipe','pipe']}));
});

test('disabled relay and forged origins make no broker calls', async () => {
  let calls=0; const fetcher=()=>{calls++;throw Error();};
  assert.equal((await handle(launch(),{},fetcher)).status,503);
  assert.equal((await handle(launch({Origin:'https://evil.example'}),env,fetcher)).status,403);
  assert.equal(calls,0);
});
test('launch signs exact backend request and reveals only VM-console-scoped password', async () => {
  const result=await handle(launch(),env,async (url,options)=>{
    assert.equal(url,'https://console.servernet.cloud/system/vpstc-console-broker');
    assert.equal(options.redirect,'manual');
    assert.equal(options.headers['X-SN-Signature'],await signature(secret,options.headers['X-SN-Time']+'\n'+options.body));
    assert.deepEqual(JSON.parse(options.body),{action:'redeem',token});
    return json({session,password:grant.vnc,cookie:grant.cookie,service_password:'must-not-leak'});
  });
  assert.equal(result.status,200);
  assert.deepEqual(await result.json(),{binding:await binding(session),password:grant.vnc});
  const cookie=result.headers.get('Set-Cookie');
  for(const part of ['__Host-sn-vpstc=', 'Secure','HttpOnly','SameSite=Strict','Max-Age=30','Path=/'])assert.ok(cookie.includes(part));
  assert.equal(result.headers.get('Cache-Control'),'no-store, private');
});
test('a login ticket cannot substitute for a console-scoped password',async()=>{
  assert.equal((await handle(launch(),env,async()=>json({session,password:grant.cookie}))).status,410);
});
test('current Proxmox eight-character password survives JSON without appearing in a URL',async()=>{
  const password='!"#:$%&=';
  const r=await handle(launch(),env,async()=>json({session,password}));
  assert.equal(r.status,200);assert.equal((await r.json()).password,password);
  assert.ok(!upstream(grant).includes(encodeURIComponent(password)));
  for(const bad of ['short','123456789','1234\n678','1234 678',12345678]){
    assert.equal((await handle(launch(),env,async()=>json({session,password:bad}))).status,410);
  }
});
test('broker redirects and malformed JSON are rejected without exposing raw text',async()=>{
  const r=await handle(launch(),env,async()=>new Response('private-provider-body',{status:302,headers:{Location:'https://evil.example'}}));
  assert.equal(r.status,410); assert.ok(!(await r.text()).includes('private-provider-body'));
  assert.equal((await handle(launch(),env,async()=>new Response('invalid',{headers:{'Content-Type':'application/json'}}))).status,502);
});
test('oversized and malformed launch bodies do not reach the broker',async()=>{
  const fetcher=()=>{throw Error('must not be called');};
  assert.equal((await handle(launch({'Content-Length':'300'}),env,fetcher)).status,400);
  const bad=new Request(origin+'/launch',{method:'POST',headers:{Origin:origin,'Content-Type':'application/json'},body:JSON.stringify({ticket:'wrong'})});
  assert.equal((await handle(bad,env,fetcher)).status,400);
});
test('strict destination validation rejects host injection, CRLF, paths and invalid port',()=>{
  assert.ok(upstream(grant).startsWith(grant.origin+grant.path+'?port=5901&vncticket=PVEVNC%3A'));
  for(const delta of [{origin:grant.origin+'.evil.example'}, {origin:'http://console.whitelabelservices.us'},
    {origin:grant.origin+'@evil.example'},{path:'/api2/json/nodes/n/shell'},{path:'/api2/json/nodes/../qemu/123/vncwebsocket'},
    {port:22},{port:'5901'},{cookie:grant.cookie+';session=other'},{cookie:grant.cookie+'\r\nX-Evil:1'}, {vnc:'PVE:login-ticket'}]) {
    assert.equal(upstream({...grant,...delta}),null,JSON.stringify(delta));
  }
});
test('duplicate session cookies and cross-tab binding mismatch fail before claim',async()=>{
  let calls=0;const fetcher=()=>{calls++;throw Error();};
  const options={headers:{Origin:origin,Upgrade:'websocket',Cookie:'__Host-sn-vpstc='+session+'; __Host-sn-vpstc='+token}};
  assert.equal((await handle(new Request(origin+'/socket?binding='+await binding(session),options),env,fetcher)).status,403);
  options.headers.Cookie='__Host-sn-vpstc='+session;
  assert.equal((await handle(new Request(origin+'/socket?binding='+await binding(token),options),env,fetcher)).status,409);
  assert.equal(calls,0);
});
test('successful socket forwards fresh headers, no browser session or credentials',async()=>{
  const NativeResponse=globalThis.Response;
  globalThis.Response=class extends NativeResponse{constructor(body,options){if(options?.status===101)return {...options,body};super(body,options);}};
  try{
    let calls=0;const remoteSocket={};const localSocket={};
    const r=await handle(new Request(origin+'/socket?binding='+await binding(session),{headers:{Origin:origin,Upgrade:'websocket',Cookie:'__Host-sn-vpstc='+session+'; portal=private',Authorization:'Bearer do-not-forward'}}),env,
      async(url,options)=>{
        calls++;
        if(calls===1){assert.equal(JSON.parse(options.body).action,'claim');return json(grant);}
        assert.equal(url,upstream(grant));assert.equal(options.redirect,'manual');
        assert.deepEqual(options.headers,{Upgrade:'websocket','Sec-WebSocket-Protocol':'binary',Origin:grant.origin,Cookie:'PVEAuthCookie='+grant.cookie});
        return {status:101,webSocket:remoteSocket};
      },socket=>{assert.equal(socket,remoteSocket);return localSocket;});
    assert.equal(calls,2);assert.equal(r.status,101);assert.equal(r.webSocket,localSocket);
    assert.ok(!JSON.stringify(r.headers).includes('PVE:'));
  }finally{globalThis.Response=NativeResponse;}
});
test('upstream error does not return provider response to the browser',async()=>{
  let calls=0;const r=await handle(new Request(origin+'/socket?binding='+await binding(session),{headers:{Origin:origin,Upgrade:'websocket',Cookie:'__Host-sn-vpstc='+session}}),env,
    async()=>++calls===1?json(grant):new Response('secret upstream error',{status:403}));
  assert.equal(r.status,502);assert.equal(await r.text(),'Connection unavailable');
});
class Socket{
  handlers={};sent=[];closed=[];accepted=false;binaryType='blob';
  accept(){this.accepted=true;}addEventListener(name,fn){this.handlers[name]=fn;}
  send(data){this.sent.push(data);}close(code,reason){this.closed.push([code,reason]);}
  receive(data){this.handlers.message({data:this.binaryType==='blob'?new Blob([data]):data});}
}
test('relay forwards binary data and forcibly closes both sides after five minutes',()=>{
  const remote=new Socket(),server=new Socket(),client={};let deadline,delay,cleared=false;
  const result=bridge(remote,()=>({0:client,1:server}),{setTimeout(fn,ms){deadline=fn;delay=ms;return 1;},clearTimeout(){cleared=true;}});
  assert.equal(result,client);assert.equal(delay,300000);assert.ok(server.accepted&&remote.accepted);
  const bytes=new Uint8Array([1,2,3]).buffer;remote.receive(bytes);assert.equal(server.sent[0],bytes);
  server.receive(bytes);assert.equal(remote.sent[0],bytes);
  deadline();assert.equal(remote.closed.length,1);assert.equal(server.closed.length,1);assert.ok(cleared);
  server.handlers.message({data:bytes});assert.equal(remote.sent.length,1);
});
test('oversized client clipboard/frame terminates the relay',()=>{
  const remote=new Socket(),server=new Socket();let closed=false;
  bridge(remote,()=>({0:{},1:server}),{setTimeout(){return 1;},clearTimeout(){closed=true;}});
  server.handlers.message({data:new Uint8Array(65537).buffer});assert.ok(closed);assert.equal(remote.sent.length,0);
});
for(const locale of ['fa','en','tr'])test('localized secure shell '+locale,async()=>{
  const r=await handle(new Request(origin+'/'+locale+'/'));const text=await r.text();
  assert.equal(r.status,200);assert.ok(text.includes('lang="'+locale+'"'));assert.ok(text.includes('dir="'+(locale==='fa'?'rtl':'ltr')+'"'));
  assert.ok(!text.includes('whitelabelservices'));assert.ok(!text.includes('PVEAuthCookie'));
  assert.ok(r.headers.get('Content-Security-Policy').includes("frame-ancestors 'none'"));
  assert.ok(html(locale).includes('id="cad" disabled'));assert.ok(html(locale).includes('id="message" role="alert"'));
});
test('static asset redirects are not followed',async()=>{
  const r=await handle(new Request(origin+'/novnc/core/rfb.js'),{},async(url,opts)=>{
    assert.equal(url,'https://console.servernet.cloud/assets/js/novnc/core/rfb.js');assert.equal(opts.redirect,'manual');return new Response(null,{status:302});
  });assert.equal(r.status,502);
});
test('client clears fragment even when ticket is invalid and fails with translated copy',async()=>{
  const elements=new Map(['status','message','screen','cad','full'].map(id=>[id,{textContent:'',disabled:false,dataset:{}}]));
  let cleared=false;
  await vm.runInNewContext(JS,{document:{documentElement:{lang:'en'},getElementById:id=>elements.get(id)},
    location:{hash:'#invalid',pathname:'/en/'},history:{replaceState(){cleared=true;}},clearTimeout(){},setTimeout(){throw Error('unexpected timer');}});
  assert.ok(cleared);assert.match(elements.get('message').textContent,/Open a new console/);assert.equal(elements.get('cad').disabled,true);
});
