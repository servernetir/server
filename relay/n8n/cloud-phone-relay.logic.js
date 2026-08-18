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
