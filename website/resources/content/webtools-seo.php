<?php

/**
 * محتوای سئوی صفحات ابزار — مقدمه، راهنمای گام‌به‌گام و پرسش‌های متداول.
 *
 * ساختار:  slug => [ locale => ['intro' => string, 'steps' => [], 'faq' => [ ['q','a'] ]] ]
 *
 * نکته: همه‌ی رشته‌ها دو-نقل‌قولی هستند چون متن ترکی و انگلیسی آپاستروف دارد
 * (entity'lere / doesn't) و در رشته‌ی تک‌نقل‌قولی PHP را می‌شکند.
 * پس داخل متن‌ها از نویسه‌های  "  و  $  استفاده نکنید.
 */

return [

    // ═══════════════════════ متن و کد ═══════════════════════

    "json-formatter" => [
        "fa" => [
            "intro" => "JSON فشرده‌ای که از یک API برمی‌گردد عملاً غیرقابل‌خواندن است؛ این ابزار آن را تورفتگی‌دار می‌کند و اگر ساختارش خراب باشد، محل دقیق خطا را نشان می‌دهد. شایع‌ترین دلیل نامعتبر بودن JSON یک کاماست که بعد از آخرین عضو آرایه یا شیء جا مانده — چیزی که در جاوااسکریپت مجاز است ولی در JSON نه.",
            "steps" => [
                "خروجی API یا محتوای فایل JSON را در کادر ورودی بچسبانید.",
                "قالب‌بندی بلافاصله انجام می‌شود؛ اگر ساختار معتبر نباشد پیام خطا با شماره‌ی موقعیت نمایش داده می‌شود.",
                "برای بازگرداندن به حالت فشرده (کم‌حجم برای انتقال) دکمه‌ی فشرده‌سازی را بزنید.",
                "نتیجه را کپی کنید یا مستقیم در ویرایشگر خود بچسبانید.",
            ],
            "faq" => [
                ["q" => "چرا JSON من نامعتبر اعلام می‌شود در حالی که در مرورگر کار می‌کند؟", "a" => "احتمالاً یک شیء جاوااسکریپت است نه JSON. در JSON کلیدها باید داخل گیومه‌ی دوتایی باشند، کامای انتهایی مجاز نیست و نقل‌قول تکی پذیرفته نمی‌شود."],
                ["q" => "داده‌ی من جایی ذخیره می‌شود؟", "a" => "خیر. کل پردازش داخل مرورگر شما انجام می‌شود و هیچ درخواستی به سرور ما ارسال نمی‌شود؛ می‌توانید اتصال اینترنت را قطع کنید و ابزار همچنان کار می‌کند."],
                ["q" => "با فایل‌های بزرگ هم کار می‌کند؟", "a" => "بله، تا چند مگابایت روان است. برای فایل‌های خیلی بزرگ‌تر سرعت به حافظه و پردازنده‌ی دستگاه خودتان بستگی دارد."],
            ],
        ],
        "en" => [
            "intro" => "Minified JSON coming back from an API is effectively unreadable; this tool indents it and, when the structure is broken, points to exactly where. The most common cause of invalid JSON is a trailing comma after the last array or object member — legal in JavaScript, but not in JSON.",
            "steps" => [
                "Paste your API response or JSON file contents into the input box.",
                "Formatting happens instantly; if the structure is invalid you get an error message with the position.",
                "Use the minify button to collapse it back down for transport.",
                "Copy the result or paste it straight into your editor.",
            ],
            "faq" => [
                ["q" => "Why is my JSON reported as invalid when it works in the browser?", "a" => "It is probably a JavaScript object rather than JSON. JSON requires double-quoted keys, forbids trailing commas and does not accept single quotes."],
                ["q" => "Is my data stored anywhere?", "a" => "No. Everything runs inside your browser and no request reaches our servers — you can disconnect from the internet and the tool still works."],
                ["q" => "Does it handle large files?", "a" => "Yes, it stays responsive up to several megabytes. Beyond that, speed depends on your own device memory and CPU."],
            ],
        ],
        "tr" => [
            "intro" => "Bir API'den dönen sıkıştırılmış JSON pratikte okunamaz; bu araç onu girintiler ve yapı bozuksa hatanın tam yerini gösterir. Geçersiz JSON'un en yaygın nedeni son dizi veya nesne üyesinden sonra kalan virgüldür — JavaScript'te serbesttir, JSON'da değil.",
            "steps" => [
                "API yanıtınızı veya JSON dosya içeriğinizi giriş kutusuna yapıştırın.",
                "Biçimlendirme anında yapılır; yapı geçersizse konum bilgisiyle hata mesajı görürsünüz.",
                "Aktarım için tekrar küçültmek isterseniz sıkıştırma düğmesini kullanın.",
                "Sonucu kopyalayın veya doğrudan editörünüze yapıştırın.",
            ],
            "faq" => [
                ["q" => "Tarayıcıda çalışan JSON neden geçersiz gösteriliyor?", "a" => "Muhtemelen JSON değil bir JavaScript nesnesidir. JSON çift tırnaklı anahtar ister, sondaki virgülü yasaklar ve tek tırnağı kabul etmez."],
                ["q" => "Verim bir yerde saklanıyor mu?", "a" => "Hayır. Her şey tarayıcınızda çalışır, sunucularımıza hiçbir istek gitmez — internet bağlantısını kesseniz bile araç çalışmaya devam eder."],
                ["q" => "Büyük dosyalarla çalışır mı?", "a" => "Evet, birkaç megabayta kadar akıcıdır. Ötesinde hız kendi cihazınızın belleğine ve işlemcisine bağlıdır."],
            ],
        ],
    ],

    "base64" => [
        "fa" => [
            "intro" => "Base64 داده‌ی دودویی را به متنی تبدیل می‌کند که از کانال‌های متنی مثل ایمیل، JSON یا آدرس اینترنتی سالم عبور کند. مهم است بدانید این رمزنگاری نیست — هرکسی می‌تواند آن را در همین صفحه بازگرداند؛ پس هرگز رمز عبور یا کلید API را با Base64 مخفی نکنید.",
            "steps" => [
                "متن یا داده‌ی خود را در کادر بالا وارد کنید.",
                "دکمه‌ی کدگذاری را برای تبدیل به Base64 یا کدگشایی را برای بازگرداندن بزنید.",
                "اگر خروجی کدگشایی نامفهوم بود، ورودی احتمالاً Base64 معتبر نیست یا داده‌ی دودویی است.",
                "نتیجه را کپی کنید.",
            ],
            "faq" => [
                ["q" => "آیا Base64 امنیت ایجاد می‌کند؟", "a" => "خیر، هیچ امنیتی ندارد. صرفاً یک تغییر قالب برگشت‌پذیر است. برای محرمانگی باید از رمزنگاری واقعی مثل AES استفاده کنید."],
                ["q" => "چرا حجم داده بعد از تبدیل بیشتر شد؟", "a" => "Base64 هر ۳ بایت را به ۴ نویسه تبدیل می‌کند، یعنی حدود ۳۳ درصد افزایش حجم. برای همین جاسازی تصویر بزرگ داخل CSS معمولاً ایده‌ی خوبی نیست."],
                ["q" => "کاربرد رایجش کجاست؟", "a" => "پیوست ایمیل، آدرس‌های data:image داخل HTML و CSS، هدر Authorization در احراز هویت Basic، و انتقال داده‌ی دودویی داخل JSON."],
            ],
        ],
        "en" => [
            "intro" => "Base64 turns binary data into text that survives text-only channels like email, JSON or a URL. It is important to understand this is not encryption — anyone can reverse it on this very page, so never use Base64 to hide a password or API key.",
            "steps" => [
                "Enter your text or data in the box above.",
                "Press encode to convert to Base64, or decode to reverse it.",
                "If decoded output looks like garbage, the input is probably not valid Base64 or is binary data.",
                "Copy the result.",
            ],
            "faq" => [
                ["q" => "Does Base64 provide any security?", "a" => "None at all. It is a reversible format change. For confidentiality you need real encryption such as AES."],
                ["q" => "Why did my data get bigger?", "a" => "Base64 maps every 3 bytes to 4 characters, roughly a 33 percent increase. That is why embedding a large image inside CSS is usually a poor trade."],
                ["q" => "Where is it commonly used?", "a" => "Email attachments, data:image URLs in HTML and CSS, the Authorization header in Basic auth, and carrying binary data inside JSON."],
            ],
        ],
        "tr" => [
            "intro" => "Base64, ikili veriyi e-posta, JSON veya URL gibi yalnızca metin taşıyan kanallardan sağ geçebilen metne çevirir. Bunun şifreleme olmadığını bilmek önemli — herkes tam bu sayfada geri çevirebilir, bu yüzden parola veya API anahtarını Base64 ile asla gizlemeyin.",
            "steps" => [
                "Metninizi veya verinizi yukarıdaki kutuya girin.",
                "Base64'e çevirmek için kodla, geri almak için çöz düğmesine basın.",
                "Çözülen çıktı anlamsız görünüyorsa giriş geçerli Base64 değildir veya ikili veridir.",
                "Sonucu kopyalayın.",
            ],
            "faq" => [
                ["q" => "Base64 güvenlik sağlar mı?", "a" => "Hiç sağlamaz. Geri döndürülebilir bir biçim değişikliğidir. Gizlilik için AES gibi gerçek şifreleme gerekir."],
                ["q" => "Verim neden büyüdü?", "a" => "Base64 her 3 baytı 4 karaktere çevirir, yaklaşık yüzde 33 artış. Büyük bir görseli CSS içine gömmek bu yüzden genelde iyi bir takas değildir."],
                ["q" => "Yaygın kullanım alanı nedir?", "a" => "E-posta ekleri, HTML ve CSS içindeki data:image adresleri, Basic kimlik doğrulamadaki Authorization başlığı ve JSON içinde ikili veri taşıma."],
            ],
        ],
    ],

    "url-encoder" => [
        "fa" => [
            "intro" => "آدرس‌های اینترنتی فقط مجموعه‌ی محدودی از نویسه‌ها را می‌پذیرند؛ فاصله، حروف فارسی و نشانه‌هایی مثل و یا مساوی باید به شکل درصدی کدگذاری شوند تا لینک نشکند. این ابزار همان کاری را می‌کند که تابع encodeURIComponent در جاوااسکریپت انجام می‌دهد.",
            "steps" => [
                "آدرس یا پارامتری که می‌خواهید کدگذاری شود را وارد کنید.",
                "کدگذاری را بزنید تا نویسه‌های ویژه به شکل درصدی درآیند.",
                "برای خواندن یک آدرس کدگذاری‌شده، کدگشایی را انتخاب کنید.",
                "خروجی را در کد یا نوار آدرس خود استفاده کنید.",
            ],
            "faq" => [
                ["q" => "تفاوت کدگذاری کل آدرس با یک پارامتر چیست؟", "a" => "اگر کل آدرس را کدگذاری کنید، دونقطه و اسلش‌های ساختاری هم تبدیل می‌شوند و لینک از کار می‌افتد. معمولاً باید فقط مقدار پارامترها را کدگذاری کنید."],
                ["q" => "فاصله باید %20 شود یا علامت جمع؟", "a" => "در مسیر آدرس همیشه %20. علامت جمع فقط در بخش پرسمان و در قالب فرم‌های قدیمی معنای فاصله دارد."],
                ["q" => "آدرس فارسی درست کار می‌کند؟", "a" => "بله. مرورگرها حروف فارسی را به شکل کدگذاری‌شده به سرور می‌فرستند ولی در نوار آدرس خوانا نشان می‌دهند؛ برای اشتراک‌گذاری در جاهای دیگر بهتر است نسخه‌ی کدگذاری‌شده را بگذارید."],
            ],
        ],
        "en" => [
            "intro" => "URLs accept only a limited character set; spaces, non-Latin letters and symbols like ampersand or equals must be percent-encoded or the link breaks. This tool does exactly what encodeURIComponent does in JavaScript.",
            "steps" => [
                "Enter the URL or parameter you want encoded.",
                "Press encode to convert special characters to percent form.",
                "Choose decode to read an already-encoded URL.",
                "Use the output in your code or address bar.",
            ],
            "faq" => [
                ["q" => "What is the difference between encoding a full URL and a parameter?", "a" => "Encoding a full URL also converts the structural colons and slashes, which breaks the link. Normally you should encode only parameter values."],
                ["q" => "Should a space become %20 or a plus sign?", "a" => "Always %20 in the path. The plus sign only means space inside the query string under legacy form encoding."],
                ["q" => "Do non-Latin URLs work?", "a" => "Yes. Browsers send them percent-encoded but display them readably. When sharing elsewhere, the encoded version is the safer form to paste."],
            ],
        ],
        "tr" => [
            "intro" => "Adresler yalnızca sınırlı bir karakter kümesi kabul eder; boşluk, Latin dışı harfler ve ve işareti gibi semboller yüzde biçiminde kodlanmazsa bağlantı bozulur. Bu araç JavaScript'teki encodeURIComponent ile aynı işi yapar.",
            "steps" => [
                "Kodlanmasını istediğiniz adresi veya parametreyi girin.",
                "Özel karakterleri yüzde biçimine çevirmek için kodla düğmesine basın.",
                "Kodlanmış bir adresi okumak için çöz seçeneğini kullanın.",
                "Çıktıyı kodunuzda veya adres çubuğunuzda kullanın.",
            ],
            "faq" => [
                ["q" => "Tam adresi kodlamakla parametreyi kodlamak arasındaki fark nedir?", "a" => "Tam adresi kodlarsanız yapısal iki nokta ve eğik çizgiler de dönüşür ve bağlantı çalışmaz. Normalde yalnızca parametre değerlerini kodlamalısınız."],
                ["q" => "Boşluk %20 mi artı işareti mi olmalı?", "a" => "Yolda her zaman %20. Artı işareti yalnızca sorgu dizesinde eski form kodlamasında boşluk anlamına gelir."],
                ["q" => "Latin dışı adresler çalışır mı?", "a" => "Evet. Tarayıcılar bunları kodlanmış gönderir ama okunur gösterir. Başka yerde paylaşırken kodlanmış sürüm daha güvenlidir."],
            ],
        ],
    ],

    "jwt-decoder" => [
        "fa" => [
            "intro" => "توکن JWT از سه بخش تشکیل شده که با نقطه جدا می‌شوند: هدر، محتوا و امضا. دو بخش اول فقط Base64 هستند و هرکسی می‌تواند بخواندشان — این ابزار همان کار را می‌کند و ادعاهای داخل توکن مثل زمان انقضا را به تاریخ خوانا تبدیل می‌کند.",
            "steps" => [
                "توکن کامل را در کادر بچسبانید.",
                "هدر و محتوا بلافاصله رمزگشایی و قالب‌بندی می‌شوند.",
                "زمان صدور و انقضا به تاریخ میلادی و شمسی تبدیل و وضعیت اعتبار نمایش داده می‌شود.",
                "ادعاهای دلخواه برنامه‌ی خودتان را در همان خروجی محتوا ببینید.",
            ],
            "faq" => [
                ["q" => "این ابزار امضای توکن را بررسی می‌کند؟", "a" => "خیر و هیچ ابزار مرورگری نمی‌تواند. راستی‌آزمایی امضا به کلید مخفی سرور نیاز دارد که هرگز نباید در مرورگر باشد. این ابزار فقط محتوا را می‌خواند."],
                ["q" => "پس JWT چطور امن است اگر همه بتوانند بخوانندش؟", "a" => "امنیت JWT از محرمانگی نمی‌آید بلکه از امضاست: هرکسی می‌تواند بخواند ولی بدون کلید نمی‌تواند تغییرش دهد. برای همین هرگز داده‌ی حساس داخل محتوای توکن نگذارید."],
                ["q" => "چسباندن توکن واقعی اینجا خطرناک است؟", "a" => "این صفحه کاملاً داخل مرورگر شما اجرا می‌شود و توکن به هیچ سروری ارسال نمی‌شود. با این حال عادت خوبی است که توکن‌های محیط عملیاتی را در هیچ ابزار آنلاینی نچسبانید."],
            ],
        ],
        "en" => [
            "intro" => "A JWT has three dot-separated parts: header, payload and signature. The first two are only Base64, so anyone can read them — this tool does exactly that and turns claims like the expiry timestamp into a readable date.",
            "steps" => [
                "Paste the full token into the box.",
                "Header and payload are decoded and formatted instantly.",
                "Issued-at and expiry times are converted to readable dates with a validity status.",
                "Inspect your application-specific claims in the same payload output.",
            ],
            "faq" => [
                ["q" => "Does this verify the signature?", "a" => "No, and no browser tool can. Signature verification needs the server secret, which must never reach a browser. This tool only reads the payload."],
                ["q" => "Then how is a JWT secure if everyone can read it?", "a" => "JWT security comes from the signature, not secrecy: anyone can read it, but nobody can alter it without the key. That is why you must never put sensitive data in the payload."],
                ["q" => "Is pasting a real token here risky?", "a" => "This page runs entirely in your browser and the token is never sent anywhere. Even so, it is good practice never to paste production tokens into any online tool."],
            ],
        ],
        "tr" => [
            "intro" => "Bir JWT nokta ile ayrılmış üç bölümden oluşur: başlık, yük ve imza. İlk ikisi yalnızca Base64'tür, yani herkes okuyabilir — bu araç tam olarak bunu yapar ve sona erme zamanı gibi talepleri okunur tarihe çevirir.",
            "steps" => [
                "Tam belirteci kutuya yapıştırın.",
                "Başlık ve yük anında çözülüp biçimlendirilir.",
                "Verilme ve sona erme zamanları okunur tarihe çevrilir, geçerlilik durumu gösterilir.",
                "Uygulamanıza özel talepleri aynı yük çıktısında inceleyin.",
            ],
            "faq" => [
                ["q" => "Bu araç imzayı doğrular mı?", "a" => "Hayır, hiçbir tarayıcı aracı doğrulayamaz. İmza doğrulama sunucu gizli anahtarını gerektirir ve o anahtar asla tarayıcıya ulaşmamalıdır. Bu araç yalnızca yükü okur."],
                ["q" => "Herkes okuyabiliyorsa JWT nasıl güvenli olur?", "a" => "JWT güvenliği gizlilikten değil imzadan gelir: herkes okur ama anahtar olmadan kimse değiştiremez. Bu yüzden yük içine hassas veri koymamalısınız."],
                ["q" => "Gerçek bir belirteci buraya yapıştırmak riskli mi?", "a" => "Bu sayfa tamamen tarayıcınızda çalışır ve belirteç hiçbir yere gönderilmez. Yine de üretim belirteçlerini hiçbir çevrimiçi araca yapıştırmamak iyi bir alışkanlıktır."],
            ],
        ],
    ],

    "case-converter" => [
        "fa" => [
            "intro" => "هر زبان برنامه‌نویسی قرارداد نام‌گذاری خودش را دارد: جاوااسکریپت camelCase، پایتون snake_case، و آدرس‌ها و کلاس‌های CSS معمولاً kebab-case. این ابزار ورودی را در هر قالبی که باشد تشخیص می‌دهد و همه‌ی حالت‌ها را هم‌زمان تولید می‌کند.",
            "steps" => [
                "متن یا نام متغیر خود را وارد کنید؛ ورودی می‌تواند از قبل camelCase یا snake_case باشد.",
                "همه‌ی حالت‌های تبدیل هم‌زمان محاسبه و نمایش داده می‌شوند.",
                "حالتی که نیاز دارید را کپی کنید.",
            ],
            "faq" => [
                ["q" => "ورودی camelCase را درست می‌شکند؟", "a" => "بله، مرز کلمات را از تغییر حروف کوچک به بزرگ تشخیص می‌دهد؛ پس userFirstName به درستی به user_first_name تبدیل می‌شود."],
                ["q" => "برای نام فایل و آدرس کدام حالت بهتر است؟", "a" => "kebab-case با خط تیره. گوگل خط تیره را جداکننده‌ی کلمه می‌داند ولی زیرخط را نه، پس برای سئو خط تیره ترجیح دارد."],
                ["q" => "متن فارسی را هم پشتیبانی می‌کند؟", "a" => "حالت‌های حروف بزرگ و کوچک روی فارسی معنا ندارند، ولی جداسازی کلمات با خط تیره یا زیرخط روی فارسی هم کار می‌کند."],
            ],
        ],
        "en" => [
            "intro" => "Every language has its own naming convention: camelCase in JavaScript, snake_case in Python, kebab-case for URLs and CSS classes. This tool detects whatever format the input is already in and produces every variant at once.",
            "steps" => [
                "Enter your text or variable name; the input can already be camelCase or snake_case.",
                "All case variants are computed and shown simultaneously.",
                "Copy whichever form you need.",
            ],
            "faq" => [
                ["q" => "Does it split camelCase input correctly?", "a" => "Yes, it detects word boundaries at lower-to-upper transitions, so userFirstName becomes user_first_name properly."],
                ["q" => "Which case is best for filenames and URLs?", "a" => "kebab-case with hyphens. Google treats a hyphen as a word separator but an underscore as a joiner, so hyphens are better for SEO."],
                ["q" => "Does it support non-Latin text?", "a" => "Upper and lower case have no meaning in scripts like Persian or Arabic, but word separation with hyphens or underscores still works."],
            ],
        ],
        "tr" => [
            "intro" => "Her dilin kendi adlandırma kuralı vardır: JavaScript'te camelCase, Python'da snake_case, adresler ve CSS sınıfları için kebab-case. Bu araç girişin hangi biçimde olduğunu algılar ve tüm varyantları aynı anda üretir.",
            "steps" => [
                "Metninizi veya değişken adınızı girin; giriş zaten camelCase veya snake_case olabilir.",
                "Tüm biçim varyantları aynı anda hesaplanıp gösterilir.",
                "İhtiyacınız olan biçimi kopyalayın.",
            ],
            "faq" => [
                ["q" => "camelCase girişi doğru böler mi?", "a" => "Evet, küçükten büyüğe geçişlerde kelime sınırını algılar, böylece userFirstName düzgün şekilde user_first_name olur."],
                ["q" => "Dosya adı ve adresler için hangi biçim daha iyi?", "a" => "Tire ile kebab-case. Google tireyi kelime ayırıcı, alt çizgiyi birleştirici sayar; SEO için tire tercih edilir."],
                ["q" => "Latin dışı metni destekler mi?", "a" => "Farsça veya Arapça gibi yazılarda büyük-küçük harf anlamsızdır, ancak tire veya alt çizgi ile kelime ayırma yine çalışır."],
            ],
        ],
    ],

    "text-counter" => [
        "fa" => [
            "intro" => "شمارش کلمات فارسی با ابزارهای انگلیسی معمولاً اشتباه است، چون بسیاری از آن‌ها فقط حروف لاتین را کلمه می‌شناسند و برای متن فارسی صفر برمی‌گردانند. این شمارنده با پشتیبانی کامل یونیکد نوشته شده و کلمه، نویسه، خط و پاراگراف را جدا گزارش می‌کند.",
            "steps" => [
                "متن خود را در کادر بچسبانید یا مستقیم تایپ کنید.",
                "آمار در همان لحظه به‌روز می‌شود؛ نیازی به زدن دکمه نیست.",
                "شمارش نویسه با و بدون فاصله را برای محدودیت‌های شبکه‌های اجتماعی مقایسه کنید.",
                "زمان تقریبی مطالعه را برای سنجش طول مقاله ببینید.",
            ],
            "faq" => [
                ["q" => "برای عنوان و توضیحات متا چند نویسه مناسب است؟", "a" => "عنوان حدود ۵۰ تا ۶۰ نویسه و توضیحات ۱۲۰ تا ۱۵۸ نویسه. بیشتر از این در نتایج گوگل بریده می‌شود."],
                ["q" => "زمان مطالعه بر چه اساسی محاسبه می‌شود؟", "a" => "بر پایه‌ی سرعت متوسط مطالعه‌ی حدود ۲۰۰ تا ۲۵۰ کلمه در دقیقه که معیار رایج در محتوای وب است."],
                ["q" => "متن من ذخیره می‌شود؟", "a" => "خیر. شمارش کاملاً داخل مرورگر انجام می‌شود و متن هیچ‌وقت از دستگاه شما خارج نمی‌شود."],
            ],
        ],
        "en" => [
            "intro" => "Counting words in non-Latin scripts trips up most English tools, which recognise only Latin letters and return zero for Persian or Arabic text. This counter is Unicode-aware and reports words, characters, lines and paragraphs separately.",
            "steps" => [
                "Paste or type your text into the box.",
                "Statistics update live — there is no button to press.",
                "Compare character counts with and without spaces for social media limits.",
                "Check the estimated reading time to gauge article length.",
            ],
            "faq" => [
                ["q" => "What character counts should I target for title and meta description?", "a" => "Around 50 to 60 characters for the title and 120 to 158 for the description. Beyond that, Google truncates in the results page."],
                ["q" => "How is reading time calculated?", "a" => "From an average reading speed of roughly 200 to 250 words per minute, the common benchmark for web content."],
                ["q" => "Is my text stored?", "a" => "No. Counting happens entirely in the browser and the text never leaves your device."],
            ],
        ],
        "tr" => [
            "intro" => "Latin dışı yazılarda kelime saymak çoğu İngilizce aracı yanıltır; bunlar yalnızca Latin harflerini tanır ve Farsça veya Arapça metin için sıfır döndürür. Bu sayaç tam Unicode desteklidir ve kelime, karakter, satır ile paragrafı ayrı ayrı raporlar.",
            "steps" => [
                "Metninizi kutuya yapıştırın veya doğrudan yazın.",
                "İstatistikler anlık güncellenir — düğmeye basmaya gerek yok.",
                "Sosyal medya sınırları için boşluklu ve boşluksuz karakter sayılarını karşılaştırın.",
                "Makale uzunluğunu ölçmek için tahmini okuma süresine bakın.",
            ],
            "faq" => [
                ["q" => "Başlık ve meta açıklama için kaç karakter hedeflemeliyim?", "a" => "Başlık için yaklaşık 50-60, açıklama için 120-158 karakter. Fazlası Google sonuç sayfasında kırpılır."],
                ["q" => "Okuma süresi nasıl hesaplanıyor?", "a" => "Web içeriğinde yaygın ölçüt olan dakikada yaklaşık 200-250 kelimelik ortalama okuma hızına göre."],
                ["q" => "Metnim saklanıyor mu?", "a" => "Hayır. Sayım tamamen tarayıcıda yapılır ve metin cihazınızdan hiç çıkmaz."],
            ],
        ],
    ],

    "slug-generator" => [
        "fa" => [
            "intro" => "نامک یا slug همان بخش خوانای آدرس صفحه است که بعد از نام دامنه می‌آید. آدرسی که کلمات کلیدی را با خط تیره جدا کرده باشد هم برای کاربر قابل‌فهم‌تر است هم گوگل بهتر موضوع صفحه را از آن می‌فهمد.",
            "steps" => [
                "عنوان مطلب خود را وارد کنید.",
                "نامک به صورت خودکار ساخته می‌شود: حروف کوچک، فاصله‌ها به خط تیره، نویسه‌های ویژه حذف.",
                "گزینه‌ی حرف‌نویسی به طور پیش‌فرض روشن است و حروف فارسی را به معادل لاتین برمی‌گرداند؛ اگر آدرس فارسی می‌خواهید آن را خاموش کنید.",
                "جداکننده را در صورت نیاز به زیرخط تغییر دهید.",
                "نامک آماده را در سیستم مدیریت محتوای خود قرار دهید.",
            ],
            "faq" => [
                ["q" => "با عنوان فارسی چه می‌کند؟", "a" => "با حرف‌نویسی روشن، حروف فارسی به معادل لاتین تبدیل می‌شوند تا نامک در همه‌جا خوانا بماند. اگر آن را خاموش کنید حروف فارسی دست‌نخورده می‌مانند — آدرس فارسی در گوگل کاملاً پشتیبانی می‌شود، هرچند نسخه‌ی لاتین برای اشتراک‌گذاری راحت‌تر است."],
                ["q" => "خط تیره بهتر است یا زیرخط؟", "a" => "خط تیره. گوگل رسماً اعلام کرده خط تیره را جداکننده‌ی کلمه می‌بیند در حالی که زیرخط دو کلمه را به هم می‌چسباند."],
                ["q" => "نامک چقدر باید بلند باشد؟", "a" => "کوتاه و توصیفی؛ معمولاً سه تا پنج کلمه‌ی کلیدی کافی است. حروف اضافه و کلمات پرکننده را حذف کنید."],
            ],
        ],
        "en" => [
            "intro" => "A slug is the readable part of a page address that follows the domain. A URL that separates keywords with hyphens is easier for a person to read and gives Google a clearer signal about what the page covers.",
            "steps" => [
                "Enter your article title.",
                "The slug is generated automatically: lowercase, spaces to hyphens, special characters stripped.",
                "Transliteration is on by default and maps non-Latin letters to their Latin equivalents; switch it off if you want a native-script URL.",
                "Switch the separator to an underscore if your platform requires it.",
                "Paste the finished slug into your CMS.",
            ],
            "faq" => [
                ["q" => "What happens with non-Latin titles?", "a" => "With transliteration on, the letters are mapped to Latin so the slug stays readable everywhere. Switch it off and the original script is preserved — non-Latin URLs are fully supported by Google, though a Latin version is easier to share."],
                ["q" => "Hyphen or underscore?", "a" => "Hyphen. Google has stated it treats a hyphen as a word separator while an underscore joins two words together."],
                ["q" => "How long should a slug be?", "a" => "Short and descriptive; three to five meaningful words is usually enough. Drop articles and filler words."],
            ],
        ],
        "tr" => [
            "intro" => "Slug, alan adından sonra gelen adresin okunabilir bölümüdür. Anahtar kelimeleri tire ile ayıran bir adres hem insan için daha anlaşılırdır hem de Google'a sayfanın konusu hakkında daha net sinyal verir.",
            "steps" => [
                "Makale başlığınızı girin.",
                "Slug otomatik oluşur: küçük harf, boşluklar tireye, özel karakterler temizlenir.",
                "Harf çevirisi varsayılan olarak açıktır ve Latin dışı harfleri Latin karşılıklarına dönüştürür; kendi yazısında adres isterseniz kapatın.",
                "Platformunuz gerektiriyorsa ayırıcıyı alt çizgiye değiştirin.",
                "Hazır slug değerini içerik yönetim sisteminize yapıştırın.",
            ],
            "faq" => [
                ["q" => "Latin dışı başlıklarda ne olur?", "a" => "Harf çevirisi açıkken harfler Latin karşılığına eşlenir ve slug her yerde okunur kalır. Kapatırsanız özgün yazı korunur — Latin dışı adresler Google tarafından tam desteklenir, yine de Latin sürüm paylaşmak için kolaydır."],
                ["q" => "Tire mi alt çizgi mi?", "a" => "Tire. Google tireyi kelime ayırıcı saydığını, alt çizginin ise iki kelimeyi birleştirdiğini açıklamıştır."],
                ["q" => "Slug ne kadar uzun olmalı?", "a" => "Kısa ve açıklayıcı; üç ila beş anlamlı kelime genelde yeterlidir. Edat ve dolgu kelimelerini atın."],
            ],
        ],
    ],

    "lorem-ipsum" => [
        "fa" => [
            "intro" => "وقتی قالب سایتی را طراحی می‌کنید و محتوای نهایی هنوز آماده نیست، متن ساختگی جای آن را می‌گیرد تا چیدمان و فاصله‌ها را واقع‌بینانه ببینید. برای طراحی سایت فارسی، متن ساختگی فارسی خیلی بهتر از لاتین است چون طول کلمات و جهت راست‌به‌چپ نتیجه‌ی متفاوتی می‌سازد.",
            "steps" => [
                "واحد خروجی را پاراگراف، جمله یا کلمه انتخاب کنید و تعدادش را تعیین کنید.",
                "زبان متن ساختگی را فارسی یا لاتین بگذارید.",
                "متن تولیدشده را کپی و در طرح خود جای‌گذاری کنید.",
            ],
            "faq" => [
                ["q" => "چرا برای سایت فارسی از لورم ایپسوم لاتین استفاده نکنم؟", "a" => "چون طول کلمه، ارتفاع خط و جهت نوشتار فرق دارد. قالبی که با متن لاتین عالی به نظر می‌رسد ممکن است با متن فارسی واقعی به هم بریزد."],
                ["q" => "متن ساختگی روی سئو اثر بد دارد؟", "a" => "اگر روی سایت منتشرشده بماند بله، گوگل آن را محتوای بی‌ارزش می‌بیند. متن ساختگی فقط برای مرحله‌ی طراحی است و باید قبل از انتشار جایگزین شود."],
                ["q" => "می‌توانم طول متن را کنترل کنم؟", "a" => "بله، تعداد پاراگراف را تعیین می‌کنید و هر بار تولید، ترکیب متفاوتی می‌سازد."],
            ],
        ],
        "en" => [
            "intro" => "When you are designing a layout and the real copy is not ready, placeholder text lets you judge spacing and rhythm realistically. For a right-to-left site, placeholder text in that script matters — word lengths and text direction change the result significantly.",
            "steps" => [
                "Choose the output unit — paragraphs, sentences or words — and how many you need.",
                "Pick the placeholder language.",
                "Copy the generated text into your design.",
            ],
            "faq" => [
                ["q" => "Why not use Latin lorem ipsum for a Persian site?", "a" => "Word length, line height and text direction all differ. A layout that looks perfect with Latin filler can fall apart with real Persian copy."],
                ["q" => "Does placeholder text hurt SEO?", "a" => "It does if it stays on a published page — Google reads it as thin content. Placeholder text belongs to the design phase and must be replaced before launch."],
                ["q" => "Can I control the length?", "a" => "Yes, you set the paragraph count and each generation produces a different combination."],
            ],
        ],
        "tr" => [
            "intro" => "Bir düzen tasarlarken gerçek metin henüz hazır değilse, yer tutucu metin boşlukları ve ritmi gerçekçi değerlendirmenizi sağlar. Sağdan sola yazılan bir site için o yazıda yer tutucu kullanmak önemlidir — kelime uzunlukları ve yazı yönü sonucu belirgin şekilde değiştirir.",
            "steps" => [
                "Çıktı birimini paragraf, cümle veya kelime olarak seçin ve sayısını belirleyin.",
                "Yer tutucu dilini belirleyin.",
                "Üretilen metni tasarımınıza kopyalayın.",
            ],
            "faq" => [
                ["q" => "Farsça bir site için neden Latin lorem ipsum kullanmayayım?", "a" => "Kelime uzunluğu, satır yüksekliği ve yazı yönü farklıdır. Latin dolgu ile kusursuz görünen bir düzen gerçek Farsça metinle bozulabilir."],
                ["q" => "Yer tutucu metin SEO'ya zarar verir mi?", "a" => "Yayınlanmış sayfada kalırsa evet — Google bunu zayıf içerik sayar. Yer tutucu metin tasarım aşamasına aittir ve yayından önce değiştirilmelidir."],
                ["q" => "Uzunluğu kontrol edebilir miyim?", "a" => "Evet, paragraf sayısını siz belirlersiniz ve her üretim farklı bir birleşim verir."],
            ],
        ],
    ],

    // ═══════════════════════ وب و سئو ═══════════════════════

    "meta-tag-generator" => [
        "fa" => [
            "intro" => "متاتگ‌ها تعیین می‌کنند صفحه‌ی شما در نتایج گوگل و هنگام اشتراک‌گذاری در تلگرام، لینکدین یا توییتر چطور دیده شود. بدون تگ‌های Open Graph، شبکه‌های اجتماعی خودشان حدس می‌زنند چه تصویری نشان دهند و نتیجه معمولاً بد است.",
            "steps" => [
                "عنوان و توضیحات صفحه را وارد کنید؛ شمارنده‌ی نویسه به شما می‌گوید در بازه‌ی مناسب هستید یا نه.",
                "آدرس کامل صفحه و آدرس تصویر اشتراک‌گذاری را اضافه کنید.",
                "کد تولیدشده شامل متای استاندارد، Open Graph و Twitter Card است.",
                "همه را در بخش head صفحه‌ی خود قرار دهید.",
            ],
            "faq" => [
                ["q" => "اندازه‌ی مناسب تصویر Open Graph چقدر است؟", "a" => "۱۲۰۰ در ۶۳۰ پیکسل، نسبت ۱٫۹۱ به ۱. تصویر کوچک‌تر از ۶۰۰ پیکسل عرض در بعضی شبکه‌ها اصلاً نمایش داده نمی‌شود."],
                ["q" => "چرا توضیحات متای من در گوگل نشان داده نمی‌شود؟", "a" => "گوگل توضیحات را الزام‌آور نمی‌داند و اگر تشخیص دهد بخش دیگری از متن صفحه به جستجوی کاربر مرتبط‌تر است، همان را نشان می‌دهد. توضیحات خوب شانس نمایش را بالا می‌برد ولی تضمین نیست."],
                ["q" => "تگ keywords هنوز لازم است؟", "a" => "خیر. گوگل سال‌هاست آن را نادیده می‌گیرد. تمرکز روی عنوان، توضیحات و محتوای واقعی صفحه بازده دارد."],
            ],
        ],
        "en" => [
            "intro" => "Meta tags decide how your page appears in Google results and when someone shares it on LinkedIn, Telegram or X. Without Open Graph tags, social networks guess which image to show and the result is usually poor.",
            "steps" => [
                "Enter your page title and description; the character counter tells you whether you are inside the recommended range.",
                "Add the canonical page URL and a share image URL.",
                "The generated code covers standard meta, Open Graph and Twitter Card.",
                "Paste it all into the head section of your page.",
            ],
            "faq" => [
                ["q" => "What size should the Open Graph image be?", "a" => "1200 by 630 pixels, a 1.91:1 ratio. Images narrower than 600 pixels may not render at all on some networks."],
                ["q" => "Why is my meta description not showing in Google?", "a" => "Google treats the description as a hint. If it decides another passage matches the query better, it shows that instead. A good description improves your odds but guarantees nothing."],
                ["q" => "Is the keywords tag still needed?", "a" => "No. Google has ignored it for many years. Your effort is better spent on the title, description and the actual page content."],
            ],
        ],
        "tr" => [
            "intro" => "Meta etiketleri sayfanızın Google sonuçlarında ve LinkedIn, Telegram veya X'te paylaşıldığında nasıl göründüğünü belirler. Open Graph etiketleri olmadan sosyal ağlar hangi görseli göstereceğini tahmin eder ve sonuç genelde kötüdür.",
            "steps" => [
                "Sayfa başlığı ve açıklamanızı girin; karakter sayacı önerilen aralıkta olup olmadığınızı söyler.",
                "Sayfanın kanonik adresini ve paylaşım görseli adresini ekleyin.",
                "Üretilen kod standart meta, Open Graph ve Twitter Card etiketlerini kapsar.",
                "Tümünü sayfanızın head bölümüne yapıştırın.",
            ],
            "faq" => [
                ["q" => "Open Graph görseli hangi boyutta olmalı?", "a" => "1200 x 630 piksel, 1.91:1 oranı. 600 pikselden dar görseller bazı ağlarda hiç görüntülenmeyebilir."],
                ["q" => "Meta açıklamam neden Google'da görünmüyor?", "a" => "Google açıklamayı bir ipucu sayar. Başka bir bölümün sorguya daha uygun olduğuna karar verirse onu gösterir. İyi bir açıklama şansı artırır ama garanti vermez."],
                ["q" => "keywords etiketi hâlâ gerekli mi?", "a" => "Hayır. Google yıllardır dikkate almıyor. Emeğinizi başlığa, açıklamaya ve gerçek sayfa içeriğine ayırmak daha verimlidir."],
            ],
        ],
    ],

    "robots-generator" => [
        "fa" => [
            "intro" => "فایل robots.txt به خزنده‌های موتور جستجو می‌گوید کدام بخش‌های سایت را نخزند. با رشد خزنده‌های هوش مصنوعی، بسیاری از سایت‌ها حالا GPTBot و ClaudeBot و مشابه‌ها را هم مدیریت می‌کنند — این ابزار آن گزینه‌ها را آماده دارد.",
            "steps" => [
                "مسیرهایی که نمی‌خواهید خزیده شوند را وارد کنید، مثل پیشخوان مدیریت یا صفحات جستجوی داخلی.",
                "در صورت تمایل، دسترسی خزنده‌های هوش مصنوعی را ببندید.",
                "آدرس نقشه‌ی سایت خود را اضافه کنید تا خزنده‌ها سریع‌تر صفحات را پیدا کنند.",
                "فایل را در ریشه‌ی دامنه قرار دهید؛ حتماً باید در مسیر ریشه باشد نه زیرپوشه.",
            ],
            "faq" => [
                ["q" => "robots.txt جلوی دسترسی به صفحه را می‌گیرد؟", "a" => "خیر، و این یک سوءتفاهم خطرناک است. فایل فقط یک درخواست مؤدبانه به خزنده‌هاست؛ هرکسی می‌تواند آن را بخواند و مسیرهای ممنوعه را ببیند. برای محافظت واقعی از احراز هویت استفاده کنید."],
                ["q" => "چرا صفحه‌ای که در robots بستم هنوز در گوگل هست؟", "a" => "چون مسدود کردن خزش با حذف از فهرست فرق دارد. اگر لینکی به آن صفحه وجود داشته باشد گوگل ممکن است آدرس را بدون محتوا فهرست کند. برای حذف قطعی از تگ noindex استفاده کنید — که آن هم مستلزم این است که صفحه در robots مسدود نباشد."],
                ["q" => "بستن خزنده‌های هوش مصنوعی کار درستی است؟", "a" => "بستگی به راهبرد شما دارد. بستن، محتوا را از داده‌ی آموزشی دور نگه می‌دارد ولی از دیده‌شدن سایت در پاسخ‌های دستیارهای هوش مصنوعی هم کم می‌کند."],
            ],
        ],
        "en" => [
            "intro" => "A robots.txt file tells search engine crawlers which parts of your site to skip. With AI crawlers now a factor, many sites also manage GPTBot, ClaudeBot and similar agents — this tool has those rules ready to toggle.",
            "steps" => [
                "List the paths you do not want crawled, such as an admin dashboard or internal search pages.",
                "Optionally block AI crawler access.",
                "Add your sitemap URL so crawlers discover pages faster.",
                "Upload the file to your domain root — it must be at the root, not in a subfolder.",
            ],
            "faq" => [
                ["q" => "Does robots.txt prevent access to a page?", "a" => "No, and this misunderstanding is dangerous. The file is a polite request to crawlers; anyone can read it and see your disallowed paths. Use authentication for real protection."],
                ["q" => "Why is a page I blocked still in Google?", "a" => "Blocking crawling is not the same as removing from the index. If other pages link to it, Google may index the URL without content. For true removal use a noindex tag — which requires the page not be blocked in robots."],
                ["q" => "Should I block AI crawlers?", "a" => "It depends on your strategy. Blocking keeps your content out of training data, but also reduces how often your site surfaces inside AI assistant answers."],
            ],
        ],
        "tr" => [
            "intro" => "robots.txt dosyası arama motoru tarayıcılarına sitenizin hangi bölümlerini atlayacağını söyler. Yapay zekâ tarayıcıları da devreye girince birçok site artık GPTBot, ClaudeBot ve benzerlerini yönetiyor — bu araçta o kurallar hazır duruyor.",
            "steps" => [
                "Taranmasını istemediğiniz yolları listeleyin, örneğin yönetim paneli veya iç arama sayfaları.",
                "İsterseniz yapay zekâ tarayıcı erişimini kapatın.",
                "Tarayıcıların sayfaları daha hızlı bulması için site haritası adresinizi ekleyin.",
                "Dosyayı alan adı kök dizinine yükleyin — alt klasörde değil, kökte olmalıdır.",
            ],
            "faq" => [
                ["q" => "robots.txt sayfaya erişimi engeller mi?", "a" => "Hayır ve bu yanlış anlama tehlikelidir. Dosya tarayıcılara yapılan kibar bir ricadır; herkes okuyup yasakladığınız yolları görebilir. Gerçek koruma için kimlik doğrulama kullanın."],
                ["q" => "Engellediğim sayfa neden hâlâ Google'da?", "a" => "Taramayı engellemek dizinden çıkarmakla aynı şey değildir. Başka sayfalar bağlantı veriyorsa Google adresi içeriksiz dizine alabilir. Gerçekten kaldırmak için noindex etiketi gerekir — o da sayfanın robots ile engellenmemiş olmasını gerektirir."],
                ["q" => "Yapay zekâ tarayıcılarını engellemeli miyim?", "a" => "Stratejinize bağlı. Engellemek içeriğinizi eğitim verisinden uzak tutar ama sitenizin yapay zekâ asistan yanıtlarında görünme sıklığını da azaltır."],
            ],
        ],
    ],

    "htaccess-redirect" => [
        "fa" => [
            "intro" => "یکی از رایج‌ترین اشتباه‌های سئو این است که سایت هم با www و هم بدون www و هم روی http در دسترس باشد؛ از نگاه موتور جستجو این‌ها چهار سایت جداگانه با محتوای تکراری‌اند. این ابزار قوانین htaccess را برای یکی‌کردن همه‌ی این حالت‌ها روی یک نسخه‌ی رسمی می‌سازد.",
            "steps" => [
                "انتخاب کنید سایت روی نسخه‌ی www بنشیند یا بدون www؛ فقط یکی را فعال کنید.",
                "انتقال به HTTPS را فعال بگذارید مگر اینکه گواهی SSL نداشته باشید.",
                "در صورت نیاز، انتقال‌های تک‌صفحه‌ای را با فرمت مسیر قدیم و سپس مسیر جدید وارد کنید.",
                "کد را در فایل htaccess ریشه‌ی سایت، بالای بقیه‌ی قوانین قرار دهید.",
            ],
            "faq" => [
                ["q" => "تفاوت انتقال ۳۰۱ و ۳۰۲ چیست؟", "a" => "۳۰۱ یعنی دائمی و اعتبار سئوی صفحه‌ی قدیم را به جدید منتقل می‌کند. ۳۰۲ یعنی موقت و اعتبار را منتقل نمی‌کند. برای تغییر آدرس همیشه ۳۰۱ درست است."],
                ["q" => "www بهتر است یا بدون www؟", "a" => "از نظر سئو تفاوتی ندارد؛ مهم این است که یکی را انتخاب کنید و ثابت بمانید. فقط اگر از زیردامنه‌های زیاد استفاده می‌کنید، www مدیریت کوکی را ساده‌تر می‌کند."],
                ["q" => "این فایل روی همه‌ی سرورها کار می‌کند؟", "a" => "htaccess مخصوص آپاچی و لایت‌اسپید است. روی Nginx کار نمی‌کند و باید قوانین معادل در پیکربندی سرور نوشته شود."],
            ],
        ],
        "en" => [
            "intro" => "A very common SEO mistake is leaving a site reachable on www, non-www and plain http all at once; to a search engine those look like four separate sites with duplicate content. This tool builds the htaccess rules that funnel every variant into one canonical version.",
            "steps" => [
                "Decide whether the canonical form is www or non-www, and enable only one.",
                "Leave the HTTPS redirect on unless you have no SSL certificate.",
                "Add any per-page redirects using old path then new path on each line.",
                "Place the rules in the htaccess file at your site root, above other rules.",
            ],
            "faq" => [
                ["q" => "What is the difference between a 301 and a 302?", "a" => "301 is permanent and passes the old page ranking signals to the new one. 302 is temporary and does not. For a moved address, 301 is always the correct choice."],
                ["q" => "Is www or non-www better?", "a" => "SEO-wise there is no difference; what matters is picking one and staying consistent. Only if you run many subdomains does www simplify cookie scoping."],
                ["q" => "Does this work on every server?", "a" => "htaccess is an Apache and LiteSpeed feature. It does nothing on Nginx, where equivalent rules go in the server configuration instead."],
            ],
        ],
        "tr" => [
            "intro" => "Çok yaygın bir SEO hatası, siteyi aynı anda www, www'siz ve düz http üzerinden erişilebilir bırakmaktır; arama motoru bunları yinelenen içerikli dört ayrı site gibi görür. Bu araç tüm varyantları tek bir kanonik sürüme yönlendiren htaccess kurallarını üretir.",
            "steps" => [
                "Kanonik biçimin www mi www'siz mi olacağına karar verin ve yalnızca birini etkinleştirin.",
                "SSL sertifikanız yoksa hariç, HTTPS yönlendirmesini açık bırakın.",
                "Sayfa bazlı yönlendirmeleri her satıra eski yol sonra yeni yol biçiminde ekleyin.",
                "Kuralları site kökündeki htaccess dosyasına, diğer kuralların üstüne yerleştirin.",
            ],
            "faq" => [
                ["q" => "301 ile 302 arasındaki fark nedir?", "a" => "301 kalıcıdır ve eski sayfanın sıralama sinyallerini yenisine aktarır. 302 geçicidir ve aktarmaz. Taşınan bir adres için doğru seçim daima 301'dir."],
                ["q" => "www mi www'siz mi daha iyi?", "a" => "SEO açısından fark yok; önemli olan birini seçip tutarlı kalmak. Yalnızca çok sayıda alt alan adı kullanıyorsanız www çerez kapsamını kolaylaştırır."],
                ["q" => "Bu her sunucuda çalışır mı?", "a" => "htaccess Apache ve LiteSpeed özelliğidir. Nginx'te hiçbir etkisi yoktur; orada eşdeğer kurallar sunucu yapılandırmasına yazılır."],
            ],
        ],
    ],

    "utm-builder" => [
        "fa" => [
            "intro" => "پارامترهای UTM به گوگل آنالیتیکس می‌گویند بازدیدکننده دقیقاً از کدام کمپین آمده است. بدون آن‌ها همه‌ی ترافیک کمپین‌های ایمیلی و تبلیغاتی در دسته‌ی مبهم ارجاع مستقیم گم می‌شود و نمی‌فهمید کدام هزینه جواب داده.",
            "steps" => [
                "آدرس مقصد را وارد کنید.",
                "منبع را نام سرویس بگذارید مثل newsletter یا instagram، و رسانه را نوع کانال مثل email یا cpc.",
                "نام کمپین را انتخاب کنید؛ همین نام در گزارش آنالیتیکس دیده می‌شود.",
                "لینک ساخته‌شده را کپی و در همان کمپین استفاده کنید.",
            ],
            "faq" => [
                ["q" => "چرا باید همه‌ی مقادیر را با حروف کوچک بنویسم؟", "a" => "چون گوگل آنالیتیکس به بزرگی و کوچکی حروف حساس است و Email و email دو کمپین جدا گزارش می‌شوند. یک قرارداد ثابت داشته باشید تا گزارش‌ها تکه‌تکه نشود."],
                ["q" => "تفاوت source و medium چیست؟", "a" => "source نام دقیق فرستنده است مثل instagram و medium نوع کانال است مثل social یا cpc. اولی می‌گوید از کجا و دومی می‌گوید از چه نوع کانالی."],
                ["q" => "برای لینک‌های داخلی سایت هم UTM بگذارم؟", "a" => "خیر. UTM روی لینک داخلی، نشست کاربر را از نو شروع می‌کند و منبع اصلی ورود او را پاک می‌کند؛ این داده‌ی آنالیتیکس شما را خراب می‌کند."],
            ],
        ],
        "en" => [
            "intro" => "UTM parameters tell Google Analytics exactly which campaign a visitor came from. Without them, email and ad traffic collapses into the vague direct or referral bucket and you cannot tell which spend actually worked.",
            "steps" => [
                "Enter the destination URL.",
                "Set source to the specific sender such as newsletter or instagram, and medium to the channel type such as email or cpc.",
                "Choose a campaign name — this is the label you will see in your Analytics report.",
                "Copy the generated link and use it in that campaign.",
            ],
            "faq" => [
                ["q" => "Why should every value be lowercase?", "a" => "Google Analytics is case sensitive, so Email and email are reported as two different campaigns. Pick one convention and stick to it or your reports fragment."],
                ["q" => "What is the difference between source and medium?", "a" => "Source is the specific sender, like instagram. Medium is the channel type, like social or cpc. One answers where from, the other answers what kind of channel."],
                ["q" => "Should I add UTMs to internal links?", "a" => "No. A UTM on an internal link restarts the session and wipes the original acquisition source, corrupting your Analytics data."],
            ],
        ],
        "tr" => [
            "intro" => "UTM parametreleri Google Analytics'e ziyaretçinin tam olarak hangi kampanyadan geldiğini söyler. Onlar olmadan e-posta ve reklam trafiği belirsiz doğrudan veya yönlendirme kovasına düşer ve hangi harcamanın işe yaradığını göremezsiniz.",
            "steps" => [
                "Hedef adresi girin.",
                "Kaynağı newsletter veya instagram gibi belirli gönderici, ortamı email veya cpc gibi kanal türü olarak ayarlayın.",
                "Bir kampanya adı seçin — Analytics raporunuzda göreceğiniz etiket budur.",
                "Üretilen bağlantıyı kopyalayıp o kampanyada kullanın.",
            ],
            "faq" => [
                ["q" => "Neden tüm değerler küçük harf olmalı?", "a" => "Google Analytics büyük-küçük harfe duyarlıdır, Email ve email iki ayrı kampanya olarak raporlanır. Bir kural seçip ona bağlı kalın yoksa raporlarınız parçalanır."],
                ["q" => "source ile medium arasındaki fark nedir?", "a" => "source instagram gibi belirli göndericidir. medium ise social veya cpc gibi kanal türüdür. Biri nereden, diğeri ne tür kanaldan sorusunu yanıtlar."],
                ["q" => "İç bağlantılara UTM eklemeli miyim?", "a" => "Hayır. İç bağlantıdaki UTM oturumu yeniden başlatır ve özgün edinme kaynağını siler, Analytics verinizi bozar."],
            ],
        ],
    ],

    "html-entities" => [
        "fa" => [
            "intro" => "اگر بخواهید نویسه‌هایی مثل کوچک‌تر، بزرگ‌تر یا و را داخل HTML نمایش دهید، باید به شکل موجودیت بنویسیدشان وگرنه مرورگر آن‌ها را بخشی از تگ می‌فهمد. همین تبدیل در سمت سرور، خط دفاعی اصلی در برابر حملات تزریق اسکریپت است.",
            "steps" => [
                "متن یا قطعه‌کد HTML خود را وارد کنید.",
                "کدگذاری را بزنید تا نویسه‌های ویژه به موجودیت تبدیل شوند.",
                "برای برگرداندن موجودیت‌ها به نویسه‌ی اصلی، کدگشایی را انتخاب کنید.",
                "خروجی را در قالب یا مستند خود بگذارید.",
            ],
            "faq" => [
                ["q" => "کدام نویسه‌ها حتماً باید تبدیل شوند؟", "a" => "علامت و، کوچک‌تر، بزرگ‌تر، و در مقادیر صفت‌ها هر دو نوع گیومه. اگر ورودی کاربر را بدون این تبدیل چاپ کنید، سایت در برابر XSS آسیب‌پذیر می‌شود."],
                ["q" => "این کار جای پاک‌سازی سمت سرور را می‌گیرد؟", "a" => "خیر. این ابزار برای کار دستی و بررسی است. در برنامه‌ی واقعی باید هنگام چاپ خروجی، کدگذاری را در خود قالب انجام دهید — همان کاری که مثلاً Blade به صورت پیش‌فرض می‌کند."],
                ["q" => "نویسه‌های فارسی هم باید تبدیل شوند؟", "a" => "نیازی نیست. با کدگذاری UTF-8 حروف فارسی مستقیم نمایش داده می‌شوند و تبدیلشان فقط حجم فایل را زیاد می‌کند."],
            ],
        ],
        "en" => [
            "intro" => "To display characters like less-than, greater-than or ampersand inside HTML you must write them as entities, otherwise the browser reads them as part of a tag. The same conversion, applied server-side, is the primary defence against script injection.",
            "steps" => [
                "Enter your text or HTML snippet.",
                "Press encode to convert special characters into entities.",
                "Choose decode to turn entities back into plain characters.",
                "Drop the output into your template or document.",
            ],
            "faq" => [
                ["q" => "Which characters must always be converted?", "a" => "Ampersand, less-than, greater-than, and both quote types inside attribute values. Printing user input without this conversion leaves you open to XSS."],
                ["q" => "Does this replace server-side sanitisation?", "a" => "No. This tool is for manual work and inspection. In a real application, encoding must happen at output time in the template layer — which Blade, for example, does by default."],
                ["q" => "Should non-Latin characters be encoded?", "a" => "There is no need. With UTF-8 encoding they display directly, and converting them only inflates file size."],
            ],
        ],
        "tr" => [
            "intro" => "HTML içinde küçüktür, büyüktür veya ve işareti gibi karakterleri göstermek için bunları varlık olarak yazmalısınız, yoksa tarayıcı onları etiketin parçası sanır. Aynı dönüşüm sunucu tarafında uygulandığında betik enjeksiyonuna karşı birincil savunmadır.",
            "steps" => [
                "Metninizi veya HTML parçanızı girin.",
                "Özel karakterleri varlıklara çevirmek için kodla düğmesine basın.",
                "Varlıkları düz karaktere döndürmek için çöz seçeneğini kullanın.",
                "Çıktıyı şablonunuza veya belgenize yerleştirin.",
            ],
            "faq" => [
                ["q" => "Hangi karakterler mutlaka dönüştürülmeli?", "a" => "Ve işareti, küçüktür, büyüktür ve öznitelik değerlerindeki her iki tırnak türü. Kullanıcı girdisini bu dönüşüm olmadan yazdırmak sizi XSS'e açık bırakır."],
                ["q" => "Bu sunucu tarafı temizlemenin yerini tutar mı?", "a" => "Hayır. Bu araç elle çalışma ve inceleme içindir. Gerçek uygulamada kodlama çıktı anında şablon katmanında yapılmalıdır — örneğin Blade bunu varsayılan olarak yapar."],
                ["q" => "Latin dışı karakterler kodlanmalı mı?", "a" => "Gerek yok. UTF-8 kodlamayla doğrudan görüntülenirler ve dönüştürmek yalnızca dosya boyutunu şişirir."],
            ],
        ],
    ],

    // ═══════════════════════ طراحی و CSS ═══════════════════════

    "color-converter" => [
        "fa" => [
            "intro" => "یک رنگ را می‌توان به شکل هگز، RGB یا HSL نوشت و هر سه دقیقاً همان رنگ‌اند. مزیت HSL این است که برای ساختن حالت روشن‌تر یا تیره‌تر یک رنگ فقط کافی است عدد سوم را تغییر دهید — کاری که با هگز عملاً حدس زدن است.",
            "steps" => [
                "رنگ را در هر قالبی وارد کنید یا از انتخابگر رنگ استفاده کنید.",
                "سه قالب دیگر بلافاصله محاسبه می‌شوند.",
                "نسبت کنتراست با سفید و مشکی را برای بررسی دسترس‌پذیری ببینید.",
                "قالب مورد نیاز خود را کپی کنید.",
            ],
            "faq" => [
                ["q" => "نسبت کنتراست چقدر باید باشد؟", "a" => "استاندارد WCAG برای متن معمولی حداقل ۴٫۵ به ۱ و برای متن درشت ۳ به ۱ را لازم می‌داند. سفید روی مشکی بیشترین مقدار ممکن یعنی ۲۱ به ۱ است."],
                ["q" => "چه وقت از HSL استفاده کنم؟", "a" => "وقتی می‌خواهید طیفی از یک رنگ بسازید یا تم روشن و تیره طراحی کنید. تغییر روشنایی در HSL قابل‌پیش‌بینی است، بر خلاف دستکاری هگز."],
                ["q" => "هگز هشت‌رقمی چیست؟", "a" => "دو رقم آخر شفافیت را نشان می‌دهند. مثلاً پسوند 80 یعنی حدود پنجاه درصد شفاف."],
            ],
        ],
        "en" => [
            "intro" => "The same colour can be written as hex, RGB or HSL and all three are identical. HSL earns its keep when you need a lighter or darker variant: you change one number, whereas with hex you are essentially guessing.",
            "steps" => [
                "Enter a colour in any format or use the colour picker.",
                "The other formats are computed instantly.",
                "Check the contrast ratio against white and black for accessibility.",
                "Copy whichever format you need.",
            ],
            "faq" => [
                ["q" => "What contrast ratio should I aim for?", "a" => "WCAG requires at least 4.5:1 for body text and 3:1 for large text. White on black is the maximum possible at 21:1."],
                ["q" => "When should I use HSL?", "a" => "When building a colour scale or light and dark themes. Adjusting lightness in HSL is predictable, unlike nudging hex values."],
                ["q" => "What is an eight-digit hex code?", "a" => "The last two digits carry alpha transparency. A suffix of 80, for example, is roughly fifty percent transparent."],
            ],
        ],
        "tr" => [
            "intro" => "Aynı renk hex, RGB veya HSL olarak yazılabilir ve üçü de aynıdır. HSL bir rengin açık ya da koyu varyantını üretirken değerini kanıtlar: tek bir sayıyı değiştirirsiniz, oysa hex ile aslında tahmin yürütüyorsunuzdur.",
            "steps" => [
                "Rengi herhangi bir biçimde girin veya renk seçiciyi kullanın.",
                "Diğer biçimler anında hesaplanır.",
                "Erişilebilirlik için beyaz ve siyaha karşı kontrast oranını kontrol edin.",
                "İhtiyacınız olan biçimi kopyalayın.",
            ],
            "faq" => [
                ["q" => "Hangi kontrast oranını hedeflemeliyim?", "a" => "WCAG gövde metni için en az 4.5:1, büyük metin için 3:1 ister. Siyah üzerine beyaz 21:1 ile mümkün olan azami değerdir."],
                ["q" => "HSL'yi ne zaman kullanmalıyım?", "a" => "Bir renk skalası veya açık ve koyu tema kurarken. HSL'de açıklık ayarlamak öngörülebilirdir, hex değerlerini kurcalamak değil."],
                ["q" => "Sekiz haneli hex kodu nedir?", "a" => "Son iki hane alfa saydamlığını taşır. Örneğin 80 son eki yaklaşık yüzde elli saydam demektir."],
            ],
        ],
    ],

    "gradient-generator" => [
        "fa" => [
            "intro" => "گرادیان CSS بدون هیچ تصویری اجرا می‌شود، یعنی نه درخواست شبکه‌ای اضافه می‌کند نه با بزرگ‌نمایی کیفیتش افت می‌کند. این ابزار پیش‌نمایش زنده می‌دهد و کد آماده‌ی چسباندن در شیوه‌نامه‌ی شما تولید می‌کند.",
            "steps" => [
                "رنگ‌های شروع و پایان را انتخاب کنید؛ در صورت نیاز رنگ سوم را هم فعال کنید.",
                "نوع گرادیان را خطی یا شعاعی بگذارید و برای حالت خطی زاویه را تنظیم کنید.",
                "پیش‌نمایش زنده را ببینید، یا با دکمه‌ی تصادفی ترکیب‌های تازه را مرور کنید.",
                "کد CSS تولیدشده را کپی کنید.",
            ],
            "faq" => [
                ["q" => "گرادیان خطی بهتر است یا شعاعی؟", "a" => "خطی برای پس‌زمینه‌ی بخش‌ها و دکمه‌ها متداول‌تر است. شعاعی وقتی خوب است که بخواهید نور از یک نقطه بتابد، مثل هاله‌ی پشت یک عنصر شاخص."],
                ["q" => "چرا گرادیان من وسطش خاکستری و کدر می‌شود؟", "a" => "این اتفاق وقتی می‌افتد که دو رنگ روی چرخه‌ی رنگی از هم دور باشند و مسیر میانی از خاکستری بگذرد. رنگ‌های نزدیک‌تر یا افزودن یک رنگ میانی مشکل را حل می‌کند."],
                ["q" => "پشتیبانی مرورگرها چطور است؟", "a" => "گرادیان‌های CSS در همه‌ی مرورگرهای امروزی بدون پیشوند کار می‌کنند و نیازی به کد جایگزین ندارید."],
            ],
        ],
        "en" => [
            "intro" => "A CSS gradient renders without any image file, so it adds no network request and never softens when scaled up. This tool gives you a live preview and produces code ready to paste into your stylesheet.",
            "steps" => [
                "Pick your start and end colours, and enable the third stop if you need one.",
                "Set the gradient type to linear or radial, and adjust the angle for linear.",
                "Watch the live preview, or hit the random button to browse fresh combinations.",
                "Copy the generated CSS.",
            ],
            "faq" => [
                ["q" => "Linear or radial?", "a" => "Linear is the common choice for section backgrounds and buttons. Radial suits cases where light should emanate from a point, such as a glow behind a hero element."],
                ["q" => "Why does my gradient go grey and muddy in the middle?", "a" => "That happens when the two colours sit far apart on the colour wheel and the path between them passes through grey. Closer hues, or adding a midpoint colour, fixes it."],
                ["q" => "What is browser support like?", "a" => "CSS gradients work unprefixed in every current browser; no fallback code is needed."],
            ],
        ],
        "tr" => [
            "intro" => "CSS gradyanı hiçbir görsel dosyası olmadan çizilir, yani ek ağ isteği getirmez ve büyütüldüğünde bulanıklaşmaz. Bu araç canlı önizleme verir ve stil sayfanıza yapıştırmaya hazır kod üretir.",
            "steps" => [
                "Başlangıç ve bitiş renklerinizi seçin, gerekiyorsa üçüncü durağı da etkinleştirin.",
                "Gradyan türünü doğrusal veya dairesel yapın, doğrusal için açıyı ayarlayın.",
                "Canlı önizlemeyi izleyin veya rastgele düğmesiyle yeni birleşimlere göz atın.",
                "Üretilen CSS kodunu kopyalayın.",
            ],
            "faq" => [
                ["q" => "Doğrusal mı dairesel mi?", "a" => "Bölüm arka planları ve düğmeler için yaygın seçim doğrusaldır. Dairesel, ışığın bir noktadan yayılması gereken durumlara uyar, örneğin öne çıkan bir öğenin arkasındaki parlama."],
                ["q" => "Gradyanım ortada neden grileşip donuklaşıyor?", "a" => "İki renk renk çemberinde birbirinden uzaksa ve aradaki yol griden geçiyorsa olur. Daha yakın tonlar veya bir ara renk eklemek sorunu çözer."],
                ["q" => "Tarayıcı desteği nasıl?", "a" => "CSS gradyanları güncel tüm tarayıcılarda öneksiz çalışır; yedek koda gerek yoktur."],
            ],
        ],
    ],

    "box-shadow" => [
        "fa" => [
            "intro" => "سایه چیزی است که به عناصر رابط کاربری حس ارتفاع می‌دهد. رازِ سایه‌های حرفه‌ای این است که به جای یک سایه‌ی تیره و بزرگ، دو یا سه سایه‌ی نرم و کم‌رنگ روی هم گذاشته می‌شوند — دقیقاً کاری که نور واقعی می‌کند.",
            "steps" => [
                "جابه‌جایی افقی و عمودی را تنظیم کنید؛ معمولاً فقط عمودی مقدار می‌گیرد چون نور از بالا می‌تابد.",
                "میزان محو‌شدگی و گسترش را تغییر دهید تا نرمی دلخواه به دست آید.",
                "رنگ و شفافیت سایه را انتخاب کنید؛ شفافیت پایین طبیعی‌تر است.",
                "کد CSS را کپی کنید.",
            ],
            "faq" => [
                ["q" => "چطور سایه‌ی طبیعی‌تری بسازم؟", "a" => "شفافیت را پایین نگه دارید، حدود ده تا بیست درصد، و محو‌شدگی را زیاد. سایه‌ی سیاه صددرصد تقریباً همیشه مصنوعی به نظر می‌رسد."],
                ["q" => "سایه‌ی داخلی به چه درد می‌خورد؟", "a" => "برای فرورفتگی؛ مثلاً کادر ورودی یا دکمه‌ی فشرده‌شده. حس می‌دهد که عنصر داخل صفحه فرو رفته نه بیرون آمده."],
                ["q" => "سایه روی کارایی اثر می‌گذارد؟", "a" => "تعداد کم مشکلی ندارد. اما سایه‌های بسیار محو روی ده‌ها عنصر متحرک می‌تواند در دستگاه‌های ضعیف باعث افت نرمی انیمیشن شود."],
            ],
        ],
        "en" => [
            "intro" => "Shadows are what give interface elements a sense of elevation. The trick behind professional-looking shadows is layering two or three soft, low-opacity shadows rather than one big dark one — which is what real light actually does.",
            "steps" => [
                "Set the horizontal and vertical offset; usually only vertical gets a value, since light comes from above.",
                "Adjust blur and spread until the softness looks right.",
                "Choose the shadow colour and opacity — lower opacity reads as more natural.",
                "Copy the CSS.",
            ],
            "faq" => [
                ["q" => "How do I make a shadow look natural?", "a" => "Keep opacity low, around ten to twenty percent, and blur generous. A fully opaque black shadow almost always looks artificial."],
                ["q" => "What is an inset shadow for?", "a" => "Depressions — an input field or a pressed button. It makes the element read as recessed into the page rather than raised above it."],
                ["q" => "Do shadows affect performance?", "a" => "A handful is fine. Very large blur radii across dozens of animated elements can cost frame rate on weaker devices."],
            ],
        ],
        "tr" => [
            "intro" => "Gölgeler arayüz öğelerine yükseklik hissi veren şeydir. Profesyonel görünen gölgelerin sırrı, tek bir büyük koyu gölge yerine iki üç yumuşak ve düşük opaklıkta gölgeyi katmanlamaktır — gerçek ışığın yaptığı da tam budur.",
            "steps" => [
                "Yatay ve dikey kaymayı ayarlayın; ışık yukarıdan geldiği için genelde yalnızca dikey değer alır.",
                "Yumuşaklık doğru görünene kadar bulanıklık ve yayılmayı ayarlayın.",
                "Gölge rengini ve opaklığını seçin — düşük opaklık daha doğal okunur.",
                "CSS kodunu kopyalayın.",
            ],
            "faq" => [
                ["q" => "Gölgeyi nasıl doğal gösteririm?", "a" => "Opaklığı düşük tutun, yaklaşık yüzde on ile yirmi arası, bulanıklığı cömert bırakın. Tam opak siyah gölge neredeyse her zaman yapay görünür."],
                ["q" => "İç gölge ne işe yarar?", "a" => "Çöküntüler için — bir giriş alanı veya basılı düğme. Öğenin sayfadan yükselmiş değil içine gömülmüş okunmasını sağlar."],
                ["q" => "Gölgeler performansı etkiler mi?", "a" => "Birkaç tanesi sorun değil. Onlarca hareketli öğede çok büyük bulanıklık yarıçapları zayıf cihazlarda kare hızına mal olabilir."],
            ],
        ],
    ],

    // ═══════════════════════ تبدیل و محاسبه ═══════════════════════

    "jalali-converter" => [
        "fa" => [
            "intro" => "تقویم هجری شمسی بر پایه‌ی رصد اعتدال بهاری بنا شده، نه یک فرمول ساده؛ به همین دلیل بسیاری از کتابخانه‌های تبدیل تاریخ در سال‌های کبیسه اشتباه می‌کنند. این مبدل از الگوریتم مرجع با جدول کامل چرخه‌های کبیسه استفاده می‌کند و روز هفته را هم درست محاسبه می‌کند.",
            "steps" => [
                "تاریخ شمسی یا میلادی را در کادر مربوطه وارد کنید.",
                "تبدیل در همان لحظه در جهت مقابل انجام می‌شود.",
                "روز هفته زیر نتیجه نمایش داده می‌شود.",
                "برای بازگشت سریع، دکمه‌ی تاریخ امروز را بزنید.",
            ],
            "faq" => [
                ["q" => "چرا بعضی مبدل‌ها یک روز اختلاف دارند؟", "a" => "معمولاً به دو دلیل: محاسبه‌ی نادرست سال کبیسه، یا نادیده گرفتن منطقه‌ی زمانی. تبدیلی که تاریخ را با ساعت جهانی حساب کند، برای کاربر ایرانی که سه ساعت و نیم جلوتر است می‌تواند یک روز جابه‌جا شود."],
                ["q" => "سال کبیسه در تقویم شمسی چطور تعیین می‌شود؟", "a" => "بر اساس چرخه‌های نامنظم ۳۳ ساله که در یک جدول مرجع تعریف شده‌اند، نه قاعده‌ی ساده‌ی بخش‌پذیری بر چهار که در تقویم میلادی هست. برای همین محاسبه‌ی دستی‌اش خطاخیز است."],
                ["q" => "بازه‌ی پشتیبانی‌شده چقدر است؟", "a" => "جدول مرجع محدوده‌ی بسیار وسیعی را پوشش می‌دهد و برای همه‌ی تاریخ‌های کاربردی، از اسناد تاریخی تا برنامه‌ریزی آینده، دقیق است."],
            ],
        ],
        "en" => [
            "intro" => "The Persian solar calendar is anchored to astronomical observation of the vernal equinox rather than a simple formula, which is why many date libraries get its leap years wrong. This converter uses the reference algorithm with the full leap-cycle table and computes the weekday correctly too.",
            "steps" => [
                "Enter either a Persian or a Gregorian date in the matching field.",
                "Conversion happens live in the opposite direction.",
                "The weekday is shown below the result.",
                "Use the today button to jump back to the current date.",
            ],
            "faq" => [
                ["q" => "Why do some converters differ by a day?", "a" => "Usually two reasons: incorrect leap year handling, or ignoring time zones. A converter that works in UTC can land a day off for a user who is three and a half hours ahead."],
                ["q" => "How are Persian leap years determined?", "a" => "By irregular 33-year cycles defined in a reference table, not the simple divisible-by-four rule of the Gregorian calendar. That is exactly why hand calculation is so error-prone."],
                ["q" => "What date range is supported?", "a" => "The reference table covers a very wide span and is accurate for every practical date, from historical records to future planning."],
            ],
        ],
        "tr" => [
            "intro" => "Fars güneş takvimi basit bir formüle değil, ilkbahar ekinoksunun gözlemine dayanır; bu yüzden birçok tarih kütüphanesi artık yıllarını yanlış hesaplar. Bu dönüştürücü tam artık döngü tablosuyla referans algoritmayı kullanır ve haftanın gününü de doğru hesaplar.",
            "steps" => [
                "İlgili alana Fars veya Miladi tarihi girin.",
                "Dönüşüm ters yönde anlık gerçekleşir.",
                "Haftanın günü sonucun altında gösterilir.",
                "Bugüne dönmek için bugün düğmesini kullanın.",
            ],
            "faq" => [
                ["q" => "Bazı dönüştürücüler neden bir gün farklı sonuç veriyor?", "a" => "Genelde iki nedenle: artık yıl işlemenin yanlış olması veya saat dilimlerinin yok sayılması. UTC üzerinden çalışan bir dönüştürücü, üç buçuk saat ileride olan bir kullanıcı için bir gün kayabilir."],
                ["q" => "Fars artık yılları nasıl belirlenir?", "a" => "Miladi takvimin dörde bölünme kuralıyla değil, referans tabloda tanımlı düzensiz 33 yıllık döngülerle. Elle hesaplamanın bu kadar hataya açık olmasının nedeni tam da budur."],
                ["q" => "Hangi tarih aralığı destekleniyor?", "a" => "Referans tablo çok geniş bir aralığı kapsar ve tarihi kayıtlardan gelecek planlamasına kadar her pratik tarih için doğrudur."],
            ],
        ],
    ],

    "timestamp-converter" => [
        "fa" => [
            "intro" => "مهر زمانی یونیکس تعداد ثانیه‌های گذشته از اول ژانویه ۱۹۷۰ به وقت جهانی است و تقریباً همه‌ی پایگاه‌های داده و APIها زمان را همین‌طور ذخیره می‌کنند. این ابزار آن عدد را به تاریخ خوانا و برعکس تبدیل می‌کند و شمسی را هم نشان می‌دهد.",
            "steps" => [
                "مهر زمانی را وارد کنید؛ ثانیه و میلی‌ثانیه هر دو تشخیص داده می‌شوند.",
                "تاریخ معادل به وقت جهانی، وقت محلی و تقویم شمسی نمایش داده می‌شود.",
                "برای مسیر برعکس، تاریخ را وارد کنید تا مهر زمانی به دست آید.",
                "برای دیدن لحظه‌ی جاری دکمه‌ی اکنون را بزنید.",
            ],
            "faq" => [
                ["q" => "عدد من ثانیه است یا میلی‌ثانیه؟", "a" => "مهر زمانی امروزی به ثانیه ده رقم دارد و به میلی‌ثانیه سیزده رقم. جاوااسکریپت میلی‌ثانیه می‌دهد ولی PHP و اکثر پایگاه‌های داده ثانیه؛ اشتباه گرفتنشان تاریخ را هزار برابر جابه‌جا می‌کند."],
                ["q" => "مشکل سال ۲۰۳۸ چیست؟", "a" => "سامانه‌هایی که مهر زمانی را در عدد صحیح علامت‌دار ۳۲ بیتی نگه می‌دارند در ژانویه ۲۰۳۸ سرریز می‌کنند. سامانه‌های ۶۴ بیتی امروزی این محدودیت را ندارند."],
                ["q" => "چرا زمان را در UTC ذخیره کنم؟", "a" => "چون منطقه‌ی زمانی و ساعت تابستانی تغییر می‌کنند ولی UTC ثابت است. زمان را در UTC ذخیره کنید و فقط هنگام نمایش به وقت محلی کاربر تبدیلش کنید."],
            ],
        ],
        "en" => [
            "intro" => "A Unix timestamp is the number of seconds since 1 January 1970 UTC, and nearly every database and API stores time this way. This tool converts that number into a readable date and back again, including the Persian calendar equivalent.",
            "steps" => [
                "Enter a timestamp; both seconds and milliseconds are detected automatically.",
                "The equivalent date is shown in UTC, your local time and the Persian calendar.",
                "For the reverse direction, enter a date to get its timestamp.",
                "Press the now button to see the current moment.",
            ],
            "faq" => [
                ["q" => "Is my number in seconds or milliseconds?", "a" => "A current timestamp is ten digits in seconds and thirteen in milliseconds. JavaScript gives milliseconds while PHP and most databases give seconds; mixing them up shifts your date by a factor of a thousand."],
                ["q" => "What is the year 2038 problem?", "a" => "Systems storing timestamps in a signed 32-bit integer overflow in January 2038. Modern 64-bit systems are not affected."],
                ["q" => "Why store times in UTC?", "a" => "Time zones and daylight saving shift, UTC does not. Store in UTC and convert to the user local time only at display time."],
            ],
        ],
        "tr" => [
            "intro" => "Unix zaman damgası 1 Ocak 1970 UTC'den bu yana geçen saniye sayısıdır ve neredeyse tüm veritabanları ile API'ler zamanı böyle saklar. Bu araç o sayıyı okunur tarihe ve tersine çevirir, Fars takvimi karşılığını da gösterir.",
            "steps" => [
                "Bir zaman damgası girin; saniye ve milisaniye otomatik algılanır.",
                "Karşılık gelen tarih UTC, yerel saatiniz ve Fars takviminde gösterilir.",
                "Ters yön için bir tarih girerek zaman damgasını alın.",
                "Şu anki anı görmek için şimdi düğmesine basın.",
            ],
            "faq" => [
                ["q" => "Sayım saniye mi milisaniye mi?", "a" => "Güncel bir zaman damgası saniyede on, milisaniyede on üç hanelidir. JavaScript milisaniye, PHP ve çoğu veritabanı saniye verir; karıştırmak tarihinizi bin kat kaydırır."],
                ["q" => "2038 yılı sorunu nedir?", "a" => "Zaman damgasını işaretli 32 bit tam sayıda saklayan sistemler Ocak 2038'de taşar. Modern 64 bit sistemler etkilenmez."],
                ["q" => "Zamanı neden UTC olarak saklamalıyım?", "a" => "Saat dilimleri ve yaz saati kayar, UTC kaymaz. UTC olarak saklayın ve yalnızca gösterim anında kullanıcının yerel saatine çevirin."],
            ],
        ],
    ],

    "password-generator" => [
        "fa" => [
            "intro" => "قدرت یک گذرواژه بیشتر از تنوع نویسه‌ها به طول آن وابسته است: یک گذرواژه‌ی بیست‌نویسه‌ای فقط با حروف کوچک، از یک گذرواژه‌ی هشت‌نویسه‌ای پر از نماد به مراتب مقاوم‌تر است. این ابزار از مولد اعداد تصادفی رمزنگارانه‌ی خود مرورگر استفاده می‌کند.",
            "steps" => [
                "طول گذرواژه را انتخاب کنید؛ حداقل شانزده نویسه توصیه می‌شود.",
                "مجموعه‌های نویسه را بر اساس محدودیت‌های سرویس مقصد فعال یا غیرفعال کنید.",
                "گذرواژه بلافاصله تولید می‌شود و نشانگر قدرت را ببینید.",
                "آن را کپی و مستقیم در مدیر گذرواژه‌ی خود ذخیره کنید.",
            ],
            "faq" => [
                ["q" => "گذرواژه واقعاً تصادفی است؟", "a" => "بله. از رابط crypto.getRandomValues مرورگر استفاده می‌شود که برای کاربردهای رمزنگاری طراحی شده، نه تابع Math.random که قابل‌پیش‌بینی است."],
                ["q" => "گذرواژه‌ی تولیدشده جایی ثبت می‌شود؟", "a" => "خیر. تولید کاملاً در مرورگر شما انجام می‌شود، به سرور ارسال نمی‌شود و در جایی ذخیره نمی‌ماند. با بستن صفحه از بین می‌رود."],
                ["q" => "هر چند وقت گذرواژه را عوض کنم؟", "a" => "توصیه‌ی امروزی این است که تعویض دوره‌ای اجباری فایده‌ای ندارد و کاربران را به سمت گذرواژه‌های ضعیف‌تر می‌برد. گذرواژه‌ی بلند و یکتا برای هر سرویس، به همراه احراز هویت دومرحله‌ای، مؤثرتر است. فقط در صورت نشت اطلاعات فوراً تعویض کنید."],
            ],
        ],
        "en" => [
            "intro" => "Password strength depends far more on length than on character variety: a twenty-character lowercase passphrase resists attack far better than eight characters full of symbols. This tool draws from the browser own cryptographic random number generator.",
            "steps" => [
                "Choose the length; sixteen characters is a sensible minimum.",
                "Toggle character sets to match the target service restrictions.",
                "The password generates instantly with a strength indicator.",
                "Copy it straight into your password manager.",
            ],
            "faq" => [
                ["q" => "Is the password genuinely random?", "a" => "Yes. It uses the browser crypto.getRandomValues interface, designed for cryptographic use, rather than the predictable Math.random function."],
                ["q" => "Is the generated password recorded anywhere?", "a" => "No. Generation happens entirely in your browser, nothing is sent to a server and nothing is stored. Closing the page discards it."],
                ["q" => "How often should I change passwords?", "a" => "Current guidance is that forced periodic rotation does not help and pushes people toward weaker passwords. A long unique password per service plus two-factor authentication works better. Change immediately only after a breach."],
            ],
        ],
        "tr" => [
            "intro" => "Parola gücü karakter çeşitliliğinden çok uzunluğa bağlıdır: yirmi karakterlik küçük harfli bir parola, sembol dolu sekiz karakterden çok daha dayanıklıdır. Bu araç tarayıcının kendi kriptografik rastgele sayı üreticisinden yararlanır.",
            "steps" => [
                "Uzunluğu seçin; on altı karakter makul bir alt sınırdır.",
                "Karakter kümelerini hedef servisin kısıtlarına göre açıp kapatın.",
                "Parola güç göstergesiyle birlikte anında üretilir.",
                "Doğrudan parola yöneticinize kopyalayın.",
            ],
            "faq" => [
                ["q" => "Parola gerçekten rastgele mi?", "a" => "Evet. Öngörülebilir Math.random yerine kriptografik kullanım için tasarlanmış crypto.getRandomValues arayüzünü kullanır."],
                ["q" => "Üretilen parola bir yere kaydediliyor mu?", "a" => "Hayır. Üretim tamamen tarayıcınızda olur, sunucuya hiçbir şey gitmez ve hiçbir şey saklanmaz. Sayfayı kapatmak onu yok eder."],
                ["q" => "Parolaları ne sıklıkla değiştirmeliyim?", "a" => "Güncel rehberlik, zorunlu periyodik değişimin fayda sağlamadığı ve insanları daha zayıf parolalara ittiği yönünde. Servis başına uzun ve benzersiz parola artı iki adımlı doğrulama daha iyi çalışır. Yalnızca bir sızıntı sonrası hemen değiştirin."],
            ],
        ],
    ],

    "hash-generator" => [
        "fa" => [
            "intro" => "درهم‌ساز یا hash از هر ورودی یک اثر انگشت با طول ثابت می‌سازد و این مسیر یک‌طرفه است — از خروجی نمی‌توان به ورودی رسید. کاربرد اصلی‌اش بررسی سلامت فایل است: اگر یک بیت از فایل عوض شود، درهم‌ساز کاملاً متفاوتی به دست می‌آید.",
            "steps" => [
                "متن مورد نظر را وارد کنید.",
                "درهم‌سازهای SHA-1، SHA-256، SHA-384 و SHA-512 هم‌زمان محاسبه می‌شوند.",
                "برای بررسی سلامت دانلود، نتیجه را با مقدار منتشرشده مقایسه کنید.",
                "مقدار مورد نیاز را کپی کنید.",
            ],
            "faq" => [
                ["q" => "می‌توانم درهم‌ساز را به متن اصلی برگردانم؟", "a" => "خیر، این تابع ریاضی یک‌طرفه است. آنچه سایت‌های بازگرداننده انجام می‌دهند جستجو در پایگاه داده‌ی درهم‌سازهای از پیش محاسبه‌شده است، نه معکوس کردن واقعی."],
                ["q" => "برای ذخیره‌ی گذرواژه از کدام استفاده کنم؟", "a" => "از هیچ‌کدام از این‌ها. SHA خانواده برای گذرواژه بیش از حد سریع است و همین حمله‌ی حدس‌زنی را آسان می‌کند. از bcrypt یا Argon2 استفاده کنید که عمداً کند و همراه با نمک‌اند."],
                ["q" => "چرا MD5 در این ابزار نیست؟", "a" => "چون رابط رمزنگاری استاندارد مرورگرها یعنی Web Crypto عمداً از MD5 پشتیبانی نمی‌کند؛ این الگوریتم سال‌هاست شکسته شده و تولید برخورد عمدی برای آن ساده است. اگر برای بررسی یک فایل قدیمی به MD5 نیاز دارید، از ابزار خط فرمان سیستم‌عامل خود استفاده کنید."],
                ["q" => "SHA-1 هنوز قابل‌استفاده است؟", "a" => "فقط برای بررسی سلامت فایل و سازگاری با سامانه‌های قدیمی مثل گیت. برای امضای دیجیتال و هر کاربرد امنیتی شکسته شده و باید SHA-256 یا بالاتر انتخاب شود."],
            ],
        ],
        "en" => [
            "intro" => "A hash turns any input into a fixed-length fingerprint, and the process is one-way — you cannot recover the input from the output. Its main use is integrity checking: change a single bit of a file and the hash comes out completely different.",
            "steps" => [
                "Enter the text you want hashed.",
                "SHA-1, SHA-256, SHA-384 and SHA-512 are computed simultaneously.",
                "To verify a download, compare the result with the published value.",
                "Copy whichever digest you need.",
            ],
            "faq" => [
                ["q" => "Can a hash be reversed to the original text?", "a" => "No, it is a one-way mathematical function. What reverse-lookup sites do is search a database of precomputed hashes, not actually invert anything."],
                ["q" => "Which one should I use to store passwords?", "a" => "None of these. The SHA family is too fast for passwords, which is exactly what makes brute-force guessing easy. Use bcrypt or Argon2 — deliberately slow and salted."],
                ["q" => "Why is MD5 not offered here?", "a" => "Because Web Crypto, the standard browser cryptography interface, deliberately omits MD5 — the algorithm has been broken for years and deliberate collisions are easy to produce. If you need MD5 to check an old file, use your operating system command line tool."],
                ["q" => "Is SHA-1 still usable?", "a" => "Only for file integrity checks and compatibility with legacy systems such as Git. For digital signatures or any security purpose it is broken and you should choose SHA-256 or stronger."],
            ],
        ],
        "tr" => [
            "intro" => "Özet fonksiyonu herhangi bir girdiyi sabit uzunlukta bir parmak izine çevirir ve süreç tek yönlüdür — çıktıdan girdiye dönemezsiniz. Başlıca kullanımı bütünlük denetimidir: bir dosyanın tek bitini değiştirin, özet tamamen farklı çıkar.",
            "steps" => [
                "Özetlenmesini istediğiniz metni girin.",
                "SHA-1, SHA-256, SHA-384 ve SHA-512 aynı anda hesaplanır.",
                "Bir indirmeyi doğrulamak için sonucu yayımlanan değerle karşılaştırın.",
                "İhtiyacınız olan özeti kopyalayın.",
            ],
            "faq" => [
                ["q" => "Özet özgün metne geri çevrilebilir mi?", "a" => "Hayır, tek yönlü bir matematiksel fonksiyondur. Ters arama siteleri önceden hesaplanmış özet veritabanında arama yapar, gerçekte hiçbir şeyi tersine çevirmez."],
                ["q" => "Parola saklamak için hangisini kullanmalıyım?", "a" => "Hiçbirini. SHA ailesi parolalar için fazla hızlıdır, kaba kuvvet tahminini kolaylaştıran da tam budur. bcrypt veya Argon2 kullanın — kasıtlı olarak yavaş ve tuzlanmış."],
                ["q" => "MD5 neden burada sunulmuyor?", "a" => "Çünkü tarayıcıların standart kriptografi arayüzü olan Web Crypto MD5'i bilerek dışarıda bırakır — algoritma yıllardır kırık ve kasıtlı çakışma üretmek kolay. Eski bir dosyayı denetlemek için MD5 gerekiyorsa işletim sisteminizin komut satırı aracını kullanın."],
                ["q" => "SHA-1 hâlâ kullanılabilir mi?", "a" => "Yalnızca dosya bütünlük denetimi ve Git gibi eski sistemlerle uyumluluk için. Dijital imza veya herhangi bir güvenlik amacı için kırıktır, SHA-256 veya daha güçlüsünü seçmelisiniz."],

            ],
        ],
    ],

    "uuid-generator" => [
        "fa" => [
            "intro" => "شناسه‌ی یکتای جهانی یا UUID عددی ۱۲۸ بیتی است که بدون هماهنگی با هیچ سرور مرکزی ساخته می‌شود و باز هم عملاً هرگز تکراری نمی‌شود. همین ویژگی آن را برای سامانه‌های توزیع‌شده که چند سرور هم‌زمان رکورد می‌سازند ایده‌آل می‌کند.",
            "steps" => [
                "تعداد شناسه‌های مورد نیاز را انتخاب کنید.",
                "شناسه‌ها بلافاصله با تصادف رمزنگارانه تولید می‌شوند.",
                "همه را یکجا کپی کنید یا هرکدام را جداگانه بردارید.",
            ],
            "faq" => [
                ["q" => "احتمال تکراری شدن چقدر است؟", "a" => "نسخه‌ی چهارم ۱۲۲ بیت تصادفی دارد. برای رسیدن به احتمال معنادار برخورد باید میلیاردها شناسه در ثانیه و برای سال‌ها تولید کنید؛ در عمل قابل چشم‌پوشی است."],
                ["q" => "UUID را کلید اصلی پایگاه داده کنم یا عدد افزایشی؟", "a" => "UUID اجازه می‌دهد شناسه پیش از درج ساخته شود و تعداد رکوردها را لو نمی‌دهد. در عوض فضای بیشتری می‌گیرد و اگر تصادفی باشد نمایه‌ی پایگاه داده را کند می‌کند. برای سامانه‌های توزیع‌شده معمولاً ارزشش را دارد."],
                ["q" => "تفاوت نسخه‌ی ۱ و ۴ چیست؟", "a" => "نسخه‌ی ۱ از زمان و نشانی کارت شبکه ساخته می‌شود، یعنی می‌تواند اطلاعات دستگاه را فاش کند. نسخه‌ی ۴ کاملاً تصادفی است و انتخاب پیش‌فرض درست."],
            ],
        ],
        "en" => [
            "intro" => "A UUID is a 128-bit identifier generated without coordinating with any central server, yet in practice never collides. That property makes it ideal for distributed systems where several servers create records at the same time.",
            "steps" => [
                "Choose how many identifiers you need.",
                "They generate instantly using cryptographic randomness.",
                "Copy them all at once or take them individually.",
            ],
            "faq" => [
                ["q" => "What are the odds of a collision?", "a" => "Version 4 carries 122 random bits. Reaching a meaningful collision probability would take billions of IDs per second for years — negligible in practice."],
                ["q" => "UUID or auto-increment for a database primary key?", "a" => "A UUID lets you create the ID before insert and does not leak your record count. In exchange it takes more space and, if random, slows index performance. For distributed systems the trade is usually worth it."],
                ["q" => "What is the difference between version 1 and version 4?", "a" => "Version 1 derives from the timestamp and network card address, so it can leak machine information. Version 4 is fully random and the correct default."],
            ],
        ],
        "tr" => [
            "intro" => "UUID, hiçbir merkezi sunucuyla eşgüdüm kurmadan üretilen 128 bitlik bir tanımlayıcıdır ve pratikte asla çakışmaz. Bu özellik onu, birden çok sunucunun aynı anda kayıt oluşturduğu dağıtık sistemler için ideal kılar.",
            "steps" => [
                "Kaç tanımlayıcı istediğinizi seçin.",
                "Kriptografik rastgelelikle anında üretilirler.",
                "Hepsini birden kopyalayın veya tek tek alın.",
            ],
            "faq" => [
                ["q" => "Çakışma olasılığı nedir?", "a" => "Sürüm 4, 122 rastgele bit taşır. Anlamlı bir çakışma olasılığına ulaşmak yıllarca saniyede milyarlarca kimlik üretmeyi gerektirir — pratikte ihmal edilebilir."],
                ["q" => "Veritabanı birincil anahtarı için UUID mi otomatik artan sayı mı?", "a" => "UUID kimliği eklemeden önce oluşturmanızı sağlar ve kayıt sayınızı sızdırmaz. Karşılığında daha fazla yer kaplar ve rastgeleyse dizin başarımını yavaşlatır. Dağıtık sistemlerde takas genelde buna değer."],
                ["q" => "Sürüm 1 ile sürüm 4 arasındaki fark nedir?", "a" => "Sürüm 1 zaman damgası ve ağ kartı adresinden türetilir, yani makine bilgisi sızdırabilir. Sürüm 4 tamamen rastgeledir ve doğru varsayılandır."],
            ],
        ],
    ],

    "byte-converter" => [
        "fa" => [
            "intro" => "بخش زیادی از سردرگمی درباره‌ی حجم فایل و فضای دیسک از یک اختلاف ساده می‌آید: سازندگان سخت‌افزار کیلوبایت را ۱۰۰۰ بایت حساب می‌کنند ولی سیستم‌عامل ۱۰۲۴ بایت. این ابزار کلید تعویض بین همین دو مبنا را دارد تا ببینید اختلاف از کجاست.",
            "steps" => [
                "عدد و واحد مبدأ را وارد کنید.",
                "معادل در همه‌ی واحدها بلافاصله محاسبه می‌شود.",
                "گزینه‌ی SI را بزنید تا مبنا از ۱۰۲۴ به ۱۰۰۰ برود و تفاوت نتیجه را ببینید.",
            ],
            "faq" => [
                ["q" => "چرا هارد یک ترابایتی من ۹۳۱ گیگابایت نشان می‌دهد؟", "a" => "چون سازنده یک ترابایت را ۱۰۰۰ به توان چهار بایت تعریف کرده ولی ویندوز همان ظرفیت را بر ۱۰۲۴ به توان چهار تقسیم می‌کند. فضایی گم نشده؛ فقط دو واحد اندازه‌گیری متفاوت است."],
                ["q" => "تفاوت KB و KiB چیست؟", "a" => "KiB دقیقاً ۱۰۲۴ بایت است و KB طبق استاندارد ۱۰۰۰ بایت. واحدهای KiB و MiB و GiB برای رفع همین ابهام تعریف شدند، هرچند در گفتار روزمره کمتر به‌کار می‌روند."],
                ["q" => "پهنای باند را با کدام مبنا حساب کنم؟", "a" => "سرعت شبکه معمولاً با بیت بر ثانیه و مبنای ۱۰۰۰ بیان می‌شود. توجه کنید که هشت بیت یک بایت است، پس اتصال ۱۰۰ مگابیتی در بهترین حالت حدود ۱۲٫۵ مگابایت در ثانیه می‌دهد."],
            ],
        ],
        "en" => [
            "intro" => "Much of the confusion around file sizes and disk space comes from one simple disagreement: hardware makers count a kilobyte as 1000 bytes while operating systems use 1024. This tool lets you switch between those two conventions so you can see exactly where the discrepancy comes from.",
            "steps" => [
                "Enter a value and its source unit.",
                "Equivalents in every unit are computed instantly.",
                "Toggle the SI option to move the base from 1024 to 1000 and watch the figures change.",
            ],
            "faq" => [
                ["q" => "Why does my 1 TB drive show as 931 GB?", "a" => "The manufacturer defines a terabyte as 1000 to the fourth power bytes while Windows divides that same capacity by 1024 to the fourth. No space is missing — these are simply two different units."],
                ["q" => "What is the difference between KB and KiB?", "a" => "KiB is exactly 1024 bytes; KB, by standard, is 1000. The KiB, MiB and GiB units exist precisely to remove this ambiguity, though everyday speech rarely uses them."],
                ["q" => "Which base applies to bandwidth?", "a" => "Network speed is normally quoted in bits per second, base 1000. Remember eight bits make a byte, so a 100 Mbit connection tops out around 12.5 MB per second."],
            ],
        ],
        "tr" => [
            "intro" => "Dosya boyutları ve disk alanı etrafındaki kafa karışıklığının çoğu tek bir uyuşmazlıktan gelir: donanım üreticileri kilobaytı 1000 bayt sayarken işletim sistemleri 1024 kullanır. Bu araç iki kural arasında geçiş yapmanızı sağlar, böylece farkın tam olarak nereden geldiğini görürsünüz.",
            "steps" => [
                "Bir değer ve kaynak birimini girin.",
                "Tüm birimlerdeki karşılıklar anında hesaplanır.",
                "SI seçeneğini işaretleyerek tabanı 1024'ten 1000'e alın ve değerlerin nasıl değiştiğini görün.",
            ],
            "faq" => [
                ["q" => "1 TB diskim neden 931 GB görünüyor?", "a" => "Üretici terabaytı 1000 üzeri dört bayt olarak tanımlar, Windows ise aynı kapasiteyi 1024 üzeri dörde böler. Kaybolan alan yok — bunlar yalnızca iki farklı birim."],
                ["q" => "KB ile KiB arasındaki fark nedir?", "a" => "KiB tam olarak 1024 bayttır; KB standarda göre 1000'dir. KiB, MiB ve GiB birimleri tam da bu belirsizliği gidermek için vardır, gerçi günlük konuşmada nadiren kullanılır."],
                ["q" => "Bant genişliği için hangi taban geçerli?", "a" => "Ağ hızı normalde saniyede bit ve 1000 tabanıyla belirtilir. Sekiz bitin bir bayt ettiğini unutmayın, yani 100 Mbit bağlantı en fazla saniyede yaklaşık 12,5 MB verir."],
            ],
        ],
    ],

];
