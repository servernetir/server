import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createHmac} from 'node:crypto';
import {handle, signature, upstream, connectUpstream} from './worker.mjs';
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
 for(const url of ['https://example.com/','https://vnc.servernet.cloud/?ticket=secret'])assert.equal((await handle(new Request(url),env)).status,404);
});
test('launch requires same-origin and rejects malformed ticket',async()=>{
 assert.equal((await handle(req('/launch',{method:'POST',headers:{Origin:'https://evil.invalid'}}),env)).status,403);
 assert.equal((await handle(req('/launch',{method:'POST',headers:{Origin:base,'Content-Type':'application/json'},body:'{"ticket":"bad"}'}),env)).status,400);
});
test('HMAC matches PHP-compatible SHA256 encoding',async()=>{
 const data='123\n{"action":"redeem"}';assert.equal(await signature(key,data),createHmac('sha256',key).update(data).digest('hex'));
});
test('redeem uses fixed broker, private cookie, no token in response',async()=>{
 const r=await handle(req('/launch',{method:'POST',headers:{Origin:base,'Content-Type':'application/json',Cookie:'portal=private'},body:JSON.stringify({ticket})}),env,async(url,opts)=>{
  assert.equal(url,'https://console.servernet.cloud/system/arvan-console-broker');assert.equal(opts.headers.Cookie,undefined);
  assert.equal(opts.headers['X-SN-Signature'],await signature(key,opts.headers['X-SN-Time']+'\n'+opts.body));
  return Response.json({session});
 });assert.equal(r.status,204);assert.match(r.headers.get('set-cookie'),/Secure; HttpOnly; SameSite=Strict/);assert.equal(await r.text(),'');
});
test('expired claim and duplicate cookie never contact provider',async()=>{
 let count=0;const network=async()=>{count++;return new Response('',{status:410});};
 const init={headers:{Origin:base,Upgrade:'websocket',Cookie:'__Host-sn-vnc='+session}};
 assert.equal((await handle(req('/socket',init),env,network)).status,410);assert.equal(count,1);
 init.headers.Cookie+='; __Host-sn-vnc='+ticket;
 assert.equal((await handle(req('/socket',init),env,network)).status,403);assert.equal(count,1);
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
 const html=await page.text();assert.match(html,/legacy-canvas/);assert.match(html,/src="\/legacy\/rfb.js"/);
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
