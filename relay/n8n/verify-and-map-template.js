// ═══ رجیستریِ الگو ═══
// نامِ منطقی (که پروژه می‌فرستد) → کدِ الگو + نگاشتِ متغیر.
// 🔴 «vars» فقط همان چیزهایی است که خودِ الگو دارد. پروژه گاهی بیشتر
//    می‌فرستد (مثلاً link و days)؛ متغیرِ اضافه را آی‌پی‌پنل رد می‌کند،
//    پس این‌جا صریح انتخاب می‌شود نه اینکه همه پاس داده شود.
const TEMPLATES = {
  // ⚠️ سمتِ چپ = متغیرِ الگو (%otp%) · سمتِ راست = کلیدی که پروژه می‌فرستد.
  //    پروژه کد را با کلیدِ `code` می‌فرستد (BaleRelaySender::sendOtp)،
  //    نه `otp`. با نگاشتِ otp←otp هر ورودی با missing_param رد می‌شد.
  otp:               { code: 'u507b9k77p8oim0', vars: { otp: 'code' } },
  welcome:           { code: 'sjuoigsehhebc66', vars: { name: 'name' } },
  invoice:           { code: 'xhfy1tg4cprsqty', vars: { number: 'number', amount: 'amount' } },
  payment_due:       { code: 'airtetaxgkti7nq', vars: { number: 'number', amount: 'amount' } },
  service_ordered:   { code: 'uo9i0fem4xybqt1', vars: { service: 'service', amount: 'amount' } },
  service_failed:    { code: 'uv8yoqyezmz2xgb', vars: { service: 'service' } },
  renewed:           { code: '0a9s8cltd8pqocc', vars: { service: 'service', until: 'until' } },
  data_deletion_due: { code: 'lcwup0i05gg1kq1', vars: { service: 'service', days: 'days' } },
  terminated:        { code: 'a48fenebvc0v9y5', vars: { service: 'service' } },
  domain_registered: { code: 'oe6xkxqy4yyn0h5', vars: { domain: 'domain', until: 'until' } },
  domain_renewed:    { code: 'irtrvjqntrowtzv', vars: { domain: 'domain', until: 'until' } },
  ticket_new:        { code: 'wi5hiwyyuihy743', vars: { number: 'number' } },
  ticket_closed:     { code: '3a3rycdozbznuw2', vars: { number: 'number' } },
  ticket_survey:     { code: '345f2ndyxf9qie6', vars: { number: 'number' } },

  // ── الگوهای دورِ دوم (مرداد ۱۴۰۵) ──
  paid:              { code: 'y4jdzi44pg5zp10', vars: { amount: 'amount' } },
  service_ready:     { code: 'b6ekjmk93urf2ak', vars: { service: 'service', ip: 'ip' } },
  expiring:          { code: '34nogu90rsad4qm', vars: { service: 'service', days: 'days', amount: 'amount' } },
  suspended:         { code: 'y25vbkw1o712s1k', vars: { service: 'service' } },
  reactivated:       { code: 'bx11vfb6wwpwh28', vars: { service: 'service' } },
  ticket_reply:      { code: '23exe71zaexyq1j', vars: { number: 'number' } },
  domain_expiring:   { code: 'wui3lk90zy2oh2f', vars: { domain: 'domain', days: 'days' } },
  domain_expired:    { code: 'bamv8b3gmsw4n0t', vars: { domain: 'domain' } },
  bank_rejected:     { code: '0mm4f5lbcblw532', vars: { reason: 'reason' } },
};

/* ══════════════════════════════════════════════════════════════════════
 * رمزنگاریِ بدونِ وابستگی
 *
 * 🔴 چرا دستی نوشته شده: task-runnerِ این n8n نه `require('crypto')` را
 *    اجازه می‌دهد («Module 'crypto' is disallowed») و نه گلوبالِ `crypto`
 *    دارد («crypto is not defined»). نسخهٔ قبلی همان‌جا می‌شکست، پس **هیچ
 *    پیامی هرگز از فیلتر رد نمی‌شد** و هیچ پیامکی نمی‌رفت.
 *
 * ⚠️ هیچ چیزِ محیطی استفاده نمی‌شود — نه Buffer، نه TextEncoder، نه atob.
 *    سندباکسی که crypto را برداشته، فردا ممکن است اینها را هم بردارد.
 *
 * ✔ این پیاده‌سازی با ۳۴ بردارِ مرجعِ تولیدشده از `hash_hmac` در PHP تطبیق
 *   داده شد: مرزهای بلاکِ ۵۵/۵۶/۶۳/۶۴/۶۵ بایت، کلیدِ بلندتر از ۶۴ بایت،
 *   متنِ فارسی، ایموجی (جفتِ جانشین)، و رمزگشاییِ پاکتِ واقعی.
 * ══════════════════════════════════════════════════════════════════════ */

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

/* ══════════════════════════ منطقِ رله ══════════════════════════ */

const input = $json ?? {};
const update = input.body ?? input;
const message = update.message ?? null;

const config = {
  baleReceiverBotToken: String(input.baleReceiverBotToken ?? '').trim(),
  allowedRelayChatId: String(input.allowedRelayChatId ?? '').trim(),
  allowedSenderBotId: String(input.allowedSenderBotId ?? '').trim(),
  relaySecret: String(input.relaySecret ?? ''),
  ipPanelApiKey: String(input.ipPanelApiKey ?? '').trim(),
  ipPanelSenderNumber: String(input.ipPanelSenderNumber ?? '').trim(),
  ipPanelEndpoint: String(input.ipPanelEndpoint ?? '').trim(),
  maxMessageAgeSeconds: Number(input.maxMessageAgeSeconds ?? 180),
};

const out = (valid, extra = {}) => [{ json: {
  valid, ...extra,
  update_id: update.update_id ?? null,
  bale_chat_id: message?.chat?.id ?? null,
  bale_message_id: message?.message_id ?? null,
} }];

/*
| 🔴 `config` عمداً در خروجی **نیست**.
|
| قبلاً بود، و چون n8n خروجیِ **هر گره** را در تاریخچهٔ اجرا ذخیره می‌کند،
| کلیدِ آی‌پی‌پنل و رازِ رله و توکنِ رباتِ گیرنده در هر گره تکرار و ذخیره
| می‌شدند. یعنی هر کسی که به تاریخچهٔ اجرا دسترسی داشته باشد — یا هر
| خروجی‌گرفتنی از آن — همهٔ رازها را می‌بیند.
|
| ⚠️ این کاملاً بسته نشد: خروجیِ خودِ گرهٔ `Relay Config` هنوز آن‌ها را دارد.
| رفعِ درست، بردنِ کلیدِ آی‌پی‌پنل به **Credential** در n8n است (رمزنگاری‌شده
| ذخیره می‌شود و در دادهٔ اجرا سانسور می‌شود). تا آن‌وقت، این تغییر سطحِ
| افشا را از «همهٔ گره‌ها» به «یک گره» می‌آورد.
*/

/* ═══ دو حاملِ پذیرفته‌شده ═══
 *
 * ۱) **مستقیم** (`{ envelope: "SMS_RELAY_V1:…" }`) — مسیرِ فعال. پروژه خودش
 *    به این وب‌هوک POST می‌کند.
 * ۲) **بله** (`{ message: { text: "SMS_RELAY_V1:…" } }`) — مسیرِ قدیمی.
 *
 * 🔴 چرا مسیرِ بله کنار گذاشته شد: بله (مثلِ تلگرام که کپی‌اش است) پیامِ یک
 *    ربات را به رباتِ دیگر تحویل نمی‌دهد. وب‌هوکِ رباتِ گیرنده درست ست بود،
 *    `pending_update_count` صفر بود، رباتِ فرستنده پیام را در گروه می‌نوشت —
 *    و n8n هیچ اجرایی نمی‌ساخت. آن زنجیره **هرگز** کامل نمی‌شد.
 *
 * ⚠️ مسیرِ بله عمداً پشتیبانی می‌شود تا اگر روزی سرورِ آلمان به این وب‌هوک
 *    نرسید، برگشت به آن فقط یک خطِ `.env` باشد.
 *
 * ⚠️ در مسیرِ مستقیم بررسیِ «گروه» و «فرستنده» بی‌معناست و انجام نمی‌شود.
 *    دروازه همان چیزی است که در هر دو مسیر دروازهٔ واقعی بود: **امضا**. راز
 *    داخلِ پاکت نیست، پس دیدنِ یک پاکت به کسی اجازهٔ ساختِ پاکتِ تازه نمی‌دهد،
 *    و پنجرهٔ زمانیِ پایین‌تر جلوی بازپخشِ همان پاکت را می‌گیرد.
 */
const PREFIX = 'SMS_RELAY_V1:';
let raw;

if (typeof update.envelope === 'string') {
  raw = update.envelope;
} else if (message && typeof message.text === 'string') {
  if (String(message.chat?.id ?? '') !== config.allowedRelayChatId) return out(false, { reason: 'chat_not_allowed' });
  if (String(message.from?.id ?? '') !== config.allowedSenderBotId) return out(false, { reason: 'sender_not_allowed' });

  raw = message.text;
} else {
  return out(false, { reason: 'no_text_message' });
}

if (!raw.startsWith(PREFIX)) return out(false, { reason: 'unsupported_message' });

// ═══ امضا ═══
// 🔴 HMAC روی **رشتهٔ Base64** زده می‌شود، نه روی JSON خام — چون هر تفاوتِ
//    ریزِ کدگذاری بینِ PHP و JS امضا را می‌شکند.
const rest = raw.slice(PREFIX.length).trim();
const dot = rest.lastIndexOf('.');
if (dot < 1) return out(false, { reason: 'malformed_envelope' });

const b64 = rest.slice(0, dot);
const sig = rest.slice(dot + 1);

if (!safeEqual(sig, hmacSha256Hex(config.relaySecret, b64))) return out(false, { reason: 'bad_signature' });

let p;
try {
  p = JSON.parse(fromUtf8(b64urlDecode(b64)));
} catch (e) { return out(false, { reason: 'invalid_payload_encoding' }); }

const now = Math.floor(Date.now() / 1000);
const ts = Number(p.issued_at ?? 0);
if (!Number.isFinite(ts) || ts <= 0 || Math.abs(now - ts) > config.maxMessageAgeSeconds) return out(false, { reason: 'expired_or_invalid_timestamp' });

const tpl = TEMPLATES[String(p.template ?? '').trim()];
if (!tpl) return out(false, { reason: 'unknown_template', template: p.template });
if (!tpl.code || tpl.code.startsWith('REPLACE_')) return out(false, { reason: 'pattern_code_not_configured', template: p.template });

const mobile = String(p.mobile ?? '').trim();
if (!/^\+989\d{9}$/.test(mobile)) return out(false, { reason: 'invalid_mobile' });

// ═══ انتخابِ متغیر ═══
// فقط کلیدهایی که خودِ الگو دارد. `namefamily` اگر نیامد پیش‌فرض می‌گیرد،
// چون الگوی OTP دارَدش ولی پروژه فقط `code` می‌فرستد.
const src = p.params ?? {};
const params = {};
for (const [patternVar, payloadKey] of Object.entries(tpl.vars)) {
  let v = src[payloadKey];
  if ((v === undefined || v === null || String(v).trim() === '') && patternVar === 'namefamily') v = 'کاربر';
  if (v === undefined || v === null || String(v).trim() === '') return out(false, { reason: 'missing_param', template: p.template, missing: payloadKey });
  params[patternVar] = String(v);
}

return out(true, {
  template: p.template,
  request_id: p.request_id ?? null,
  mobile,
  ipPanelBody: {
    sending_type: 'pattern',
    from_number: config.ipPanelSenderNumber,
    code: tpl.code,
    recipients: [mobile],
    params,
  },
});
