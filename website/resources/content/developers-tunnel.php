<?php

/**
 * محتوای مرجعِ APIِ تونلِ سرورِ اکسیت — سه‌زبانه.
 *
 * ═══ چرا صفحهٔ جداست و نه بخشی از /developers ═══
 *
 * `/developers` عنوانش «مرجع API نمایندگی دامنه» است و کلِ روایتش دربارهٔ
 * ثبت و تمدیدِ دامنه. چهار ردیفِ تونل مدتی داخلِ همان جدولِ endpointها بود و
 * از نظرِ فنی «مستند» بود — ولی مشتری‌ای که برای ساختِ کاربرِ WireGuard به
 * آن صفحه می‌رفت، چهار خط میانِ چهارده خطِ دامنه می‌دید و هیچ توضیحی نه از
 * جریان، نه از فیلدها، نه از ایجنتِ روتر.
 *
 * **فهرست‌شدن مستندسازی نیست.** اگر خواننده بعد از خواندنش نداند از کجا
 * شروع کند، آن صفحه کارش را نکرده.
 *
 * ⚠️ **هیچ عددی این‌جا نیست** — سقفِ اکانت، فاصلهٔ پیمایشِ ایجنت و سقف‌های
 * نرخ در Blade از ثابت‌های کد خوانده می‌شوند. عددِ دستی، اولین باری که
 * تنظیمات عوض شود دروغ می‌گوید.
 *
 * ⚠️ رشته‌ها دو-نقل‌قولی‌اند چون متنِ انگلیسی و ترکی آپاستروف دارد. پس داخلِ
 * متن‌ها از نویسه‌های  "  و  $  استفاده نکنید.
 */

return [

    // ═══════════════════════ سرصفحه ═══════════════════════

    "badge" => ["fa" => "API نسخهٔ ۱ — پایدار", "en" => "API v1 — stable", "tr" => "API v1 — kararli"],

    "title" => [
        "fa" => "مرجع API تونل سرور اکسیت",
        "en" => "Exit Server Tunnel API Reference",
        "tr" => "Cikis Sunucusu Tunel API Referansi",
    ],

    "lead" => [
        "fa" => "ساخت و حذفِ کاربرانِ WireGuard-روی-TCP روی سرورِ اکسیتِ خودتان، از سامانهٔ خودتان. با فعال‌کردنِ اجرای خودکار، peer هم روی روترِ MikroTik شما ساخته می‌شود — بدونِ اینکه کسی دستوری را دستی اجرا کند.",
        "en" => "Create and remove WireGuard-over-TCP users on your own exit server, from your own system. With auto-apply enabled, the peer is also created on your MikroTik router, with nobody running a command by hand.",
        "tr" => "Kendi cikis sunucunuzdaki WireGuard-uzeri-TCP kullanicilarini kendi sisteminizden olusturun ve silin. Otomatik uygulama acikken peer, MikroTik yonlendiricinizde de olusturulur.",
    ],

    "meta_desc" => [
        "fa" => "مرجع API سرورنت برای مدیریت کاربران تونل WireGuard روی TCP: احراز با توکن، ساخت و حذف اکانت، کانفیگ آماده sing-box، و ایجنت روتر MikroTik برای اعمال خودکار peer.",
        "en" => "ServerNet API reference for managing WireGuard-over-TCP tunnel users: token auth, account create and delete, ready sing-box config, and the MikroTik router agent that applies peers automatically.",
        "tr" => "WireGuard-uzeri-TCP tunel kullanicilarini yonetmek icin ServerNet API referansi: token dogrulama, hesap olusturma ve silme, hazir sing-box yapilandirmasi ve MikroTik yonlendirici ajani.",
    ],

    "toc_title" => ["fa" => "در این صفحه", "en" => "On this page", "tr" => "Bu sayfada"],
    "print"     => ["fa" => "نسخهٔ PDF", "en" => "PDF version", "tr" => "PDF surumu"],
    "print_hint" => [
        "fa" => "نسخهٔ چاپی این صفحه در تبِ تازه باز می‌شود؛ از مرورگر «ذخیره به PDF» بگیرید.",
        "en" => "Opens a print-optimised copy in a new tab; use the browser Save as PDF.",
        "tr" => "Yazdirmaya uygun kopyayi yeni sekmede acar; tarayicidan PDF olarak kaydedin.",
    ],

    "back" => [
        "fa" => "مرجع API نمایندگی دامنه",
        "en" => "Domain Reseller API Reference",
        "tr" => "Alan Adi Bayilik API Referansi",
    ],

    // ═══════════════════════ ۱ — پیش‌نیاز ═══════════════════════

    "s1" => ["fa" => "پیش‌نیاز و احراز هویت", "en" => "Prerequisites and authentication", "tr" => "On kosullar ve kimlik dogrulama"],

    "s1_p" => [
        "fa" => "این رابط فقط برای سرورهای اکسیتی کار می‌کند که تونلِ TCP رویشان راه‌اندازی شده است. سروری که چنین پروفایلی ندارد اصلاً در فهرست ظاهر نمی‌شود — پس لازم نیست بدانید کدام سرور قابل است؛ خودِ فهرست جواب است.",
        "en" => "This interface only works for exit servers that have a TCP tunnel configured. A server without such a profile does not appear in the listing at all, so you never have to know which server is eligible: the listing itself is the answer.",
        "tr" => "Bu arayuz yalnizca TCP tuneli yapilandirilmis cikis sunuculari icin calisir. Boyle bir profili olmayan sunucu listede hic gorunmez.",
    ],

    "s1_steps" => [
        "fa" => [
            "در پنل کاربری خود به صفحهٔ امنیت بروید و یک توکن API بسازید.",
            "دو دسترسی tunnel:read و tunnel:write را تیک بزنید.",
            "در صورت نیاز، IP مجاز را روی همان توکن تعیین کنید تا فقط از سرور خودتان کار کند.",
            "توکن فقط یک بار نمایش داده می‌شود؛ همان‌جا ذخیره‌اش کنید.",
        ],
        "en" => [
            "Open the Security page in your client panel and issue an API token.",
            "Tick the two scopes tunnel:read and tunnel:write.",
            "If needed, set an IP allowlist on that token so it only works from your own server.",
            "The token is shown once; store it right there.",
        ],
        "tr" => [
            "Musteri panelinizde Guvenlik sayfasini acin ve bir API tokeni olusturun.",
            "tunnel:read ve tunnel:write kapsamlarini isaretleyin.",
            "Gerekirse tokene IP kisitlamasi ekleyin.",
            "Token yalnizca bir kez gosterilir; hemen kaydedin.",
        ],
    ],

    "s1_warn" => [
        "fa" => "محدودیت IP روی خودِ توکن می‌نشیند، نه روی حساب. اگر قواعد IP حساب را فعال کنید، خودتان را از مرورگر خودتان هم بیرون می‌اندازید.",
        "en" => "The IP allowlist lives on the token, not on the account. Enabling the account level IP rules would also lock you out of your own browser.",
        "tr" => "IP kisitlamasi hesapta degil tokenin uzerindedir. Hesap duzeyi IP kurallari kendi tarayicinizi da disarida birakir.",
    ],

    // ═══════════════════════ ۲ — شناسهٔ سرویس ═══════════════════════

    "s2" => ["fa" => "شناسهٔ سرویس خود را بگیرید", "en" => "Find your service id", "tr" => "Servis kimliginizi bulun"],

    "s2_p" => [
        "fa" => "همهٔ مسیرهای بعدی به یک شناسهٔ عددی نیاز دارند. آن را از این فراخوان بگیرید و در کد خود نگه دارید.",
        "en" => "Every later path needs a numeric id. Read it from this call and keep it in your code.",
        "tr" => "Sonraki tum yollar sayisal bir kimlik ister. Bu cagridan alin ve kodunuzda saklayin.",
    ],

    "s2_warn" => [
        "fa" => "این عدد با کد مشتری شما (مثل SN-571100) یکی نیست. کد مشتری شناسهٔ حساب است، نه شناسهٔ سرویس.",
        "en" => "This number is not your customer code (such as SN-571100). The customer code identifies the account, not the service.",
        "tr" => "Bu numara musteri kodunuz degildir. Musteri kodu hesabi tanimlar, servisi degil.",
    ],

    "s2_fields" => [
        "fa" => [
            "service_id" => "شناسه‌ای که در همهٔ مسیرهای بعدی جای {service} می‌نشیند",
            "host"       => "نام یا آدرسی که کاربر نهایی به آن وصل می‌شود",
            "subnet"     => "رنج داخلی سرور؛ آدرس هر کاربر از همین رنج داده می‌شود",
            "next_ip"    => "اولین آدرس آزاد — اگر ip ندهید همین انتخاب می‌شود",
            "writable"   => "اگر false باشد سرویس فعال نیست و اکانت تازه صادر نمی‌شود",
            "agent"      => "وضعیت اجرای خودکار روی روتر — بخش بعد",
        ],
        "en" => [
            "service_id" => "the id that replaces {service} in every later path",
            "host"       => "the name or address the end user connects to",
            "subnet"     => "the internal range of the server; every user address comes from it",
            "next_ip"    => "first free address, used when you omit ip",
            "writable"   => "false means the service is not active and no account is issued",
            "agent"      => "auto-apply status on the router, see the next section",
        ],
        "tr" => [
            "service_id" => "sonraki yollarda {service} yerine gecen kimlik",
            "host"       => "son kullanicinin baglandigi ad veya adres",
            "subnet"     => "sunucunun dahili araligi; her kullanici adresi buradan verilir",
            "next_ip"    => "ilk bos adres, ip vermezseniz bu secilir",
            "writable"   => "false ise servis etkin degildir ve yeni hesap verilmez",
            "agent"      => "yonlendiricide otomatik uygulama durumu",
        ],
    ],

    // ═══════════════════════ ۳ — ایجنت روتر ═══════════════════════

    "s3" => ["fa" => "اجرای خودکار روی روتر", "en" => "Auto-apply on the router", "tr" => "Yonlendiricide otomatik uygulama"],

    "s3_p" => [
        "fa" => "روتر شما از سمت ما قابل دسترسی نیست، پس ما به آن وصل نمی‌شویم — خودش می‌پرسد. با اجرای دو خط زیر روی روتر، یک اسکریپت و یک زمان‌بند نصب می‌شود که مرتب صف شما را می‌خواند و peerهای تازه را اعمال می‌کند. بدون این کار همه‌چیز کار می‌کند، فقط دستور روتر را باید خودتان اجرا کنید.",
        "en" => "Your router is not reachable from our side, so we never connect to it: it asks us. Running the two lines below installs a script and a scheduler that read your queue regularly and apply new peers. Without this, everything still works, you just run the router command yourself.",
        "tr" => "Yonlendiriciniz bizim tarafimizdan erisilebilir degil, bu yuzden biz baglanmayiz: o bize sorar. Asagidaki iki satir bir betik ve zamanlayici kurar; kuyrugunuzu duzenli okur ve yeni peerlari uygular.",
    ],

    "s3_steps" => [
        "fa" => [
            "با فراخوان زیر توکن ایجنت را بگیرید. پاسخ، همان دو خط آمادهٔ اجرا را هم می‌دهد.",
            "دو خط را در ترمینال روتر MikroTik خود اجرا کنید.",
            "بار اول چند ثانیه طول می‌کشد چون گواهی ریشه هم نصب می‌شود.",
            "پس از آن، وضعیت باید alive شود.",
        ],
        "en" => [
            "Fetch the agent token with the call below; the response also returns the two ready to run lines.",
            "Run those two lines in your MikroTik router terminal.",
            "The first run takes a few seconds because the root certificate is installed too.",
            "After that the status should turn alive.",
        ],
        "tr" => [
            "Asagidaki cagri ile ajan tokenini alin; yanit calistirmaya hazir iki satiri da doner.",
            "Bu iki satiri MikroTik terminalinizde calistirin.",
            "Ilk calisma birkac saniye surer cunku kok sertifika da kurulur.",
            "Sonrasinda durum alive olmalidir.",
        ],
    ],

    "s3_warn" => [
        "fa" => "هر بار که این فراخوان را بزنید، توکن قبلی همان لحظه باطل می‌شود و تا اجرای دو خط تازه روی روتر، اجرای خودکار قطع است. پس اول ترمینال روتر را باز کنید، بعد این را بزنید.",
        "en" => "Each call to this endpoint revokes the previous token immediately, and auto-apply stays down until the two new lines run on the router. Open the router terminal first, then make the call.",
        "tr" => "Bu uc noktaya her cagri onceki tokeni aninda iptal eder. Once yonlendirici terminalini acin, sonra cagriyi yapin.",
    ],

    "s3_alive" => [
        "fa" => "installed یعنی توکن صادر شده؛ alive یعنی روتر واقعاً در دقایق اخیر تماس گرفته. این دو عمداً جدا هستند: ایجنتی که نصب است ولی خاموش، از نظر شما کار نمی‌کند و یک برچسب واحد آن را پنهان می‌کرد.",
        "en" => "installed means a token was issued; alive means the router actually checked in recently. The two are deliberately separate: an agent that is installed but silent does not work from your point of view, and a single label would hide that.",
        "tr" => "installed token verildigi anlamina gelir; alive yonlendiricinin gercekten yakin zamanda baglandigi anlamina gelir. Ikisi bilerek ayridir.",
    ],

    "s3_safe" => [
        "fa" => "سرور هرگز «دستور» به روتر نمی‌فرستد. پاسخ فقط سه مقدار دارد — نام، آدرس و کلید عمومی — و اسکریپت هرکدام را جداگانه اعتبارسنجی می‌کند و خودش دستور را می‌سازد. آدرس هم فقط از داخل رنج خود شما پذیرفته می‌شود.",
        "en" => "The server never sends the router a command. The reply carries only three values, name, address and public key; the script validates each one separately and builds the command itself. The address is only accepted from inside your own range.",
        "tr" => "Sunucu yonlendiriciye asla komut gondermez. Yanit yalnizca ad, adres ve genel anahtar tasir; betik her birini dogrular ve komutu kendisi olusturur.",
    ],

    // ═══════════════════════ ۴ — ساخت اکانت ═══════════════════════

    "s4" => ["fa" => "ساخت کاربر", "en" => "Create a user", "tr" => "Kullanici olusturma"],

    "s4_in" => ["fa" => "ورودی‌ها", "en" => "Input fields", "tr" => "Giris alanlari"],

    "s4_fields" => [
        "fa" => [
            "name"   => "اجباری — ۲ تا ۲۴ نویسهٔ لاتین کوچک، رقم، خط‌تیره یا زیرخط",
            "ip"     => "اختیاری — ندهید تا آدرس آزاد بعدی خودکار انتخاب شود",
            "format" => "اختیاری — singbox پیش‌فرض است؛ legacy برای اپ‌های قدیمی‌تر",
        ],
        "en" => [
            "name"   => "required, 2 to 24 lowercase latin letters, digits, hyphen or underscore",
            "ip"     => "optional, omit it and the next free address is chosen",
            "format" => "optional, singbox is the default; legacy is for older apps",
        ],
        "tr" => [
            "name"   => "zorunlu, 2 ile 24 kucuk latin harfi, rakam, tire veya alt cizgi",
            "ip"     => "istege bagli, vermezseniz siradaki bos adres secilir",
            "format" => "istege bagli, varsayilan singbox; eski uygulamalar icin legacy",
        ],
    ],

    "s4_key" => [
        "fa" => "کلید خصوصی فقط در همین یک پاسخ برمی‌گردد و هرگز ذخیره نمی‌شود. اگر نگهش ندارید، راهی برای بازیابی نیست و باید کاربر را حذف و دوباره بسازید.",
        "en" => "The private key is returned in this one response and is never stored. If you do not keep it there is no way to recover it; you delete the user and create it again.",
        "tr" => "Ozel anahtar yalnizca bu yanitta doner ve asla saklanmaz. Saklamazsaniz geri getirilemez.",
    ],

    "s4_cfg" => [
        "fa" => "میدان config یک کانفیگ کامل sing-box است؛ آن را مستقیم به کاربر نهایی بدهید تا با پسوند json ذخیره و در برنامه‌اش import کند. لازم نیست خودتان چیزی بسازید.",
        "en" => "The config field is a complete sing-box configuration; hand it to the end user to save with a json extension and import into their app. You do not have to build anything.",
        "tr" => "config alani eksiksiz bir sing-box yapilandirmasidir; son kullaniciya verin, json olarak kaydedip uygulamasina aktarsin.",
    ],

    "s4_delivery" => [
        "fa" => "میدان delivery می‌گوید کار کجاست: mode برابر agent یعنی در صف روتر نشست و ظرف چند ثانیه ساخته می‌شود؛ mode برابر manual یعنی ایجنت نصب نیست و باید router_command را خودتان روی روتر اجرا کنید. router_command در هر دو حالت برمی‌گردد، چون ایجنت ممکن است خاموش باشد و آن خط تنها راه نجات در همان لحظه است.",
        "en" => "The delivery field says where the work is: mode agent means it is queued for the router and will be created within seconds; mode manual means no agent is installed and you run router_command yourself. router_command is returned in both cases, because the agent may be down and that line is then the only way out.",
        "tr" => "delivery alani isin nerede oldugunu soyler: agent kuyruga alindi demektir; manual ajan kurulu degil demektir. router_command her iki durumda da doner.",
    ],

    "s4_201" => [
        "fa" => "کد ۲۰۱ به‌تنهایی یعنی «ثبت شد»، نه «کاربر وصل می‌شود». تا وقتی وضعیت اکانت به active نرسیده، آن کانفیگ کار نمی‌کند.",
        "en" => "A 201 alone means recorded, not connected. Until the account state reaches active, that configuration does not work.",
        "tr" => "Tek basina 201 kaydedildi demektir, baglanti kuruldu degil. Hesap durumu active olana kadar yapilandirma calismaz.",
    ],

    // ═══════════════════════ ۵ — وضعیت ═══════════════════════

    "s5" => ["fa" => "فهرست و وضعیت کاربران", "en" => "List users and their state", "tr" => "Kullanicilari ve durumlarini listeleme"],

    "s5_p" => [
        "fa" => "هر کاربر یک میدان state دارد که همان چیزی را می‌گوید که کاربر نهایی تجربه می‌کند، نه یک برچسب داخلی.",
        "en" => "Every user carries a state field that reports what the end user experiences, not an internal label.",
        "tr" => "Her kullanicinin, dahili bir etiketi degil son kullanicinin deneyimini bildiren bir state alani vardir.",
    ],

    "s5_states" => [
        "fa" => [
            "active"  => "روی روتر نشسته و کاربر وصل می‌شود",
            "pending" => "هنوز در صف روتر است — چند ثانیه صبر کنید",
            "failed"  => "روتر نپذیرفت یا ایجنت اجرا نشد؛ لاگ روتر را ببینید",
        ],
        "en" => [
            "active"  => "applied on the router, the user connects",
            "pending" => "still queued for the router, wait a few seconds",
            "failed"  => "the router refused it or the agent never ran; check the router log",
        ],
        "tr" => [
            "active"  => "yonlendiricide uygulandi, kullanici baglanir",
            "pending" => "hala kuyrukta, birkac saniye bekleyin",
            "failed"  => "yonlendirici reddetti veya ajan calismadi; kaydi kontrol edin",
        ],
    ],

    "s5_legacy" => [
        "fa" => "کاربرانی که پیش از راه‌اندازی این صف ساخته شده‌اند هیچ ردیف کاری ندارند و عمداً active گزارش می‌شوند، نه نامعلوم — همه‌شان سالم روی روتر نشسته‌اند.",
        "en" => "Users created before this queue existed have no job row and are deliberately reported as active rather than unknown: they are all sitting correctly on the router.",
        "tr" => "Bu kuyruktan once olusturulan kullanicilarin is kaydi yoktur ve bilerek active olarak bildirilir.",
    ],

    // ═══════════════════════ ۶ — حذف ═══════════════════════

    "s6" => ["fa" => "حذف کاربر", "en" => "Delete a user", "tr" => "Kullanici silme"],

    "s6_p" => [
        "fa" => "با ایجنت فعال، peer خودش از روتر برداشته می‌شود. بدون ایجنت، تا وقتی router_command حذف را اجرا نکنید آن کاربر همچنان وصل می‌شود — حذف از فهرست به‌تنهایی دسترسی را قطع نمی‌کند.",
        "en" => "With the agent enabled the peer is removed from the router for you. Without it, that user keeps connecting until you run the removal router_command: deleting from the listing alone does not cut access.",
        "tr" => "Ajan etkinken peer yonlendiriciden sizin icin kaldirilir. Ajan yoksa, silme komutunu calistirana kadar kullanici baglanmaya devam eder.",
    ],

    // ═══════════════════════ ۷ — خطاها ═══════════════════════

    "s7" => ["fa" => "خطاها و سقف‌ها", "en" => "Errors and limits", "tr" => "Hatalar ve sinirlar"],

    "s7_p" => [
        "fa" => "پاسخ ناموفق همیشه یک کد ماشین‌خوان در میدان error دارد. به متن message تکیه نکنید؛ ممکن است عوض شود.",
        "en" => "A failed response always carries a machine readable code in the error field. Do not depend on the message text, it may change.",
        "tr" => "Basarisiz yanit her zaman error alaninda makine tarafindan okunabilir bir kod tasir.",
    ],

    "s7_errors" => [
        "fa" => [
            "insufficient_scope" => "توکن دسترسی لازم را ندارد",
            "not_found"          => "چنین سرور یا اکانتی در حساب شما نیست",
            "service_not_active" => "سرویس تعلیق، منقضی یا لغو شده است",
            "bad_name"           => "نام با قالب مجاز نمی‌خواند",
            "name_taken"         => "اکانتی با این نام از قبل هست",
            "bad_ip"             => "آدرس از رنج داخلی همین سرور نیست",
            "ip_taken"           => "این آدرس قبلاً داده شده است",
            "limit_reached"      => "به سقف تعداد اکانت رسیده‌اید",
        ],
        "en" => [
            "insufficient_scope" => "the token lacks the required scope",
            "not_found"          => "no such server or account in your account",
            "service_not_active" => "the service is suspended, expired or cancelled",
            "bad_name"           => "the name does not match the allowed pattern",
            "name_taken"         => "an account with this name already exists",
            "bad_ip"             => "the address is not from this server internal range",
            "ip_taken"           => "that address is already in use",
            "limit_reached"      => "you reached the account limit",
        ],
        "tr" => [
            "insufficient_scope" => "token gerekli kapsama sahip degil",
            "not_found"          => "hesabinizda boyle bir sunucu veya hesap yok",
            "service_not_active" => "servis askida, suresi dolmus veya iptal",
            "bad_name"           => "ad izin verilen bicime uymuyor",
            "name_taken"         => "bu adla bir hesap zaten var",
            "bad_ip"             => "adres bu sunucunun dahili araliginda degil",
            "ip_taken"           => "bu adres zaten kullanimda",
            "limit_reached"      => "hesap sinirina ulastiniz",
        ],
    ],

    "s7_limits" => ["fa" => "سقف نرخ", "en" => "Rate limits", "tr" => "Hiz sinirlari"],

    "s7_rows" => [
        "fa" => ["read" => "خواندن", "write" => "ساخت و حذف", "agent" => "صدور توکن ایجنت", "peers" => "حداکثر کاربر روی هر سرور", "min" => "دقیقه"],
        "en" => ["read" => "read", "write" => "create and delete", "agent" => "agent token issue", "peers" => "maximum users per server", "min" => "min"],
        "tr" => ["read" => "okuma", "write" => "olusturma ve silme", "agent" => "ajan tokeni", "peers" => "sunucu basina kullanici", "min" => "dk"],
    ],
];
