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

const { default: worker } = await import(process.env.WORKER_PATH || './gpu-proxy.js');

async function tokenFor(label) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', enc.encode(SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(label));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** Worker را با یک بالادستِ ساختگی اجرا می‌کند و درخواستِ رسیده به کانتینر را برمی‌گرداند */
async function run({ cookie, upstream } = {}) {
  const token = await tokenFor(LABEL);
  let seen = null;
  const realFetch = globalThis.fetch;
  globalThis.fetch = async (req) => { seen = req; return upstream ? upstream() : new Response('ok'); };
  try {
    const headers = { 'X-SN-Token': token };
    if (cookie) headers.Cookie = cookie;
    const resp = await worker.fetch(new Request(`https://${HOST}/api/tags`, { headers }), { GATE_SECRET: SECRET });
    return { resp, seen, token };
  } finally {
    globalThis.fetch = realFetch;
  }
}

test('🔴 panel session and remember-me cookies never reach the tenant container', async () => {
  const { seen } = await run({
    cookie: 'snet_session=ADMIN_SESSION; remember_web_59ba36=ADMIN_REMEMBER; '
      + 'remember_customer_3dc7=CUST_REMEMBER; XSRF-TOKEN=CSRF; _xsrf=jupyter; username-g-x=jup-login',
  });
  const sent = seen.headers.get('Cookie') || '';
  for (const secret of ['ADMIN_SESSION', 'ADMIN_REMEMBER', 'CUST_REMEMBER', 'CSRF']) {
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
      { GATE_SECRET: SECRET },
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

test('the gate still refuses a request with no token', async () => {
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error('must not reach the container'); };
  try {
    const resp = await worker.fetch(new Request(`https://${HOST}/`, { headers: { Cookie: 'snet_session=S' } }), { GATE_SECRET: SECRET });
    assert.equal(resp.status, 401);
  } finally {
    globalThis.fetch = realFetch;
  }
});
