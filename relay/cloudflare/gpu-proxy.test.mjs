/**
 * تستِ مرزِ کوکیِ دروازهٔ GPU — با خودِ Worker، نه با کپیِ منطقش.
 *
 * اجرا:  node --test relay/cloudflare/gpu-proxy.test.mjs
 *
 * ⚠️ ادعاها روی **هدری است که واقعاً به کانتینرِ مشتری می‌رسد** (fetchِ
 *    شبیه‌سازی‌شده آن را ضبط می‌کند)، نه روی اینکه تابعی صدا زده شد.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

const SECRET = 'test-gate-secret';
const LABEL = 'amber-owl-0123456789abcdef';
const HOST = `g-${LABEL}.servernet.cloud`;
const RELAY_KEY = 'test-relay-key';
/* همان دو bindingی که Worker زنده دارد — بی GPU_RELAY_SECRET، Worker عمداً 503 می‌دهد */
const ENV = { GATE_SECRET: SECRET, GPU_RELAY_SECRET: RELAY_KEY };

const { default: worker } = await import(process.env.WORKER_PATH || './gpu-proxy.js');

async function tokenFor(label) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', enc.encode(SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(label));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** Worker را با یک بالادستِ ساختگی اجرا می‌کند و درخواستِ رسیده به کانتینر را برمی‌گرداند */
async function run({ cookie, upstream, path = '/api/tags', headers: extraHeaders = {}, method = 'GET' } = {}) {
  const token = await tokenFor(LABEL);
  let seen = null;
  const realFetch = globalThis.fetch;
  globalThis.fetch = async (req) => { seen = req; return upstream ? upstream() : new Response('ok'); };
  try {
    const headers = { 'X-SN-Token': token, ...extraHeaders };
    if (cookie) headers.Cookie = cookie;
    const resp = await worker.fetch(new Request(`https://${HOST}${path}`, { headers, method }), ENV);
    return { resp, seen, token };
  } finally {
    globalThis.fetch = realFetch;
  }
}

test('🔴 panel session and remember-me cookies never reach the tenant container', async () => {
  const { seen } = await run({
    cookie: 'snet_session=ADMIN_SESSION; remember_web_59ba36=ADMIN_REMEMBER; '
      + 'remember_customer_3dc7=CUST_REMEMBER; __Host-servernet_session=HOST_SESSION; '
      + '__Host-remember_customer_test=HOST_REMEMBER; XSRF-TOKEN=CSRF; _xsrf=jupyter; username-g-x=jup-login',
  });
  const sent = seen.headers.get('Cookie') || '';
  for (const secret of ['ADMIN_SESSION', 'ADMIN_REMEMBER', 'CUST_REMEMBER', 'HOST_SESSION', 'HOST_REMEMBER', 'CSRF']) {
    assert.ok(!sent.includes(secret), `leaked to the container: ${secret} — got: ${sent}`);
  }
  // ⚠️ نیمهٔ دیگر: کوکی‌های خودِ برنامه باید بمانند، وگرنه ورودِ Jupyter می‌شکند
  assert.ok(sent.includes('_xsrf=jupyter'), 'Jupyter _xsrf was stripped');
  assert.ok(sent.includes('username-g-x=jup-login'), 'Jupyter login cookie was stripped');
});

test('the gate cookie itself is not forwarded, yet still authenticates', async () => {
  const token = await tokenFor(LABEL);
  let seen = null;
  const realFetch = globalThis.fetch;
  globalThis.fetch = async (req) => { seen = req; return new Response('ok'); };
  try {
    const resp = await worker.fetch(
      new Request(`https://${HOST}/`, { headers: { Cookie: `sn_token=${token}; _xsrf=j` } }),
      ENV,
    );
    assert.equal(resp.status, 200, 'cookie-only auth must still pass the gate');
    assert.ok(!(seen.headers.get('Cookie') || '').includes(token), 'sn_token forwarded upstream');
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a request carrying only panel cookies reaches the container with no Cookie header at all', async () => {
  const { seen } = await run({ cookie: 'snet_session=S; XSRF-TOKEN=X' });
  assert.equal(seen.headers.get('Cookie'), null);
});

test('🔴 the tenant app cannot plant cookies on the panel domain', async () => {
  const { resp } = await run({
    upstream: () => {
      const h = new Headers();
      h.append('Set-Cookie', 'snet_session=ATTACKER; Domain=.servernet.cloud; Path=/');
      h.append('Set-Cookie', 'evil=1; Domain=servernet.cloud; Path=/');
      h.append('Set-Cookie', 'tossed=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT; Domain=.servernet.cloud');
      h.append('Set-Cookie', 'remember_web_x=ATTACKER; Path=/');           // panel name, even host-only
      h.append('Set-Cookie', '_xsrf=keep; Path=/');                        // host-only app cookie
      h.append('Set-Cookie', `own=keep; Domain=${HOST}; Path=/`);          // its own host is fine
      return new Response('ok', { headers: h });
    },
  });
  const set = resp.headers.getSetCookie();
  const joined = set.join(' | ');
  for (const bad of ['ATTACKER', 'evil=1', 'tossed=1']) {
    assert.ok(!joined.includes(bad), `cookie escaped the tenant host: ${bad} — got: ${joined}`);
  }
  assert.ok(set.includes('_xsrf=keep; Path=/'), 'host-only app cookie was dropped');
  assert.ok(set.some((c) => c.startsWith('own=keep')), 'cookie scoped to its own host was dropped');
});

test('responses with no Set-Cookie are passed through untouched', async () => {
  const original = new Response('body', { status: 200, headers: { 'X-App': '1' } });
  const { resp } = await run({ upstream: () => original });
  assert.equal(resp, original, 'an unaffected response must not be re-wrapped');
});

test('a WebSocket handshake (101) is never re-wrapped', async () => {
  const handshake = { status: 101, headers: new Headers({ 'Set-Cookie': 'x=1; Domain=.servernet.cloud' }), webSocket: {} };
  const { resp } = await run({ upstream: () => handshake });
  assert.equal(resp, handshake, 're-wrapping a 101 loses its webSocket');
});

/* ── مسیرِ relay (۳۰ شهریور ۱۴۰۵) — تا مهر ۱۴۰۵ فقط در داشبوردِ Cloudflare بود ── */

test('traffic goes through the relay, with the real target only in the signed header', async () => {
  const { seen } = await run();
  const u = new URL(seen.url);
  assert.equal(u.hostname, 'gpu-relay.servernet.cloud', 'must never call the infrastructure host directly (its WAF blocks Iran)');
  assert.equal(u.pathname, '/api/tags');
  assert.equal(seen.headers.get('X-ServerNet-Upstream'), `${LABEL}.salad.cloud`);
  assert.equal(seen.headers.get('X-ServerNet-Relay-Key'), RELAY_KEY);
});

test('the gate token and client identity headers are not forwarded upstream', async () => {
  const token = await tokenFor(LABEL);
  let seen = null;
  const realFetch = globalThis.fetch;
  globalThis.fetch = async (req) => { seen = req; return new Response('ok'); };
  try {
    await worker.fetch(new Request(`https://${HOST}/`, {
      headers: { 'X-SN-Token': token, 'CF-Connecting-IP': '5.160.0.1', 'CF-IPCountry': 'IR', 'X-Forwarded-For': '5.160.0.1' },
    }), ENV);
    for (const h of ['X-SN-Token', 'CF-Connecting-IP', 'CF-IPCountry', 'X-Forwarded-For']) {
      assert.equal(seen.headers.get(h), null, `${h} forwarded upstream`);
    }
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a missing relay secret fails closed (503) and never calls out', async () => {
  const token = await tokenFor(LABEL);
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error('must not call out without the relay secret'); };
  const realError = console.error;
  console.error = () => {};
  try {
    const resp = await worker.fetch(new Request(`https://${HOST}/`, { headers: { 'X-SN-Token': token } }), { GATE_SECRET: SECRET });
    assert.equal(resp.status, 503);
    assert.ok(!(await resp.text()).toLowerCase().includes('salad'), 'the error names the infrastructure');
  } finally {
    globalThis.fetch = realFetch;
    console.error = realError;
  }
});

test('an absolute redirect to the infrastructure host comes back branded', async () => {
  const { resp } = await run({
    upstream: () => new Response(null, { status: 302, headers: { Location: `https://${LABEL}.salad.cloud/lab?x=1` } }),
  });
  assert.equal(resp.status, 302);
  assert.equal(resp.headers.get('Location'), `https://${HOST}/lab?x=1`);
});

test('the gate still refuses a request with no token', async () => {
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error('must not reach the container'); };
  try {
    const resp = await worker.fetch(new Request(`https://${HOST}/`, { headers: { Cookie: 'snet_session=S' } }), ENV);
    assert.equal(resp.status, 401);
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a missing gate secret returns unavailable without forwarding a request', async () => {
  const original = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => { calls++; return new Response('unexpected'); };
  try {
    const response = await worker.fetch(new Request('https://'+HOST+'/'), { GPU_RELAY_SECRET: RELAY_KEY });
    assert.equal(response.status, 503);
    assert.equal(calls, 0);
  } finally {
    globalThis.fetch = original;
  }
});
const BROWSER_DOCUMENT = {
  'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  'Sec-Fetch-Mode': 'navigate',
  'Sec-Fetch-Dest': 'document',
};

function providerPage(status, headers = {}) {
  return new Response(`<!doctype html><html><body><b>Salad</b>
    <h1>Error ${status} Detected</h1><h2>What this means</h2>
    <p>The request lacks the required credentials to access the resource.</p>
    <h2>What you can do</h2></body></html>`, {
    status, headers: { 'Content-Type': 'text/html; charset=utf-8', ...headers },
  });
}

test('a browser document without authorization receives a branded 401 and never reaches the upstream', async () => {
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error('must not reach the upstream'); };
  try {
    const resp = await worker.fetch(new Request(`https://${HOST}/`, {
      headers: { ...BROWSER_DOCUMENT, 'Accept-Language': 'fa-IR,fa;q=0.9,en;q=0.8' },
    }), ENV);
    assert.equal(resp.status, 401);
    assert.match(resp.headers.get('Content-Type'), /^text\/html/);
    assert.match(resp.headers.get('Cache-Control'), /no-store/);
    const page = await resp.text();
    assert.match(page, /<html lang="fa" dir="rtl">/);
    assert.match(page, /احراز هویت/);
    assert.match(page, /https:\/\/console\.servernet\.cloud\/account\/servers/);
    assert.ok(!page.includes(HOST), 'customer address was exposed in the error document');
    assert.ok(!page.includes('<script'), 'error page must not run scripts');
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('API authorization failures remain a branded JSON 401', async () => {
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error('must not reach the upstream'); };
  try {
    const resp = await worker.fetch(new Request(`https://${HOST}/api/tags`, {
      headers: { Accept: 'application/json' },
    }), ENV);
    assert.equal(resp.status, 401);
    assert.equal(resp.headers.get('Content-Type'), 'application/json');
    assert.equal((await resp.json()).error, 'unauthorized');
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a recognized provider 403 browser page becomes generic ServerNet unavailable HTML, retaining 403', async () => {
  const { resp, token } = await run({
    path: '/', headers: BROWSER_DOCUMENT, upstream: () => providerPage(403),
  });
  assert.equal(resp.status, 403);
  const page = await resp.text();
  assert.match(page, /GPU access is currently unavailable/);
  assert.match(page, /may be stopped, starting, or experiencing a connectivity issue/);
  assert.ok(!/salad/i.test(page));
  assert.ok(!page.includes(HOST));
  assert.ok(!page.includes(token));
  assert.ok(!page.includes(SECRET));
  assert.ok(!page.includes(RELAY_KEY));
});

test('a recognized provider 503 browser page preserves 503 and Retry-After, with Turkish copy', async () => {
  const { resp } = await run({
    path: '/', headers: { ...BROWSER_DOCUMENT, 'Accept-Language': 'tr-TR' },
    upstream: () => providerPage(503, { 'Retry-After': '120' }),
  });
  assert.equal(resp.status, 503);
  assert.equal(resp.headers.get('Retry-After'), '120');
  assert.match(await resp.text(), /GPU erişimi şu anda kullanılamıyor/);
});

test('provider pages for API or non-document requests remain untouched', async () => {
  for (const request of [
    { path: '/api/tags', headers: { Accept: 'application/json' } },
    { path: '/docs', headers: { Accept: 'text/html' } },
    { path: '/', headers: BROWSER_DOCUMENT, method: 'POST' },
  ]) {
    const original = providerPage(403);
    const { resp } = await run({ ...request, upstream: () => original });
    assert.equal(resp, original);
    assert.match(await resp.text(), /Salad/);
  }
});

test('app-generated browser 403/503 pages are preserved unless the known provider template is present', async () => {
  for (const status of [403, 503]) {
    const original = new Response('<html><h1>Application permission denied</h1><p>Contact your project owner.</p></html>', {
      status, headers: { 'Content-Type': 'text/html', 'X-App-Error': 'kept' },
    });
    const { resp } = await run({ path: '/lab', headers: BROWSER_DOCUMENT, upstream: () => original });
    assert.equal(resp, original);
    assert.equal(resp.headers.get('X-App-Error'), 'kept');
    assert.match(await resp.text(), /Application permission denied/);
  }
});

test('slow streaming HTML errors are returned unchanged after the bounded inspection', async () => {
  const stream = new ReadableStream({
    start(controller) {
      controller.enqueue(new TextEncoder().encode('<html><h1>Application error</h1>'));
      // A deliberately stalled app body, not a real request.
    },
  });
  const original = new Response(stream, { status: 503, headers: { 'Content-Type': 'text/html' } });
  const started = Date.now();
  const { resp } = await run({ path: '/lab', headers: BROWSER_DOCUMENT, upstream: () => original });
  assert.equal(resp, original);
  assert.ok(Date.now() - started < 2000, 'inspection must not wait for the entire app stream');
  const reader = resp.body.getReader();
  const first = await reader.read();
  assert.match(new TextDecoder().decode(first.value), /Application error/);
  void reader.cancel().catch(() => {});
});

test('a normal HTML browser reply is returned unchanged', async () => {
  const original = new Response('<html><h1>Healthy app</h1></html>', {
    status: 200, headers: { 'Content-Type': 'text/html' },
  });
  const { resp } = await run({ path: '/', headers: BROWSER_DOCUMENT, upstream: () => original });
  assert.equal(resp, original);
  assert.match(await resp.text(), /Healthy app/);
});
