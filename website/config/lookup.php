<?php

/*
|--------------------------------------------------------------------------
| ServerNet — مجموعه ابزار DNS و شبکه (Lookup)
|--------------------------------------------------------------------------
| هر نوع بررسی یک صفحه‌ی مستقل سئو‌شده با URL تمیز /lookup/{type} دارد.
| کلید هر type با متد سرویس NetworkTools و رندر فرانت‌اند هماهنگ است.
|
| kind:  dns | dnssec | propagation | reverse | ssl | ports | ping
| input: domain | host | ip
*/

return [

    'groups' => [
        'records' => ['fa' => 'رکوردهای DNS', 'en' => 'DNS Records', 'tr' => 'DNS Kayıtları'],
        'network' => ['fa' => 'شبکه و امنیت', 'en' => 'Network & Security', 'tr' => 'Ağ & Güvenlik'],
    ],

    'types' => [

        /* ------------------------------------------------------------- A */
        'a' => [
            'group' => 'records', 'icon' => 'globe', 'kind' => 'dns', 'rr' => 'A', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد A', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد A دامنه — استعلام آنلاین IPv4',
                'meta_d' => 'رکورد A هر دامنه را آنلاین و رایگان استعلام کنید؛ آدرس IPv4 سرور، TTL و صحت اتصال دامنه به هاست را در چند ثانیه ببینید.',
                'h1a' => 'رکورد A دامنه را', 'h1b' => 'زنده ببینید.',
                'lead' => 'دامنه را وارد کنید تا آدرس IPv4 سرور، مقدار TTL و صحت اشاره‌ی دامنه به هاست را فوری ببینید.',
                'intro' => 'رکورد A مهم‌ترین رکورد DNS است و نام دامنه را به یک آدرس IPv4 وصل می‌کند. وقتی کاربر دامنه‌ی شما را باز می‌کند، مرورگر ابتدا رکورد A را می‌خواند تا بفهمد باید به کدام سرور وصل شود. این ابزار رکورد A را مستقیم از resolver جهانی گوگل می‌خواند تا نتیجه دقیق و مستقل از کش محلی باشد.',
                'faq' => [
                    ['q' => 'رکورد A چیست؟', 'a' => 'رکوردی که نام دامنه را به یک آدرس IPv4 (مثل 93.184.216.34) نگاشت می‌کند تا مرورگر بداند به کدام سرور وصل شود.'],
                    ['q' => 'TTL در نتیجه یعنی چه؟', 'a' => 'مدت‌زمان (به ثانیه) که resolverها مجازند نتیجه را کش کنند. TTL کمتر یعنی تغییرات DNS سریع‌تر جهانی می‌شوند.'],
                    ['q' => 'چرا چند رکورد A می‌بینم؟', 'a' => 'برای توزیع بار یا افزونگی، یک دامنه می‌تواند چند IP داشته باشد؛ همه معتبرند و مرورگر یکی را انتخاب می‌کند.'],
                ],
            ],
            'en' => [
                't' => 'A Record', 'placeholder' => 'example.com',
                'meta_t' => 'A Record Lookup — Check a Domain\'s IPv4 Online',
                'meta_d' => 'Look up any domain\'s A record online for free. See the server IPv4 address, TTL and whether the domain points to the right host in seconds.',
                'h1a' => 'See a domain\'s', 'h1b' => 'A record live.',
                'lead' => 'Enter a domain to instantly see its server IPv4 address, TTL value and whether it points to the right host.',
                'intro' => 'The A record is the most important DNS record — it maps a hostname to an IPv4 address. When a visitor opens your domain, the browser reads the A record first to know which server to connect to. This tool queries the A record straight from Google\'s global resolver, so results are accurate and independent of your local cache.',
                'faq' => [
                    ['q' => 'What is an A record?', 'a' => 'A DNS record that maps a hostname to an IPv4 address (e.g. 93.184.216.34) so browsers know which server to reach.'],
                    ['q' => 'What does the TTL mean?', 'a' => 'How long (in seconds) resolvers may cache the result. A lower TTL means DNS changes propagate faster worldwide.'],
                    ['q' => 'Why do I see multiple A records?', 'a' => 'For load balancing or redundancy a domain can have several IPs; all are valid and the browser picks one.'],
                ],
            ],
            'tr' => [
                't' => 'A Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'A Kaydı Sorgulama — Alan Adı IPv4 Kontrolü',
                'meta_d' => 'Herhangi bir alan adının A kaydını ücretsiz sorgulayın. Sunucu IPv4 adresini, TTL değerini ve doğru hosta işaret edip etmediğini saniyeler içinde görün.',
                'h1a' => 'Alan adının', 'h1b' => 'A kaydını görün.',
                'lead' => 'Sunucu IPv4 adresini, TTL değerini ve doğru hosta işaret edip etmediğini anında görmek için bir alan adı girin.',
                'intro' => 'A kaydı en önemli DNS kaydıdır; bir ana bilgisayar adını IPv4 adresine eşler. Ziyaretçi alan adınızı açtığında tarayıcı önce A kaydını okur. Bu araç A kaydını doğrudan Google\'ın küresel çözümleyicisinden sorgular.',
                'faq' => [
                    ['q' => 'A kaydı nedir?', 'a' => 'Bir ana bilgisayar adını IPv4 adresine eşleyen DNS kaydı.'],
                    ['q' => 'TTL ne anlama gelir?', 'a' => 'Çözümleyicilerin sonucu önbelleğe alabileceği saniye cinsinden süre.'],
                    ['q' => 'Neden birden çok A kaydı görüyorum?', 'a' => 'Yük dengeleme veya yedeklilik için bir alan adı birden çok IP\'ye sahip olabilir.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- AAAA */
        'aaaa' => [
            'group' => 'records', 'icon' => 'globe', 'kind' => 'dns', 'rr' => 'AAAA', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد AAAA', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد AAAA — استعلام IPv6 دامنه',
                'meta_d' => 'رکورد AAAA دامنه را رایگان استعلام کنید و ببینید سایت شما روی IPv6 در دسترس است یا نه؛ همراه آدرس کامل IPv6 و TTL.',
                'h1a' => 'آماده‌ی', 'h1b' => 'IPv6 هستید؟',
                'lead' => 'دامنه را وارد کنید تا رکورد AAAA و آدرس IPv6 سرور را ببینید و از دسترس‌پذیری روی نسل جدید اینترنت مطمئن شوید.',
                'intro' => 'رکورد AAAA همتای IPv6 رکورد A است و نام دامنه را به یک آدرس IPv6 وصل می‌کند. با کمبود آدرس‌های IPv4، پشتیبانی از IPv6 برای سرعت و آینده‌نگری اهمیت دارد. نبود رکورد AAAA یعنی کاربران فقط‌-IPv6 نمی‌توانند مستقیم به سایت شما وصل شوند.',
                'faq' => [
                    ['q' => 'رکورد AAAA چیست؟', 'a' => 'رکوردی که دامنه را به آدرس IPv6 نگاشت می‌کند؛ نسخه‌ی مدرن رکورد A.'],
                    ['q' => 'اگر رکورد AAAA نداشته باشم مشکلی هست؟', 'a' => 'سایت همچنان روی IPv4 کار می‌کند، اما بهتر است برای پوشش کامل کاربران، IPv6 هم فعال باشد.'],
                    ['q' => 'چطور IPv6 را فعال کنم؟', 'a' => 'اگر سرور شما IPv6 دارد، کافی است در پنل DNS یک رکورد AAAA به آن آدرس بسازید؛ تیم سرورنت کمک می‌کند.'],
                ],
            ],
            'en' => [
                't' => 'AAAA Record', 'placeholder' => 'example.com',
                'meta_t' => 'AAAA Record Lookup — Check a Domain\'s IPv6',
                'meta_d' => 'Look up any domain\'s AAAA record for free and see whether your site is reachable over IPv6, with the full IPv6 address and TTL.',
                'h1a' => 'Are you', 'h1b' => 'IPv6 ready?',
                'lead' => 'Enter a domain to see its AAAA record and server IPv6 address, and confirm you\'re reachable on the modern internet.',
                'intro' => 'The AAAA record is the IPv6 counterpart of the A record — it maps a hostname to an IPv6 address. With IPv4 addresses running out, IPv6 support matters for speed and future-proofing. No AAAA record means IPv6-only users can\'t reach your site directly.',
                'faq' => [
                    ['q' => 'What is an AAAA record?', 'a' => 'A DNS record that maps a domain to an IPv6 address — the modern version of the A record.'],
                    ['q' => 'Is it a problem if I have no AAAA record?', 'a' => 'Your site still works over IPv4, but enabling IPv6 gives you full coverage of all visitors.'],
                    ['q' => 'How do I enable IPv6?', 'a' => 'If your server has IPv6, just add an AAAA record pointing to that address in your DNS panel; ServerNet can help.'],
                ],
            ],
            'tr' => [
                't' => 'AAAA Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'AAAA Kaydı Sorgulama — Alan Adı IPv6 Kontrolü',
                'meta_d' => 'Herhangi bir alan adının AAAA kaydını ücretsiz sorgulayın ve sitenizin IPv6 üzerinden erişilebilir olup olmadığını görün.',
                'h1a' => 'IPv6\'ya', 'h1b' => 'hazır mısınız?',
                'lead' => 'AAAA kaydını ve sunucu IPv6 adresini görmek için bir alan adı girin.',
                'intro' => 'AAAA kaydı, A kaydının IPv6 karşılığıdır; bir ana bilgisayar adını IPv6 adresine eşler. IPv4 adresleri tükenirken IPv6 desteği önemlidir.',
                'faq' => [
                    ['q' => 'AAAA kaydı nedir?', 'a' => 'Bir alan adını IPv6 adresine eşleyen DNS kaydı.'],
                    ['q' => 'AAAA kaydım yoksa sorun olur mu?', 'a' => 'Siteniz IPv4 üzerinden çalışır, ancak IPv6 tam kapsama sağlar.'],
                    ['q' => 'IPv6\'yı nasıl etkinleştiririm?', 'a' => 'Sunucunuzun IPv6\'sı varsa DNS panelinde bir AAAA kaydı ekleyin.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- MX */
        'mx' => [
            'group' => 'records', 'icon' => 'mail', 'kind' => 'dns', 'rr' => 'MX', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد MX', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد MX — استعلام سرور ایمیل دامنه',
                'meta_d' => 'رکوردهای MX دامنه را رایگان استعلام کنید؛ سرورهای دریافت ایمیل، اولویت هرکدام و صحت تنظیمات میل را ببینید.',
                'h1a' => 'ایمیل‌ها', 'h1b' => 'کجا می‌روند؟',
                'lead' => 'دامنه را وارد کنید تا سرورهای ایمیل (MX)، اولویت هرکدام و درستی مسیر دریافت ایمیل را ببینید.',
                'intro' => 'رکورد MX (Mail Exchange) مشخص می‌کند ایمیل‌های ارسالی به دامنه‌ی شما باید به کدام سرور تحویل داده شوند. عدد اولویت پایین‌تر یعنی ترجیح بالاتر. تنظیم اشتباه MX شایع‌ترین دلیل نرسیدن ایمیل است؛ این ابزار به شما نشان می‌دهد رکوردها درست پیکربندی شده‌اند یا نه.',
                'faq' => [
                    ['q' => 'رکورد MX چیست؟', 'a' => 'رکوردی که سرور مسئول دریافت ایمیل دامنه را تعیین می‌کند.'],
                    ['q' => 'عدد کنار رکورد MX چیست؟', 'a' => 'اولویت (Priority) است؛ سرور با عدد کمتر اول امتحان می‌شود و بقیه پشتیبان‌اند.'],
                    ['q' => 'چرا ایمیل‌هایم نمی‌رسند؟', 'a' => 'اغلب به‌خاطر نبود یا اشتباه‌بودن رکورد MX یا رکورد A سرور ایمیل است؛ همراه SPF/DKIM بررسی کنید.'],
                ],
            ],
            'en' => [
                't' => 'MX Record', 'placeholder' => 'example.com',
                'meta_t' => 'MX Record Lookup — Check a Domain\'s Mail Servers',
                'meta_d' => 'Look up any domain\'s MX records for free. See the mail servers that receive email, their priority and whether mail routing is set up correctly.',
                'h1a' => 'Where does', 'h1b' => 'email go?',
                'lead' => 'Enter a domain to see its mail servers (MX), each one\'s priority, and whether email delivery is routed correctly.',
                'intro' => 'The MX (Mail Exchange) record decides which server should receive email sent to your domain. A lower priority number means higher preference. A misconfigured MX record is the most common reason email doesn\'t arrive — this tool shows you whether your records are set up correctly.',
                'faq' => [
                    ['q' => 'What is an MX record?', 'a' => 'A DNS record that names the server responsible for receiving a domain\'s email.'],
                    ['q' => 'What is the number next to the MX record?', 'a' => 'The priority — the server with the lowest number is tried first, the rest act as backups.'],
                    ['q' => 'Why isn\'t my email arriving?', 'a' => 'Usually a missing or wrong MX record (or the mail server\'s A record); check SPF/DKIM too.'],
                ],
            ],
            'tr' => [
                't' => 'MX Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'MX Kaydı Sorgulama — Alan Adı Posta Sunucusu',
                'meta_d' => 'Herhangi bir alan adının MX kayıtlarını ücretsiz sorgulayın; e-posta alan sunucuları ve öncelikleri görün.',
                'h1a' => 'E-posta', 'h1b' => 'nereye gidiyor?',
                'lead' => 'Posta sunucularını (MX) ve önceliklerini görmek için bir alan adı girin.',
                'intro' => 'MX (Mail Exchange) kaydı, alan adınıza gönderilen e-postaların hangi sunucuya teslim edileceğini belirler. Düşük öncelik numarası daha yüksek tercih anlamına gelir.',
                'faq' => [
                    ['q' => 'MX kaydı nedir?', 'a' => 'Bir alan adının e-postasını almaktan sorumlu sunucuyu belirten DNS kaydı.'],
                    ['q' => 'MX kaydının yanındaki sayı nedir?', 'a' => 'Öncelik; en düşük numaralı sunucu önce denenir.'],
                    ['q' => 'E-postam neden gelmiyor?', 'a' => 'Genellikle eksik veya yanlış MX kaydı; SPF/DKIM de kontrol edin.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- NS */
        'ns' => [
            'group' => 'records', 'icon' => 'server', 'kind' => 'dns', 'rr' => 'NS', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد NS', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد NS — استعلام نیم‌سرور دامنه',
                'meta_d' => 'نیم‌سرورهای (NS) دامنه را رایگان استعلام کنید و ببینید مدیریت DNS دامنه دست کدام سرورهاست.',
                'h1a' => 'DNS دامنه را', 'h1b' => 'چه کسی می‌گرداند؟',
                'lead' => 'دامنه را وارد کنید تا نیم‌سرورهای معتبر (NS) که مدیریت DNS آن را برعهده دارند ببینید.',
                'intro' => 'رکورد NS نشان می‌دهد کدام نیم‌سرورها مرجع رسمی رکوردهای DNS دامنه هستند. وقتی دامنه را از یک ثبت‌کننده به دیگری منتقل می‌کنید یا CDN اضافه می‌کنید، همین رکوردها تغییر می‌کنند. بررسی NS اولین قدم برای عیب‌یابی مشکلات DNS است.',
                'faq' => [
                    ['q' => 'رکورد NS چیست؟', 'a' => 'رکوردی که نیم‌سرورهای مرجع دامنه را مشخص می‌کند؛ همان‌جایی که همه‌ی رکوردهای دیگر نگهداری می‌شوند.'],
                    ['q' => 'چند رکورد NS طبیعی است؟', 'a' => 'معمولاً دو تا چهار نیم‌سرور برای افزونگی و پایداری توصیه می‌شود.'],
                    ['q' => 'تغییر NS چقدر طول می‌کشد؟', 'a' => 'انتشار کامل تا ۲۴–۴۸ ساعت ممکن است طول بکشد؛ با ابزار انتشار DNS پیگیری کنید.'],
                ],
            ],
            'en' => [
                't' => 'NS Record', 'placeholder' => 'example.com',
                'meta_t' => 'NS Record Lookup — Check a Domain\'s Nameservers',
                'meta_d' => 'Look up a domain\'s nameservers (NS) for free and see which servers are authoritative for its DNS.',
                'h1a' => 'Who runs the', 'h1b' => 'domain\'s DNS?',
                'lead' => 'Enter a domain to see the authoritative nameservers (NS) that manage its DNS.',
                'intro' => 'The NS record shows which nameservers are the official authority for a domain\'s DNS records. When you move a domain between registrars or add a CDN, these are the records that change. Checking NS is the first step in diagnosing DNS issues.',
                'faq' => [
                    ['q' => 'What is an NS record?', 'a' => 'A record that names the authoritative nameservers for a domain — where all other records live.'],
                    ['q' => 'How many NS records are normal?', 'a' => 'Usually two to four nameservers are recommended for redundancy and stability.'],
                    ['q' => 'How long do NS changes take?', 'a' => 'Full propagation can take 24–48 hours; track it with the DNS propagation tool.'],
                ],
            ],
            'tr' => [
                't' => 'NS Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'NS Kaydı Sorgulama — Alan Adı Ad Sunucuları',
                'meta_d' => 'Bir alan adının ad sunucularını (NS) ücretsiz sorgulayın ve DNS\'ini hangi sunucuların yönettiğini görün.',
                'h1a' => 'DNS\'i kim', 'h1b' => 'yönetiyor?',
                'lead' => 'Yetkili ad sunucularını (NS) görmek için bir alan adı girin.',
                'intro' => 'NS kaydı, bir alan adının DNS kayıtları için resmi yetkili ad sunucularını gösterir. Kayıt kuruluşu değiştirdiğinizde bu kayıtlar değişir.',
                'faq' => [
                    ['q' => 'NS kaydı nedir?', 'a' => 'Bir alan adının yetkili ad sunucularını belirten kayıt.'],
                    ['q' => 'Kaç NS kaydı normaldir?', 'a' => 'Genellikle yedeklilik için iki ila dört ad sunucusu önerilir.'],
                    ['q' => 'NS değişiklikleri ne kadar sürer?', 'a' => 'Tam yayılma 24–48 saat sürebilir.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- TXT */
        'txt' => [
            'group' => 'records', 'icon' => 'book', 'kind' => 'dns', 'rr' => 'TXT', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد TXT', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد TXT — استعلام SPF، DKIM و تأیید دامنه',
                'meta_d' => 'رکوردهای TXT دامنه را رایگان ببینید؛ شامل SPF، DKIM، DMARC و کدهای تأیید مالکیت گوگل و مایکروسافت.',
                'h1a' => 'رکوردهای متنی را', 'h1b' => 'بخوانید.',
                'lead' => 'دامنه را وارد کنید تا همه‌ی رکوردهای TXT شامل SPF، DKIM، DMARC و کدهای تأیید را ببینید.',
                'intro' => 'رکورد TXT اطلاعات متنی دلخواه را در DNS نگه می‌دارد و امروز بیشتر برای امنیت ایمیل (SPF، DKIM، DMARC) و تأیید مالکیت دامنه توسط سرویس‌هایی مثل گوگل و مایکروسافت استفاده می‌شود. رکورد SPF نادرست یکی از دلایل رفتن ایمیل به اسپم است.',
                'faq' => [
                    ['q' => 'رکورد TXT برای چیست؟', 'a' => 'برای نگهداری متن دلخواه در DNS؛ عمدتاً SPF، DKIM، DMARC و تأیید مالکیت دامنه.'],
                    ['q' => 'SPF چیست؟', 'a' => 'رکورد TXT‌ای که مشخص می‌کند چه سرورهایی مجاز به ارسال ایمیل از طرف دامنه‌ی شما هستند.'],
                    ['q' => 'چند رکورد TXT می‌توانم داشته باشم؟', 'a' => 'به هر تعداد، اما فقط یک رکورد SPF مجاز است؛ چند SPF باعث خطا می‌شود.'],
                ],
            ],
            'en' => [
                't' => 'TXT Record', 'placeholder' => 'example.com',
                'meta_t' => 'TXT Record Lookup — Check SPF, DKIM & Verification',
                'meta_d' => 'View any domain\'s TXT records for free — including SPF, DKIM, DMARC and Google/Microsoft domain-verification codes.',
                'h1a' => 'Read the', 'h1b' => 'text records.',
                'lead' => 'Enter a domain to see all its TXT records, including SPF, DKIM, DMARC and verification codes.',
                'intro' => 'The TXT record holds arbitrary text in DNS and is now used mostly for email security (SPF, DKIM, DMARC) and domain-ownership verification by services like Google and Microsoft. A wrong SPF record is one of the top reasons email lands in spam.',
                'faq' => [
                    ['q' => 'What is a TXT record for?', 'a' => 'Holding arbitrary text in DNS — mainly SPF, DKIM, DMARC and domain verification.'],
                    ['q' => 'What is SPF?', 'a' => 'A TXT record that defines which servers are allowed to send email on behalf of your domain.'],
                    ['q' => 'How many TXT records can I have?', 'a' => 'As many as you like, but only one SPF record is allowed; multiple SPFs cause errors.'],
                ],
            ],
            'tr' => [
                't' => 'TXT Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'TXT Kaydı Sorgulama — SPF, DKIM ve Doğrulama',
                'meta_d' => 'Bir alan adının TXT kayıtlarını ücretsiz görüntüleyin — SPF, DKIM, DMARC ve doğrulama kodları dahil.',
                'h1a' => 'Metin', 'h1b' => 'kayıtlarını okuyun.',
                'lead' => 'SPF, DKIM, DMARC ve doğrulama kodları dahil tüm TXT kayıtlarını görmek için bir alan adı girin.',
                'intro' => 'TXT kaydı DNS\'te rastgele metin tutar ve çoğunlukla e-posta güvenliği (SPF, DKIM, DMARC) ve alan adı doğrulaması için kullanılır.',
                'faq' => [
                    ['q' => 'TXT kaydı ne işe yarar?', 'a' => 'DNS\'te metin tutmak — çoğunlukla SPF, DKIM, DMARC ve doğrulama.'],
                    ['q' => 'SPF nedir?', 'a' => 'Alan adınız adına hangi sunucuların e-posta gönderebileceğini tanımlayan TXT kaydı.'],
                    ['q' => 'Kaç TXT kaydım olabilir?', 'a' => 'İstediğiniz kadar, ancak yalnızca bir SPF kaydına izin verilir.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- CNAME */
        'cname' => [
            'group' => 'records', 'icon' => 'restore', 'kind' => 'dns', 'rr' => 'CNAME', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد CNAME', 'placeholder' => 'www.example.com',
                'meta_t' => 'بررسی رکورد CNAME — استعلام نام مستعار دامنه',
                'meta_d' => 'رکورد CNAME هر ساب‌دامنه را رایگان استعلام کنید و ببینید به کدام دامنه‌ی اصلی اشاره می‌کند.',
                'h1a' => 'نام مستعار', 'h1b' => 'به کجا می‌رود؟',
                'lead' => 'یک ساب‌دامنه (مثل www) وارد کنید تا ببینید رکورد CNAME آن به کدام مقصد اشاره دارد.',
                'intro' => 'رکورد CNAME یک نام را به‌عنوان «نام مستعار» به نام دیگری وصل می‌کند. برای مثال www.example.com می‌تواند با CNAME به example.com اشاره کند تا هر دو یک‌جا مدیریت شوند. CNAME در اتصال دامنه به CDN، سرویس ایمیل و پلتفرم‌های ابری کاربرد زیادی دارد.',
                'faq' => [
                    ['q' => 'رکورد CNAME چیست؟', 'a' => 'رکوردی که یک نام دامنه را به‌عنوان نام مستعار به یک نام دیگر (canonical) وصل می‌کند.'],
                    ['q' => 'تفاوت CNAME و A چیست؟', 'a' => 'رکورد A به IP اشاره می‌کند اما CNAME به یک نام دامنه‌ی دیگر؛ CNAME هنگام تغییر IP نیازی به به‌روزرسانی ندارد.'],
                    ['q' => 'چرا روی دامنه‌ی ریشه CNAME نمی‌شود؟', 'a' => 'طبق استاندارد، دامنه‌ی ریشه (بدون www) نمی‌تواند CNAME داشته باشد؛ از رکورد A یا ALIAS استفاده کنید.'],
                ],
            ],
            'en' => [
                't' => 'CNAME Record', 'placeholder' => 'www.example.com',
                'meta_t' => 'CNAME Record Lookup — Check a Domain Alias',
                'meta_d' => 'Look up any subdomain\'s CNAME record for free and see which canonical domain it points to.',
                'h1a' => 'Where does the', 'h1b' => 'alias point?',
                'lead' => 'Enter a subdomain (like www) to see the target its CNAME record points to.',
                'intro' => 'A CNAME record links one name as an "alias" to another name. For example www.example.com can point via CNAME to example.com so both are managed together. CNAMEs are widely used to connect a domain to a CDN, email service or cloud platform.',
                'faq' => [
                    ['q' => 'What is a CNAME record?', 'a' => 'A record that points one hostname as an alias to another canonical name.'],
                    ['q' => 'CNAME vs A record?', 'a' => 'An A record points to an IP; a CNAME points to another hostname and needs no update when the IP changes.'],
                    ['q' => 'Why can\'t the root domain use CNAME?', 'a' => 'By standard, the root (no www) can\'t have a CNAME; use an A or ALIAS record instead.'],
                ],
            ],
            'tr' => [
                't' => 'CNAME Kaydı', 'placeholder' => 'www.example.com',
                'meta_t' => 'CNAME Kaydı Sorgulama — Alan Adı Takma Adı',
                'meta_d' => 'Herhangi bir alt alan adının CNAME kaydını ücretsiz sorgulayın ve hangi ana alan adına işaret ettiğini görün.',
                'h1a' => 'Takma ad', 'h1b' => 'nereye gidiyor?',
                'lead' => 'CNAME kaydının işaret ettiği hedefi görmek için bir alt alan adı (www gibi) girin.',
                'intro' => 'CNAME kaydı bir adı başka bir ada "takma ad" olarak bağlar. Örneğin www.example.com, CNAME ile example.com\'a işaret edebilir.',
                'faq' => [
                    ['q' => 'CNAME kaydı nedir?', 'a' => 'Bir ana bilgisayar adını başka bir ada takma ad olarak yönlendiren kayıt.'],
                    ['q' => 'CNAME ile A farkı nedir?', 'a' => 'A kaydı IP\'ye, CNAME başka bir ana bilgisayar adına işaret eder.'],
                    ['q' => 'Kök alan adı neden CNAME kullanamaz?', 'a' => 'Standart gereği kök alan adı CNAME\'e sahip olamaz; A veya ALIAS kullanın.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- SOA */
        'soa' => [
            'group' => 'records', 'icon' => 'wrench', 'kind' => 'dns', 'rr' => 'SOA', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد SOA', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد SOA — استعلام اطلاعات مرجع زون DNS',
                'meta_d' => 'رکورد SOA دامنه را رایگان ببینید؛ نیم‌سرور اصلی، ایمیل مدیر، سریال زون و زمان‌بندی رفرش و انقضا.',
                'h1a' => 'قلب زون DNS', 'h1b' => 'را ببینید.',
                'lead' => 'دامنه را وارد کنید تا رکورد SOA شامل نیم‌سرور اصلی، سریال و زمان‌بندی همگام‌سازی زون را ببینید.',
                'intro' => 'رکورد SOA (Start of Authority) اطلاعات پایه‌ی هر زون DNS را نگه می‌دارد: نیم‌سرور اصلی، ایمیل مدیر، شماره سریال (که با هر تغییر باید افزایش یابد) و بازه‌های refresh، retry و expire. این رکورد برای همگام‌سازی بین نیم‌سرورهای اصلی و ثانویه حیاتی است.',
                'faq' => [
                    ['q' => 'رکورد SOA چیست؟', 'a' => 'رکورد پایه‌ی هر زون که مرجع و پارامترهای همگام‌سازی DNS را تعریف می‌کند.'],
                    ['q' => 'سریال SOA چه کاربردی دارد؟', 'a' => 'نسخه‌ی زون است؛ نیم‌سرورهای ثانویه با دیدن سریال بالاتر می‌فهمند باید به‌روزرسانی کنند.'],
                    ['q' => 'هر زون چند SOA دارد؟', 'a' => 'دقیقاً یکی؛ SOA همیشه اولین رکورد یک زون است.'],
                ],
            ],
            'en' => [
                't' => 'SOA Record', 'placeholder' => 'example.com',
                'meta_t' => 'SOA Record Lookup — Check a DNS Zone\'s Authority',
                'meta_d' => 'View a domain\'s SOA record for free — primary nameserver, admin email, zone serial and the refresh/retry/expire timers.',
                'h1a' => 'See the heart of', 'h1b' => 'the DNS zone.',
                'lead' => 'Enter a domain to see its SOA record — primary nameserver, serial and zone synchronization timers.',
                'intro' => 'The SOA (Start of Authority) record holds the core details of every DNS zone: the primary nameserver, the admin email, a serial number (which must increase on every change), and the refresh, retry and expire intervals. This record is essential for syncing primary and secondary nameservers.',
                'faq' => [
                    ['q' => 'What is an SOA record?', 'a' => 'The base record of every zone that defines DNS authority and synchronization parameters.'],
                    ['q' => 'What is the SOA serial for?', 'a' => 'It\'s the zone\'s version; secondary nameservers see a higher serial and know to update.'],
                    ['q' => 'How many SOA records per zone?', 'a' => 'Exactly one; the SOA is always the first record of a zone.'],
                ],
            ],
            'tr' => [
                't' => 'SOA Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'SOA Kaydı Sorgulama — DNS Bölgesi Yetkisi',
                'meta_d' => 'Bir alan adının SOA kaydını ücretsiz görüntüleyin — birincil ad sunucusu, seri numarası ve zamanlayıcılar.',
                'h1a' => 'DNS bölgesinin', 'h1b' => 'kalbini görün.',
                'lead' => 'SOA kaydını görmek için bir alan adı girin.',
                'intro' => 'SOA (Start of Authority) kaydı, her DNS bölgesinin temel ayrıntılarını tutar: birincil ad sunucusu, yönetici e-postası, seri numarası ve zamanlayıcılar.',
                'faq' => [
                    ['q' => 'SOA kaydı nedir?', 'a' => 'Her bölgenin DNS yetkisini ve senkronizasyon parametrelerini tanımlayan temel kayıt.'],
                    ['q' => 'SOA serisi ne işe yarar?', 'a' => 'Bölgenin sürümüdür; ikincil sunucular güncellemeyi bilir.'],
                    ['q' => 'Bölge başına kaç SOA vardır?', 'a' => 'Tam olarak bir tane.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- CAA */
        'caa' => [
            'group' => 'records', 'icon' => 'shield', 'kind' => 'dns', 'rr' => 'CAA', 'input' => 'domain',
            'fa' => [
                't' => 'رکورد CAA', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی رکورد CAA — کنترل صدور گواهی SSL',
                'meta_d' => 'رکورد CAA دامنه را رایگان استعلام کنید و ببینید کدام مراجع صدور گواهی مجاز به صدور SSL برای دامنه‌ی شما هستند.',
                'h1a' => 'چه کسی مجاز', 'h1b' => 'به صدور SSL است؟',
                'lead' => 'دامنه را وارد کنید تا ببینید رکورد CAA کدام مراجع صدور گواهی (CA) را برای دامنه‌ی شما مجاز کرده است.',
                'intro' => 'رکورد CAA به شما اجازه می‌دهد مشخص کنید فقط چه مراجع صدور گواهی (مثل Let\'s Encrypt یا DigiCert) حق صدور گواهی SSL برای دامنه‌ی شما را دارند. این یک لایه‌ی امنیتی مهم برای جلوگیری از صدور گواهی جعلی است. نبود رکورد CAA یعنی هر CA می‌تواند گواهی صادر کند.',
                'faq' => [
                    ['q' => 'رکورد CAA چیست؟', 'a' => 'رکوردی که تعیین می‌کند کدام مراجع صدور گواهی مجاز به صدور SSL برای دامنه هستند.'],
                    ['q' => 'آیا داشتن CAA ضروری است؟', 'a' => 'اجباری نیست اما به‌شدت توصیه می‌شود؛ جلوی صدور گواهی غیرمجاز را می‌گیرد.'],
                    ['q' => 'اگر CAA نداشته باشم چه می‌شود؟', 'a' => 'هر مرجع صدور گواهی می‌تواند برای دامنه‌ی شما SSL صادر کند که از نظر امنیتی ایده‌آل نیست.'],
                ],
            ],
            'en' => [
                't' => 'CAA Record', 'placeholder' => 'example.com',
                'meta_t' => 'CAA Record Lookup — Control SSL Certificate Issuance',
                'meta_d' => 'Look up a domain\'s CAA record for free and see which certificate authorities are allowed to issue SSL for your domain.',
                'h1a' => 'Who may issue', 'h1b' => 'your SSL?',
                'lead' => 'Enter a domain to see which certificate authorities (CAs) its CAA record allows to issue SSL.',
                'intro' => 'The CAA record lets you specify exactly which certificate authorities (like Let\'s Encrypt or DigiCert) are allowed to issue SSL certificates for your domain. It\'s an important security layer against mis-issued certificates. No CAA record means any CA can issue a certificate.',
                'faq' => [
                    ['q' => 'What is a CAA record?', 'a' => 'A record that defines which certificate authorities may issue SSL for a domain.'],
                    ['q' => 'Is a CAA record required?', 'a' => 'Not mandatory but strongly recommended; it prevents unauthorized certificate issuance.'],
                    ['q' => 'What if I have no CAA record?', 'a' => 'Any certificate authority can issue SSL for your domain, which is not ideal for security.'],
                ],
            ],
            'tr' => [
                't' => 'CAA Kaydı', 'placeholder' => 'example.com',
                'meta_t' => 'CAA Kaydı Sorgulama — SSL Sertifika Kontrolü',
                'meta_d' => 'Bir alan adının CAA kaydını ücretsiz sorgulayın ve hangi sertifika yetkililerinin SSL verebileceğini görün.',
                'h1a' => 'SSL\'i kim', 'h1b' => 'verebilir?',
                'lead' => 'CAA kaydının hangi sertifika yetkililerine (CA) izin verdiğini görmek için bir alan adı girin.',
                'intro' => 'CAA kaydı, alan adınız için hangi sertifika yetkililerinin SSL verebileceğini belirtmenizi sağlar. Yanlış verilen sertifikalara karşı önemli bir güvenlik katmanıdır.',
                'faq' => [
                    ['q' => 'CAA kaydı nedir?', 'a' => 'Bir alan adı için hangi sertifika yetkililerinin SSL verebileceğini tanımlayan kayıt.'],
                    ['q' => 'CAA kaydı gerekli mi?', 'a' => 'Zorunlu değil ama şiddetle önerilir.'],
                    ['q' => 'CAA kaydım yoksa ne olur?', 'a' => 'Herhangi bir sertifika yetkilisi alan adınız için SSL verebilir.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- Reverse DNS */
        'reverse' => [
            'group' => 'network', 'icon' => 'globe', 'kind' => 'reverse', 'input' => 'ip',
            'fa' => [
                't' => 'Reverse DNS', 'placeholder' => '8.8.8.8',
                'meta_t' => 'بررسی Reverse DNS (PTR) — استعلام نام از روی IP',
                'meta_d' => 'رکورد PTR (Reverse DNS) هر IP را رایگان استعلام کنید و ببینید آن آدرس به کدام نام دامنه بازمی‌گردد؛ مهم برای اعتبار ایمیل.',
                'h1a' => 'از IP به', 'h1b' => 'نام دامنه.',
                'lead' => 'یک آدرس IP وارد کنید تا نام دامنه‌ی متناظر (رکورد PTR) آن را ببینید.',
                'intro' => 'Reverse DNS برعکس جستجوی معمولی عمل می‌کند: از یک آدرس IP به نام دامنه می‌رسد. رکورد PTR درست به‌ویژه برای سرورهای ایمیل حیاتی است؛ بسیاری از سرویس‌ها ایمیلی را که IP فرستنده‌اش PTR معتبر ندارد، اسپم یا رد می‌کنند.',
                'faq' => [
                    ['q' => 'Reverse DNS چیست؟', 'a' => 'جستجوی معکوس که از یک IP به نام دامنه می‌رسد؛ با رکورد PTR انجام می‌شود.'],
                    ['q' => 'چرا PTR برای ایمیل مهم است؟', 'a' => 'سرورهای گیرنده اغلب اعتبار فرستنده را با تطبیق PTR و نام میزبان می‌سنجند؛ نبود آن یعنی احتمال اسپم‌شدن.'],
                    ['q' => 'چه کسی PTR را تنظیم می‌کند؟', 'a' => 'صاحب بلوک IP (معمولاً دیتاسنتر یا ارائه‌دهنده‌ی سرور)، نه صاحب دامنه.'],
                ],
            ],
            'en' => [
                't' => 'Reverse DNS', 'placeholder' => '8.8.8.8',
                'meta_t' => 'Reverse DNS (PTR) Lookup — Name From an IP',
                'meta_d' => 'Look up any IP\'s PTR (reverse DNS) record for free and see which hostname it resolves back to — important for email reputation.',
                'h1a' => 'From IP to', 'h1b' => 'hostname.',
                'lead' => 'Enter an IP address to see the hostname (PTR record) it maps back to.',
                'intro' => 'Reverse DNS works the opposite of a normal lookup: it goes from an IP address to a hostname. A correct PTR record is especially critical for mail servers — many services flag or reject email whose sending IP has no valid PTR.',
                'faq' => [
                    ['q' => 'What is reverse DNS?', 'a' => 'A reverse lookup that resolves an IP back to a hostname, using the PTR record.'],
                    ['q' => 'Why is PTR important for email?', 'a' => 'Receiving servers often check sender legitimacy by matching PTR to hostname; missing it risks being marked spam.'],
                    ['q' => 'Who sets the PTR record?', 'a' => 'The owner of the IP block (usually the datacenter or server provider), not the domain owner.'],
                ],
            ],
            'tr' => [
                't' => 'Reverse DNS', 'placeholder' => '8.8.8.8',
                'meta_t' => 'Reverse DNS (PTR) Sorgulama — IP\'den Ad',
                'meta_d' => 'Herhangi bir IP\'nin PTR (ters DNS) kaydını ücretsiz sorgulayın ve hangi ana bilgisayar adına çözümlendiğini görün.',
                'h1a' => 'IP\'den', 'h1b' => 'ana bilgisayara.',
                'lead' => 'Eşlendiği ana bilgisayar adını (PTR kaydı) görmek için bir IP adresi girin.',
                'intro' => 'Ters DNS, normal aramanın tersini yapar: bir IP adresinden ana bilgisayar adına gider. Doğru bir PTR kaydı özellikle posta sunucuları için kritiktir.',
                'faq' => [
                    ['q' => 'Ters DNS nedir?', 'a' => 'PTR kaydını kullanarak bir IP\'yi ana bilgisayar adına çözen ters arama.'],
                    ['q' => 'PTR e-posta için neden önemli?', 'a' => 'Alıcı sunucular gönderen meşruiyetini PTR ile kontrol eder.'],
                    ['q' => 'PTR kaydını kim ayarlar?', 'a' => 'IP bloğunun sahibi (genellikle veri merkezi).'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- DNSSEC */
        'dnssec' => [
            'group' => 'network', 'icon' => 'shield', 'kind' => 'dnssec', 'input' => 'domain',
            'fa' => [
                't' => 'بررسی DNSSEC', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی DNSSEC دامنه — تست امنیت امضای DNS',
                'meta_d' => 'وضعیت DNSSEC دامنه را رایگان بررسی کنید؛ ببینید امضای امنیتی DNS فعال و معتبر است و رکوردهای DS و DNSKEY وجود دارند.',
                'h1a' => 'DNS شما', 'h1b' => 'امضا شده است؟',
                'lead' => 'دامنه را وارد کنید تا ببینید DNSSEC فعال است، پاسخ‌ها authenticated هستند و رکوردهای DS/DNSKEY وجود دارند.',
                'intro' => 'DNSSEC با امضای دیجیتال رکوردهای DNS مانع جعل و مسموم‌سازی کش (DNS spoofing) می‌شود. وقتی DNSSEC فعال باشد، resolverها می‌توانند اطمینان یابند پاسخی که دریافت می‌کنند دستکاری نشده است. این ابزار وجود امضای معتبر (flag AD) و رکوردهای DS و DNSKEY را بررسی می‌کند.',
                'faq' => [
                    ['q' => 'DNSSEC چیست؟', 'a' => 'لایه‌ای امنیتی که رکوردهای DNS را امضای دیجیتال می‌کند تا از جعل و دستکاری جلوگیری شود.'],
                    ['q' => 'flag AD یعنی چه؟', 'a' => 'Authenticated Data؛ یعنی resolver توانسته صحت امضای DNSSEC پاسخ را تأیید کند.'],
                    ['q' => 'چطور DNSSEC را فعال کنم؟', 'a' => 'در پنل DNS آن را روشن کرده و رکورد DS را نزد ثبت‌کننده‌ی دامنه ثبت کنید؛ تیم سرورنت راهنمایی می‌کند.'],
                ],
            ],
            'en' => [
                't' => 'DNSSEC Check', 'placeholder' => 'example.com',
                'meta_t' => 'DNSSEC Check — Test a Domain\'s DNS Signing',
                'meta_d' => 'Check any domain\'s DNSSEC status for free — see whether DNS signing is enabled and valid, and whether DS and DNSKEY records exist.',
                'h1a' => 'Is your DNS', 'h1b' => 'signed?',
                'lead' => 'Enter a domain to see whether DNSSEC is enabled, responses are authenticated and DS/DNSKEY records exist.',
                'intro' => 'DNSSEC digitally signs DNS records to prevent forgery and cache poisoning (DNS spoofing). When DNSSEC is enabled, resolvers can be sure the answer they receive hasn\'t been tampered with. This tool checks for a valid signature (the AD flag) and for DS and DNSKEY records.',
                'faq' => [
                    ['q' => 'What is DNSSEC?', 'a' => 'A security layer that digitally signs DNS records to prevent forgery and tampering.'],
                    ['q' => 'What does the AD flag mean?', 'a' => 'Authenticated Data — the resolver was able to verify the DNSSEC signature of the response.'],
                    ['q' => 'How do I enable DNSSEC?', 'a' => 'Turn it on in your DNS panel and register the DS record with your domain registrar; ServerNet can guide you.'],
                ],
            ],
            'tr' => [
                't' => 'DNSSEC Kontrolü', 'placeholder' => 'example.com',
                'meta_t' => 'DNSSEC Kontrolü — Alan Adı DNS İmzalama Testi',
                'meta_d' => 'Herhangi bir alan adının DNSSEC durumunu ücretsiz kontrol edin — DNS imzalamanın etkin ve geçerli olup olmadığını görün.',
                'h1a' => 'DNS\'iniz', 'h1b' => 'imzalı mı?',
                'lead' => 'DNSSEC\'in etkin olup olmadığını görmek için bir alan adı girin.',
                'intro' => 'DNSSEC, sahteciliği ve önbellek zehirlenmesini önlemek için DNS kayıtlarını dijital olarak imzalar. Bu araç geçerli bir imza (AD bayrağı) ve DS/DNSKEY kayıtlarını kontrol eder.',
                'faq' => [
                    ['q' => 'DNSSEC nedir?', 'a' => 'DNS kayıtlarını sahteciliğe karşı imzalayan güvenlik katmanı.'],
                    ['q' => 'AD bayrağı ne demek?', 'a' => 'Authenticated Data — çözümleyici DNSSEC imzasını doğruladı.'],
                    ['q' => 'DNSSEC\'i nasıl etkinleştiririm?', 'a' => 'DNS panelinizde açın ve DS kaydını kayıt kuruluşunuza kaydedin.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- Propagation */
        'propagation' => [
            'group' => 'network', 'icon' => 'zap', 'kind' => 'propagation', 'input' => 'domain',
            'fa' => [
                't' => 'انتشار DNS', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی انتشار DNS — تست Propagation جهانی رکورد',
                'meta_d' => 'انتشار DNS دامنه را روی چند resolver جهانی (گوگل، کلادفلر، Quad9) بررسی کنید و ببینید تغییرات DNS همه‌جا اعمال شده‌اند یا نه.',
                'h1a' => 'تغییرات DNS', 'h1b' => 'جهانی شدند؟',
                'lead' => 'دامنه را وارد کنید تا رکورد آن را روی چند resolver عمومی جهان هم‌زمان مقایسه و وضعیت انتشار را ببینید.',
                'intro' => 'وقتی رکورد DNS را تغییر می‌دهید، این تغییر باید در resolverهای سراسر جهان منتشر شود که به‌خاطر کش ممکن است تا ۴۸ ساعت طول بکشد. این ابزار رکورد شما را هم‌زمان از چند resolver عمومی معتبر (گوگل، کلادفلر، Quad9) می‌پرسد تا ببینید همه پاسخ یکسان می‌دهند یا هنوز در حال انتشار است.',
                'faq' => [
                    ['q' => 'انتشار DNS چیست؟', 'a' => 'فرایند اعمال‌شدن یک تغییر DNS در resolverهای سراسر جهان که به‌خاطر کش زمان‌بر است.'],
                    ['q' => 'چرا نتایج متفاوت‌اند؟', 'a' => 'یعنی تغییر هنوز کامل منتشر نشده؛ بعضی resolverها هنوز نسخه‌ی قدیمی را کش دارند.'],
                    ['q' => 'انتشار چقدر طول می‌کشد؟', 'a' => 'بسته به TTL معمولاً چند دقیقه تا ۴۸ ساعت؛ TTL پایین‌تر یعنی سریع‌تر.'],
                ],
            ],
            'en' => [
                't' => 'DNS Propagation', 'placeholder' => 'example.com',
                'meta_t' => 'DNS Propagation Check — Global Record Test',
                'meta_d' => 'Check a domain\'s DNS propagation across multiple global resolvers (Google, Cloudflare, Quad9) and see whether your DNS changes are live everywhere.',
                'h1a' => 'Are DNS changes', 'h1b' => 'live worldwide?',
                'lead' => 'Enter a domain to compare its record across several public resolvers at once and see the propagation status.',
                'intro' => 'When you change a DNS record, it must propagate to resolvers around the world, which can take up to 48 hours because of caching. This tool queries your record simultaneously from several trusted public resolvers (Google, Cloudflare, Quad9) to show whether they all agree or it\'s still propagating.',
                'faq' => [
                    ['q' => 'What is DNS propagation?', 'a' => 'The process of a DNS change taking effect across resolvers worldwide, delayed by caching.'],
                    ['q' => 'Why are the results different?', 'a' => 'The change hasn\'t fully propagated yet — some resolvers still have the old version cached.'],
                    ['q' => 'How long does propagation take?', 'a' => 'Depending on the TTL, usually a few minutes to 48 hours; a lower TTL is faster.'],
                ],
            ],
            'tr' => [
                't' => 'DNS Yayılımı', 'placeholder' => 'example.com',
                'meta_t' => 'DNS Yayılımı Kontrolü — Küresel Kayıt Testi',
                'meta_d' => 'Bir alan adının DNS yayılımını birden çok küresel çözümleyicide (Google, Cloudflare, Quad9) kontrol edin.',
                'h1a' => 'DNS değişiklikleri', 'h1b' => 'yayıldı mı?',
                'lead' => 'Kaydını birden çok genel çözümleyicide karşılaştırmak için bir alan adı girin.',
                'intro' => 'Bir DNS kaydını değiştirdiğinizde, önbellek nedeniyle dünya çapında çözümleyicilere yayılması 48 saate kadar sürebilir. Bu araç kaydınızı birkaç güvenilir genel çözümleyiciden aynı anda sorgular.',
                'faq' => [
                    ['q' => 'DNS yayılımı nedir?', 'a' => 'Bir DNS değişikliğinin dünya çapında çözümleyicilerde etkili olma süreci.'],
                    ['q' => 'Sonuçlar neden farklı?', 'a' => 'Değişiklik henüz tam yayılmadı; bazı çözümleyiciler eski sürümü önbellekte tutuyor.'],
                    ['q' => 'Yayılma ne kadar sürer?', 'a' => 'TTL\'e bağlı olarak birkaç dakika ile 48 saat arası.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- SSL */
        'ssl' => [
            'group' => 'network', 'icon' => 'lock', 'kind' => 'ssl', 'input' => 'domain',
            'fa' => [
                't' => 'بررسی SSL', 'placeholder' => 'example.com',
                'meta_t' => 'بررسی گواهی SSL دامنه — تست اعتبار و انقضای HTTPS',
                'meta_d' => 'گواهی SSL/TLS هر دامنه را رایگان بررسی کنید؛ اعتبار، تاریخ انقضا، صادرکننده، دامنه‌های تحت پوشش و روزهای باقی‌مانده را ببینید.',
                'h1a' => 'گواهی HTTPS', 'h1b' => 'سالم است؟',
                'lead' => 'دامنه را وارد کنید تا اعتبار گواهی SSL، تاریخ انقضا، صادرکننده و دامنه‌های تحت پوشش آن را ببینید.',
                'intro' => 'گواهی SSL/TLS اتصال کاربران به سایت شما را رمزنگاری می‌کند و قفل سبز مرورگر را فعال می‌سازد. گواهی منقضی یا نامعتبر باعث هشدار امنیتی مرورگر و فرار کاربر می‌شود. این ابزار گواهی را مستقیم از سرور می‌خواند و اعتبار، انقضا، صادرکننده و دامنه‌های تحت پوشش (SAN) را نشان می‌دهد.',
                'faq' => [
                    ['q' => 'این ابزار چه چیزی را بررسی می‌کند؟', 'a' => 'اعتبار فعلی گواهی، تاریخ صدور و انقضا، مرجع صادرکننده و همه‌ی دامنه‌های تحت پوشش گواهی.'],
                    ['q' => 'گواهی من چند روز دیگر منقضی می‌شود؟', 'a' => 'در نتیجه، «روزهای باقی‌مانده» را می‌بینید؛ بهتر است پیش از ۱۵ روز مانده تمدید کنید.'],
                    ['q' => 'اگر گواهی منقضی شده باشد چه کنم؟', 'a' => 'باید فوراً تمدید یا صادر مجدد شود؛ سرورنت گواهی رایگان و پولی نصب می‌کند.'],
                ],
            ],
            'en' => [
                't' => 'SSL Check', 'placeholder' => 'example.com',
                'meta_t' => 'SSL Certificate Check — Test HTTPS Validity & Expiry',
                'meta_d' => 'Check any domain\'s SSL/TLS certificate for free — validity, expiry date, issuer, covered domains and days remaining.',
                'h1a' => 'Is your HTTPS', 'h1b' => 'certificate healthy?',
                'lead' => 'Enter a domain to see its SSL certificate validity, expiry date, issuer and the domains it covers.',
                'intro' => 'An SSL/TLS certificate encrypts your visitors\' connection and enables the browser\'s padlock. An expired or invalid certificate triggers a browser security warning and drives users away. This tool reads the certificate straight from the server and shows validity, expiry, issuer and the covered domains (SAN).',
                'faq' => [
                    ['q' => 'What does this tool check?', 'a' => 'The certificate\'s current validity, issue and expiry dates, issuing authority and all covered domains.'],
                    ['q' => 'How many days until my certificate expires?', 'a' => 'The result shows "days remaining"; renew before 15 days are left.'],
                    ['q' => 'What if my certificate is expired?', 'a' => 'Renew or reissue it immediately; ServerNet installs both free and paid certificates.'],
                ],
            ],
            'tr' => [
                't' => 'SSL Kontrolü', 'placeholder' => 'example.com',
                'meta_t' => 'SSL Sertifika Kontrolü — HTTPS Geçerlilik Testi',
                'meta_d' => 'Herhangi bir alan adının SSL/TLS sertifikasını ücretsiz kontrol edin — geçerlilik, son kullanma tarihi, veren ve kapsanan alan adları.',
                'h1a' => 'HTTPS sertifikanız', 'h1b' => 'sağlıklı mı?',
                'lead' => 'SSL sertifikası geçerliliğini görmek için bir alan adı girin.',
                'intro' => 'SSL/TLS sertifikası ziyaretçilerinizin bağlantısını şifreler ve tarayıcının kilidini etkinleştirir. Süresi dolmuş bir sertifika güvenlik uyarısı tetikler. Bu araç sertifikayı doğrudan sunucudan okur.',
                'faq' => [
                    ['q' => 'Bu araç neyi kontrol eder?', 'a' => 'Sertifikanın geçerliliği, tarihleri, veren yetkili ve kapsanan alan adları.'],
                    ['q' => 'Sertifikam kaç gün sonra dolacak?', 'a' => 'Sonuç "kalan gün" gösterir; 15 günden önce yenileyin.'],
                    ['q' => 'Sertifikam dolduysa ne yapmalıyım?', 'a' => 'Hemen yenileyin; ServerNet ücretsiz ve ücretli sertifika kurar.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- Ports */
        'ports' => [
            'group' => 'network', 'icon' => 'server', 'kind' => 'ports', 'input' => 'host',
            'fa' => [
                't' => 'اسکن پورت', 'placeholder' => 'example.com',
                'meta_t' => 'اسکن پورت آنلاین — بررسی باز بودن پورت‌های سرور',
                'meta_d' => 'پورت‌های پرکاربرد یک سرور یا دامنه را رایگان اسکن کنید؛ ببینید کدام پورت‌ها (SSH، HTTP، HTTPS، FTP، دیتابیس و…) باز هستند.',
                'h1a' => 'کدام پورت‌ها', 'h1b' => 'باز هستند؟',
                'lead' => 'دامنه یا IP را وارد کنید تا وضعیت باز/بسته بودن پورت‌های پرکاربرد سرور را ببینید.',
                'intro' => 'اسکن پورت نشان می‌دهد کدام سرویس‌ها روی سرور شما از بیرون قابل‌دسترس‌اند. پورت باز یعنی سرویسی روی آن گوش می‌دهد (مثلاً 443 برای HTTPS). پورت‌های غیرضروریِ باز سطح حمله را افزایش می‌دهند؛ این ابزار پورت‌های رایج را بررسی می‌کند تا از امن‌بودن سرورتان مطمئن شوید.',
                'faq' => [
                    ['q' => 'اسکن پورت چه می‌کند؟', 'a' => 'بررسی می‌کند کدام پورت‌های TCP روی سرور از بیرون باز و قابل‌اتصال هستند.'],
                    ['q' => 'کدام پورت‌ها بررسی می‌شوند؟', 'a' => 'پورت‌های پرکاربرد مانند SSH(22)، HTTP(80)، HTTPS(443)، FTP(21)، ایمیل و دیتابیس.'],
                    ['q' => 'آیا اسکن پورت قانونی است؟', 'a' => 'اسکن سرور خودتان کاملاً مجاز است؛ برای عیب‌یابی و بررسی امنیت استفاده کنید.'],
                ],
            ],
            'en' => [
                't' => 'Port Scan', 'placeholder' => 'example.com',
                'meta_t' => 'Online Port Scanner — Check Open Server Ports',
                'meta_d' => 'Scan a server or domain\'s common ports for free — see which ports (SSH, HTTP, HTTPS, FTP, database and more) are open.',
                'h1a' => 'Which ports', 'h1b' => 'are open?',
                'lead' => 'Enter a domain or IP to see which common server ports are open or closed.',
                'intro' => 'A port scan shows which services on your server are reachable from outside. An open port means a service is listening on it (e.g. 443 for HTTPS). Unnecessary open ports increase your attack surface — this tool checks the common ports so you can confirm your server is secure.',
                'faq' => [
                    ['q' => 'What does a port scan do?', 'a' => 'It checks which TCP ports on a server are open and reachable from the outside.'],
                    ['q' => 'Which ports are checked?', 'a' => 'Common ports like SSH(22), HTTP(80), HTTPS(443), FTP(21), email and database ports.'],
                    ['q' => 'Is port scanning legal?', 'a' => 'Scanning your own server is perfectly fine; use it for troubleshooting and security checks.'],
                ],
            ],
            'tr' => [
                't' => 'Port Tarama', 'placeholder' => 'example.com',
                'meta_t' => 'Çevrimiçi Port Tarayıcı — Açık Sunucu Portları',
                'meta_d' => 'Bir sunucunun veya alan adının yaygın portlarını ücretsiz tarayın — hangi portların açık olduğunu görün.',
                'h1a' => 'Hangi portlar', 'h1b' => 'açık?',
                'lead' => 'Hangi yaygın sunucu portlarının açık olduğunu görmek için bir alan adı veya IP girin.',
                'intro' => 'Port taraması, sunucunuzdaki hangi hizmetlerin dışarıdan erişilebilir olduğunu gösterir. Gereksiz açık portlar saldırı yüzeyini artırır.',
                'faq' => [
                    ['q' => 'Port taraması ne yapar?', 'a' => 'Bir sunucudaki hangi TCP portlarının açık olduğunu kontrol eder.'],
                    ['q' => 'Hangi portlar kontrol edilir?', 'a' => 'SSH(22), HTTP(80), HTTPS(443), FTP(21) gibi yaygın portlar.'],
                    ['q' => 'Port taraması yasal mı?', 'a' => 'Kendi sunucunuzu taramak tamamen sorunsuzdur.'],
                ],
            ],
        ],

        /* ------------------------------------------------------------- Ping */
        'ping' => [
            'group' => 'network', 'icon' => 'zap', 'kind' => 'ping', 'input' => 'host',
            'fa' => [
                't' => 'پینگ و تأخیر', 'placeholder' => 'example.com',
                'meta_t' => 'تست پینگ آنلاین — بررسی تأخیر و در دسترس بودن سرور',
                'meta_d' => 'پینگ و تأخیر (latency) هر سرور یا دامنه را رایگان بسنجید؛ زمان پاسخ کمینه، میانگین، بیشینه و درصد از دست رفتن بسته را ببینید.',
                'h1a' => 'سرور چقدر', 'h1b' => 'سریع پاسخ می‌دهد؟',
                'lead' => 'دامنه یا IP را وارد کنید تا تأخیر اتصال (پینگ)، پایداری و در دسترس بودن سرور را بسنجید.',
                'intro' => 'پینگ زمان رفت‌وبرگشت داده بین شما و سرور را می‌سنجد؛ عدد کمتر یعنی سرور سریع‌تر و کاربر راضی‌تر. این ابزار با اتصال TCP به سرور، تأخیر کمینه، میانگین و بیشینه را در چند تلاش اندازه می‌گیرد و پایداری اتصال را نشان می‌دهد.',
                'faq' => [
                    ['q' => 'پینگ چیست؟', 'a' => 'زمان رفت‌وبرگشت داده (به میلی‌ثانیه) بین شما و سرور؛ معیار سرعت پاسخ‌دهی.'],
                    ['q' => 'پینگ خوب چند است؟', 'a' => 'زیر ۵۰ms عالی، ۵۰ تا ۱۰۰ms خوب، بالای ۲۰۰ms کند محسوب می‌شود.'],
                    ['q' => 'چرا از latency به‌جای ICMP استفاده می‌کنید؟', 'a' => 'برای دقت و پایداری، تأخیر را با اتصال واقعی TCP به سرویس سرور می‌سنجیم که واقع‌بینانه‌تر از پینگ ICMP است.'],
                ],
            ],
            'en' => [
                't' => 'Ping & Latency', 'placeholder' => 'example.com',
                'meta_t' => 'Online Ping Test — Check Server Latency & Uptime',
                'meta_d' => 'Measure any server or domain\'s ping and latency for free — see minimum, average and maximum response time plus packet loss.',
                'h1a' => 'How fast does', 'h1b' => 'the server respond?',
                'lead' => 'Enter a domain or IP to measure connection latency (ping), stability and whether the server is reachable.',
                'intro' => 'Ping measures the round-trip time of data between you and a server; a lower number means a faster server and happier users. This tool connects to the server over TCP and measures minimum, average and maximum latency across several attempts, showing connection stability.',
                'faq' => [
                    ['q' => 'What is ping?', 'a' => 'The round-trip time of data (in milliseconds) between you and a server — a measure of responsiveness.'],
                    ['q' => 'What is a good ping?', 'a' => 'Under 50ms is excellent, 50–100ms is good, over 200ms is considered slow.'],
                    ['q' => 'Why TCP latency instead of ICMP?', 'a' => 'For accuracy and reliability we measure latency with a real TCP connection to the server\'s service, which is more realistic than an ICMP ping.'],
                ],
            ],
            'tr' => [
                't' => 'Ping & Gecikme', 'placeholder' => 'example.com',
                'meta_t' => 'Çevrimiçi Ping Testi — Sunucu Gecikme Kontrolü',
                'meta_d' => 'Herhangi bir sunucunun ping ve gecikmesini ücretsiz ölçün — minimum, ortalama, maksimum yanıt süresi ve paket kaybı.',
                'h1a' => 'Sunucu ne kadar', 'h1b' => 'hızlı yanıt veriyor?',
                'lead' => 'Bağlantı gecikmesini (ping) ölçmek için bir alan adı veya IP girin.',
                'intro' => 'Ping, sizinle sunucu arasındaki verinin gidiş-dönüş süresini ölçer; daha düşük sayı daha hızlı sunucu demektir. Bu araç TCP üzerinden bağlanarak gecikmeyi ölçer.',
                'faq' => [
                    ['q' => 'Ping nedir?', 'a' => 'Sizinle sunucu arasındaki verinin gidiş-dönüş süresi (ms).'],
                    ['q' => 'İyi bir ping nedir?', 'a' => '50ms altı mükemmel, 50–100ms iyi, 200ms üstü yavaş.'],
                    ['q' => 'Neden ICMP yerine TCP?', 'a' => 'Doğruluk için gecikmeyi gerçek bir TCP bağlantısıyla ölçüyoruz.'],
                ],
            ],
        ],

    ],
];
