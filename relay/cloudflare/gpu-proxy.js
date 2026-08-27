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
 * ── نصب (یک بار، در داشبورد Cloudflare) ─────────────────────────────────
 * ۱) Workers & Pages → Create Worker → نام: gpu-proxy → این فایل را بچسبان.
 * ۲) DNS زون servernet.cloud → رکورد جدید:
 *      Type: A · Name: * · IPv4: 192.0.2.1 · Proxy: روشن (ابر نارنجی)
 *    (192.0.2.1 یک IPِ رزروِ مستندسازی است؛ هرگز استفاده نمی‌شود — ترافیک
 *     پیش از رسیدن به آن، توسط Worker پاسخ می‌گیرد.)
 *    ⚠️ رکوردهای موجود (console، my، …) دست نمی‌خورند — رکوردِ صریح همیشه
 *    بر wildcard مقدم است. فقط نام‌های تعریف‌نشده به Worker می‌رسند که ۴۰۴
 *    می‌گیرند.
 * ۳) Worker → Settings → Triggers → Add Route:
 *      Route: g-*.servernet.cloud/*   ·   Zone: servernet.cloud
 * ۴) پنل مدیریت → تنظیمات → زیرساختِ GPU → «دامنهٔ برندشدهٔ دروازه» =
 *      servernet.cloud → ذخیره.
 *    از این لحظه پنل و ایمیلِ تحویل نشانیِ g-….servernet.cloud می‌دهند.
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
