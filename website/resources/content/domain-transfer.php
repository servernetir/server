<?php

/**
 * صفحهٔ عمومیِ انتقالِ دامنه — سه‌زبانه.
 *
 * ⚠️ رشته‌ها دو-نقل‌قولی‌اند چون متنِ انگلیسی و ترکی آپاستروف دارد. پس داخلِ
 * متن‌ها از نویسه‌های  "  و  $  استفاده نکنید.
 *
 * ⚠️ هیچ قیمتی این‌جا نیست. قیمتِ انتقال per-TLD است و از `TldPriceBook`
 * می‌آید؛ عددِ تایپ‌شده در یک صفحهٔ بازاریابی، اولین باری که نرخِ ارز بپرد
 * دروغ می‌گوید — و روی دامنه که حاشیه‌اش کم است، همان دروغ ضرر است.
 */

return [

    "badge" => ["fa" => "انتقال دامنه", "en" => "Domain transfer", "tr" => "Alan adi transferi"],

    "title" => [
        "fa" => "دامنه‌تان را به سرورنت منتقل کنید",
        "en" => "Move your domain to ServerNet",
        "tr" => "Alan adinizi ServerNet e tasiyin",
    ],

    "lead" => [
        "fa" => "انتقال، دامنه را خاموش نمی‌کند: سایت و ایمیل شما در تمامِ مدت بالا می‌مانند و یک سال به اعتبارِ دامنه اضافه می‌شود.",
        "en" => "A transfer does not take your domain offline: your site and email keep running throughout, and one year is added to the registration.",
        "tr" => "Transfer alan adinizi kapatmaz: siteniz ve e-postaniz calismaya devam eder ve kayda bir yil eklenir.",
    ],

    "meta_desc" => [
        "fa" => "انتقال دامنه به سرورنت بدون قطعی سایت و ایمیل، با یک سال تمدید رایگان، پنل فارسی و پشتیبانی شبانه‌روزی. راهنمای گام‌به‌گام و پیش‌نیازها.",
        "en" => "Transfer your domain to ServerNet with no downtime for your site or email, one extra year included, and 24/7 support. Step-by-step guide and requirements.",
        "tr" => "Alan adinizi kesintisiz olarak ServerNet e tasiyin: bir yil ek sure ve 7/24 destek dahil.",
    ],

    "cta" => ["fa" => "شروع انتقال", "en" => "Start the transfer", "tr" => "Transferi baslat"],
    "cta_note" => [
        "fa" => "برای شروع باید وارد حساب کاربری شوید.",
        "en" => "You need to sign in to start.",
        "tr" => "Baslamak icin giris yapmalisiniz.",
    ],

    // ═══════════ چرا ═══════════

    "why" => ["fa" => "چرا منتقل کنید", "en" => "Why move", "tr" => "Neden tasimali"],

    "why_items" => [
        "fa" => [
            ["یک سال اضافه می‌شود", "انتقالِ موفق یک سال به اعتبارِ دامنه اضافه می‌کند؛ پس هزینه‌ای که می‌دهید عملاً هزینهٔ تمدید است، نه هزینهٔ جابه‌جایی."],
            ["سایت و ایمیل قطع نمی‌شود", "نام‌سرورهای فعلیِ شما دست‌نخورده منتقل می‌شوند. تا وقتی خودتان تغییرشان ندهید، هیچ‌چیز جابه‌جا نمی‌شود."],
            ["مدیریت از یک پنل", "دامنه، هاست و سرورتان در یک حساب و یک صورت‌حساب جمع می‌شوند و سررسیدها را یک‌جا می‌بینید."],
            ["یادآوریِ تمدید", "پیش از سررسید فاکتور صادر و به شما اطلاع داده می‌شود؛ دامنه‌ای که فراموش شود از دست می‌رود."],
        ],
        "en" => [
            ["One year is added", "A completed transfer adds a year to the registration, so what you pay is effectively a renewal rather than a moving fee."],
            ["No downtime", "Your current nameservers move across untouched. Nothing changes until you decide to change it."],
            ["One panel, one invoice", "Domains, hosting and servers sit in one account with a single billing history and one place to see every due date."],
            ["Renewal reminders", "We issue the invoice and notify you before expiry. A forgotten domain is a lost domain."],
        ],
        "tr" => [
            ["Bir yil eklenir", "Tamamlanan transfer kayda bir yil ekler; odediginiz tutar tasima ucreti degil, fiilen yenileme ucretidir."],
            ["Kesinti olmaz", "Mevcut ad sunucularınız oldugu gibi tasinir. Siz degistirene kadar hicbir sey degismez."],
            ["Tek panel, tek fatura", "Alan adi, hosting ve sunucular tek hesapta ve tek fatura gecmisinde toplanir."],
            ["Yenileme hatirlatmasi", "Bitis tarihinden once fatura kesilir ve size bildirilir."],
        ],
    ],

    // ═══════════ پیش‌نیاز ═══════════

    "need" => ["fa" => "پیش از شروع", "en" => "Before you start", "tr" => "Baslamadan once"],

    "need_p" => [
        "fa" => "این چهار مورد را نزدِ رجیسترارِ فعلی‌تان آماده کنید. اگر یکی‌شان نباشد، رجیستری درخواست را رد می‌کند و فقط وقت تلف می‌شود.",
        "en" => "Prepare these four things at your current registrar. If any is missing the registry rejects the request and the only cost is lost time.",
        "tr" => "Mevcut kayit sirketinizde bu dort seyi hazirlayin. Biri eksikse registry istegi reddeder.",
    ],

    "need_items" => [
        "fa" => [
            ["قفلِ انتقال باز باشد", "در پنلِ رجیسترارِ فعلی گزینهٔ Transfer Lock یا clientTransferProhibited را خاموش کنید."],
            ["کدِ انتقال (EPP/Auth Code)", "همان‌جا کد را بگیرید. این کد کلیدِ مالکیتِ دامنه است — آن را در گفتگوی عمومی یا ایمیلِ بی‌رمز نفرستید."],
            ["ایمیلِ مالک در دسترس باشد", "رجیستری برای تأیید به نشانیِ ثبت‌شده در WHOIS پیام می‌فرستد."],
            ["دامنه بیش از ۶۰ روز عمر داشته باشد", "قانونِ ICANN: دامنهٔ تازه‌ثبت‌شده یا تازه‌منتقل‌شده تا ۶۰ روز قابلِ انتقال نیست."],
        ],
        "en" => [
            ["Transfer lock off", "In your current registrar panel, disable Transfer Lock (clientTransferProhibited)."],
            ["Authorization code (EPP)", "Obtain it from the same panel. This code is the ownership key — never send it over public chat or unencrypted email."],
            ["Access to the registrant email", "The registry sends the approval request to the address on the WHOIS record."],
            ["Domain older than 60 days", "ICANN rule: a newly registered or recently transferred domain cannot move for 60 days."],
        ],
        "tr" => [
            ["Transfer kilidi kapali", "Mevcut panelinizde Transfer Lock secenegini kapatin."],
            ["Yetki kodu (EPP)", "Ayni panelden alin. Bu kod sahiplik anahtaridir — herkese acik kanallardan gondermeyin."],
            ["Kayit sahibi e-postasina erisim", "Registry onay istegini WHOIS kaydindaki adrese gonderir."],
            ["Alan adi 60 gunden eski", "ICANN kurali: yeni kaydedilmis alan adi 60 gun tasinamaz."],
        ],
    ],

    // ═══════════ مراحل ═══════════

    "how" => ["fa" => "مراحل انتقال", "en" => "How it works", "tr" => "Nasil calisir"],

    "how_steps" => [
        "fa" => [
            ["نام دامنه را وارد کنید", "بررسی می‌کنیم که پسوندش را می‌فروشیم و دامنه واقعاً ثبت‌شده است. تا این‌جا هیچ مبلغی درگیر نمی‌شود."],
            ["فاکتور را پرداخت کنید", "هزینهٔ انتقال شاملِ یک سال تمدید است. رجیسترار در لحظهٔ ثبتِ درخواست هزینه را برمی‌دارد، پس پرداخت باید جلوتر باشد."],
            ["کدِ انتقال را وارد کنید", "در صفحهٔ همان دامنه در پنل. کد را نگه نمی‌داریم؛ در همان لحظه به رجیسترار می‌رود و از حافظه بیرون می‌رود."],
            ["تأیید و تحویل", "رجیسترارِ فعلی معمولاً تا ۵ روزِ کاری تأیید می‌کند. نتیجه — چه موفق چه ناموفق — به شما اطلاع داده می‌شود."],
        ],
        "en" => [
            ["Enter the domain", "We verify that we sell the extension and that the domain really is registered. No money is involved at this point."],
            ["Pay the invoice", "The transfer fee includes one year of renewal. The registrar charges us the moment the request is filed, so payment comes first."],
            ["Enter the authorization code", "On that domain page in your panel. We do not retain the code; it goes to the registrar in the same request and leaves memory."],
            ["Approval and handover", "The losing registrar usually approves within five business days. You are notified of the outcome either way."],
        ],
        "tr" => [
            ["Alan adini girin", "Uzantiyi sattigimizi ve alan adinin gercekten kayitli oldugunu dogrularız. Bu asamada odeme yoktur."],
            ["Faturayi odeyin", "Transfer ucreti bir yillik yenilemeyi icerir. Kayit sirketi istek anında ucreti alir."],
            ["Yetki kodunu girin", "Panelde ilgili alan adi sayfasindan. Kodu saklamayiz."],
            ["Onay ve teslim", "Mevcut kayit sirketi genellikle bes is gunu icinde onaylar."],
        ],
    ],

    // ═══════════ صداقت ═══════════

    "honest" => ["fa" => "چیزهایی که باید بدانید", "en" => "What we will not hide", "tr" => "Gizlemedigimiz seyler"],

    "honest_items" => [
        "fa" => [
            "انتقال آنی نیست. پنجرهٔ تأییدِ رجیستری تا ۵ روزِ کاری است و در اختیارِ ما نیست.",
            "اگر رجیسترارِ فعلی یا مالک، درخواست را رد کند، انتقال انجام نمی‌شود و **کلِ مبلغ به اعتبارِ حسابِ شما بازمی‌گردد**.",
            "پسوندهای ملیِ ایران (.ir و مشتقاتش) از این مسیر منتقل نمی‌شوند؛ آن‌ها مستقیماً نزدِ ایرنیک مدیریت می‌شوند.",
            "برخی پسوندها یک سال به اعتبار اضافه نمی‌کنند (قاعدهٔ خودِ رجیستری است، نه سیاستِ ما). پیش از پرداخت، همین صفحه قیمت و شرایطِ همان پسوند را نشان می‌دهد.",
        ],
        "en" => [
            "A transfer is not instant. The registry approval window is up to five business days and is not under our control.",
            "If the losing registrar or the registrant refuses, the transfer does not happen and **the full amount returns to your account credit**.",
            "Iranian national extensions (.ir and its subdomains) are not transferred through this channel; they are managed directly at IRNIC.",
            "A few extensions do not add a year on transfer — that is the registry policy, not ours. The price and terms for your specific extension are shown before you pay.",
        ],
        "tr" => [
            "Transfer aninda degildir. Registry onay penceresi bes is gunune kadardir ve bizim kontrolumuzde degildir.",
            "Reddedilirse transfer gerceklesmez ve **tutarin tamami hesap kredinize iade edilir**.",
            "Iran ulusal uzantilari bu kanaldan tasinmaz.",
            "Bazi uzantilar transferde bir yil eklemez; bu registry politikasidir.",
        ],
    ],

    // ═══════════ پرسش‌ها ═══════════

    "faq" => ["fa" => "پرسش‌های پرتکرار", "en" => "Frequently asked", "tr" => "Sik sorulanlar"],

    "faqs" => [
        "fa" => [
            ["در مدتِ انتقال سایتم قطع می‌شود؟", "خیر. نام‌سرورها و رکوردهای DNS شما تغییری نمی‌کنند و انتقال فقط مالکیتِ ثبت را جابه‌جا می‌کند. سایت و ایمیل در تمامِ مدت بالا می‌مانند."],
            ["اعتبارِ باقی‌ماندهٔ دامنه‌ام از بین می‌رود؟", "خیر. تاریخِ انقضای فعلی حفظ می‌شود و یک سال به آن اضافه می‌شود."],
            ["کدِ انتقال را نگه می‌دارید؟", "خیر، و این تصمیمِ آگاهانه است. آن کد کلیدِ مالکیتِ دامنه است؛ در همان درخواست به رجیسترار می‌رود و هیچ‌جا ذخیره نمی‌شود. اگر انتقال رد شد، دوباره از شما می‌پرسیم."],
            ["اگر انتقال انجام نشود چه می‌شود؟", "مبلغِ پرداختی به‌طور کامل به اعتبارِ حسابِ شما بازمی‌گردد و می‌توانید از همان اعتبار برای هر سرویسِ دیگری استفاده کنید یا درخواست را دوباره بفرستید."],
            ["چند دامنه را هم‌زمان می‌توانم منتقل کنم؟", "محدودیتی ندارد؛ هر دامنه فاکتور و کدِ انتقالِ خودش را دارد."],
        ],
        "en" => [
            ["Will my site go down during the transfer?", "No. Your nameservers and DNS records are unchanged; a transfer only moves the registration. Site and email keep running throughout."],
            ["Do I lose the time left on my domain?", "No. The current expiry date is preserved and one year is added on top."],
            ["Do you store the authorization code?", "No, and that is deliberate. The code is the ownership key; it goes to the registrar in the same request and is never persisted. If the transfer is refused we ask you for it again."],
            ["What if the transfer fails?", "The full amount returns to your account credit. You can spend it on any other service or file the request again."],
            ["How many domains can I transfer at once?", "There is no limit; each domain has its own invoice and its own authorization code."],
        ],
        "tr" => [
            ["Transfer sirasinda sitem kapanir mi?", "Hayir. Ad sunuculariniz ve DNS kayitlariniz degismez."],
            ["Kalan surem kaybolur mu?", "Hayir. Mevcut bitis tarihi korunur ve uzerine bir yil eklenir."],
            ["Yetki kodunu sakliyor musunuz?", "Hayir, bu bilincli bir karardir. Kod sahiplik anahtaridir ve hicbir yerde saklanmaz."],
            ["Transfer basarisiz olursa?", "Tutarin tamami hesap kredinize doner."],
            ["Ayni anda kac alan adi tasiyabilirim?", "Sinir yoktur; her alan adinin kendi faturasi ve kodu vardir."],
        ],
    ],

    "search_cta" => ["fa" => "ثبت دامنهٔ جدید", "en" => "Register a new domain", "tr" => "Yeni alan adi kaydet"],

    "form_label" => ["fa" => "نام دامنه", "en" => "Domain name", "tr" => "Alan adi"],
];
