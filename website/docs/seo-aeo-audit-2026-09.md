# ممیزی SEO و AEO — ۷ سپتامبر ۲۰۲۶

## دامنه، روش و محدودیت داده

این ممیزی پیش از هر تغییر کد انجام شد و سه منبع را تطبیق داد: رندر درون‌پروسه‌ای
تمام ۶۵۷ URL نقشهٔ سایت با `php artisan site:gate`، پیمایش لینک‌ها با
`php artisan links:site` و بازبینی route/controller/view/config. دسترسی HTTP این
محیط به `servernet.cloud` در پراکسی با 403 متوقف شد؛ بنابراین وضعیت DNS/CDN و
Lighthouse میدانی در این اجرا قابل بازتأیید نبود.

هیچ credential، export یا اتصال Google Search Console در محیط/مخزن موجود نبود.
در نتیجه **نمی‌توان دادهٔ یک‌ماههٔ GSC یا فهرست واقعی queryها را بدون جعل داده
گزارش کرد**. بازهٔ درخواستی برای export برابر ۲۰۲۶-۰۸-۰۸ تا ۲۰۲۶-۰۹-۰۶ است.
خروجی Performance باید با ابعاد `query,page,country,device,searchAppearance` و
متریک‌های `clicks,impressions,ctr,position` (شامل ردیف‌های صفرکلیک) ارائه شود؛
Page indexing، Sitemaps و Core Web Vitals نیز export شوند. پس از دریافت:

* فرصت CTR: `impressions >= 100` و CTR کمتر از CTR میانهٔ همان بازهٔ position؛
  اولویت = `impressions × (benchmark_ctr(position) - ctr)`.
* فرصت رتبه: position بین ۴ و ۲۰؛ اولویت = impression قابل‌دستیابی × فاصلهٔ CTR
  تا رتبهٔ ۳، سپس تطبیق query→landing page برای جلوگیری از cannibalization.
* queryهای هم‌معنا که بیش از یک URL impression می‌گیرند با سهم هیچ URL بالاتر
  از ۷۰٪، کاندید cannibalization هستند؛ تصمیم نهایی با intent و conversion است.

## یافته‌های تطبیقی

| حوزه | وضعیت و Evidence | نتیجه / اقدام |
|---|---|---|
| Crawl و index | sitemap پویا ۶۵۷ URL را رندر کرد؛ گیت، status=200، یک H1، عنوان یکتا، بودجهٔ مسیر، schema نمونه و orphanهای لینک‌شده را کنترل می‌کند. | پایه قوی است؛ sitemap زنده باید در GSC با submitted/discovered/indexed تطبیق شود. |
| robots.txt | wildcard اجازهٔ crawl می‌دهد و `/api/`، account/login/payment/system/go را می‌بندد؛ sitemap اعلام شده است. | Google/Gemini و Bing/Copilot پوشش دارند. OAI-SearchBot و PerplexityBot نیز از wildcard مجازند؛ در CDN/WAF باید user-agent و IPهای مستندشان جداگانه verify شوند. |
| Canonical/parameters | layout برای صفحهٔ indexable canonical می‌دهد؛ noindexها canonical/hreflang ندارند. categoryهای blog canonical شامل query دارند و pagination از sitemap حذف است. | الگوی درست؛ نمونه‌های GSC «Duplicate/Alternate» و URLهای `?page`, `?cat` پایش شوند. پارامترهای campaign نباید canonical را تغییر دهند. |
| Redirect/host | middleware و تست‌های repo برای HTTPS، www→non-www، دامنهٔ قدیمی و console وجود دارد. بررسی شبکه از این محیط 403 شد. | زنجیرهٔ production را از بیرون با یک hop و 301/308، بدون loop، مجدداً probe کنید. |
| hreflang | fa/en/tr و x-default در layout تولید می‌شوند؛ صفحات فقط‌فارسی alternate دروغین نمی‌دهند و محتوای ترجمه‌نشده وارد sitemap زبان دیگر نمی‌شود. | reciprocity و status=200 تمام alternateها در crawl خارجی و International targeting sample بررسی شود. |
| Duplicate/thin/cannibalization | گیت عنوان تکراری را per-language مسدود می‌کند و page budget دارد. ۶۴ صفحه سفارش و صفحات ابزاری/شهری ریسک template similarity دارند؛ بدون GSC نمی‌توان cannibalization واقعی را اثبات کرد. | P1: cluster بر اساس query+page و بررسی word count/entity uniqueness؛ ادغام/noindex فقط پس از evidence. |
| Internal links/orphans | crawl رندرشده ۴۲۹ URL خارج sitemap کشف کرد؛ بیشتر مسیرهای تعاملی/noindex طبق allowlist طراحی خارج sitemap هستند. `links:site` شش asset را 404 گزارش کرد، اما فایل‌ها در `public/` موجودند و این محدودیت kernel داخلی است، نه broken URL اثبات‌شده. | crawl خارجی لازم است؛ صفحات indexable خارج sitemap باید صفر بمانند. blog DB محلی خالی بود، پس لینک‌های محتوای production هنوز نیاز به export/crawl دارد. |
| Headings/metadata | روی ۶۵۷ URL دقیقاً یک H1 و title یکتا کنترل شد. title/description/OG/Twitter در layout SSR هستند. | بعد از GSC، title/description صفحات impression بالا و CTR پایین را تست کنید؛ KPI: CTR و clicks همان query/page بدون افت position. |
| Schema | Organization سراسری و Product/Offer، FAQ، HowTo، Breadcrumb و WebApplication متناسب با template وجود دارد. | Rich Results/Enhancements را پایش کنید؛ FAQ فقط با FAQ قابل‌مشاهده منتشر شود و قیمت/availability با checkout یکی بماند. |
| JS rendering | metadata، canonical، hreflang، محتوای اصلی و schema server-rendered هستند؛ ابزارها client-side‌اند. | وابستگی index به JS پایین است. خروجی ابزار تعاملی نیاز به index ندارد؛ HTML اولیه باید توضیح و لینک کافی داشته باشد. |
| CWV/mobile | CSS اصلی حدود ۲۸۴KB و دارایی عمومی حدود ۱۱MB است؛ فونت‌های WOFF2 preload شده‌اند. سابقهٔ کد از ۷۴ URL موبایل با LCP>4s یاد می‌کند، ولی دادهٔ جاری GSC در دسترس نیست. | P0 پس از دسترسی: تفکیک Poor/Need improvement بر اساس template؛ PSI/Lighthouse mobile روی home، product، blog، webtool. KPI: good URLs و p75 LCP/INP/CLS. |
| Image SEO | گیت alt داشت، اما متن نمونهٔ `<img>` داخل JavaScript را تصویر واقعی حساب می‌کرد و سه false positive می‌ساخت. تصاویر محتوایی عمدتاً alt و تصاویر تزئینی alt خالی دارند. | در این PR شمارنده فقط markup رندرشونده را می‌سنجد؛ KPI: خطاهای image-alt گیت، image-search impressions/clicks و LCP. |
| Blog | index/category/pagination canonical تفکیک شده و ترجمهٔ منتشرنشده در sitemap نیست. DB محلی seedشده پست production ندارد. | export دیتابیس یا crawl production برای thin/orphan، decay، clusters و cannibalization الزامی است. |
| AI visibility | `/llms.txt` پویا، Organization/schema، محتوای SSR و robots باز وجود دارد. | دسترسی واقعی OAI-SearchBot/Perplexity/Bing/Google را در CDN logs بسنجید؛ llms.txt استاندارد الزام‌آور نیست و جای robots/schema/content را نمی‌گیرد. |

## Backlog اولویت‌بندی‌شده

### P0

1. **اتصال read-only به GSC و baseline ۲۸روزه** — Impact: بسیار زیاد؛ تنها راه
   اولویت‌بندی CTR/rank/CWV با تقاضای واقعی. Evidence: هیچ داده یا credential
   موجود نیست. Implementation: service account با دسترسی Restricted/Full به
   property یا exportهای گفته‌شده؛ ذخیرهٔ snapshot بدون secret در repo. KPI:
   clicks، impressions، CTR، average position، indexed pages و Good CWV URLs.
2. **crawl و CWV خارجی production** — Impact: زیاد؛ شبکهٔ این محیط 403 است.
   Evidence: curl قبل از تغییر در CONNECT proxy متوقف شد. Implementation:
   اجرای Screaming Frog/Sitebulb و Lighthouse از runner بیرونی، mobile و JS-on،
   به‌همراه logهای CDN. KPI: valid indexable 200، redirect chains، p75 LCP/INP/CLS.
3. **تأیید WAF برای crawlerهای search/AI** — Impact: زیاد اگر bot challenge
   وجود داشته باشد. Evidence: robots مجاز است اما reachability production اینجا
   اثبات نشد. Implementation: verify reverse DNS/IP طبق مستندات vendor، نه صرفاً
   UA؛ گزارش 2xx/3xx/4xx و crawl bytes. KPI: successful bot requests، 403 rate،
   discovered/indexed pages و referral/citation visibility.

### P1

1. **صف CTR/ranking مبتنی بر GSC** — Impact: زیاد و سریع. Evidence: pending
   export. Implementation: فرمول‌های بالا، سپس بازنویسی title/description و
   تقویت intent/internal links برای position 4–20؛ یک متغیر در هر آزمایش. KPI:
   query-page CTR/clicks و position در ۲۸ روز قبل/بعد.
2. **ممیزی production blog و content clusters** — Impact: متوسط تا زیاد.
   Evidence: DB محلی فاقد post بود. Implementation: crawl+GSC join، نقشهٔ parent
   hub، ادغام/redirect محتوای هم‌هدف، لینک contextual به money pages. KPI:
   non-brand clicks، orphan count، URLs per query و assisted conversion.
3. **بودجهٔ performance بر اساس template** — Impact: زیاد برای mobile SEO و
   conversion. Evidence: CSS اصلی بزرگ و سابقهٔ LCP موبایل. Implementation:
   critical CSS/font subset، حذف CSS بلااستفاده و تعیین width/height و preload
   فقط برای LCP image؛ بعد از trace، نه کورکورانه. KPI: p75 LCP/INP/CLS، Good URLs
   و conversion rate mobile.

### P2

1. **مانیتور هفتگی hreflang/canonical/schema/sitemap** — Impact: متوسط؛ جلوگیری
   از regression. Evidence: قرارداد فعلی خوب ولی ۶۵۷ URL پویاست. Implementation:
   scheduled external crawl و diff با alert. KPI: invalid alternates، duplicate
   canonical، schema errors و submitted-vs-indexed delta.
2. **اندازه‌گیری AI citation/share of voice** — Impact: متوسط و بلندمدت.
   Evidence: crawlability آماده است، visibility اندازه‌گیری نشده. Implementation:
   prompt set ثابت fa/en/tr، ثبت citation/domain mention و landing referral با
   UTM؛ انتشار facts قابل استناد با تاریخ/منبع. KPI: citation rate، branded
   searches، AI referrals و conversion آن‌ها.

## تغییر امن این PR و KPI

شمارش alt در release gate از regex روی کل response به inspectorی تغییر کرد که
comment، script و template را حذف می‌کند. این تغییر HTML production را عوض
نمی‌کند؛ false positive سه نسخهٔ `/webtools/svg-to-png` را حذف می‌کند و مانع
بی‌اعتبارشدن سیگنال image SEO می‌شود. KPI مستقیم: تعداد `RG-ALT-14` واقعی در
`site:gate` (هدف صفر)؛ KPI نتیجه‌ای پس از deploy: image search impressions/clicks
و نسبت URLهای دارای تصویر معتبر. هیچ deployی در این PR انجام نشده است.
