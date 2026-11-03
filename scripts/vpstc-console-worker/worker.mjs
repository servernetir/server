// Standalone relay, independent of the provider's proprietary WHMCS module.
const ORIGIN = 'https://vps-vnc.servernet.cloud';
const PORTAL = 'https://console.servernet.cloud';
const BROKER = PORTAL + '/system/vpstc-console-broker';
const ID = /^[a-f0-9]{64}$/;
const PASSWORD = /^(?:[\x21-\x7e]{8}|PVEVNC:[A-Za-z0-9+/=:@_.-]{1,4089})$/;
const COOKIE = '__Host-sn-vpstc';
const HEADERS = {'Cache-Control': 'no-store, private', 'Referrer-Policy': 'no-referrer',
  'X-Content-Type-Options': 'nosniff', 'X-Frame-Options': 'DENY',
  'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()'};
const reply = (body, status = 200, extra = {}) => new Response(body, {status, headers: {...HEADERS, ...extra}});
const hex = bytes => Array.from(new Uint8Array(bytes), x => x.toString(16).padStart(2, '0')).join('');
export async function signature(key, data) {
  const encoder = new TextEncoder();
  const secret = await crypto.subtle.importKey('raw', encoder.encode(key), {name: 'HMAC', hash: 'SHA-256'}, false, ['sign']);
  return hex(await crypto.subtle.sign('HMAC', secret, encoder.encode(data)));
}
export async function binding(token) {
  return hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode('sn-vpstc-console-v1\n' + token)));
}
function session(request) {
  const values = (request.headers.get('Cookie') || '').split(';').map(x => x.trim()).filter(x => x.startsWith(COOKIE + '='));
  if (values.length !== 1) return null;
  const token = values[0].slice(COOKIE.length + 1);
  return ID.test(token) ? token : null;
}
async function broker(action, token, env, fetcher) {
  const body = JSON.stringify({action, token}), ts = String(Math.floor(Date.now() / 1000));
  const response = await fetcher(BROKER, {method: 'POST', body, redirect: 'manual', signal: AbortSignal.timeout(45000),
    headers: {'Content-Type': 'application/json', 'X-SN-Time': ts, 'X-SN-Signature': await signature(env.BROKER_KEY, ts + '\n' + body)}});
  if (response.status !== 200 || !response.headers.get('Content-Type')?.includes('application/json')) return null;
  const text = await response.text();
  return text.length <= 16384 ? JSON.parse(text) : null;
}
export function upstream(grant) {
  if (!grant || !['https://console.whitelabelservices.us', 'https://console.whitelabelservices.us:8006'].includes(grant.origin)
      || !/^\/api2\/json\/nodes\/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\/qemu\/[1-9][0-9]{0,9}\/vncwebsocket$/.test(grant.path || '')
      || !Number.isInteger(grant.port) || grant.port < 5900 || grant.port > 5999
      || !/^PVE:[A-Za-z0-9+/=:@_.-]{1,4092}$/.test(grant.cookie || '')
      || !/^PVEVNC:[A-Za-z0-9+/=:@_.-]{1,4089}$/.test(grant.vnc || '')) return null;
  return grant.origin + grant.path + '?port=' + grant.port + '&vncticket=' + encodeURIComponent(grant.vnc);
}
export async function connect(destination, cookie, fetcher) {
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 10000);
  try {
    return await fetcher(destination, {redirect: 'manual', signal: controller.signal,
      headers: {Upgrade: 'websocket', 'Sec-WebSocket-Protocol': 'binary',
        Origin: new URL(destination).origin, Cookie: 'PVEAuthCookie=' + cookie}});
  } finally { clearTimeout(timer); }
}
export function bridge(upstreamSocket, makePair = () => new WebSocketPair(), timers = {setTimeout, clearTimeout}) {
  const pair = makePair(), client = pair[0], server = pair[1];
  let closed = false;
  const end = () => {
    if (closed) return;
    closed = true; timers.clearTimeout(deadline);
    for (const socket of [server, upstreamSocket]) { try { socket.close(1000, 'Console session ended'); } catch {} }
  };
  const deadline = timers.setTimeout(end, 300000);
  // Workers dated 2026-03-17+ default to Blob. Select synchronous binary
  // delivery before accepting either socket, so frame bounds keep working.
  server.binaryType = 'arraybuffer'; upstreamSocket.binaryType = 'arraybuffer';
  server.accept(); upstreamSocket.accept();
  for (const [from, to, limit] of [[server, upstreamSocket, 65536], [upstreamSocket, server, 16777216]]) {
    from.addEventListener('message', event => {
      if (closed) return;
      const bytes = typeof event.data === 'string' ? new TextEncoder().encode(event.data).length : event.data?.byteLength;
      if (!Number.isFinite(bytes) || bytes > limit) { end(); return; }
      try { to.send(event.data); } catch { end(); }
    });
    from.addEventListener('close', end); from.addEventListener('error', end);
  }
  return client;
}
const TEXT = {
  fa: {title:'کنسول امن سرور',brand:'سرورنت',back:'بازگشت به سرویس‌ها',wait:'در حال آماده‌سازی کنسول…',ready:'متصل',
    fail:'اتصال برقرار نشد. از صفحهٔ سرویس، کنسول تازه باز کنید.',full:'تمام‌صفحه',initial:'کنسول را از صفحهٔ سرویس باز کنید.'},
  en: {title:'Secure server console',brand:'ServerNet',back:'Back to services',wait:'Preparing console…',ready:'Connected',
    fail:'Connection unavailable. Open a new console from your service page.',full:'Full screen',initial:'Open the console from your service page.'},
  tr: {title:'Güvenli sunucu konsolu',brand:'ServerNet',back:'Hizmetlere dön',wait:'Konsol hazırlanıyor…',ready:'Bağlandı',
    fail:'Bağlantı kurulamadı. Hizmet sayfasından yeni konsol açın.',full:'Tam ekran',initial:'Konsolu hizmet sayfasından açın.'},
};
const CSS = `@font-face{font-family:IRANSans;src:url('/fonts/IRANSans-web.woff2') format('woff2');font-display:swap}:root{color-scheme:dark;font-family:IRANSans,Tahoma,Arial,sans-serif;background:#0b1220;color:#e2e8f0}*{box-sizing:border-box}body{margin:0}header{display:flex;align-items:center;gap:16px;min-height:80px;padding:14px 24px;background:#111d30;border-bottom:1px solid #28475d;flex-wrap:wrap}a{color:#22d3ee;text-decoration:none}.brand{font-weight:700;font-size:24px}button{font:inherit;padding:8px 14px;border-radius:9px;border:1px solid #22d3ee;background:#123747;color:#e2e8f0;cursor:pointer}button:disabled{opacity:.4;cursor:default}#status{margin-inline-start:auto;font-size:13px}#message{text-align:center;color:#fbbf24;padding:10px}#screen{height:calc(100dvh - 120px);direction:ltr}#screen:fullscreen{height:100dvh;background:#000}@media(max-width:600px){header{padding:10px;gap:10px}.brand{font-size:20px}#status{width:100%;margin:0}#screen{height:calc(100dvh - 180px)}}`;
export function html(locale) {
  const t = TEXT[locale], prefix = locale === 'fa' ? '' : '/' + locale;
  return `<!doctype html><html lang="${locale}" dir="${locale === 'fa' ? 'rtl' : 'ltr'}"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${t.title} | ServerNet</title><link rel="stylesheet" href="/console.css"><header><a class="brand" href="${PORTAL + prefix}/account/servers">${t.brand}</a><span>${t.title}</span><span id="status" role="status"></span><button id="cad" disabled>Ctrl+Alt+Del</button><button id="full">${t.full}</button><a href="${PORTAL + prefix}/account/servers">${t.back}</a></header><section id="message" role="alert">${t.initial}</section><main id="screen"></main><script defer src="/console.js"></script></html>`;
}
export const JS = `(async()=>{
const strings=${JSON.stringify(TEXT)},locale=document.documentElement.lang,t=strings[locale]||strings.en;
const status=document.getElementById('status'),message=document.getElementById('message'),screen=document.getElementById('screen'),cad=document.getElementById('cad');
let rfb,timer,failed=false;const ticket=location.hash.slice(1);
function fail(){if(failed)return;failed=true;clearTimeout(timer);status.textContent='';message.textContent=t.fail;cad.disabled=true;try{rfb?.disconnect();}catch{}}
document.getElementById('full').onclick=()=>screen.requestFullscreen().catch(()=>{});cad.onclick=()=>rfb?.sendCtrlAltDel();
try{
 history.replaceState(null,'',location.pathname);if(!/^[a-f0-9]{64}$/.test(ticket))throw Error();
 status.textContent=t.wait;message.textContent='';timer=setTimeout(fail,60000);
 const {default:RFB}=await import('/novnc/core/rfb.js');if(failed)return;
 const response=await fetch('/launch',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({ticket}),signal:AbortSignal.timeout(50000)});
 if(!response.ok)throw Error();const grant=await response.json();if(failed)return;
 if(!/^[a-f0-9]{64}$/.test(grant.binding||'')||typeof grant.password!=='string'||!${PASSWORD}.test(grant.password))throw Error();
 rfb=new RFB(screen,'wss://'+location.host+'/socket?binding='+grant.binding,{credentials:{password:grant.password},wsProtocols:['binary']});grant.password='';
 rfb.scaleViewport=true;rfb.resizeSession=false;rfb.addEventListener('connect',()=>{if(failed)return;clearTimeout(timer);status.textContent=t.ready;cad.disabled=false;});
 rfb.addEventListener('disconnect',fail);rfb.addEventListener('securityfailure',fail);rfb.addEventListener('credentialsrequired',fail);
 clearTimeout(timer);timer=setTimeout(fail,20000);
}catch{fail();}
})();`;
export async function handle(request, env = {}, fetcher = fetch, socketBridge = bridge) {
  try {
    const url = new URL(request.url);
    if (url.origin !== ORIGIN || (url.search && (url.pathname !== '/socket'
      || [...url.searchParams.keys()].length !== 1 || !ID.test(url.searchParams.get('binding') || '')))) return reply('Not found', 404);
    const locale = /^\/(fa|en|tr)\/$/.exec(url.pathname)?.[1];
    if (request.method === 'GET' && locale) return reply(html(locale), 200, {'Content-Type':'text/html; charset=utf-8'});
    if (request.method === 'GET' && url.pathname === '/') return reply(html('fa'), 200, {'Content-Type':'text/html; charset=utf-8'});
    if (request.method === 'GET' && url.pathname === '/console.js') return reply(JS, 200, {'Content-Type':'text/javascript; charset=utf-8'});
    if (request.method === 'GET' && url.pathname === '/console.css') return reply(CSS, 200, {'Content-Type':'text/css; charset=utf-8'});
    const asset = /^\/novnc\/(core|vendor)\/[A-Za-z0-9_./-]+\.js$/.test(url.pathname) && !url.pathname.includes('..') && !url.pathname.includes('//');
    if (request.method === 'GET' && (asset || url.pathname === '/fonts/IRANSans-web.woff2')) {
      const r = await fetcher(PORTAL + (asset ? '/assets/js' + url.pathname : '/assets/font/woff2/IRANSans-web.woff2'), {redirect:'manual',signal:AbortSignal.timeout(10000)});
      return r.status === 200 ? reply(r.body, 200, {'Content-Type':asset ? 'text/javascript; charset=utf-8' : 'font/woff2'}) : reply('Unavailable',502);
    }
    if (env.ENABLED !== '1' || !ID.test(env.BROKER_KEY || '')) return reply('Console unavailable',503);
    if (request.headers.get('Origin') !== ORIGIN) return reply('Forbidden',403);
    if (request.method === 'POST' && url.pathname === '/launch') {
      if (request.headers.get('Content-Type') !== 'application/json' || Number(request.headers.get('Content-Length') || 0) > 256) return reply('Invalid request',400);
      const body = await request.text(); if (body.length > 256) return reply('Invalid request',400);
      const token = JSON.parse(body).ticket; if (typeof token !== 'string' || !ID.test(token)) return reply('Invalid request',400);
      const result = await broker('redeem', token, env, fetcher);
      if (!result || !ID.test(result.session || '') || typeof result.password !== 'string' || !PASSWORD.test(result.password)) return reply('Expired',410);
      return reply(JSON.stringify({binding:await binding(result.session),password:result.password}),200,{
        'Content-Type':'application/json', 'Set-Cookie':COOKIE + '=' + result.session + '; Path=/; Max-Age=30; Secure; HttpOnly; SameSite=Strict'});
    }
    if (request.method === 'GET' && url.pathname === '/socket') {
      const token = session(request);
      if (!token || request.headers.get('Upgrade')?.toLowerCase() !== 'websocket') return reply('Forbidden',403);
      if (url.searchParams.get('binding') !== await binding(token)) return reply('Reopen console',409);
      const grant = await broker('claim',token,env,fetcher), destination = upstream(grant);
      if (!destination) return reply('Expired',410);
      const r = await connect(destination,grant.cookie,fetcher);
      if (r.status !== 101 || !r.webSocket) return reply('Connection unavailable',502);
      return new Response(null,{status:101,webSocket:socketBridge(r.webSocket),headers:{'Sec-WebSocket-Protocol':'binary','Cache-Control':'no-store'}});
    }
    return reply('Not found',404);
  } catch { return reply('Connection unavailable',502); } // Never log console payloads or provider credentials.
}
export default {fetch(request,env) { return handle(request,env); }};
