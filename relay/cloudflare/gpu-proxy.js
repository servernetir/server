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
 * راستی‌آزماییِ انجام‌شده: g-test.servernet.cloud از Worker به لبهٔ زیرساخت
 * رسید؛ میزبانِ بی‌الگو 404ِ خودِ Worker گرفت؛ cdn دست‌نخورده ماند.
 *
 * SSE و WebSocket از Worker رد می‌شوند؛ TLS مالِ Cloudflare است (پوششِ
 * Universal SSL برای *.servernet.cloud — تک‌سطحی، برای همین g-{label} است
 * نه {label}.g).
 */
export default {
  async fetch(request) {
    const url = new URL(request.url);
    const m = url.hostname.match(/^g-([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.servernet\.cloud$/);

    if (!m) {
      return new Response('Not found', { status: 404 });
    }

    url.hostname = m[1] + '.salad.cloud';
    url.port = '';

    return fetch(new Request(url, request));
  },
};
