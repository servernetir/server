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


    'barcode-generator' => [
        'fa' => [
            'intro' => 'کدست C در استاندارد Code 128 هر دو رقم را در یک نماد ۱۱ ماژولی جا می‌دهد؛ به همین دلیل یک عدد ۱۲ رقمی به‌جای ۱۲ نماد فقط ۶ نماد می‌گیرد. این ابزار خودش بین کدست‌های A، B و C سوییچ می‌کند و کاراکتر کنترلی مبنای ۱۰۳ را حساب می‌کند. رایج‌ترین اشتباه هم بریدن حاشیه سفید کنار بارکد است؛ بدون دست‌کم ۱۰ ماژول فضای خالی، اسکنر اصلاً بارکد را پیدا نمی‌کند.',
            'steps' => [
                'متن یا عدد را در کادر ورودی بنویسید.',
                'استاندارد را روی Code 128 یا EAN-13 بگذارید.',
                'عرض ماژول و ارتفاع میله را با اسلایدرها تنظیم کنید.',
                'تیک متن خوانا را بزنید تا داده زیر میله‌ها چاپ شود.',
                'با دکمه دانلود، خروجی را به صورت PNG ذخیره کنید.',
            ],
            'faq' => [
                ['q' => 'چرا با متن فارسی بارکد ساخته نمی‌شود؟', 'a' => 'Code 128 فقط نویسه‌های ASCII با کد ۰ تا ۱۲۷ را پوشش می‌دهد و حروف فارسی در این محدوده نیستند. ابزار به‌جای تولید بارکد خرابی که هیچ اسکنری درست نمی‌خواند، خطا می‌دهد و همان نویسه غیرمجاز را نشان می‌دهد. برای داده فارسی باید سراغ QR Code بروید.'],
                ['q' => 'فرق کدست A، B و C چیست و کدام را انتخاب کنم؟', 'a' => 'کدست A حروف بزرگ و نویسه‌های کنترلی، کدست B حروف بزرگ و کوچک، و کدست C فقط جفت‌رقم را پوشش می‌دهد. لازم نیست خودتان انتخاب کنید؛ ابزار برای هر بخش از داده کوتاه‌ترین حالت را برمی‌دارد و توالی سوییچ‌ها را زیر بارکد نشان می‌دهد.'],
                ['q' => 'برای EAN-13 باید رقم کنترل را هم وارد کنم؟', 'a' => 'نه. اگر ۱۲ رقم بدهید، رقم سیزدهم با فرمول وزنی ۱ و ۳ محاسبه و اضافه می‌شود. اگر هر ۱۳ رقم را وارد کنید، ابزار رقم کنترل شما را بررسی می‌کند و اگر غلط باشد مقدار درست را نشان می‌دهد.'],
                ['q' => 'چرا بارکدی که چاپ کردم اسکن نمی‌شود؟', 'a' => 'معمولاً به‌خاطر کوچک‌شدن تصویر موقع چاپ یا حذف حاشیه سفید است. فایل PNG را با ابعاد اصلی چاپ کنید و عرض ماژول را دست‌کم روی ۲ بگذارید. توجه کنید که خروجی این ابزار بیت‌مپ است نه وکتور؛ برای چاپ در ابعاد بزرگ، به‌جای بزرگ‌کردن تصویر، عرض ماژول را بالاتر ببرید.'],
            ],
        ],
        'en' => [
            'intro' => 'Code set C in Code 128 packs two digits into a single 11-module symbol, so a 12-digit number costs 6 symbols instead of 12. This generator switches between code sets A, B and C on its own and computes the modulo-103 check character for you. The most common failure is cropping the white margin: without at least 10 modules of quiet zone, a scanner will not even locate the barcode.',
            'steps' => [
                'Type the text or number you want to encode.',
                'Choose Code 128 or EAN-13 as the symbology.',
                'Set the module width and bar height with the sliders.',
                'Tick the readable-text option to print the data under the bars.',
                'Hit Download PNG to save the barcode.',
            ],
            'faq' => [
                ['q' => 'Why does my accented or non-Latin text fail?', 'a' => 'Code 128 covers only ASCII characters 0-127, so accented letters and non-Latin scripts fall outside it. Rather than silently emitting a barcode no scanner decodes correctly, the tool raises an error and shows you the offending character. Use a QR code for that data instead.'],
                ['q' => 'What is the difference between code sets A, B and C?', 'a' => 'Set A carries uppercase letters and control characters, set B carries upper and lowercase, and set C carries pairs of digits only. You never pick one manually: the tool takes the shortest option for each run of data and prints the resulting switch sequence under the barcode.'],
                ['q' => 'Do I have to supply the EAN-13 check digit myself?', 'a' => 'No. Enter 12 digits and the thirteenth is derived with the alternating 1 and 3 weighting. Enter all 13 and the tool validates your check digit instead, reporting the correct value when it disagrees.'],
                ['q' => 'My printed barcode will not scan. What went wrong?', 'a' => 'Usually the image was scaled down on the way to the printer, or the white margin was trimmed. Print the PNG at its native pixel size and keep module width at 2 or above. Note the output is a bitmap, not vector: for large-format printing raise the module width rather than enlarging the image.'],
            ],
        ],
        'tr' => [
            'intro' => 'Code 128 standardındaki C kod seti iki rakamı tek bir 11 modüllük sembole sığdırır; bu yüzden 12 haneli bir sayı 12 yerine yalnızca 6 sembol tutar. Bu araç A, B ve C kod setleri arasında kendisi geçiş yapar ve modulo-103 kontrol karakterini sizin yerinize hesaplar. En sık yapılan hata kenardaki beyaz boşluğu kırpmaktır: en az 10 modüllük sessiz bölge olmadan okuyucu barkodu bulamaz bile.',
            'steps' => [
                'Kodlamak istediğiniz metni veya sayıyı yazın.',
                'Standart olarak Code 128 veya EAN-13 seçin.',
                'Modül genişliğini ve çubuk yüksekliğini kaydırıcılarla ayarlayın.',
                'Verinin çubukların altına yazılması için okunabilir metin kutusunu işaretleyin.',
                'PNG indir düğmesiyle barkodu kaydedin.',
            ],
            'faq' => [
                ['q' => 'Türkçe karakterli metin neden çalışmıyor?', 'a' => 'Code 128 yalnızca 0-127 aralığındaki ASCII karakterlerini kapsar; ç, ğ, ı, ö, ş ve ü bu aralığın dışında kalır. Araç hiçbir okuyucunun doğru çözemeyeceği bozuk bir barkod üretmek yerine hata verir ve sorunlu karakteri gösterir. Bu karakterler gerekiyorsa QR kod kullanın.'],
                ['q' => 'A, B ve C kod setleri arasındaki fark nedir?', 'a' => 'A seti büyük harfleri ve kontrol karakterlerini, B seti büyük ve küçük harfleri, C seti ise yalnızca rakam çiftlerini taşır. Elle seçim yapmanız gerekmez: araç verinin her bölümü için en kısa seçeneği kullanır ve ortaya çıkan geçiş sırasını barkodun altında gösterir.'],
                ['q' => 'EAN-13 kontrol hanesini kendim girmeli miyim?', 'a' => 'Hayır. 12 hane girerseniz on üçüncü hane, dönüşümlü 1 ve 3 ağırlıklandırmasıyla hesaplanıp eklenir. 13 hanenin tamamını girerseniz araç sizin kontrol hanenizi doğrular ve uyuşmadığında doğru değeri bildirir.'],
                ['q' => 'Yazdırdığım barkod neden okunmuyor?', 'a' => 'Genellikle görsel yazdırılırken küçültülmüştür ya da beyaz kenar boşluğu kırpılmıştır. PNG dosyasını kendi piksel boyutunda yazdırın ve modül genişliğini en az 2 tutun. Çıktının vektör değil bitmap olduğunu unutmayın: büyük boyutlu baskı için görseli büyütmek yerine modül genişliğini artırın.'],
            ],
        ],
    ],

    'border-radius-generator' => [
        'fa' => [
            'intro' => 'در CSS مقدارهای درصدی border-radius نسبت به هر محور جداگانه محاسبه می‌شوند؛ یعنی ۵۰٪ روی یک مستطیل به‌جای دایره یک بیضی می‌سازد. با نویسهٔ اسلش (/) هم می‌توانید شعاع افقی و عمودی هر گوشه را جدا تعیین کنید و همین راز ساختن شکل‌های ارگانیک و حبابی است. این ابزار هر چهار گوشه و حالت هشت‌مقداری بیضوی را با پیش‌نمایش زنده در اختیارتان می‌گذارد و کد آمادهٔ کپی را همان لحظه می‌سازد.',
            'steps' => [
                'حالت ساده یا بیضوی را انتخاب کنید و واحد px یا درصد را تعیین کنید.',
                'اسلایدرِ هر گوشه را بکشید و تغییر شکل جعبه را زنده ببینید.',
                'برای شروع سریع یکی از شکل‌های آماده مثل قرصی، مربع‌گِرد یا حبابی را بزنید.',
                'با گزینهٔ قفل گوشه‌ها هر چهار گوشه را هم‌زمان و یکسان تنظیم کنید.',
                'کد border-radius تولیدشده را با دکمهٔ کپی بردارید و در پروژه بگذارید.',
            ],
            'faq' => [
                ['q' => 'چرا border-radius: 50% روی دکمهٔ مستطیلی دایره نمی‌سازد؟', 'a' => 'چون درصدها نسبت به عرض و ارتفاع به‌طور جداگانه اعمال می‌شوند و روی عنصر غیرمربع نتیجه بیضی است. برای گوشهٔ کاملاً گرد (شکل قرصی) از مقدار px بزرگ‌تر از نصف کوچک‌ترین ضلع استفاده کنید یا شکل آمادهٔ «قرصی» را بزنید.'],
                ['q' => 'اسلش (/) در مقدار border-radius چه‌کار می‌کند؟', 'a' => 'مقدارهای پیش از اسلش شعاع افقیِ گوشه‌ها و مقدارهای پس از آن شعاع عمودی آن‌ها هستند. همین جداسازی است که ساخت گوشه‌های حبابی و ارگانیک را ممکن می‌کند.'],
                ['q' => 'آیا این شکل‌های حبابی همان squircle واقعی اپل هستند؟', 'a' => 'خیر. squircle واقعی یک اَبَربیضی (superellipse) است و با border-radius دقیق ساخته نمی‌شود؛ خروجی این ابزار تقریبی نزدیک و کاملاً سازگار با مرورگرهاست، بدون نیاز به SVG یا clip-path.'],
                ['q' => 'ترتیب چهار مقدار border-radius چگونه است؟', 'a' => 'به‌ترتیب بالا-چپ، بالا-راست، پایین-راست، پایین-چپ و ساعت‌گرد از گوشهٔ بالا-چپ. هرگاه بعضی مقدارها برابر باشند، ابزار به‌صورت خودکار کد را کوتاه می‌کند.'],
            ],
        ],
        'en' => [
            'intro' => 'In CSS, percentage border-radius values are resolved against each axis independently, so 50% on a rectangle produces an ellipse rather than a circle. The slash syntax (/) lets you set the horizontal and vertical radius of every corner separately, which is exactly how organic blob shapes are built. This generator exposes all four corners plus the 8-value elliptical mode with a live preview and copy-ready CSS.',
            'steps' => [
                'Pick Simple or Elliptical mode and choose px or % as the unit.',
                'Drag each corner slider and watch the preview box reshape live.',
                'Hit a preset shape — pill, squircle or blob — for an instant starting point.',
                'Turn on Link corners to move all four corners together in lockstep.',
                'Copy the generated border-radius rule straight into your stylesheet.',
            ],
            'faq' => [
                ['q' => "Why doesn't border-radius: 50% make my rectangular button a circle?", 'a' => 'Percentages apply to width and height separately, so on a non-square element you get an ellipse. For a true stadium/pill shape use a px value larger than half the shorter side, or click the Pill preset.'],
                ['q' => 'What does the slash (/) do in a border-radius value?', 'a' => 'Values before the slash are the horizontal radii and values after it are the vertical radii of each corner. That split is what makes organic, blob-like corners possible.'],
                ['q' => 'Are these blobs real Apple squircles?', 'a' => 'No. A true squircle is a superellipse and cannot be reproduced exactly with border-radius; this tool gives a close, fully browser-native approximation with no SVG or clip-path needed.'],
                ['q' => 'What order are the four values in?', 'a' => 'Top-left, top-right, bottom-right, bottom-left — clockwise from the top-left corner. When some values match, the tool automatically shortens the output.'],
            ],
        ],
        'tr' => [
            'intro' => 'CSS icinde yuzde cinsinden border-radius degerleri her ekseni ayri hesaplar; yani bir dikdortgende yuzde 50 daire degil elips uretir. Egik cizgi (/) soz dizimiyle her kosenin yatay ve dikey yaricapini ayri ayri verebilir, organik damla sekilleri tam olarak boyle olusturulur. Bu arac dort koseyi ve sekiz degerli eliptik modu canli onizleme ve kopyalanabilir CSS ile sunar.',
            'steps' => [
                'Basit veya Eliptik modu secin ve birim olarak px ya da yuzde belirleyin.',
                'Her kose kaydiricisini surukleyin ve onizleme kutusunun canli degistigini gorun.',
                'Hap, kare-daire veya damla gibi hazir bir sekle tiklayarak hizli baslayin.',
                'Koseleri bagla secenegini acarak dort koseyi ayni anda ve esit ayarlayin.',
                'Uretilen border-radius kuralini kopyalayip stil dosyaniza yapistirin.',
            ],
            'faq' => [
                ['q' => 'border-radius: 50% neden dikdortgen dugmemi daire yapmiyor?', 'a' => 'Yuzdeler genislik ve yukseklige ayri uygulanir, bu yuzden kare olmayan bir ogede elips elde edersiniz. Gercek hap sekli icin kisa kenarin yarisindan buyuk bir px degeri kullanin ya da Hap hazir seklini secin.'],
                ['q' => 'border-radius degerindeki egik cizgi (/) ne ise yarar?', 'a' => 'Egik cizgiden onceki degerler yatay yaricap, sonraki degerler dikey yaricaptir. Bu ayrim organik damla koselerini mumkun kilar.'],
                ['q' => 'Bu damlalar gercek Apple kare-daireleri mi?', 'a' => 'Hayir. Gercek bir squircle bir superelipstir ve border-radius ile birebir uretilemez; bu arac SVG veya clip-path olmadan tarayiciya tam uyumlu yakin bir yaklasim verir.'],
                ['q' => 'Dort deger hangi sirada yazilir?', 'a' => 'Ust sol, ust sag, alt sag, alt sol — ust sol koseden saat yonunde. Bazi degerler esit oldugunda arac ciktiyi otomatik kisaltir.'],
            ],
        ],
    ],

    'clip-path-generator' => [
        'fa' => [
            'intro' => 'خصوصیت clip-path فقط ناحیهٔ نمایش یک عنصر را می‌بُرد و در چیدمان صفحه هیچ فضایی آزاد نمی‌کند؛ پس بخش‌های بریده‌شده همچنان کلیک و هاور را دریافت می‌کنند مگر آنکه pointer-events را مدیریت کنید. با این ابزار رأس‌های چندضلعی را مستقیم روی پیش‌نمایش می‌کشید و کد دقیق clip-path را برای پالیگون، دایره، بیضی و inset می‌گیرید. مقادیر بر حسب درصدِ خودِ عنصر خروجی می‌شوند تا شکل واکنش‌گرا بماند.',
            'steps' => [
                'حالت را انتخاب کنید: چندضلعی، دایره، بیضی یا inset؛ یا شکل آماده‌ای مثل ستاره و حباب گفتار را بارگذاری کنید.',
                'دستگیره‌ها را روی پیش‌نمایش بکشید تا شکل دلخواه ساخته شود.',
                'در حالت چندضلعی با دابل‌کلیک نقطه اضافه و با راست‌کلیک روی دستگیره آن را حذف کنید.',
                'در صورت نیاز تصویر خودتان را بارگذاری کنید تا برش روی محتوای واقعی دیده شود.',
                'کد clip-path را کپی و در استایل عنصر خود قرار دهید.',
            ],
            'faq' => [
                ['q' => 'تفاوت clip-path با overflow: hidden چیست؟', 'a' => 'overflow فقط می‌تواند محتوا را در یک مستطیل ببُرد، اما clip-path هر مسیر هندسی مانند چندضلعی، دایره یا بیضی را می‌پذیرد و ناحیهٔ بیرون کاملاً شفاف می‌شود؛ در عوض clip-path برخلاف overflow اسکرول محتوا را کنترل نمی‌کند.'],
                ['q' => 'چرا سایهٔ box-shadow روی عنصر بریده‌شده ناپدید می‌شود؟', 'a' => 'چون clip-path سایه را هم همراه با عنصر می‌بُرد. برای سایه‌ای که لبهٔ برش را دنبال کند باید به‌جای box-shadow از filter: drop-shadow() روی همان عنصر یا والد آن استفاده کنید.'],
                ['q' => 'آیا می‌توان clip-path را انیمیشن داد؟', 'a' => 'بله، اما فقط زمانی که نوع شکل و تعداد نقاط بین دو حالت یکسان بماند؛ می‌توان بین دو پالیگون با شمار نقاط برابر transition گذاشت، ولی تبدیل دایره به پالیگون یا تغییر تعداد رأس‌ها انیمیشن‌پذیر نیست.'],
                ['q' => 'مقادیر درصدی نسبت به چه چیزی حساب می‌شوند؟', 'a' => 'نسبت به border-box خودِ عنصر، نه صفحه یا ویوپورت. در circle شعاعِ درصدی به یک قطر مرجع بر پایهٔ ابعاد عنصر گره می‌خورد؛ به همین دلیل پیش‌نمایش این ابزار مربع است تا دایره دقیق نمایش داده شود.'],
            ],
        ],
        'en' => [
            'intro' => 'The clip-path property only trims where an element paints; it never frees up layout space, so clipped-away regions still capture clicks and hovers unless you also set pointer-events. This generator lets you drag polygon vertices straight over the preview and copy exact clip-path code for polygon, circle, ellipse and inset. All values are emitted as percentages of the element itself, so the shape stays responsive.',
            'steps' => [
                'Pick a mode — polygon, circle, ellipse or inset — or load a preset such as star or speech bubble.',
                'Drag the handles over the preview until the shape looks right.',
                'In polygon mode, double-click to add a vertex and right-click a handle to remove it.',
                'Optionally upload your own image so you can see the clip against real content.',
                "Copy the clip-path code and paste it into your element's CSS.",
            ],
            'faq' => [
                ['q' => 'What is the difference between clip-path and overflow: hidden?', 'a' => 'overflow can only cut content to a rectangle, while clip-path accepts any geometric path — polygon, circle or ellipse — and makes everything outside fully transparent; the trade-off is that clip-path does not clip scrolling the way overflow does.'],
                ['q' => 'Why does my box-shadow disappear on a clipped element?', 'a' => 'clip-path removes the shadow along with the element. To keep a shadow that follows the clipped edge, use filter: drop-shadow() on the element or its parent instead of box-shadow.'],
                ['q' => 'Can clip-path be animated?', 'a' => 'Yes, but only when the shape function and point count stay identical between states — you can transition between two polygons with the same number of points, but morphing a circle into a polygon or changing the vertex count is not animatable.'],
                ['q' => 'What are the percentage values relative to?', 'a' => "They resolve against the element's own border-box, not the page or viewport. For circle() the percentage radius is tied to a reference diameter derived from the element's size, which is why this preview is square so the circle renders accurately."],
            ],
        ],
        'tr' => [
            'intro' => 'clip-path ozelligi bir ogenin yalnizca boyandigi alani kirpar ve sayfa yerlesiminde hic bosluk birakmaz; bu yuzden kirpilan bolgeler pointer-events ayarlanmadikca tiklama ve hover almaya devam eder. Bu arac ile cokgen noktalarini dogrudan onizleme uzerinde surukler ve cokgen, daire, elips ve inset icin tam clip-path kodunu alirsiniz. Tum degerler ogenin kendi boyutuna gore yuzde olarak uretilir, boylece sekil duyarli kalir.',
            'steps' => [
                'Bir mod secin: cokgen, daire, elips veya inset; ya da yildiz veya konusma balonu gibi hazir bir sekil yukleyin.',
                'Sekli istediginiz gibi ayarlamak icin tutamaklari onizleme uzerinde surukleyin.',
                'Cokgen modunda nokta eklemek icin cift tiklayin, silmek icin tutamaga sag tiklayin.',
                'Isterseniz kendi resminizi yukleyin, boylece kirpmayi gercek icerik uzerinde gorursunuz.',
                'clip-path kodunu kopyalayip ogenizin CSS koduna yapistirin.',
            ],
            'faq' => [
                ['q' => 'clip-path ile overflow: hidden arasindaki fark nedir?', 'a' => 'overflow icerigi yalnizca bir dikdortgene kirpabilir, oysa clip-path cokgen, daire veya elips gibi her geometrik yolu kabul eder ve disarida kalan alan tamamen saydam olur; buna karsilik clip-path kaydirmayi overflow gibi kirpmaz.'],
                ['q' => 'Kirpilan ogede box-shadow neden kayboluyor?', 'a' => 'clip-path golgeyi de oge ile birlikte keser. Kirpilan kenari izleyen bir golge icin box-shadow yerine oge veya ust ogesi uzerinde filter: drop-shadow() kullanin.'],
                ['q' => 'clip-path animasyonlu olabilir mi?', 'a' => 'Evet, ancak yalnizca sekil turu ve nokta sayisi iki durum arasinda ayni kaldiginda; ayni sayida noktaya sahip iki cokgen arasinda gecis yapabilirsiniz ama daireyi cokgene donusturmek veya nokta sayisini degistirmek animasyonlu olmaz.'],
                ['q' => 'Yuzde degerleri neye gore hesaplanir?', 'a' => 'Sayfaya veya goruntu alanina degil, ogenin kendi border-box degerine gore cozulur. circle() icin yuzde yaricap, ogenin boyutundan turetilen bir referans capa baglidir; bu nedenle bu onizleme kareyi kullanir ki daire dogru gorunsun.'],
            ],
        ],
    ],

    'color-shades' => [
        'fa' => [
            'intro' => 'در فضای رنگی HSL، روشناییِ ۵۰٪ برای زرد و آبی دقیقاً یک عدد است، اما چشم زردِ ۵۰٪ را چند برابر روشن‌تر از آبیِ ۵۰٪ می‌بیند؛ به همین دلیل طیفی که فقط روی مؤلفه‌ی L حرکت می‌کند، لزوماً پله‌های بصریِ یکنواخت نمی‌دهد. این ابزار رمپ استاندارد ۵۰ تا ۹۰۰ را با درون‌یابی رنگ پایه به سمت سفید و سیاه می‌سازد و کنار هر پله نسبت کنتراست واقعی WCAG را می‌نویسد تا به‌جای حدس زدن، با عدد تصمیم بگیرید. فام (Hue) در تمام ده پله ثابت می‌ماند، پس طیف از هویت رنگی برند خارج نمی‌شود.',
            'steps' => [
                'رنگ پایه را با انتخابگر بردارید یا کد آن را به شکل HEX، RGB یا HSL در کادر بنویسید.',
                'نام متغیر را بگذارید؛ همین نام در خروجی CSS و Tailwind به کار می‌رود و کاراکترهای غیرمجاز خودکار به خط تیره تبدیل می‌شود.',
                'اگر تینت‌ها و شیدها بیش از حد سیر به نظر می‌رسند، گزینه‌ی ملایم‌کردن دو سر طیف را بزنید تا اشباع در پله‌های انتهایی کم شود.',
                'در هر ردیف کد HEX و نسبت کنتراست روی سفید و سیاه را ببینید و با کلیک روی کد، آن را کپی کنید.',
                'در پایان، بلوک متغیرهای CSS یا قطعه‌ی تنظیمات Tailwind را یکجا کپی کنید.',
            ],
            'faq' => [
                ['q' => 'چرا فاصله‌ی بصری پله‌ها یکنواخت نیست؟', 'a' => 'چون HSL فضای ادراکی نیست. اختلاف روشنایی عددی با اختلافی که چشم می‌بیند یکی نیست، مخصوصاً در زرد و فیروزه‌ای که در روشنایی یکسان بسیار درخشان‌تر به نظر می‌رسند. اگر فاصله‌ی کاملاً یکنواخت می‌خواهید باید سراغ فضاهایی مثل OKLCH بروید؛ در این ابزار ملاک عملی شما همان ستون نسبت کنتراست است که عدد واقعی و قابل استناد می‌دهد.'],
                ['q' => 'برای متن اصلی سایت کدام پله را بردارم؟', 'a' => 'تصور رایج این است که پله‌ی ۵۰۰ یعنی رنگ برند، پس برای متن هم مناسب است؛ این معمولاً غلط است. مثلاً فیروزه‌ای ‎#22d3ee‎ روی سفید فقط ۱٫۸۱:۱ کنتراست دارد و حتی برای متن درشت هم رد می‌شود. روی پس‌زمینه‌ی سفید معمولاً باید تا پله‌ی ۶۰۰ یا ۷۰۰ پایین بروید تا به ۴٫۵:۱ برسید — ستون «روی سفید» دقیقاً همین را نشان می‌دهد.'],
                ['q' => 'چرا وقتی رنگ پایه‌ام روشن است، پله‌های ۵۰ تا ۳۰۰ تقریباً یکسان درمی‌آیند؟', 'a' => 'این محدودیت واقعی روش کار است: رنگ پایه همیشه روی پله‌ی ۵۰۰ می‌نشیند، پس اگر روشنایی آن مثلاً ۹۰٪ باشد، تا سفید فقط ۱۰٪ فضا باقی می‌ماند و چهار پله‌ی بالایی ناچار در هم می‌روند. برای گرفتن رمپ متعادل، رنگی با روشنایی حدود ۴۵ تا ۶۰ درصد را به‌عنوان پایه بدهید؛ عدد HSL زیر کادر ورودی همین را به شما نشان می‌دهد.'],
                ['q' => 'خروجی با پالت‌های خود Tailwind یکی می‌شود؟', 'a' => 'خیر. پالت‌های پیش‌فرض Tailwind دستی و چشمی تنظیم شده‌اند و فام‌شان در طول طیف کمی جابه‌جا می‌شود. این ابزار یک رمپ ریاضی و یکدست از رنگ خودتان می‌سازد که فام ثابتی دارد. قطعه‌ی خروجی زیر theme.extend.colors قرار می‌گیرد و پالت‌های پیش‌فرض را حذف نمی‌کند.'],
            ],
        ],
        'en' => [
            'intro' => 'In HSL, 50% lightness is literally the same number for yellow and for blue, yet the eye reads 50% yellow as far brighter than 50% blue — so a ramp built by moving the L channel alone will not give visually even steps. This generator builds the standard 50-900 scale by interpolating your base colour toward white and black, and prints the real WCAG contrast ratio beside every step so you choose by number rather than by eye. Hue is held fixed across all ten steps, so the scale never drifts off-brand.',
            'steps' => [
                'Pick the base colour with the picker, or type it as HEX, RGB or HSL in the text field.',
                'Set the variable name; it is reused in both outputs and any illegal characters are folded to hyphens automatically.',
                'If the tints and shades look too saturated, enable softening to pull saturation down at the ends of the ramp.',
                'Read each row for its HEX and its contrast against white and black, and click a HEX to copy that single step.',
                'Copy the whole CSS custom-property block or the Tailwind config snippet from the panes below.',
            ],
            'faq' => [
                ['q' => 'Why are the steps not evenly spaced to my eye?', 'a' => 'Because HSL is not a perceptual colour space. A fixed numeric lightness gap does not correspond to a fixed perceived gap, which is most obvious with yellows and cyans that look far more luminous than blues at identical L. For truly even spacing you would need a perceptual space such as OKLCH; here the contrast-ratio column is your practical, objective check.'],
                ['q' => 'Which step should I use for body text?', 'a' => 'A common assumption is that 500 is the brand colour and therefore safe for text. It usually is not. Cyan #22d3ee scores only 1.81:1 on white, which fails even the large-text threshold. On a white background you typically have to go down to 600 or 700 to clear 4.5:1 — the on-white column tells you exactly where that happens.'],
                ['q' => 'Why do 50-300 look almost identical when I start from a light colour?', 'a' => 'That is a genuine limitation of the method. The base always lands on step 500, so if its lightness is already around 90% there is only 10% of headroom left toward white and the top four steps necessarily bunch up. Feed it a mid-tone with roughly 45-60% lightness for a balanced ramp; the HSL readout under the input shows you where you are.'],
                ['q' => "Will the output match Tailwind's own palettes?", 'a' => "No. Tailwind's default palettes are hand-tuned and deliberately shift hue slightly along the scale. This tool produces a mathematically consistent, fixed-hue ramp from your own colour. The snippet goes under theme.extend.colors, so it adds your scale without removing the built-in palettes."],
            ],
        ],
        'tr' => [
            'intro' => 'HSL icinde %50 aciklik sari ve mavi icin birebir ayni sayidir, ama goz %50 sariyi %50 maviden cok daha parlak okur; bu yuzden yalnizca L kanali uzerinde ilerleyen bir rampa gorsel olarak esit araliklar vermez. Bu urec standart 50-900 olcegini ana renginizi beyaza ve siyaha dogru enterpole ederek kurar ve her adimin yanina gercek WCAG kontrast oranini yazar, boylece goz kararyla degil sayiyla secersiniz. Renk tonu on adim boyunca sabit tutulur, yani olcek marka kimliginden sapmaz.',
            'steps' => [
                'Ana rengi seciciyle alin veya metin kutusuna HEX, RGB ya da HSL olarak yazin.',
                'Degisken adini belirleyin; ayni ad iki ciktida da kullanilir ve gecersiz karakterler otomatik olarak tireye cevrilir.',
                'Acik ve koyu tonlar fazla doygun gorunuyorsa yumusatma secenegini acin, boylece uc adimlarda doygunluk duser.',
                'Her satirda HEX kodunu ve beyaza ile siyaha karsi kontrasti okuyun; tek bir adimi kopyalamak icin koda tiklayin.',
                'Alttaki panellerden CSS degisken blogunu veya Tailwind yapilandirma parcasini toptan kopyalayin.',
            ],
            'faq' => [
                ['q' => 'Adimlar neden gozume esit araliklarda gorunmuyor?', 'a' => 'Cunku HSL algisal bir renk uzayi degildir. Sabit bir sayisal aciklik farki sabit bir algilanan farka karsilik gelmez; bu ozellikle ayni L degerinde mavilerden cok daha parlak gorunen sari ve turkuazlarda belirgindir. Gercekten esit aralik icin OKLCH gibi algisal bir uzay gerekir; burada pratik ve nesnel olcutunuz kontrast orani sutunudur.'],
                ['q' => 'Govde metni icin hangi adimi kullanmaliyim?', 'a' => 'Yaygin bir varsayim 500 adiminin marka rengi oldugu ve dolayisiyla metin icin guvenli oldugudur. Genelde degildir. Turkuaz #22d3ee beyaz uzerinde yalnizca 1.81:1 alir ve buyuk metin esigini bile gecemez. Beyaz zeminde 4.5:1 degerini yakalamak icin cogunlukla 600 veya 700 adimina inmeniz gerekir; beyaz uzerinde sutunu bunun tam olarak nerede gerceklestigini soyler.'],
                ['q' => 'Acik bir renkle basladigimda 50-300 neden neredeyse ayni gorunuyor?', 'a' => 'Bu yontemin gercek bir sinirlamasidir. Ana renk her zaman 500 adimina oturur; acikligi zaten %90 civarindaysa beyaza dogru yalnizca %10 pay kalir ve ustteki dort adim zorunlu olarak birbirine yaklasir. Dengeli bir rampa icin acikligi kabaca %45-60 arasinda olan bir orta ton verin; giris kutusunun altindaki HSL degeri nerede oldugunuzu gosterir.'],
                ['q' => 'Cikti Tailwind paletleriyle ayni olur mu?', 'a' => 'Hayir. Tailwind varsayilan paletleri elle ayarlanmistir ve olcek boyunca renk tonunu bilerek biraz kaydirir. Bu arac kendi renginizden matematiksel olarak tutarli, sabit tonlu bir rampa uretir. Parca theme.extend.colors altina girer, yani yerlesik paletleri silmeden kendi olceginizi ekler.'],
            ],
        ],
    ],

    'css-formatter' => [
        'fa' => [
            'intro' => 'در یک data URI مثل url(data:image/svg+xml;base64,...) سمی‌کالن بخشی از خود آدرس است، نه جداکنندهٔ اعلان. فرمترهایی که فقط روی { و } و ; برش می‌زنند دقیقاً همین‌جا استایل‌شیت را دو نیم می‌کنند و همین اتفاق برای content: "a { b" هم می‌افتد. این ابزار به‌جای برش متنی، CSS را توکن‌به‌توکن می‌خواند، بنابراین رشته‌ها، کامنت‌ها و محتوای url() دست‌نخورده باقی می‌مانند.',
            'steps' => [
                'CSS فشرده را در کادر ورودی بچسبانید؛ خروجی بی‌درنگ ساخته می‌شود.',
                'تورفتگی را روی ۲ فاصله، ۴ فاصله یا Tab بگذارید.',
                'جای آکولاد باز را روی «همان خط» یا «خط بعد» تنظیم کنید.',
                'تیک‌های «هر انتخابگر در یک خط»، «خط خالی بین قاعده‌ها» و «نگه‌داشتن کامنت‌ها» را مطابق سلیقه‌تان بزنید.',
                'شمارندهٔ قاعده و اعلان را چک کنید و با دکمهٔ کپی خروجی را بردارید.',
            ],
            'faq' => [
                ['q' => 'آیا کد من جایی آپلود می‌شود؟', 'a' => 'نه. کل تجزیه و بازسازی با JavaScript داخل همان تبِ مرورگر انجام می‌شود و ابزار هیچ درخواست شبکه‌ای نمی‌زند. می‌توانید تب Network در ابزار توسعه‌دهنده را باز بگذارید و ببینید که هنگام مرتب‌سازی هیچ درخواستی ثبت نمی‌شود.'],
                ['q' => 'آیا مرتب‌سازی می‌تواند ظاهر سایت را عوض کند؟', 'a' => 'این تصور که «فاصله در CSS بی‌معناست» غلط است. فاصله در انتخابگر معنا دارد؛ ‎.a .b‎ یعنی نواده و ‎.a.b‎ یعنی هر دو کلاس روی یک عنصر. داخل calc() هم فاصلهٔ اطراف + و - اجباری است، وگرنه مقدار نامعتبر می‌شود. این ابزار همین فاصله‌های معنادار را از منبع حفظ می‌کند و فقط فاصلهٔ اطراف کاما، دونقطه و ترکیب‌گرهای > و + و ~ را یکدست می‌کند.'],
                ['q' => 'وقتی «نگه‌داشتن کامنت‌ها» را خاموش کنم چه اتفاقی می‌افتد؟', 'a' => 'این تنها جایی است که خروجی صد در صد هم‌ارز منبع نیست. کامنت حذف‌شده با یک فاصله جایگزین می‌شود تا توکن‌های دو طرفش به هم نچسبند؛ در نتیجه ‎.a/*x*/.b‎ به ‎.a .b‎ تبدیل می‌شود که معنایش با ‎.a.b‎ فرق دارد. اگر در استایل‌شیت‌تان کامنت وسط انتخابگر دارید، این گزینه را روشن نگه دارید.'],
                ['q' => 'از CSS تودرتو و at-rule های جدید پشتیبانی می‌کند؟', 'a' => 'بله. پارسر عمومی است و هر بلوکی را که با { باز شود دنبال می‌کند، پس تودرتویی بومی CSS با ‎&‎ و همچنین media@، supports@، container@، layer@، keyframes@، font-face@ و page@ به‌درستی تورفتگی می‌گیرند. اگر آکولاد یا رشته‌ای بسته نشده باشد، ابزار خروجی را ترمیم می‌کند و پیام هشدار نشان می‌دهد.'],
            ],
        ],
        'en' => [
            'intro' => 'In a data URI like url(data:image/svg+xml;base64,...) the semicolon is part of the address, not a declaration separator. Formatters that simply split on {, } and ; cut the stylesheet in half right there, and the same happens to content: "a { b". This tool tokenises the CSS instead of slicing the text, so strings, comments and url() contents survive untouched.',
            'steps' => [
                'Paste your minified CSS into the input box; the output is rebuilt as you type.',
                'Set the indent to 2 spaces, 4 spaces or a tab.',
                'Choose whether the opening brace sits on the same line or the next line.',
                'Toggle one selector per line, blank line between rules, and keep comments.',
                'Check the rule and declaration counters, then hit Copy.',
            ],
            'faq' => [
                ['q' => 'Is my CSS uploaded anywhere?', 'a' => 'No. All parsing and re-emitting happens in JavaScript inside your own browser tab, and the tool makes no network requests at all. Leave the Network panel of your devtools open while you format and you will see zero requests logged.'],
                ['q' => 'Can beautifying change how my page renders?', 'a' => 'The common belief that whitespace in CSS is always meaningless is wrong. Whitespace is significant in selectors: .a .b is a descendant match while .a.b requires both classes on one element, and inside calc() the spaces around + and - are mandatory or the value is invalid. This formatter preserves those meaningful gaps from the source and only normalises spacing around commas, colons and the >, + and ~ combinators.'],
                ['q' => 'What happens when I turn off Keep comments?', 'a' => 'That is the one setting where output is not byte-equivalent to the source. A removed comment is replaced with a single space so the tokens on either side cannot fuse, which means .a/*x*/.b becomes .a .b — semantically different from .a.b. If your sheet has comments inside a selector or a value, leave the option on.'],
                ['q' => 'Does it handle nested CSS and modern at-rules?', 'a' => 'Yes. The parser is generic and follows any block opened with {, so native CSS nesting with & is indented correctly, along with @media, @supports, @container, @layer, @keyframes, @font-face and @page. If a brace, string or comment is left unclosed, the tool repairs the output and shows a warning instead of silently producing garbage.'],
            ],
        ],
        'tr' => [
            'intro' => 'url(data:image/svg+xml;base64,...) gibi bir data URI içinde noktalı virgül adresin parçasıdır, bildirim ayırıcısı değildir. Yalnızca {, } ve ; karakterlerinden bölen biçimlendiriciler stil dosyasını tam burada ikiye ayırır; aynı sorun content: "a { b" için de geçerlidir. Bu araç metni kesmek yerine CSS kodunu token token okur, böylece metinler, yorumlar ve url() içerikleri hiç bozulmadan kalır.',
            'steps' => [
                'Küçültülmüş CSS kodunu giriş kutusuna yapıştırın; çıktı siz yazarken yeniden oluşturulur.',
                'Girintiyi 2 boşluk, 4 boşluk veya sekme olarak seçin.',
                'Açılış parantezinin aynı satırda mı yoksa sonraki satırda mı duracağını belirleyin.',
                'Satır başına tek seçici, kurallar arasında boş satır ve yorumları koru seçeneklerini ihtiyacınıza göre açıp kapatın.',
                'Kural ve bildirim sayaçlarını kontrol edip Kopyala düğmesine basın.',
            ],
            'faq' => [
                ['q' => 'CSS kodum bir yere yükleniyor mu?', 'a' => 'Hayır. Tüm ayrıştırma ve yeniden yazma işlemi kendi tarayıcı sekmenizde JavaScript ile yapılır ve araç hiçbir ağ isteği göndermez. Biçimlendirme sırasında geliştirici araçlarının Network sekmesini açık bırakırsanız hiçbir istek kaydedilmediğini görürsünüz.'],
                ['q' => 'Biçimlendirme sayfamın görünümünü değiştirebilir mi?', 'a' => 'CSS içinde boşlukların her zaman anlamsız olduğu yaygın inancı yanlıştır. Seçicilerde boşluk anlamlıdır: .a .b torun eşleşmesi demektir, .a.b ise iki sınıfın da aynı öğede olmasını ister. calc() içinde de + ve - çevresindeki boşluklar zorunludur, yoksa değer geçersiz olur. Bu araç kaynaktaki bu anlamlı boşlukları korur ve yalnızca virgül, iki nokta ile >, + ve ~ birleştiricilerinin çevresindeki aralığı standartlaştırır.'],
                ['q' => 'Yorumları koru seçeneğini kapatırsam ne olur?', 'a' => 'Çıktının kaynakla bayt bazında eşdeğer olmadığı tek ayar budur. Silinen yorumun yerine tek bir boşluk konur ki iki yanındaki tokenlar birbirine yapışmasın; bu da .a/*x*/.b ifadesini .a .b haline getirir ve bu .a.b ile aynı anlama gelmez. Stil dosyanızda seçici ya da değer ortasında yorum varsa bu seçeneği açık bırakın.'],
                ['q' => 'İç içe CSS ve yeni at-rule yapıları destekleniyor mu?', 'a' => 'Evet. Ayrıştırıcı geneldir ve { ile açılan her bloğu izler; bu yüzden & ile yazılan yerleşik CSS iç içe kullanımı doğru girintilenir, ayrıca @media, @supports, @container, @layer, @keyframes, @font-face ve @page da desteklenir. Kapatılmamış bir parantez, metin veya yorum varsa araç sessizce bozuk çıktı üretmek yerine sonucu onarır ve uyarı gösterir.'],
            ],
        ],
    ],

    'css-loader' => [
        'fa' => [
            'intro' => 'چرخاندن یک اسپینر با transform: rotate روی لایه‌ی compositor مرورگر انجام می‌شود و رشته‌ی اصلی را اشغال نمی‌کند؛ اما اگر همان حرکت را با تغییر width یا margin بسازید، مرورگر مجبور است در هر فریم دوباره layout بگیرد و لودر دقیقاً وقتی صفحه شلوغ است کند می‌شود. هر ۱۲ لودر این ابزار با CSS خالص نوشته شده‌اند و به‌جز «موج دایره‌ای»، همگی فقط transform و opacity را انیمیت می‌کنند. اندازه، ضخامت، مدت هر دور و دو رنگ را تنظیم می‌کنید و کد HTML و CSS آماده را برمی‌دارید.',
            'steps' => [
                'از گالری یکی از ۱۲ لودر را انتخاب کنید؛ کارت انتخاب‌شده با حاشیه‌ی فیروزه‌ای مشخص می‌شود.',
                'اندازه، ضخامت خط و مدت هر دور را با کشویی‌ها تنظیم کنید؛ هر ۱۲ نمونه هم‌زمان با همان مقادیر به‌روز می‌شوند.',
                'رنگ اصلی و رنگ دوم را انتخاب کنید و پس‌زمینه‌ی پیش‌نمایش را روی تیره و روشن بگذارید تا کنتراست را در هر دو تم ببینید.',
                'اگر لازم است متن aria-label را عوض کنید و گزینه‌ی prefers-reduced-motion را بزنید.',
                'کد HTML و CSS را جداگانه یا با هم کپی کنید و مستقیم در پروژه بگذارید.',
            ],
            'faq' => [
                ['q' => 'این لودرها به جاوااسکریپت یا فایل تصویری نیاز دارند؟', 'a' => 'نه. خروجی یک div ساده به‌همراه چند قاعده‌ی CSS و بلوک keyframes است؛ هیچ SVG، GIF، فونت آیکونی یا اسکریپتی لازم نیست. چون انیمیشن سمت CSS اجرا می‌شود، حتی وقتی جاوااسکریپت صفحه هنوز اجرا نشده هم لودر می‌چرخد — که معمولاً دقیقاً همان لحظه‌ای است که به لودر نیاز دارید.'],
                ['q' => 'چرا گزینه‌ی prefers-reduced-motion انیمیشن را کاملاً خاموش نمی‌کند؟', 'a' => 'خیلی‌ها animation: none می‌نویسند، ولی این کار عنصر را روی فریم صفر قفل می‌کند؛ در «موج دایره‌ای» فریم صفر عرض صفر دارد و در «تپش» مقیاس و شفافیت پایین است، یعنی نشانگر عملاً ناپدید می‌شود و کاربر فکر می‌کند صفحه هنگ کرده. این ابزار به‌جای خاموش‌کردن، مدت انیمیشن را چهار برابر می‌کند تا حرکت آرام شود ولی نشانگر دیده بماند.'],
                ['q' => 'کدام لودر برای موبایل‌های ضعیف مناسب‌تر است؟', 'a' => 'حلقه، پره‌ها، میله‌ها و نقطه‌های محوشونده فقط transform و opacity را انیمیت می‌کنند و روی GPU اجرا می‌شوند. «موج دایره‌ای» عمداً width و height را انیمیت می‌کند، چون شکلش بدون آن ساخته نمی‌شود؛ همین باعث layout مجدد در هر فریم است، پس آن را در اندازه‌ی کوچک و فقط یک‌بار در صفحه به کار ببرید.'],
                ['q' => 'می‌توانم رنگ را به متغیر CSS برندم وصل کنم؟', 'a' => 'بله. انتخابگر رنگ مرورگر فقط با کد هگز کار می‌کند، بنابراین خروجی مقدار ثابتی مثل #22d3ee دارد؛ بعد از کپی کافی است آن را با var(--brand) جایگزین کنید. به‌جای rgba(...) هم می‌توانید color-mix(in srgb, var(--brand) 22%, transparent) بنویسید.'],
            ],
        ],
        'en' => [
            'intro' => "Spinning an element with transform: rotate is handled on the browser's compositor and never blocks the main thread; build the same motion by animating width or margin and the browser re-runs layout on every frame, so the loader stutters exactly when the page is busiest. All 12 loaders here are pure CSS, and apart from Ripple every one animates only transform and opacity. Set the size, thickness, cycle duration and two colours, then take the ready HTML and CSS.",
            'steps' => [
                'Pick one of the 12 loaders in the gallery — the selected card gets a cyan border.',
                'Set the size, line thickness and cycle duration with the sliders; all 12 previews update together.',
                'Choose the main and second colour, then flip the preview background between dark and light to check contrast in both themes.',
                'Change the aria-label text and tick the prefers-reduced-motion option if you need them.',
                'Copy the HTML and the CSS separately or together and paste them straight into your project.',
            ],
            'faq' => [
                ['q' => 'Do these loaders need JavaScript or an image file?', 'a' => "No. The output is a plain div plus a few CSS rules and a keyframes block — no SVG, GIF, icon font or script. Because the animation runs in CSS, the loader spins even before the page's JavaScript has executed, which is usually the exact moment you need a loader."],
                ['q' => 'Why does the prefers-reduced-motion option not switch the animation off completely?', 'a' => 'Most people write animation: none, but that freezes the element on its 0% frame. For Ripple that frame has zero width and for Pulse it is scaled down and semi-transparent, so the indicator disappears and users assume the page has hung. Instead of stopping it, this tool multiplies the duration by four: the motion becomes gentle but the indicator stays visible.'],
                ['q' => 'Which loader is safest on low-end phones?', 'a' => 'Ring, Spokes, Bars and Fading dots animate only transform and opacity, so they stay on the GPU. Ripple deliberately animates width and height because the expanding-circle shape cannot be built otherwise, and that forces a layout pass every frame — use it at small sizes and only once per page.'],
                ['q' => 'Can I wire the colours to my own CSS variables?', 'a' => 'Yes. The browser colour picker only speaks hex, so the output contains a literal value such as #22d3ee; after copying, swap it for var(--brand). The rgba(...) track can become color-mix(in srgb, var(--brand) 22%, transparent).'],
            ],
        ],
        'tr' => [
            'intro' => 'Bir ögeyi transform: rotate ile döndürdüğünüzde iş tarayıcının compositor katmanında yapılır ve ana iş parçacığı meşgul edilmez; aynı hareketi width veya margin değiştirerek kurarsanız tarayıcı her karede yeniden yerleşim hesaplar ve yükleyici tam da sayfa yoğunken takılır. Buradaki 12 yükleyicinin tamamı saf CSS ile yazıldı ve Dalga dışında hepsi yalnızca transform ile opacity canlandırır. Boyutu, kalınlığı, tur süresini ve iki rengi ayarlayıp hazır HTML ve CSS kodunu alırsınız.',
            'steps' => [
                'Galerideki 12 yükleyiciden birine tıklayın; seçili kart turkuaz çerçeveyle işaretlenir.',
                'Boyut, çizgi kalınlığı ve tur süresini kaydırıcılarla ayarlayın; 12 örnek de aynı anda güncellenir.',
                'Ana rengi ve ikinci rengi seçin, önizleme arka planını koyu ve açık yaparak kontrastı iki temada da görün.',
                'Gerekirse aria-label metnini değiştirin ve prefers-reduced-motion kuralını ekleyin.',
                'HTML ve CSS kodunu ayrı ayrı veya birlikte kopyalayıp doğrudan projenize yapıştırın.',
            ],
            'faq' => [
                ['q' => 'Bu yükleyiciler JavaScript veya görsel dosyası gerektirir mi?', 'a' => 'Hayır. Çıktı sade bir div ile birkaç CSS kuralı ve bir keyframes bloğundan ibarettir; SVG, GIF, ikon fontu ya da betik gerekmez. Animasyon CSS tarafında çalıştığı için sayfanın JavaScript kodu henüz yürütülmemişken bile döner, ki yükleyiciye genellikle tam o anda ihtiyaç duyarsınız.'],
                ['q' => 'prefers-reduced-motion seçeneği animasyonu neden tümüyle kapatmıyor?', 'a' => 'Çoğu kişi animation: none yazar; bu, ögeyi sıfırıncı kareye kilitler. Dalga yükleyicisinde o karede genişlik sıfırdır, Nabız yükleyicisinde ise küçültülmüş ve yarı saydamdır; yani gösterge kaybolur ve kullanıcı sayfanın donduğunu sanır. Bu araç kapatmak yerine süreyi dört katına çıkarır, hareket yumuşar ama gösterge görünür kalır.'],
                ['q' => 'Düşük donanımlı telefonlar için hangisi daha uygun?', 'a' => 'Halka, Işınlar, Çubuklar ve Solan noktalar yalnızca transform ve opacity canlandırdığı için GPU üzerinde kalır. Dalga ise büyüyen daire biçimi başka türlü kurulamadığından bilerek width ve height değerlerini canlandırır ve her karede yeniden yerleşime yol açar; onu küçük boyutta ve sayfada yalnızca bir kez kullanın.'],
                ['q' => 'Renkleri kendi CSS değişkenlerime bağlayabilir miyim?', 'a' => 'Evet. Tarayıcının renk seçicisi yalnızca hex ile çalıştığından çıktıda #22d3ee gibi sabit bir değer bulunur; kopyaladıktan sonra bunu var(--brand) ile değiştirin. rgba(...) ile yazılan iz rengi yerine de color-mix(in srgb, var(--brand) 22%, transparent) kullanabilirsiniz.'],
            ],
        ],
    ],

    'css-minifier' => [
        'fa' => [
            'intro' => 'اگر یک فشرده‌ساز فاصله‌های اطراف منها را در calc(100% - 20px) بردارد، مرورگر کل آن اعلان را دور می‌اندازد؛ همین یک مورد فرق یک ابزار واقعی با چند replace ساده‌ی regex است. این فشرده‌ساز کد را نویسه‌به‌نویسه توکن می‌کند، بنابراین رشته‌ها، محتوای url() و شرط‌های مدیا کوئری سالم می‌مانند و فقط بایت‌های بی‌اثر حذف می‌شوند. همه‌چیز داخل مرورگر شما اجرا می‌شود و فایل جایی آپلود نمی‌شود.',
            'steps' => [
                'کد CSS را در کادر ورودی بچسبانید، یا دکمه‌ی نمونه را بزنید تا یک شیت آزمایشی بارگذاری شود.',
                'چهار گزینه‌ی پایین را تنظیم کنید: کوتاه‌سازی رنگ، حذف صفرهای بی‌اثر، حذف قواعد خالی و نگه‌داشتن کامنت مجوز.',
                'خروجی همان لحظه ساخته می‌شود؛ کارت‌های بالا حجم اولیه، حجم نهایی، بایت صرفه‌جویی‌شده و درصد کاهش را نشان می‌دهند.',
                'دکمه‌ی کپی را بزنید و نتیجه را در فایل style.min.css خودتان بریزید.',
            ],
            'faq' => [
                ['q' => 'چرا 0px به 0 تبدیل نشد؟', 'a' => 'چون همیشه امن نیست. داخل calc() عدد بدون واحد نامعتبر است و مقادیر زمانی مثل 0s هم حتماً باید واحد داشته باشند. به همین دلیل فقط صفرهای بی‌اثر برداشته می‌شوند: 0.50em می‌شود .5em و 1.0 می‌شود 1، ولی 0px همان 0px می‌ماند.'],
                ['q' => 'چرا فاصله‌ی بین li و first-child حذف نشد؟', 'a' => 'چون در انتخابگر، خودِ فاصله یک ترکیب‌گر است. li :first-child یعنی فرزندِ اولِ داخلِ li، ولی li:first-child یعنی خودِ li که فرزند اول باشد. ابزار فاصله را فقط کنار ترکیب‌گرهای > و + و ~ و کنار کاما، آکولاد و پرانتز برمی‌دارد.'],
                ['q' => 'همه‌ی رنگ‌های شش‌رقمی کوتاه می‌شوند؟', 'a' => 'نه. فقط وقتی هر سه جفت رقم یکسان باشند: aabbcc# می‌شود abc# اما aabbcd# دست‌نخورده می‌ماند. رنگ هشت‌رقمی دارای آلفا هم با همین قاعده به چهار رقم می‌رسد و حروف بزرگ هگز به کوچک تبدیل می‌شود.'],
                ['q' => 'این ابزار چه کارهایی نمی‌کند؟', 'a' => 'قواعد تکراری را ادغام نمی‌کند، شورت‌هند نمی‌سازد و پیشوندهای قدیمی مرورگرها را پاک نمی‌کند؛ کارش فقط حذف بایت‌های بی‌اثر است. سقف ورودی هم ۲ میلیون کاراکتر است تا مرورگر قفل نکند.'],
            ],
        ],
        'en' => [
            'intro' => 'Delete the spaces around the minus in calc(100% - 20px) and the browser throws the whole declaration away — that single case is the difference between a real minifier and a few regex replacements. This tool tokenises the stylesheet character by character, so strings, url() bodies and media query conditions survive intact while only the dead bytes go. Everything runs in your browser; nothing is uploaded.',
            'steps' => [
                'Paste your CSS into the input box, or press Sample to load a test sheet.',
                'Set the four options: shorten colours, trim redundant zeros, drop empty rules, keep licence comments.',
                'The output appears instantly; the cards show original size, minified size, bytes saved and the percentage cut.',
                'Hit Copy and drop the result into your style.min.css.',
            ],
            'faq' => [
                ['q' => 'Why was 0px not turned into 0?', 'a' => 'Because it is not always safe. A unitless number is invalid inside calc(), and time values such as 0s must keep their unit. So only dead zeros are removed: 0.50em becomes .5em and 1.0 becomes 1, but 0px stays 0px.'],
                ['q' => 'Why is the space between li and first-child still there?', 'a' => 'In a selector the space is itself a combinator. li :first-child means the first child inside an li, while li:first-child means the li that is a first child. Space is only removed next to the > + and ~ combinators and next to commas, braces and parentheses.'],
                ['q' => 'Do all six-digit colours get shortened?', 'a' => 'No. Only when all three pairs repeat: #aabbcc becomes #abc, but #aabbcd is left alone. Eight-digit colours with alpha follow the same rule down to four digits, and uppercase hex is lowercased.'],
                ['q' => 'What does this tool not do?', 'a' => 'It does not merge duplicate rules, build shorthand properties, or strip old vendor prefixes — it only removes bytes that have no effect. Input is capped at 2 million characters so the tab never locks up.'],
            ],
        ],
        'tr' => [
            'intro' => 'calc(100% - 20px) ifadesindeki eksi isaretinin iki yanindaki bosluklari silerseniz tarayici o bildirimin tamamini atar; gercek bir kucultucu ile birkac regex degistirme arasindaki fark tam olarak budur. Bu arac stil dosyasini karakter karakter parcalara ayirir, boylece metin degerleri, url() icerigi ve medya sorgusu kosullari bozulmadan kalir, sadece etkisiz baytlar gider. Her sey tarayicinizda calisir, hicbir dosya yuklenmez.',
            'steps' => [
                'CSS kodunuzu giris kutusuna yapistirin veya Ornek dugmesine basip deneme sayfasini yukleyin.',
                'Alttaki dort secenegi ayarlayin: renk kisaltma, gereksiz sifirlari kirpma, bos kurallari atma ve lisans yorumlarini koruma.',
                'Cikti aninda olusur; ustteki kartlar orijinal boyutu, kucultulmus boyutu, kazanilan bayti ve yuzde azalmayi gosterir.',
                'Kopyala dugmesine basip sonucu style.min.css dosyaniza yapistirin.',
            ],
            'faq' => [
                ['q' => '0px neden 0 olmadi?', 'a' => 'Cunku bu her zaman guvenli degil. calc() icinde birimsiz sayi gecersizdir ve 0s gibi sure degerleri birimini korumak zorundadir. Bu yuzden yalnizca etkisiz sifirlar silinir: 0.50em degeri .5em, 1.0 degeri 1 olur ama 0px oldugu gibi kalir.'],
                ['q' => 'li ile first-child arasindaki bosluk neden duruyor?', 'a' => 'Secicide bosluk basli basina bir birlestiricidir. li :first-child bir li icindeki ilk cocugu, li:first-child ise ilk cocuk olan li ogesini anlatir. Bosluk sadece > + ve ~ birlestiricilerinin, virgulun, suslu parantezin ve normal parantezin yanindan kaldirilir.'],
                ['q' => 'Alti haneli her renk kisalir mi?', 'a' => 'Hayir. Yalnizca uc cift de kendi icinde ayni oldugunda: #aabbcc rengi #abc olur ama #aabbcd aynen kalir. Alfa iceren sekiz haneli renkler ayni kurala gore dort haneye iner ve buyuk harfli hex kucuk harfe cevrilir.'],
                ['q' => 'Bu arac neleri yapmaz?', 'a' => 'Ayni kurallari birlestirmez, kisa yazim ozellikleri uretmez ve eski uretici on eklerini temizlemez; sadece hicbir etkisi olmayan baytlari kaldirir. Sekmenin kilitlenmemesi icin girdi siniri 2 milyon karakterdir.'],
            ],
        ],
    ],

    'css-triangle' => [
        'fa' => [
            'intro' => 'مثلث CSS در واقع شکل نیست؛ یکی از چهار حاشیهٔ یک عنصر صفر در صفر است. وقتی width و height صفر باشند، چهار حاشیه در یک نقطه به هم می‌رسند و مرورگر هرکدام را به صورت یک گوهٔ مثلثی رسم می‌کند؛ سه‌تا را transparent کنید، یکی باقی می‌ماند. به همین دلیل برای مثلث رو به بالا با عرض ۸۰ پیکسل باید حاشیهٔ چپ و راست را ۴۰ پیکسل بدهید، نه ۸۰.',
            'steps' => [
                'جهت را از شبکهٔ سه در سه انتخاب کنید؛ چهار جهت اصلی و چهار مثلث گوشه‌ای در دسترس است.',
                'عرض و ارتفاع را با اسلایدر یا کادر عددی تنظیم کنید، یا قفل تناسب را بزنید تا مثلث متساوی‌الاضلاع (و در حالت گوشه‌ای، ۴۵ درجه) شود.',
                'رنگ را از انتخابگر بردارید یا کد هگز را مستقیم تایپ کنید و در صورت نیاز نام کلاس خروجی را عوض کنید.',
                'اگر مثلث باید در صفحهٔ راست‌به‌چپ قرینه شود، خصوصیت‌های منطقی را روشن کنید.',
                'کد روش border یا نسخهٔ clip-path کنارش را کپی کنید.',
            ],
            'faq' => [
                ['q' => 'چرا مثلث CSS من نمایش داده نمی‌شود؟', 'a' => 'تقریباً همیشه به این دلیل که عنصر هنوز width و height دارد. اندازهٔ مثلث فقط از ضخامت حاشیه‌ها می‌آید؛ اگر عنصر ابعاد واقعی داشته باشد، به جای مثلث یک کادر با گوشه‌های اریب می‌بینید. ضمناً حاشیه‌های استفاده‌نشده باید transparent باشند، نه حذف‌شده.'],
                ['q' => 'می‌شود به مثلث border گرادیان یا کادر داد؟', 'a' => 'نه. این مثلث با رنگ حاشیه رنگ می‌گیرد، پس فقط یک رنگ ثابت می‌پذیرد و border-radius هم رویش اثری ندارد. برای گرادیان یا تصویر از خروجی clip-path همین ابزار استفاده کنید؛ برای مثلث خط‌دار باید یک مثلث کمی بزرگ‌تر را پشت مثلث کوچک‌تر بگذارید.'],
                ['q' => 'مثلث در صفحهٔ فارسی برعکس می‌شود؟', 'a' => 'با خروجی پیش‌فرض نه، چون border-left و border-right فیزیکی‌اند و هرگز جابه‌جا نمی‌شوند. اگر می‌خواهید فلش با جهت متن بچرخد، گزینهٔ خصوصیت‌های منطقی را روشن کنید تا border-inline-start و border-inline-end تولید شود.'],
                ['q' => 'محدودیت این ابزار چیست؟', 'a' => 'فقط مثلث‌های تک‌رنگ تا ۳۰۰ پیکسل و در همان هشت جهت ثابت تولید می‌کند. زاویهٔ دلخواه، نوک گِرد و مثلث چرخیده خارج از دامنهٔ کار آن است؛ برای این‌ها باید سراغ SVG یا transform بروید.'],
            ],
        ],
        'en' => [
            'intro' => 'A CSS triangle is not a shape at all — it is one border of a zero-sized box. Set width and height to 0 and the four borders collapse to a single point, so the browser paints each one as a wedge; make three transparent and a single triangle is left. That is why an 80px-wide upward triangle needs left and right borders of 40px each, not 80px.',
            'steps' => [
                'Pick a direction in the 3×3 grid — four straight arrows plus four corner triangles.',
                'Set width and height with the sliders or number boxes, or tick lock proportion for an equilateral triangle (45 degrees for the corner shapes).',
                'Choose a fill color with the picker or type a hex code, and rename the output selector if you want.',
                'Turn on logical properties if the arrow must mirror on RTL pages.',
                'Copy the border CSS, or the clip-path version next to it.',
            ],
            'faq' => [
                ['q' => 'Why is my CSS triangle not showing up?', 'a' => "Almost always because the element still has a width and a height. The triangle's size comes only from the border widths, so with real dimensions you get a box with bevelled corners instead. Also make sure the unused borders are transparent rather than removed."],
                ['q' => 'Can I put a gradient or an outline on a CSS border triangle?', 'a' => 'No. The triangle is painted by a border color, so it accepts exactly one flat color and border-radius has no effect on it. Use the clip-path output for gradients and images; for an outlined triangle, stack a slightly larger triangle behind a smaller one.'],
                ['q' => 'Does the triangle flip on RTL pages?', 'a' => 'Not with the default output — border-left and border-right are physical and never swap. Enable logical properties and the tool emits border-inline-start and border-inline-end, which mirror with the text direction.'],
                ['q' => 'What are the limits of this generator?', 'a' => 'It produces flat single-color triangles up to 300px in eight fixed directions. Arbitrary angles, rounded tips and rotated triangles are out of scope — use SVG or a CSS transform for those.'],
            ],
        ],
        'tr' => [
            'intro' => 'CSS üçgeni aslında bir şekil değil, sıfır boyutlu bir kutunun tek bir kenarlığıdır. width ve height sıfır olunca dört kenarlık tek bir noktada buluşur ve tarayıcı her birini bir kama olarak boyar; üçünü transparent yaparsanız geriye tek üçgen kalır. Bu yüzden 80 piksel genişliğinde yukarı bakan bir üçgende sol ve sağ kenarlıklar 80 değil, 40 piksel olmalıdır.',
            'steps' => [
                '3×3 ızgaradan bir yön seçin: dört düz ok ve dört köşe üçgeni.',
                'Genişlik ve yüksekliği kaydırıcı veya sayı kutusuyla ayarlayın ya da eşkenar üçgen (köşelerde 45 derece) için oran kilidini işaretleyin.',
                'Rengi seçiciden alın veya hex kodunu yazın, isterseniz çıktıdaki seçici adını değiştirin.',
                'Ok RTL sayfalarda aynalanmalıysa mantıksal özellikleri açın.',
                'border kodunu veya yanındaki clip-path sürümünü kopyalayın.',
            ],
            'faq' => [
                ['q' => 'CSS üçgenim neden görünmüyor?', 'a' => 'Neredeyse her zaman öğenin hâlâ bir genişliği ve yüksekliği olduğu için. Üçgenin boyutu yalnızca kenarlık kalınlıklarından gelir; gerçek boyutlar verirseniz üçgen yerine köşeleri pahlanmış bir kutu elde edersiniz. Ayrıca kullanılmayan kenarlıklar kaldırılmış değil, transparent olmalıdır.'],
                ['q' => 'CSS üçgenine degrade veya çerçeve eklenebilir mi?', 'a' => 'Hayır. Üçgen bir kenarlık rengiyle boyanır, bu yüzden tek bir düz renk kabul eder ve border-radius üzerinde etkisizdir. Degrade veya görsel için clip-path çıktısını kullanın; çerçeveli üçgen içinse biraz daha büyük bir üçgenin önüne küçüğünü yerleştirin.'],
                ['q' => 'Üçgen RTL sayfalarda ters döner mi?', 'a' => 'Varsayılan çıktıda dönmez, çünkü border-left ve border-right fizikseldir ve asla yer değiştirmez. Mantıksal özellikleri açarsanız araç border-inline-start ve border-inline-end üretir; bunlar metin yönüyle birlikte aynalanır.'],
                ['q' => 'Bu aracın sınırları neler?', 'a' => 'Sekiz sabit yönde, 300 piksele kadar düz tek renkli üçgenler üretir. Serbest açılar, yuvarlatılmış uçlar ve döndürülmüş üçgenler kapsam dışıdır; bunlar için SVG veya CSS transform gerekir.'],
            ],
        ],
    ],

    'cubic-bezier' => [
        'fa' => [
            'intro' => 'مقدار ease در CSS همان cubic-bezier(0.25, 0.1, 0.25, 1) است و برخلاف تصور رایج، در نیمهٔ زمان انیمیشن حدود ۸۰ درصد مسیر را طی کرده است. دو سر منحنی روی نقاط (0,0) و (1,1) قفل‌اند و فقط دو نقطهٔ کنترل در اختیار شماست. مقدار x باید بین ۰ و ۱ بماند وگرنه مرورگر کل قاعده را نامعتبر می‌شمارد و کنار می‌گذارد، اما y می‌تواند از این بازه بیرون بزند و حرکت را پرشی کند.',
            'steps' => [
                'نقطه‌های کنترل فیروزه‌ای و بنفش را روی شبکه بکشید، یا با Tab روی یکی بایستید و با کلیدهای جهت‌دار جابه‌جایش کنید.',
                'یا از فهرست کشویی یک پیش‌تنظیم آماده انتخاب کنید: linear، ease، easeOutBack، material-standard و موارد دیگر.',
                'برای بارگذاری منحنی موجود، مقدار cubic-bezier() را در کادر ورودی بچسبانید و دکمهٔ اعمال را بزنید؛ دکمهٔ معکوس، منحنی را وارونه می‌کند.',
                'سه نوار پیش‌نمایش یعنی جابه‌جایی، نوار پیشرفت و محو شدن را ببینید و لغزندهٔ مدت را روی زمان واقعی انیمیشن خود تنظیم کنید.',
                'با لغزندهٔ نمونه‌بردار مقدار f(x) را در هر نقطه بخوانید، سپس مقدار cubic-bezier() یا قطعهٔ کامل CSS را کپی کنید.',
            ],
            'faq' => [
                ['q' => 'چرا x فقط می‌تواند بین ۰ و ۱ باشد ولی y آزاد است؟', 'a' => 'چون x همان زمان است. اگر x از این بازه بیرون بزند، منحنی روی خودش تا می‌خورد و یک لحظه از زمان به دو مقدار پیشرفت نگاشت می‌شود؛ به همین دلیل استاندارد x1 و x2 را به بازهٔ ۰ تا ۱ محدود کرده و مرورگرها کل اعلان را نامعتبر می‌شمارند. y چنین محدودیتی ندارد و مقادیر بزرگ‌تر از ۱ یا کوچک‌تر از ۰ باعث پرش از مقصد یا عقب‌نشینی اولیه می‌شوند. این ابزار هم x را به همین دلیل محدود می‌کند و ورودی خارج از بازه را رد می‌کند.'],
                ['q' => 'آیا کلیدواژه‌هایی مثل ease-in-out رفتار ویژه‌ای دارند؟', 'a' => 'خیر، این کلیدواژه‌ها صرفاً نام مستعار هستند. در استاندارد، ease-in-out دقیقاً برابر cubic-bezier(0.42, 0, 0.58, 1) تعریف شده است، همان‌طور که ease برابر (0.25, 0.1, 0.25, 1)، ease-in برابر (0.42, 0, 1, 1)، ease-out برابر (0, 0, 0.58, 1) و linear برابر (0, 0, 1, 1) است. هیچ منطق پنهانی پشت آن‌ها نیست.'],
                ['q' => 'می‌توانم با این ابزار حرکت فنری یا جهشی بسازم؟', 'a' => 'نه، و این محدودیت خودِ cubic-bezier است نه این ابزار. یک منحنی بزیه درجه سه هر مقدار x را فقط یک بار قطع می‌کند و حداکثر یک بار می‌تواند از مقصد رد شود. جهش یا فنر واقعی به نوسان‌های پیاپی نیاز دارد که فقط با تابع linear() و ده‌ها نقطه، یا با keyframes و جاوااسکریپت شدنی است. بیشترین کاری که اینجا می‌شود کرد یک پرش واحد مثل easeOutBack است که در حدود x=0.573 به اوج y=1.0978 می‌رسد.'],
                ['q' => 'حلقهٔ توخالی بنفش در پیش‌نمایش چه فرقی با نقطهٔ پر دارد؟', 'a' => 'نقطهٔ پر را کد خود ابزار با حل معادلهٔ منحنی در هر فریم رسم می‌کند، ولی حلقهٔ توخالی را موتور CSS مرورگر و مستقیماً از روی مقدار تولیدشده حرکت می‌دهد. اگر نمونه‌برداری ما درست باشد این دو باید روی هم بیفتند، پس این حلقه در عمل یک آزمون صحت زنده است. اختلاف کوچک فقط وقتی دیده می‌شود که ترنزیشن یک فریم دیرتر شروع شود یا مرورگر تب پس‌زمینه را کند کند.'],
            ],
        ],
        'en' => [
            'intro' => 'CSS ease is not a gentle curve — it is cubic-bezier(0.25, 0.1, 0.25, 1), and at the halfway point in time it has already covered 80.2% of the distance. The two endpoints are locked at (0,0) and (1,1), so only the two control points are yours to move. x must stay within 0–1 or the browser throws the whole declaration away as invalid, while y is free to leave that range and produce overshoot.',
            'steps' => [
                'Drag the cyan and violet control points on the grid, or focus one with Tab and nudge it with the arrow keys.',
                'Or pick a named preset from the dropdown — linear, ease, easeOutBack, material-standard and more.',
                'Paste an existing cubic-bezier() into the import box and press Apply to load it; Reverse mirrors the curve into its opposite.',
                'Watch the movement, progress bar and fade lanes, and set the duration slider to the length your real animation uses.',
                'Drag the sampler slider to read f(x) at any progress point, then copy the cubic-bezier() value or the full CSS snippet.',
            ],
            'faq' => [
                ['q' => 'Why can x only be between 0 and 1 when y is unrestricted?', 'a' => 'Because x is time. If x left that range the curve could fold back on itself and map a single instant to two progress values, so the spec restricts x1 and x2 to 0–1 and browsers treat anything else as an invalid declaration and drop it. y has no such limit: values above 1 overshoot the target and values below 0 pull back before starting. This tool clamps x for the same reason and rejects out-of-range x on import.'],
                ['q' => 'Do keywords like ease-in-out behave differently from a raw cubic-bezier?', 'a' => 'No, they are pure shorthand. The spec defines ease-in-out as exactly cubic-bezier(0.42, 0, 0.58, 1), just as ease is (0.25, 0.1, 0.25, 1), ease-in is (0.42, 0, 1, 1), ease-out is (0, 0, 0.58, 1) and linear is (0, 0, 1, 1). There is no hidden behaviour behind the names.'],
                ['q' => 'Can I build a bounce or spring with this?', 'a' => 'No, and that is a limitation of cubic-bezier itself rather than this tool. A cubic bezier crosses each x exactly once and can overshoot at most once. A real bounce or spring needs repeated oscillations, which requires the linear() function with many stops, keyframes, or JavaScript. The most you can do here is a single overshoot like easeOutBack, which peaks at y=1.0978 around x=0.573.'],
                ['q' => 'What is the hollow violet ring in the preview for?', 'a' => 'The filled dot is positioned by our own code, solving the curve on every frame. The hollow ring is moved by the browser CSS engine directly from the generated value. If our sampling is correct the two overlap, so the ring is a live correctness check on the maths. A small gap appears only when the transition restarts a frame late or the browser throttles a background tab.'],
            ],
        ],
        'tr' => [
            'intro' => 'CSS ease değeri aslında cubic-bezier(0.25, 0.1, 0.25, 1) demektir ve sürenin tam yarısında mesafenin yüzde 80 kadarını çoktan almış olur. Eğrinin iki ucu (0,0) ve (1,1) noktalarına sabitlenmiştir, yalnızca iki kontrol noktası sizin elinizdedir. x değeri 0 ile 1 arasında kalmak zorundadır, yoksa tarayıcı kuralın tamamını geçersiz sayıp atar; y ise bu aralığın dışına çıkıp taşma etkisi üretebilir.',
            'steps' => [
                'Izgara üzerindeki turkuaz ve mor kontrol noktalarını sürükleyin ya da Tab ile birini seçip yön tuşlarıyla kaydırın.',
                'Veya açılır listeden hazır bir eğri seçin: linear, ease, easeOutBack, material-standard ve diğerleri.',
                'Mevcut bir eğriyi yüklemek için cubic-bezier() değerini içe aktarma kutusuna yapıştırıp Uygula düğmesine basın; Ters çevir düğmesi eğrinin tersini üretir.',
                'Hareket, ilerleme çubuğu ve solma şeritlerini izleyin ve süre kaydırıcısını gerçek animasyonunuzun süresine ayarlayın.',
                'Örnekleyici kaydırıcısıyla herhangi bir noktadaki f(x) değerini okuyun, ardından cubic-bezier() değerini veya tam CSS parçasını kopyalayın.',
            ],
            'faq' => [
                ['q' => 'y serbestken x neden yalnızca 0 ile 1 arasında olabiliyor?', 'a' => 'Çünkü x zamandır. x bu aralığın dışına çıksaydı eğri kendi üzerine katlanır ve tek bir an iki farklı ilerleme değerine karşılık gelirdi. Bu yüzden standart x1 ve x2 değerlerini 0–1 aralığıyla sınırlar ve tarayıcılar bunun dışındaki bildirimi geçersiz sayıp tamamen atar. y için böyle bir sınır yoktur: 1 üzerindeki değerler hedefi aşar, 0 altındaki değerler ise başlamadan önce geri çeker. Bu araç da aynı nedenle x değerini sınırlar ve aralık dışı girdiyi reddeder.'],
                ['q' => 'ease-in-out gibi anahtar kelimeler farklı mı davranır?', 'a' => 'Hayır, bunlar tamamen kısayoldur. Standart ease-in-out değerini tam olarak cubic-bezier(0.42, 0, 0.58, 1) diye tanımlar; aynı şekilde ease (0.25, 0.1, 0.25, 1), ease-in (0.42, 0, 1, 1), ease-out (0, 0, 0.58, 1) ve linear (0, 0, 1, 1) demektir. İsimlerin arkasında gizli bir davranış yoktur.'],
                ['q' => 'Bununla zıplama veya yay efekti yapabilir miyim?', 'a' => 'Hayır ve bu, aracın değil cubic-bezier fonksiyonunun sınırıdır. Kübik bir bezier her x değerini tam olarak bir kez keser ve en fazla bir kez taşabilir. Gerçek bir zıplama veya yay art arda salınım gerektirir; bunun için çok sayıda duraklı linear() fonksiyonu, keyframes veya JavaScript gerekir. Burada yapılabilecek en fazla şey easeOutBack gibi tek bir taşmadır, ki bu eğri x=0.573 dolayında y=1.0978 tepe değerine ulaşır.'],
                ['q' => 'Önizlemedeki içi boş mor halka ne işe yarar?', 'a' => 'İçi dolu nokta, her karede eğriyi çözen kendi kodumuz tarafından konumlandırılır. İçi boş halkayı ise doğrudan üretilen değerden yola çıkarak tarayıcının CSS motoru hareket ettirir. Örneklememiz doğruysa ikisi üst üste biner, yani halka matematiğin canlı bir doğruluk testidir. Küçük bir kayma yalnızca geçiş bir kare geç başladığında veya tarayıcı arka plandaki sekmeyi yavaşlattığında görünür.'],
            ],
        ],
    ],

    'html-formatter' => [
        'fa' => [
            'intro' => 'در HTML همه‌ی فاصله‌ها بی‌اثر نیستند: داخل pre و textarea هر فاصله و هر خط تازه دقیقاً همان چیزی است که مرورگر روی صفحه می‌کشد، پس ابزاری که کورکورانه به آن‌ها تورفتگی بدهد ظاهر خروجی را عوض می‌کند. از طرف دیگر li و td و p در استاندارد HTML تگ پایانی اختیاری دارند؛ فهرستی که هیچ بستن li ندارد کاملاً معتبر است و یک تورفتگی‌دهنده‌ی ساده که فقط علامت کوچک‌تر و بزرگ‌تر را می‌شمارد آن را به تودرتویی بی‌معنی تبدیل می‌کند. این ابزار به‌جای شمردن نویسه، درخت واقعی سند را می‌سازد.',
            'steps' => [
                'کد HTML را در کادر ورودی بچسبانید؛ قالب‌بندی همان لحظه انجام می‌شود.',
                'تورفتگی را انتخاب کنید: ۲، ۳، ۴ یا ۸ فاصله، یا تب.',
                'اگر تگ‌های باز طولانی دارید گزینه‌ی شکستن تگ باز را روشن کنید تا هر ویژگی روی خط خودش بیفتد.',
                'شمارنده‌های پایین را ببینید: خط خروجی، تعداد عنصر و بیشترین عمق تودرتویی؛ هشدارهای ساختاری هم با شماره‌ی خط نمایش داده می‌شوند.',
                'خروجی را با دکمه‌ی کپی بردارید.',
            ],
            'faq' => [
                ['q' => 'چرا محتوای داخل pre من تورفته نشد؟', 'a' => 'عمدی است. فاصله‌های داخل pre و textarea معنادار هستند و مرورگر عیناً نشانشان می‌دهد، پس تورفتگی دادن به آن‌ها ظاهر صفحه را تغییر می‌داد. محتوای این دو تگ بایت‌به‌بایت کپی می‌شود. محتوای script و style فرق دارد: با روشن بودن گزینه‌اش دوباره تورفتگی می‌گیرد، مگر آنکه داخلش رشته‌ی قالبی با بک‌تیک باشد که در آن صورت دست‌نخورده می‌ماند.'],
                ['q' => 'چرا برای br یا img تگ بسته اضافه نمی‌شود؟', 'a' => 'چون این‌ها عنصر تهی هستند. area، base، br، col، embed، hr، img، input، link، meta، param، source، track و wbr اصلاً تگ پایانی ندارند و نوشتن یک بستن br نامعتبر است. ابزار هر چهارده مورد را می‌شناسد و اگر خودتان اسلش پایانی به سبک XHTML نوشته باشید، همان را نگه می‌دارد.'],
                ['q' => 'هشدار تگ بسته‌نشده حتماً یعنی کد من خراب است؟', 'a' => 'نه. عنصرهایی که تگ پایانی اختیاری دارند مثل li، td، tr، p، option و dd هرگز به‌عنوان بسته‌نشده گزارش نمی‌شوند، چون پارسر HTML خودش آن‌ها را می‌بندد. هشدار فقط برای تگ‌هایی می‌آید که واقعاً باید بسته می‌شدند، برای تگ‌های بسته‌ای که هیچ تگ بازی ندارند، و برای تگ‌هایی که به‌جای تودرتو شدن روی هم افتاده‌اند.'],
                ['q' => 'محدودیت‌های این ابزار چیست؟', 'a' => 'ورودی تا ۸۰۰ هزار نویسه و تودرتویی تا ۵۱۲ سطح پذیرفته می‌شود. ضمناً این یک قالب‌بند است نه اعتبارسنج کامل W3C: نام ویژگی‌ها یا رعایت قواعد معنایی را بررسی نمی‌کند و متن طولانی داخل یک پاراگراف را هم به چند خط نمی‌شکند.'],
            ],
        ],
        'en' => [
            'intro' => 'Whitespace is not always cosmetic in HTML: inside pre and textarea every space and newline is exactly what the browser paints, so a formatter that blindly re-indents them changes how the page looks. In the other direction, li, td and p have optional end tags in the standard, so a list written without a single closing li is perfectly valid, and a naive indenter that just counts angle brackets turns it into nonsense nesting. This tool builds a real document tree instead of counting characters.',
            'steps' => [
                'Paste your HTML into the input box; formatting runs as you type.',
                'Pick the indent: 2, 3, 4 or 8 spaces, or a tab.',
                'Turn on the long-opening-tag option to give each attribute its own line.',
                'Read the counters below for output lines, element count and maximum nesting depth, and check any structure warnings with their line numbers.',
                'Copy the result with the copy button.',
            ],
            'faq' => [
                ['q' => 'Why was the content of my pre block left un-indented?', 'a' => 'On purpose. Whitespace inside pre and textarea is significant and rendered literally, so re-indenting it would visibly change the page. Those two elements are copied through byte for byte. Script and style bodies are different: they get re-indented when that option is on, unless they contain a backtick template literal, in which case the tool leaves them alone.'],
                ['q' => 'Why does the tool never add a closing tag for br or img?', 'a' => 'They are void elements. area, base, br, col, embed, hr, img, input, link, meta, param, source, track and wbr have no end tag at all, and writing a closing br is invalid HTML. All fourteen are recognised, and if you already write the XHTML-style trailing slash it is preserved rather than stripped.'],
                ['q' => 'Does an unbalanced-tag warning always mean my HTML is broken?', 'a' => 'No. Elements with optional end tags such as li, td, tr, p, option and dd are never reported as unclosed, because the HTML parser closes them for you. Warnings are raised only for tags that genuinely needed a closing tag, for closing tags with no matching opener, and for tags that overlap instead of nesting.'],
                ['q' => 'What are the limits?', 'a' => 'Input is capped at 800,000 characters and nesting at 512 levels. It is a formatter, not a full W3C validator: it does not check attribute names or semantic rules, and it does not re-wrap a long run of text inside a paragraph.'],
            ],
        ],
        'tr' => [
            'intro' => 'HTML kodunda boşluk her zaman süs değildir: pre ve textarea içindeki her boşluk ve her satır sonu tarayıcının ekrana çizdiği şeyin ta kendisidir, bu yüzden onları körü körüne girintileyen bir araç sayfanın görünümünü bozar. Öte yandan li, td ve p standartta isteğe bağlı kapanış etiketine sahiptir; tek bir kapanış li içermeyen bir liste tamamen geçerlidir ve yalnızca açı parantezi sayan basit bir düzenleyici onu anlamsız bir iç içe yapıya çevirir. Bu araç karakter saymak yerine gerçek bir belge ağacı kurar.',
            'steps' => [
                'HTML kodunu giriş kutusuna yapıştırın; biçimlendirme siz yazarken çalışır.',
                'Girintiyi seçin: 2, 3, 4 veya 8 boşluk ya da sekme.',
                'Uzun açılış etiketleri seçeneğini açarak her özniteliği kendi satırına indirin.',
                'Alttaki sayaçlardan çıktı satırını, öğe sayısını ve en fazla iç içe derinliği görün; yapı uyarılarını satır numarasıyla okuyun.',
                'Sonucu kopyala düğmesiyle alın.',
            ],
            'faq' => [
                ['q' => 'pre bloğumun içi neden girintilenmedi?', 'a' => 'Bilerek. pre ve textarea içindeki boşluklar anlamlıdır ve tarayıcı bunları olduğu gibi gösterir; girintilemek sayfanın görünümünü değiştirirdi. Bu iki öğenin içeriği bayt bayt kopyalanır. script ve style ise seçenek açıkken yeniden girintilenir; ancak içinde ters tırnaklı şablon dizesi varsa araç onlara dokunmaz.'],
                ['q' => 'br veya img için neden kapanış etiketi eklenmiyor?', 'a' => 'Bunlar void öğelerdir. area, base, br, col, embed, hr, img, input, link, meta, param, source, track ve wbr hiçbir zaman kapanış etiketi almaz; kapanan bir br yazmak geçersiz HTML olur. Araç bu on dört öğeyi tanır ve XHTML tarzı sondaki eğik çizgiyi yazdıysanız onu silmez, korur.'],
                ['q' => 'Kapanmayan etiket uyarısı her zaman kodumun bozuk olduğu anlamına mı gelir?', 'a' => 'Hayır. li, td, tr, p, option ve dd gibi kapanışı isteğe bağlı öğeler asla kapanmamış olarak bildirilmez, çünkü HTML çözümleyicisi onları sizin yerinize kapatır. Uyarı yalnızca gerçekten kapatılması gereken etiketler, karşılığı olmayan kapanış etiketleri ve iç içe geçmek yerine çakışan etiketler için verilir.'],
                ['q' => 'Sınırlar neler?', 'a' => 'Girdi 800.000 karakter, iç içe geçme 512 seviye ile sınırlıdır. Bu bir biçimlendiricidir, tam bir W3C doğrulayıcısı değildir: öznitelik adlarını veya anlamsal kuralları denetlemez ve bir paragraf içindeki uzun metni satırlara bölmez.'],
            ],
        ],
    ],

    'image-compressor' => [
        'fa' => [
            'intro' => 'یک عکس ذخیره‌شده با کیفیت ۱۰۰ درصد تقریباً هیچ صرفه‌ای در حجم ندارد، در حالی که تفاوت دیداری‌اش با کیفیت ۸۰ برای چشم عادی محسوس نیست؛ به همین دلیل بیشتر حجم یک تصویر را می‌توان بدون افت واقعی کیفیت حذف کرد. این ابزار عکس را در همان مرورگر شما دوباره با فرمت JPEG یا WebP کدگذاری می‌کند و هیچ فایلی به سرور فرستاده نمی‌شود.',
            'steps' => [
                'تصویر را روی کادر بکشید و رها کنید، یا برای انتخاب فایل روی آن کلیک کنید.',
                'فرمت خروجی را JPEG یا WebP انتخاب و کیفیت را با لغزنده تنظیم کنید.',
                'در صورت نیاز گزینه‌ی محدودکردن بزرگ‌ترین ضلع را فعال کنید تا ابعاد هم کوچک شود.',
                'درصد کاهش حجم و مقایسه‌ی کنارِ‌هم تصویر اصلی و فشرده را ببینید.',
                'فایل فشرده‌شده را دانلود کنید.',
            ],
            'faq' => [
                ['q' => 'کیفیت مناسب برای وب چقدر است؟', 'a' => 'برای عکس‌های معمولی وب، کیفیت بین ۷۰ تا ۸۰ تعادل خوبی بین حجم و ظاهر است. بالای ۹۰ حجم به‌سرعت زیاد می‌شود بدون آنکه تفاوت دیداری قابل‌توجهی بدهد.'],
                ['q' => 'آیا فشرده‌سازی دوباره‌ی یک JPEG کیفیت را برمی‌گرداند؟', 'a' => 'خیر. JPEG فشرده‌سازی «با اتلاف» است؛ هر بار ذخیره‌ی مجدد اطلاعات بیشتری را حذف می‌کند و راه بازگشتی ندارد. همیشه از فایل اصلی و باکیفیت شروع کنید.'],
                ['q' => 'WebP بهتر است یا JPEG؟', 'a' => 'در کیفیت یکسان، WebP معمولاً حدود ۲۵ تا ۳۵ درصد کوچک‌تر از JPEG است و شفافیت را هم نگه می‌دارد؛ اما توجه کنید که هنگام تبدیل یک PNG شفاف به JPEG، پس‌زمینه سفید می‌شود.'],
                ['q' => 'چرا خروجی من گاهی بزرگ‌تر از فایل اصلی شد؟', 'a' => 'اگر تصویر از قبل فشرده و بهینه باشد، کدگذاری دوباره می‌تواند حجم را بیشتر کند. در این حالت ابزار هشدار می‌دهد؛ کیفیت را پایین بیاورید یا همان فایل اصلی را نگه دارید.'],
            ],
        ],
        'en' => [
            'intro' => "A JPEG saved at quality 100 is barely smaller than uncompressed, yet at quality 80 the difference is usually invisible to the eye — so most of an image's weight can be removed with no real loss. This tool re-encodes your picture to JPEG or WebP inside the browser; the file is never sent to a server.",
            'steps' => [
                'Drop an image onto the box, or click it to choose a file.',
                'Pick JPEG or WebP output and set the quality slider.',
                'Optionally turn on the largest-side limit to shrink the dimensions too.',
                'Read the percent saved and compare the original and compressed side by side.',
                'Download the compressed file.',
            ],
            'faq' => [
                ['q' => 'What quality should I use for the web?', 'a' => 'For typical web photos, quality 70 to 80 is a good balance of size and appearance. Above 90 the file grows quickly with little visible gain.'],
                ['q' => 'Does re-compressing a JPEG restore quality?', 'a' => 'No. JPEG is lossy: every re-save discards more detail and there is no way back. Always start from the original, high-quality file.'],
                ['q' => 'WebP or JPEG?', 'a' => 'At the same quality WebP is usually 25 to 35 percent smaller than JPEG and keeps transparency — but note that converting a transparent PNG to JPEG fills the background with white.'],
                ['q' => 'Why was my output sometimes larger than the original?', 'a' => 'If the image is already compressed and optimised, re-encoding can increase its size. The tool warns you when this happens; lower the quality or keep the original.'],
            ],
        ],
        'tr' => [
            'intro' => 'Kalite 100 ile kaydedilen bir JPEG, sıkıştırılmamış halinden neredeyse hiç küçük değildir; oysa kalite 80 seviyesinde fark çoğu zaman gözle görülmez, yani bir görselin boyutunun büyük kısmı gerçek bir kayıp olmadan atılabilir. Bu araç resmi tarayıcı içinde JPEG veya WebP olarak yeniden kodlar ve dosya hiçbir sunucuya gönderilmez.',
            'steps' => [
                'Bir görseli kutuya bırakın veya dosya seçmek için üzerine tıklayın.',
                'JPEG veya WebP çıktısını seçin ve kalite kaydırıcısını ayarlayın.',
                'Boyutları da küçültmek için en uzun kenar sınırını isteğe bağlı açın.',
                'Kazanılan yüzdeyi okuyun ve orijinal ile sıkıştırılmışı yan yana karşılaştırın.',
                'Sıkıştırılmış dosyayı indirin.',
            ],
            'faq' => [
                ['q' => 'Web için hangi kaliteyi kullanmalıyım?', 'a' => 'Tipik web fotoğrafları için 70 ila 80 kalite, boyut ile görünüm arasında iyi bir dengedir. 90 üzerinde dosya, gözle görülür bir kazanç olmadan hızla büyür.'],
                ['q' => 'Bir JPEG yeniden sıkıştırmak kaliteyi geri getirir mi?', 'a' => 'Hayır. JPEG kayıplı bir biçimdir: her yeniden kaydetme daha fazla ayrıntı atar ve geri dönüşü yoktur. Her zaman orijinal, yüksek kaliteli dosyadan başlayın.'],
                ['q' => 'WebP mi JPEG mi?', 'a' => 'Aynı kalitede WebP genellikle JPEG biçiminden yüzde 25 ila 35 daha küçüktür ve saydamlığı korur; ancak saydam bir PNG görselini JPEG olarak kaydederken arka plan beyaza döner.'],
                ['q' => 'Çıktım neden bazen orijinalden büyük oldu?', 'a' => 'Görsel zaten sıkıştırılmış ve optimize edilmişse yeniden kodlama boyutu artırabilir. Araç bu durumda sizi uyarır; kaliteyi düşürün veya orijinali saklayın.'],
            ],
        ],
    ],

    'image-filters' => [
        'fa' => [
            'intro' => 'موتور فیلتر canvas همان موتور فیلتر CSS است؛ به‌محض اینکه ctx.filter را روی grayscale(100%) بگذارید، ماتریس روشنایی 0.2126R + 0.7152G + 0.0722B اجرا می‌شود و قرمز خالص #FF0000 به rgb(54,54,54) تبدیل می‌شود، نه به خاکستری وسط. ترتیب فیلترها هم مهم است: blur(4px) saturate(200%) با saturate(200%) blur(4px) یک نتیجه نمی‌دهد. این ابزار هشت فیلتر را با ترتیب ثابت و مشخص اعمال می‌کند و دقیقاً همان رشته‌ای را که به کار برده نشان می‌دهد.',
            'steps' => [
                'تصویر را روی کادر بالا بکشید یا کلیک کنید تا انتخاب شود؛ اگر عجله دارید همان چارت رنگ ۶۰۰×۴۰۰ که پیش‌فرض بارگذاری شده کافی است.',
                'یکی از پیش‌تنظیم‌ها — نوآر، قدیمی، رنگ زنده، محو، رؤیایی، نگاتیو یا نئون — را بزنید تا نقطه‌ی شروع بگیرید.',
                'هشت اسلایدر روشنایی، کنتراست، اشباع رنگ، سیاه‌وسفید، سپیا، چرخش رنگ، معکوس و تاری را تا رسیدن به نتیجه‌ی دلخواه تنظیم کنید.',
                'دکمه‌ی «مقایسه با اصل» را بزنید تا بوم بین تصویر فیلترشده و تصویر دست‌نخورده جابه‌جا شود.',
                'رشته‌ی filter را برای استفاده در استایل‌شیت کپی کنید یا خروجی را در ابعاد واقعی تصویر به‌صورت PNG دانلود کنید.',
            ],
            'faq' => [
                ['q' => 'سیاه‌وسفید کردن یعنی میانگین گرفتن از سه کانال رنگ؟', 'a' => 'نه، و همین اشتباه رایج است. grayscale از ماتریس روشنایی استفاده می‌کند: 0.2126 قرمز + 0.7152 سبز + 0.0722 آبی. به همین دلیل قرمز خالص به rgb(54,54,54) و سبز خالص به rgb(182,182,182) تبدیل می‌شود، در حالی که میانگین ساده هر دو را ۸۵ می‌کرد. برای دیدنش کافی است چارت رنگ نمونه را بارگذاری کنید و اسلایدر سیاه‌وسفید را روی ۱۰۰ بگذارید.'],
                ['q' => 'رشته‌ی CSS دقیقاً همان چیزی را می‌سازد که در پیش‌نمایش می‌بینم؟', 'a' => 'برای فیلترهای رنگی بله؛ brightness، contrast، saturate، grayscale، sepia، hue-rotate و invert مستقل از اندازه‌اند و همان مقدار پیکسل را می‌دهند. اما blur برحسب پیکسل است و به اندازه‌ی رندرشده‌ی عنصر بستگی دارد: blur(5px) روی تصویری که در ابعاد واقعی‌اش نمایش داده شود با همان تصویر که در نصف اندازه رندر شده یکسان نیست.'],
                ['q' => 'تصویر من جایی آپلود می‌شود؟ محدودیت اندازه چقدر است؟', 'a' => 'هیچ فایلی ارسال نمی‌شود؛ خواندن فایل، رسم روی canvas و ساخت PNG همه داخل مرورگر شما انجام می‌شود و این صفحه اصلاً درخواست شبکه‌ای نمی‌فرستد. تنها محدودیت واقعی این است که اگر ضلع بلند تصویر بیشتر از ۲۴۰۰ پیکسل باشد، برای اینکه پیش‌نمایش زنده کند نشود کوچک می‌شود؛ بنابراین خروجی PNG هم حداکثر ۲۴۰۰ پیکسل خواهد بود.'],
                ['q' => 'چرا بعد از اعمال تاری، لبه‌های تصویر کم‌رنگ و شفاف می‌شوند؟', 'a' => 'چون blur برای هر پیکسل از پیکسل‌های اطرافش میانگین می‌گیرد و بیرون از مرز بوم چیزی جز شفافیت وجود ندارد. این رفتار استاندارد فیلتر است، نه ایراد ابزار. اگر لبه‌ی تمیز می‌خواهید، بعد از دانلود چند پیکسل از هر طرف را برش بزنید یا مقدار تاری کمتری بگذارید.'],
            ],
        ],
        'en' => [
            'intro' => "Canvas 2D and CSS share one filter engine: set ctx.filter = 'grayscale(100%)' and you get the luminance matrix 0.2126R + 0.7152G + 0.0722B, which turns pure red #FF0000 into rgb(54,54,54) rather than mid grey. Order matters as well — blur(4px) saturate(200%) and saturate(200%) blur(4px) are two different images. This studio applies the eight filters in one fixed, documented order and prints the exact string it used.",
            'steps' => [
                'Drop an image on the box or click to pick one — or just start on the 600×400 colour chart that loads by default.',
                'Hit a preset — Noir, Vintage, Vivid, Faded, Dreamy, Negative or Neon — to get a starting point.',
                'Tune the eight sliders: brightness, contrast, saturation, grayscale, sepia, hue-rotate, invert and blur.',
                'Press Compare to flip the canvas between the filtered result and the untouched original.',
                "Copy the filter string into your stylesheet, or download the result as a PNG at the image's own pixel size.",
            ],
            'faq' => [
                ['q' => 'Is grayscale just the average of the three channels?', 'a' => 'No, and that is the usual misconception. grayscale uses the luminance matrix 0.2126 red + 0.7152 green + 0.0722 blue, so pure red becomes rgb(54,54,54) and pure green becomes rgb(182,182,182), where a plain average would make both 85. Load the sample colour chart and push the grayscale slider to 100 to see it for yourself.'],
                ['q' => 'Does the CSS string reproduce exactly what the preview shows?', 'a' => 'For the colour functions, yes: brightness, contrast, saturate, grayscale, sepia, hue-rotate and invert are resolution independent and give identical pixel values. blur is not — it is measured in pixels and depends on how large the element is actually rendered, so blur(5px) on an image shown at native size differs from the same image rendered at half size.'],
                ['q' => 'Is my image uploaded anywhere, and is there a size limit?', 'a' => 'Nothing is sent: reading the file, drawing to the canvas and encoding the PNG all happen in your browser, and the page makes no network request at all. The one real limit is that images larger than 2400px on the long side are scaled down so the live preview stays responsive, which means the exported PNG is also capped at 2400px.'],
                ['q' => 'Why do the edges go transparent when I add blur?', 'a' => 'Because blur averages each pixel with its neighbours, and beyond the edge of the bitmap there is nothing but transparency. That is standard filter behaviour, not a bug in the tool. If you need clean edges, crop a few pixels off each side after downloading, or use a smaller blur radius.'],
            ],
        ],
        'tr' => [
            'intro' => 'Canvas 2D ile CSS aynı filtre motorunu kullanır: ctx.filter değerini grayscale(100%) yaptığınızda 0.2126R + 0.7152G + 0.0722B parlaklık matrisi çalışır ve saf kırmızı #FF0000 orta gri yerine rgb(54,54,54) olur. Sıra da önemlidir: blur(4px) saturate(200%) ile saturate(200%) blur(4px) aynı görüntüyü vermez. Bu araç sekiz filtreyi sabit ve belgelenmiş bir sırayla uygular ve kullandığı dizeyi aynen yazar.',
            'steps' => [
                'Görseli kutuya sürükleyin veya tıklayıp seçin; acele ediyorsanız varsayılan olarak yüklenen 600×400 renk kartı da yeterlidir.',
                'Noir, Eski, Canlı, Soluk, Rüya, Negatif veya Neon hazır ayarlarından birine basarak bir başlangıç noktası alın.',
                'Sekiz kaydırıcıyı ayarlayın: parlaklık, kontrast, doygunluk, gri ton, sepya, renk döndürme, ters çevirme ve bulanıklık.',
                'Karşılaştır düğmesiyle tuvali filtreli sonuç ile dokunulmamış orijinal arasında değiştirin.',
                'Filtre dizesini stil dosyanıza kopyalayın veya sonucu görselin kendi piksel boyutunda PNG olarak indirin.',
            ],
            'faq' => [
                ['q' => 'Gri tonlama üç kanalın ortalaması mıdır?', 'a' => 'Hayır, ve en sık yapılan yanlış budur. grayscale parlaklık matrisini kullanır: 0.2126 kırmızı + 0.7152 yeşil + 0.0722 mavi. Bu yüzden saf kırmızı rgb(54,54,54), saf yeşil ise rgb(182,182,182) olur; basit ortalama ikisini de 85 yapardı. Örnek renk kartını yükleyip gri ton kaydırıcısını 100 yaparak kendiniz görebilirsiniz.'],
                ['q' => 'CSS dizesi önizlemede gördüğüm sonucun aynısını verir mi?', 'a' => 'Renk fonksiyonları için evet: brightness, contrast, saturate, grayscale, sepia, hue-rotate ve invert çözünürlükten bağımsızdır ve aynı piksel değerlerini üretir. Ancak blur piksel cinsindendir ve öğenin ekranda kaç piksel genişlikte çizildiğine bağlıdır; yarı boyutta gösterilen bir görselde blur(5px) daha güçlü görünür.'],
                ['q' => 'Görselim bir yere yükleniyor mu, boyut sınırı var mı?', 'a' => 'Hiçbir dosya gönderilmez; dosyayı okuma, tuvale çizme ve PNG üretme işlemlerinin tamamı tarayıcınızda olur, sayfa tek bir ağ isteği bile yapmaz. Tek gerçek sınır şudur: uzun kenarı 2400 pikselden büyük görseller canlı önizleme akıcı kalsın diye küçültülür, dolayısıyla indirilen PNG de en fazla 2400 piksel olur.'],
                ['q' => 'Bulanıklık verince kenarlar neden saydamlaşıyor?', 'a' => 'Çünkü blur her pikseli komşularıyla ortalar ve tuvalin kenarının ötesinde saydamlıktan başka bir şey yoktur. Bu, filtrenin standart davranışıdır, aracın hatası değildir. Temiz kenar isterseniz indirdikten sonra her kenardan birkaç piksel kırpın veya daha düşük bir bulanıklık yarıçapı kullanın.'],
            ],
        ],
    ],

    'image-palette' => [
        'fa' => [
            'intro' => 'میانگین سادهٔ کانال‌های RGB یک تصویر تقریباً همیشه به یک خاکستریِ گل‌آلود می‌رسد که هیچ ربطی به رنگی که واقعاً «غالب» می‌بینید ندارد. این ابزار به‌جای میانگین‌گیری، پیکسل‌های تصویر را داخل مرورگر نمونه‌برداری و با الگوریتم برش میانه (median-cut) به ۶ تا ۱۰ خوشهٔ رنگی تقسیم می‌کند و سهم درصدی هر رنگ را کنار کد HEX نشان می‌دهد. تمام پردازش روی دستگاه خودتان انجام می‌شود و هیچ تصویری آپلود نمی‌شود.',
            'steps' => [
                'تصویر را داخل کادر رها کنید یا برای انتخاب فایل روی آن کلیک کنید.',
                'با لغزنده، تعداد رنگ‌های خروجی را بین ۶ تا ۱۰ تنظیم کنید.',
                'روی هر نمونه بزنید تا کد HEX آن کپی شود.',
                'بلوک متغیرهای CSS یا میانگین رنگ را برای استفاده در طرح خود کپی کنید.',
            ],
            'faq' => [
                ['q' => 'آیا تصویر من روی سرور آپلود می‌شود؟', 'a' => 'خیر. تصویر با FileReader داخل مرورگر خوانده و روی canvas پردازش می‌شود و هیچ داده‌ای به سرور فرستاده نمی‌شود؛ به همین دلیل ابزار حتی بدون اتصال به اینترنت هم کار می‌کند.'],
                ['q' => 'تفاوت «رنگ غالب» با «میانگین رنگ» چیست؟', 'a' => 'رنگ غالب مرکز بزرگ‌ترین خوشهٔ پیکسل‌هاست و برای ساخت پالت مناسب است، اما میانگین رنگ برایند ریاضی همهٔ پیکسل‌هاست و معمولاً کدر و بی‌روح درمی‌آید؛ برای طراحی از رنگ‌های غالب استفاده کنید نه از میانگین.'],
                ['q' => 'چرا گاهی به‌جای ۱۰ رنگ، تعداد کمتری نمایش داده می‌شود؟', 'a' => 'اگر تصویر تنوع رنگی کافی نداشته باشد — مثلاً یک لوگوی دو رنگ — الگوریتم خوشهٔ معناداری برای تفکیک بیشتر پیدا نمی‌کند و فقط رنگ‌هایی را که واقعاً وجود دارند نشان می‌دهد.'],
                ['q' => 'درصدها دقیقاً چه چیزی را نشان می‌دهند؟', 'a' => 'سهم پیکسل‌های هر خوشه از کل پیکسل‌های مات، پس از کوچک‌سازی تصویر تا حداکثر ۱۶۰ پیکسل در بزرگ‌ترین بعد؛ پیکسل‌های شفاف و نیمه‌شفاف در محاسبه کنار گذاشته می‌شوند.'],
            ],
        ],
        'en' => [
            'intro' => "A plain RGB channel average of an image almost always collapses into a muddy grey that has nothing to do with the color you actually perceive as dominant. Instead of averaging, this tool samples the pixels in your browser and splits them into 6 to 10 color clusters with a median-cut algorithm, showing each color's share percentage next to its HEX code. Everything runs on your own device and no image is uploaded.",
            'steps' => [
                'Drop an image into the box, or click it to choose a file.',
                'Use the slider to set how many colors to extract, from 6 to 10.',
                'Click any swatch to copy its HEX code.',
                'Copy the CSS variables block or the average color for your design.',
            ],
            'faq' => [
                ['q' => 'Is my image uploaded to a server?', 'a' => 'No. The image is read with FileReader and processed on a canvas entirely inside your browser, and nothing is sent to any server — which is why the tool keeps working even with no internet connection.'],
                ['q' => 'What is the difference between the dominant color and the average color?', 'a' => 'A dominant color is the center of a large pixel cluster and is what you want for a palette, while the average color is the mathematical mean of every pixel and usually comes out dull and washed out; use the dominant colors for design work, not the average.'],
                ['q' => 'Why do I sometimes get fewer than 10 colors?', 'a' => 'If the image lacks enough color variety — a two-color logo, for example — the algorithm cannot find more meaningful clusters to split, so it shows only the colors that are actually present.'],
                ['q' => 'What exactly do the percentages mean?', 'a' => "Each is the share of that cluster's pixels out of all opaque pixels, measured after the image is downsampled to at most 160 pixels on its longest side; transparent and semi-transparent pixels are excluded."],
            ],
        ],
        'tr' => [
            'intro' => 'Bir görselin düz RGB kanal ortalaması neredeyse her zaman çamurlu bir griye dönüşür ve baskın olarak algıladığınız renkle hiçbir ilgisi yoktur. Bu araç ortalama almak yerine pikselleri tarayıcınızda örnekler ve median-cut algoritmasıyla 6 ila 10 renk kümesine ayırır, her rengin pay yüzdesini HEX kodunun yanında gösterir. Tüm işlem kendi cihazınızda çalışır ve hiçbir görsel yüklenmez.',
            'steps' => [
                'Bir görseli kutuya bırakın veya dosya seçmek için üzerine tıklayın.',
                'Kaydırıcıyla çıkarılacak renk sayısını 6 ile 10 arasında ayarlayın.',
                'HEX kodunu kopyalamak için herhangi bir örneğe tıklayın.',
                'CSS değişkenleri bloğunu veya ortalama rengi tasarımınız için kopyalayın.',
            ],
            'faq' => [
                ['q' => 'Görselim bir sunucuya yüklenir mi?', 'a' => 'Hayır. Görsel FileReader ile okunur ve tamamen tarayıcınızda bir canvas üzerinde işlenir, hiçbir veri sunucuya gönderilmez; bu yüzden araç internet bağlantısı olmadan da çalışır.'],
                ['q' => 'Baskın renk ile ortalama renk arasındaki fark nedir?', 'a' => 'Baskın renk büyük bir piksel kümesinin merkezidir ve palet için uygundur, ortalama renk ise tüm piksellerin matematiksel ortalamasıdır ve genellikle donuk ve soluk çıkar; tasarım için ortalamayı değil baskın renkleri kullanın.'],
                ['q' => 'Neden bazen 10 yerine daha az renk görüyorum?', 'a' => 'Görselde yeterli renk çeşitliliği yoksa — örneğin iki renkli bir logo — algoritma bölünecek daha anlamlı küme bulamaz ve yalnızca gerçekten var olan renkleri gösterir.'],
                ['q' => 'Yüzdeler tam olarak neyi ifade eder?', 'a' => 'Her biri, görsel en uzun kenarında en fazla 160 piksele küçültüldükten sonra o kümenin piksellerinin tüm opak pikseller içindeki payıdır; saydam ve yarı saydam pikseller hesaba katılmaz.'],
            ],
        ],
    ],

    'image-to-base64' => [
        'fa' => [
            'intro' => 'کدگذاری Base64 حجم هر فایل را حدود ۳۳٪ بزرگ‌تر می‌کند و رشته‌ی حاصل در HTML یا CSS به‌صورت جداگانه کش نمی‌شود؛ به همین دلیل data URI فقط برای تصویرهای کوچک مثل آیکون، لوگو یا الگوی پس‌زمینه به‌صرفه است. این ابزار تصویر را کاملاً داخل مرورگر شما می‌خواند، رشته‌ی data: را می‌سازد و همان‌جا قطعه‌کدهای آماده‌ی <img>، background-image و Markdown را در اختیارتان می‌گذارد. هیچ فایلی جایی آپلود نمی‌شود.',
            'steps' => [
                'تصویر را داخل کادر بکشید و رها کنید یا روی آن کلیک کنید تا فایل را انتخاب کنید.',
                'حجم اصلی، طول رشته‌ی data URI و درصد افزایش حجم را در جدول مشخصات ببینید.',
                'به هشدار توجه کنید؛ اگر رشته بیش از حد بزرگ شد، به‌جای درج مستقیم فایل را لینک کنید.',
                'قطعه‌کد موردنظر را — <img>، background-image یا Markdown — با یک کلیک کپی کنید.',
            ],
            'faq' => [
                ['q' => 'data URI تا چه حجمی مناسب است؟', 'a' => 'مرورگرهای امروزی محدودیت سختی ندارند و رشته‌های چندمگابایتی را هم می‌پذیرند، اما IE قدیمی سقف ۳۲ کیلوبایتی داشت. در عمل بهتر است data URI را زیر چند کیلوبایت نگه دارید، چون Base64 حدود ۳۳٪ به حجم اضافه می‌کند و مرورگر نمی‌تواند تصویر را جداگانه کش کند؛ برای عکس‌های بزرگ لینک مستقیم بهتر است.'],
                ['q' => 'آیا تصویر من روی سرور آپلود می‌شود؟', 'a' => 'خیر. تبدیل با FileReader و به‌صورت محلی داخل مرورگر انجام می‌شود و فایل هیچ‌گاه از دستگاه شما خارج نمی‌شود.'],
                ['q' => 'چرا حجم data URI از فایل اصلی بیشتر است؟', 'a' => 'Base64 هر ۳ بایت را به ۴ کاراکتر تبدیل می‌کند، یعنی حدود ۳۳٪ سربار، به‌علاوه‌ی چند کاراکتر سرآیند مثل data:image/png;base64,.'],
                ['q' => 'آیا SVG را هم باید Base64 کرد؟', 'a' => 'لزوماً نه. برای SVG می‌توانید از data URI بدون Base64 و به‌صورت URL-encoded استفاده کنید که معمولاً کوچک‌تر و خواناتر است؛ این ابزار برای سازگاری همه‌ی فرمت‌ها همه‌چیز را Base64 می‌کند.'],
            ],
        ],
        'en' => [
            'intro' => "Base64 encoding makes a file about 33% larger, and the resulting string can't be cached separately by the browser — so a data URI only pays off for small images like icons, logos, or background patterns. This tool reads the image entirely inside your browser, builds the data: string, and hands you ready-to-paste snippets for <img>, CSS background-image, and Markdown. Nothing is uploaded.",
            'steps' => [
                'Drop an image into the box, or click it to pick a file.',
                'Check the stats table for the original size, data URI length, and percentage size increase.',
                'Watch the warning — if the string is too large, link the file instead of inlining it.',
                'Copy the snippet you need — <img>, background-image, or Markdown — with one click.',
            ],
            'faq' => [
                ['q' => 'How large can a data URI be?', 'a' => "Modern browsers have no hard limit and handle multi-megabyte URIs, but old IE capped them at 32 KB. Practically, keep data URIs under a few KB: base64 adds ~33% and the image can't be cached on its own, so large photos are better linked than inlined."],
                ['q' => 'Is my image uploaded anywhere?', 'a' => "No. Conversion runs locally with the browser's FileReader; the file never leaves your device."],
                ['q' => 'Why is the data URI bigger than the original file?', 'a' => 'Base64 turns every 3 bytes into 4 characters, a ~33% overhead, plus a short data:...;base64, header prepended to the payload.'],
                ['q' => 'Should I base64-encode SVGs?', 'a' => 'Not necessarily. SVGs also work as URL-encoded data URIs (no base64), which is usually smaller and stays human-readable. This tool base64-encodes everything for format-agnostic reliability.'],
            ],
        ],
        'tr' => [
            'intro' => 'Base64 kodlama bir dosyayi yaklasik yuzde 33 buyutur ve olusan dizge tarayici tarafindan ayri olarak onbellege alinamaz; bu yuzden data URI yalnizca simge, logo veya arka plan deseni gibi kucuk gorseller icin mantiklidir. Bu arac gorseli tamamen tarayicinizin icinde okur, data: dizgesini olusturur ve <img>, CSS background-image ve Markdown icin hazir kod parcalarini verir. Hicbir dosya yuklenmez.',
            'steps' => [
                'Bir gorseli kutunun icine surukleyip birakin veya dosya secmek icin uzerine tiklayin.',
                'Ozgun boyutu, data URI uzunlugunu ve yuzde boyut artisini ozellikler tablosunda gorun.',
                'Uyariya dikkat edin; dizge cok buyukse gomme yerine dosyayi baglanti olarak verin.',
                'Ihtiyaciniz olan kod parcasini — <img>, background-image veya Markdown — tek tiklamayla kopyalayin.',
            ],
            'faq' => [
                ['q' => 'Bir data URI ne kadar buyuk olabilir?', 'a' => 'Modern tarayicilarda kesin bir sinir yoktur ve megabaytlarca URI islenebilir, ancak eski IE bunu 32 KB ile sinirlardi. Pratikte data URI degerlerini birkac KB altinda tutun; base64 yaklasik yuzde 33 ekler ve gorsel tek basina onbellege alinamaz, bu yuzden buyuk fotograflari gommek yerine baglamak daha iyidir.'],
                ['q' => 'Gorselim bir yere yuklenir mi?', 'a' => 'Hayir. Donusum tarayicinin FileReader ozelligiyle yerel olarak calisir; dosya cihazinizdan cikmaz.'],
                ['q' => 'data URI neden ozgun dosyadan daha buyuk?', 'a' => 'Base64 her 3 bayti 4 karaktere cevirir, bu da yaklasik yuzde 33 ek yuk demektir; ayrica veriye kisa bir data...;base64 basligi eklenir.'],
                ['q' => 'SVG dosyalarini base64 yapmali miyim?', 'a' => 'Sart degil. SVG dosyalari URL kodlu data URI olarak da calisir (base64 olmadan) ve bu genellikle daha kucuk ve okunabilir kalir. Bu arac tum formatlarda guvenilirlik icin her seyi base64 ile kodlar.'],
            ],
        ],
    ],

    'palette-generator' => [
        'fa' => [
            'intro' => 'در فضای HSL رنگ مکمل چیز پیچیده‌ای نیست: همان فام پایه به‌علاوه‌ی ۱۸۰ درجه؛ سه‌گانه با گام‌های ۱۲۰ درجه و چهارگانه با گام‌های ۹۰ درجه ساخته می‌شود. تله‌ی واقعی اینجاست که HSL ادراکی نیست؛ زرد و آبی با روشنایی یکسان ۵۳٪ روی کاغذ برابرند اما چشم زرد را به‌مراتب روشن‌تر می‌بیند، برای همین کنار هر سواچ نسبت کنتراست WCAG هم نوشته شده است. تمام محاسبه‌ها داخل مرورگر شما انجام می‌شود و هیچ رنگی جایی ارسال نمی‌گردد.',
            'steps' => [
                'رنگ پایه را با انتخابگر رنگ بردارید یا کد هگز آن را در کادر بچسبانید؛ لغزنده‌های فام، اشباع و روشنایی همان لحظه هماهنگ می‌شوند.',
                'یکی از شش الگو را انتخاب کنید: مکمل، مشابه، سه‌گانه، مکمل شکسته، چهارگانه یا تک‌فام.',
                'برای الگوهای مشابه، مکمل شکسته و چهارگانه، لغزنده‌ی زاویه را جابه‌جا کنید تا بازشدگی فام‌ها کم یا زیاد شود.',
                'قفل هر سواچی را که پسندیده‌اید ببندید و دکمه‌ی رنگ تصادفی را بزنید تا فقط بقیه دوباره ساخته شوند.',
                'پیشوند متغیر را بنویسید، قالب CSS، SCSS، JSON یا HEX را انتخاب کنید و بلوک خروجی را کپی کنید.',
            ],
            'faq' => [
                ['q' => 'آیا رنگ مکمل همیشه بهترین گزینه برای رنگ تأکیدی است؟', 'a' => 'نه. دو فام دقیقاً ۱۸۰ درجه از هم، وقتی هر دو اشباع بالا داشته باشند، روی لبه‌ی مشترکشان ارتعاش بصری می‌سازند و چشم را خسته می‌کنند. برای رنگ تأکیدی معمولاً مکمل شکسته (۱۵۰ و ۲۱۰ درجه) نتیجه‌ی آرام‌تری می‌دهد، یا اشباع رنگ مکمل را با لغزنده پایین بیاورید.'],
                ['q' => 'چرا در بیشتر الگوها اشباع و روشنایی ثابت می‌ماند؟', 'a' => 'چون تعریف کلاسیک هارمونی رنگ فقط درباره‌ی چرخش فام است و ثابت نگه‌داشتن S و L باعث می‌شود رنگ‌ها هم‌وزن به نظر برسند. تنها استثنا الگوی تک‌فام است که فام را قفل می‌کند و پنج پله‌ی روشنایی با فاصله‌ی ۱۵ واحد می‌سازد؛ اگر رنگ پایه خیلی روشن یا خیلی تیره باشد، کل این پنجره‌ی ۶۰ واحدی جابه‌جا می‌شود تا هر پنج پله متمایز بمانند.'],
                ['q' => 'پالت هارمونیک یعنی پالت دسترس‌پذیر؟', 'a' => 'خیر، و این رایج‌ترین سوءبرداشت است. هارمونی فقط درباره‌ی فاصله‌ی فام‌هاست و هیچ تضمینی برای خوانایی نمی‌دهد. عددی که کنار هر سواچ می‌بینید نسبت کنتراست همان رنگ با مشکی یا سفیدِ انتخاب‌شده روی خودش است، نه با پس‌زمینه‌ی سایت شما؛ برای متن روی پس‌زمینه‌ی واقعی باید جداگانه اندازه بگیرید.'],
                ['q' => 'چرا با عوض کردن الگو، قفل‌ها پاک می‌شوند؟', 'a' => 'چون تعداد خانه‌های هر الگو فرق دارد: مکمل دو، مشابه و سه‌گانه سه، چهارگانه چهار و تک‌فام پنج خانه. نگه‌داشتن قفل خانه‌ی چهارم در الگویی که فقط دو خانه دارد بی‌معنی است، پس با هر تغییر الگو قفل‌ها صفر می‌شوند. رنگ پایه اما دست‌نخورده باقی می‌ماند.'],
            ],
        ],
        'en' => [
            'intro' => 'In HSL a complementary colour is nothing exotic: it is the base hue plus 180 degrees, triadic steps by 120, tetradic by 90. The real trap is that HSL is not perceptually uniform — yellow and blue at an identical 53% lightness measure the same but read as wildly different brightness, which is why every swatch here also carries its WCAG contrast ratio. All the maths runs in your browser; no colour is ever sent anywhere.',
            'steps' => [
                'Pick a base colour with the colour input, or paste a HEX code — the hue, saturation and lightness sliders sync instantly.',
                'Choose one of six schemes: complementary, analogous, triadic, split-complementary, tetradic or monochromatic.',
                'For analogous, split-complementary and tetradic, drag the angle slider to widen or tighten the hue spread.',
                'Lock any swatch you want to keep, then hit Randomise so only the unlocked slots are rebuilt.',
                'Set a variable prefix, choose CSS, SCSS, JSON or plain HEX, and copy the exported block.',
            ],
            'faq' => [
                ['q' => 'Is the complementary colour always the best accent?', 'a' => 'No. Two hues exactly 180 degrees apart, both at high saturation, vibrate along their shared edge and tire the eye. For an accent, split-complementary (150 and 210 degrees) usually lands softer, or simply drop the saturation of the complement with the slider.'],
                ['q' => 'Why do saturation and lightness stay fixed across most schemes?', 'a' => 'Because classical colour harmony is defined purely as a hue rotation, and holding S and L constant is what makes the results feel evenly weighted. The one exception is monochromatic, which locks the hue and builds five lightness stops 15 points apart; if the base is very light or very dark the whole 60-point window slides instead of clipping, so all five stops stay distinct.'],
                ['q' => 'Does a harmonious palette mean an accessible palette?', 'a' => 'No, and this is the most common misconception. Harmony is only about hue spacing and guarantees nothing about legibility. The number beside each swatch is that colour against the black or white label auto-picked for the chip itself, not against your site background — measure text on your real background separately.'],
                ['q' => 'Why do my locks disappear when I switch scheme?', 'a' => 'Because the slot count differs per scheme: complementary has two, analogous and triadic three, tetradic four, monochromatic five. Keeping a lock on slot four inside a two-slot scheme is meaningless, so locks reset on every scheme change. The base colour itself is untouched.'],
            ],
        ],
        'tr' => [
            'intro' => 'HSL uzayında tamamlayıcı renk hiç de gizemli değildir: ana tonun 180 derece dönmüş halidir, üçlü şema 120, dörtlü şema 90 derecelik adımlarla ilerler. Asıl tuzak, HSL uzayının algısal olarak eşit olmamasıdır; aynı yüzde 53 açıklık değerindeki sarı ile mavi ölçümde eşittir ama göze çok farklı parlaklıkta görünür, bu yüzden her örneğin yanında WCAG kontrast oranı da yazar. Tüm hesaplar tarayıcınızda çalışır, hiçbir renk dışarı gönderilmez.',
            'steps' => [
                'Renk seçiciyle ana rengi belirleyin veya HEX kodunu kutuya yapıştırın; ton, doygunluk ve açıklık kaydırıcıları anında eşitlenir.',
                'Altı şemadan birini seçin: tamamlayıcı, benzer, üçlü, bölünmüş tamamlayıcı, dörtlü veya tek renkli.',
                'Benzer, bölünmüş tamamlayıcı ve dörtlü şemalarda açı kaydırıcısını sürükleyerek ton aralığını genişletin veya daraltın.',
                'Beğendiğiniz örneği kilitleyin, ardından Rastgele düğmesine basın; yalnızca kilitsiz kutular yeniden üretilir.',
                'Değişken önekini yazın, CSS, SCSS, JSON veya düz HEX biçimini seçin ve çıktı bloğunu kopyalayın.',
            ],
            'faq' => [
                ['q' => 'Vurgu rengi için her zaman tamamlayıcı renk mi seçilmeli?', 'a' => 'Hayır. Tam 180 derece uzaktaki iki ton, ikisi de yüksek doygunluktaysa ortak kenarlarında titreşim yaratır ve gözü yorar. Vurgu için bölünmüş tamamlayıcı (150 ve 210 derece) genellikle daha yumuşak sonuç verir; ya da tamamlayıcı rengin doygunluğunu kaydırıcıyla düşürün.'],
                ['q' => 'Şemaların çoğunda doygunluk ve açıklık neden sabit kalıyor?', 'a' => 'Çünkü klasik renk uyumu yalnızca ton döndürmesi olarak tanımlanır ve S ile L değerlerini sabit tutmak renklerin eşit ağırlıkta görünmesini sağlar. Tek istisna, tonu kilitleyip 15 birim aralıklı beş açıklık basamağı üreten tek renkli şemadır; ana renk çok açık veya çok koyuysa 60 birimlik pencere kırpılmak yerine kayar, böylece beş basamak da ayırt edilebilir kalır.'],
                ['q' => 'Uyumlu palet erişilebilir palet demek midir?', 'a' => 'Hayır, en yaygın yanlış anlama budur. Uyum sadece tonlar arasındaki açıyla ilgilidir ve okunabilirlik konusunda hiçbir güvence vermez. Her örneğin yanındaki sayı, o rengin kendi üzerine otomatik seçilen siyah veya beyaz etikete göre kontrastıdır; sitenizin arka planına göre değil. Gerçek arka plan üzerindeki metni ayrıca ölçmelisiniz.'],
                ['q' => 'Şemayı değiştirince kilitlerim neden siliniyor?', 'a' => 'Çünkü her şemanın kutu sayısı farklıdır: tamamlayıcı iki, benzer ve üçlü üç, dörtlü dört, tek renkli beş kutu içerir. İki kutulu bir şemada dördüncü kutunun kilidini korumak anlamsız olacağından her şema değişiminde kilitler sıfırlanır. Ana renk ise olduğu gibi kalır.'],
            ],
        ],
    ],

    'qr-generator' => [
        'fa' => [
            'intro' => 'در یک کد QR نسخه‌ی ۲ با سطح تصحیح خطای M، از مجموع ۴۴ کدواژه، ۱۶ کدواژه صرف پاریتی رید-سالومون می‌شود؛ یعنی بیش از یک‌سوم نقش‌ونگار کد فقط برای زنده‌ماندن در برابر خط‌وخش و چروک آنجاست. شایع‌ترین دلیل خوانده‌نشدن یک کد چاپ‌شده هم کیفیت چاپ نیست، بلکه حذف حاشیه‌ی سفید دور آن است: استاندارد چهار ماژول فضای خالی در هر چهار طرف را الزامی می‌کند. این ابزار کد را کامل در مرورگر شما می‌سازد و همان حاشیه را داخل خروجی PNG نگه می‌دارد.',
            'steps' => [
                'متن یا نشانی موردنظر را در کادر محتوا بنویسید؛ کد با هر بار تایپ بی‌درنگ بازسازی می‌شود.',
                'سطح تصحیح خطا را انتخاب کنید: L برای نمایش روی صفحه و H برای چاپ روی سطحی که در معرض ساییدگی است.',
                'اندازه‌ی هر ماژول را با لغزنده تنظیم کنید تا ابعاد نهایی تصویر به کار چاپ یا وب بخورد.',
                'ماسک را روی «خودکار» بگذارید تا کم‌جریمه‌ترین الگو از میان هشت الگو انتخاب شود؛ عدد ثابت فقط برای بررسی و اشکال‌زدایی است.',
                'دکمه‌ی دانلود PNG را بزنید؛ حاشیه‌ی چهار ماژولی داخل خود تصویر قرار دارد.',
            ],
            'faq' => [
                ['q' => 'آیا سطح تصحیح خطای بالاتر همیشه کد را مطمئن‌تر می‌کند؟', 'a' => 'نه. سطح H حدود ۳۰٪ از کد را بازیابی می‌کند، اما ظرفیت داده را به‌شدت کم می‌کند؛ همان متن مجبور می‌شود به نسخه‌ی بالاتری برود و ماژول‌ها ریزتر شوند. اگر ابعاد چاپ ثابت باشد، H گاهی بدتر از M خوانده می‌شود چون هر ماژول فیزیکی کوچک‌تر است. برای بیشتر کاربردها M تعادل درستی است.'],
                ['q' => 'این ابزار تا چه اندازه داده را پشتیبانی می‌کند؟', 'a' => 'نسخه‌های ۱ تا ۱۰ پیاده‌سازی شده‌اند، یعنی حداکثر ۲۷۱ بایت در سطح L، ۲۱۳ بایت در M، ۱۵۱ بایت در Q و ۱۱۹ بایت در H. این برای نشانی اینترنتی، اطلاعات وای‌فای و متن کوتاه کافی است، اما یک vCard طولانی پیام خطای ظرفیت می‌گیرد. حالت کدگذاری هم فقط Byte است؛ حالت‌های عددی و الفبایی-عددی که رقم‌های خالص را فشرده‌تر ذخیره می‌کنند پیاده نشده‌اند.'],
                ['q' => 'متن فارسی چطور کدگذاری می‌شود؟', 'a' => 'متن به UTF-8 تبدیل و در حالت Byte نوشته می‌شود. تقریباً همه‌ی اپلیکیشن‌های اسکنر امروزی UTF-8 را خودکار تشخیص می‌دهند، اما استاندارد به‌طور پیش‌فرض ISO-8859-1 را فرض می‌کند و این ابزار نشانگر ECI درج نمی‌کند؛ بنابراین روی بارکدخوان‌های صنعتی قدیمی ممکن است متن فارسی درست نمایش داده نشود. ضمناً هر حرف فارسی دو بایت مصرف می‌کند و ظرفیت را زودتر پر می‌کند.'],
                ['q' => 'آیا محتوای کد به جایی ارسال می‌شود؟', 'a' => 'خیر. کل مسیر — از کدگذاری و محاسبه‌ی رید-سالومون روی GF(256) تا انتخاب ماسک و رسم روی canvas — در مرورگر شما اجرا می‌شود و هیچ درخواست شبکه‌ای زده نمی‌شود. می‌توانید اتصال را قطع کنید و ابزار همچنان کار می‌کند.'],
            ],
        ],
        'en' => [
            'intro' => 'In a version-2 QR code at error correction level M, 16 of the 44 codewords are Reed-Solomon parity — more than a third of the symbol exists purely to survive damage. The most common reason a printed code fails to scan is not print resolution but a missing quiet zone: the standard requires four empty modules on all four sides. This generator builds the symbol entirely in your browser and keeps that margin inside the exported PNG.',
            'steps' => [
                'Type or paste your text or URL into the content box; the code redraws on every keystroke.',
                'Pick an error correction level: L for on-screen use, H when the code will be printed on something that gets scuffed.',
                'Set the module size slider to match the output dimensions you need for print or web.',
                'Leave the mask on Auto so the lowest-penalty pattern of the eight wins; pin a fixed 0-7 only when debugging or comparing output.',
                'Click Download PNG — the 4-module quiet zone is baked into the image.',
            ],
            'faq' => [
                ['q' => 'Does a higher error correction level always make the code more reliable?', 'a' => 'No. Level H recovers about 30% of the symbol but cuts data capacity sharply, pushing the same text into a larger version with smaller modules. At a fixed print size H can scan worse than M, because each physical module is tinier. M is the right balance for most jobs.'],
                ['q' => 'How much data can this tool encode?', 'a' => 'Versions 1 through 10 are implemented: at most 271 bytes at level L, 213 at M, 151 at Q and 119 at H. That covers URLs, WiFi credentials and short text, but a long vCard will trigger the capacity error. Only byte mode is implemented — numeric and alphanumeric modes, which pack pure digits far more tightly, are not.'],
                ['q' => 'How is non-Latin text handled?', 'a' => 'Text is converted to UTF-8 and written in byte mode. Virtually every modern scanner app auto-detects UTF-8, but the standard assumes ISO-8859-1 by default and this tool does not emit an ECI header, so older industrial scanners may render non-Latin text incorrectly. Each Persian or accented character also costs two bytes, so capacity fills faster than the character count suggests.'],
                ['q' => 'Is the content uploaded anywhere?', 'a' => 'No. The whole pipeline — encoding, Reed-Solomon over GF(256), mask selection and canvas rendering — runs in your browser and makes no network requests. Disconnect the machine and the tool still works.'],
            ],
        ],
        'tr' => [
            'intro' => 'Sürüm 2 bir QR kodunda M hata düzeltme seviyesinde 44 kod sözcüğünün 16 tanesi Reed-Solomon paritesidir; yani desenin üçte birinden fazlası yalnızca hasara dayanmak için oradadır. Basılı bir kodun okunmama sebebi çoğu zaman baskı kalitesi değil, kaldırılmış sessiz alandır: standart dört kenarın her birinde 4 modüllük boşluk ister. Bu araç kodu tamamen tarayıcınızda üretir ve o boşluğu PNG çıktısının içinde korur.',
            'steps' => [
                'Metninizi veya bağlantınızı içerik kutusuna yazın; kod her tuş vuruşunda yeniden çizilir.',
                'Hata düzeltme seviyesini seçin: ekran kullanımı için L, aşınmaya açık bir yüzeye basılacaksa H.',
                'Modül boyutu kaydırıcısını baskı veya web için ihtiyacınız olan çıktı boyutuna göre ayarlayın.',
                'Maskeyi Otomatik bırakın ki sekiz desen arasından en düşük cezalı olan seçilsin; sabit 0-7 değerini yalnızca hata ayıklarken veya çıktı karşılaştırırken sabitleyin.',
                'PNG indir düğmesine basın; 4 modüllük sessiz alan görüntünün içindedir.',
            ],
            'faq' => [
                ['q' => 'Daha yüksek hata düzeltme seviyesi kodu her zaman daha güvenilir yapar mı?', 'a' => 'Hayır. H seviyesi desenin yaklaşık %30 kadarını kurtarır ama veri kapasitesini ciddi biçimde düşürür; aynı metin daha büyük bir sürüme taşınır ve modüller küçülür. Sabit bir baskı boyutunda H, M seviyesinden daha kötü okunabilir çünkü her fiziksel modül daha ufaktır. Çoğu iş için M dengeli seçimdir.'],
                ['q' => 'Bu araç ne kadar veri kodlayabilir?', 'a' => 'Sürüm 1 ile 10 arası uygulanmıştır: L seviyesinde en fazla 271 bayt, M seviyesinde 213, Q seviyesinde 151 ve H seviyesinde 119 bayt. Bu bağlantılar, WiFi bilgileri ve kısa metinler için yeterlidir, ancak uzun bir vCard kapasite hatası verir. Yalnızca byte modu uygulanmıştır; saf rakamları çok daha sıkı paketleyen sayısal ve alfanumerik modlar bulunmaz.'],
                ['q' => 'Latin dışı karakterler nasıl işlenir?', 'a' => 'Metin UTF-8 olarak kodlanır ve byte modunda yazılır. Modern tarayıcı uygulamalarının neredeyse tamamı bu kodlamayı otomatik algılar, ancak standart varsayılan olarak ISO-8859-1 kabul eder ve bu araç ECI başlığı eklemez; bu yüzden eski endüstriyel okuyucularda Latin dışı metin bozuk görünebilir. Ayrıca Türkçe özel karakterlerin her biri iki bayt tuttuğu için kapasite karakter sayısının ima ettiğinden daha hızlı dolar.'],
                ['q' => 'İçerik bir yere gönderiliyor mu?', 'a' => 'Hayır. Kodlama, GF(256) üzerinde Reed-Solomon hesabı, maske seçimi ve canvas çizimi dahil tüm işlem tarayıcınızda çalışır ve hiçbir ağ isteği yapılmaz. Makinenin bağlantısını kesseniz bile araç çalışmaya devam eder.'],
            ],
        ],
    ],

    'svg-to-png' => [
        'fa' => [
            'intro' => 'کیفیت PNG خروجی از یک SVG به اندازه‌ای که رستر می‌کنید بستگی دارد: اگر تگ <svg> صفت width و height نداشته باشد، مرورگرها آن را ۳۰۰×۱۵۰ فرض می‌کنند مگر اینکه viewBox موجود باشد. این ابزار viewBox را می‌خواند، تصویر را روی بوم در مقیاس ۱x تا ۴x یا عرض دلخواه رسم می‌کند و فایل PNG می‌دهد. همهٔ کار در مرورگر شما و به‌صورت آفلاین انجام می‌شود و هیچ فایلی آپلود نمی‌شود.',
            'steps' => [
                'کد SVG را بچسبانید یا فایل ‎.svg‎ را بارگذاری کنید.',
                'مقیاس ۱x/۲x/۴x یا «عرض دلخواه» را بر حسب پیکسل انتخاب کنید.',
                'در صورت نیاز «پس‌زمینهٔ سفید» را فعال کنید تا شفافیت با سفید پر شود.',
                'اندازهٔ خروجی را در پیش‌نمایش ببینید و روی «دانلود PNG» بزنید.',
            ],
            'faq' => [
                ['q' => 'چرا PNG من تار یا پیکسلی است؟', 'a' => 'PNG یک قالب رستری است؛ در مقیاس ۱x همان ابعاد پیکسلی viewBox رسم می‌شود. برای نمایشگرهای رتینا مقیاس ۲x یا ۴x را انتخاب کنید یا عرض دلخواه بزرگ‌تری بدهید.'],
                ['q' => 'چرا بعضی SVGها با خطای «ارجاع بیرونی» رد می‌شوند؟', 'a' => 'اگر SVG به تصویر، فونت یا فایل بیرونی (آدرس http یا مسیر نسبی مثل logo.png) ارجاع دهد، مرورگر بوم را «آلوده» می‌کند و گرفتن خروجی ممنوع می‌شود. منبع را به‌صورت data: درون‌خطی کنید تا محدودیت برطرف شود.'],
                ['q' => 'پس‌زمینهٔ PNG شفاف است یا سفید؟', 'a' => 'به‌طور پیش‌فرض شفاف است، چون PNG کانال آلفا دارد. برای پس‌زمینهٔ سفید، گزینهٔ «پس‌زمینهٔ سفید» را فعال کنید.'],
                ['q' => 'آیا فایل من جایی آپلود می‌شود؟', 'a' => 'خیر؛ تبدیل کاملاً در مرورگر و به‌صورت محلی انجام می‌شود و چیزی به سرور فرستاده نمی‌شود.'],
            ],
        ],
        'en' => [
            'intro' => 'A PNG exported from an SVG is only as sharp as the pixel size you render at: if the <svg> tag has no width and height, browsers assume 300×150 unless a viewBox is present. This converter reads the viewBox, rasterizes to a canvas at 1x–4x or a custom width, and hands you a PNG — all locally, with nothing uploaded.',
            'steps' => [
                'Paste your SVG markup or upload a .svg file.',
                'Pick a 1x/2x/4x multiplier, or choose Custom width in pixels.',
                'Optionally turn on White background to flatten transparency.',
                'Check the output size in the preview, then click Download PNG.',
            ],
            'faq' => [
                ['q' => 'Why does my PNG look blurry or pixelated?', 'a' => 'PNG is a raster format. At 1x it renders the viewBox pixel size; for retina screens pick 2x or 4x, or give a larger custom width.'],
                ['q' => 'Why are some SVGs rejected with an "external reference" error?', 'a' => 'If the SVG links to an external image, font, or file (an http URL or a relative path like logo.png), the browser taints the canvas and blocks export. Inline the resource as a data: URI to get around the limit.'],
                ['q' => 'Is the PNG background transparent or white?', 'a' => 'Transparent by default, because PNG has an alpha channel. Enable White background to flatten it to a solid white fill.'],
                ['q' => 'Are my files uploaded anywhere?', 'a' => 'No. Conversion runs entirely in your browser, offline — nothing is sent to a server.'],
            ],
        ],
        'tr' => [
            'intro' => 'Bir SVG dosyasindan alinan PNG cozunurlugu render ettiginiz piksel boyutuna baglidir: <svg> etiketinde width ve height yoksa tarayicilar viewBox olmadikca 300x150 varsayar. Bu arac viewBox degerini okur, tuval uzerinde 1x-4x veya ozel genislikte rasterize eder ve size PNG verir; her sey tarayicida, cevrimdisi ve hicbir dosya yuklenmeden calisir.',
            'steps' => [
                'SVG kodunu yapistirin veya bir .svg dosyasi yukleyin.',
                '1x/2x/4x carpanini secin ya da piksel cinsinden Ozel genislik girin.',
                'Saydamligi beyazla doldurmak icin istege bagli Beyaz arka plan secenegini acin.',
                'Onizlemede cikti boyutunu gorun ve PNG indir dugmesine basin.',
            ],
            'faq' => [
                ['q' => 'PNG neden bulanik veya pikselli gorunuyor?', 'a' => 'PNG raster bir bicimdir. 1x degerinde viewBox piksel boyutunda render edilir; retina ekranlar icin 2x veya 4x secin ya da daha buyuk bir ozel genislik verin.'],
                ['q' => 'Bazi SVGler neden dis referans hatasiyla reddediliyor?', 'a' => 'SVG dis bir goruntu, yazi tipi veya dosyaya (http adresi ya da logo.png gibi goreli bir yol) baglaniyorsa tarayici tuvali kirletir ve disa aktarmayi engeller. Sinirlamayi asmak icin kaynagi data: URI olarak satir icine alin.'],
                ['q' => 'PNG arka plani saydam mi beyaz mi?', 'a' => 'Varsayilan olarak saydamdir cunku PNG bir alfa kanali icerir. Duz beyaz dolguya cevirmek icin Beyaz arka plan secenegini acin.'],
                ['q' => 'Dosyalarim bir yere yukleniyor mu?', 'a' => 'Hayir. Donusturme tamamen tarayicinizda, cevrimdisi calisir; sunucuya hicbir sey gonderilmez.'],
            ],
        ],
    ],

    'bg-pattern' => [
        'fa' => [
            'intro' => 'همهٔ الگوهای این ابزار فقط از گرادیان ساخته می‌شوند؛ نه فایل تصویری دارند نه SVG، پس هیچ درخواست شبکه‌ای اضافه نمی‌کنند و در هر بزرگ‌نمایی تیز می‌مانند. یک نکته که خیلی‌ها را گیر می‌اندازد: repeating-linear-gradient روی محور خودِ گرادیان تکرار می‌شود، نه روی کادر عنصر؛ برای همین الگوی صفر درجه از لبهٔ پایین شروع می‌شود و با بلندتر شدن عنصر می‌لغزد. به همین دلیل زاویهٔ خطوط الگوی شبکه اینجا روی ۹۰ و ۱۸۰ درجه قفل شده تا همیشه به لبهٔ بالا و ابتدای کادر بچسبند.',
            'steps' => [
                'یکی از هفت الگو را از نوار بالا انتخاب کنید: راه‌راه، شطرنجی، نقطه‌ای، زیگزاگ، هاشور مورب، شبکه یا کربن.',
                'رنگ پس‌زمینه و رنگ نقش را با دو انتخابگر رنگ تعیین کنید؛ دکمهٔ «جابه‌جایی رنگ» جای این دو را عوض می‌کند.',
                'اندازهٔ کاشی، ضخامت و زاویه را با لغزنده‌ها تنظیم کنید؛ لغزنده‌هایی که روی الگوی انتخابی اثری ندارند خودکار غیرفعال می‌شوند.',
                'اگر می‌خواهید خروجی داخل یک انتخابگر بیاید، تیک «بسته‌بندی در کلاس CSS» را بزنید.',
                'کد CSS تولیدشده را کپی کنید و مستقیم روی عنصر دلخواهتان بگذارید.',
            ],
            'faq' => [
                ['q' => 'چرا لغزندهٔ زاویه برای شطرنجی، نقطه‌ای، زیگزاگ، شبکه و کربن غیرفعال است؟', 'a' => 'چون هندسهٔ این پنج الگو با conic-gradient، radial-gradient یا چند لایهٔ لینیر با موقعیت ثابت ساخته شده و چرخاندنشان فقط با خصوصیت‌های background شدنی نیست. برای چرخش واقعی باید روی خود عنصر transform: rotate بگذارید که کادر را هم می‌چرخاند. اگر الگوی زاویه‌دار می‌خواهید، «راه‌راه» و «هاشور مورب» زاویه را کامل پشتیبانی می‌کنند.'],
                ['q' => 'آیا الگوی CSS واقعاً از تصویر پس‌زمینه سبک‌تر است؟', 'a' => 'از نظر شبکه بله: صفر بایت دانلود، صفر درخواست HTTP و مستقل از رزولوشن. ولی «رایگان» نیست؛ هر لایهٔ گرادیان در هر بار رنگ‌آمیزی دوباره محاسبه می‌شود. الگوی کربن شش لایه دارد و اگر آن را روی یک عنصر تمام‌صفحهٔ متحرک بگذارید افت فریم می‌بینید. برای پس‌زمینهٔ ثابت هیچ مشکلی ندارد.'],
                ['q' => 'چرا خانه‌های شطرنجی نصفِ اندازه‌ای است که تنظیم کرده‌ام؟', 'a' => 'عددی که تنظیم می‌کنید اندازهٔ کاشی تکرارشونده است و هر کاشی دقیقاً چهار خانه دارد. پس با اندازهٔ ۴۰ پیکسل، هر خانه ۲۰ در ۲۰ پیکسل می‌شود. خانهٔ گوشه هم همیشه رنگ پس‌زمینه را می‌گیرد، نه رنگ نقش را.'],
                ['q' => 'در مرورگرهای قدیمی چه اتفاقی می‌افتد؟', 'a' => 'شش الگو از هفت الگو فقط از linear-gradient و radial-gradient استفاده می‌کنند که سال‌هاست همه‌جا کار می‌کنند. تنها الگوی شطرنجی به conic-gradient نیاز دارد که از سال ۲۰۲۰ در کروم، فایرفاکس و سافاری هست؛ در مرورگرهای قدیمی‌تر به جای شطرنج فقط رنگ پس‌زمینهٔ ساده دیده می‌شود.'],
            ],
        ],
        'en' => [
            'intro' => 'Every pattern here is built from gradients alone: no image file and no SVG, so it costs zero HTTP requests and stays sharp at any zoom level. One detail catches people out — repeating-linear-gradient repeats along its own gradient axis, not along the element box, so a 0deg pattern starts at the bottom edge and slides as the element gets taller. That is why the grid pattern here is locked to 90deg and 180deg, which anchor the lines to the top and inline-start edges instead.',
            'steps' => [
                'Pick one of seven patterns from the row at the top: stripes, checks, dots, zigzag, diagonal crosshatch, grid or carbon.',
                'Set the background colour and the pattern colour; the swap button exchanges the two.',
                'Adjust tile size, thickness and angle with the sliders — sliders that do nothing for the selected pattern are disabled automatically.',
                'Tick "wrap in a CSS class" if you want the output inside a .pattern selector.',
                'Copy the generated CSS and paste it straight onto your element.',
            ],
            'faq' => [
                ['q' => 'Why is the angle slider disabled for checks, dots, zigzag, grid and carbon?', 'a' => 'Those five are built from conic-gradient, radial-gradient or fixed-position stacked linear layers, and none of them can be rotated using background properties alone. Real rotation needs transform: rotate on the element, which rotates the box as well. If you want an angled pattern, stripes and diagonal crosshatch support the full 0-360 degree range.'],
                ['q' => 'Is a CSS pattern really lighter than a background image?', 'a' => 'On the network, yes: zero bytes downloaded, zero requests, and resolution independent. It is not free though. Every gradient layer is recomputed on each paint, and the carbon pattern uses six layers — put that on a full-screen animated element and you will see frame drops. For a static background it costs nothing you will notice.'],
                ['q' => 'Why are the checkerboard squares half the size I set?', 'a' => 'The value you set is the size of the repeating tile, and each tile holds exactly four squares. So a 40px tile gives 20x20px squares. The corner square always takes the background colour, not the pattern colour.'],
                ['q' => 'What happens in older browsers?', 'a' => 'Six of the seven patterns use only linear-gradient and radial-gradient, which have worked everywhere for years. Only checks needs conic-gradient, supported in Chrome, Firefox and Safari since 2020. In anything older the checkerboard degrades to the flat background colour.'],
            ],
        ],
        'tr' => [
            'intro' => 'Buradaki desenlerin tamamı yalnızca gradyanlardan üretilir; ne resim dosyası ne de SVG vardır, bu yüzden sıfır HTTP isteği maliyeti taşır ve her yakınlaştırma düzeyinde keskin kalır. Çoğu kişiyi yanıltan bir ayrıntı var: repeating-linear-gradient kutu boyunca değil kendi gradyan ekseni boyunca tekrar eder, bu yüzden 0 derecelik bir desen alt kenardan başlar ve öğe uzadıkça kayar. Bu nedenle ızgara deseni burada 90 ve 180 dereceye sabitlenmiştir; böylece çizgiler üst ve başlangıç kenarına demirlenir.',
            'steps' => [
                'Üstteki şeritten yedi desenden birini seçin: çizgili, dama, nokta, zikzak, çapraz tarama, ızgara veya karbon.',
                'Arka plan rengini ve desen rengini iki renk seçiciyle belirleyin; renk takas düğmesi ikisinin yerini değiştirir.',
                'Karo boyutunu, kalınlığı ve açıyı kaydırıcılarla ayarlayın; seçili desende etkisi olmayan kaydırıcılar otomatik olarak kapanır.',
                'Çıktının bir .pattern seçicisi içinde gelmesini istiyorsanız CSS sınıfı içine sar kutusunu işaretleyin.',
                'Üretilen CSS kodunu kopyalayıp doğrudan öğenize yapıştırın.',
            ],
            'faq' => [
                ['q' => 'Açı kaydırıcısı neden dama, nokta, zikzak, ızgara ve karbon desenlerinde kapalı?', 'a' => 'Bu beş desenin geometrisi conic-gradient, radial-gradient veya sabit konumlu üst üste linear katmanlarla kurulur ve hiçbiri yalnızca background özellikleriyle döndürülemez. Gerçek bir döndürme için öğeye transform: rotate uygulamanız gerekir, bu da kutunun kendisini döndürür. Açılı bir desen istiyorsanız çizgili ve çapraz tarama seçenekleri 0-360 derece aralığını tam destekler.'],
                ['q' => 'CSS deseni gerçekten arka plan resminden daha mı hafif?', 'a' => 'Ağ tarafında evet: sıfır bayt indirme, sıfır istek ve çözünürlükten bağımsız keskinlik. Ama bedava değildir; her gradyan katmanı her boyama işleminde yeniden hesaplanır. Karbon deseni altı katman kullanır ve bunu tam ekran hareketli bir öğeye verirseniz kare düşüşü görürsünüz. Sabit bir arka planda ise fark edeceğiniz bir maliyeti yoktur.'],
                ['q' => 'Dama desenindeki kareler neden ayarladığım boyutun yarısı?', 'a' => 'Ayarladığınız değer tekrarlanan karonun boyutudur ve her karo tam olarak dört kare içerir. Yani 40 piksellik bir karoda her kare 20x20 piksel olur. Köşedeki kare de her zaman desen rengini değil arka plan rengini alır.'],
                ['q' => 'Eski tarayıcılarda ne olur?', 'a' => 'Yedi desenin altısı yalnızca linear-gradient ve radial-gradient kullanır; bunlar yıllardır her yerde çalışır. Sadece dama deseni conic-gradient gerektirir ve bu da Chrome, Firefox ile Safari tarafında 2020 yılından beri desteklenir. Daha eski tarayıcılarda dama yerine düz arka plan rengi görünür.'],
            ],
        ],
    ],

    'color-mixer' => [
        'fa' => [
            'intro' => 'میانگین‌گرفتن از دو کد هگز، در واقع میانگین‌گرفتن از عددهای گامادار sRGB است، نه از مقدار نور. آبی #0000ff را با زرد #ffff00 این‌طور ترکیب کنید و به خاکستری بی‌جان #808080 می‌رسید؛ همان ترکیب در فضای OKLab نتیجه‌اش #6cabc7 است. این ابزار هر دو مسیر را همزمان و کنار هم حساب می‌کند تا اختلاف را با چشم ببینید، به‌علاوهٔ یک نسخهٔ OKLCH که فام را از کوتاه‌ترین کمان می‌چرخاند.',
            'steps' => [
                'دو رنگ را با انتخابگر رنگ بردارید یا کد هگز آن‌ها را در کادر کناری بنویسید.',
                'لغزندهٔ سهم رنگ دوم را روی درصد دلخواه ببرید؛ صفر یعنی فقط رنگ اول و صد یعنی فقط رنگ دوم.',
                'سه کارت sRGB، OKLab و OKLCH را کنار هم مقایسه کنید و کد هگز هرکدام را با دکمهٔ کپی بردارید.',
                'تعداد پله‌ها را تغییر دهید و روی هر پله از نوارهای طیف بزنید تا کد هگزش کپی شود.',
                'کادر خروجی CSS را بردارید؛ متغیرها، معادل color-mix و گرادیان OKLab آماده‌اند.',
            ],
            'faq' => [
                ['q' => 'چرا نتیجهٔ sRGB و OKLab این‌قدر با هم فرق دارد؟', 'a' => 'چون کد هگز مقدار نور نیست؛ عددی است که با گامای حدوداً ۲٫۲ رمزگذاری شده. میانگین دو عدد گامادار، وسطِ ادراکیِ دو رنگ نیست. نمونهٔ گویا: وسط سیاه و سفید در sRGB می‌شود #808080، ولی خاکستری‌ای که چشم دقیقاً وسط می‌بیند #636363 است — همان چیزی که OKLab با L=۵۰٪ می‌دهد.'],
                ['q' => 'خروجی این ابزار با color-mix(in oklab, …) مرورگر یکی است؟', 'a' => 'برای رنگ‌های مات بله؛ هر دو همان درون‌یابی خطی در OKLab را انجام می‌دهند و ماتریس‌های پیاده‌شده اینجا دقیقاً ماتریس‌های اصلی OKLab هستند. تنها اختلاف ممکن در رنگ‌های بیرون از گاموت است: ما هر کانال را ساده می‌بُریم (clip) در حالی که بعضی مرورگرها gamut mapping دقیق‌تری انجام می‌دهند.'],
                ['q' => 'پس همیشه باید OKLCH را انتخاب کرد؟', 'a' => 'نه. OKLCH برای طیف‌های تک‌فام یا رنگ‌های نزدیک به هم عالی است، اما وسطِ قرمز و آبی از سمت صورتی رد می‌شود و به #ba00c2 می‌رسد که بیرون از گاموت sRGB است و بریده می‌شود؛ کارت مربوطه همین را هشدار می‌دهد. برای دو رنگ دور از هم، OKLab معمولاً نتیجهٔ امن‌تری دارد.'],
                ['q' => 'آلفا و رنگ‌های نیمه‌شفاف پشتیبانی می‌شوند؟', 'a' => 'نه. ورودی فقط هگز مات سه یا شش‌رقمی است و کانال آلفا نادیده گرفته می‌شود. اگر لایه‌ای نیمه‌شفاف دارید، اول رنگ نهایی روی پس‌زمینه را حساب کنید و بعد آن را اینجا ترکیب کنید. تمام محاسبه هم داخل مرورگر خودتان انجام می‌شود و چیزی به سرور نمی‌رود.'],
            ],
        ],
        'en' => [
            'intro' => 'Averaging two hex codes averages gamma-encoded sRGB numbers, not light. Blend #0000ff with #ffff00 that way and you land on dead grey #808080; the same blend in OKLab gives #6cabc7. This tool runs both paths at once and shows them side by side, plus an OKLCH version that rotates hue along the shortest arc.',
            'steps' => [
                'Pick two colours with the swatch pickers, or type their hex codes in the field next to each one.',
                'Drag the share slider to the percentage you want — 0 is pure first colour, 100 is pure second colour.',
                'Compare the sRGB, OKLab and OKLCH cards side by side and copy whichever hex you need.',
                "Change the step count and click any swatch in the ramps to copy that step's hex code.",
                'Take the CSS box: it holds the variables, the equivalent color-mix() call and an OKLab gradient.',
            ],
            'faq' => [
                ['q' => 'Why do the sRGB and OKLab results differ so much?', 'a' => 'A hex code is not a light value — it is a number encoded with roughly a 2.2 gamma. Averaging two gamma-encoded numbers does not land on the perceptual midpoint. Concrete case: the midpoint of black and white in sRGB is #808080, but the grey the eye reads as halfway is #636363, which is exactly what OKLab gives at L=50%.'],
                ['q' => "Does this match the browser's color-mix(in oklab, …)?", 'a' => 'For opaque colours, yes. Both do a linear interpolation in OKLab, and the matrices implemented here are the original OKLab ones. The only possible difference is out-of-gamut results: this tool clips each channel, whereas some browsers apply a more careful gamut mapping.'],
                ['q' => 'So should I always use OKLCH?', 'a' => 'No. OKLCH is excellent for single-hue ramps or colours close on the wheel, but red to blue travels through magenta and the midpoint comes out as #ba00c2, which falls outside sRGB and gets clipped — the card warns you when that happens. For two distant colours, OKLab is usually the safer result.'],
                ['q' => 'Are alpha and semi-transparent colours supported?', 'a' => 'No. The inputs accept opaque 3- or 6-digit hex only, and any alpha channel is ignored. If you are mixing a translucent layer, first compute the flattened colour over its background, then mix that here. Everything runs in your own browser; nothing is uploaded.'],
            ],
        ],
        'tr' => [
            'intro' => 'İki hex kodunun ortalamasını almak, ışığın değil gama kodlu sRGB sayılarının ortalamasını alır. #0000ff ile #ffff00 rengini böyle karıştırırsanız cansız bir gri olan #808080 çıkar; aynı karışım OKLab uzayında #6cabc7 verir. Bu araç iki yolu aynı anda hesaplar ve yan yana gösterir, ayrıca renk tonunu en kısa yay boyunca döndüren bir OKLCH sürümü ekler.',
            'steps' => [
                'İki rengi renk seçicilerden alın ya da yanlarındaki kutuya hex kodlarını yazın.',
                'İkinci rengin payı kaydırıcısını istediğiniz yüzdeye getirin; 0 sadece birinci rengi, 100 sadece ikinci rengi verir.',
                'sRGB, OKLab ve OKLCH kartlarını yan yana karşılaştırın ve istediğiniz hex kodunu kopyalayın.',
                'Basamak sayısını değiştirin ve rampalardaki herhangi bir kareye tıklayarak o basamağın hex kodunu kopyalayın.',
                'CSS kutusunu alın: değişkenler, eşdeğer color-mix() çağrısı ve bir OKLab gradyanı hazır.',
            ],
            'faq' => [
                ['q' => 'sRGB ile OKLab sonuçları neden bu kadar farklı?', 'a' => 'Hex kodu bir ışık değeri değil, yaklaşık 2.2 gama ile kodlanmış bir sayıdır. İki gama kodlu sayının ortalaması algısal orta noktaya denk gelmez. Somut örnek: siyah ile beyazın sRGB ortası #808080 çıkar, ama gözün tam orta olarak okuduğu gri #636363 rengidir ve OKLab L=%50 tam olarak bunu verir.'],
                ['q' => 'Sonuç tarayıcının color-mix(in oklab, …) çıktısıyla aynı mı?', 'a' => 'Opak renkler için evet. İkisi de OKLab içinde doğrusal ara değerleme yapar ve burada uygulanan matrisler özgün OKLab matrisleridir. Tek olası fark gamut dışına taşan sonuçlardadır: bu araç her kanalı basitçe kırpar, bazı tarayıcılar ise daha dikkatli bir gamut eşleme uygular.'],
                ['q' => 'Yani her zaman OKLCH mi seçmeliyim?', 'a' => 'Hayır. OKLCH tek tonlu rampalarda veya çarkta birbirine yakın renklerde çok iyidir, ama kırmızıdan maviye geçiş macenta tarafından döner ve orta nokta #ba00c2 çıkar; bu renk sRGB dışında kaldığı için kırpılır ve kart sizi uyarır. Birbirinden uzak iki renkte OKLab genelde daha güvenli sonuç verir.'],
                ['q' => 'Alfa kanalı ve yarı saydam renkler destekleniyor mu?', 'a' => 'Hayır. Girdiler yalnızca opak 3 veya 6 haneli hex kabul eder, alfa kanalı yok sayılır. Yarı saydam bir katman karıştırıyorsanız önce arka plan üzerindeki düzleştirilmiş rengi hesaplayın, sonra onu burada karıştırın. Tüm hesaplama kendi tarayıcınızda çalışır, hiçbir veri gönderilmez.'],
            ],
        ],
    ],

    'glassmorphism' => [
        'fa' => [
            'intro' => 'backdrop-filter آنچه را پشت عنصر رسم شده تار می‌کند، نه خود عنصر را؛ به همین دلیل کارتی که پس‌زمینه‌ی کدر دارد هر قدر هم بلور را بالا ببرید شیشه‌ای نمی‌شود و حتماً باید rgba با آلفای پایین بگیرد. نکته‌ی دوم اینکه تار کردن رنگ‌ها را بی‌جان می‌کند؛ برای همین در کدهای حرفه‌ای تقریباً همیشه saturate بین ۱۵۰ تا ۱۸۰ درصد کنار blur می‌آید. این ابزار هر دو را همراه خط دور، سایه، خط نور داخلی و یک بلوک جایگزین اختیاری برای مرورگرهای قدیمی می‌نویسد.',
            'steps' => [
                'یکی از پنج پس‌زمینه‌ی رنگی بالای صفحه را انتخاب کنید تا افکت واقعاً دیده شود؛ گزینه‌ی راه‌راه برای سنجیدن دقیق میزان تاری بهترین است.',
                'تاری، شفافیت لایه، رنگ لایه، اشباع و روشنایی را با اسلایدرها تنظیم کنید؛ کارت نمونه بلافاصله تغییر می‌کند.',
                'ضخامت و رنگ خط دور، گردی گوشه، سایه‌ی بیرونی و خط نور داخلی را تنظیم کنید یا از یکی از پنج پیش‌تنظیم آماده شروع کنید.',
                'اگر پشتیبانی از مرورگرهای قدیمی برایتان مهم است، تیک بلوک جایگزین را بزنید تا شرط پشتیبانی با پس‌زمینه‌ی کدرتر به خروجی اضافه شود.',
                'نام کلاس دلخواه را در فیلد سلکتور بنویسید و CSS نهایی را کپی کنید.',
            ],
            'faq' => [
                ['q' => 'چرا افکت شیشه‌ای من در پروژه هیچ اثری ندارد؟', 'a' => 'سه دلیل رایج دارد: پس‌زمینه‌ی خود عنصر کاملاً کدر است و چیزی از پشت پیدا نیست؛ پشت عنصر فقط یک رنگ تخت است و تار کردن رنگ تخت هیچ تفاوتی نمی‌سازد؛ یا یکی از عنصرهای والد خاصیت filter دارد که ریشه‌ی پس‌زمینه را جابه‌جا می‌کند و blur را خنثی می‌کند.'],
                ['q' => 'پیشوند -webkit- هنوز لازم است؟', 'a' => 'برای سافاری بله. کروم و اج از نسخه‌ی ۷۶ و فایرفاکس از نسخه‌ی ۱۰۳ شکل بدون پیشوند را می‌فهمند، ولی سافاری سال‌ها فقط نسخه‌ی webkit را می‌شناخت. نگه داشتن هر دو خط هزینه‌ای ندارد و ترتیب درست این است که اول خط پیشوند‌دار بیاید و بعد خط استاندارد.'],
                ['q' => 'این افکت روی کارایی سایت اثر می‌گذارد؟', 'a' => 'بله، backdrop-filter یکی از گران‌ترین خاصیت‌های CSS است چون مرورگر باید ناحیه‌ی پشت عنصر را جداگانه رندر و تار کند. روی یک هدر یا چند کارت مشکلی پیش نمی‌آید، ولی گذاشتن آن روی ده‌ها آیتم یک لیست یا روی عنصری که مدام اسکرول می‌شود، مخصوصاً در موبایل، افت محسوس فریم می‌دهد.'],
                ['q' => 'خروجی این ابزار خوانایی متن را تضمین می‌کند؟', 'a' => 'خیر و این محدودیت واقعی ابزار است: اینجا فقط ظاهر کارت ساخته می‌شود و کنتراست متن اصلاً سنجیده نمی‌شود. چون زمینه‌ی پشت کارت در صفحه‌ی واقعی شما متغیر است، متن ممکن است روی نواحی روشن به حد استاندارد WCAG نرسد؛ در این حالت شفافیت لایه را بالاتر ببرید یا به متن سایه بدهید.'],
            ],
        ],
        'en' => [
            'intro' => 'backdrop-filter blurs whatever is painted behind an element, never the element itself, so a card with an opaque background will never look like glass no matter how far you push the blur — it needs an rgba fill with a low alpha. Blurring also drains colour, which is why production glass CSS nearly always pairs blur() with saturate(150%-180%). This generator writes both, plus the border, drop shadow, inner highlight and an optional fallback block for browsers without support.',
            'steps' => [
                'Pick one of the five colourful backdrops at the top so the effect is actually visible; the stripes backdrop makes the exact blur radius easy to judge.',
                'Set blur, layer opacity, tint colour, saturation and brightness with the sliders; the sample card updates instantly.',
                'Tune the border width and colour, corner radius, drop shadow and inner highlight, or start from one of the five presets.',
                'If you need older browsers to degrade gracefully, tick the fallback option to append a feature-query block with a more opaque background.',
                'Type your own class name in the selector field and copy the finished CSS.',
            ],
            'faq' => [
                ['q' => 'Why does my glass effect do nothing?', 'a' => "Three usual causes: the element's own background is fully opaque so nothing shows through; there is only a flat colour behind it, and blurring a flat colour changes nothing; or an ancestor element has a filter, which creates a new backdrop root and cancels the blur entirely."],
                ['q' => 'Is the -webkit- prefix still needed?', 'a' => 'For Safari, yes. Chrome and Edge accept the unprefixed property from version 76 and Firefox from version 103, but Safari shipped only the webkit form for years. Keeping both lines costs nothing; put the prefixed declaration first and the standard one second.'],
                ['q' => 'Does backdrop-filter hurt performance?', 'a' => 'Yes, it is one of the more expensive CSS properties because the browser has to render and blur the region behind the element separately. One header or a handful of cards is fine, but applying it to dozens of list items or to something that scrolls constantly will drop frames, especially on mobile.'],
                ['q' => 'Does the generated CSS guarantee readable text?', 'a' => "No, and that is a real limitation of this tool: it only produces the card's appearance and never measures text contrast. Because the backdrop behind the card varies on a real page, text can fall below the WCAG contrast threshold over bright areas, so raise the layer opacity or add a text shadow."],
            ],
        ],
        'tr' => [
            'intro' => 'backdrop-filter öğenin arkasına çizilen şeyi bulanıklaştırır, öğenin kendisini değil; bu yüzden arka planı tamamen opak olan bir kart, bulanıklığı ne kadar artırırsanız artırın cam gibi görünmez, düşük alfalı bir rgba dolgusu şarttır. Bulanıklaştırma renkleri de soldurur; üretimde kullanılan cam CSS kodu neredeyse her zaman blur ile birlikte saturate(150%-180%) içerir. Bu üreteç ikisini de yazar, ayrıca kenarlık, dış gölge, iç parlama ve destek vermeyen tarayıcılar için isteğe bağlı bir yedek blok üretir.',
            'steps' => [
                'Efektin gerçekten görünmesi için üstteki beş renkli arka plandan birini seçin; çizgili arka plan bulanıklık yarıçapını ölçmek için en uygunudur.',
                'Bulanıklık, katman opaklığı, renk tonu, doygunluk ve parlaklığı kaydırıcılarla ayarlayın; örnek kart anında güncellenir.',
                'Kenarlık kalınlığı ve rengini, köşe yarıçapını, dış gölgeyi ve iç parlamayı ayarlayın veya beş hazır ayardan biriyle başlayın.',
                'Eski tarayıcıların düzgün davranması gerekiyorsa yedek seçeneğini işaretleyin; çıktıya daha opak bir arka planla bir özellik sorgusu bloku eklenir.',
                'Seçici alanına kendi sınıf adınızı yazın ve hazır CSS kodunu kopyalayın.',
            ],
            'faq' => [
                ['q' => 'Cam efektim neden hiçbir şey yapmıyor?', 'a' => 'Üç yaygın neden var: öğenin kendi arka planı tamamen opaktır ve arkadaki hiçbir şey görünmez; arkada yalnızca düz bir renk vardır ve düz rengi bulanıklaştırmak hiçbir fark yaratmaz; ya da üst öğelerden birinde filter vardır, bu yeni bir backdrop kökü oluşturur ve bulanıklığı tamamen iptal eder.'],
                ['q' => '-webkit- öneki hâlâ gerekli mi?', 'a' => 'Safari için evet. Chrome ve Edge sürüm 76 ile, Firefox sürüm 103 ile öneksiz özelliği kabul eder, ancak Safari yıllarca yalnızca webkit biçimini destekledi. İki satırı da tutmanın maliyeti yoktur; önce önekli bildirim, sonra standart bildirim gelmelidir.'],
                ['q' => 'backdrop-filter performansı düşürür mü?', 'a' => 'Evet, en pahalı CSS özelliklerinden biridir çünkü tarayıcı öğenin arkasındaki bölgeyi ayrı işleyip bulanıklaştırmak zorundadır. Tek bir başlık veya birkaç kart sorun değildir, ancak onlarca liste öğesine veya sürekli kaydırılan bir yapıya uygulamak özellikle mobilde kare kaybına yol açar.'],
                ['q' => 'Üretilen CSS metnin okunabilirliğini garanti eder mi?', 'a' => 'Hayır ve bu, aracın gerçek bir sınırıdır: yalnızca kartın görünümünü üretir, metin kontrastını hiç ölçmez. Gerçek bir sayfada kartın arkasındaki zemin değiştiği için metin, açık bölgelerde WCAG kontrast eşiğinin altına düşebilir; katman opaklığını artırın veya metne gölge ekleyin.'],
            ],
        ],
    ],

    'html-minifier' => [
        'fa' => [
            'intro' => 'مرورگر هر دنبالهٔ فاصله را داخل متن به یک فاصله تبدیل می‌کند، اما همان یک فاصله بین دو تگ inline مثل دو span پشت سر هم، محتوای واقعی است و اگر حذفش کنید دو کلمه به هم می‌چسبند. این ابزار دقیقاً همین مرز را رعایت می‌کند: فاصله را فقط کنار عناصر بلوکی حذف می‌کند و محتوای pre، textarea، script و style را بایت‌به‌بایت عبور می‌دهد. تمام پردازش داخل مرورگر شما انجام می‌شود و کد به هیچ سروری ارسال نمی‌شود.',
            'steps' => [
                'کد HTML را در کادر ورودی بچسبانید یا دکمهٔ نمونه را بزنید.',
                'تیک حذف کامنت‌ها، فشرده‌سازی فاصله‌ها و حذف ویژگی‌های زائد را مطابق نیاز پروژه تنظیم کنید.',
                'برای خروجی کوتاه‌تر، گزینهٔ حذف کوتیشن‌های غیرضروری را هم فعال کنید.',
                'حجم اولیه، حجم فشرده و درصد کاهش را در کارت‌های آمار بررسی کنید.',
                'خروجی را با دکمهٔ کپی بردارید و در پروژه جایگزین کنید.',
            ],
            'faq' => [
                ['q' => 'آیا این ابزار ممکن است ظاهر صفحه را خراب کند؟', 'a' => 'فاصله فقط جایی حذف می‌شود که مرورگر آن را نمایش نمی‌دهد؛ یعنی کنار تگ‌های بلوکی مثل div، p، li و td. بین تگ‌های inline مثل span، a و b همیشه یک فاصله باقی می‌ماند و تگ‌های ناشناخته یا سفارشی مثل my-widget هم عمداً inline در نظر گرفته می‌شوند تا فاصله‌شان از بین نرود.'],
                ['q' => 'آیا کد داخل script و style هم فشرده می‌شود؟', 'a' => 'نه، و این عمدی است. محتوای pre، textarea، script و style بایت‌به‌بایت کپی می‌شود. فشرده‌سازی جاوااسکریپت و CSS به پارسر جداگانهٔ خودش نیاز دارد و مخلوط‌کردن آن با مینیفای HTML یکی از رایج‌ترین منابع باگ است. تعداد بلوک‌های محافظت‌شده کنار نوار وضعیت نمایش داده می‌شود.'],
                ['q' => 'چرا درصد کاهش من پایین است؟', 'a' => 'مینیفای HTML روی متن خام معمولاً ۵ تا ۲۵ درصد کم می‌کند و بیشترین سود را در فایل‌های پر از تورفتگی می‌دهد. اگر سرور شما gzip یا brotli فعال دارد، همان فاصله‌های تکراری از قبل خوب فشرده می‌شدند؛ پس مینیفای را مکمل فشرده‌سازی سرور بدانید نه جایگزین آن.'],
                ['q' => 'محدودیت شناخته‌شدهٔ این ابزار چیست؟', 'a' => 'با فعال بودن حذف ویژگی‌های زائد، type=text از input برداشته می‌شود چون مقدار پیش‌فرض HTML است؛ اگر در CSS یا جاوااسکریپت خود سلکتور input[type=text] دارید این تیک را بردارید. ضمناً ورودی تا ۲ مگابایت پردازش می‌شود و محتوای داخل pre هرگز فشرده نمی‌شود.'],
            ],
        ],
        'en' => [
            'intro' => 'A browser already collapses any run of whitespace inside text down to a single space — but the one space between two adjacent inline tags is real content, and deleting it glues two words together. This minifier respects that boundary: it only drops whitespace beside block-level elements, and it passes the contents of pre, textarea, script and style through byte-for-byte. Everything runs in your browser and the markup is never uploaded.',
            'steps' => [
                'Paste your HTML into the input box, or press Sample to load a test snippet.',
                'Toggle remove comments, collapse whitespace and strip redundant attributes to suit the project.',
                'Enable unquote safe attribute values when you want a shorter output.',
                'Read the original size, minified size and reduction percentage in the stat cards.',
                'Copy the result and drop it into your build.',
            ],
            'faq' => [
                ['q' => 'Can this break my page layout?', 'a' => 'Whitespace is only removed where the browser would not render it — next to block-level tags such as div, p, li and td. One space always survives between inline tags such as span, a and b, and unknown or custom elements like my-widget are deliberately treated as inline so their spacing is never lost.'],
                ['q' => 'Does it minify the JavaScript and CSS inside script and style tags?', 'a' => 'No, deliberately. The contents of pre, textarea, script and style are copied byte-for-byte. Minifying JS or CSS needs its own parser, and folding that into an HTML minifier is a classic source of broken builds. The status line reports how many protected blocks were kept.'],
                ['q' => 'Why did my file only shrink a few percent?', 'a' => 'HTML minification typically saves 5-25% of raw bytes, and most of that comes from indentation. If your server already sends gzip or brotli, those repeated spaces were compressing well anyway — treat minification as a complement to transport compression, not a replacement.'],
                ['q' => 'What are the known limitations?', 'a' => 'With strip redundant attributes on, type=text is removed from input elements because it is the HTML default — turn that toggle off if your CSS or JS relies on an input[type=text] selector. Input is capped at 2 MB, and anything inside pre is never compacted.'],
            ],
        ],
        'tr' => [
            'intro' => 'Tarayici metin icindeki ardisik bosluklari zaten tek bosluga indirir, ancak yan yana duran iki satir ici etiketin arasindaki o tek bosluk gercek bir icerik sayilir ve silinirse iki kelime birbirine yapisir. Bu arac tam olarak o siniri gozetir: boslugu yalnizca blok seviyesindeki etiketlerin yaninda siler, pre, textarea, script ve style iceriklerini bayt bayt aynen gecirir. Tum islem tarayicinizda yapilir, kod hicbir sunucuya gonderilmez.',
            'steps' => [
                'HTML kodunuzu giris kutusuna yapistirin veya Ornek dugmesine basin.',
                'Yorumlari kaldir, bosluklari daralt ve gereksiz ozellikleri sil seceneklerini projenize gore ayarlayin.',
                'Daha kisa bir cikti icin guvenli tirnaklari kaldir secenegini de isaretleyin.',
                'Ozgun boyut, kucultulmus boyut ve azalma yuzdesini istatistik kartlarindan okuyun.',
                'Sonucu kopyala dugmesiyle alip projenize yerlestirin.',
            ],
            'faq' => [
                ['q' => 'Bu arac sayfamin gorunumunu bozabilir mi?', 'a' => 'Bosluk yalnizca tarayicinin zaten gostermedigi yerlerden silinir; yani div, p, li ve td gibi blok etiketlerin yaninda. span, a ve b gibi satir ici etiketler arasinda her zaman tek bosluk kalir. Bilinmeyen veya ozel etiketler de bilerek satir ici kabul edilir, boylece bosluklari kaybolmaz.'],
                ['q' => 'script ve style icindeki JavaScript ve CSS de kucultuluyor mu?', 'a' => 'Hayir, bilerek. pre, textarea, script ve style icerikleri bayt bayt kopyalanir. JavaScript veya CSS kucultmek ayri bir cozumleyici ister ve bunu HTML kucultmeyle karistirmak klasik bir hata kaynagidir. Durum satiri kac korumali blok birakildigini bildirir.'],
                ['q' => 'Dosyam neden sadece birkac yuzde kuculdu?', 'a' => 'HTML kucultme genelde ham baytlarin yuzde 5 ile 25 arasini kazandirir ve bunun buyuk kismi girintilerden gelir. Sunucunuz zaten gzip veya brotli gonderiyorsa o tekrarli bosluklar hali hazirda iyi sikisiyordu; kucultmeyi tasima sikistirmasinin yerine degil tamamlayicisi olarak dusunun.'],
                ['q' => 'Bilinen sinirlari neler?', 'a' => 'Gereksiz ozellikleri sil acikken input etiketinden type=text kaldirilir cunku HTML varsayilanidir; CSS veya JavaScript tarafinda input[type=text] secicisi kullaniyorsaniz bu kutuyu isaretsiz birakin. Girdi 2 MB ile sinirlidir ve pre icindeki hicbir sey sikistirilmaz.'],
            ],
        ],
    ],

    'image-color-picker' => [
        'fa' => [
            'intro' => 'مرورگر تصویر شما را داخل یک canvas می‌کشد و مقدار هر پیکسل مستقیماً از حافظهٔ همان canvas خوانده می‌شود؛ بنابراین فایل هرگز به سرور فرستاده نمی‌شود. نکته‌ای که اغلب نادیده گرفته می‌شود این است که فشرده‌سازی JPEG رنگ‌های همسایه را کمی جابه‌جا می‌کند، پس رنگی که از یک اسکرین‌شات JPEG برمی‌دارید می‌تواند چند واحد با رنگ اصلی فایل طراحی فرق داشته باشد. برای کد رنگ دقیق، همیشه از نسخهٔ PNG یا خروجی بدون افت کیفیت استفاده کنید.',
            'steps' => [
                'تصویر را روی کادر بکشید و رها کنید یا دکمهٔ «انتخاب تصویر» را بزنید؛ برای آزمایش سریع، دکمهٔ «تصویر نمونه» چهار نوار رنگی می‌سازد.',
                'تیک «نمایش ذره‌بین» را نگه دارید تا با حرکت نشانگر، ناحیه‌ای ۱۶ در ۱۶ پیکسل با بزرگ‌نمایی هشت برابر و کد HEX زیر آن نشان داده شود.',
                'روی نقطهٔ موردنظر کلیک کنید؛ نوار وضعیت، کد HEX و مختصات همان پیکسل را گزارش می‌دهد.',
                'در جدول زیر تصویر، مقدار HEX، RGB و HSL را با دکمهٔ کپی روبه‌روی هر ردیف بردارید.',
                'رنگ‌های انتخاب‌شده در تاریخچه می‌مانند؛ کلیک روی هر مربع کد آن را کپی می‌کند و دکمهٔ «پاک‌کردن» فهرست را خالی می‌کند.',
            ],
            'faq' => [
                ['q' => 'آیا رنگ برداشته‌شده میانگین یک ناحیه است؟', 'a' => 'خیر. دقیقاً مقدار همان یک پیکسلی است که زیر نشانگر قرار داشته. روی لبه‌های نرم‌شده (anti-alias)، متن‌ها و گرادیان‌ها، پیکسل‌های کنار هم رنگ‌های متفاوتی دارند؛ برای رسیدن به رنگ واقعی، از وسط یک ناحیهٔ یکدست انتخاب کنید و از ذره‌بین کمک بگیرید.'],
                ['q' => 'چرا روی بخش شفاف تصویر ‎#000000‎ می‌گیرم؟', 'a' => 'این ابزار فقط سه کانال قرمز، سبز و آبی را گزارش می‌کند و کانال آلفا را کنار می‌گذارد. پیکسل کاملاً شفاف در PNG معمولاً هر سه کانال را صفر دارد، پس سیاه دیده می‌شود. اگر رنگ نهایی روی پس‌زمینه را می‌خواهید، تصویر را روی پس‌زمینهٔ دلخواه فلت کنید و بعد بارگذاری کنید.'],
                ['q' => 'با تصاویر خیلی بزرگ چه می‌کند؟', 'a' => 'تصاویری که هر ضلعشان از ۴۰۹۶ پیکسل بیشتر باشد پیش از رسم کوچک می‌شوند. رنگ نواحی یکدست تغییر نمی‌کند، اما مختصاتی که در نوار وضعیت می‌بینید مربوط به همان نسخهٔ کوچک‌شده است، نه فایل اصلی.'],
                ['q' => 'تاریخچهٔ رنگ‌ها تا کی نگه داشته می‌شود؟', 'a' => 'فقط تا زمانی که صفحه باز است. حداکثر ۳۲ رنگ آخر ذخیره می‌شود، رنگ تکراری به ابتدای فهرست منتقل می‌شود و با تازه‌سازی صفحه یا زدن «پاک‌کردن» همه‌چیز از بین می‌رود؛ چیزی روی سرور یا در حساب شما ذخیره نمی‌شود.'],
            ],
        ],
        'en' => [
            'intro' => 'The browser draws your image into a canvas and reads each pixel straight out of that canvas memory, so the file never leaves your machine. What most people forget is that JPEG compression shifts neighbouring colours slightly, so a swatch picked from a JPEG screenshot can be a few units off the value in the original design file. For exact codes, always pick from a PNG or another lossless export.',
            'steps' => [
                'Drag an image onto the drop area or press Choose image; the Sample image button paints four colour bands if you just want to try it.',
                'Leave Show magnifier ticked so hovering reveals a 16x16 pixel region at eight times zoom with its HEX code underneath.',
                'Click the spot you want. The status line reports the HEX code together with the exact pixel coordinates.',
                'Copy the HEX, RGB or HSL value from the readout table using the copy button on each row.',
                'Every pick is kept in the history strip; click a swatch to copy its code, or press Clear to empty the list.',
            ],
            'faq' => [
                ['q' => 'Is the picked colour an average of the surrounding area?', 'a' => 'No. It is the value of the single pixel under the cursor. On anti-aliased edges, text and gradients, adjacent pixels carry blended colours, so pick from the middle of a flat region and use the magnifier to confirm what is under the crosshair.'],
                ['q' => 'Why do transparent areas give me #000000?', 'a' => 'The tool reports only the red, green and blue channels and ignores alpha. A fully transparent PNG pixel usually stores zero in all three channels, so it reads as black. If you need the composited colour, flatten the image onto your intended background first, then load it.'],
                ['q' => 'What happens with very large images?', 'a' => 'Anything larger than 4096 pixels on a side is scaled down before drawing. Colours in flat areas are unaffected, but the coordinates shown in the status line refer to that resized copy, not to the original file.'],
                ['q' => 'How long is the colour history kept?', 'a' => 'Only while the page is open. The last 32 colours are held in memory, a repeated colour moves back to the front, and refreshing the page or pressing Clear wipes it. Nothing is stored on a server or in your account.'],
            ],
        ],
        'tr' => [
            'intro' => 'Tarayıcı görselinizi bir canvas üzerine çizer ve her pikselin değerini doğrudan o canvas belleğinden okur; dosya hiçbir zaman bilgisayarınızdan çıkmaz. Çoğu kişinin atladığı nokta şudur: JPEG sıkıştırması komşu renkleri hafifçe kaydırır, bu yüzden bir JPEG ekran görüntüsünden alınan renk, orijinal tasarım dosyasındaki değerden birkaç birim sapabilir. Kesin kod için her zaman PNG veya kayıpsız bir dışa aktarım kullanın.',
            'steps' => [
                'Görseli bırakma alanına sürükleyin ya da Görsel seç düğmesine basın; sadece denemek isterseniz Örnek görsel düğmesi dört renk bandı çizer.',
                'Büyüteci göster kutusunu işaretli bırakın; imleç gezdikçe 16x16 piksellik bir bölge sekiz kat büyütmeyle ve altında HEX koduyla görünür.',
                'İstediğiniz noktaya tıklayın. Durum satırı HEX kodunu ve tam piksel koordinatlarını bildirir.',
                'Alt taraftaki tabloda HEX, RGB ve HSL değerlerini her satırın yanındaki kopyala düğmesiyle alın.',
                'Her seçim geçmiş şeridinde tutulur; kodunu kopyalamak için bir kareye tıklayın, listeyi boşaltmak için Temizle düğmesine basın.',
            ],
            'faq' => [
                ['q' => 'Alınan renk çevredeki bölgenin ortalaması mıdır?', 'a' => 'Hayır. İmlecin altındaki tek pikselin değeridir. Yumuşatılmış (anti-alias) kenarlarda, metinlerde ve gradyanlarda komşu pikseller karışık renkler taşır; gerçek rengi almak için düz bir alanın ortasından seçin ve büyüteçle kontrol edin.'],
                ['q' => 'Saydam alanlarda neden #000000 çıkıyor?', 'a' => 'Bu araç yalnızca kırmızı, yeşil ve mavi kanalları bildirir, alfa kanalını yok sayar. Tamamen saydam bir PNG pikseli genellikle üç kanalda da sıfır tutar, bu yüzden siyah okunur. Birleşmiş rengi istiyorsanız görseli önce istediğiniz arka planla düzleştirin, sonra yükleyin.'],
                ['q' => 'Çok büyük görsellerde ne olur?', 'a' => 'Bir kenarı 4096 pikseli aşan görseller çizilmeden önce küçültülür. Düz alanlardaki renkler değişmez, ancak durum satırında gördüğünüz koordinatlar orijinal dosyaya değil, küçültülmüş kopyaya aittir.'],
                ['q' => 'Renk geçmişi ne kadar süre saklanır?', 'a' => 'Yalnızca sayfa açık kaldığı sürece. Son 32 renk bellekte tutulur, tekrarlanan bir renk listenin başına taşınır, sayfayı yenilemek veya Temizle düğmesine basmak her şeyi siler. Sunucuda ya da hesabınızda hiçbir şey saklanmaz.'],
            ],
        ],
    ],

    'photo-censor' => [
        'fa' => [
            'intro' => 'پوشاندن با پیکسل فقط تا جایی امن است که اندازه بلوک بزرگ باشد؛ روی متن ریز با قلم شناخته‌شده، بلوک‌های ۴ یا ۶ پیکسلی را می‌شود با آزمون‌وخطا حدس زد. اشتباه رایج‌تر این است که کاربر یک مستطیل سیاه را در نرم‌افزار ویرایش «روی» عکس می‌گذارد و لایه زیرش دست‌نخورده باقی می‌ماند. این ابزار ناحیه‌ها را مستقیم روی پیکسل‌های خروجی می‌پزد و همه‌ی کار داخل مرورگر و بدون ارسال فایل به سرور انجام می‌شود.',
            'steps' => [
                'تصویر را روی کادر رها کنید یا با دکمه «انتخاب تصویر» بازش کنید؛ فایل فقط در حافظه‌ی مرورگر خوانده می‌شود.',
                'حالت پوشاندن را از میان پیکسلی، محو یا سیاه انتخاب کنید و اگر لازم بود لغزنده‌ی اندازه بلوک (۴ تا ۸۰) یا شعاع محو (۲ تا ۴۰) را تنظیم کنید؛ حالت سیاه لغزنده ندارد.',
                'روی تصویر کلیک کنید و بکشید تا کادر ساخته شود. هر کادر با همان تنظیمات لحظه‌ی کشیدن ثبت می‌شود؛ می‌توانید تا ۴۰ ناحیه بسازید.',
                'برای اصلاح، ضربدر گوشه‌ی هر ناحیه را بزنید یا از «واگرد» و کلید Ctrl+Z استفاده کنید؛ «پاک‌کردن همه» تصویر را به حالت اول برمی‌گرداند.',
                'قالب خروجی را PNG یا JPEG کنید و «دانلود تصویر» را بزنید تا فایل با پیکسل‌های سانسورشده ذخیره شود.',
            ],
            'faq' => [
                ['q' => 'عکس من روی سرور شما آپلود می‌شود؟', 'a' => 'خیر. فایل با FileReader داخل مرورگر خوانده و روی canvas پردازش می‌شود و خروجی هم از همان‌جا دانلود می‌شود. هیچ درخواستی با محتوای تصویر به سرور فرستاده نمی‌شود، بنابراین ابزار آفلاین هم کار می‌کند.'],
                ['q' => 'می‌شود پیکسلی‌شدن را برگرداند؟', 'a' => 'از روی فایل خروجی نه، چون اطلاعات اصلی حذف شده و تصویر اولیه جایی داخل فایل نگهداری نمی‌شود. اما «برگشت‌ناپذیر» به معنای «غیرقابل حدس» نیست: اگر بلوک‌ها کوچک باشند و محتوا قابل پیش‌بینی باشد (مثل شماره کارت یا پلاک)، می‌توان با ساخت نمونه و مقایسه به آن رسید. برای داده‌های حساس بلوک بزرگ یا حالت سیاه را انتخاب کنید.'],
                ['q' => 'چرا نمی‌توانم ناحیه‌ای را جابه‌جا یا تغییر اندازه بدهم؟', 'a' => 'ناحیه‌ها ویرایش‌پذیر نیستند؛ فقط می‌شود حذفشان کرد یا با واگرد به عقب برگشت و دوباره کشید. سقف تعداد ناحیه ۴۰ است و تصاویر بزرگ‌تر از حدود ۲۴ مگاپیکسل پیش از پردازش کوچک می‌شوند تا حافظه‌ی مرورگر پر نشود؛ در این حالت ابعاد جدید زیر تصویر نشان داده می‌شود.'],
                ['q' => 'PNG بهتر است یا JPEG؟', 'a' => 'PNG بدون افت کیفیت است و شفافیت را نگه می‌دارد. JPEG حجم کمتری دارد ولی پس‌زمینه‌ی شفاف را سفید می‌کند و با کیفیت ۹۲٪ فشرده می‌شود. در هر دو حالت چون تصویر از canvas دوباره ساخته می‌شود، متادیتای اصلی مثل EXIF و موقعیت GPS در خروجی باقی نمی‌ماند.'],
            ],
        ],
        'en' => [
            'intro' => 'A pixelated region is only as safe as its block size: on small text in a known font, 4 or 6 pixel blocks can be brute-forced back into readable characters. The more common mistake is drawing a black rectangle as a layer in an editor and shipping a file where the original pixels still sit underneath. This tool bakes each region straight into the exported pixels, and does the whole job inside your browser with no upload.',
            'steps' => [
                'Drop an image onto the panel or open it with the choose button; the file is read only into browser memory.',
                'Pick a censor mode - pixelate, blur or solid black - and adjust the block size slider (4 to 80) or blur radius slider (2 to 40). Solid mode has no slider.',
                'Click and drag on the image to draw a region. Each region locks in the mode and strength active at that moment, and you can add up to 40 of them.',
                'Fix mistakes with the × on a region, the Undo button, or Ctrl+Z; Clear all returns the image to its untouched state.',
                'Choose PNG or JPEG as the output format and press download to save the file with the censoring already baked into the pixels.',
            ],
            'faq' => [
                ['q' => 'Is my photo uploaded to your server?', 'a' => 'No. The file is read with FileReader, processed on a canvas element, and the result is downloaded from the same page. No request carrying the image is ever sent, so the tool keeps working with the network disconnected.'],
                ['q' => 'Can the pixelation be undone?', 'a' => 'Not from the exported file - the original data is discarded and no copy is embedded. But irreversible is not the same as unguessable: with small blocks and predictable content such as a card number or a licence plate, an attacker can render candidates and match them against the blocks. For sensitive data use a large block size or solid black.'],
                ['q' => 'Why can I not move or resize a region after drawing it?', 'a' => 'Regions are not editable - you can only delete them or undo and redraw. There is also a hard limit of 40 regions, and images larger than about 24 megapixels are scaled down before processing so the browser does not run out of memory; the new dimensions are shown below the image when that happens.'],
                ['q' => 'Should I export PNG or JPEG?', 'a' => 'PNG is lossless and keeps transparency. JPEG is smaller but flattens any transparent background to white and re-encodes at 92% quality. Either way the image is rebuilt from a canvas, so original metadata such as EXIF and GPS coordinates does not survive into the exported file.'],
            ],
        ],
        'tr' => [
            'intro' => 'Piksellenmiş bir alan, ancak blok boyutu kadar güvenlidir: bilinen bir yazı tipiyle yazılmış küçük metinde 4 veya 6 piksellik bloklar deneme yanılmayla okunabilir hale getirilebilir. Daha yaygın hata ise, düzenleyicide siyah bir dikdörtgeni katman olarak üste koyup orijinal piksellerin altta durduğu bir dosya paylaşmaktır. Bu araç her bölgeyi doğrudan dışa aktarılan piksellere işler ve tüm işi hiçbir yükleme yapmadan tarayıcınızda yürütür.',
            'steps' => [
                'Görüntüyü panele bırakın veya seçme düğmesiyle açın; dosya yalnızca tarayıcı belleğine okunur.',
                'Sansür modunu seçin - pikselle, bulanıklaştır veya düz siyah - ve gerekirse blok boyutu kaydırıcısını (4 ile 80) ya da bulanıklık yarıçapı kaydırıcısını (2 ile 40) ayarlayın. Siyah modda kaydırıcı bulunmaz.',
                'Bölge çizmek için görüntü üzerinde tıklayıp sürükleyin. Her bölge o andaki mod ve şiddet değerini sabitler; en fazla 40 bölge ekleyebilirsiniz.',
                'Hataları bölgenin köşesindeki × işaretiyle, Geri al düğmesiyle veya Ctrl+Z ile düzeltin; Tümünü temizle görüntüyü ilk haline döndürür.',
                'Çıktı biçimi olarak PNG veya JPEG seçin ve indir düğmesine basarak sansürü piksellere işlenmiş dosyayı kaydedin.',
            ],
            'faq' => [
                ['q' => 'Fotoğrafım sunucunuza yükleniyor mu?', 'a' => 'Hayır. Dosya FileReader ile okunur, canvas üzerinde işlenir ve sonuç aynı sayfadan indirilir. Görüntüyü taşıyan hiçbir istek gönderilmez, bu yüzden araç ağ bağlantısı olmadan da çalışır.'],
                ['q' => 'Piksellenmiş alan geri alınabilir mi?', 'a' => 'Dışa aktarılan dosyadan alınamaz; orijinal veri atılır ve dosyanın içine kopyası gömülmez. Ancak geri alınamaz olması tahmin edilemez olması demek değildir: küçük bloklar ve kart numarası ya da plaka gibi öngörülebilir içerikte saldırgan adayları üretip bloklarla eşleştirebilir. Hassas veriler için büyük blok boyutu veya düz siyahı tercih edin.'],
                ['q' => 'Çizdikten sonra bir bölgeyi neden taşıyamıyor veya yeniden boyutlandıramıyorum?', 'a' => 'Bölgeler düzenlenebilir değildir; yalnızca silinebilir ya da geri alınıp yeniden çizilebilir. Ayrıca 40 bölgelik kesin bir sınır vardır ve yaklaşık 24 megapiksel üzerindeki görüntüler bellek dolmasın diye işlemden önce küçültülür; bu durumda yeni boyutlar görüntünün altında gösterilir.'],
                ['q' => 'PNG mi yoksa JPEG mi seçmeliyim?', 'a' => 'PNG kayıpsızdır ve saydamlığı korur. JPEG daha küçüktür ama saydam arka planı beyaza çevirir ve yüzde 92 kalitede yeniden kodlar. Her iki durumda da görüntü canvas üzerinden yeniden oluşturulduğu için EXIF ve GPS konumu gibi orijinal meta veriler çıktı dosyasına taşınmaz.'],
            ],
        ],
    ],

    'whitespace-remover' => [
        'fa' => [
            'intro' => 'بیشتر ابزارهای «حذف فاصله‌ی اضافه» نیم‌فاصله (U+200C) را هم یک نوع فاصله می‌بینند و پاکش می‌کنند؛ نتیجه این است که «می‌شود» به «میشود» و «کتاب‌ها» به «کتابها» تبدیل می‌شود. این ابزار نیم‌فاصله و اتصال‌دهنده‌ی صفرعرض (U+200D) را به‌صورت پیش‌فرض دست‌نخورده نگه می‌دارد و حذفشان فقط با یک گزینه‌ی جداگانه و هشداردار ممکن است. تمام پردازش هم داخل مرورگر خودتان انجام می‌شود و متن جایی ارسال نمی‌شود.',
            'steps' => [
                'متن را در کادر ورودی بچسبانید؛ خروجی هم‌زمان با تایپ ساخته می‌شود و نیازی به زدن دکمه نیست.',
                'گزینه‌های پاک‌سازی را انتخاب کنید: ادغام فاصله‌های پشت‌سرهم، پیرایش ابتدا و انتهای هر خط، پیرایش کل متن، تبدیل تب به فاصله با عرض ۱، ۲، ۴ یا ۸، و یکسان‌سازی NBSP و فاصله‌های یونیکدی.',
                'در صورت نیاز حذف خطوط خالی، تبدیل کل متن به یک خط، یا حذف نویسه‌های نامرئی مثل ZWSP و BOM و نشانگرهای جهت را فعال کنید.',
                'تیک «نمایش نویسه‌های نامرئی» را بزنید تا فاصله، تب، شکست خط، NBSP، نیم‌فاصله و نویسه‌های نامرئی با نماد رنگی روی خروجی دیده شوند.',
                'آمار پایین صفحه شامل حجم پیش و پس، نویسه‌های حذف‌شده، شمار خط‌ها و تعداد نیم‌فاصله‌های باقی‌مانده را بررسی کنید و بعد خروجی را کپی کنید.',
            ],
            'faq' => [
                ['q' => 'آیا این ابزار نیم‌فاصله‌ی فارسی را حذف می‌کند؟', 'a' => 'نه، به‌صورت پیش‌فرض نه. نیم‌فاصله (U+200C) و U+200D در هیچ‌کدام از قاعده‌های فاصله‌ی این ابزار به‌عنوان «فاصله» شناخته نمی‌شوند و دست‌نخورده باقی می‌مانند. فقط اگر تیک جداگانه‌ی «حذف نیم‌فاصله (پرخطر)» را خودتان بزنید حذف می‌شوند و در همان لحظه هم یک هشدار زرد بالای دکمه‌ها ظاهر می‌شود.'],
                ['q' => 'تفاوت «حذف نویسه‌های نامرئی» با گزینه‌ی نیم‌فاصله چیست؟', 'a' => 'گزینه‌ی نویسه‌های نامرئی فقط U+200B، U+2060، U+FEFF، U+00AD و نشانگرهای جهت U+200E، U+200F و U+061C را برمی‌دارد و هرگز به U+200C و U+200D دست نمی‌زند. این دو گزینه کاملاً مستقل‌اند؛ برای متن فارسی می‌توانید اولی را با خیال راحت روشن کنید.'],
                ['q' => 'چرا خطی که فقط نیم‌فاصله دارد با «حذف خطوط خالی» پاک نمی‌شود؟', 'a' => 'این رفتار عمدی است. خط خالی یعنی خطی که فقط فاصله، تب یا فاصله‌های یونیکدی دارد؛ نیم‌فاصله محتوای متنی حساب می‌شود و آن خط دست‌نخورده می‌ماند. اگر واقعاً می‌خواهید چنین خطی حذف شود، اول باید گزینه‌ی پرخطر حذف نیم‌فاصله را فعال کنید.'],
                ['q' => 'محدودیت‌های ابزار چیست؟', 'a' => 'پردازش تا ۵۰۰٬۰۰۰ نویسه انجام می‌شود و متن بلندتر برش می‌خورد و پیام اخطار نشان داده می‌شود. پیش‌نمایش نویسه‌های نامرئی هم تا ۲۰٬۰۰۰ نویسه رندر می‌شود. ضمناً پایان‌خط‌ها همیشه به LF یکدست می‌شوند، پس اگر فایل شما حتماً باید CRLF ویندوزی داشته باشد، بعد از کپی آن را در ویرایشگر خودتان تنظیم کنید.'],
            ],
        ],
        'en' => [
            'intro' => 'Most "remove extra spaces" tools treat the Persian zero-width non-joiner (U+200C) as whitespace and strip it, which silently turns «می‌شود» into «میشود» and «کتاب‌ها» into «کتابها». This cleaner excludes U+200C and U+200D from every whitespace rule it applies, and only removes them if you tick a separate, clearly flagged option. Everything runs in your browser; the text is never uploaded.',
            'steps' => [
                'Paste your text into the input box. The cleaned result is rebuilt as you type, with no button to press.',
                'Pick the cleanup options: collapse repeated spaces, trim each line, trim the whole text, convert tabs to spaces at width 1, 2, 4 or 8, and normalize NBSP and Unicode spaces.',
                'Optionally turn on removing blank lines, joining everything into one line, or stripping invisible characters such as ZWSP, BOM and direction marks.',
                'Tick "Reveal invisible characters" to see spaces, tabs, line breaks, NBSP, ZWNJ and invisibles marked with colored symbols on the output.',
                'Check the stats row (characters before and after, characters removed, line counts and how many ZWNJ survived), then copy the output.',
            ],
            'faq' => [
                ['q' => 'Does this tool delete the Persian ZWNJ?', 'a' => 'Not by default. U+200C and U+200D are deliberately excluded from every whitespace class the cleaner uses, so they pass through untouched. They are only removed if you tick the separate "Remove ZWNJ and ZWJ (risky)" box, and a yellow warning appears the moment you do.'],
                ['q' => 'How is "remove invisible characters" different from the ZWNJ option?', 'a' => 'The invisible-characters option removes U+200B, U+2060, U+FEFF, U+00AD and the direction marks U+200E, U+200F and U+061C, and never touches U+200C or U+200D. The two options are independent, so it is safe to enable the invisible-character cleanup on Persian text.'],
                ['q' => 'Why does "remove blank lines" keep a line that contains only a ZWNJ?', 'a' => 'That is intentional. A line counts as blank only when it contains nothing but spaces, tabs or Unicode spaces; a ZWNJ is treated as real content, so the line survives. To drop such a line you would first have to enable the risky ZWNJ removal option.'],
                ['q' => 'What are the limits?', 'a' => 'Processing is capped at 500,000 characters; longer input is truncated and a warning is shown. The invisible-character preview renders at most 20,000 characters. Line endings are always normalized to LF, so if a file must keep Windows CRLF endings, restore them in your editor after copying.'],
            ],
        ],
        'tr' => [
            'intro' => 'Çoğu "fazla boşlukları sil" aracı Farsça sıfır genişlikli birleştirmeyen karakteri (U+200C) boşluk sanıp siler; bu da «می‌شود» kelimesini sessizce «میشود» hâline getirir. Bu temizleyici U+200C ve U+200D karakterlerini uyguladığı hiçbir boşluk kuralına dahil etmez ve onları yalnızca ayrı, açıkça işaretlenmiş bir seçenek işaretlendiğinde kaldırır. Tüm işlem tarayıcınızda yapılır, metin hiçbir yere gönderilmez.',
            'steps' => [
                'Metni girdi kutusuna yapıştırın. Sonuç siz yazarken yeniden hesaplanır, ayrıca bir düğmeye basmanız gerekmez.',
                'Temizlik seçeneklerini seçin: art arda boşlukları birleştirme, her satırı kırpma, metnin tamamını kırpma, sekmeleri 1, 2, 4 veya 8 genişlikte boşluğa çevirme ve NBSP ile Unicode boşlukları normalleştirme.',
                'İsterseniz boş satırları kaldırma, her şeyi tek satırda birleştirme veya ZWSP, BOM ve yön işaretleri gibi görünmez karakterleri silme seçeneklerini açın.',
                '"Görünmez karakterleri göster" kutusunu işaretleyerek boşluk, sekme, satır sonu, NBSP, ZWNJ ve görünmez karakterleri çıktı üzerinde renkli simgelerle görün.',
                'Alttaki istatistikleri (önceki ve sonraki karakter sayısı, silinen karakterler, satır sayıları ve korunan ZWNJ adedi) kontrol edin, sonra çıktıyı kopyalayın.',
            ],
            'faq' => [
                ['q' => 'Bu araç Farsça ZWNJ karakterini siler mi?', 'a' => 'Varsayılan olarak hayır. U+200C ve U+200D, aracın kullandığı boşluk sınıflarının hiçbirine bilerek dahil edilmemiştir ve dokunulmadan geçer. Yalnızca ayrı duran "ZWNJ ve ZWJ karakterlerini kaldır (riskli)" kutusunu işaretlerseniz silinirler ve o anda sarı bir uyarı görünür.'],
                ['q' => '"Görünmez karakterleri kaldır" seçeneğinin ZWNJ seçeneğinden farkı nedir?', 'a' => 'Görünmez karakter seçeneği U+200B, U+2060, U+FEFF, U+00AD ile U+200E, U+200F ve U+061C yön işaretlerini kaldırır; U+200C veya U+200D karakterine asla dokunmaz. İki seçenek birbirinden bağımsızdır, bu yüzden Farsça metinde görünmez karakter temizliğini rahatça açabilirsiniz.'],
                ['q' => 'Neden yalnızca ZWNJ içeren bir satır "boş satırları kaldır" ile silinmiyor?', 'a' => 'Bu bilinçli bir davranıştır. Bir satır ancak sadece boşluk, sekme veya Unicode boşluk içeriyorsa boş sayılır; ZWNJ gerçek içerik kabul edilir ve satır korunur. Böyle bir satırı silmek için önce riskli ZWNJ kaldırma seçeneğini açmanız gerekir.'],
                ['q' => 'Aracın sınırları neler?', 'a' => 'İşlem 500.000 karakterle sınırlıdır; daha uzun girdi kısaltılır ve bir uyarı gösterilir. Görünmez karakter önizlemesi en fazla 20.000 karakter çizer. Ayrıca satır sonları her zaman LF olarak normalleştirilir, bu nedenle dosyanız Windows CRLF satır sonlarını korumak zorundaysa kopyaladıktan sonra kendi düzenleyicinizde geri ayarlayın.'],
            ],
        ],
    ],

    'json-tree' => [
        'fa' => [
            'intro' => 'JSON.parse مرورگر ترتیب کلیدهایی که شبیه عدد هستند را جابه‌جا می‌کند، از دو کلید هم‌نام فقط آخری را نگه می‌دارد و هر عدد صحیح بزرگ‌تر از ۲ به توان ۵۳ را گرد می‌کند؛ یعنی یک payload می‌تواند در کنسول سالم به نظر برسد و باز هم غلط باشد. این ابزار پارسر RFC 8259 خودش را دارد، پس درخت ترتیب اصلی اعضا را نشان می‌دهد، کلید تکراری را برچسب می‌زند و رقم‌های عدد بزرگ را دست‌نخورده چاپ می‌کند. همه‌ی کار هم داخل مرورگر انجام می‌شود.',
            'steps' => [
                'JSON را در کادر ورودی بچسبانید یا دکمهٔ «نمونه» را بزنید؛ درخت بدون دکمهٔ اجرا و با کمی تأخیر پس از تایپ ساخته می‌شود.',
                'نوار آمار بالای درخت تعداد گره، عمق، کلید، شیء، آرایه و زمان تجزیه را نشان می‌دهد و اگر کلید تکراری باشد، پیل نارنجی شمار آن را اضافه می‌کند.',
                'برای باز و بسته کردن هر شاخه روی مثلث کنارش کلیک کنید یا روی سطر دوبار بزنید؛ دکمه‌های «باز کردن همه» و «بستن همه» کل درخت را کنترل می‌کنند و کلیدهای جهت‌دار هم داخل درخت کار می‌کنند.',
                'در کادر جست‌وجو عبارت را بنویسید؛ هر کلید و مقدار منطبق هایلایت می‌شود، شاخه‌های والدش خودکار باز می‌شوند و با Enter یا دکمه‌های بالا و پایین بین نتایج می‌چرخید.',
                'روی گرهٔ موردنظر کلیک کنید تا مسیر JSONPath و نوع، طول یا تعداد فرزندش پایین صفحه بیاید، سپس «کپی مسیر» یا «کپی مقدار» را بزنید.',
            ],
            'faq' => [
                ['q' => 'آیا JSON من جایی آپلود می‌شود؟', 'a' => 'خیر. تجزیه، رسم درخت، جست‌وجو و کپی همگی با جاوااسکریپت در همان مرورگر شما انجام می‌شود و هیچ درخواستی با محتوای ورودی به سرور فرستاده نمی‌شود. برای payload های حاوی داده‌ی مشتری هم بی‌خطر است.'],
                ['q' => 'چرا کنار عدد بزرگم برچسب «صحیح ناامن» خورده؟', 'a' => 'چون آن عدد از محدودهٔ امن اعداد صحیح در جاوااسکریپت (۲ به توان ۵۳ منهای ۱) گذشته است. این ابزار رقم‌های اصلی را از متن ورودی نگه می‌دارد و «کپی مقدار» هم همان لیترال دست‌نخورده را می‌دهد، اما اگر همان داده را در کد خودتان با JSON.parse بخوانید رقم‌های آخر تغییر می‌کند؛ راه‌حل معمول این است که سمت API آن شناسه را رشته بفرستید.'],
                ['q' => 'کادر جست‌وجو عبارت JSONPath قبول می‌کند؟', 'a' => 'نه، و این رایج‌ترین سوءتفاهم دربارهٔ این ابزار است. جست‌وجو یک تطبیق زیررشته‌ای ساده و بدون حساسیت به بزرگی و کوچکی حروف روی نام کلیدها و مقادیر است؛ JSONPath فقط خروجی است، یعنی مسیر گره‌ای که انتخاب کرده‌اید تا در کد یا در jq استفاده کنید.'],
                ['q' => 'برای فایل‌های خیلی بزرگ چه محدودیتی دارد؟', 'a' => 'ورودی تا ۲ میلیون کاراکتر، حداکثر ۱۵۰٬۰۰۰ گره و ۲۰۰ سطح تودرتو پذیرفته می‌شود و در هر لحظه فقط ۶۰۰۰ سطر رسم می‌شود؛ بقیه پس از بستن شاخه‌ها یا جست‌وجو دیده می‌شوند. رشته‌های بلندتر از ۱۶۰ کاراکتر هم در درخت کوتاه‌شده نمایش داده می‌شوند، ولی «کپی مقدار» همیشه مقدار کامل را می‌دهد.'],
            ],
        ],
        'en' => [
            'intro' => "The browser's JSON.parse silently reorders keys that look like integers, keeps only the last of two same-named members, and rounds any integer past 2^53 — so a payload can look fine in devtools and still be wrong. This viewer ships its own RFC 8259 parser, so the tree preserves original member order, tags duplicate keys, and prints big integers with their exact digits. Everything runs in your browser.",
            'steps' => [
                'Paste your JSON into the input box or hit Sample; the tree builds itself shortly after you stop typing, with no run button to press.',
                'Read the stat pills above the tree: node count, depth, keys, objects, arrays and parse time — plus an orange pill counting duplicate keys when the payload has any.',
                'Expand or collapse a branch by clicking its twisty or double-clicking the row; Expand all and Collapse all handle the whole tree, and the arrow keys work once the tree has focus.',
                'Type in the search box to highlight every matching key and value; parent branches open automatically, and Enter or the up/down buttons cycle through the hit counter.',
                'Click a node to reveal its JSONPath plus its type, length or child count, then use Copy path or Copy value.',
            ],
            'faq' => [
                ['q' => 'Is my JSON uploaded anywhere?', 'a' => 'No. Parsing, rendering, searching and copying all happen in JavaScript in your own browser, and no request carrying the input is ever sent to a server. That makes it safe for payloads containing customer data.'],
                ['q' => 'Why is my large number tagged as an unsafe int?', 'a' => "Because it sits outside JavaScript's safe integer range (2^53 minus 1). The viewer keeps the original digits from your input text and Copy value returns that untouched literal, but if your own code reads the same data with JSON.parse the trailing digits will change. The usual fix is to have the API send that identifier as a string."],
                ['q' => 'Can the search box take a JSONPath expression?', 'a' => 'No, and that is the most common misconception about this tool. Search is a plain case-insensitive substring match over key names and values. JSONPath is output only: it is the path of the node you selected, ready to paste into code or jq.'],
                ['q' => 'What are the limits on very large files?', 'a' => 'The input is capped at 2 million characters, 150,000 nodes and 200 nesting levels, and only 6,000 rows are painted at a time — the rest appear once you collapse branches or search. Strings longer than 160 characters are shown truncated in the tree, but Copy value always yields the full value.'],
            ],
        ],
        'tr' => [
            'intro' => 'Tarayıcıdaki JSON.parse, tam sayıya benzeyen anahtarların sırasını sessizce değiştirir, aynı adlı iki üyeden yalnızca sonuncusunu tutar ve 2^53 sınırını aşan tam sayıları yuvarlar; yani bir payload geliştirici konsolunda düzgün görünüp yine de hatalı olabilir. Bu görüntüleyici kendi RFC 8259 ayrıştırıcısını kullanır: ağaç özgün üye sırasını korur, yinelenen anahtarları etiketler ve büyük tam sayıları tam basamaklarıyla yazar. Her şey tarayıcınızda çalışır.',
            'steps' => [
                'JSON verinizi girdi kutusuna yapıştırın ya da Örnek düğmesine basın; yazmayı bıraktıktan kısa süre sonra ağaç kendiliğinden oluşur, ayrıca bir çalıştır düğmesi yoktur.',
                'Ağacın üstündeki istatistik rozetlerini okuyun: düğüm sayısı, derinlik, anahtar, nesne, dizi ve ayrıştırma süresi; yinelenen anahtar varsa turuncu bir rozet bunların sayısını gösterir.',
                'Bir dalı açmak veya kapatmak için yanındaki oka tıklayın ya da satıra çift tıklayın; Tümünü aç ve Tümünü kapat düğmeleri ağacın tamamını yönetir, ağaç odaktayken ok tuşları da çalışır.',
                'Arama kutusuna yazdığınızda eşleşen tüm anahtar ve değerler vurgulanır, üst dallar kendiliğinden açılır; Enter ya da yukarı/aşağı düğmeleriyle sonuçlar arasında dolaşırsınız.',
                'Bir düğüme tıklayarak JSONPath yolunu ve türünü, uzunluğunu veya alt öğe sayısını görün, sonra Yolu kopyala ya da Değeri kopyala düğmesini kullanın.',
            ],
            'faq' => [
                ['q' => 'JSON verim bir yere yükleniyor mu?', 'a' => 'Hayır. Ayrıştırma, ağacın çizimi, arama ve kopyalama tamamen kendi tarayıcınızdaki JavaScript ile yapılır ve girdiyi taşıyan hiçbir istek sunucuya gitmez. Bu nedenle müşteri verisi içeren payload verileri için de güvenlidir.'],
                ['q' => 'Büyük sayım neden güvensiz tam sayı olarak etiketlendi?', 'a' => 'Çünkü o sayı JavaScript güvenli tam sayı aralığının (2^53 eksi 1) dışında kalıyor. Görüntüleyici basamakları girdi metninden olduğu gibi korur ve Değeri kopyala bu ham değeri verir; ancak aynı veriyi kendi kodunuzda JSON.parse ile okursanız son basamaklar değişir. Alışılmış çözüm, bu kimliği API tarafında metin olarak göndermektir.'],
                ['q' => 'Arama kutusuna JSONPath ifadesi yazılabilir mi?', 'a' => 'Hayır ve bu araç hakkındaki en yaygın yanlış anlama budur. Arama, anahtar adları ve değerler üzerinde büyük küçük harf ayrımı olmayan düz bir alt dizi eşleşmesidir. JSONPath yalnızca çıktıdır: seçtiğiniz düğümün koda veya jq komutuna yapıştırılmaya hazır yoludur.'],
                ['q' => 'Çok büyük dosyalarda sınırlar neler?', 'a' => 'Girdi 2 milyon karakter, 150.000 düğüm ve 200 iç içe seviye ile sınırlıdır; aynı anda yalnızca 6.000 satır çizilir, kalanı dalları kapattığınızda veya arama yaptığınızda görünür. 160 karakterden uzun metinler ağaçta kısaltılmış gösterilir, ancak Değeri kopyala her zaman tam değeri verir.'],
            ],
        ],
    ],

    'image-cropper' => [
        'fa' => [
            'intro' => 'پیش‌نمایش این ابزار برای روان ماندن کار تا حداکثر ۱۴۰۰ پیکسل کوچک می‌شود، اما برش نهایی همیشه از فایل اصلی و با رزولوشن کامل گرفته می‌شود. تصور رایج این است که چون کادر را روی یک پیش‌نمایش کوچک کشیده‌اید، خروجی هم کم‌کیفیت می‌شود؛ این‌طور نیست. کل کار داخل مرورگر و روی Canvas انجام می‌شود و فایل شما به هیچ سروری فرستاده نمی‌شود.',
            'steps' => [
                'تصویر را روی کادر نقطه‌چین رها کنید یا روی «انتخاب تصویر» بزنید.',
                'نسبت ابعاد را از میان آزاد، ۱:۱، ۴:۳ یا ۱۶:۹ انتخاب کنید.',
                'کادر سفید را بکشید تا جابه‌جا شود و با هشت دستگیره اندازه‌اش را تنظیم کنید؛ موقعیت و ابعاد خروجی لحظه‌ای زیر تصویر نمایش داده می‌شود.',
                'قالب PNG یا JPEG را انتخاب کنید و روی دانلود بزنید؛ فایل با نامی مثل cropped-800x600.png ذخیره می‌شود.',
            ],
            'faq' => [
                ['q' => 'آیا برش باعث افت کیفیت تصویر می‌شود؟', 'a' => 'نه. پیش‌نمایش فقط برای نمایش کوچک شده و برش از فایل اصلی برداشته می‌شود، پس تراکم پیکسل خروجی همان تصویر ورودی است. تنها در حالت JPEG فشرده‌سازی با کیفیت حدود ۹۲ درصد اعمال می‌شود.'],
                ['q' => 'شفافیت تصویر حفظ می‌شود؟', 'a' => 'در خروجی PNG بله. اگر JPEG بگیرید، چون این قالب کانال آلفا ندارد، ناحیه‌های شفاف با رنگ سفید پر می‌شوند.'],
                ['q' => 'می‌توانم تصویر را بچرخانم، آینه کنم یا ابعاد خروجی را تغییر بدهم؟', 'a' => 'خیر. این ابزار فقط برش می‌دهد؛ چرخش، آینه‌کردن، زوم و تغییر اندازه ندارد و کوچک‌ترین کادر ممکن حدود ۴ درصد ضلع کوتاه‌تر تصویر است.'],
                ['q' => 'فایل من جایی آپلود می‌شود؟', 'a' => 'خیر. تصویر با FileReader در مرورگر خوانده و روی Canvas پردازش می‌شود؛ هیچ داده‌ای به سرور ما ارسال نمی‌شود.'],
            ],
        ],
        'en' => [
            'intro' => 'The on-screen preview is downscaled to at most 1400 px so dragging stays smooth, but the actual crop is always taken from the original file at full resolution. People often assume that cropping a scaled preview throws away pixels — it does not. Everything runs on a Canvas inside your browser, so the file never leaves your machine.',
            'steps' => [
                'Drop an image onto the dashed area, or click Choose image.',
                'Pick an aspect ratio: Free, 1:1, 4:3 or 16:9.',
                'Drag the white frame to reposition it and use the eight handles to resize; the crop position and output size update live below the image.',
                'Choose PNG or JPEG, then click download — the file is saved as something like cropped-800x600.png.',
            ],
            'faq' => [
                ['q' => 'Does cropping reduce image quality?', 'a' => 'No. The preview is only scaled for display; the crop is copied from the original image, so the output keeps the same pixel density. Only JPEG adds compression, at roughly 92% quality.'],
                ['q' => 'Is transparency preserved?', 'a' => 'With PNG output, yes. JPEG has no alpha channel, so transparent areas are filled with white before the file is written.'],
                ['q' => 'Can I rotate, flip, zoom or resize the result?', 'a' => "No. This tool only crops — there is no rotation, mirroring, zoom or output resizing, and the smallest possible frame is about 4% of the image's shorter side."],
                ['q' => 'Is my file uploaded anywhere?', 'a' => 'No. The image is read with FileReader and processed on a Canvas locally; nothing is sent to our server.'],
            ],
        ],
        'tr' => [
            'intro' => 'Ekrandaki önizleme, sürükleme akıcı kalsın diye en fazla 1400 piksele küçültülür; ancak kırpma her zaman orijinal dosyadan tam çözünürlükte alınır. Küçültülmüş bir önizleme üzerinde kırpınca piksel kaybedildiği sanılır, oysa durum böyle değildir. Tüm işlem tarayıcı içinde Canvas üzerinde yapılır ve dosya cihazınızdan çıkmaz.',
            'steps' => [
                'Görseli kesik çizgili alana bırakın veya Görsel seç düğmesine tıklayın.',
                'En boy oranını seçin: Serbest, 1:1, 4:3 veya 16:9.',
                'Beyaz çerçeveyi sürükleyerek konumlandırın ve sekiz tutamaçla boyutlandırın; kırpma konumu ile çıktı boyutu görselin altında anlık güncellenir.',
                'PNG veya JPEG seçip indirin; dosya cropped-800x600.png benzeri bir adla kaydedilir.',
            ],
            'faq' => [
                ['q' => 'Kırpmak görsel kalitesini düşürür mü?', 'a' => 'Hayır. Önizleme yalnızca gösterim için küçültülür, kırpma orijinal görselden alınır ve piksel yoğunluğu korunur. Sadece JPEG seçilirse yaklaşık yüzde 92 kalitede sıkıştırma uygulanır.'],
                ['q' => 'Saydamlık korunur mu?', 'a' => 'PNG çıktısında evet. JPEG biçiminde alfa kanalı bulunmadığı için saydam alanlar beyazla doldurulur.'],
                ['q' => 'Görseli döndürebilir, aynalayabilir veya boyutunu değiştirebilir miyim?', 'a' => 'Hayır. Bu araç yalnızca kırpar; döndürme, aynalama, yakınlaştırma veya çıktı boyutlandırma yoktur ve en küçük çerçeve, kısa kenarın yaklaşık yüzde 4 kadarıdır.'],
                ['q' => 'Dosyam bir yere yükleniyor mu?', 'a' => 'Hayır. Görsel FileReader ile okunur ve Canvas üzerinde yerel olarak işlenir; sunucumuza hiçbir veri gönderilmez.'],
            ],
        ],
    ],
];
