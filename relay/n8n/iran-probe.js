/*
| ═══ گرهٔ «Iran Probe» — نقطهٔ سنجشِ داخلِ ایران ═══
|
| ورک‌فلوی جدا از رلهٔ پیامک، روی همان n8n ایرانی:
|
|   Laravel (WebProbe::probeFetch) ──POST──▶ Webhook → Set(config) → این گره → Respond
|
| ورودی ($json پس از Set):
|   probeToken   رمزِ مشترک — در گرهٔ Set ست می‌شود، نه این‌جا
|   headers      هدرهای وب‌هوک (x-probe-token)
|   body         { target: "https://example.com/" }
|
| خروجی: { ok, status, total_ms, error }
|   ok=false هرگز HTTP خطا نمی‌شود؛ لاراول ok را می‌خواند نه کد را
|   (همان قاعدهٔ رله: «۲۰۰ ولی نرفت» — پس سمتِ لاراول فقط به ok تکیه می‌کند).
|
| 🔴 سندباکسِ این n8n به require راه نمی‌دهد (درسِ رلهٔ پیامک)، پس HTTP از
|    this.helpers.httpRequest می‌آید و اگر نبود از fetchِ سراسری. اگر هیچ‌کدام
|    نبود خطای صریح «no_http_capability» برمی‌گردد — نه سکوت.
|
| ⚠️ محافظ SSRF دو لایه است: لایهٔ اصلی SafeUrl در لاراول است (پیش از ارسال)،
|    این‌جا فقط scheme و IPِ خصوصیِ literal رد می‌شود چون در سندباکس DNS نداریم.
|    این وب‌هوک بدونِ توکن هیچ کاری نمی‌کند.
*/

const headers = $json.headers || {};
const got = String(headers['x-probe-token'] || headers['X-Probe-Token'] || '');
const expected = String($json.probeToken || '');
const target = String(($json.body && $json.body.target) || '');

const reply = (obj) => [{ json: obj }];

if (!expected || !got || got !== expected) {
  return reply({ ok: false, error: 'bad_token' });
}

/*
| 🔴 پارس URL بدون `new URL`: سندباکس این n8n علاوه بر crypto (درس رله)،
|    گلوبال URL را هم ندارد — نسخه‌ی اول همین‌جا throw می‌کرد و catch آن را
|    «bad_target» می‌خواند؛ یعنی هر هدفِ سالمی رد می‌شد، بی‌هیچ خطایی در لاگ.
|    به هیچ گلوبال محیطی جز رجکس و رشته تکیه نکن.
*/
const sm = target.match(/^([a-z][a-z0-9+.-]*):\/\//i);
if (sm && sm[1].toLowerCase() !== 'http' && sm[1].toLowerCase() !== 'https') {
  return reply({ ok: false, error: 'bad_scheme' });
}
const hm = target.match(/^https?:\/\/([^\/:?#]+)(?::\d+)?(?:[\/?#]|$)/i);
if (!hm) {
  return reply({ ok: false, error: 'bad_target' });
}
const host = hm[1].toLowerCase();

// IPv4 خصوصی/رزروِ literal — DNS در سندباکس نداریم؛ لایهٔ کامل سمتِ لاراول است
const m = host.match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
if (m) {
  const a = +m[1], b = +m[2];
  const priv = a === 10 || a === 127 || a === 0
    || (a === 192 && b === 168) || (a === 172 && b >= 16 && b <= 31)
    || (a === 169 && b === 254);
  if (priv) {
    return reply({ ok: false, error: 'private_target' });
  }
}
if (host === 'localhost' || host.endsWith('.local')) {
  return reply({ ok: false, error: 'private_target' });
}

// HTTP: اول helper رسمیِ n8n، بعد fetchِ سراسری (Node 18+)
const t0 = Date.now();
let status = 0;

try {
  if (this && this.helpers && typeof this.helpers.httpRequest === 'function') {
    const res = await this.helpers.httpRequest({
      url: target,
      method: 'GET',
      timeout: 12000,
      returnFullResponse: true,
      ignoreHttpStatusErrors: true,
    });
    status = Number(res && (res.statusCode || res.status)) || 0;
  } else if (typeof fetch === 'function') {
    const res = await fetch(target, { redirect: 'manual', signal: AbortSignal.timeout(12000) });
    status = Number(res.status) || 0;
  } else {
    return reply({ ok: false, error: 'no_http_capability' });
  }
} catch (e) {
  return reply({ ok: false, error: 'fetch_failed', total_ms: Date.now() - t0 });
}

return reply({ ok: status > 0, status: status, total_ms: Date.now() - t0 });
