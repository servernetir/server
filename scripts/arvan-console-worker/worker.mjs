// Dedicated console relay. No API credentials, portal cookies or arbitrary upstreams.
const ORIGIN = 'https://vnc.servernet.cloud';
const PORTAL = 'https://console.servernet.cloud';
const BROKER = PORTAL + '/system/arvan-console-broker';
const ID = /^[a-f0-9]{64}$/;
const COOKIE = '__Host-sn-vnc';
const HEADERS = {
  'Cache-Control': 'no-store, private', 'Referrer-Policy': 'no-referrer',
  'X-Content-Type-Options': 'nosniff', 'X-Frame-Options': 'DENY',
  'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
};
function reply(body, status = 200, extra = {}) {
  return new Response(body, {status, headers: {...HEADERS, ...extra}});
}
function session(request) {
  // Reject duplicate names; ignore every portal/analytics cookie.
  const matches = (request.headers.get('Cookie') || '').split(';').map(x => x.trim())
    .filter(x => x.startsWith(COOKIE + '='));
  if (matches.length !== 1) return null;
  const value = matches[0].slice(COOKIE.length + 1);
  return ID.test(value) ? value : null;
}
export async function signature(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', enc.encode(secret), {name: 'HMAC', hash: 'SHA-256'}, false, ['sign']);
  return Array.from(new Uint8Array(await crypto.subtle.sign('HMAC', key, enc.encode(message))))
    .map(x => x.toString(16).padStart(2, '0')).join('');
}
async function broker(action, token, env, fetcher) {
  const body = JSON.stringify({action, token});
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = await signature(env.BROKER_KEY, ts + '\n' + body);
  const response = await fetcher(BROKER, {method: 'POST', redirect: 'manual',
    headers: {'Content-Type': 'application/json', 'X-SN-Time': ts, 'X-SN-Signature': sig},
    body, signal: AbortSignal.timeout(10000)});
  if (response.status !== 200 || !response.headers.get('content-type')?.includes('application/json')) return null;
  const text = await response.text();
  if (text.length > 4096) return null;
  return JSON.parse(text);
}
export function upstream(data) {
  if (!data || data.region !== 'ir-tbz-sh1' || !/^[a-f0-9-]{36}$/i.test(data.token || '')) return null;
  // Path must be independently observed in the provider's WebSocket handshake.
  if (!['/ir-tbz-sh1/websockify', '/ir-tbz-sh1/vnc_lite.html/websockify'].includes(data.path)) return null;
  return 'https://console.arvaniaas.ir' + data.path;
}
const HTML = `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کنسول سرور | سرورنت</title><link rel="stylesheet" href="/console.css"><header><a class="brand" href="https://console.servernet.cloud/account/servers"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/></svg>سرورنت</a><span>کنسول امن سرور</span><span id="status" role="status">در حال بررسی اتصال…</span><button id="cad" disabled>Ctrl+Alt+Del</button><button id="full">تمام‌صفحه</button><a href="https://console.servernet.cloud/account/servers">بازگشت به سرویس‌ها</a></header><main id="screen"></main><section id="message" role="alert"></section><script type="module" src="/console.js"></script></html>`;
const CSS = `:root{color-scheme:dark;font-family:Tahoma,Arial,sans-serif;background:#0b1220;color:#e2e8f0}*{box-sizing:border-box}body{margin:0}header{min-height:72px;display:flex;align-items:center;gap:18px;padding:12px 24px;background:#111d30;border-bottom:1px solid #25415a;flex-wrap:wrap}a{color:#22d3ee;text-decoration:none}.brand{font-weight:700;font-size:23px;display:flex;align-items:center;gap:10px}.brand svg{width:35px;height:35px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round}button{font:inherit;background:#123747;border:1px solid #22d3ee;border-radius:8px;color:#e2e8f0;padding:8px 12px;cursor:pointer}button:disabled{opacity:.4;cursor:default}#status{margin-inline-start:auto;font-size:13px}#screen{height:calc(100dvh - 96px);direction:ltr}#screen:fullscreen{height:100dvh;background:#000}#message{text-align:center;padding:12px;color:#fbbf24}body.failed #screen{display:none}`;
const JS = `import RFB from '/novnc/core/rfb.js';
const status=document.getElementById('status'),screen=document.getElementById('screen'),message=document.getElementById('message');
const ticket=location.hash.slice(1);history.replaceState(null,'',location.pathname);
function fail(){status.textContent='اتصال برقرار نشد';message.textContent='مهلت اتصال پایان یافته یا سرویس در دسترس نیست. از صفحهٔ سرویس، کنسول تازه باز کنید.';document.body.classList.add('failed');document.getElementById('cad').disabled=true;}
let rfb;
document.getElementById('full').onclick=()=>screen.requestFullscreen().catch(()=>{});
document.getElementById('cad').onclick=()=>rfb?.sendCtrlAltDel();
try{
 if(!/^[a-f0-9]{64}$/.test(ticket))throw Error('expired');
 const r=await fetch('/launch',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({ticket})});
 if(!r.ok)throw Error('expired');
 rfb=new RFB(screen,'wss://'+location.host+'/socket',{wsProtocols:['binary']});rfb.scaleViewport=true;rfb.resizeSession=false;
 rfb.addEventListener('connect',()=>{status.textContent='متصل';document.getElementById('cad').disabled=false;});
 rfb.addEventListener('disconnect',fail);rfb.addEventListener('securityfailure',fail);
 // A provider VNC password is never a Windows Administrator password.
 rfb.addEventListener('credentialsrequired',()=>{rfb.disconnect();fail();});
}catch{fail();}`;
export async function handle(request, env = {}, fetcher = fetch) {
  try {
    const url = new URL(request.url);
    if (url.origin !== ORIGIN || url.search) return reply('Not found', 404);
    if (request.method === 'GET' && url.pathname === '/') return reply(env.CLIENT_LEGACY === '1' ? LEGACY_HTML : HTML, 200, {'Content-Type': 'text/html; charset=utf-8'});
    if (request.method === 'GET' && url.pathname === '/favicon.ico') return reply(null, 204);
    if (request.method === 'GET' && url.pathname === '/console.css') return reply(CSS + `@font-face{font-family:IRANSans;src:url('/fonts/IRANSans-web.woff2') format('woff2');font-weight:400;font-display:swap}@font-face{font-family:IRANSans;src:url('/fonts/IRANSans-Bold-web.woff2') format('woff2');font-weight:700;font-display:swap}:root{font-family:IRANSans,Tahoma,Arial,sans-serif}`, 200, {'Content-Type': 'text/css; charset=utf-8'});
    if (request.method === 'GET' && ['/fonts/IRANSans-web.woff2', '/fonts/IRANSans-Bold-web.woff2'].includes(url.pathname)) {
      const r = await fetcher(PORTAL + '/assets/font/woff2/' + url.pathname.split('/').pop(), {redirect: 'manual', signal: AbortSignal.timeout(10000)});
      if (r.status !== 200) return reply('Font unavailable', 502);
      return reply(r.body, 200, {'Content-Type': 'font/woff2'});
    }
    if (request.method === 'GET' && url.pathname === '/console.js') return reply(env.CLIENT_LEGACY === '1' ? LEGACY_JS : JS, 200, {'Content-Type': 'text/javascript; charset=utf-8'});
    if (request.method === 'GET' && /^\/legacy\/(util|webutil|base64|websock|des|input|display|jsunzip|rfb)\.js$/.test(url.pathname)) {
      const r = await fetcher('https://console.arvaniaas.ir/ir-tbz-sh1/include/' + url.pathname.split('/').pop(), {redirect: 'manual', signal: AbortSignal.timeout(10000)});
      if (r.status !== 200) return reply('Asset unavailable', 502);
      return reply(r.body, 200, {'Content-Type': 'text/javascript; charset=utf-8'});
    }
    if (request.method === 'GET' && /^\/novnc\/(core|vendor)\/[a-zA-Z0-9_./-]+\.js$/.test(url.pathname) && !url.pathname.includes('..') && !url.pathname.includes('//')) {
      const r = await fetcher(PORTAL + '/assets/js' + url.pathname, {redirect: 'manual', signal: AbortSignal.timeout(10000)});
      if (r.status !== 200) return reply('Asset unavailable', 502);
      return reply(r.body, 200, {'Content-Type': 'text/javascript; charset=utf-8'});
    }
    if (env.ENABLED !== '1' || !ID.test(env.BROKER_KEY || '')) return reply('Console unavailable', 503);
    if (request.headers.get('Origin') !== ORIGIN) return reply('Forbidden', 403);
    if (request.method === 'POST' && url.pathname === '/launch') {
      if (request.headers.get('Content-Type') !== 'application/json' || Number(request.headers.get('Content-Length') || 0) > 256) return reply('Invalid request', 400);
      const body = await request.text();
      if (body.length > 256) return reply('Invalid request', 400);
      const token = JSON.parse(body).ticket;
      if (typeof token !== 'string' || !ID.test(token)) return reply('Invalid request', 400);
      const result = await broker('redeem', token, env, fetcher);
      if (!result || !ID.test(result.session || '')) return reply('Expired', 410);
      return reply(null, 204, {'Set-Cookie': COOKIE + '=' + result.session + '; Path=/; Max-Age=90; Secure; HttpOnly; SameSite=Strict'});
    }
    if (request.method === 'GET' && url.pathname === '/socket') {
      const token = session(request);
      if (!token || request.headers.get('Upgrade')?.toLowerCase() !== 'websocket') return reply('Forbidden', 403);
      const grant = await broker('claim', token, env, fetcher);
      const destination = upstream(grant);
      if (!destination) return reply('Expired', 410);
      // Construct fresh headers: never forward browser cookies, auth or arbitrary URL parameters.
      const r = await fetcher(destination, {redirect: 'manual', headers: {
        Upgrade: 'websocket', Origin: 'https://console.arvaniaas.ir', Cookie: 'token=' + grant.token,
        'Sec-WebSocket-Protocol': 'binary',
      }});
      if (r.status !== 101 || !r.webSocket) return reply('Connection unavailable', 502);
      return new Response(null, {status: 101, webSocket: r.webSocket, headers: {
        'Sec-WebSocket-Protocol': 'binary', 'Cache-Control': 'no-store',
        'Set-Cookie': COOKIE + '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Strict',
      }});
    }
    return reply('Not found', 404);
  } catch {
    // Do not log URLs, cookies, tickets, upstream payloads or guest keystrokes.
    return reply('Connection unavailable', 502);
  }
}
export default {fetch(request, env) { return handle(request, env); }};

// Provider-compatible client for an isolated A/B acceptance test. The relay remains identical.
const LEGACY_HTML = HTML.replace('<main id="screen"></main>', '<main id="screen"><canvas id="legacy-canvas" tabindex="0"></canvas></main>')
  .replace('<script type="module" src="/console.js"></script>', ['util','webutil','base64','websock','des','input','display','jsunzip','rfb'].map(x=>'<script src="/legacy/'+x+'.js"></script>').join('')+'<script src="/console.js"></script>');
const LEGACY_JS = `(async()=>{
const status=document.getElementById('status'),screen=document.getElementById('screen'),message=document.getElementById('message'),cad=document.getElementById('cad');
const ticket=location.hash.slice(1);history.replaceState(null,'',location.pathname);let rfb;
function fail(){status.textContent='اتصال برقرار نشد';message.textContent='از صفحهٔ سرویس، کنسول تازه باز کنید.';cad.disabled=true;}
document.getElementById('full').onclick=()=>screen.requestFullscreen().catch(()=>{});
cad.onclick=()=>rfb?.sendCtrlAltDel();
try{
 if(!/^[a-f0-9]{64}$/.test(ticket))throw Error('expired');
 const r=await fetch('/launch',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({ticket})});
 if(!r.ok)throw Error('expired');
 WebUtil.init_logging('error');
 rfb=new RFB({target:document.getElementById('legacy-canvas'),encrypt:true,repeaterID:'',true_color:true,local_cursor:true,shared:true,view_only:false,
updateState:(r,state)=>{if(['failed','fatal','disconnect','disconnected'].includes(state)){fail();return;}if(state==='normal'){status.textContent='متصل';cad.disabled=false;message.textContent='';}else if(!message.textContent){status.textContent='در حال اتصال…';cad.disabled=true;}},
 onPasswordRequired:()=>{rfb.disconnect();fail();}});
 rfb.connect(location.hostname,443,'','socket');
}catch{fail();}
})();`;
