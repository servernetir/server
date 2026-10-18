<?php

/*
|--------------------------------------------------------------------------
| لبهٔ سرورنت — CDN، DNS و WAF
|--------------------------------------------------------------------------
|
| منبعِ **یگانه**ی این حوزه. همان درسی که `config/billing.php` و
| `config/calendar.php` گران یاد دادند: هر مقداری که در مهاجرت، اعتبارسنجی،
| Blade و اسکریپتِ نود هم‌زمان ظاهر می‌شود باید یک جا تعریف شود، وگرنه روزی
| یکی‌شان جا می‌مانَد و خرابی **خاموش** است.
|
| ⚠️ مقدارهای این فایل به snapshotی می‌روند که نودهای لبه می‌خوانند. یعنی
| تغییرشان روی سرورهای زنده اثر دارد، نه فقط روی نمایشِ پنل.
*/

return [

    /*
    | نیم‌سرورهای **DNS مدیریت‌شده** — مشتری این‌ها را به رجیسترارش می‌دهد و
    | `DelegationCheck` تفویض را با همین فهرست می‌سنجد.
    |
    | 🔴 عمداً **جدا** از `ns1/ns2.servernet.cloud` است. آن دو به BINDِ سرورِ
    | cPanelِ core اشاره می‌کنند و zoneِ هاست‌ها را سرو می‌کنند
    | (`DomainZoneProvisioner`). دامنه‌ای که مشتری به آن دو بدهد هرگز به
    | PowerDNS نمی‌رسد و رکوردهایی که این‌جا ساخته، بی‌صدا نادیده گرفته می‌شوند.
    |
    | مقدارِ واقعی را مدیر در `/admin/dns` تنظیم می‌کند (`Setting: dns_nameservers`)؛
    | این فقط پیش‌فرض است. خواندن همیشه از `CdnZone::nameservers()`.
    |
    | 🔴 v2: این کلید دیگر منبعِ حقیقت **نیست** و هیچ کدی آن را نمی‌خواند؛ فقط
    | برای سازگاری مانده. منبعِ یگانه `dns.nameservers` پایین‌تر است که فقط
    | `App\Services\Dns\DnsConfig` می‌خواندش. نام‌های این‌جا هرگز به‌عنوانِ
    | پیش‌فرض به مشتری نشان داده نمی‌شوند.
    |
    | ⚠️ **تعداد** مهم است: رجیسترارها دست‌کم دو نیم‌سرور می‌خواهند. و دو
    | نیم‌سرور باید روی دو **شبکهٔ** جدا باشند — هر دو روی یک ماشین یعنی
    | خاموشیِ آن ماشین = خاموشیِ DNSِ همهٔ مشتریان.
    */
    'nameservers' => array_values(array_filter([
        env('DNS_NS1', 'derek.servernet.cloud'),
        env('DNS_NS2', 'linda.servernet.cloud'),
    ])),

    /*
    | zoneِ «در انتظار» که در این مدت تفویض نشود حذف می‌شود.
    |
    | 🔴 دلیلِ امنیتی، نه خانه‌تکانی: zoneِ در انتظار روی PowerDNS منتشر است
    | (تا لحظهٔ تعویضِ نیم‌سرور، سایت بی‌وقفه جواب بدهد). اگر بی‌انقضا بماند،
    | کسی می‌تواند zoneِ دامنهٔ دیگری را «رزرو» کند و روزی که صاحبِ واقعی
    | نیم‌سرورش را به ما بدهد، رکوردهای **او** سرو شود. انقضا + ادعای مالکیت
    | با TXT (`ZoneService::claim`) این پنجره را می‌بندد.
    */
    'pending_days' => 14,

    /*
    | پسوندهای دوبخشی که «دامنهٔ قابلِ ثبت» زیرشان سه‌بخشی است.
    | zone فقط روی دامنهٔ قابلِ ثبت ساخته می‌شود، نه زیردامنه — وگرنه یک
    | مشتری zoneِ `shop.example.com` را می‌سازد در حالی که `example.com`
    | مالِ دیگری است، و روی همان نیم‌سرورها دو zone با هم می‌جنگند.
    */
    'multi_label_suffixes' => [
        'co.ir', 'ac.ir', 'org.ir', 'net.ir', 'gov.ir', 'id.ir', 'sch.ir',
        'com.tr', 'net.tr', 'org.tr', 'gen.tr', 'biz.tr', 'info.tr', 'web.tr',
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk',
        'com.au', 'net.au', 'org.au', 'com.cn', 'net.cn', 'org.cn',
        'co.jp', 'com.br', 'com.tw', 'co.nz', 'co.za', 'com.sg', 'com.my',
        'com.ua', 'com.ru', 'co.in', 'com.pk', 'com.af', 'com.iq', 'com.sa', 'com.eg',
    ],

    /*
    | مقصدِ CNAME در حالتِ «فقط CDN». هر zone یک زیردامنهٔ یکتا زیرِ این
    | می‌گیرد (`{token}.edge.servernet.cloud`) تا دو مشتری با یک مقصد اشتباه
    | گرفته نشوند و بشود ترافیکِ هرکدام را جدا مسیر داد.
    */
    'edge_suffix' => env('EDGE_SUFFIX', 'edge.servernet.cloud'),

    /*
    | snapshot — قلبِ مدلِ «کشیده می‌شود، فرستاده نمی‌شود».
    |
    | `interval` فاصلهٔ کشیدنِ نود است و `stale_after` آستانه‌ای که
    | `SystemHealth` نود را «گم‌شده» اعلام می‌کند. دومی باید دستِ‌کم دو برابرِ
    | اولی باشد وگرنه یک کشیدنِ جامانده هشدارِ کاذب می‌سازد.
    */
    'snapshot' => [
        'interval'    => 30,
        'stale_after' => 90,
    ],

    /*
    | انواعِ رکورد. `proxiable` زیرمجموعهٔ آن است: فقط رکوردی که به یک میزبانِ
    | HTTP اشاره می‌کند می‌تواند از لبه رد شود.
    |
    | 🔴 MX هرگز proxiable نیست و نباید بشود. پروکسی‌کردنِ MX یعنی ایمیلِ
    | مشتری به یک وب‌سرور می‌رود و **بی‌صدا** گم می‌شود — همان جنس خرابی که
    | `CloudflareDns` کامنتش را دارد (proxied=false عمدی).
    */
    'record_types'  => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'],

    /*
    | بیشینهٔ طولِ محتوای TXT. رکوردِ DKIMِ ۲۰۴۸بیتی ~۴۱۰ نویسه است و
    | ۴۰۹۶بیتی ~۷۴۰؛ ۲۰۴۸ جا برای هر دو و SPFِ بلند دارد. تکه‌کردن به ۲۵۵
    | (محدودیتِ پروتکل برای هر رشته) کارِ `PowerDnsClient` است، نه کاربر.
    */
    'txt_max' => 2048,
    'proxiable'     => ['A', 'AAAA', 'CNAME'],

    /*
    | رکوردهایی که واردکننده **باید** پیش از تعویضِ نیم‌سرور نشان دهد و تأیید
    | بگیرد. zoneای که بی‌این‌ها منتقل شود ایمیلِ شرکتِ مشتری را قطع می‌کند و
    | هیچ خطایی هم نمی‌سازد.
    */
    'critical_types' => ['MX', 'TXT', 'NS'],

    'ttl_choices' => [60, 120, 300, 600, 1800, 3600, 7200, 14400, 43200, 86400],
    'default_ttl' => 3600,

    /*
    | WAF — سطحِ حساسیت به paranoia levelِ OWASP CRS نگاشت می‌شود.
    | `off` عمداً یک سطح است نه نبودِ رکورد: مشتری باید بتواند موقتاً خاموشش
    | کند بی‌آنکه قواعدِ سفارشی‌اش پاک شود.
    */
    'waf' => [
        'levels'         => ['off' => 0, 'low' => 1, 'medium' => 2, 'high' => 3],
        'default_level'  => 'medium',
        'actions'        => ['log', 'challenge', 'block'],
        'default_action' => 'block',
        'kinds'          => ['custom', 'rate_limit', 'geo', 'ip'],
    ],

    'cache' => [
        'match_types' => ['path', 'extension', 'header'],
        'actions'     => ['cache', 'bypass'],
    ],

    /*
    | سقف‌ها. عمداً سخاوتمند ولی متناهی‌اند: هر ردیف به snapshotی می‌رود که
    | روی **هر** درخواست در نودِ لبه خوانده می‌شود، پس zoneای با ۱۰٬۰۰۰ قاعده
    | تأخیرِ همهٔ مشتری‌های همان نود را بالا می‌برد.
    */
    'limits' => [
        'records_per_zone'    => 250,
        'cache_rules_per_zone' => 50,
        'waf_rules_per_zone'   => 100,
        'origins_per_zone'     => 5,
        'zones_per_customer'   => 50,
    ],

    /*
    | PowerDNS — آدرس و کلیدِ API. کلید مثلِ بقیهٔ توکن‌های این پروژه در
    | تنظیمات (`Setting::putSecret('pdns_api_key')`) می‌نشیند نه در .env، تا
    | مدیر بتواند بدونِ دیپلوی عوضش کند.
    */
    'pdns' => [
        'url'    => env('PDNS_API_URL', 'http://127.0.0.1:8081'),
        'server' => env('PDNS_API_SERVER', 'localhost'),
        'soa'    => [
            // primary عمداً این‌جا نیست: همان نیم‌سرورِ اولِ `CdnZone::nameservers()`
            // است، تا تغییرِ نام در پنل SOA را هم با خودش ببرد.
            'email'   => 'hostmaster.servernet.cloud',
            'refresh' => 3600,
            'retry'   => 600,
            'expire'  => 604800,
            'ttl'     => 3600,
        ],
    ],

    /*
    | DNSِ مدیریت‌شده v2 — قراردادِ `docs/dns/MANAGED-DNS-DESIGN.md` §2.
    |
    | 🔴 فقط `App\Services\Dns\DnsConfig` این بخش را می‌خواند و تنظیمِ مدیر
    | (`Setting`) بر آن مقدم است. دو خواننده یعنی روزی پنل یک نیم‌سرور بگوید و
    | تأییدِ تفویض نامِ دیگری بخواهد.
    |
    | ⚠️ هیچ نامِ نیم‌سروری این‌جا ساخته نشده: تا مدیر فهرست را با IP تنظیم
    | نکند سرویس بسته می‌ماند (`DnsConfig::serviceOpen`). پیش‌فرضِ «حدسی» یعنی
    | مشتری نیم‌سرورش را به نامی بدهد که هیچ سروری پشتش نیست.
    */
    'dns' => [
        // پرچمِ ویژگی؛ Setting `dns_managed_enabled` ('1'/'0') بر این مقدم است
        'enabled'   => (bool) env('DNS_MANAGED_ENABLED', false),

        // Setting `pdns_api_url` مقدم است. پیش‌فرضِ ساختگی نداریم.
        'api_url'   => env('PDNS_API_URL'),
        'server_id' => env('PDNS_API_SERVER', 'localhost'),

        /*
        | http بی‌رمز فقط برای آزمایشگاه/توسعه و فقط روی loopback/RFC1918/
        | link-local. عمداً Setting ندارد: پنلِ مدیر نباید بتواند کلیدِ API را
        | روی شبکهٔ عمومی بی‌رمز بفرستد.
        */
        'allow_insecure_api' => (bool) env('DNS_ALLOW_INSECURE_API', false),

        // zoneِ catalogِ تولیدکننده روی primary (Setting `dns_catalog_zone`)
        'catalog'  => 'catalog.servernet.invalid',

        // فقط **نام**ِ کلیدِ TSIG (Setting `dns_xfr_tsig_key`)؛ رازش روی سرورهاست
        'tsig_key' => 'xfr-servernet',

        // [{host, ipv4, ipv6, port}] — Setting `dns_nameservers` (JSON) مقدم است
        'nameservers' => [],

        /*
        | expire = ۲۸ روز: نودِ عمومی تا این مدت بی‌primary جواب می‌دهد، بعد
        | SERVFAIL. کوتاه‌کردنش یعنی قطعیِ کوتاهِ لینکِ بین‌المللی همهٔ zoneها را
        | زودتر می‌کشد.
        */
        'soa' => [
            'email'   => 'hostmaster.servernet.cloud',
            'refresh' => 3600,
            'retry'   => 600,
            'expire'  => 2419200,
            'minimum' => 300,
        ],

        // سهمیه‌ها و نرخ‌ها — همه از `DnsConfig::limit()` خوانده می‌شوند
        'limits' => [
            'zones_per_customer'          => 50,
            'records_per_zone'            => 500,
            'pending_claims_per_customer' => 20,
            'claim_ttl_days'              => 7,
            'import_max_lines'            => 5000,
            'import_max_bytes'            => 1048576,
            'txt_max_bytes'               => 4096,
            'add_per_minute'              => 10,   // افزودن/ادعا/تأیید
            'record_writes_per_minute'    => 60,
            'imports_per_minute'          => 6,
            'checks_per_minute'           => 10,
        ],

        // پاسخِ HTTP هرگز بیش از این منتظرِ PowerDNS نمی‌ماند (§4.2)
        'http' => [
            'connect_timeout' => 4,
            'timeout'         => 8,
        ],
    ],

    /*
    | ACME — صدور با DNS-01 روی همان PowerDNS خودمان.
    |
    | 🔴 چرا DNS-01 و نه HTTP-01: چون zone دستِ ماست، گواهی **پیش از** اینکه
    | ترافیک به لبه بیاید صادر می‌شود. با HTTP-01 باید اول رکورد را عوض کرد و
    | بعد گواهی گرفت، یعنی یک پنجرهٔ واقعی که سایتِ مشتری خطای TLS می‌دهد.
    |
    | ⚠️ باینری روی این ماشین نیست و لازم هم نیست: `AcmeIssuer` نبودش را
    | صریح گزارش می‌کند و هیچ‌جا سقوطِ بی‌صدا ندارد.
    */
    'acme' => [
        'binary'     => env('ACME_BINARY', 'lego'),
        'email'      => env('ACME_EMAIL', 'ssl@servernet.cloud'),
        'storage'    => storage_path('app/acme'),
        'renew_days'  => 21,   // زودتر از ۳۰ روزِ متعارف، چون صدور صف دارد
        'warn_days'   => 14,   // زیرِ این عدد SystemHealth قرمز می‌شود
    ],

    /*
    | بهایِ تمام‌شدهٔ پیش‌فرضِ پهنای باند به ازای هر گیگابایت، به **میکرو-یورو**
    | (۱ یورو = ۱٬۰۰۰٬۰۰۰).
    |
    | 🔴 چرا اصلاً این‌جاست: CDN یعنی فروشِ پهنای باند، و پهنای باندِ نودِ ایران
    | چند برابرِ آلمان است — یعنی ساختارِ هزینه وارونهٔ ساختارِ تقاضاست. اگر
    | بهایِ تمام‌شده روی خودِ نود ثبت نشود، قیمت‌گذاری کور است و همان الگوی
    | «زیرِ بها فروختن» تکرار می‌شود که یک‌بار روی سرورِ ساعتی رخ داد.
    | مقدارِ واقعیِ هر نود در `cdn_nodes.cost_per_gb_micro` است؛ این فقط
    | پیش‌فرضِ ساختِ نودِ تازه است.
    */
    'default_cost_per_gb_micro' => 1000,   // ۰٫۰۰۱ یورو — عمداً کم، تا ندیده‌گرفتنش پیدا شود

];
