/**
 * مهاجرت سئوی servernet.ir → servernet.cloud — نسخه‌ی لبه v3
 *
 * تغییر نسبت به v2 (۲۸ اوت — پس از لایوشدن بخش سه‌زبانه‌ی /urmia):
 *   · ۱۷ صفحه‌ی شهری قدیمی حالا مستقیم به صفحه‌ی شهریِ خودشان می‌روند
 *     (/urmia/cities/{slug}) نه /webdesign — تطبیق معناییِ یک‌به‌یک، انتقال
 *     اعتبار قوی‌تر.
 *   · خوشه‌ی «در-ارومیه» و «طراحی-سایت» به وارث واقعی‌شان: /urmia و
 *     /urmia/web-design (نه /webdesign عمومی).
 *   · اسلاگ‌های قدیمی ترکی/انگلیسی که تا امروز ۴۱۰ می‌گرفتند، حالا مقصد
 *     دارند: /tr/urmia/web-design و /en/urmia/web-design.
 * بقیه (پورت‌های پنل، PASS، تاکسونومی، ۴۱۰ فراگیر) عیناً همان v2.
 *
 * نصب: Workers & Pages → blue-heart-fbf3 → Edit code → جایگزینی کامل → Deploy
 * (روت‌ها همان‌اند: servernet.ir/* و *.servernet.ir/*)
 */

const CLOUD = 'https://servernet.cloud';

// مقصدهای دقیق — کلیدها بدون / ابتدا و انتها، رمزگشایی‌شده
const EXACT = {
  'about-us': '/about',
  'contact': '/contact',
  'terms': '/terms',
  'host': '/hosting/linux',
  'webdesign': '/webdesign',
  'seo': '/solutions/seo-services',
  'marketing': '/solutions/seo-services',
  'app': '/webdesign',
  'graphics': '/webdesign',
  'media': '/webdesign',
  'blog': '/blog',
  'بهترین-سرور-مجازی-برای-سرخطی': '/vps/iran',
  'سرور-اختصاصی-و-خرید-آن': '/dedicated/iran',
};

// اگر اسلاگ یکی از این‌ها را «در بر داشت» → مقصد. ترتیب = اولویت:
// اول شهرهای مشخص، بعد ارومیه، آخر از همه‌ی fallbackهای عمومی.
const CONTAINS = [
  // ── مقاله‌ها (همان v2) ──
  ['سرور-وی-پی-ان', '/blog/vpn-server'],
  ['لیست-سرورهای-ایرانی-و-فارسی-دیسکورد', '/blog/iranian-discord-servers'],
  ['وی-پی-ان-یا-فیلترشکن-چیست', '/blog/what-is-vpn'],
  ['کسب-درآمد-از-رشته-برق', '/blog/earn-money-electrical-engineering'],
  ['پیشرفت-تکنولوژی-از-گذشته', '/blog/technology-progress-history'],
  ['ویژگی-منحصر-به-فرد-گوشی', '/blog/smartphone-hidden-features'],
  ['پرداخت-درونبرنامهای-کافه', '/blog/cafebazaar-in-app-payment'],
  ['کسب-درآمد-از-معماری', '/blog/earn-money-architecture'],
  ['درگاه-زرین-پال-در-اپلیکیشن', '/blog/zarinpal-app-integration'],
  ['پرداخت-درونبرنامهای-مایکت', '/blog/myket-in-app-payment'],
  ['تبلیغات-تپسل', '/blog/tapsell-ads-setup'],
  ['کسب-درآمد-از-رشته-کامپیوتر', '/blog/earn-money-computer-science'],
  ['google-tasks', '/blog/google-tasks-guide'],
  ['معرفی-کلاینت', '/blog/what-is-a-client'],
  ['نگاهی-به-سرور-گوشی', '/blog/phone-servers-explained'],
  ['معرفی-6-نرم', '/blog/graphic-design-software'],
  ['انتشار-اپلیکیشن-در-ایران-اپس', '/blog/publish-app-iranapps'],
  ['حساب-توسعه-دهنده-در-گوگل-پلی', '/blog/google-play-developer-account'],
  ['انتشار-اپلیکیشن-در-کافه-بازار', '/blog/publish-app-cafebazaar'],
  ['تفاوت-میان-پروکسی-و-فیلترشکن', '/blog/proxy-vs-vpn'],
  ['دکمه-خروج-به-اپلیکیش', '/blog/app-exit-button-tutorial'],
  ['کسب-درآمد-از-طریق-کامپیوتر', '/blog/make-money-with-computer'],
  ['راهنمای-استفاده-از-پنل-سرورنت', '/knowledge'],
  ['شماره-حساب-ها-و-اطلاعات-پرداخت', '/contact'],

  // ── خوشه‌ی شهری — یک‌به‌یک به صفحه‌ی همان شهر (v3) ──
  // نیدل «در-شهر» هر دو قالب قدیمی را می‌گیرد: «طراحی-سایت-در-X» و
  // «سرورنت-طراحی-سایت-در-X». شاهین‌دژ/قره‌ضیاءالدین عمداً کوتاه‌اند تا
  // نیم‌فاصله/املای متفاوت اسلاگ قدیمی بی‌اثر باشد.
  ['در-خوی', '/urmia/cities/khoy'],
  ['در-سلماس', '/urmia/cities/salmas'],
  ['در-مهاباد', '/urmia/cities/mahabad'],
  ['در-بوکان', '/urmia/cities/bukan'],
  ['در-میاندوآب', '/urmia/cities/miandoab'],
  ['در-پیرانشهر', '/urmia/cities/piranshahr'],
  ['در-نقده', '/urmia/cities/naqadeh'],
  ['در-تکاب', '/urmia/cities/takab'],
  ['در-شاهین', '/urmia/cities/shahin-dej'],
  ['در-ماکو', '/urmia/cities/maku'],
  ['در-بازرگان', '/urmia/cities/bazargan'],
  ['در-سردشت', '/urmia/cities/sardasht'],
  ['در-اشنویه', '/urmia/cities/oshnavieh'],
  ['در-پلدشت', '/urmia/cities/poldasht'],
  ['در-شوط', '/urmia/cities/showt'],
  ['در-چالدران', '/urmia/cities/chaldoran'],
  ['در-قره', '/urmia/cities/qarah-ziaeddin'],

  // ── اسلاگ‌های قدیمی ترکی/انگلیسی — تا v2 ۴۱۰ می‌گرفتند (v3) ──
  ['urmiyede', '/tr/urmia/web-design'],
  ['istanbulda', '/tr/urmia/web-design'],
  ['website-design-in-urmia', '/en/urmia/web-design'],

  // ── وارث خوشه‌ی ارومیه (v3: قبلاً /webdesign) ──
  ['طراحی-سایت-در-ارومیه', '/urmia/web-design'],
  ['در-ارومیه', '/urmia'],
  ['سرورنت-طراحی-سایت-در-', '/urmia'],
  ['طراحی-سایت', '/urmia/web-design'],
];

// مقاله‌های زنده تا مهاجرت محتوا — تطبیق زیررشته‌ای
const KEEP = [];

// مسیرهای زیرساختی که باید به مبدأ برسند
const PASS_PREFIX = [
  'wp-admin', 'wp-login.php', 'wp-cron.php', 'wp-content/', 'wp-includes/',
  'sms-relay.php', 'sms-poller', 'index.php',
];

function r301(to) {
  return Response.redirect(to, 301);
}

function gone() {
  return new Response(
    '<!doctype html><meta charset="utf-8"><title>410</title>' +
    '<p>این صفحه برای همیشه حذف شده است. سایت ما: <a href="https://servernet.cloud">servernet.cloud</a></p>',
    { status: 410, headers: { 'content-type': 'text/html; charset=utf-8' } }
  );
}

// پورت‌های cPanel/WHM/وب‌میلِ سرور ایران — ریدایرکت نمی‌شوند، مستقیم به مبدأ
const PANEL_PORTS = ['2082', '2083', '2086', '2087', '2095', '2096'];

export default {
  async fetch(request) {
    const url = new URL(request.url);

    // Hermes: exact, authenticated application endpoints; fixed destination.
    if (url.hostname === 'servernet.ir' && url.pathname.startsWith('/bale/hermes')) {
      const methods = {
        '/bale/hermes': ['GET', 'HEAD'],
        '/bale/hermes/session': ['GET'],
        '/bale/hermes/overview': ['GET'],
        '/bale/hermes/state': ['GET'],
        '/bale/hermes/chat': ['POST'],
        '/bale/hermes/memory': ['POST'],
        '/bale/hermes/history/clear': ['POST'],
        '/bale/hermes/assets/fonts/IRANSans-regular.woff2': ['GET', 'HEAD'],
        '/bale/hermes/assets/fonts/IRANSans-medium.woff2': ['GET', 'HEAD'],
        '/bale/hermes/assets/fonts/IRANSans-bold.woff2': ['GET', 'HEAD'],
        '/bale/hermes/errors': ['GET'],
        '/bale/hermes/automation': ['GET'],
        '/bale/hermes/client-errors': ['POST'],
      };
      const jsonError = (status, code) => new Response(JSON.stringify({ok: false, code}), {
        status, headers: {'Content-Type': 'application/json', 'Cache-Control': 'private, no-store'}
      });
      if (!methods[url.pathname]) return jsonError(404, 'not_found');
      if (!methods[url.pathname].includes(request.method)) return jsonError(405, 'method_not_allowed');
      if (Number(request.headers.get('content-length') || 0) > 65536) return jsonError(413, 'request_too_large');
      const headers = new Headers();
      for (const name of ['Accept', 'Content-Type', 'X-Bale-Init-Data', 'X-Hermes-Session']) {
        const value = request.headers.get(name);
        if (value) headers.set(name, value);
      }
      try {
        const upstream = await fetch(CLOUD + url.pathname + url.search, {
          method: request.method, headers,
          body: request.method === 'POST' ? request.body : undefined,
          redirect: 'manual', cf: {cacheEverything: false, cacheTtl: 0}
        });
        if (upstream.status >= 300 && upstream.status < 400) return jsonError(502, 'service_unavailable');
        const result = new Response(upstream.body, upstream);
        result.headers.set('Cache-Control', 'private, no-store');
        result.headers.delete('Set-Cookie');
        return result;
      } catch {
        return jsonError(502, 'service_unavailable');
      }
    }


    if (PANEL_PORTS.includes(url.port)) {
      return fetch(request);
    }

    let path;
    try {
      path = decodeURIComponent(url.pathname);
    } catch {
      return gone(); // درصدنویسی خراب = آدرس آشغال
    }
    const slug = path.replace(/^\/+|\/+$/g, ''); // بدون / ابتدا و انتها

    // خانه — نسخه‌های زبانی WPML به زبان همان مقصد
    if (slug === '') {
      const lang = url.searchParams.get('lang');
      if (lang === 'en') return r301(CLOUD + '/en');
      if (lang === 'tr') return r301(CLOUD + '/tr');
      return r301(CLOUD + '/');
    }

    // زیرساخت + مقاله‌های زنده → مبدأ (فقط این‌ها به سرور ایران می‌رسند)
    if (PASS_PREFIX.some((p) => slug === p || slug.startsWith(p))) {
      return fetch(request);
    }
    if (KEEP.some((k) => slug.includes(k))) {
      return fetch(request);
    }

    // نگاشت دقیق
    if (EXACT[slug] !== undefined) {
      return r301(CLOUD + EXACT[slug]);
    }

    // تاکسونومی بلاگ
    if (/^(category|tag|author)\//.test(slug)) {
      return r301(CLOUD + '/blog');
    }

    // نگاشت زیررشته‌ای (مقاله‌ها + خوشه‌ی شهری + میراث en/tr)
    for (const [needle, dest] of CONTAINS) {
      if (slug.includes(needle)) return r301(CLOUD + dest);
    }

    // بقیه‌ی تاریخ — ۴۱۰
    return gone();
  },
};
