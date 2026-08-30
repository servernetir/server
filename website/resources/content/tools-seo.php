<?php

/**
 * محتوای سئوی صفحات «ابزارهای رایگان» (/tools/{slug}).
 *
 * ساختار:  slug => [ locale => ['intro' => string, 'steps' => [], 'faq' => [ ['q','a'] ]] ]
 *
 * ⚠️ خواهرِ `webtools-seo.php` است و **عمداً** فایلِ جداست: آن یکی مالِ ۴۸ ابزارِ
 * سمت‌مرورگرِ /webtools است و این مالِ ابزارهای سمت‌سرورِ /tools. یکی‌کردنشان یعنی
 * تصادمِ اسلاگ (هر دو ممکن است روزی `ip` داشته باشند).
 *
 * اسلاگی که این‌جا نیاید، آن بخش از صفحه‌اش اصلاً رندر نمی‌شود — پس افزودنِ
 * تدریجی امن است.
 *
 * نکته: همه‌ی رشته‌ها دو-نقل‌قولی هستند چون متن ترکی و انگلیسی آپاستروف دارد
 * (doesn not / adresinizi) و در رشته‌ی تک‌نقل‌قولی PHP را می‌شکند.
 * پس داخل متن‌ها از نویسه‌های  "  و  $  استفاده نکنید.
 */

return [

    "whois" => [
        "fa" => [
            "intro" => "Whois دفترِ ثبتِ عمومیِ دامنه‌هاست: می‌گوید یک دامنه ثبت شده یا آزاد است، چه تاریخی ثبت شده، کِی منقضی می‌شود، از کدام شرکتِ ثبت‌کننده گرفته شده و روی چه نام‌سرورهایی نشسته. این ابزار همان اطلاعات را مستقیم از سرورِ Whois همان پسوند می‌گیرد و خام و دست‌نخورده هم نشانتان می‌دهد. نکته‌ای که خیلی‌ها را غافلگیر می‌کند: از سالِ ۲۰۱۸ و با اجرای GDPR، بیشترِ رجیسترارها نام و ایمیل و تلفنِ مالک را پنهان می‌کنند؛ پس خالی‌بودنِ آن فیلدها به‌معنای اشکال در دامنه نیست.",
            "steps" => [
                "نامِ دامنه را با پسوندش بنویسید — مثلاً example.com یا example.ir.",
                "دکمهٔ استعلام را بزنید؛ پاسخ مستقیم از سرورِ Whois همان پسوند خوانده می‌شود.",
                "وضعیت را ببینید: اگر آزاد بود می‌توانید همان‌جا ثبتش کنید، و اگر گرفته شده بود نام‌های مشابهِ آزاد را جستجو کنید.",
                "برای جزئیاتِ بیشتر، «نمایش پاسخ کامل» را باز کنید — همان متنی که رجیسترار برمی‌گرداند.",
            ],
            "faq" => [
                ["q" => "چرا نام و اطلاعاتِ مالک نمایش داده نمی‌شود؟", "a" => "به‌خاطر قانونِ حریمِ خصوصیِ اروپا (GDPR) بیشترِ رجیسترارها از سال ۲۰۱۸ اطلاعاتِ شخصیِ مالک را از پاسخِ عمومیِ Whois حذف می‌کنند. این پنهان‌سازی عمدی است و ربطی به سلامتِ دامنه ندارد."],
                ["q" => "تاریخِ انقضا گذشته ولی دامنه هنوز کار می‌کند — چرا؟", "a" => "بعد از انقضا یک دورهٔ مهلت (معمولاً ۳۰ روز) و بعد دورهٔ بازیابی (حدود ۳۰ روز دیگر) هست. در این مدت دامنه هنوز مالِ صاحبِ قبلی است و آزاد نمی‌شود؛ برای همین «منقضی» با «قابلِ ثبت» یکی نیست."],
                ["q" => "نوشته آزاد است ولی موقعِ ثبت می‌گوید گرفته شده. چرا؟", "a" => "سه دلیلِ رایج: دامنه همین چند دقیقه پیش ثبت شده و Whois هنوز به‌روز نشده، دامنه رزروِ رجیستری است (کلمات ممنوعه یا نام‌های کوتاه)، یا پسوندِ خاصی است که شرایطِ ویژه دارد. استعلامِ لحظهٔ خرید همیشه معتبرتر از Whois است."],
                ["q" => "Whois با DNS چه فرقی دارد؟", "a" => "Whois دربارهٔ **مالکیت** حرف می‌زند (چه کسی، از کِی، تا کِی)، ولی DNS دربارهٔ **مسیر** است (این دامنه به کدام سرور اشاره می‌کند). برای بررسی رکوردهای DNS از ابزار جداگانهٔ ما استفاده کنید."],
                ["q" => "دامنهٔ .ir هم پشتیبانی می‌شود؟", "a" => "بله. پاسخِ دامنه‌های .ir از سرورِ Whois ایرنیک خوانده می‌شود و ساختارش با پسوندهای بین‌المللی کمی فرق دارد، ولی همان اطلاعاتِ اصلی را دارد."],
                ["q" => "چطور بفهمم دامنه‌ای که می‌خواهم بخرم سابقهٔ بد ندارد؟", "a" => "تاریخِ ثبتِ خیلی قدیمی با نام‌سرورهای متعدد و تغییرات مکرر می‌تواند نشانهٔ دامنهٔ دستِ‌دوم باشد. پیش از خرید، نامِ دامنه را در گوگل و در آرشیوِ اینترنت هم جستجو کنید تا از کاربردِ قبلی‌اش مطمئن شوید."],
            ],
        ],
        "en" => [
            "intro" => "Whois is the public registry of domain names: it tells you whether a name is taken or free, when it was registered, when it expires, which registrar holds it and which nameservers it points to. This tool reads that answer straight from the Whois server of the relevant extension and shows you the raw response too. One thing that surprises people: since GDPR took effect in 2018, most registrars redact the owner name, email and phone from public Whois — so empty owner fields are not a fault in the domain.",
            "steps" => [
                "Type the domain with its extension — for example example.com or example.ir.",
                "Press the lookup button; the answer comes straight from that extension Whois server.",
                "Read the status: if it is free you can register it right here, and if it is taken you can search for similar available names.",
                "Open the full response for the complete text the registrar returned.",
            ],
            "faq" => [
                ["q" => "Why is the owner information hidden?", "a" => "Because of the EU privacy regulation (GDPR), most registrars have redacted personal owner details from public Whois since 2018. The redaction is deliberate and says nothing about the health of the domain."],
                ["q" => "The expiry date has passed but the domain still works. Why?", "a" => "After expiry there is a grace period (usually 30 days) and then a redemption period (roughly another 30). During that window the domain still belongs to its previous owner and does not become available, which is why expired and registrable are not the same thing."],
                ["q" => "It says free, but registration fails as taken. Why?", "a" => "Three common causes: it was registered minutes ago and Whois has not caught up, it is reserved by the registry (blocked words or very short names), or the extension has special eligibility rules. The check at purchase time is always more authoritative than Whois."],
                ["q" => "How is Whois different from DNS?", "a" => "Whois is about ownership — who, since when, until when. DNS is about routing — where this name points. Use our separate DNS tools to inspect records."],
                ["q" => "Are .ir domains supported?", "a" => "Yes. Answers for .ir come from the IRNIC Whois server; the layout differs slightly from international extensions but carries the same core facts."],
                ["q" => "How do I check a domain I want to buy has no bad history?", "a" => "A very old registration date with many nameserver changes can indicate a second-hand domain. Before buying, search the name on Google and in the Internet Archive to see what it was used for."],
            ],
        ],
        "tr" => [
            "intro" => "Whois, alan adlarinin kamuya acik kaydidir: bir adin alinmis mi bos mu oldugunu, ne zaman kaydedildigini, ne zaman sona erecegini, hangi kayit sirketinde oldugunu ve hangi ad sunucularina isaret ettigini soyler. Bu arac cevabi dogrudan ilgili uzantinin Whois sunucusundan okur ve ham yaniti da gosterir. Cogu kisiyi sasirtan bir nokta: 2018 de GDPR yururluge girdiginden beri cogu kayit sirketi sahip adini, e-postasini ve telefonunu gizler; bu alanlarin bos olmasi alan adinda bir sorun oldugu anlamina gelmez.",
            "steps" => [
                "Alan adini uzantisiyla birlikte yazin — ornegin example.com veya example.ir.",
                "Sorgula dugmesine basin; yanit dogrudan o uzantinin Whois sunucusundan gelir.",
                "Durumu okuyun: bos ise burada kaydedebilir, alinmis ise benzer musait adlari arayabilirsiniz.",
                "Kayit sirketinin dondurdugu tam metin icin tam yaniti acin.",
            ],
            "faq" => [
                ["q" => "Sahip bilgileri neden gizli?", "a" => "AB gizlilik duzenlemesi (GDPR) nedeniyle cogu kayit sirketi 2018 den beri kisisel sahip bilgilerini kamuya acik Whois ten kaldiriyor. Bu kasitlidir ve alan adinin durumu hakkinda bir sey soylemez."],
                ["q" => "Bitis tarihi gecti ama alan adi hala calisiyor. Neden?", "a" => "Bitisten sonra bir odeme suresi (genelde 30 gun) ve ardindan kurtarma suresi (yaklasik 30 gun daha) vardir. Bu surede alan adi hala onceki sahibine aittir ve bosa dusmez."],
                ["q" => "Bos gorunuyor ama kayit sirasinda alinmis diyor. Neden?", "a" => "Uc yaygin neden: dakikalar once kaydedilmis ve Whois henuz guncellenmemis, kayit kurulusunca rezerve edilmis, ya da uzantinin ozel kosullari var. Satin alma anindaki kontrol her zaman daha guvenilirdir."],
                ["q" => "Whois ile DNS arasindaki fark nedir?", "a" => "Whois sahiplik hakkindadir; DNS ise yonlendirme hakkinda. Kayitlari incelemek icin ayri DNS araclarimizi kullanin."],
                ["q" => ".ir alan adlari destekleniyor mu?", "a" => "Evet. .ir yanitlari IRNIC Whois sunucusundan gelir; duzeni biraz farklidir ama ayni temel bilgileri tasir."],
                ["q" => "Alacagim alan adinin kotu bir gecmisi olmadigini nasil anlarim?", "a" => "Cok eski bir kayit tarihi ve sik ad sunucusu degisiklikleri ikinci el bir alan adina isaret edebilir. Satin almadan once adi Google da ve Internet Archive de aratin."],
            ],
        ],
    ],

    "ip" => [
        "fa" => [
            "intro" => "هر دستگاهی که به اینترنت وصل می‌شود یک آدرس IP دارد؛ همان چیزی که سایت‌ها، سرورها و سرویس‌های امنیتی با آن شما را می‌شناسند. این ابزار آدرس IP یا نام دامنه را می‌گیرد و کشور، استان، شهر، منطقه‌ی زمانی، ارائه‌دهنده‌ی اینترنت (ISP)، شماره‌ی شبکه (ASN) و نوع اتصال را نشان می‌دهد. مهم‌ترین نکته‌ای که باید بدانید این است که موقعیت جغرافیایی IP از روی محل ثبت آن بلوک IP توسط ارائه‌دهنده به دست می‌آید، نه از GPS دستگاه شما؛ بنابراین کشور تقریباً همیشه درست است ولی شهر می‌تواند شهر مرکز مخابراتی یا دیتاسنتر ارائه‌دهنده باشد، نه شهر خودتان.",
            "steps" => [
                "برای دیدن IP خودتان کافی است وارد صفحه شوید — بررسی به‌صورت خودکار انجام می‌شود.",
                "برای بررسی آدرس دیگری، IP نسخه‌ی ۴ یا ۶ را در کادر بنویسید؛ می‌توانید به‌جای IP نام دامنه هم بدهید تا اول به IP تبدیل شود.",
                "دکمه‌ی بررسی را بزنید و کارت نتیجه را ببینید: پرچم و کشور، نقشه، ساعت محلی و جدول کامل مشخصات.",
                "با دکمه‌ی کپی، آدرس IP یا شماره‌ی ASN را برای تیکت پشتیبانی یا تنظیم فایروال بردارید.",
            ],
            "faq" => [
                ["q" => "موقعیت جغرافیایی نمایش‌داده‌شده چقدر دقیق است؟", "a" => "کشور در عمل نزدیک به همیشه درست است. شهر دقت کمتری دارد چون از پایگاه‌داده‌ی ثبت بلوک‌های IP خوانده می‌شود، نه از موقعیت واقعی دستگاه. اگر اینترنت شما از یک ارائه‌دهنده‌ی سراسری می‌آید، ممکن است شهر مرکز آن ارائه‌دهنده نمایش داده شود."],
                ["q" => "چرا شهر من اشتباه نشان داده می‌شود؟", "a" => "سه دلیل رایج دارد: اینترنت موبایل که ترافیک را از یک نقطه‌ی مرکزی خارج می‌کند، استفاده از VPN یا پروکسی، و بلوک IP تازه‌ای که هنوز در پایگاه‌داده‌های ژئو به‌روزرسانی نشده است. هیچ‌کدام نشانه‌ی مشکل در اتصال شما نیست."],
                ["q" => "برچسب دیتاسنتر یا پروکسی یعنی چه؟", "a" => "یعنی این IP متعلق به یک شرکت میزبانی یا سرویس VPN است، نه یک خط اینترنت خانگی. سایت‌های فروشگاهی و درگاه‌های پرداخت گاهی به این IP ها حساسیت بیشتری نشان می‌دهند و ممکن است تأیید بیشتری بخواهند."],
                ["q" => "ASN چیست و به چه دردی می‌خورد؟", "a" => "ASN شماره‌ی یکتای یک شبکه در اینترنت جهانی است؛ مثلاً کل بلوک‌های IP یک ارائه‌دهنده زیر یک ASN قرار می‌گیرند. برای تشخیص اینکه یک IP واقعاً متعلق به کدام شرکت است، ASN از نام ISP قابل‌اعتمادتر است."],
                ["q" => "با VPN، IP واقعی من دیده می‌شود؟", "a" => "این ابزار همان IP ای را می‌بیند که ترافیک شما با آن به اینترنت می‌رسد. اگر VPN فعال باشد، IP سرور VPN نمایش داده می‌شود. البته نشتی DNS یا WebRTC می‌تواند در جای دیگری آدرس واقعی را فاش کند؛ آن موضوع جداست."],
                ["q" => "تفاوت IPv4 و IPv6 در این بررسی چیست؟", "a" => "هر دو پشتیبانی می‌شوند. اگر دستگاه شما هم‌زمان IPv4 و IPv6 داشته باشد، نتیجه به این بستگی دارد که مرورگر با کدام‌یک به سایت وصل شده است؛ برای همین ممکن است در دو مرورگر دو آدرس متفاوت ببینید."],
                ["q" => "سرورنت آدرس IP مرا ذخیره می‌کند؟", "a" => "بررسی برای نمایش نتیجه انجام می‌شود و ما پروفایلی از IP بازدیدکنندگان این صفحه نمی‌سازیم. استفاده از ابزار رایگان است و نیازی به ثبت‌نام ندارد."],
                ["q" => "برای سرور خودم چطور IP ثابت بگیرم؟", "a" => "هر سرور مجازی یا اختصاصی سرورنت با IP اختصاصی و ثابت تحویل داده می‌شود و می‌توانید بین مکان‌های ایران و اروپا انتخاب کنید. برای دیدن پلن‌ها و مکان‌های موجود به بخش سرور ابری مراجعه کنید."],
            ],
        ],
        "en" => [
            "intro" => "Every device on the internet has an IP address — the identifier websites, servers and security systems use to recognise you. This tool takes an IP address or a domain name and shows the country, region, city, timezone, internet provider (ISP), network number (ASN) and connection type. One thing worth knowing up front: IP geolocation comes from where the provider registered that block of addresses, not from your device GPS. The country is almost always right, but the city may be the location of your provider exchange or datacenter rather than your own.",
            "steps" => [
                "To see your own IP, just open the page — the lookup runs automatically.",
                "To check a different address, type an IPv4 or IPv6 address into the box; you can also enter a domain name and it will be resolved to an IP first.",
                "Press the check button and read the result card: flag and country, map, local time and the full detail table.",
                "Use the copy buttons to grab the IP or ASN for a support ticket or a firewall rule.",
            ],
            "faq" => [
                ["q" => "How accurate is the location shown?", "a" => "The country is right in practice almost every time. The city is less reliable because it is read from IP registration databases rather than the actual position of the device. If your connection comes from a nationwide provider, you may see the city where that provider is headquartered."],
                ["q" => "Why is my city wrong?", "a" => "Three common reasons: mobile internet routes traffic out through a central point, a VPN or proxy is in the path, or the IP block is new and geolocation databases have not caught up. None of them means anything is wrong with your connection."],
                ["q" => "What does the datacenter or proxy tag mean?", "a" => "It means the address belongs to a hosting company or VPN service rather than a home line. Shops and payment gateways sometimes treat those addresses more cautiously and may ask for extra verification."],
                ["q" => "What is an ASN and why does it matter?", "a" => "An ASN is the unique number of a network on the global internet; all the IP blocks of one provider sit under it. To work out which company an address really belongs to, the ASN is more dependable than the ISP name."],
                ["q" => "Will my real IP show through a VPN?", "a" => "This tool sees the address your traffic actually arrives with. With a VPN active, that is the VPN server address. DNS or WebRTC leaks can expose the real one elsewhere, but that is a separate matter."],
                ["q" => "Does IPv4 or IPv6 change the result?", "a" => "Both are supported. If your device has IPv4 and IPv6 at the same time, the result depends on which one the browser used to reach us — which is why two browsers can show two different addresses."],
                ["q" => "Does ServerNet store my IP address?", "a" => "The lookup happens so the result can be shown, and we do not build a profile of the addresses that visit this page. The tool is free and needs no account."],
                ["q" => "How do I get a static IP for my own server?", "a" => "Every ServerNet virtual or dedicated server ships with its own static IP, and you can pick between locations in Iran and Europe. See the cloud server section for available plans and regions."],
            ],
        ],
        "tr" => [
            "intro" => "Internete baglanan her cihazin bir IP adresi vardir; web siteleri, sunucular ve guvenlik sistemleri sizi bu adresle taniyor. Bu arac bir IP adresini ya da alan adini alir ve ulke, bolge, sehir, saat dilimi, internet saglayici (ISP), ag numarasi (ASN) ve baglanti turunu gosterir. Bastan bilinmesi gereken sey su: IP konumu, o adres blogunun saglayici tarafindan kaydedildigi yerden gelir, cihazinizin GPS bilgisinden degil. Ulke neredeyse her zaman dogrudur ama sehir, sizin sehriniz yerine saglayicinin santral veya veri merkezi sehri olabilir.",
            "steps" => [
                "Kendi IP adresinizi gormek icin sayfayi acmaniz yeterli — sorgu otomatik calisir.",
                "Baska bir adresi kontrol etmek icin kutuya IPv4 veya IPv6 adresi yazin; IP yerine alan adi da girebilirsiniz, once IP adresine cozumlenir.",
                "Sorgula dugmesine basin ve sonuc kartini okuyun: bayrak ve ulke, harita, yerel saat ve tam detay tablosu.",
                "Kopyala dugmeleriyle IP adresini veya ASN numarasini destek talebi ya da guvenlik duvari kurali icin alin.",
            ],
            "faq" => [
                ["q" => "Gosterilen konum ne kadar dogru?", "a" => "Ulke pratikte neredeyse her zaman dogrudur. Sehir daha az guvenilirdir cunku cihazin gercek konumundan degil, IP kayit veritabanlarindan okunur. Baglantiniz ulke capinda bir saglayicidan geliyorsa o saglayicinin merkez sehrini gorebilirsiniz."],
                ["q" => "Sehrim neden yanlis gorunuyor?", "a" => "Uc yaygin nedeni var: mobil internetin trafigi merkezi bir noktadan cikarmasi, yolda bir VPN veya proxy bulunmasi ve IP blogunun yeni olup konum veritabanlarina henuz islenmemis olmasi. Hicbiri baglantinizda sorun oldugu anlamina gelmez."],
                ["q" => "Veri merkezi veya proxy etiketi ne demek?", "a" => "Bu adresin ev aboneligine degil, bir barindirma sirketine veya VPN hizmetine ait oldugu anlamina gelir. Magazalar ve odeme saglayicilari bu adreslere bazen daha temkinli yaklasir ve ek dogrulama isteyebilir."],
                ["q" => "ASN nedir, neden onemli?", "a" => "ASN, kuresel internette bir agin benzersiz numarasidir; bir saglayicinin tum IP bloklari onun altinda toplanir. Bir adresin gercekte hangi sirkete ait oldugunu anlamak icin ASN, ISP adindan daha guvenilirdir."],
                ["q" => "VPN kullanirken gercek IP adresim gorunur mu?", "a" => "Bu arac trafiginizin geldigi adresi gorur. VPN acikken bu, VPN sunucusunun adresidir. DNS veya WebRTC sizintilari gercek adresi baska bir yerde aciga cikarabilir, ama o ayri bir konudur."],
                ["q" => "IPv4 ile IPv6 sonucu degistirir mi?", "a" => "Ikisi de desteklenir. Cihazinizda ayni anda IPv4 ve IPv6 varsa sonuc, tarayicinin bize hangisiyle ulastigina baglidir — bu yuzden iki tarayicida iki farkli adres gorebilirsiniz."],
                ["q" => "ServerNet IP adresimi saklar mi?", "a" => "Sorgu yalnizca sonucu gosterebilmek icin yapilir ve bu sayfayi ziyaret eden adreslerden profil olusturmayiz. Arac ucretsizdir ve hesap gerektirmez."],
                ["q" => "Kendi sunucum icin sabit IP nasil alirim?", "a" => "Her ServerNet sanal veya adanmis sunucusu kendi sabit IP adresiyle teslim edilir ve Iran ile Avrupa lokasyonlari arasinda secim yapabilirsiniz. Mevcut planlar ve bolgeler icin bulut sunucu bolumune bakin."],
            ],
        ],
    ],

    "speedtest" => [
        "fa" => [
            "intro" => "این تست سرعت، اتصال شما را تا دیتاسنتر سرورنت در اروپا می‌سنجد — یعنی همان مسیر بین‌المللی که بیشتر سایت‌ها و سرویس‌های خارجی از آن می‌آیند. سه عدد می‌گیرید: پینگ (تاخیر رفت‌وبرگشت)، جیتر (نوسان همان تاخیر — برای تماس تصویری و بازی مهم‌تر از خود پینگ) و سرعت واقعی دانلود و آپلود. برخلاف بعضی تست‌ها که به نزدیک‌ترین سرور شهر خودتان وصل می‌شوند و عدد خوش‌بینانه می‌دهند، این عدد همان چیزی است که هنگام کار با سایت‌های خارجی واقعا تجربه می‌کنید.",
            "steps" => [
                "روی دکمه‌ی شروع تست بزنید — نیازی به هیچ ورودی‌ای نیست.",
                "چند ثانیه صبر کنید: اول پینگ و جیتر، بعد دانلود و در آخر آپلود سنجیده می‌شود.",
                "برای نتیجه‌ی دقیق‌تر، دانلودهای دیگر را متوقف کنید و اگر روی وای‌فای هستید یک بار هم با کابل امتحان کنید.",
                "اعداد را با بسته‌ی اینترنتی که خریده‌اید مقایسه کنید؛ اختلاف زیاد یعنی وقت پیگیری از اپراتور است.",
            ],
            "faq" => [
                ["q" => "چرا عدد این تست با speedtest فرق دارد؟", "a" => "تست‌های معروف معمولا به نزدیک‌ترین سرور در شهر خودتان وصل می‌شوند و عدد داخلی را نشان می‌دهند. این تست مسیر بین‌الملل را می‌سنجد که تقریبا همیشه کندتر است — و همان است که هنگام باز کردن سایت‌های خارجی حس می‌کنید."],
                ["q" => "جیتر چیست و چرا مهم است؟", "a" => "نوسان پینگ بین بسته‌های متوالی. برای تماس تصویری، VoIP و بازی آنلاین، جیتر بالا از پینگ بالا آزاردهنده‌تر است چون صدا و تصویر را می‌بُرد."],
                ["q" => "چه عددی برای مسیر بین‌الملل خوب است؟", "a" => "از ایران، پینگ ۸۰ تا ۱۵۰ میلی‌ثانیه تا اروپا طبیعی است. دانلود بالای ۲۰ مگابیت برای کار روزمره راحت است؛ آپلود معمولا خیلی کمتر از دانلود است و همین طبیعی است."],
                ["q" => "چرا هر بار عدد کمی فرق می‌کند؟", "a" => "سرعت لحظه‌ای به شلوغی شبکه‌ی اپراتور و مسیر بستگی دارد. دو سه بار در ساعت‌های مختلف تست بگیرید و به میانگین نگاه کنید، نه یک اجرا."],
            ],
        ],
        "en" => [
            "intro" => "This speed test measures your connection to the ServerNet datacenter in Europe — the same international route most foreign sites and services travel. You get three numbers: ping (round-trip delay), jitter (the variation of that delay — more important than ping itself for video calls and gaming) and your real download and upload speeds. Unlike tests that connect to the nearest server in your own city and produce optimistic numbers, this one shows what you actually experience with foreign sites.",
            "steps" => [
                "Press the start button — no input needed.",
                "Wait a few seconds: ping and jitter first, then download, then upload.",
                "For accuracy, pause other downloads and try once over a cable if you are on Wi-Fi.",
                "Compare the numbers with the plan you pay for; a big gap is worth raising with your ISP.",
            ],
            "faq" => [
                ["q" => "Why does this differ from speedtest?", "a" => "Popular tests usually connect to the nearest server in your city, showing the domestic number. This test measures the international route, which is almost always slower — and is what you feel on foreign sites."],
                ["q" => "What is jitter and why does it matter?", "a" => "The variation of ping between consecutive packets. For video calls, VoIP and online gaming, high jitter is more disruptive than high ping."],
                ["q" => "What is a good number for an international route?", "a" => "From Iran, 80-150ms ping to Europe is normal. Download above 20 Mbps is comfortable for daily work; upload is usually much lower than download by design."],
                ["q" => "Why does the number change between runs?", "a" => "Momentary speed depends on your ISP's load and the route. Run it a few times at different hours and look at the average, not one run."],
            ],
        ],
        "tr" => [
            "intro" => "Bu hiz testi baglantinizi ServerNet in Avrupa daki veri merkezine kadar olcer — cogu yabanci sitenin geldigi uluslararasi rota. Uc sayi alirsiniz: ping, jitter ve gercek indirme/yukleme hizi. Sehrinizdeki en yakin sunucuya baglanan testlerin aksine, bu sayi yabanci sitelerde gercekten yasadiginizdir.",
            "steps" => [
                "Baslat dugmesine basin — girdi gerekmez.",
                "Birkac saniye bekleyin: once ping ve jitter, sonra indirme, en son yukleme.",
                "Dogruluk icin diger indirmeleri durdurun; Wi-Fi kullaniyorsaniz bir kez de kabloyla deneyin.",
                "Sayilari satin aldiginiz paketle karsilastirin; buyuk fark operatorle konusmaya deger.",
            ],
            "faq" => [
                ["q" => "Neden speedtest ile farkli cikiyor?", "a" => "Populer testler genellikle sehrinizdeki en yakin sunucuya baglanir. Bu test neredeyse her zaman daha yavas olan uluslararasi rotayi olcer."],
                ["q" => "Jitter nedir?", "a" => "Ardisik paketler arasindaki ping dalgalanmasi. Gorusme ve oyun icin yuksek jitter, yuksek pingden daha rahatsiz edicidir."],
                ["q" => "Uluslararasi rota icin iyi sayi nedir?", "a" => "Iran dan Avrupa ya 80-150ms ping normaldir. 20 Mbps ustu indirme gunluk is icin rahattir."],
                ["q" => "Sayi neden her calistirmada degisiyor?", "a" => "Anlik hiz, operator yogunluguna baglidir. Farkli saatlerde birkac kez test edip ortalamaya bakin."],
            ],
        ],
    ],

    "domain-ideas" => [
        "fa" => [
            "intro" => "خوب‌ترین نام‌ها زودتر از همه ثبت می‌شوند و جستجوی دستی، ساعت‌ها وقت می‌بَرد: نامی به ذهن می‌رسد، استعلام می‌گیرید، گرفته شده، و از اول. این ابزار مسیر را برعکس می‌کند — کسب‌وکارتان را در یک جمله توصیف می‌کنید و هوش مصنوعی نام‌های کوتاه و برنددار می‌سازد؛ نام‌هایی که قطعاً ثبت شده‌اند همان لحظه علامت می‌خورند تا وقتتان را نگیرند و بقیه با یک کلیک به استعلام زندهٔ رجیسترار و قیمتِ روز می‌رسند. نام خوب، کوتاه است، راحت تلفظ می‌شود و با برندتان بزرگ می‌شود.",
            "steps" => [
                "کسب‌وکار یا ایده‌تان را در یک جمله توصیف کنید — فارسی یا انگلیسی، فرقی ندارد.",
                "دکمهٔ پیشنهاد را بزنید تا فهرستی از نام‌های کوتاه و برنددار ساخته شود.",
                "نام‌هایی که «ثبت شده» خورده‌اند را کنار بگذارید — این وضعیت قطعی است.",
                "روی نامِ موردعلاقه «بررسی و ثبت» را بزنید تا استعلام زنده با قیمت روز و پسوندهای مختلف را ببینید و همان‌جا ثبتش کنید.",
            ],
            "faq" => [
                ["q" => "چرا بعضی نام‌ها علامت ثبت‌شده ندارند ولی بعداً معلوم می‌شود گرفته‌اند؟", "a" => "علامت «ثبت شده» از وجود نیم‌سرور می‌آید که مدرک قطعی است؛ اما دامنهٔ ثبت‌شده‌ای که هنوز نیم‌سرور ندارد هم وجود دارد. برای همین وضعیت نهایی همیشه با استعلام زندهٔ لحظهٔ خرید مشخص می‌شود — دکمهٔ بررسی همین کار را می‌کند."],
                ["q" => "نام خوب چه ویژگی‌هایی دارد؟", "a" => "کوتاه (زیر ۱۲ حرف)، بدون خط تیره و عدد، راحت در تلفظ و املا، و بدون وابستگی به یک کلمهٔ عمومی — نامی که بشود رویش برند ساخت. اگر مخاطبتان ایرانی است، تلفظِ فارسی‌اش را هم بلند بگویید تا مطمئن شوید غریب نیست."],
                ["q" => "توضیح را فارسی بنویسم یا انگلیسی؟", "a" => "هر دو کار می‌کند. اگر فارسی بنویسید، مدل مفهوم را می‌فهمد و نام لاتین می‌سازد. هرچه توصیف دقیق‌تر باشد — حوزه، مخاطب، حس برند — پیشنهادها هدفمندتر می‌شوند."],
                ["q" => "چرا فقط پسوند com. نشان داده می‌شود؟", "a" => "com. جهانی‌ترین و امن‌ترین انتخاب برای برند است و وضعیتش سریع بررسی می‌شود. در صفحهٔ استعلام می‌توانید همان نام را با ده‌ها پسوند دیگر (ir.، io.، shop. و…) ببینید و مقایسه کنید."],
                ["q" => "پیشنهادها تکراری یا نامرتبط بود؛ چه کنم؟", "a" => "توصیف را کمی دقیق‌تر کنید: به‌جای «فروشگاه»، بنویسید «فروشگاه آنلاین قهوهٔ تخصصی برای دفترهای کار». هر بار اجرا نتیجهٔ تازه می‌سازد، پس دوباره امتحان‌کردن هم مؤثر است."],
            ],
        ],
        "en" => [
            "intro" => "The best names get registered first, and searching by hand burns hours: you think of a name, look it up, it is taken, repeat. This tool reverses the flow — describe your business in one sentence and the AI invents short, brandable candidates; names that are definitely taken get flagged immediately so they waste none of your time, and the rest are one click away from a live registrar check at today s price. A good name is short, easy to say, and grows with your brand.",
            "steps" => [
                "Describe your business or idea in one sentence — Persian or English both work.",
                "Press the suggest button to get a list of short, brandable candidates.",
                "Skip the ones flagged as taken — that flag is definitive.",
                "Click check and register on your favorite to see the live availability with today s price and other extensions, and register it right here.",
            ],
            "faq" => [
                ["q" => "Why do some unflagged names later turn out to be taken?", "a" => "The taken flag comes from the existence of nameservers, which is definitive evidence; but registered domains without nameservers exist too. The final answer always comes from the live check at purchase time — that is what the check button does."],
                ["q" => "What makes a good domain name?", "a" => "Short (under 12 letters), no hyphens or digits, easy to pronounce and spell, and not chained to one generic keyword — a name you can build a brand on."],
                ["q" => "Should I write the description in Persian or English?", "a" => "Both work. Write in Persian and the model understands the concept and invents latin names. The more specific the description — field, audience, brand feel — the sharper the suggestions."],
                ["q" => "Why is only the .com extension shown?", "a" => "It is the most universal, safest choice for a brand and its status can be checked fast. On the search page you can compare the same name across dozens of other extensions."],
                ["q" => "The suggestions felt generic — what now?", "a" => "Sharpen the description: instead of a store, write an online store for specialty coffee aimed at offices. Every run generates fresh results, so trying again also helps."],
            ],
        ],
        "tr" => [
            "intro" => "En iyi isimler once kaydedilir ve elle arama saatler alir: bir isim dusunursunuz, sorgularsiniz, alinmistir, bastan. Bu arac akisi tersine cevirir — isinizi tek cumleyle tanimlarsiniz, yapay zeka kisa ve marka olabilecek adaylar uretir; kesin alinmis olanlar aninda isaretlenir, digerleri tek tikla canli kayit sorgusuna gider. Iyi bir isim kisadir, kolay soylenir ve markanizla buyur.",
            "steps" => [
                "Isinizi veya fikrinizi tek cumleyle tanimlayin.",
                "Oner dugmesine basarak kisa, marka olabilecek aday listesini alin.",
                "Alinmis olarak isaretlenenleri atlayin — bu isaret kesindir.",
                "Begendiginizde kontrol et ve kaydet dugmesiyle guncel fiyatli canli sorguyu gorun ve hemen kaydedin.",
            ],
            "faq" => [
                ["q" => "Isaretsiz bazi isimler neden sonradan alinmis cikiyor?", "a" => "Alinmis isareti ad sunucularinin varligindan gelir ve kesin kanittir; ancak ad sunucusu olmayan kayitli alan adlari da vardir. Son cevap her zaman satin alma anindaki canli sorgudan gelir."],
                ["q" => "Iyi bir alan adi nasil olur?", "a" => "Kisa (12 harfin altinda), tiresiz ve rakamsiz, kolay telaffuz edilir — uzerine marka kurabileceginiz bir isim."],
                ["q" => "Aciklamayi hangi dilde yazmaliyim?", "a" => "Farsca ve Ingilizce ikisi de calisir; model kavrami anlar ve latin isimler uretir. Aciklama ne kadar net olursa oneriler o kadar isabetli olur."],
                ["q" => "Neden yalnizca .com gosteriliyor?", "a" => "Marka icin en evrensel ve guvenli secimdir. Arama sayfasinda ayni ismi baska uzantilarla karsilastirabilirsiniz."],
                ["q" => "Oneriler genel kaldi — ne yapmaliyim?", "a" => "Tanimi keskinlestirin: magaza yerine ofislere ozel kahve satan cevrimici magaza yazin. Her calistirma yeni sonuc uretir."],
            ],
        ],
    ],

];
