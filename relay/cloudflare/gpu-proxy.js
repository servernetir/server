/**
 * دروازهٔ برندشدهٔ خطِ GPU — Cloudflare Worker
 *
 * چرا: زیرساختِ GPU هیچ امکانِ دامنهٔ سفارشی ندارد (نه در مستندات، نه در
 * اسپکِ API — فیلدِ dns فقط‌خواندنی است و همیشه *.salad.cloud). نشانیِ خام
 * نامِ زیرساخت را به مشتری لو می‌دهد — نقضِ قاعدهٔ سفیدبرچسبیِ پروژه.
 *
 * نگاشتِ قطعی و بی‌حالت (بدونِ هیچ دیتابیسی):
 *
 *   g-{label}.servernet.cloud  ──▶  {label}.salad.cloud
 *
 * همان نگاشتی که CloudInstance::accessHost() در اپ می‌سازد؛ اگر یکی عوض شد،
 * دیگری هم باید عوض شود.
 *
 * ── دروازه‌بانی (شهریور ۱۴۰۵ — حکمِ شورای مدیران، مسدودکنندهٔ انتشار) ────
 *
 * برنامه‌های آمادهٔ این خط خودشان احراز ندارند؛ هر کسی نشانیِ g-… را بداند
 * می‌تواند GPUِ مشتری را مصرف کند و ساعت‌هایش را بسوزاند. پس:
 *
 *   token = HMAC-SHA256(GATE_SECRET, label)         ← هگزِ ۶۴رقمی
 *
 * همان فرمول در CloudInstance::accessToken() است (رازش در تنظیماتِ پنل:
 * «رازِ دروازهٔ برندشده»). سه راهِ ارائه، به ترتیبِ بررسی:
 *
 *   1) هدرِ X-SN-Token           ← فرمان/کد (curl، SDK)
 *   2) کوکیِ sn_token            ← مرورگر، بعد از اولین ورود
 *   3) کوئریِ ?sn_token=…        ← اولین ورودِ مرورگری؛ Worker آن را از URL
 *      برمی‌دارد، کوکی می‌کند و 302 می‌دهد — توکن در نوارِ آدرس نمی‌مانَد و
 *      درخواست‌های بعدیِ صفحه (asset/XHR) از کوکی رد می‌شوند.
 *
 * ⚠️ GATE_SECRET **تعریف‌نشده = دروازه باز** — عمدی، برای استقرارِ تدریجی:
 * اول اپ دیپلوی می‌شود (توکن در پنل می‌نشیند)، بعد راز این‌جا ست می‌شود.
 * برداشتنِ متغیر از Worker همان «کلیدِ اضطراریِ خاموشی» است.
 *
 * ── نصبِ انجام‌شده (۵ شهریور ۱۴۰۵ — از داشبورد؛ برای بازسازی) ────────────
 * ۱) Workers & Pages → Worker به نامِ gpu-proxy با همین کد.
 * ۲) DNS زونِ servernet.cloud → رکوردِ A با نامِ `*` → 192.0.2.1، پروکسی
 *    روشن. (IPِ رزروِ مستندسازی؛ Worker پیش از رسیدن به آن پاسخ می‌دهد.
 *    رکوردهای صریحِ موجود همیشه بر wildcard مقدم‌اند.)
 * ۳) ⚠️ Cloudflare الگویِ `g-*.host` را نمی‌پذیرد (wildcard فقط ابتدای
 *    hostname). پس مسیرها در زون → Workers Routes این‌طورند:
 *      *.servernet.cloud/*     → gpu-proxy
 *      cdn.servernet.cloud/*   → None   ← استثنای هر رکوردِ پروکسی‌شده
 *      ns3.servernet.cloud/*   → None
 *    🔴 قاعده: هر رکوردِ **پروکسی‌شدهٔ** تازه‌ای که ساختی، یک مسیرِ None
 *    هم بگیرد، وگرنه ترافیکش از این Worker رد می‌شود و ۴۰۴ می‌گیرد.
 *    (رکوردهای DNS-only اصلاً به Cloudflare/Worker نمی‌رسند.)
 * ۴) پنل مدیریت → تنظیمات → زیرساخت → «دامنهٔ برندشدهٔ دروازه» =
 *    servernet.cloud (انجام شد). پنل و ایمیلِ تحویل نشانیِ برندشده می‌دهند.
 * ۵) Worker → Settings → Variables → GATE_SECRET = همان رازی که در پنل
 *    (تنظیمات → زیرساخت → «رازِ دروازهٔ برندشده») ذخیره شده.
 *
 * SSE و WebSocket از Worker رد می‌شوند؛ TLS مالِ Cloudflare است (پوششِ
 * Universal SSL برای *.servernet.cloud — تک‌سطحی، برای همین g-{label} است
 * نه {label}.g).
 */

const enc = new TextEncoder();

async function hmacHex(secret, msg) {
  const key = await crypto.subtle.importKey(
    'raw', enc.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(msg));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/* مقایسهٔ زمان-ثابت — نشتِ زمانی روی توکنِ ۶۴رقمی بعید است ولی مجانی است */
function safeEqual(a, b) {
  if (typeof a !== 'string' || a.length !== b.length) { return false; }
  let d = 0;
  for (let i = 0; i < a.length; i++) { d |= a.charCodeAt(i) ^ b.charCodeAt(i); }
  return d === 0;
}

function readCookie(request, name) {
  const raw = request.headers.get('Cookie') || '';
  const m = raw.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
  return m ? m[1] : null;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const m = url.hostname.match(/^g-([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.servernet\.cloud$/);

    if (!m) {
      return new Response('Not found', { status: 404 });
    }

    const secret = env && env.GATE_SECRET;

    if (secret) {
      const expected = await hmacHex(secret, m[1]);

      const fromQuery = url.searchParams.get('sn_token');
      if (fromQuery !== null) {
        // ورودِ مرورگری: توکن از URL برداشته، کوکی و 302 — توکن در نوارِ
        // آدرس و تاریخچه نمی‌مانَد و assetهای بعدی از کوکی رد می‌شوند.
        if (!safeEqual(fromQuery, expected)) {
          return unauthorized();
        }
        url.searchParams.delete('sn_token');
        return new Response(null, {
          status: 302,
          headers: {
            Location: url.pathname + (url.search || ''),
            'Set-Cookie': 'sn_token=' + expected
              + '; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=604800',
          },
        });
      }

      const given = request.headers.get('X-SN-Token')
        || bearerOf(request)
        || readCookie(request, 'sn_token');

      if (!given || !safeEqual(given, expected)) {
        return unauthorized();
      }
    }

    url.hostname = m[1] + '.salad.cloud';
    url.port = '';

    return fetch(new Request(url, request));
  },
};

function bearerOf(request) {
  const a = request.headers.get('Authorization') || '';
  return a.startsWith('Bearer ') ? a.slice(7) : null;
}

function unauthorized() {
  /* پیامِ خنثی و برندشده — نه نامی از زیرساخت، نه سرنخی از فرمولِ توکن */
  return new Response(
    JSON.stringify({ error: 'unauthorized', message: 'Valid access token required. Find yours in your ServerNet panel.' }),
    { status: 401, headers: { 'Content-Type': 'application/json' } }
  );
}
