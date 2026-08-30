<?php

/*
|--------------------------------------------------------------------------
| ServerNet — ابزارهای جامع (هاب)
|--------------------------------------------------------------------------
| دو ابزار یکپارچه که همه‌ی زیرابزارهای DNS و شبکه را در یک صفحه جمع می‌کنند.
| صفحات تکی /lookup/{type} برای سئو باقی می‌مانند؛ این هاب‌ها تجربه‌ی
| یکپارچه و منوی تمیز می‌سازند.
|
| mode: dns (گزارش همه‌ی رکوردها) | network (تب‌های بررسی شبکه/امنیت)
*/

return [

    /* ------------------------------------------------------- گزارش کامل DNS */
    'dns' => [
        'icon' => 'db', 'mode' => 'dns',
        'fa' => [
            't' => 'بررسی کامل DNS', 'placeholder' => 'example.com', 'btn' => 'بررسی همه رکوردها',
            'meta_t' => 'بررسی کامل رکوردهای DNS دامنه — گزارش یکجا',
            'meta_d' => 'همه‌ی رکوردهای DNS یک دامنه را در یک گزارش ببینید؛ A، AAAA، MX، NS، TXT، CNAME، SOA و CAA یکجا، آنلاین و رایگان از resolver جهانی گوگل.',
            'h1a' => 'همه‌ی رکوردهای DNS دامنه،', 'h1b' => 'در یک نگاه.',
            'lead' => 'دامنه را وارد کنید تا همه‌ی رکوردهای مهم DNS — از IP و ایمیل تا نیم‌سرور و امنیت — در یک گزارش کامل و مرتب نمایش داده شوند.',
            'intro' => 'به‌جای بررسی تک‌تک رکوردها، این ابزار همه‌ی رکوردهای پرکاربرد DNS دامنه‌ی شما را در یک درخواست از resolver جهانی گوگل می‌خواند و کنار هم نشان می‌دهد: A و AAAA برای آدرس سرور، MX برای ایمیل، NS برای نیم‌سرور، TXT برای SPF و تأیید دامنه، CNAME، SOA و CAA. یک گزارش کامل برای عیب‌یابی سریع و بررسی سلامت DNS.',
            'faq' => [
                ['q' => 'این ابزار چه رکوردهایی را بررسی می‌کند؟', 'a' => 'همه‌ی رکوردهای پرکاربرد: A، AAAA، MX، NS، TXT، CNAME، SOA و CAA را یکجا در یک گزارش.'],
                ['q' => 'داده‌ها از کجا خوانده می‌شوند؟', 'a' => 'مستقیم از resolver جهانی گوگل (DNS-over-HTTPS)، پس نتیجه دقیق و مستقل از کش محلی شماست.'],
                ['q' => 'برای بررسی یک رکورد خاص چه کنم؟', 'a' => 'می‌توانید از صفحات تخصصی هر رکورد استفاده کنید؛ در انتهای همین صفحه به آن‌ها لینک داده‌ایم.'],
            ],
        ],
        'en' => [
            't' => 'Full DNS Lookup', 'placeholder' => 'example.com', 'btn' => 'Check all records',
            'meta_t' => 'Full DNS Records Lookup — All Records at Once',
            'meta_d' => 'See every DNS record of a domain in one report — A, AAAA, MX, NS, TXT, CNAME, SOA and CAA together, online and free from Google\'s global resolver.',
            'h1a' => 'Every DNS record of a domain,', 'h1b' => 'at a glance.',
            'lead' => 'Enter a domain to see all its important DNS records — from IP and email to nameservers and security — in one clean, complete report.',
            'intro' => 'Instead of checking records one by one, this tool reads all your domain\'s common DNS records in a single query from Google\'s global resolver and shows them side by side: A and AAAA for the server address, MX for email, NS for nameservers, TXT for SPF and domain verification, plus CNAME, SOA and CAA. A complete report for fast troubleshooting and DNS health checks.',
            'faq' => [
                ['q' => 'Which records does this tool check?', 'a' => 'All the common ones: A, AAAA, MX, NS, TXT, CNAME, SOA and CAA, together in one report.'],
                ['q' => 'Where is the data read from?', 'a' => 'Straight from Google\'s global resolver (DNS-over-HTTPS), so results are accurate and independent of your local cache.'],
                ['q' => 'How do I check a single record type?', 'a' => 'You can use the dedicated page for each record; we link to them at the bottom of this page.'],
            ],
        ],
        'tr' => [
            't' => 'Tam DNS Sorgu', 'placeholder' => 'example.com', 'btn' => 'Tüm kayıtları kontrol et',
            'meta_t' => 'Tam DNS Kayıt Sorgulama — Tüm Kayıtlar Bir Arada',
            'meta_d' => 'Bir alan adının tüm DNS kayıtlarını tek raporda görün — A, AAAA, MX, NS, TXT, CNAME, SOA ve CAA bir arada, ücretsiz.',
            'h1a' => 'Bir alan adının tüm DNS kayıtları,', 'h1b' => 'tek bakışta.',
            'lead' => 'Tüm önemli DNS kayıtlarını — IP ve e-postadan ad sunucularına ve güvenliğe — tek bir temiz raporda görmek için bir alan adı girin.',
            'intro' => 'Kayıtları tek tek kontrol etmek yerine, bu araç alan adınızın tüm yaygın DNS kayıtlarını Google\'ın küresel çözümleyicisinden tek sorguda okur ve yan yana gösterir: sunucu adresi için A ve AAAA, e-posta için MX, ad sunucuları için NS, SPF ve doğrulama için TXT, ayrıca CNAME, SOA ve CAA.',
            'faq' => [
                ['q' => 'Bu araç hangi kayıtları kontrol eder?', 'a' => 'Tüm yaygın kayıtlar: A, AAAA, MX, NS, TXT, CNAME, SOA ve CAA, tek raporda.'],
                ['q' => 'Veriler nereden okunuyor?', 'a' => 'Doğrudan Google\'ın küresel çözümleyicisinden (DNS-over-HTTPS), böylece sonuçlar yerel önbelleğinizden bağımsızdır.'],
                ['q' => 'Tek bir kayıt türünü nasıl kontrol ederim?', 'a' => 'Her kayıt için özel sayfayı kullanabilirsiniz; bu sayfanın altında bağlantı verdik.'],
            ],
        ],
    ],

    /* ------------------------------------------------------- شبکه و امنیت */
    'network' => [
        'icon' => 'shield', 'mode' => 'network',
        'checks' => ['ssl', 'dnssec', 'propagation', 'ping', 'ports', 'reverse'],
        'fa' => [
            't' => 'بررسی شبکه و امنیت', 'placeholder' => 'example.com', 'btn' => 'بررسی',
            'meta_t' => 'بررسی شبکه و امنیت دامنه — SSL، پورت، پینگ و DNSSEC',
            'meta_d' => 'ابزار جامع بررسی شبکه و امنیت: گواهی SSL، DNSSEC، انتشار DNS، پینگ، اسکن پورت و Reverse DNS، همه در یک صفحه، آنلاین و رایگان.',
            'h1a' => 'شبکه و امنیت دامنه را', 'h1b' => 'کامل بررسی کنید.',
            'lead' => 'یک دامنه یا IP وارد کنید و با یک کلیک بین بررسی‌های SSL، DNSSEC، انتشار DNS، پینگ، اسکن پورت و Reverse DNS جابه‌جا شوید.',
            'intro' => 'این ابزار همه‌ی بررسی‌های شبکه و امنیت را در یک صفحه جمع کرده است: اعتبار و انقضای گواهی SSL، وضعیت امضای DNSSEC، انتشار جهانی DNS، تأخیر و دسترس‌پذیری با پینگ، پورت‌های باز سرور و رکورد معکوس (PTR). کافی است دامنه را وارد و بررسی موردنظر را انتخاب کنید.',
            'faq' => [
                ['q' => 'چه بررسی‌هایی در این ابزار هست؟', 'a' => 'گواهی SSL، DNSSEC، انتشار DNS، پینگ و تأخیر، اسکن پورت و Reverse DNS — همه در یک صفحه.'],
                ['q' => 'اسکن پورت چه پورت‌هایی را بررسی می‌کند؟', 'a' => 'پورت‌های پرکاربرد سرور مثل SSH، HTTP، HTTPS، FTP، ایمیل و دیتابیس.'],
                ['q' => 'برای Reverse DNS چه چیزی وارد کنم؟', 'a' => 'برای بررسی معکوس یک آدرس IP وارد کنید تا نام دامنه‌ی متناظر (رکورد PTR) آن نمایش داده شود.'],
            ],
        ],
        'en' => [
            't' => 'Network & Security Check', 'placeholder' => 'example.com', 'btn' => 'Check',
            'meta_t' => 'Network & Security Check — SSL, Ports, Ping & DNSSEC',
            'meta_d' => 'An all-in-one network & security tool: SSL certificate, DNSSEC, DNS propagation, ping, port scan and reverse DNS — all on one page, online and free.',
            'h1a' => 'Check a domain\'s', 'h1b' => 'network & security in full.',
            'lead' => 'Enter a domain or IP and switch with one click between SSL, DNSSEC, DNS propagation, ping, port scan and reverse DNS checks.',
            'intro' => 'This tool brings every network and security check onto one page: SSL certificate validity and expiry, DNSSEC signing status, global DNS propagation, latency and reachability via ping, open server ports, and the reverse (PTR) record. Just enter a domain and pick the check you want.',
            'faq' => [
                ['q' => 'What checks are in this tool?', 'a' => 'SSL certificate, DNSSEC, DNS propagation, ping & latency, port scan and reverse DNS — all on one page.'],
                ['q' => 'Which ports does the scan check?', 'a' => 'Common server ports like SSH, HTTP, HTTPS, FTP, email and database ports.'],
                ['q' => 'What do I enter for reverse DNS?', 'a' => 'For a reverse lookup enter an IP address to see the hostname (PTR record) it maps to.'],
            ],
        ],
        'tr' => [
            't' => 'Ağ & Güvenlik Kontrolü', 'placeholder' => 'example.com', 'btn' => 'Kontrol et',
            'meta_t' => 'Ağ & Güvenlik Kontrolü — SSL, Port, Ping ve DNSSEC',
            'meta_d' => 'Hepsi bir arada ağ & güvenlik aracı: SSL sertifikası, DNSSEC, DNS yayılımı, ping, port tarama ve ters DNS — tek sayfada, ücretsiz.',
            'h1a' => 'Bir alan adının', 'h1b' => 'ağ ve güvenliğini tam kontrol edin.',
            'lead' => 'Bir alan adı veya IP girin ve SSL, DNSSEC, DNS yayılımı, ping, port tarama ve ters DNS kontrolleri arasında tek tıkla geçiş yapın.',
            'intro' => 'Bu araç tüm ağ ve güvenlik kontrollerini tek sayfada toplar: SSL sertifika geçerliliği ve bitişi, DNSSEC imzalama durumu, küresel DNS yayılımı, ping ile gecikme ve erişilebilirlik, açık sunucu portları ve ters (PTR) kayıt. Bir alan adı girin ve istediğiniz kontrolü seçin.',
            'faq' => [
                ['q' => 'Bu araçta hangi kontroller var?', 'a' => 'SSL sertifikası, DNSSEC, DNS yayılımı, ping ve gecikme, port tarama ve ters DNS — hepsi tek sayfada.'],
                ['q' => 'Tarama hangi portları kontrol eder?', 'a' => 'SSH, HTTP, HTTPS, FTP, e-posta ve veritabanı gibi yaygın sunucu portları.'],
                ['q' => 'Ters DNS için ne girmeliyim?', 'a' => 'Ters arama için, eşlendiği ana bilgisayar adını (PTR kaydı) görmek üzere bir IP adresi girin.'],
            ],
        ],
    ],

];
