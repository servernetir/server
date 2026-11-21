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
 *      admin.servernet.cloud/* → None  ← اصلاحِ مسیرِ Proxmox، ۳۰ اکتبر ۲۰۲۶
 *    🔴 قاعده: هر رکوردِ **پروکسی‌شدهٔ** تازه‌ای که ساختی، یک مسیرِ None
 *    هم بگیرد، وگرنه ترافیکش از این Worker رد می‌شود و ۴۰۴ می‌گیرد.
 *    (رکوردهای DNS-only اصلاً به Cloudflare/Worker نمی‌رسند.)
 * ۴) پنل مدیریت → تنظیمات → زیرساخت → «دامنهٔ برندشدهٔ دروازه» =
 *    servernet.cloud (انجام شد). پنل و ایمیلِ تحویل نشانیِ برندشده می‌دهند.
 * ۵) Worker → Settings → Variables → GATE_SECRET = همان رازی که در پنل
 *    (تنظیمات → زیرساخت → «رازِ دروازهٔ برندشده») ذخیره شده.
 *
 * 🔴 **این فایل منبعِ کدِ زنده است.** Worker از داشبورد دیپلوی می‌شود نه از
 *    گیت، و یک‌بار (بخشِ relay پایین) ده روز فقط در داشبورد بود و نسخهٔ مخزن
 *    عقب مانده بود — اصلاحِ بعدی نزدیک بود روی کدِ کهنه نوشته شود. هر ویرایش
 *    در داشبورد ⇒ همین‌جا هم، و پیش از دیپلوی:
 *      node --test relay/cloudflare/gpu-proxy.test.mjs
 *
 * SSE و WebSocket از Worker رد می‌شوند؛ TLS مالِ Cloudflare است (پوششِ
 * Universal SSL برای *.servernet.cloud — تک‌سطحی، برای همین g-{label} است
 * نه {label}.g).
 *
 * ── خروجی Salad از relay آلمان (۳۰ شهریور ۱۴۰۵) ───────────────────────
 * WAF زون salad.cloud درخواستِ مستقیمِ Worker را با سیگنال داخلی
 * cf.worker.upstream_zone تشخیص و برای کاربران ایران 403 می‌کرد. این سیگنال
 * header معمولی نیست و از کد Worker حذف نمی‌شود. بنابراین Worker بعد از
 * احراز، درخواست را به hostname ثابتِ DNS-only زیر می‌فرستد؛ relay مقصد
 * واقعی *.salad.cloud را از X-ServerNet-Upstream می‌گیرد و با egress آلمان
 * برقرار می‌کند. برای مهاجرت relay فقط A record همین hostname تغییر می‌کند:
 *
 *   gpu-relay.servernet.cloud  (DNS-only)
 *
 * binding محرمانهٔ GPU_RELAY_SECRET باید در Worker و فایل root-only relay
 * یکسان باشد. مقدار آن هرگز در سورس یا مستندات ثبت نمی‌شود.
 */

const enc = new TextEncoder();
const GPU_RELAY_URL = 'https://gpu-relay.servernet.cloud';

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

/* ════════════════════════════════════════════════════════════════════════
 * 🔴 مرزِ کوکی — کوکی‌های پنل هرگز به کانتینرِ مشتری نمی‌رسند (مهر ۱۴۰۵)
 * ════════════════════════════════════════════════════════════════════════
 *
 * نشستِ پنل روی **کلِ** `.servernet.cloud` است
 * (AppServiceProvider::shareSessionAcrossSubdomains)، پس مرورگر آن را به
 * g-{label}.servernet.cloud هم می‌فرستد. ورودِ مشتری همیشه remember=true است
 * (LoginController، RegisterController) و CookieServiceProvider دامنهٔ کوکیِ
 * «مرا به خاطر بسپار» را هم از همان تنظیم می‌گیرد — یعنی کوکیِ ماندگار هم.
 *
 * و این Worker تا امروز همهٔ هدرها را دست‌نخورده به بالادست می‌داد. کانتینرِ
 * پشتِ g-… مالِ **مشتری** است؛ مشتریِ Jupyter در آن کد اجرا می‌کند. زنجیره:
 *
 *   مشتری لینکِ g-…/?sn_token=… را در تیکت می‌گذارد
 *     → مدیرِ واردشده کلیک می‌کند
 *     → snet_session و remember_*ِ مدیر به کانتینرِ مشتری می‌رسد
 *     → کنترلِ کاملِ پنلِ مدیریت.
 *
 * دو طرف لازم است:
 *   ۱ درخواست: کوکی‌های پنل از هدرِ Cookie برداشته می‌شوند.
 *   ۲ پاسخ: هر Set-Cookie که از g-{label} بیرون بزند دور ریخته می‌شود؛
 *     وگرنه برنامهٔ مشتری می‌تواند روی .servernet.cloud کوکی بکارد
 *     (session fixation: قربانی بی‌صدا واردِ حسابِ مهاجم می‌شود).
 *
 * ⚠️ کلِ هدرِ Cookie را **نباید** حذف کرد: ورودِ خودِ Jupyter به کوکی‌های
 *    خودش (`_xsrf`، `username-…`) نیاز دارد. فهرستِ سیاه است نه سفید.
 * ⚠️ قاعده: هر کوکیِ تازه‌ای که پنل با دامنهٔ .servernet.cloud بسازد، باید
 *    این‌جا اضافه شود. تست: relay/cloudflare/gpu-proxy.test.mjs
 */
const PANEL_COOKIE_NAMES = ['snet_session', 'XSRF-TOKEN', 'sn_token'];
const PANEL_COOKIE_PREFIXES = ['remember_'];

function isPanelCookie(name) {
  const n = String(name || '').trim();
  return PANEL_COOKIE_NAMES.indexOf(n) !== -1
    || PANEL_COOKIE_PREFIXES.some((p) => n.indexOf(p) === 0);
}

/** کوکی‌های پنل را از هدرهای رو به بالادست حذف می‌کند (درجا) */
function stripPanelCookies(headers) {
  const raw = headers.get('Cookie');
  if (!raw) { return; }

  const kept = raw.split(';')
    .map((part) => part.trim())
    .filter((part) => part !== '' && !isPanelCookie(part.split('=')[0]));

  if (kept.length) {
    headers.set('Cookie', kept.join('; '));
  } else {
    headers.delete('Cookie');
  }
}

/* ⚠️ Set-Cookieِ چندتایی را با get() نخوان: آن‌ها را با «,» می‌چسبانَد و
 *    تاریخِ Expires خودش «,» دارد — تفکیکِ درست ناممکن می‌شود. */
function setCookiesOf(headers) {
  if (typeof headers.getSetCookie === 'function') { return headers.getSetCookie(); }
  if (typeof headers.getAll === 'function') { return headers.getAll('Set-Cookie'); }
  const one = headers.get('Set-Cookie');
  return one ? [one] : [];
}

/** آیا این Set-Cookie از میزبانِ خودِ مشتری بیرون می‌زند؟ */
function escapesTenant(setCookie, brandedHost) {
  const parts = String(setCookie).split(';');
  if (isPanelCookie(parts[0].split('=')[0])) { return true; }

  const domainAttr = parts.slice(1)
    .map((a) => a.trim())
    .find((a) => a.toLowerCase().indexOf('domain=') === 0);

  if (!domainAttr) { return false; }              // host-only ⇒ فقط همین g-{label}

  const d = domainAttr.slice(7).trim().replace(/^\./, '').toLowerCase();
  return d !== String(brandedHost).toLowerCase();
}

/** Set-Cookieهای بیرون‌زننده را از پاسخ حذف می‌کند */
function confineResponseCookies(resp, brandedHost) {
  // ⚠️ دست‌دادنِ WebSocket (101) هرگز بازپیچیده نشود — webSocketِ پاسخ گم می‌شود.
  if (resp.status === 101) { return resp; }

  const all = setCookiesOf(resp.headers);
  if (!all.some((c) => escapesTenant(c, brandedHost))) { return resp; }

  const out = new Response(resp.body, resp);
  out.headers.delete('Set-Cookie');
  all.filter((c) => !escapesTenant(c, brandedHost))
    .forEach((c) => out.headers.append('Set-Cookie', c));
  return out;
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
          return unauthorized(request);
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
        return unauthorized(request);
      }
    }

    const brandedHost = url.hostname;
    const originHost = m[1] + '.salad.cloud';
    if (!env || !env.GPU_RELAY_SECRET) {
      console.error(JSON.stringify({
        message: 'GPU relay secret is missing',
        host: brandedHost,
        path: url.pathname,
      }));
      return isBrowserDocument(request)
        ? gatewayPage(request, 503, 'unavailable')
        : new Response('GPU gateway is temporarily unavailable', { status: 503 });
    }

    url.hostname = new URL(GPU_RELAY_URL).hostname;
    url.port = '';

    /*
     * 🔴 Origin/Referer را به میزبانِ زیرساخت بازنویسی کن.
     *
     * چرا حیاتی است: برنامه‌های وبِ پشتِ دروازه (Jupyter، ComfyUI، …) برای
     * ضدِ CSRF هدرِ Origin را با میزبانِ خودشان می‌سنجند. مرورگرِ مشتری
     * Origin: https://g-….servernet.cloud می‌فرستد، ولی بک‌اند خودش را
     * araza-….salad.cloud می‌شناسد ⇒ ناسازگاری ⇒ Jupyter **۴۰۴** می‌دهد و
     * ورود ناممکن می‌شود.
     *
     * 🔴 این دقیقاً همان «تحویل شد ولی نمی‌توانم استفاده کنم»یِ مشتری‌هاست:
     * curl/SDK (که Origin نمی‌فرستد) کار می‌کند، مرورگر ۴۰۴/کلادفلر می‌دهد —
     * برای همین از این ماشین هرگز دیده نمی‌شد. کوکی و Referer بی‌تقصیرند؛
     * فقط Origin. Referer را هم برای هم‌خوانی بازنویسی می‌کنیم.
     */
    // درخواستِ تازه با میزبانِ زیرساخت، بعد هدرها را رویش بازنویسی می‌کنیم.
    // ⚠️ الگوی دو-Request عمداً: هدرهای یک Request که از Request دیگر ساخته
    //    شده تغییرناپذیرند؛ فقط از راهِ init دوم می‌شود بازنویسی‌شان کرد. و
    //    body با همین الگو بی‌دستکاری منتقل می‌شود (نه spread که getterها را
    //    از دست می‌دهد).
    const upstream = new Request(url, request);
    const fwd = new Headers(upstream.headers);

    /*
     * هدرهای دروازه و هویتِ کلاینت فقط برای hop ورودی‌اند. فرستادنشان به
     * دامنهٔ Salad دو ایراد دارد: رازِ دسترسیِ ServerNet را بی‌دلیل افشا
     * می‌کند و WAF آن دامنه درخواست را با کشور/IP مشتری (از جمله ایران)
     * ارزیابی می‌کند، در حالی که اتصال واقعی از Worker برقرار شده است.
     * Cloudflare برای subrequest میان دو zone هدرهای لازمِ خودش را دوباره
     * می‌سازد؛ پس این مقادیرِ ورودی نباید به upstream کپی شوند.
     */
    [
      'X-SN-Token',
      'CF-Connecting-IP',
      'CF-IPCountry',
      'X-Real-IP',
      'X-Forwarded-For',
      'True-Client-IP',
      'Forwarded',
    ].forEach((name) => fwd.delete(name));

    // فقط relay دارای این راز می‌تواند مقصد Salad را بپذیرد. مقصد نیز از
    // label معتبرِ hostname ورودی ساخته شده و هرگز از ورودی آزاد کاربر نیست.
    fwd.set('X-ServerNet-Upstream', originHost);
    fwd.set('X-ServerNet-Relay-Key', env.GPU_RELAY_SECRET);

    const rewriteHostIn = (name) => {
      const v = fwd.get(name);
      if (v && v.indexOf(brandedHost) !== -1) {
        fwd.set(name, v.split(brandedHost).join(originHost));
      }
    };
    rewriteHostIn('Origin');
    rewriteHostIn('Referer');

    // 🔴 مرزِ کوکی — بالای فایل را بخوان. پس از بررسیِ دروازه است، چون
    //    readCookie هنوز sn_token را از request (نه از fwd) می‌خوانَد.
    stripPanelCookies(fwd);

    /*
     * 🔴 redirect: 'manual' — بدونِ آن، fetchِ Worker ریدایرکت‌ها را **خودش**
     * دنبال می‌کند و دو خرابیِ هم‌زمان می‌سازد:
     *   ۱) Set-Cookieِ پاسخِ 302 (مثلاً کوکیِ ورودِ موفقِ Jupyter) دور ریخته
     *      می‌شود ⇒ مشتری هرگز واردِ برنامه نمی‌مانَد و به 404/لاگینِ دوباره
     *      می‌خورد — عیناً همان تیکت‌های «تحویل شد ولی نمی‌توانم استفاده کنم».
     *   ۲) پاسخِ مقصدِ ریدایرکت زیرِ URLِ اولیه رندر می‌شود و نوارِ آدرس دروغ
     *      می‌گوید.
     * پس 3xx عیناً به مرورگرِ مشتری پاس می‌شود.
     */
    const resp = confineResponseCookies(
      await fetch(new Request(upstream, { headers: fwd }), { redirect: 'manual' }),
      brandedHost
    );

    // Only a recognized infrastructure error page on a full HTML navigation
    // is replaced. A 403/503 alone cannot prove that the customer's GPU is off.
    // Application errors, API replies and upstream status codes stay intact.
    if (isBrowserDocument(request) && await isInfrastructureErrorPage(resp)) {
      const retryAfter = resp.headers.get('Retry-After');
      if (resp.body) { void resp.body.cancel().catch(() => {}); }
      return gatewayPage(request, resp.status, 'unavailable', retryAfter);
    }

    /*
     * اگر برنامه Locationِ مطلق با میزبانِ زیرساخت بدهد، به دامنهٔ برندشده
     * برگردانده می‌شود — هم سفیدبرچسبی حفظ می‌شود، هم مشتریِ داخلِ مرورگر به
     * میزبانی پرت نمی‌شود که برایش کوکیِ دروازه ندارد.
     */
    const loc = resp.headers.get('Location');
    if (loc && loc.indexOf('.salad.cloud') !== -1) {
      const out = new Response(resp.body, resp);
      out.headers.set('Location', loc.replace(originHost, brandedHost));
      return out;
    }

    return resp;
  },
};

function bearerOf(request) {
  const a = request.headers.get('Authorization') || '';
  return a.startsWith('Bearer ') ? a.slice(7) : null;
}

function unauthorized(request) {
  if (isBrowserDocument(request)) {
    return gatewayPage(request, 401, 'authorization');
  }
  /* پیامِ خنثی و برندشده — نه نامی از زیرساخت، نه سرنخی از فرمولِ توکن */
  return new Response(
    JSON.stringify({ error: 'unauthorized', message: 'Valid access token required. Find yours in your ServerNet panel.' }),
    { status: 401, headers: { 'Content-Type': 'application/json' } }
  );
}

/** AJAX, SDK, API and form requests must retain the original error contract. */
function isBrowserDocument(request) {
  return request.method === 'GET'
    && request.headers.get('Sec-Fetch-Mode') === 'navigate'
    && request.headers.get('Sec-Fetch-Dest') === 'document'
    && /(?:^|,)\s*text\/html(?:\s*[;,]|$)/i.test(request.headers.get('Accept') || '');
}

/**
 * Inspect a bounded prefix of a cloned, HTML gateway error only. An ordinary
 * app 403/503 is not enough. Require the recognized infrastructure branding
 * and error-template headings before taking over the browser error rendering.
 */
async function isInfrastructureErrorPage(response) {
  const limit = 65536;
  if (![403, 503].includes(response.status)
      || !/^text\/html(?:\s*;|$)/i.test(response.headers.get('Content-Type') || '')
      || Number(response.headers.get('Content-Length') || 0) > limit
      || !response.body) {
    return false;
  }
  try {
    const copy = response.clone();
    const reader = copy.body.getReader();
    const decoder = new TextDecoder();
    let bytes = 0;
    let html = '';
    let timedOut = false;
    // An ordinary tenant HTML error may stream indefinitely. Inspect it for
    // at most half a second; then return the unaltered original response.
    const timer = setTimeout(() => {
      timedOut = true;
      void reader.cancel().catch(() => {});
    }, 500);
    try {
      while (bytes < limit) {
        const { value, done } = await reader.read();
        if (done) { break; }
        const chunk = value.subarray(0, limit - bytes);
        bytes += chunk.byteLength;
        html += decoder.decode(chunk, { stream: true });
      }
      html += decoder.decode();
    } finally {
      clearTimeout(timer);
      // Do not await cancellation of a tee branch while its original response
      // may still be consumed by the browser.
      void reader.cancel().catch(() => {});
      reader.releaseLock();
    }
    if (timedOut) { return false; }
    const words = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').toLowerCase();
    return /\bsalad\b/i.test(html)
      && words.includes('error ' + response.status + ' detected')
      && words.includes('what this means')
      && words.includes('what you can do')
      && (response.status !== 403 || words.includes('required credentials'));
  } catch {
    // Unknown or unreadable response: preserve it rather than masking it.
    return false;
  }
}

const GATEWAY_COPY = {
  fa: {
    dir: 'rtl', panel: 'مدیریت سرویس‌های من', service: 'خدمات GPU سرورنت',
    authorization: {
      title: 'برای ورود به سرویس، ابتدا احراز هویت کنید',
      body: 'این نشانی محافظت شده است. از صفحه مدیریت سرویس GPU خود در پنل سرورنت، دکمهٔ ورود به برنامه یا مستندات را انتخاب کنید.',
      note: 'این پیام به معنای خاموش بودن دستگاه شما نیست.',
    },
    unavailable: {
      title: 'دسترسی به سرویس موقتاً فراهم نیست',
      body: 'سرویس ممکن است خاموش، در حال راه‌اندازی یا با مشکل اتصال روبه‌رو باشد. وضعیت واقعی دستگاه را در پنل سرورنت بررسی کنید.',
      note: 'اگر در پنل روشن است و خطا ادامه دارد، با پشتیبانی سرورنت تماس بگیرید.',
    },
  },
  en: {
    dir: 'ltr', panel: 'Manage my services', service: 'ServerNet GPU Services',
    authorization: {
      title: 'Sign in to access your GPU service',
      body: 'This address is protected. Use the application or documentation button on your GPU service page in the ServerNet panel.',
      note: 'This message does not mean your GPU is stopped.',
    },
    unavailable: {
      title: 'GPU access is currently unavailable',
      body: 'Your GPU may be stopped, starting, or experiencing a connectivity issue. Check the actual service status in the ServerNet panel.',
      note: 'If the panel shows your GPU is running and this error persists, contact ServerNet support.',
    },
  },
  tr: {
    dir: 'ltr', panel: 'Hizmetlerimi yönet', service: 'ServerNet GPU Hizmetleri',
    authorization: {
      title: 'GPU hizmetinize erişmek için giriş yapın',
      body: 'Bu adres korumalıdır. ServerNet panelindeki GPU hizmet sayfanızdan uygulama veya dokümantasyon düğmesini kullanın.',
      note: 'Bu mesaj GPU sunucunuzun kapalı olduğu anlamına gelmez.',
    },
    unavailable: {
      title: 'GPU erişimi şu anda kullanılamıyor',
      body: 'GPU kapalı, başlatılıyor veya bir bağlantı sorunu yaşıyor olabilir. Hizmetin gerçek durumunu ServerNet panelinden kontrol edin.',
      note: 'Panel GPU hizmetinizin çalıştığını gösteriyorsa ve hata devam ediyorsa ServerNet destek ekibiyle iletişime geçin.',
    },
  },
};

/** Static branded page: no request URL, host, token, provider, JS or external assets. */
function gatewayPage(request, status, kind, retryAfter = null) {
  const languages = (request.headers.get('Accept-Language') || '').split(',');
  const lang = languages.map(v => v.split(';')[0].trim().toLowerCase().split('-')[0])
    .find(v => Object.hasOwn(GATEWAY_COPY, v)) || 'en';
  const copy = GATEWAY_COPY[lang];
  const message = copy[kind];
  const headers = new Headers({
    'Content-Type': 'text/html; charset=utf-8',
    'Cache-Control': 'no-store, private',
    'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
    'X-Frame-Options': 'DENY',
    'Vary': 'Accept, Accept-Language, Sec-Fetch-Mode, Sec-Fetch-Dest',
  });
  if (retryAfter) { headers.set('Retry-After', retryAfter); }
  return new Response(`<!doctype html>
<html lang="${lang}" dir="${copy.dir}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>${message.title} | ServerNet</title>
<style>
:root{color-scheme:dark;--muted:#a7b6cc;--accent:#63e6dc;--line:#26364e}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100svh;display:grid;align-items:center;justify-items:center;padding:24px;background:radial-gradient(ellipse at 18% 0%,#15354a 0%,#101a2c 36%,#0b1220 75%);color:#f4f7fc;font:16px/1.8 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{width:100%;max-width:570px;border:1px solid var(--line);background:#111f32;border-radius:24px;padding:clamp(24px,6vw,48px);box-shadow:0 22px 64px #050a1670}
.brand{display:flex;gap:12px;align-items:center;color:var(--muted);font-weight:650;font-size:14px}
.mark{border:1px solid #2b7686;background:#173d50;border-radius:10px;width:36px;height:36px;display:grid;place-items:center;color:var(--accent);font-weight:800;letter-spacing:-1px;flex:none}
.status{display:inline-flex;margin-top:32px;padding:4px 10px;color:#f0cf8a;background:#413522;border:1px solid #645130;border-radius:8px;font-size:13px;font-weight:700;direction:ltr}
h1{font-size:clamp(24px,5vw,34px);line-height:1.5;margin:16px 0 12px}
p{color:var(--muted);margin:0 0 22px}
a{display:inline-flex;align-items:center;justify-content:center;width:100%;min-height:52px;padding:12px 18px;border-radius:12px;background:var(--accent);color:#092534;font-weight:750;text-decoration:none;margin:2px 0 22px}
a:focus-visible{outline:3px solid white;outline-offset:4px}
.note{font-size:13px;border-top:1px solid var(--line);padding-top:18px;margin-bottom:0}
</style></head><body><main>
<div class="brand"><span class="mark" aria-hidden="true">SN</span><span>${copy.service}</span></div>
<div class="status">HTTP ${status}</div><h1>${message.title}</h1><p>${message.body}</p>
<a href="https://console.servernet.cloud/account/servers" rel="noreferrer">${copy.panel}</a>
<p class="note">${message.note}</p></main></body></html>`, { status, headers });
}
