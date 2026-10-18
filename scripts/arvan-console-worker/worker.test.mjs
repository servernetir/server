import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createHash, createHmac} from 'node:crypto';
import {handle, signature, upstream, connectUpstream, sessionBinding} from './worker.mjs';
const base='https://vnc.servernet.cloud';
const key='a'.repeat(64), ticket='b'.repeat(64), session='c'.repeat(64);
const env={ENABLED:'1',BROKER_KEY:key};
const req=(path,init={})=>new Request(base+path,init);

test('console uses same-origin IranSans with a fixed font allowlist',async()=>{
 const css=await handle(req('/console.css'),{});
 assert.match(await css.text(),/font-family:IRANSans,Tahoma/);
 assert.match(css.headers.get('content-security-policy'),/font-src 'self'/);
 const font=await handle(req('/fonts/IRANSans-web.woff2'),{},async(url,opts)=>{
  assert.equal(url,'https://console.servernet.cloud/assets/font/woff2/IRANSans-web.woff2');
  assert.equal(opts.headers,undefined);
  return new Response('font',{status:200});
 });
 assert.equal(font.headers.get('content-type'),'font/woff2');
 assert.equal((await handle(req('/fonts/other.woff2'),{})).status,503);
});
test('disabled deployment never reaches broker or provider',async()=>{
 const r=await handle(req('/socket'),{},()=>{throw Error('unexpected network');});assert.equal(r.status,503);
});
test('foreign hosts and queries fail closed',async()=>{
 for(const url of ['https://example.com/','https://vnc.servernet.cloud/?ticket=secret',
  base+'/socket?token='+session,base+'/socket?binding=bad',base+'/socket?binding='+session+'&binding='+session,
  base+'/socket?binding='+session+'&extra=1'])assert.equal((await handle(new Request(url),env)).status,404);
});
test('launch requires same-origin and rejects malformed ticket',async()=>{
 assert.equal((await handle(req('/launch',{method:'POST',headers:{Origin:'https://evil.invalid'}}),env)).status,403);
 assert.equal((await handle(req('/launch',{method:'POST',headers:{Origin:base,'Content-Type':'application/json'},body:'{"ticket":"bad"}'}),env)).status,400);
});
test('HMAC matches PHP-compatible SHA256 encoding',async()=>{
 const data='123\n{"action":"redeem"}';assert.equal(await signature(key,data),createHmac('sha256',key).update(data).digest('hex'));
});
test('tab binding is a domain-separated digest, not the session credential',async()=>{
 const binding=await sessionBinding(session);
 assert.equal(binding,createHash('sha256').update('servernet-vnc-tab-v1\n'+session).digest('hex'));
 assert.notEqual(binding,session);
});
test('redeem uses fixed broker, private cookie, no token in response',async()=>{
 const r=await handle(req('/launch',{method:'POST',headers:{Origin:base,'Content-Type':'application/json',Cookie:'portal=private'},body:JSON.stringify({ticket})}),env,async(url,opts)=>{
  assert.equal(url,'https://console.servernet.cloud/system/arvan-console-broker');assert.equal(opts.headers.Cookie,undefined);
  assert.equal(opts.headers['X-SN-Signature'],await signature(key,opts.headers['X-SN-Time']+'\n'+opts.body));
  return Response.json({session});
 });assert.equal(r.status,200);assert.match(r.headers.get('set-cookie'),/Secure; HttpOnly; SameSite=Strict/);
 assert.equal(r.headers.get('content-type'),'application/json');
 const text=await r.text();assert.ok(!text.includes(session));assert.deepEqual(JSON.parse(text),{binding:await sessionBinding(session)});
});
test('expired claim and duplicate cookie never contact provider',async()=>{
 let count=0;const network=async()=>{count++;return new Response('',{status:410});};
 const init={headers:{Origin:base,Upgrade:'websocket',Cookie:'__Host-sn-vnc='+session}};
 const path='/socket?binding='+await sessionBinding(session);
 assert.equal((await handle(req(path,init),env,network)).status,410);assert.equal(count,1);
 init.headers.Cookie+='; __Host-sn-vnc='+ticket;
 assert.equal((await handle(req(path,init),env,network)).status,403);assert.equal(count,1);
});
test('socket requires matching HttpOnly session and tab binding before any claim',async()=>{
 const unexpected=()=>{throw Error('must not reach broker or provider');};
 const binding=await sessionBinding(session);
 assert.equal((await handle(req('/socket?binding='+binding,{headers:{Origin:base,Upgrade:'websocket'}}),env,unexpected)).status,403);
 const headers={Origin:base,Upgrade:'websocket',Cookie:'__Host-sn-vnc='+session};
 assert.equal((await handle(req('/socket',{headers}),env,unexpected)).status,403);
 assert.equal((await handle(req('/socket?binding='+await sessionBinding(ticket),{headers}),env,unexpected)).status,409);
 // Knowing the public fingerprint does not let it stand in for the cookie.
 assert.equal((await handle(req('/socket?binding='+binding,{headers:{...headers,Cookie:'__Host-sn-vnc='+binding}}),env,unexpected)).status,409);
});
test('concurrent tab cookie overwrite fails closed without consuming or controlling the other server',async()=>{
 const ticketA='1'.repeat(64),ticketB='2'.repeat(64),sessionA='3'.repeat(64),sessionB='4'.repeat(64);
 const providerA='00000000-0000-0000-0000-000000000001',providerB='00000000-0000-0000-0000-000000000002';
 const tickets=new Map([[ticketA,sessionA],[ticketB,sessionB]]);
 const sessions=new Map([[sessionA,providerA],[sessionB,providerB]]);
 const claims=[],providerCookies=[];
 const network=async(url,options)=>{
  if(url==='https://console.servernet.cloud/system/arvan-console-broker'){
   const {action,token}=JSON.parse(options.body);
   if(action==='redeem')return Response.json({session:tickets.get(token)});
   claims.push(token);const upstreamToken=sessions.get(token);sessions.delete(token);
   return upstreamToken?Response.json({region:'ir-tbz-sh1',token:upstreamToken,path:'/ir-tbz-sh1/websockify'}):new Response('',{status:410});
  }
  assert.equal(url,'https://console.arvaniaas.ir/ir-tbz-sh1/websockify');
  providerCookies.push(options.headers.Cookie);
  // Node's standard Response cannot represent101; exercising provider dispatch
  // with a synthetic rejection is enough to verify which grant was selected.
  return new Response('',{status:502});
 };
 const launch=async token=>{
  const response=await handle(req('/launch',{method:'POST',headers:{Origin:base,'Content-Type':'application/json'},body:JSON.stringify({ticket:token})}),env,network);
  assert.equal(response.status,200);
  return {binding:(await response.json()).binding,cookie:response.headers.get('set-cookie').split(';')[0]};
 };
 const a=await launch(ticketA),b=await launch(ticketB);
 const socket=(binding,cookie)=>handle(req('/socket?binding='+binding,{headers:{Origin:base,Upgrade:'websocket',Cookie:cookie}}),env,network);
 // B's later Set-Cookie replaces A's browser cookie before A opens its socket.
 assert.equal((await socket(a.binding,b.cookie)).status,409);
 assert.deepEqual(claims,[]);assert.deepEqual(providerCookies,[]);assert.equal(sessions.size,2);
 assert.equal((await socket(b.binding,b.cookie)).status,502);
 assert.deepEqual(claims,[sessionB]);assert.deepEqual(providerCookies,['token='+providerB]);assert.ok(sessions.has(sessionA));
 assert.equal((await socket(b.binding,b.cookie)).status,410);assert.equal(providerCookies.length,1);
 // An independently matching A cookie still reaches only A's original grant.
 assert.equal((await socket(a.binding,a.cookie)).status,502);
 assert.deepEqual(providerCookies,['token='+providerB,'token='+providerA]);
});
test('no arbitrary upstream, cookie injection or region escape',()=>{
 for(const data of [{region:'evil',token:'a'.repeat(36),path:'/ir-tbz-sh1/websockify'}, {region:'ir-tbz-sh1',token:'x; admin=true',path:'/ir-tbz-sh1/websockify'}, {region:'ir-tbz-sh1',token:'a'.repeat(36),path:'//evil.invalid'}])assert.equal(upstream(data),null);
});
test('regional grants stay on the fixed console host and matching regional path',()=>{
 assert.equal(upstream({region:'ir-thr-c2',token:'a'.repeat(36),path:'/ir-thr-c2/websockify'}),'https://console.arvaniaas.ir/ir-thr-c2/websockify');
 assert.equal(upstream({region:'ir-thr-c2',token:'a'.repeat(36),path:'/ir-tbz-sh1/websockify'}),null);
 assert.equal(upstream({region:'ir-../evil-x',token:'a'.repeat(36),path:'/ir-../evil-x/websockify'}),null);
});
test('branded shell has no third-party code and forbids framing',async()=>{
 const r=await handle(req('/'));assert.match(r.headers.get('Content-Security-Policy'),/frame-ancestors 'none'/);assert.match(await r.text(),/سرورنت/);
});

test('public root does not wait on broker or provider',async()=>{
 const r=await handle(req('/'),{...env,CLIENT_LEGACY:'1'},()=>{throw Error('unexpected network');});
 assert.equal(r.status,200);assert.match(await r.text(),/id="status"/);
});

test('both shells render guidance and inline styles before any VNC dependency',async()=>{
 for(const CLIENT_LEGACY of ['0','1']){
  const html=await(await handle(req('/'),{...env,CLIENT_LEGACY},()=>{throw Error('unexpected network');})).text();
  assert.match(html,/<style>/);assert.match(html,/font-display:swap/);assert.match(html,/id="message" role="alert">[^<]+/);
  assert.match(html,/<noscript>[^<]+<\/noscript>/);assert.match(html,/<script defer src="\/console.js"><\/script>/);
  assert.equal((html.match(/<script/g)||[]).length,1);assert.ok(!html.includes('<link'));
  assert.ok(!html.includes('src="/legacy/'));assert.ok(!html.includes('src="/novnc/'));
 }
});

test('upstream upgrade is aborted after ten seconds',async t=>{
 t.mock.timers.enable({apis:['setTimeout']});
 let signal;
 const pending=connectUpstream('https://console.arvaniaas.ir/ir-tbz-sh1/websockify',{},async(url,options)=>{
  signal=options.signal;
  return new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(Error('upgrade timeout'))));
 });
 assert.equal(signal.aborted,false);t.mock.timers.tick(10000);
 await assert.rejects(pending,/upgrade timeout/);assert.equal(signal.aborted,true);
});

test('successful upgrade clears its timer and does not abort an established socket',async t=>{
 t.mock.timers.enable({apis:['setTimeout']});
 let signal;const socket={};
 const result=await connectUpstream('https://console.arvaniaas.ir/ir-tbz-sh1/websockify',{},async(url,options)=>{
  signal=options.signal;return {status:101,webSocket:socket};
 });
 assert.equal(result.webSocket,socket);t.mock.timers.tick(60000);assert.equal(signal.aborted,false);
});
test('asset requests cannot forward portal cookies or follow redirects',async()=>{
 const r=await handle(req('/novnc/core/rfb.js',{headers:{Cookie:'portal=private'}}),env,async(url,opts)=>{
  assert.equal(url,'https://console.servernet.cloud/assets/js/novnc/core/rfb.js');assert.equal(opts.headers,undefined);assert.equal(opts.redirect,'manual');return new Response('',{status:302});
 });assert.equal(r.status,502);
});
test('legacy test client stays on own origin with fixed allowlisted assets',async()=>{
 const page=await handle(req('/'),{...env,CLIENT_LEGACY:'1'});
 const html=await page.text();assert.match(html,/legacy-canvas/);assert.match(html,/src="\/console.js"/);assert.ok(!html.includes('src="/legacy/'));
 const r=await handle(req('/legacy/rfb.js',{headers:{Cookie:'portal=private'}}),env,async(url,opts)=>{
  assert.equal(url,'https://console.arvaniaas.ir/ir-tbz-sh1/include/rfb.js');
  assert.equal(opts.headers,undefined);assert.equal(opts.redirect,'manual');return new Response('/* fixture */');
 });assert.equal(r.status,200);
 assert.equal((await handle(req('/legacy/other.js'),env)).status,403);
});
test('legacy disconnect is terminal and cannot overwrite the failure message',async()=>{
 const js=await (await handle(req('/console.js'),{...env,CLIENT_LEGACY:'1'})).text();
 assert.match(js,/'disconnect','disconnected'/);
 assert.match(js,/fail\(\);return;/);
 assert.match(js,/else if\(!message.textContent\)/);
});
