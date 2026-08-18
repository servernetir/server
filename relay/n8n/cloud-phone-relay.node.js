/*
| ⚠️⚠️ این فایل **ساخته می‌شود** — دستی ویرایشش نکن. ⚠️⚠️
|
|   node relay/n8n/build-cloud-phone-relay.js
|
| منطق در  cloud-phone-relay.logic.js
| رمزنگاری از  verify-and-map-template.js  (همان بلوک، بدونِ کپیِ دوم)
*/

const K256 = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

const rotr = (x, n) => ((x >>> n) | (x << (32 - n))) >>> 0;

function sha256(bytes) {
  const H = new Uint32Array([
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
  ]);

  const l = bytes.length;
  const buf = new Uint8Array((((l + 8) >> 6) + 1) << 6);
  buf.set(bytes);
  buf[l] = 0x80;

  const dv = new DataView(buf.buffer);
  const bits = l * 8;
  dv.setUint32(buf.length - 8, Math.floor(bits / 4294967296), false);
  dv.setUint32(buf.length - 4, bits >>> 0, false);

  const w = new Uint32Array(64);

  for (let i = 0; i < buf.length; i += 64) {
    for (let j = 0; j < 16; j++) w[j] = dv.getUint32(i + j * 4, false);

    for (let j = 16; j < 64; j++) {
      const x = w[j - 15], y = w[j - 2];
      const s0 = (rotr(x, 7) ^ rotr(x, 18) ^ (x >>> 3)) >>> 0;
      const s1 = (rotr(y, 17) ^ rotr(y, 19) ^ (y >>> 10)) >>> 0;
      w[j] = (w[j - 16] + s0 + w[j - 7] + s1) >>> 0;
    }

    let a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];

    for (let j = 0; j < 64; j++) {
      const S1 = (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)) >>> 0;
      const ch = ((e & f) ^ (~e & g)) >>> 0;
      const t1 = (h + S1 + ch + K256[j] + w[j]) >>> 0;
      const S0 = (rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)) >>> 0;
      const maj = ((a & b) ^ (a & c) ^ (b & c)) >>> 0;
      const t2 = (S0 + maj) >>> 0;

      h = g; g = f; f = e; e = (d + t1) >>> 0;
      d = c; c = b; b = a; a = (t1 + t2) >>> 0;
    }

    H[0] = (H[0] + a) >>> 0; H[1] = (H[1] + b) >>> 0; H[2] = (H[2] + c) >>> 0; H[3] = (H[3] + d) >>> 0;
    H[4] = (H[4] + e) >>> 0; H[5] = (H[5] + f) >>> 0; H[6] = (H[6] + g) >>> 0; H[7] = (H[7] + h) >>> 0;
  }

  const out = new Uint8Array(32);
  for (let i = 0; i < 8; i++) {
    out[i * 4] = (H[i] >>> 24) & 0xff;
    out[i * 4 + 1] = (H[i] >>> 16) & 0xff;
    out[i * 4 + 2] = (H[i] >>> 8) & 0xff;
    out[i * 4 + 3] = H[i] & 0xff;
  }

  return out;
}

// ⚠️ جفتِ جانشین دستی ترکیب می‌شود؛ بی‌این، هر نویسهٔ خارج از BMP امضا را
//    می‌شکند — خرابی‌ای که فقط برای بعضی پیام‌ها رخ می‌دهد.
function utf8(str) {
  const out = [];

  for (let i = 0; i < str.length; i++) {
    let c = str.charCodeAt(i);

    if (c >= 0xd800 && c <= 0xdbff && i + 1 < str.length) {
      const n = str.charCodeAt(i + 1);
      if (n >= 0xdc00 && n <= 0xdfff) { c = 0x10000 + ((c - 0xd800) << 10) + (n - 0xdc00); i++; }
    }

    if (c < 0x80) out.push(c);
    else if (c < 0x800) out.push(0xc0 | (c >> 6), 0x80 | (c & 63));
    else if (c < 0x10000) out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    else out.push(0xf0 | (c >> 18), 0x80 | ((c >> 12) & 63), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
  }

  return new Uint8Array(out);
}

function fromUtf8(bytes) {
  let s = '';

  for (let i = 0; i < bytes.length;) {
    const b = bytes[i];

    if (b < 0x80) { s += String.fromCharCode(b); i += 1; continue; }
    if (b < 0xe0) { s += String.fromCharCode(((b & 31) << 6) | (bytes[i + 1] & 63)); i += 2; continue; }
    if (b < 0xf0) {
      s += String.fromCharCode(((b & 15) << 12) | ((bytes[i + 1] & 63) << 6) | (bytes[i + 2] & 63));
      i += 3;
      continue;
    }

    const cp = ((b & 7) << 18) | ((bytes[i + 1] & 63) << 12) | ((bytes[i + 2] & 63) << 6) | (bytes[i + 3] & 63);
    const v = cp - 0x10000;
    s += String.fromCharCode(0xd800 + (v >> 10), 0xdc00 + (v & 1023));
    i += 4;
  }

  return s;
}

const HEX = '0123456789abcdef';

function hex(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i++) s += HEX[bytes[i] >> 4] + HEX[bytes[i] & 15];

  return s;
}

function b64urlDecode(s) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  const clean = String(s).replace(/[=\s]/g, '');
  const out = [];
  let acc = 0, bits = 0;

  for (let i = 0; i < clean.length; i++) {
    const v = A.indexOf(clean[i]);
    if (v < 0) throw new Error('base64url نامعتبر');

    acc = (acc << 6) | v;
    bits += 6;

    if (bits >= 8) { bits -= 8; out.push((acc >> bits) & 0xff); }
  }

  return new Uint8Array(out);
}

// هم‌ارزِ `hash_hmac('sha256', $msg, $key)` در PHP
function hmacSha256Hex(keyStr, msgStr) {
  const B = 64;
  let key = utf8(keyStr);

  // ⚠️ کلیدِ بلندتر از بلاک، اول هش می‌شود — بی‌این، کلیدهای بلند غلط می‌دهند
  if (key.length > B) key = sha256(key);

  const pad = new Uint8Array(B);
  pad.set(key);

  const ipad = new Uint8Array(B), opad = new Uint8Array(B);
  for (let i = 0; i < B; i++) { ipad[i] = pad[i] ^ 0x36; opad[i] = pad[i] ^ 0x5c; }

  const msg = utf8(msgStr);
  const inner = new Uint8Array(B + msg.length);
  inner.set(ipad); inner.set(msg, B);

  const outer = new Uint8Array(B + 32);
  outer.set(opad); outer.set(sha256(inner), B);

  return hex(sha256(outer));
}

// مقایسهٔ زمان‌ثابت — جلوی حملهٔ زمان‌سنجی روی امضا
function safeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;

  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);

  return diff === 0;
}

/* ══════════════════════ منطقِ رلهٔ تلفن ابری ══════════════════════ */

/*
| ورودی: بدنهٔ وب‌هوک — `{ envelope: "CLOUD_PHONE_V1:<b64url>.<hmac>" }`
|
| خروجی:
|   { status: 'sent' }                          تماس به تأمین‌کننده رفت
|   { status: 'ignored', reason: '…' }          پاکت رد شد
|   { status: 'failed',  reason: '…' }          پاکت درست بود ولی API نپذیرفت
|
| 🔴 لاراول **فقط** `sent` را موفق می‌شمارد (fail-closed). پس هر مسیرِ دیگری
|    باید صراحتاً `ignored` یا `failed` بدهد — نه یک ۲۰۰ی خالی.
|
| ⚠️ پیکربندی از گرهٔ «Relay Config» می‌آید، نه از این‌جا:
|      relaySecret   رازِ مشترک با لاراول (باید دقیقاً برابرِ
|                    CLOUD_PHONE_RELAY_SECRET در .env باشد)
|      phoneToken    PHONE_TOKEN — کلیدِ API تلفن ابری
|      apiBase       https://coreapi.daftareshoma.com
|      fromNumber    شمارهٔ خطِ ما، مثلاً 02171057757
|
| 🔴 `phoneToken` عمداً **این‌جا و در مخزن نیست**. پاکتی که از آلمان می‌آید هم
|    حاملش نیست. همان قاعده‌ای که برای توکنِ آی‌پی‌پنل رعایت شده.
*/

const PREFIX = 'CLOUD_PHONE_V1:';
const WINDOW_SECONDS = 180;

const reply = (status, extra = {}) => [{ json: Object.assign({ status: status }, extra) }];

const cfg = $json || {};
const body = cfg.body || {};

const relaySecret = String(cfg.relaySecret || '');
const phoneToken = String(cfg.phoneToken || '');
const apiBase = String(cfg.apiBase || 'https://coreapi.daftareshoma.com').replace(/\/+$/, '');
// از Relay Config فقط به‌عنوان پشتیبانِ `extension` می‌ماند (پاکت مقدم است)
const fromNumber = String(cfg.fromNumber || '');

/*
| ⚠️ پیکربندیِ جاافتاده باید **ببندد**، نه باز کند. رازِ خالی یعنی هر پاکتی
| امضایش با رشتهٔ خالی می‌خواند — یعنی وب‌هوک برای کلِ اینترنت باز است.
*/
if (!relaySecret) return reply('ignored', { reason: 'relay_not_configured' });
if (!phoneToken) return reply('ignored', { reason: 'token_not_configured' });

const raw = String(body.envelope || '').trim();

if (!raw) return reply('ignored', { reason: 'no_envelope' });
if (!raw.startsWith(PREFIX)) return reply('ignored', { reason: 'unsupported_envelope' });

const rest = raw.slice(PREFIX.length).trim();
const dot = rest.lastIndexOf('.');

if (dot < 1) return reply('ignored', { reason: 'malformed_envelope' });

const b64 = rest.slice(0, dot);
const sig = rest.slice(dot + 1);

// 🔴 مقایسهٔ زمان‌ثابت — جلوی حملهٔ زمان‌سنجی روی امضا
if (!safeEqual(hmacSha256Hex(relaySecret, b64), sig)) {
  return reply('ignored', { reason: 'bad_signature' });
}

let payload;
try {
  payload = JSON.parse(fromUtf8(b64urlDecode(b64)));
} catch (e) {
  return reply('ignored', { reason: 'bad_payload' });
}

if (payload.version !== 1) return reply('ignored', { reason: 'bad_version' });
if (payload.action !== 'outgoing_call') return reply('ignored', { reason: 'unknown_action' });

/*
| ضدِّ بازپخش. پاکتی که یک بار روی سیم دیده شده نباید فردا دوباره یک تماس
| بسازد. پنجره دوطرفه است چون ساعتِ دو سرور دقیقاً یکی نیست.
*/
const age = Math.floor(Date.now() / 1000) - Number(payload.issued_at || 0);
if (!Number.isFinite(age) || Math.abs(age) > WINDOW_SECONDS) {
  return reply('ignored', { reason: 'stale_envelope', age_seconds: age });
}

const toNational = String(payload.to_number || '').replace(/\D+/g, '');
const fromNational = String(payload.from_number || '').replace(/\D+/g, '');

/*
| ⚠️ هر سه از **پاکت** می‌آیند، نه از Relay Config — با یک استثنا:
| `caller_extension` اگر در پاکت نبود از Relay Config برداشته می‌شود.
|
| چرا این‌طور: خطِ ابری یکی است و عوض نمی‌شود، ولی شمارهٔ تماس‌گیرنده ممکن است
| برای هر کاربرِ پنل فرق کند. اگر هر دو در n8n سخت‌کد بودند، روزی که تیم چند
| نفره شود باید ورک‌فلو ویرایش می‌شد — و ویرایشِ ورک‌فلو نه نسخه‌بندی دارد نه
| تست.
*/
const extension = String(payload.caller_extension || cfg.extension || fromNumber || '').replace(/\D+/g, '');

if (!toNational) return reply('ignored', { reason: 'no_destination' });
if (!fromNational) return reply('ignored', { reason: 'no_from_number' });
if (!extension) return reply('ignored', { reason: 'no_extension' });

/*
| 🔴 لایهٔ دوم: طولِ شماره.
|
| لاراول از قبل اعتبارسنجی می‌کند، ولی یک بار عددِ `1` از همهٔ نگهبان‌هایش رد
| شد و `from_number: "01"` به تأمین‌کننده رفت. رله آخرین جایی است که می‌شود
| جلویش را گرفت — و ارزانش هم هست.
|
| ⚠️ شکستِ صریح، نه اصلاحِ خودکار. اگر این‌جا شماره را «درست» کنیم، خطای
| پیکربندی بالادست پنهان می‌شود و ماه‌ها با یک شمارهٔ اشتباه تماس می‌گیریم.
*/
if (toNational.length < 10) return reply('ignored', { reason: 'destination_too_short', value: toNational });
if (fromNational.length < 10) return reply('ignored', { reason: 'from_number_too_short', value: fromNational });

/*
| ⚠️ لاراول شکلِ ملیِ بدونِ صفر می‌فرستد (`9142223343`). تأمین‌کننده در
| نمونه‌های واقعی شکلِ `09142223343` را نشان می‌دهد، پس صفر را این‌جا برمی‌گردانیم
| — یک جا، تا اگر روزی قالبش عوض شد فقط همین خط عوض شود.
*/
const toNumber = '0' + toNational;
const fromNumber2 = '0' + fromNational;

if (typeof this === 'undefined' || !this.helpers || typeof this.helpers.httpRequest !== 'function') {
  /*
  | درسِ گرهٔ Iran Probe: این سندباکس نه `require` دارد نه `fetch`ِ تضمینی.
  | خطای صریح، نه سکوت — وگرنه از بیرون شبیه «تماس رفت ولی زنگ نخورد» است.
  */
  if (typeof fetch !== 'function') {
    return reply('failed', { reason: 'no_http_capability' });
  }
}

let res;
try {
  const options = {
    url: apiBase + '/api/Customize/OutgoingCall',
    method: 'POST',
    timeout: 15000,
    returnFullResponse: true,
    ignoreHttpStatusErrors: true,
    headers: {
      Authorization: 'Bearer ' + phoneToken,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    /*
    | نگاشت با رویدادِ واقعیِ `CallOutgoingEnded` (۱۸ آگوست) تأیید شد:
    |   from_number      → CallerNumber        (پایی که اول زنگ می‌خورد)
    |   caller_extension → CalleeExtension     (خطِ ابری)
    |   to_number        → TransferredToNumber (مقصد)
    */
    body: {
      from_number: fromNumber2,
      to_number: toNumber,
      caller_extension: extension,
    },
    json: true,
  };

  if (this && this.helpers && typeof this.helpers.httpRequest === 'function') {
    res = await this.helpers.httpRequest(options);
  } else {
    const r = await fetch(options.url, {
      method: 'POST',
      headers: options.headers,
      body: JSON.stringify(options.body),
    });
    res = { statusCode: r.status };
  }
} catch (e) {
  return reply('failed', { reason: 'request_failed', detail: String((e && e.message) || e).slice(0, 200) });
}

const status = Number((res && (res.statusCode || res.status)) || 0);

/*
| 🔴 فقط ۲xx موفق است.
|
| اندپوینتِ OutgoingCall هیچ بدنه‌ای برنمی‌گرداند، پس کدِ HTTP تنها سیگنالِ
| ماست. ۴۰۱ یعنی توکن منقضی شده — و آن باید به لاراول برسد نه اینکه در
| تاریخچهٔ n8n دفن شود.
*/
if (status >= 200 && status < 300) {
  return reply('sent', { request_id: payload.request_id, http_status: status });
}

return reply('failed', { reason: 'api_status_' + status, request_id: payload.request_id });
