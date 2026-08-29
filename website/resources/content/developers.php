<?php

/**
 * محتوای مرجعِ APIِ نمایندگیِ دامنه — سه‌زبانه.
 *
 * ساختار:  key => [ locale => string|array ]
 *
 * ═══ چرا محتوا این‌جاست و نه در Blade ═══
 *
 * نسخهٔ اول این صفحه یک Blade فارسی‌تنها بود، با این استدلال که «مخاطبش
 * نمایندهٔ ایرانی است». آن استدلال غلط بود: `/en/developers` و
 * `/tr/developers` از قبل ساخته می‌شدند (روت داخلِ closureِ `$site` است) و
 * بازدیدکنندهٔ انگلیسی‌زبان یک صفحهٔ کاملاً فارسی می‌دید — که از نبودِ صفحه
 * بدتر است، چون به‌نظر می‌رسد سایت خراب است نه اینکه ترجمه ندارد.
 *
 * ═══ لحن ═══
 *
 * این یک مرجعِ یکپارچه‌سازی است، نه صفحهٔ بازاریابی. زبان **هنجاری** است:
 * «باید» یک الزامِ قراردادی است و نقضش رفتار را تعریف‌نشده می‌کند؛ «توصیه
 * می‌شود» یک قوّتِ پیشنهادی است. هر ادعا باید در کد قابلِ راستی‌آزمایی باشد.
 *
 * ⚠️ **هیچ عددی این‌جا نیست.** سقف‌ها، درصدها، دسترسی‌ها و فهرستِ عملیاتِ
 * ممنوع همه در Blade از `config()` خوانده می‌شوند. مستنداتی که عددش دستی
 * نوشته شود، اولین باری که تنظیمات عوض شود دروغ می‌گوید — و یکپارچه‌سازی‌ای
 * که بر اساسش نوشته شده، خرابی‌اش را ماه‌ها بعد نشان می‌دهد.
 *
 * ⚠️ رشته‌ها دو-نقل‌قولی‌اند چون متنِ انگلیسی و ترکی آپاستروف دارد. پس داخلِ
 * متن‌ها از نویسه‌های  "  و  $  استفاده نکنید.
 */

return [

    // ═══════════════════════ سرصفحه ═══════════════════════

    "badge" => ["fa" => "API نسخهٔ ۱ — پایدار", "en" => "API v1 — stable", "tr" => "API v1 — kararli"],

    "title" => [
        "fa" => "مرجع API نمایندگی دامنه",
        "en" => "Domain Reseller API Reference",
        "tr" => "Alan Adi Bayilik API Referansi",
    ],

    "lead" => [
        "fa" => "یک رابطِ HTTP برای ثبت، تمدید و مدیریت دامنه با قیمتِ سطحِ نمایندگی شما — طراحی‌شده برای فراخوانیِ خودکار از سامانهٔ صورت‌حسابِ خودتان. ماژول‌های آمادهٔ WHMCS و ووکامرس روی همین رابط ساخته شده‌اند.",
        "en" => "An HTTP interface for registering, renewing and managing domains at your reseller tier price, designed to be driven automatically from your own billing system. The WHMCS and WooCommerce modules are built on this same interface.",
        "tr" => "Alan adlarini bayi seviyenizin fiyatiyla kaydetmek, yenilemek ve yonetmek icin bir HTTP arayuzu — kendi faturalama sisteminizden otomatik cagrilmak uzere tasarlandi.",
    ],

    "meta_desc" => [
        "fa" => "مرجع کامل API سرورنت برای نمایندگان دامنه: احراز هویت با توکن، استعلام قیمت، ثبت، تمدید، مدیریت نام‌سرور، کلید یکتاسازی و کدهای خطا — به‌همراه ماژول WHMCS و ووکامرس.",
        "en" => "Complete ServerNet domain reseller API reference: token authentication, price lookup, registration, renewal, nameserver management, idempotency and error codes, with WHMCS and WooCommerce modules.",
        "tr" => "ServerNet alan adi bayilik API referansi: token kimlik dogrulama, fiyat sorgulama, kayit, yenileme, ad sunucu yonetimi, idempotency ve hata kodlari.",
    ],

    // ═══════════════════════ فهرست کناری ═══════════════════════

    "toc_title" => ["fa" => "در این صفحه", "en" => "On this page", "tr" => "Bu sayfada"],

    "print" => ["fa" => "نسخهٔ PDF", "en" => "PDF version", "tr" => "PDF surumu"],
    "print_hint" => [
        "fa" => "نمای چاپی در برگهٔ تازه باز می‌شود؛ در پنجرهٔ چاپ مقصد را روی «ذخیره به‌صورت PDF» بگذارید.",
        "en" => "Opens the print view in a new tab; set the destination to Save as PDF in the print dialog.",
        "tr" => "Yazdirma gorunumu yeni sekmede acilir; yazdirma penceresinde hedefi PDF olarak kaydet yapin.",
    ],

    // ═══════════════════════ ۱ — آغاز به کار ═══════════════════════

    "s1" => ["fa" => "آغاز به کار", "en" => "Getting started", "tr" => "Baslangic"],

    "s1_steps" => [
        "fa" => [
            "حسابِ شما باید به‌عنوان نمایندهٔ دامنه فعال شده باشد. تا پیش از آن، نقاطِ پایانیِ دامنه پاسخِ مجازنبودن می‌دهند.",
            "در پنل کاربری، بخش امنیت، یک توکن API صادر کنید و دامنهٔ دسترسیِ آن را به کمترین چیزی که یکپارچه‌سازی‌تان لازم دارد محدود کنید.",
            "نشانی IP خروجیِ سرورِ خودتان را در فهرستِ مجازِ همان توکن ثبت کنید. توکنِ بدونِ محدودیتِ IP از هر نقطه‌ای قابلِ استفاده است.",
            "حساب را شارژ کنید. ثبت و تمدید در لحظهٔ فراخوانی از اعتبار تسویه می‌شوند و صورت‌حسابِ پس از مصرف وجود ندارد.",
            "فراخوانیِ آزمایشی به نقطهٔ پایانیِ سلامت بزنید و سطح و اعتبارِ برگشتی را با پنل مقایسه کنید.",
        ],
        "en" => [
            "Your account must be enabled as a domain reseller. Until then the domain endpoints return an authorization error.",
            "In your client panel, under Security, issue an API token and narrow its scope to the minimum your integration needs.",
            "Add the egress IP of your own server to that token allowlist. A token without an IP restriction is usable from anywhere.",
            "Fund the account. Registrations and renewals settle against your credit at call time; there is no post-paid billing.",
            "Make a test call to the health endpoint and reconcile the returned tier and balance against the panel.",
        ],
        "tr" => [
            "Hesabinizin alan adi bayisi olarak etkinlestirilmesi gerekir; aksi halde uc noktalar yetki hatasi doner.",
            "Musteri panelinde Guvenlik bolumunden bir API token uretin ve kapsamini entegrasyonunuzun asgari ihtiyacina daraltin.",
            "Kendi sunucunuzun cikis IP sini o tokenin izin listesine ekleyin. IP kisiti olmayan bir token her yerden kullanilabilir.",
            "Hesabi fonlayin. Kayit ve yenileme cagri aninda kredinizden tahsil edilir; sonradan odemeli faturalama yoktur.",
            "Saglik uc noktasina bir test cagrisi yapin ve donen kademe ile bakiyeyi panelle karsilastirin.",
        ],
    ],

    "s1_warn" => [
        "fa" => "متنِ خامِ توکن تنها یک بار، در لحظهٔ صدور، نمایش داده می‌شود. ما فقط چکیدهٔ رمزنگاریِ آن را نگه می‌داریم و بازیابی‌اش از نظرِ محاسباتی ممکن نیست. توکنِ گم‌شده را باید باطل و توکنِ تازه صادر کرد؛ ابطال آنی است و بر توکن‌های دیگرِ همان حساب اثری ندارد.",
        "en" => "The raw token is displayed once, at issue time. We retain only its cryptographic digest, and recovery is computationally infeasible. A lost token must be revoked and replaced; revocation is immediate and does not affect the other tokens on the account.",
        "tr" => "Ham token yalnizca uretim aninda bir kez gosterilir. Yalnizca kriptografik ozetini sakliyoruz ve geri getirilmesi hesaplama acisindan mumkun degildir.",
    ],

    // ═══════════════════════ ۲ — احراز هویت ═══════════════════════

    "s2" => ["fa" => "احراز هویت و دامنهٔ دسترسی", "en" => "Authentication and scope", "tr" => "Kimlik dogrulama ve kapsam"],

    "s2_p" => [
        "fa" => "احراز هویت با توکنِ حامل در هدرِ Authorization انجام می‌شود. نشست، کوکی و CSRF در کار نیست؛ هر درخواست مستقل و بی‌حالت است. توکن نباید در نشانیِ URL، پارامترِ پرس‌وجو یا کدِ سمتِ مرورگر قرار بگیرد، چون در لاگِ سرور، لاگِ شبکهٔ توزیعِ محتوا و تاریخچهٔ مرورگر ثبت می‌شود.",
        "en" => "Authentication uses a bearer token in the Authorization header. There is no session, cookie or CSRF layer; every request is independent and stateless. The token must never appear in a URL path, a query parameter or browser-side code, where it would be recorded in server logs, CDN logs and browser history.",
        "tr" => "Kimlik dogrulama Authorization basligindaki bearer token ile yapilir. Oturum, cerez veya CSRF katmani yoktur; her istek bagimsiz ve durumsuzdur. Token asla URL de veya tarayici kodunda yer almamalidir.",
    ],

    "s2_scopes" => ["fa" => "دامنه‌های دسترسی", "en" => "Scopes", "tr" => "Kapsamlar"],

    /*
     * ⚠️ ترجمهٔ دسترسی‌ها، **نه** فهرستشان.
     *
     * فهرست از `CustomerApiToken::ABILITIES` می‌آید و Blade روی همان حلقه
     * می‌زند، پس دسترسیِ تازه خودبه‌خود در مستندات ظاهر می‌شود. این آرایه فقط
     * متنِ هر کلید را سه‌زبانه می‌کند و اگر کلیدی این‌جا نباشد، توضیحِ فارسیِ
     * خودِ مدل چاپ می‌شود — یعنی «ترجمه ندارد»، نه «نامرئی است».
     */
    "s2_scope_desc" => [
        "fa" => [], // خودِ ABILITIES فارسی است
        "en" => [
            "read" => "read the account profile, services, invoices and credit balance",
            "domains:read" => "read the domain portfolio, availability and tier pricing",
            "domains:write" => "register and renew domains — settles against account credit",
            "domains:manage" => "modify nameservers and the auto-renew flag on existing domains",
            "tunnel:read" => "read the WireGuard-over-TCP tunnel accounts on your exit server",
            "tunnel:write" => "create and delete tunnel accounts — the private key is returned once",
        ],
        "tr" => [
            "read" => "hesap profili, hizmetler, faturalar ve kredi bakiyesini okuma",
            "domains:read" => "alan adi portfoyu, uygunluk ve kademe fiyatlarini okuma",
            "domains:write" => "alan adi kaydi ve yenileme — hesap kredisinden tahsil edilir",
            "domains:manage" => "mevcut alan adlarinda ad sunucu ve otomatik yenileme degisikligi",
            "tunnel:read" => "çıkış sunucunuzdaki WireGuard-over-TCP tünel hesaplarını okuma",
            "tunnel:write" => "tünel hesabı oluşturma ve silme — özel anahtar yalnızca bir kez döner",
        ],
    ],

    "s2_note" => [
        "fa" => "دسترسیِ نوشتن به‌طور ضمنی شاملِ خواندن است؛ عکسِ آن هرگز برقرار نیست. توصیهٔ ما صدورِ توکنِ جداگانه به‌ازای هر محیط است — یکی فقط‌خواندنی برای گزارش‌گیری، یکی نوشتنی و مقیدشده به IP برای سرورِ صورت‌حساب. توکنِ لورفتهٔ فقط‌خواندنی هیچ هزینه‌ای تولید نمی‌کند.",
        "en" => "Write scope implies read scope; the converse never holds. We recommend issuing a separate token per environment — one read-only for reporting, one write and IP-bound for the billing host. A leaked read-only token cannot incur cost.",
        "tr" => "Yazma kapsami okuma kapsamini icerir; tersi asla gecerli degildir. Her ortam icin ayri token uretmenizi oneririz.",
    ],

    // ═══════════════════════ ۳ — قرارداد پاسخ ═══════════════════════

    "s3" => ["fa" => "قرارداد پاسخ", "en" => "Response contract", "tr" => "Yanit sozlesmesi"],

    "s3_p" => [
        "fa" => "هر پاسخ یک پوششِ JSON با شکلِ ثابت دارد. منطقِ شرطیِ سرویس‌گیرنده باید روی شناسهٔ ماشین‌خوانِ error بنشیند، نه روی متنِ message. متن قابلِ بومی‌سازی و ویرایش است و بخشی از قراردادِ پایدار نیست؛ شناسه‌ها بخشی از قرارداد نسخهٔ ۱ هستند و تا انتشارِ نسخهٔ بعدی حذف یا تغییرِ معنا نمی‌دهند.",
        "en" => "Every response is a JSON envelope with a fixed shape. Client branching must key on the machine-readable error identifier, never on the message text. Message text is localizable and editable and is not part of the stable contract; identifiers are part of the v1 contract and will not be removed or redefined before a new major version.",
        "tr" => "Her yanit sabit bicimli bir JSON zarfidir. Istemci mantigi mesaj metnine degil, makine tarafindan okunabilen error tanimlayicisina gore dallanmalidir.",
    ],

    "s3_warn" => [
        "fa" => "به کدِ وضعیتِ HTTP به‌تنهایی اتکا نکنید؛ همیشه فیلدِ ok را ارزیابی کنید. این یک محافظه‌کاریِ نظری نیست: چند سرویسِ بالادستیِ همین زنجیره — از جمله رجیسترار و درگاهِ پرداخت — روی خطا هم کدِ ۲۰۰ برمی‌گردانند و نتیجهٔ واقعی را فقط در بدنه می‌گذارند. سرویس‌گیرنده‌ای که فقط کدِ وضعیت را می‌سنجد، شکست را موفقیت می‌خواند.",
        "en" => "Do not rely on the HTTP status code alone; always evaluate the ok field. This is not theoretical caution: several upstream services in this same chain, the registrar and the payment gateway among them, return 200 on failure and place the real outcome only in the body. A client that inspects only the status code will read a failure as a success.",
        "tr" => "Yalnizca HTTP durum koduna guvenmeyin; her zaman ok alanini degerlendirin. Bu zincirdeki bazi ust servisler hatada bile 200 dondurur.",
    ],

    // ═══════════════════════ ۴ — نقاط پایانی ═══════════════════════

    "s4" => ["fa" => "نقاط پایانی", "en" => "Endpoints", "tr" => "Uc noktalar"],

    "s4_check" => ["fa" => "استعلام موجودی و قیمت", "en" => "Availability and pricing", "tr" => "Uygunluk ve fiyatlandirma"],

    "s4_state_warn" => [
        "fa" => "منطقِ سرویس‌گیرنده باید روی state تصمیم بگیرد، نه روی بولینِ available. مقدارِ unchecked به‌معنای «استعلام قطعی نشد» است و باید مثلِ یک خطای گذرا با تلاشِ دوباره برخورد شود، نه مثلِ «ثبت‌شده». تقلیلِ این شش حالت به یک بولین یک بار در همین سامانه به کاربران گفت نامِ انتخابی‌شان گرفته شده در حالی که آزاد بود.",
        "en" => "Client logic must branch on state, not on the available boolean. The value unchecked means the lookup did not resolve and must be treated as a transient condition eligible for retry, not as taken. Collapsing these six states into one boolean once told users in this very system that their preferred name was gone while it was in fact available.",
        "tr" => "Istemci mantigi available booleanina degil state alanina gore dallanmalidir. unchecked degeri sorgunun sonuclanmadigi anlamina gelir ve gecici bir durum olarak ele alinmalidir.",
    ],

    "s4_states" => [
        "fa" => [
            "free" => "قابلِ ثبت",
            "premium" => "قابلِ ثبت، با قیمت‌گذاریِ پرمیومِ رجیستری",
            "taken" => "ثبت‌شده",
            "unchecked" => "استعلام قطعی نشد — قابلِ تلاشِ دوباره",
            "unsupported" => "این پسوند در کاتالوگِ فروشِ ما نیست",
            "no_price" => "قابلِ ثبت، ولی قیمتِ قابلِ اتکا در دسترس نیست",
        ],
        "en" => [
            "free" => "registrable",
            "premium" => "registrable, at registry premium pricing",
            "taken" => "already registered",
            "unchecked" => "lookup did not resolve — retryable",
            "unsupported" => "the extension is not in our sales catalogue",
            "no_price" => "registrable, but no reliable price is available",
        ],
        "tr" => [
            "free" => "kaydedilebilir",
            "premium" => "kaydedilebilir, registry premium fiyatiyla",
            "taken" => "zaten kayitli",
            "unchecked" => "sorgu sonuclanmadi — tekrar denenebilir",
            "unsupported" => "uzanti satis katalogumuzda degil",
            "no_price" => "kaydedilebilir, ancak guvenilir fiyat yok",
        ],
    ],

    "s4_floor_note" => [
        "fa" => "پرچمِ price_floored با مقدارِ درست یعنی تخفیفِ سطحِ شما روی آن پسوند به‌طور کامل اعمال نشده، چون قیمتِ حاصل به کفِ حاشیهٔ ما رسیده است. این وضعیت پنهان نمی‌شود و در بخشِ قیمت‌گذاری کامل توضیح داده شده.",
        "en" => "A true price_floored flag means your tier discount was not fully applied on that extension because the resulting price reached our margin floor. The condition is never hidden; it is described in full in the pricing section.",
        "tr" => "price_floored degeri true ise, kademe indiriminiz o uzantida tam uygulanmamistir cunku fiyat marj tabanimiza ulasmistir.",
    ],

    "s4_register" => ["fa" => "ثبت دامنه", "en" => "Registration", "tr" => "Kayit"],

    "s4_order_states" => [
        "fa" => [
            "registered" => ["حالتِ نهایی — دامنه نزدِ رجیستری ثبت شده", "اقدامِ دیگری لازم نیست"],
            "pending" => ["حالتِ غیرنهایی — سفارش پذیرفته و در صفِ اجراست", "با فاصلهٔ فزاینده وضعیتِ دامنه را استعلام کنید؛ سفارشِ تازه ندهید"],
            "manual" => ["حالتِ غیرنهایی — نیازمند بررسیِ انسانی نزدِ ما", "مبلغ نگهداری می‌شود و نتیجه اعلام خواهد شد"],
            "failed" => ["حالتِ نهایی — ثبت انجام نشد", "مبلغ به‌طور کامل به اعتبارِ شما بازگردانده می‌شود"],
        ],
        "en" => [
            "registered" => ["terminal — the domain is registered at the registry", "no further action"],
            "pending" => ["non-terminal — the order is accepted and queued", "poll the domain endpoint with increasing backoff; do not re-order"],
            "manual" => ["non-terminal — held for human review on our side", "the amount is held and the outcome will be reported"],
            "failed" => ["terminal — registration did not complete", "the amount is returned to your credit in full"],
        ],
        "tr" => [
            "registered" => ["nihai — alan adi registry de kayitli", "baska islem gerekmez"],
            "pending" => ["nihai degil — siparis kabul edildi ve kuyrukta", "artan araliklarla sorgulayin; yeniden siparis vermeyin"],
            "manual" => ["nihai degil — tarafimizda insan incelemesinde", "tutar tutulur ve sonuc bildirilir"],
            "failed" => ["nihai — kayit tamamlanmadi", "tutar tamamen kredinize iade edilir"],
        ],
    ],

    "s4_pending_warn" => [
        "fa" => "حالتِ pending یک شکست نیست و نباید مثلِ شکست پردازش شود. تفسیرِ آن به‌عنوان خطا و ارسالِ سفارشِ دوباره می‌تواند دامنه‌ای را که همان لحظه در حالِ ثبت است دوباره خریداری کند. کلیدِ یکتاسازی دقیقاً برای مهارِ همین حالت طراحی شده و ارسالِ آن روی هر عملیاتِ پولی شرطِ درستیِ یکپارچه‌سازی است.",
        "en" => "The pending state is not a failure and must not be handled as one. Interpreting it as an error and re-submitting can purchase a domain that is being registered at that very moment. The idempotency key exists precisely to contain this case, and sending one on every billable operation is a correctness requirement.",
        "tr" => "pending bir hata degildir ve oyle islenmemelidir. Hata sanip yeniden gondermek, o anda kaydedilmekte olan alan adini ikinci kez satin alabilir.",
    ],

    // ═══════════════════════ ۵ — یکتاسازی ═══════════════════════

    "s5" => ["fa" => "یکتاسازی و تلاش دوباره", "en" => "Idempotency and retries", "tr" => "Idempotency ve yeniden deneme"],

    "s5_p" => [
        "fa" => "سرور درخواستِ بدونِ هدرِ Idempotency-Key را هم می‌پذیرد، ولی در آن حالت **هیچ محافظتی در برابرِ تکرار وجود ندارد**؛ بنابراین ارسالِ آن روی هر عملیاتِ پولی شرطِ درستیِ هر یکپارچه‌سازی است. کلید حداکثر ۸۰ نویسه است و پیش از انجامِ کار روی یک ایندکسِ یکتای پایگاه‌داده تصاحب می‌شود، پس دو درخواستِ هم‌زمان با یک کلید هرگز به دو تراکنشِ مالی منجر نمی‌شوند. پاسخِ بازپخش‌شده از نظرِ محتوا با پاسخِ اصلی یکسان است و پرچمِ replayed دارد. اگر تلاشِ اول با خطا تمام شود کلید آزاد می‌شود — یکتاسازی یعنی «یک کار دو بار انجام نشود»، نه «یک خطا تا ابد تکرار شود».",
        "en" => "The server accepts requests without an Idempotency-Key header, but in that case **no duplicate protection applies whatsoever**; sending one on every billable operation is therefore a correctness requirement of any integration. The key is at most 80 characters and is claimed on a unique database index before any work is performed, so two concurrent requests carrying the same key can never produce two financial transactions. A replayed response is identical in substance to the original and carries a replayed flag. If the first attempt ends in an error the key is released — idempotency means one operation is not performed twice, not that one error is repeated forever.",
        "tr" => "Sunucu Idempotency-Key basligi olmayan istekleri de kabul eder, ancak bu durumda hicbir tekrar korumasi uygulanmaz; bu nedenle her ucretli islemde gonderilmesi her entegrasyonun dogruluk sartidir. Anahtar en fazla 80 karakterdir ve is yapilmadan once benzersiz bir veritabani indeksinde talep edilir.",
    ],

    "s5_warn_title" => [
        "fa" => "کلیدِ تمدید باید تاریخِ انقضای جاری را در خود داشته باشد",
        "en" => "A renewal key must incorporate the current expiry date",
        "tr" => "Yenileme anahtari mevcut bitis tarihini icermelidir",
    ],

    "s5_warn" => [
        "fa" => "اگر کلید تنها از نامِ دامنه مشتق شود، تمدیدِ دورهٔ بعدیِ همان دامنه کلیدِ یکسانی تولید می‌کند. سرور آن را تکراری تشخیص می‌دهد و پاسخِ دورهٔ قبل را بازپخش می‌کند: سامانهٔ شما موفقیت ثبت می‌کند، مشتری پرداخت می‌کند، و هیچ تمدیدی نزدِ رجیستری انجام نمی‌شود. این خرابی تا روزِ انقضا هیچ نشانه‌ای تولید نمی‌کند. کلید باید دستِ‌کم شاملِ نامِ دامنه، تاریخِ انقضای جاری و تعدادِ سال باشد.",
        "en" => "If the key is derived from the domain name alone, the next period renewal of the same domain produces an identical key. The server treats it as a repeat and replays the previous response: your system records success, the customer is charged, and no renewal takes place at the registry. This failure emits no signal until the expiry date. The key must incorporate at least the domain name, the current expiry date and the term in years.",
        "tr" => "Anahtar yalnizca alan adindan turetilirse, bir sonraki donem yenilemesi ayni anahtari uretir ve onceki yanit tekrar oynatilir: sisteminiz basari kaydeder, musteri odeme yapar ve registry de hicbir yenileme gerceklesmez.",
    ],

    "s5_note" => [
        "fa" => "برای تلاشِ دوباره از عقب‌نشینیِ نمایی با پراکندگیِ تصادفی استفاده کنید و همان کلید را نگه دارید؛ کلیدِ تازه در تلاشِ دوباره کلِ محافظت را خنثی می‌کند. ماژول‌های رسمیِ ما این کلید را خودشان می‌سازند و نگه می‌دارند.",
        "en" => "Retry with exponential backoff and jitter, reusing the same key; minting a fresh key on retry defeats the entire guarantee. Our official modules construct and persist this key for you.",
        "tr" => "Ayni anahtari koruyarak ussel geri cekilme ve jitter ile yeniden deneyin; yeniden denemede yeni anahtar uretmek tum garantiyi bozar.",
    ],

    // ═══════════════════════ ۶ — خطاها ═══════════════════════

    "s6" => ["fa" => "شناسه‌های خطا", "en" => "Error identifiers", "tr" => "Hata tanimlayicilari"],

    "s6_rows" => [
        "fa" => [
            "missing_token" => "هدرِ Authorization ارسال نشده",
            "invalid_token" => "توکن شناسایی نشد",
            "token_expired" => "توکن منقضی شده — توکنِ تازه صادر کنید",
            "token_revoked" => "توکن باطل شده است",
            "ip_not_allowed" => "IP مبدأ در فهرستِ مجازِ این توکن نیست",
            "insufficient_scope" => "توکن دامنهٔ دسترسیِ لازم را ندارد",
            "panel_only" => "این عملیات از رابطِ برنامه‌نویسی در دسترس نیست",
            "insufficient_credit" => "اعتبار کافی نیست — مبلغِ لازم و موجودی در فیلدِ data آمده",
            "daily_cap_reached" => "سقفِ خرجِ روزانهٔ حساب پر شده است",
            "already_registered" => "دامنه در پرتفویِ فعالِ ما موجود است",
            "renewal_in_progress" => "تمدیدی برای همین دامنه در جریان است",
            "request_in_progress" => "درخواستی با همین کلیدِ یکتاسازی هنوز در حالِ پردازش است",
            "tld_blocked" => "ثبت در این پسوند موقتاً معلق است؛ هیچ مبلغی کسر نشد",
            "tld_not_sold" => "این پسوند در کاتالوگِ فروش نیست",
            "registrant_incomplete" => "مشخصاتِ مالکِ ثبت‌شده در حسابِ شما ناقص است",
            "no_price" => "قیمتِ قابلِ اتکا در دسترس نیست",
            "lookup_failed" => "استعلام از رجیسترار قطعی نشد — قابلِ تلاشِ دوباره",
            "registrar_rejected" => "رجیسترار تغییرِ درخواستی را نپذیرفت",
            "validation_failed" => "بارِ درخواست معتبر نیست — جزئیاتِ هر فیلد در data",
            "bad_idempotency_key" => "کلیدِ یکتاسازی از ۸۰ نویسه بلندتر است",
            "conflict" => "همین کلیدِ یکتاسازی قبلاً برای درخواستِ دیگری مصرف شده",
            "invalid_domain" => "نامِ دامنه از نظرِ نحوی معتبر نیست",
            "not_found" => "این دامنه در حسابِ شما نیست",
            "not_registered" => "دامنه هنوز نزدِ رجیسترار ثبت نشده — عملیاتِ مدیریتی روی آن ممکن نیست",
            "not_active" => "فقط دامنهٔ فعال تمدید می‌شود",
            "already_yours" => "دامنه از قبل در حسابِ خودِ شماست",
            "account_inactive" => "حسابِ نمایندگی در دسترس نیست",
            "order_failed" => "سفارش کامل نشد — هیچ مبلغی کسر نشده است",
        ],
        "en" => [
            "missing_token" => "no Authorization header was sent",
            "invalid_token" => "the token was not recognised",
            "token_expired" => "the token has expired — issue a new one",
            "token_revoked" => "the token has been revoked",
            "ip_not_allowed" => "the source IP is not on this token allowlist",
            "insufficient_scope" => "the token lacks the required scope",
            "panel_only" => "this operation is not exposed over the API",
            "insufficient_credit" => "insufficient credit — required and available amounts are in data",
            "daily_cap_reached" => "the account daily spend cap is exhausted",
            "already_registered" => "the domain is present in our active portfolio",
            "renewal_in_progress" => "a renewal for this domain is already running",
            "request_in_progress" => "a request with this idempotency key is still processing",
            "tld_blocked" => "registration in this extension is temporarily suspended; nothing was charged",
            "tld_not_sold" => "the extension is not in the sales catalogue",
            "registrant_incomplete" => "the registrant details on your account are incomplete",
            "no_price" => "no reliable price is available",
            "lookup_failed" => "the registrar lookup did not resolve — retryable",
            "registrar_rejected" => "the registrar refused the requested change",
            "validation_failed" => "the request payload is invalid — per-field detail is in data",
            "bad_idempotency_key" => "the idempotency key exceeds 80 characters",
            "conflict" => "this idempotency key was already consumed by a different request",
            "invalid_domain" => "the domain name is not syntactically valid",
            "not_found" => "the domain is not in your account",
            "not_registered" => "the domain is not yet registered at the registrar — management operations are unavailable",
            "not_active" => "only an active domain can be renewed",
            "already_yours" => "the domain is already in your own account",
            "account_inactive" => "the reseller account is unavailable",
            "order_failed" => "the order did not complete — nothing was charged",
        ],
        "tr" => [
            "missing_token" => "Authorization basligi gonderilmedi",
            "invalid_token" => "token taninmadi",
            "token_expired" => "token suresi doldu — yenisini uretin",
            "token_revoked" => "token iptal edilmis",
            "ip_not_allowed" => "kaynak IP bu tokenin izin listesinde degil",
            "insufficient_scope" => "token gerekli kapsama sahip degil",
            "panel_only" => "bu islem API uzerinden sunulmuyor",
            "insufficient_credit" => "kredi yetersiz — gereken ve mevcut tutarlar data alaninda",
            "daily_cap_reached" => "hesabin gunluk harcama limiti tukendi",
            "already_registered" => "alan adi aktif portfoyumuzde mevcut",
            "renewal_in_progress" => "bu alan adi icin yenileme zaten calisiyor",
            "request_in_progress" => "bu idempotency anahtarli istek hala isleniyor",
            "tld_blocked" => "bu uzantida kayit gecici olarak askida; ucret alinmadi",
            "tld_not_sold" => "uzanti satis katalogunda degil",
            "registrant_incomplete" => "hesabinizdaki kayit sahibi bilgileri eksik",
            "no_price" => "guvenilir fiyat yok",
            "lookup_failed" => "kayit sirketi sorgusu sonuclanmadi — tekrar denenebilir",
            "registrar_rejected" => "kayit sirketi istenen degisikligi reddetti",
            "validation_failed" => "istek govdesi gecersiz — alan bazli ayrinti data icinde",
            "bad_idempotency_key" => "idempotency anahtari 80 karakteri asiyor",
            "conflict" => "bu idempotency anahtari baska bir istek tarafindan kullanilmis",
            "invalid_domain" => "alan adi sozdizimsel olarak gecersiz",
            "not_found" => "alan adi hesabinizda degil",
            "not_registered" => "alan adi kayit sirketinde henuz kayitli degil — yonetim islemleri kullanilamaz",
            "not_active" => "yalnizca aktif bir alan adi yenilenebilir",
            "already_yours" => "alan adi zaten kendi hesabinizda",
            "account_inactive" => "bayi hesabi kullanilamiyor",
            "order_failed" => "siparis tamamlanmadi — ucret alinmadi",
        ],
    ],

    // ═══════════════════════ ۷ — قیمت‌گذاری ═══════════════════════

    "s7" => ["fa" => "قیمت‌گذاری و سطح‌بندی", "en" => "Pricing and tiers", "tr" => "Fiyatlandirma ve kademeler"],

    "s7_p" => [
        "fa" => "قیمتی که API برمی‌گرداند بهایِ خریدِ شماست: قیمتِ خرده‌فروشی پس از کسرِ تخفیفِ سطح. سطح از دو سنجه به‌طور هم‌زمان مشتق می‌شود — حجمِ خریدِ دوازده ماهِ گذشته و تعدادِ دامنهٔ فعالِ پرتفویتان — و روزانه بازبینی می‌گردد. قیمتِ برگشتی از استعلام تضمینِ اجرا نیست؛ مرجعِ تسویه همان استعلامِ تازه‌ای است که در لحظهٔ سفارش انجام می‌شود.",
        "en" => "The price returned by the API is your buying price: retail less the tier discount. The tier is derived from two metrics simultaneously — your trailing twelve month purchase volume and your active portfolio size — and is reviewed daily. A quoted price is not an execution guarantee; settlement is authoritative against the fresh quote taken at order time.",
        "tr" => "API nin dondurdugu fiyat alis fiyatinizdir: perakende eksi kademe indirimi. Kademe, son on iki aylik hacim ve aktif portfoy buyuklugunden ayni anda turetilir.",
    ],

    "s7_bullets" => [
        "fa" => [
            "ارتقا آنی است و در همان لحظه‌ای اعمال می‌شود که حجمِ خرید از آستانه عبور کند.",
            "تنزل تدریجی است: افتِ حجم ابتدا مهلت می‌گیرد و سپس حداکثر یک پله اعمال می‌شود. این عدمِ تقارن عمدی است.",
            "سطحِ جاری، فاصله تا آستانهٔ بعدی و تاریخِ آخرین بازبینی در پنلِ نمایندگی و در پاسخِ نقطهٔ پایانیِ سلامت در دسترس است.",
        ],
        "en" => [
            "Upgrades are immediate and apply the moment purchase volume crosses a threshold.",
            "Downgrades are gradual: a drop in volume first enters a grace period, then moves at most one step. The asymmetry is deliberate.",
            "Your current tier, the distance to the next threshold and the last review date are available in the reseller panel and in the health endpoint response.",
        ],
        "tr" => [
            "Yukseltmeler aninda uygulanir.",
            "Dususler kademelidir: once ek sure, sonra en fazla bir basamak. Bu asimetri kasitlidir.",
            "Mevcut kademeniz ve bir sonraki esige uzakliginiz bayi panelinde ve saglik uc noktasi yanitinda mevcuttur.",
        ],
    ],

    "s7_floor_title" => [
        "fa" => "کفِ حاشیه",
        "en" => "The margin floor",
        "tr" => "Marj tabani",
    ],

    "s7_floor" => [
        "fa" => "حاشیهٔ سودِ ما به‌ازای هر پسوند متفاوت است و از ساختارِ هزینهٔ همان رجیستری می‌آید. روی پسوندهای کم‌حاشیه — که در عمل پرتقاضاترین‌ها هم هستند — تخفیفِ سطحِ شما تنها تا کفی اعمال می‌شود که بالای بهایِ تمام‌شدهٔ ما بماند. هر جا این کف فعال شود، پاسخِ API پرچمِ price_floored را درست می‌گذارد و درصدِ مؤثرِ اعمال‌شده را جداگانه برمی‌گرداند.",
        "en" => "Our margin varies per extension and follows the cost structure of the registry concerned. On thin-margin extensions — in practice also the highest demand ones — your tier discount applies only down to a floor that remains above our landed cost. Wherever that floor engages, the API sets price_floored true and returns the effective applied percentage separately.",
        "tr" => "Marjimiz uzantiya gore degisir ve ilgili registry nin maliyet yapisini izler. Dusuk marjli uzantilarda indirim yalnizca maliyetimizin ustunde kalan bir tabana kadar uygulanir.",
    ],

    "s7_floor_why" => [
        "fa" => "افشای این محدودیت یک تصمیمِ آگاهانه است. کفِ اعلام‌نشده اختلافی می‌سازد که سرویس‌گیرنده نمی‌تواند حسابش را ببندد و تنها راهِ کشفش مغایرت‌گیریِ دستیِ فاکتورهاست — یعنی همان چیزی که یک برنامهٔ وفاداری قرار بود از بین ببرد.",
        "en" => "Disclosing this constraint is a deliberate choice. An undeclared floor produces a discrepancy the client cannot reconcile, discoverable only by manually auditing invoices — precisely the work a loyalty programme is meant to eliminate.",
        "tr" => "Bu kisitin acikca belirtilmesi bilincli bir tercihtir. Beyan edilmemis bir taban, istemcinin mutabakat yapamayacagi bir fark uretir.",
    ],

    // ═══════════════════════ ۸ — محدودیت‌ها ═══════════════════════

    "s8" => ["fa" => "سهمیه‌ها و محدودیت‌ها", "en" => "Quotas and limits", "tr" => "Kotalar ve limitler"],

    "s8_rows" => [
        "fa" => ["read" => "درخواست‌های خواندنی", "check" => "استعلام قیمت و موجودی", "write" => "عملیات نوشتنی",
                 "years" => "بیشینهٔ دورهٔ هر سفارش (سال)", "tokens" => "بیشینهٔ توکنِ فعالِ هم‌زمان", "min" => "دقیقه"],
        "en" => ["read" => "read requests", "check" => "availability and price lookups", "write" => "write operations",
                 "years" => "maximum term per order (years)", "tokens" => "maximum concurrent active tokens", "min" => "minute"],
        "tr" => ["read" => "okuma istekleri", "check" => "uygunluk ve fiyat sorgulari", "write" => "yazma islemleri",
                 "years" => "siparis basina azami sure (yil)", "tokens" => "azami es zamanli aktif token", "min" => "dakika"],
    ],

    "s8_note" => [
        "fa" => "عبور از سقفِ نرخ پاسخِ ۴۲۹ می‌گیرد و باید با عقب‌نشینی مدیریت شود، نه با فراخوانیِ موازیِ بیشتر. افزون بر این، هر حساب یک سقفِ خرجِ روزانه دارد که در پنلِ نمایندگی قابلِ تنظیمِ رو به پایین است. این سقف یک محافظِ محدودکنندهٔ خسارت است: در سناریوی افشای توکن، بیشینهٔ زیانِ ممکن پیش از آنکه کسی متوجه شود به همان عدد محدود می‌مانَد. توصیه می‌شود آن را روی چند برابرِ گردشِ روزانهٔ واقعی‌تان تنظیم کنید، نه بیشتر.",
        "en" => "Exceeding a rate limit returns 429 and must be handled with backoff, not with additional concurrency. Each account additionally carries a daily spend cap, adjustable downward in the reseller panel. This cap is a blast-radius control: in a token compromise scenario it bounds the maximum loss achievable before anyone notices. We recommend setting it to a small multiple of your genuine daily turnover, not higher.",
        "tr" => "Hiz limitinin asilmasi 429 doner ve daha fazla es zamanlilikla degil geri cekilmeyle yonetilmelidir. Ayrica her hesabin gunluk harcama limiti vardir.",
    ],

    // ═══════════════════════ ۹ — عمداً نیست ═══════════════════════

    "s9" => ["fa" => "عملیاتِ عمداً خارج از رابط", "en" => "Deliberately out of scope", "tr" => "Bilerek kapsam disi"],

    "s9_p" => [
        "fa" => "موارد زیر پیاده‌سازی‌نشده نیستند؛ آگاهانه از سطحِ دسترسیِ توکن کنار گذاشته شده‌اند. معیار روشن است: عملیاتی که با یک توکنِ افشاشده کنترلِ دامنهٔ مشتریِ نهایی را منتقل می‌کند، تنها با احرازِ انسانی در پنل انجام می‌شود. قفلِ انتقال از رابط فقط قابلِ روشن‌شدن است — عملیاتی که محافظت اضافه می‌کند بی‌خطر است، عملیاتی که محافظت را برمی‌دارد نیست.",
        "en" => "The following are not unimplemented; they are deliberately excluded from the token surface. The criterion is explicit: any operation that, with a compromised token, transfers control of an end customer domain is performed only under human authentication in the panel. The transfer lock can be enabled over the API but never disabled — an operation that adds protection is safe, one that removes it is not.",
        "tr" => "Asagidakiler eksik degil, token yuzeyinden bilerek cikarilmistir. Olcut acik: ele gecirilmis bir tokenla son musteri alan adinin kontrolunu devreden her islem yalnizca panelde insan dogrulamasiyla yapilir.",
    ],

    /* همان قاعده برای `panel_only_operations` در config — فهرست از config، متن از این‌جا. */
    "s9_desc" => [
        "fa" => [],
        "en" => [
            "auth_code" => "the transfer authorization (EPP) code is the bearer credential for domain ownership; returned over an API it persists in your application logs, CDN logs and error trackers.",
            "transfer_unlock" => "disabling the transfer lock is the necessary first step of moving a domain to another registrar.",
            "registrant_change" => "changing the registrant is a transfer of legal ownership, not an update of contact details.",
        ],
        "tr" => [
            "auth_code" => "transfer yetki (EPP) kodu alan adi sahipliginin hamiline kimlik bilgisidir; API den donerse kayitlarda kalici olur.",
            "transfer_unlock" => "transfer kilidini kaldirmak alan adini baska bir kayit sirketine tasimanin ilk adimidir.",
            "registrant_change" => "kayit sahibini degistirmek yasal sahiplik devridir, iletisim bilgisi guncellemesi degil.",
        ],
    ],

    "s9_extra" => [
        "fa" => ["dns" => "مدیریت رکوردهای DNS در دامنهٔ این رابط نیست؛ نام‌سرورِ خودتان یا ارائه‌دهندهٔ DNS دلخواهتان را ست کنید."],
        "en" => ["dns" => "DNS record management is outside the scope of this interface; delegate to your own nameservers or DNS provider."],
        "tr" => ["dns" => "DNS kayit yonetimi bu arayuzun kapsami disindadir; kendi ad sunucularinizi delege edin."],
    ],

    "s9_registrant" => [
        "fa" => "در نسخهٔ جاری، مالکِ ثبت‌شده نزدِ رجیستری حسابِ نمایندگیِ شماست، نه مشتریِ نهایی. اگر ماژول یا کدِ شما مشخصاتِ تماسِ مشتری را ارسال کند، این فیلدها نادیده گرفته و ذخیره نمی‌شوند. انتقالِ دادهٔ هویتیِ مشتریِ نهایی به یک مسیرِ دادهٔ مستقل نیاز دارد — رضایتِ صریح، توافق‌نامهٔ پردازش، سیاستِ نگهداری و مسیرِ حذف — و تا آماده‌شدنِ آن، پذیرشِ خاموشِ چنین داده‌ای بدتر از نپذیرفتنش است.",
        "en" => "In the current version the registry-side registrant is your reseller account, not your end customer. If your module or code sends customer contact fields, they are ignored and not persisted. Carrying end-customer identity data requires an independent data path — explicit consent, a processing agreement, a retention policy and an erasure route — and until that exists, silently accepting such data would be worse than refusing it.",
        "tr" => "Mevcut surumde registry tarafindaki kayit sahibi bayi hesabinizdir, son musteriniz degil. Gonderilen musteri iletisim alanlari yok sayilir ve saklanmaz.",
    ],

    "s9_ir" => [
        "fa" => "پسوندهای ملیِ ایران از این مسیر عرضه نمی‌شوند. بهایِ آنها از راهِ رجیسترارِ بین‌المللی چند ده برابرِ تعرفهٔ مستقیمِ ایرنیک است، پس استعلامشان حالتِ unsupported برمی‌گرداند و اساساً هیچ قیمتی تولید نمی‌شود. این محدودیتِ عرضه است، نه محدودیتِ فنی، و در نقشهٔ راه پیگیری می‌شود.",
        "en" => "Iranian national extensions are not offered through this channel. Their cost through an international registrar is many times the direct IRNIC tariff, so a lookup returns unsupported and no price is generated at all. This is a supply constraint rather than a technical one, and it is tracked on the roadmap.",
        "tr" => "Iran ulusal uzantilari bu kanaldan sunulmaz; sorgu unsupported doner ve hic fiyat uretilmez. Bu teknik degil tedarik kaynakli bir kisittir.",
    ],

    // ═══════════════════════ ۱۰ — ماژول‌ها ═══════════════════════

    "s10" => ["fa" => "ماژول‌های رسمی", "en" => "Official modules", "tr" => "Resmi moduller"],

    "s10_p" => [
        "fa" => "دو پیاده‌سازیِ مرجع نگهداری می‌شوند که همین رابط را مصرف می‌کنند و هر دو از پنلِ نمایندگی قابلِ دریافت‌اند. اگر پشتهٔ شما یکی از این دو است، ماژول را به یکپارچه‌سازیِ دست‌نویس ترجیح دهید: محافظ‌های مالی از پیش در آنها پیاده شده‌اند.",
        "en" => "Two reference implementations are maintained against this interface, both downloadable from the reseller panel. If your stack is one of these two, prefer the module over a hand-written integration: the financial safeguards are already implemented in it.",
        "tr" => "Bu arayuze karsi iki referans uygulama surdurulmektedir; ikisi de bayi panelinden indirilebilir. Yiginiz bunlardan biriyse el yazimi entegrasyon yerine modulu tercih edin.",
    ],

    "s10_whmcs" => [
        "fa" => "یک ماژولِ رجیسترارِ استاندارد. در پوشهٔ رجیسترارهای WHMCS قرار می‌گیرد و تنها به توکن نیاز دارد. استعلام، ثبت، تمدید، مدیریت نام‌سرور، قفلِ انتقال، همگام‌سازیِ وضعیت و درون‌ریزیِ جدولِ قیمت را پوشش می‌دهد و کلیدِ یکتاسازی را — با احتسابِ تاریخِ انقضا — خودش می‌سازد.",
        "en" => "A standard registrar module. It installs into the WHMCS registrars directory and requires only the token. It covers lookup, registration, renewal, nameserver management, transfer lock, status synchronisation and price-table import, and constructs the idempotency key itself, expiry date included.",
        "tr" => "Standart bir registrar modulu. WHMCS registrar dizinine kurulur ve yalnizca token gerektirir. Sorgu, kayit, yenileme, ad sunucu, transfer kilidi ve fiyat aktarimini kapsar.",
    ],

    "s10_wp" => [
        "fa" => "یک افزونهٔ وردپرس با یکپارچگیِ اختیاریِ ووکامرس. کدِ کوتاه رابطِ جستجو را رندر می‌کند؛ با ووکامرسِ فعال، دامنه به سبد افزوده می‌شود، مشتری از درگاهِ خودِ شما پرداخت می‌کند و ثبت پس از تأییدِ پرداخت به‌طور خودکار اجرا می‌شود. افزونه روی نصبِ بدونِ ووکامرس هم بی‌خطا بالا می‌آید.",
        "en" => "A WordPress plugin with optional WooCommerce integration. A shortcode renders the search interface; with WooCommerce active the domain enters the cart, the customer pays through your own gateway, and registration executes automatically once payment is confirmed. The plugin also loads cleanly on installations without WooCommerce.",
        "tr" => "Istege bagli WooCommerce entegrasyonlu bir WordPress eklentisi. Kisa kod arama arayuzunu olusturur; odeme onaylandiginda kayit otomatik calisir.",
    ],

    "s10_guards_title" => [
        "fa" => "سه محافظِ مالی که ماژول‌ها پیاده کرده‌اند — و یکپارچه‌سازیِ دست‌نویس هم باید بکند",
        "en" => "Three financial safeguards the modules implement — and a hand-written integration must implement too",
        "tr" => "Modullerin uyguladigi uc finansal koruma — el yazimi entegrasyonun da uygulamasi gerekir",
    ],

    "s10_guards" => [
        "fa" => [
            "قیمت هرگز از سمتِ مرورگر پذیرفته نمی‌شود. در لحظهٔ افزودن به سبد، قیمت مجدداً از این رابط استعلام و جایگزین می‌شود. بی‌این محافظ، یک درخواستِ دست‌ساز می‌تواند دامنه‌ای گران را به مبلغِ دلخواه واردِ سبد کند؛ تسویه با بهایِ واقعی از اعتبارِ شما انجام می‌شود و مابه‌التفاوت زیانِ شماست.",
            "پیش از اجرای ثبت، بهایِ خرید با مبلغِ لحظهٔ سفارش مقایسه می‌شود. اگر افزایش از آستانهٔ تعریف‌شده بگذرد، ثبتِ خودکار انجام نمی‌شود و سفارش برای تصمیمِ انسانی معلق می‌مانَد. فاصلهٔ میانِ افزودن به سبد و پرداخت می‌تواند روزها باشد و در آن فاصله نرخِ ارز جابه‌جا می‌شود.",
            "کلیدِ یکتاسازی از شناسهٔ سطرِ سفارش مشتق می‌شود، نه از شناسهٔ سفارش. یک سفارش می‌تواند چند دامنه داشته باشد و ووکامرس یک سفارش را از چند مسیرِ مستقل — وب‌هوکِ درگاه، بازگشتِ کاربر و تغییرِ دستیِ مدیر — پرداخت‌شده علامت می‌زند.",
        ],
        "en" => [
            "A price is never accepted from the browser. At add-to-cart time the price is re-quoted from this interface and substituted. Without this safeguard a hand-crafted request can place an expensive domain into the cart at an arbitrary amount; settlement still draws the true cost from your credit and the difference is your loss.",
            "Before registration executes, the buying price is compared against the amount quoted at order time. If the increase exceeds a configured tolerance, automatic registration is skipped and the order is held for a human decision. Days can elapse between add-to-cart and payment, and exchange rates move in that window.",
            "The idempotency key is derived from the order line identifier, not the order identifier. One order may contain several domains, and WooCommerce marks a single order paid through several independent paths: the gateway webhook, the customer return, and a manual status change by an administrator.",
        ],
        "tr" => [
            "Fiyat asla tarayicidan kabul edilmez; sepete ekleme aninda bu arayuzden yeniden sorgulanir ve degistirilir.",
            "Kayit yurutulmeden once alis fiyati siparis anindaki tutarla karsilastirilir; artis tanimli tolerani asarsa otomatik kayit atlanir ve siparis insan karari icin bekletilir.",
            "Idempotency anahtari siparis kimliginden degil siparis satiri kimliginden turetilir; bir siparis birden fazla alan adi icerebilir ve WooCommerce bir siparisi birden fazla bagimsiz yoldan odenmis isaretler.",
        ],
    ],

    // ═══════════════════════ ۱۱ — نقشهٔ راه ═══════════════════════

    "s11" => ["fa" => "نقشهٔ راه", "en" => "Roadmap", "tr" => "Yol haritasi"],

    "s11_p" => [
        "fa" => "موارد زیر برنامه‌ریزی شده‌اند ولی هنوز در رابطِ نسخهٔ ۱ منتشر نشده‌اند. تا لحظهٔ انتشار، فراخوانیِ نقطهٔ پایانیِ متناظر پاسخِ ۴۰۴ می‌گیرد. افزودنِ نقطهٔ پایانیِ تازه یک تغییرِ سازگار است و نسخهٔ رابط را بالا نمی‌برد؛ سرویس‌گیرندهٔ شما نباید با دیدنِ فیلدِ ناشناخته در پاسخ خطا بدهد.",
        "en" => "The following are planned but not yet published in the v1 interface. Until release, calling the corresponding endpoint returns 404. Adding an endpoint is a backward-compatible change and does not increment the interface version; your client must not error on encountering an unknown field in a response.",
        "tr" => "Asagidakiler planlanmistir ancak v1 arayuzunde henuz yayinlanmamistir. Yayina kadar ilgili uc nokta 404 doner. Uc nokta eklemek geriye donuk uyumlu bir degisikliktir.",
    ],

    "s11_rows" => [
        "fa" => [
            "transfer" => "انتقالِ دامنه از رجیسترارِ دیگر — ارسالِ کدِ انتقال، پیگیریِ وضعیتِ درخواست و تسویهٔ سالِ اضافه‌شده. در دستِ توسعه است. توجه: نقطهٔ پایانیِ فهرستِ پسوندها از هم‌اکنون قیمتِ انتقال را برمی‌گرداند تا بتوانید جدولِ قیمتِ خود را کامل بسازید؛ ولی عملیاتِ انتقال هنوز قابلِ فراخوانی نیست.",
            "ir" => "عرضهٔ پسوندهای ملیِ ایران از راهِ اتصالِ مستقیم، به‌جای رجیسترارِ بین‌المللی.",
            "webhook" => "اعلانِ رویداد به نشانیِ شما، تا برای تغییرِ حالتِ سفارش‌های غیرنهایی به استعلامِ دوره‌ای نیاز نباشد.",
            "contact" => "مسیرِ دادهٔ مالکِ ثبت‌شده به تفکیکِ مشتریِ نهایی، همراه با سازوکارِ رضایت و حذف.",
        ],
        "en" => [
            "transfer" => "inbound domain transfer — submitting the authorization code, tracking request state and settling the added year. In development. Note that the TLD listing endpoint already returns a transfer price so you can build a complete price table; the transfer operation itself is not yet callable.",
            "ir" => "supply of Iranian national extensions through a direct connection rather than an international registrar.",
            "webhook" => "event delivery to an endpoint of yours, removing the need to poll for state changes on non-terminal orders.",
            "contact" => "a per-end-customer registrant data path, with the accompanying consent and erasure mechanics.",
        ],
        "tr" => [
            "transfer" => "gelen alan adi transferi — yetki kodunun gonderilmesi, istek durumunun izlenmesi ve eklenen yilin tahsili. Gelistirme asamasinda. TLD listeleme uc noktasi transfer fiyatini simdiden dondurur; ancak transfer islemi henuz cagrilabilir degildir.",
            "ir" => "Iran ulusal uzantilarinin uluslararasi kayit sirketi yerine dogrudan baglantiyla saglanmasi.",
            "webhook" => "kendi uc noktaniza olay iletimi; nihai olmayan siparisler icin surekli sorgulama gereksinimini kaldirir.",
            "contact" => "son musteri bazinda kayit sahibi veri yolu, ilgili riza ve silme mekanizmalariyla.",
        ],
    ],
];
