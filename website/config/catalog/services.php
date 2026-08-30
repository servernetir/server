<?php

/*
|--------------------------------------------------------------------------
| کاتالوگ خدمات — ۸ صفحه (fa / en / tr)
|--------------------------------------------------------------------------
| pid ها placeholder هستند (۲۱۰ به بعد).
*/

$mk = fn (string $name, int $pid, int $irt, float $eur, array $specs, bool $pop = false) =>
    array_filter(['name' => $name, 'pid' => $pid, 'irt' => $irt, 'eur' => $eur, 'specs' => $specs, 'popular' => $pop]);

return [

    'site-builder' => [
        'icon' => 'rocket', 'group' => 'services',
        'fa' => ['t' => 'سایت‌ساز', 'tag' => 'بدون کدنویسی · آنلاین در یک روز',
            'hero_t' => 'سایت حرفه‌ای،', 'hero_g' => 'بدون حتی یک خط کد.',
            'hero_d' => 'با قالب‌های آماده فارسی و ادیتور کشیدن‌ورهاکردن، سایت شرکتی یا فروشگاهتان را همین امروز بالا بیاورید — هاست، دامنه و SSL همه داخل پکیج.'],
        'en' => ['t' => 'Site Builder', 'tag' => 'No-code · Online in a day',
            'hero_t' => 'A professional website,', 'hero_g' => 'without a single line of code.',
            'hero_d' => 'Launch your company or store site today with ready templates and a drag-and-drop editor — hosting, domain and SSL all included.'],
        'tr' => ['t' => 'Site Oluşturucu', 'tag' => 'Kodsuz · Bir günde yayında',
            'hero_t' => 'Tek satır kod yazmadan', 'hero_g' => 'profesyonel bir site.',
            'hero_d' => 'Hazır şablonlar ve sürükle-bırak editörle şirket veya mağaza sitenizi bugün açın — hosting, alan adı ve SSL dahil.'],
        'chips' => ['AI Builder', 'Drag & Drop', '100+ RTL Templates', 'SEO-Ready', 'Free SSL + Hosting'],
        'signature' => ['type' => 'ai-builder'],
        'plans' => [
            $mk('Personal', 210, 290000, 2.90, [
                ['fa' => 'قالب‌های شخصی و رزومه', 'en' => 'Personal & resume templates', 'tr' => 'Kişisel ve özgeçmiş şablonları'],
                ['fa' => 'هاست + SSL رایگان', 'en' => 'Free hosting + SSL', 'tr' => 'Ücretsiz hosting + SSL'],
                ['fa' => '۱۰ صفحه', 'en' => '10 pages', 'tr' => '10 sayfa'],
                ['fa' => 'اتصال شبکه‌های اجتماعی', 'en' => 'Social media integration', 'tr' => 'Sosyal medya entegrasyonu'],
            ]),
            $mk('Business', 211, 590000, 5.90, [
                ['fa' => 'قالب‌های شرکتی + فرم‌ساز', 'en' => 'Company templates + form builder', 'tr' => 'Kurumsal şablonlar + form oluşturucu'],
                ['fa' => 'دامنه ir رایگان', 'en' => 'Free domain', 'tr' => 'Ücretsiz alan adı'],
                ['fa' => 'صفحات نامحدود + چندزبانه', 'en' => 'Unlimited pages + multilingual', 'tr' => 'Sınırsız sayfa + çok dilli'],
                ['fa' => 'سیستم نوبت‌دهی و وبلاگ', 'en' => 'Booking system & blog', 'tr' => 'Randevu sistemi ve blog'],
            ], true),
            $mk('Shop', 212, 990000, 9.90, [
                ['fa' => 'فروشگاه کامل + درگاه پرداخت', 'en' => 'Full store + payment gateway', 'tr' => 'Tam mağaza + ödeme ağ geçidi'],
                ['fa' => 'مدیریت محصول و انبار', 'en' => 'Product & inventory management', 'tr' => 'Ürün ve stok yönetimi'],
                ['fa' => 'اتصال به ترب و ایمالز', 'en' => 'Marketplace integrations', 'tr' => 'Pazar yeri entegrasyonları'],
                ['fa' => 'صدور فاکتور رسمی', 'en' => 'Official invoicing', 'tr' => 'Resmi faturalama'],
            ]),
        ],
        'features' => ['instant', 'ssl', 'backup', 'support', 'uptime',
            ['icon' => 'layout',
                'fa' => ['t' => 'ادیتور زنده فارسی', 'd' => 'همه‌چیز را همان‌طور که دیده می‌شود ویرایش کنید — راست‌چین واقعی، فونت‌های فارسی استاندارد و پیش‌نمایش موبایل لحظه‌ای.'],
                'en' => ['t' => 'Live Visual Editor', 'd' => 'Edit everything exactly as it appears — true RTL, standard Persian fonts and instant mobile preview.'],
                'tr' => ['t' => 'Canlı Görsel Editör', 'd' => 'Her şeyi göründüğü gibi düzenleyin — gerçek RTL ve anında mobil önizleme.']],
        ],
        'faqs' => ['activation',
            ['fa' => ['q' => 'بعداً می‌توانم به وردپرس مهاجرت کنم؟', 'a' => 'بله — محتوای شما قابل خروجی‌گیری است و تیم ما در صورت رشد کسب‌وکار، سایت را رایگان به هاست وردپرس سرورنت منتقل می‌کند.'],
             'en' => ['q' => 'Can I migrate to WordPress later?', 'a' => 'Yes — your content is exportable, and if you outgrow the builder our team migrates you to ServerNet WordPress hosting for free.'],
             'tr' => ['q' => 'Sonra WordPress\'e geçebilir miyim?', 'a' => 'Evet — içeriğiniz dışa aktarılabilir; büyürseniz ekibimiz sizi ücretsiz taşır.']],
            'upgrade', 'refund',
        ],
    ],

    'premium-support' => [
        'icon' => 'umbrella', 'group' => 'services',
        'fa' => ['t' => 'پشتیبانی چتر آبی', 'tag' => 'SLA پاسخ ۵ دقیقه‌ای',
            'hero_t' => 'زیر چتر آبی،', 'hero_g' => 'هیچ‌وقت تنها نیستید.',
            'hero_d' => 'پشتیبانی فراتر از استاندارد: خط تلفن مستقیم، مهندس آشنا با پروژه شما و SLA پاسخ‌گویی ۵ دقیقه‌ای — برای کسب‌وکارهایی که هر دقیقه قطعی برایشان هزینه است.'],
        'en' => ['t' => 'Premium Support', 'tag' => '5-minute response SLA',
            'hero_t' => 'Under the blue umbrella,', 'hero_g' => 'you\'re never alone.',
            'hero_d' => 'Support beyond the standard: a direct phone line, an engineer who knows your project and a 5-minute response SLA — for businesses where every minute of downtime costs money.'],
        'tr' => ['t' => 'Premium Destek', 'tag' => '5 dakikalık yanıt SLA',
            'hero_t' => 'Mavi şemsiyenin altında', 'hero_g' => 'asla yalnız değilsiniz.',
            'hero_d' => 'Standardın ötesinde destek: doğrudan telefon hattı, projenizi tanıyan mühendis ve 5 dakikalık yanıt SLA\'sı.'],
        'chips' => ['Direct Phone Line', 'Named Engineer', '5-min SLA', '24/7/365', 'Monthly Reports'],
        'signature' => ['type' => 'bars',
            'fa' => ['t' => 'زمان پاسخ اول، بدون تعارف', 'd' => 'میانگین زمان اولین پاسخ فنی واقعی — نه پاسخ خودکار'],
            'en' => ['t' => 'First-response time, honestly', 'd' => 'Average time to a real technical first response — not an auto-reply'],
            'tr' => ['t' => 'İlk yanıt süresi, dürüstçe', 'd' => 'Gerçek teknik ilk yanıta ortalama süre'],
            'items' => [
                ['fa' => 'چتر آبی سرورنت', 'en' => 'ServerNet Premium', 'tr' => 'ServerNet Premium', 'val' => '5 min', 'w' => '100%', 'hl' => true],
                ['fa' => 'میانگین هاستینگ‌های ایران', 'en' => 'Iranian host average', 'tr' => 'Yerel hosting ortalaması', 'val' => '~4 h', 'w' => '12%'],
                ['fa' => 'پشتیبانی فقط-ایمیلی', 'en' => 'Email-only support', 'tr' => 'Yalnızca e-posta desteği', 'val' => '~24 h', 'w' => '4%'],
            ]],
        'plans' => [
            $mk('Care', 213, 990000, 9.90, [
                ['fa' => 'پاسخ تیکت < ۳۰ دقیقه', 'en' => 'Ticket response < 30 min', 'tr' => 'Bilet yanıtı < 30 dk'],
                ['fa' => 'ساعات اداری + آخر هفته', 'en' => 'Business hours + weekends', 'tr' => 'Mesai saatleri + hafta sonu'],
                ['fa' => '۵ درخواست اولویت‌دار در ماه', 'en' => '5 priority requests / mo', 'tr' => 'Ayda 5 öncelikli talep'],
                ['fa' => 'بررسی ماهانه سرویس', 'en' => 'Monthly service review', 'tr' => 'Aylık hizmet incelemesi'],
            ]),
            $mk('Pro', 214, 2490000, 24.90, [
                ['fa' => 'پاسخ < ۵ دقیقه · ۲۴/۷', 'en' => 'Response < 5 min · 24/7', 'tr' => 'Yanıt < 5 dk · 7/24'],
                ['fa' => 'خط تلفن مستقیم مهندسان', 'en' => 'Direct engineer phone line', 'tr' => 'Mühendislere direkt hat'],
                ['fa' => 'درخواست نامحدود', 'en' => 'Unlimited requests', 'tr' => 'Sınırsız talep'],
                ['fa' => 'مانیتورینگ فعال سرویس‌ها', 'en' => 'Proactive service monitoring', 'tr' => 'Proaktif hizmet izleme'],
            ], true),
            $mk('Enterprise', 215, 5900000, 59.00, [
                ['fa' => 'مهندس اختصاصی نام‌دار', 'en' => 'Named dedicated engineer', 'tr' => 'İsimli özel mühendis'],
                ['fa' => 'SLA قراردادی با جریمه', 'en' => 'Contractual SLA with penalties', 'tr' => 'Cezalı sözleşmeli SLA'],
                ['fa' => 'جلسه ماهانه معماری', 'en' => 'Monthly architecture session', 'tr' => 'Aylık mimari toplantısı'],
                ['fa' => 'کانال اختصاصی تیم شما', 'en' => 'Private channel for your team', 'tr' => 'Ekibinize özel kanal'],
            ]),
        ],
        'features' => ['support', 'uptime',
            ['icon' => 'user',
                'fa' => ['t' => 'مهندسی که شما را می‌شناسد', 'd' => 'به‌جای تکرار ماجرا برای هر اپراتور، با مهندسی صحبت می‌کنید که تاریخچه و معماری سرویس شما را از قبل می‌داند.'],
                'en' => ['t' => 'An Engineer Who Knows You', 'd' => 'Instead of re-explaining to every operator, you talk to an engineer who already knows your service history and architecture.'],
                'tr' => ['t' => 'Sizi Tanıyan Bir Mühendis', 'd' => 'Her operatöre baştan anlatmak yerine, geçmişinizi bilen mühendisle konuşursunuz.']],
            ['icon' => 'gauge',
                'fa' => ['t' => 'مانیتورینگ قبل از تماس شما', 'd' => 'اغلب قبل از اینکه تماس بگیرید، ما مشکل را دیده‌ایم و رفعش کرده‌ایم — گزارشش را هم برایتان فرستاده‌ایم.'],
                'en' => ['t' => 'We See It Before You Call', 'd' => 'Often we\'ve detected and fixed the issue before you even call — with the report already in your inbox.'],
                'tr' => ['t' => 'Siz Aramadan Görürüz', 'd' => 'Çoğu zaman siz aramadan sorunu görüp çözeriz.']],
            ['icon' => 'book',
                'fa' => ['t' => 'گزارش شفاف ماهانه', 'd' => 'هر ماه: تیکت‌ها، زمان‌های پاسخ، آپتایم و پیشنهادهای بهبود — روی کاغذ، نه فقط حرف.'],
                'en' => ['t' => 'Transparent Monthly Report', 'd' => 'Every month: tickets, response times, uptime and improvement suggestions — on paper, not just promises.'],
                'tr' => ['t' => 'Şeffaf Aylık Rapor', 'd' => 'Her ay: biletler, yanıt süreleri ve iyileştirme önerileri.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'چتر آبی چه فرقی با پشتیبانی عادی دارد؟', 'a' => 'پشتیبانی عادی سرورنت ۲۴/۷ و رایگان است و همیشه می‌ماند. چتر آبی یک لایه بالاتر است: SLA تضمینی، خط مستقیم، مهندس ثابت و مانیتورینگ فعال — برای کسب‌وکارهایی که سرویسشان حیاتی است.'],
             'en' => ['q' => 'How is this different from normal support?', 'a' => 'ServerNet\'s standard 24/7 support stays free forever. Premium is a layer above: guaranteed SLA, direct line, a fixed engineer and proactive monitoring — for mission-critical businesses.'],
             'tr' => ['q' => 'Normal destekten farkı ne?', 'a' => 'Standart 7/24 destek her zaman ücretsizdir. Premium bir üst katmandır: garantili SLA, direkt hat ve sabit mühendis.']],
            ['fa' => ['q' => 'فقط برای سرویس‌های سرورنت است؟', 'a' => 'خیر — در پلن Pro و Enterprise سرورهای شما نزد هر شرکت دیگری را هم پوشش می‌دهیم؛ خیلی از مشتریان ما را همین تفاوت آورده است.'],
             'en' => ['q' => 'Is it only for ServerNet services?', 'a' => 'No — on Pro and Enterprise we also cover your servers at any other provider; that difference is exactly what brings many customers to us.'],
             'tr' => ['q' => 'Sadece ServerNet hizmetleri için mi?', 'a' => 'Hayır — Pro ve Enterprise\'da başka sağlayıcılardaki sunucularınızı da kapsarız.']],
            'activation', 'refund',
        ],
    ],

    'devops' => [
        'icon' => 'flow', 'group' => 'services',
        'fa' => ['t' => 'خدمات دواپس', 'tag' => 'CI/CD · Kubernetes · SRE',
            'hero_t' => 'تیم دواپس شما،', 'hero_g' => 'بدون استخدام دواپس.',
            'hero_d' => 'از CI/CD و کانتینرسازی تا Kubernetes و مانیتورینگ حرفه‌ای — مهندسان ما زیرساخت را مثل تیم داخلی‌تان می‌سازند و می‌چرخانند، با کسری از هزینه استخدام.'],
        'en' => ['t' => 'DevOps Services', 'tag' => 'CI/CD · Kubernetes · SRE',
            'hero_t' => 'Your DevOps team,', 'hero_g' => 'without hiring DevOps.',
            'hero_d' => 'From CI/CD and containerization to Kubernetes and serious monitoring — our engineers build and run your infrastructure like an in-house team, at a fraction of hiring cost.'],
        'tr' => ['t' => 'DevOps Hizmetleri', 'tag' => 'CI/CD · Kubernetes · SRE',
            'hero_t' => 'DevOps işe almadan', 'hero_g' => 'DevOps ekibiniz.',
            'hero_d' => 'CI/CD ve konteynerden Kubernetes\'e — mühendislerimiz altyapınızı şirket içi ekip gibi kurar ve işletir.'],
        'chips' => ['GitLab / GitHub CI', 'Docker + K8s', 'Terraform IaC', 'Grafana Monitoring', 'Zero-Downtime Deploys'],
        'signature' => ['type' => 'term',
            'fa' => ['t' => 'از push تا پروداکشن، خودکار', 'd' => 'پایپ‌لاینی که برایتان می‌سازیم — تست، بیلد و دیپلوی بدون دخالت دست'],
            'en' => ['t' => 'From push to production, automated', 'd' => 'The pipeline we build for you — test, build and deploy hands-free'],
            'tr' => ['t' => 'Push\'tan üretime, otomatik', 'd' => 'Sizin için kurduğumuz pipeline'],
            'lines' => [
                ['p', '$ git push origin main'],
                ['c', '── ServerNet CI/CD pipeline ──'],
                ['w', '▸ tests ............ 214 passed (41s)'],
                ['w', '▸ build image ...... registry.acme.ir/app:v2.4.1'],
                ['w', '▸ deploy canary .... 10% traffic, error rate 0.00%'],
                ['ok', '✔ rolled out to production — zero downtime (2m 12s)'],
            ]],
        'plans' => [
            $mk('Launch', 216, 4900000, 49.00, [
                ['fa' => 'راه‌اندازی CI/CD کامل', 'en' => 'Full CI/CD setup', 'tr' => 'Tam CI/CD kurulumu'],
                ['fa' => 'داکرایز کردن اپلیکیشن', 'en' => 'App containerization', 'tr' => 'Uygulama konteynerleştirme'],
                ['fa' => 'مانیتورینگ + هشدار پایه', 'en' => 'Monitoring + basic alerting', 'tr' => 'İzleme + temel uyarı'],
                ['fa' => '۲۰ ساعت مهندسی در ماه', 'en' => '20 engineering hours / mo', 'tr' => 'Ayda 20 mühendislik saati'],
            ]),
            $mk('Scale', 217, 9900000, 99.00, [
                ['fa' => 'Kubernetes + اتواسکیل', 'en' => 'Kubernetes + autoscaling', 'tr' => 'Kubernetes + otomatik ölçek'],
                ['fa' => 'IaC با Terraform', 'en' => 'IaC with Terraform', 'tr' => 'Terraform ile IaC'],
                ['fa' => 'On-Call مشترک با تیم شما', 'en' => 'Shared on-call with your team', 'tr' => 'Ekibinizle ortak nöbet'],
                ['fa' => '۵۰ ساعت مهندسی در ماه', 'en' => '50 engineering hours / mo', 'tr' => 'Ayda 50 mühendislik saati'],
            ], true),
            array_merge($mk('Enterprise', 218, 0, 0, [
                ['fa' => 'تیم SRE اختصاصی', 'en' => 'Dedicated SRE team', 'tr' => 'Özel SRE ekibi'],
                ['fa' => 'معماری چند-دیتاسنتری HA', 'en' => 'Multi-DC HA architecture', 'tr' => 'Çok DC\'li HA mimari'],
                ['fa' => 'SLA و On-Call کامل ۲۴/۷', 'en' => 'Full 24/7 on-call + SLA', 'tr' => 'Tam 7/24 nöbet + SLA'],
                ['fa' => 'ساعت نامحدود پروژه‌ای', 'en' => 'Unlimited project hours', 'tr' => 'Sınırsız proje saati'],
            ]), ['contact' => true]),
        ],
        'features' => ['support', 'uptime', 'ddos',
            ['icon' => 'flow',
                'fa' => ['t' => 'استقرار بدون قطعی', 'd' => 'Blue-Green و Canary Deployment — کاربرانتان هرگز نمی‌فهمند نسخه جدید کی منتشر شد.'],
                'en' => ['t' => 'Zero-Downtime Deployments', 'd' => 'Blue-green and canary deployments — your users never notice when a release ships.'],
                'tr' => ['t' => 'Kesintisiz Dağıtım', 'd' => 'Blue-green ve canary — kullanıcılar sürümün ne zaman çıktığını fark etmez.']],
            ['icon' => 'gauge',
                'fa' => ['t' => 'مانیتورینگ که حرف می‌زند', 'd' => 'داشبوردهای Grafana با متریک‌های واقعی کسب‌وکار شما و هشدارهایی که قبل از کاربر خبردار می‌شوند.'],
                'en' => ['t' => 'Monitoring That Speaks', 'd' => 'Grafana dashboards with your real business metrics, and alerts that fire before your users notice.'],
                'tr' => ['t' => 'Konuşan İzleme', 'd' => 'Gerçek iş metrikli Grafana panoları ve erken uyarılar.']],
            ['icon' => 'book',
                'fa' => ['t' => 'دانش نزد شما می‌ماند', 'd' => 'همه‌چیز مستند و در ریپوی خودتان — هر لحظه بخواهید، بدون وابستگی به ما ادامه می‌دهید.'],
                'en' => ['t' => 'Knowledge Stays With You', 'd' => 'Everything documented in your own repos — continue without us whenever you choose, zero lock-in.'],
                'tr' => ['t' => 'Bilgi Sizde Kalır', 'd' => 'Her şey kendi repolarınızda belgeli — bize bağımlılık sıfır.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'با استک فعلی ما کار می‌کنید؟', 'a' => 'بله — لاراول، جنگو، نود، Go و… فرقی نمی‌کند. اول معماری فعلی را ممیزی می‌کنیم، بعد نقشه راه بهبود را با اولویت‌بندی شفاف تحویل می‌دهیم.'],
             'en' => ['q' => 'Do you work with our current stack?', 'a' => 'Yes — Laravel, Django, Node, Go, anything. We first audit your current architecture, then deliver a prioritized, transparent improvement roadmap.'],
             'tr' => ['q' => 'Mevcut stack\'imizle çalışır mısınız?', 'a' => 'Evet — Laravel, Django, Node, Go fark etmez. Önce denetler, sonra öncelikli yol haritası veririz.']],
            ['fa' => ['q' => 'ساعت‌های استفاده‌نشده چه می‌شود؟', 'a' => 'تا ۵۰٪ ساعت‌های ماهانه به ماه بعد منتقل می‌شود — برای ماه‌هایی که پروژه بزرگ دارید ذخیره کنید.'],
             'en' => ['q' => 'What happens to unused hours?', 'a' => 'Up to 50% of monthly hours roll over — save them for the months with big projects.'],
             'tr' => ['q' => 'Kullanılmayan saatler ne olur?', 'a' => 'Aylık saatlerin %50\'sine kadarı sonraki aya devreder.']],
            'manage', 'refund',
        ],
    ],

    'security' => [
        'icon' => 'shield', 'group' => 'services', 'billing' => 'yearly',
        'unit' => ['fa' => '/ پروژه', 'en' => '/ project', 'tr' => '/ proje'],
        'fa' => ['t' => 'خدمات امنیت', 'tag' => 'پن‌تست · SOC · امن‌سازی',
            'hero_t' => 'قبل از هکرها،', 'hero_g' => 'ما پیدایش می‌کنیم.',
            'hero_d' => 'تست نفوذ توسط متخصصان دارای مدرک OSCP، امن‌سازی زیرساخت و مانیتورینگ امنیتی ۲۴ ساعته — گزارش‌هایی که مدیر می‌فهمد و مهندس اجرا می‌کند.'],
        'en' => ['t' => 'Security Services', 'tag' => 'Pentest · SOC · Hardening',
            'hero_t' => 'We find it', 'hero_g' => 'before the hackers do.',
            'hero_d' => 'Penetration testing by OSCP-certified specialists, infrastructure hardening and 24/7 security monitoring — reports managers understand and engineers can act on.'],
        'tr' => ['t' => 'Güvenlik Hizmetleri', 'tag' => 'Pentest · SOC · Sıkılaştırma',
            'hero_t' => 'Hacker\'lardan önce', 'hero_g' => 'biz buluruz.',
            'hero_d' => 'OSCP sertifikalı uzmanlarla sızma testi, altyapı sıkılaştırma ve 7/24 güvenlik izleme.'],
        'chips' => ['OSCP Certified', 'OWASP Top 10', 'SIEM / SOC', 'Incident Response', 'Compliance Reports'],
        'signature' => ['type' => 'term',
            'fa' => ['t' => 'گزارشی که تعارف ندارد', 'd' => 'نمونه خروجی اسکن امنیتی — هر یافته با شدت، اثبات و راه‌حل'],
            'en' => ['t' => 'A report with no sugar-coating', 'd' => 'Sample security scan output — every finding with severity, proof and fix'],
            'tr' => ['t' => 'Şekersiz bir rapor', 'd' => 'Örnek güvenlik taraması çıktısı'],
            'lines' => [
                ['p', '$ snet-sec scan --target app.example.ir --profile deep'],
                ['w', '▸ 2,148 endpoints crawled · 96 tests per endpoint'],
                ['w', '[HIGH]   SQLi on /api/orders?id= — PoC attached'],
                ['w', '[MEDIUM] Session cookie missing SameSite=Strict'],
                ['w', '[LOW]    Server version disclosed in headers'],
                ['ok', '✔ report ready → 3 findings, 3 fixes, retest included'],
            ]],
        'plans' => [
            $mk('Scan', 219, 2900000, 29.00, [
                ['fa' => 'اسکن آسیب‌پذیری کامل', 'en' => 'Full vulnerability scan', 'tr' => 'Tam zafiyet taraması'],
                ['fa' => 'وب + سرور + شبکه', 'en' => 'Web + server + network', 'tr' => 'Web + sunucu + ağ'],
                ['fa' => 'گزارش با راه‌حل عملی', 'en' => 'Report with actionable fixes', 'tr' => 'Uygulanabilir çözümlü rapor'],
                ['fa' => 'اسکن مجدد رایگان بعد از رفع', 'en' => 'Free retest after fixes', 'tr' => 'Düzeltme sonrası ücretsiz tekrar test'],
            ]),
            $mk('Pentest', 220, 9900000, 99.00, [
                ['fa' => 'تست نفوذ دستی OSCP', 'en' => 'Manual OSCP pentest', 'tr' => 'Manuel OSCP pentest'],
                ['fa' => 'سناریوهای واقعی حمله', 'en' => 'Real-world attack scenarios', 'tr' => 'Gerçek saldırı senaryoları'],
                ['fa' => 'گزارش مدیریتی + فنی', 'en' => 'Executive + technical report', 'tr' => 'Yönetici + teknik rapor'],
                ['fa' => 'جلسه ارائه یافته‌ها', 'en' => 'Findings walkthrough session', 'tr' => 'Bulgu sunum toplantısı'],
            ], true),
            array_merge($mk('SOC', 221, 0, 0, [
                ['fa' => 'مانیتورینگ امنیتی ۲۴/۷', 'en' => '24/7 security monitoring', 'tr' => '7/24 güvenlik izleme'],
                ['fa' => 'SIEM + پاسخ به حادثه', 'en' => 'SIEM + incident response', 'tr' => 'SIEM + olay müdahalesi'],
                ['fa' => 'امن‌سازی مستمر زیرساخت', 'en' => 'Continuous hardening', 'tr' => 'Sürekli sıkılaştırma'],
                ['fa' => 'قرارداد ماهانه سازمانی', 'en' => 'Monthly enterprise contract', 'tr' => 'Aylık kurumsal sözleşme'],
            ]), ['contact' => true]),
        ],
        'features' => ['ddos', 'support',
            ['icon' => 'search',
                'fa' => ['t' => 'دید مهاجم، اخلاق مدافع', 'd' => 'با همان ابزار و ذهنیت هکرهای واقعی تست می‌کنیم — اما همه‌چیز تحت قرارداد NDA و کاملاً قانونی.'],
                'en' => ['t' => 'Attacker\'s Eye, Defender\'s Ethics', 'd' => 'We test with the same tools and mindset as real attackers — everything under NDA and fully legal.'],
                'tr' => ['t' => 'Saldırgan Gözü, Savunmacı Etiği', 'd' => 'Gerçek saldırganların araç ve zihniyetiyle, tamamen yasal test ederiz.']],
            ['icon' => 'book',
                'fa' => ['t' => 'گزارش دو زبانه‌ی دو مخاطبه', 'd' => 'خلاصه مدیریتی برای تصمیم‌گیری و جزئیات فنی PoC برای تیم توسعه — هر دو در یک گزارش.'],
                'en' => ['t' => 'One Report, Two Audiences', 'd' => 'An executive summary for decisions plus technical PoC details for your dev team — in one document.'],
                'tr' => ['t' => 'Tek Rapor, İki Kitle', 'd' => 'Karar için yönetici özeti + geliştirici için teknik PoC.']],
            ['icon' => 'restore',
                'fa' => ['t' => 'رفع، نه فقط کشف', 'd' => 'در کنار تیم شما می‌مانیم تا آسیب‌پذیری‌ها واقعاً بسته شوند — اسکن مجدد رایگان تاییدش می‌کند.'],
                'en' => ['t' => 'Fixing, Not Just Finding', 'd' => 'We stay with your team until vulnerabilities are actually closed — the free retest proves it.'],
                'tr' => ['t' => 'Sadece Bulmak Değil, Düzeltmek', 'd' => 'Zafiyetler gerçekten kapanana kadar ekibinizin yanındayız.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'تست نفوذ به سرویس ما آسیب نمی‌زند؟', 'a' => 'خیر — دامنه تست از قبل مکتوب توافق می‌شود، تست‌های مخرب روی محیط استیجینگ انجام می‌شود و برای پروداکشن فقط روش‌های امن به‌کار می‌رود.'],
             'en' => ['q' => 'Can a pentest harm our service?', 'a' => 'No — the scope is agreed in writing beforehand, destructive tests run on staging, and only safe techniques touch production.'],
             'tr' => ['q' => 'Pentest hizmetimize zarar verir mi?', 'a' => 'Hayır — kapsam önceden yazılı belirlenir, yıkıcı testler staging\'de yapılır.']],
            ['fa' => ['q' => 'خروجی برای انطباق/قرارداد قابل استفاده است؟', 'a' => 'بله — گزارش‌ها مطابق استانداردهای OWASP و قابل ارائه به کارفرمایان، بانک‌ها و ممیزی‌های امنیتی است.'],
             'en' => ['q' => 'Can I use the output for compliance?', 'a' => 'Yes — reports follow OWASP standards and are suitable for clients, banks and security audits.'],
             'tr' => ['q' => 'Çıktıyı uyumluluk için kullanabilir miyim?', 'a' => 'Evet — raporlar OWASP standartlarına uygundur ve denetimlere sunulabilir.']],
            'activation',
        ],
    ],

    'wordpress-care' => [
        'icon' => 'lifebuoy', 'group' => 'services',
        'fa' => ['t' => 'پشتیبانی وردپرس', 'tag' => 'نگهداری کامل سایت وردپرسی',
            'hero_t' => 'وردپرس‌تان را بسپارید،', 'hero_g' => 'خیالتان را بردارید.',
            'hero_d' => 'آپدیت امن، بکاپ، امنیت، رفع خطا و بهینه‌سازی سرعت — تیم وردپرس‌کار ما سایت شما را مثل سایت خودش نگه می‌دارد؛ شما فقط محتوا بگذارید.'],
        'en' => ['t' => 'WordPress Care', 'tag' => 'Complete WordPress maintenance',
            'hero_t' => 'Hand over your WordPress,', 'hero_g' => 'take back your peace of mind.',
            'hero_d' => 'Safe updates, backups, security, error fixing and speed optimization — our WordPress team maintains your site like its own; you just publish content.'],
        'tr' => ['t' => 'WordPress Bakımı', 'tag' => 'Eksiksiz WordPress bakımı',
            'hero_t' => 'WordPress\'inizi bize bırakın,', 'hero_g' => 'içiniz rahat olsun.',
            'hero_d' => 'Güvenli güncelleme, yedek, güvenlik, hata giderme ve hız optimizasyonu — siz sadece içerik yayınlayın.'],
        'chips' => ['Safe Updates', 'Malware Cleanup', 'Speed Optimization', 'Uptime Watch', 'Emergency Fixes'],
        'signature' => ['type' => 'bars',
            'fa' => ['t' => 'وقتی سایت می‌خوابد، چند دقیقه طول می‌کشد؟', 'd' => 'میانگین زمان رفع خطای بحرانی وردپرس (سایت از دسترس خارج)'],
            'en' => ['t' => 'When your site goes down, how long until it\'s back?', 'd' => 'Average time to fix a critical WordPress outage'],
            'tr' => ['t' => 'Siteniz düştüğünde ne kadar sürede döner?', 'd' => 'Kritik WordPress kesintisini giderme süresi'],
            'items' => [
                ['fa' => 'با پشتیبانی وردپرس سرورنت', 'en' => 'With ServerNet WP Care', 'tr' => 'ServerNet WP Bakımı ile', 'val' => '< 1 h', 'w' => '100%', 'hl' => true],
                ['fa' => 'فریلنسر (اگر جواب بدهد)', 'en' => 'Freelancer (if they answer)', 'tr' => 'Freelancer (yanıt verirse)', 'val' => '~1 day', 'w' => '9%'],
                ['fa' => 'خودتان + جستجوی گوگل', 'en' => 'DIY + Google', 'tr' => 'Kendiniz + Google', 'val' => '~3 days', 'w' => '3%'],
            ]],
        'plans' => [
            $mk('Solo', 222, 490000, 4.90, [
                ['fa' => '۱ سایت وردپرسی', 'en' => '1 WordPress site', 'tr' => '1 WordPress sitesi'],
                ['fa' => 'آپدیت امن هفتگی + بکاپ', 'en' => 'Weekly safe updates + backups', 'tr' => 'Haftalık güvenli güncelleme + yedek'],
                ['fa' => 'پایش امنیت و آپتایم', 'en' => 'Security & uptime monitoring', 'tr' => 'Güvenlik ve uptime izleme'],
                ['fa' => '۲ ساعت رفع مشکل در ماه', 'en' => '2 fix-hours / mo', 'tr' => 'Ayda 2 saat müdahale'],
            ]),
            $mk('Studio', 223, 1490000, 14.90, [
                ['fa' => '۵ سایت وردپرسی', 'en' => '5 WordPress sites', 'tr' => '5 WordPress sitesi'],
                ['fa' => 'همه امکانات Solo + استیجینگ', 'en' => 'Everything in Solo + staging', 'tr' => 'Solo\'daki her şey + staging'],
                ['fa' => 'بهینه‌سازی سرعت فصلی', 'en' => 'Quarterly speed optimization', 'tr' => 'Üç aylık hız optimizasyonu'],
                ['fa' => '۸ ساعت رفع مشکل در ماه', 'en' => '8 fix-hours / mo', 'tr' => 'Ayda 8 saat müdahale'],
            ], true),
            $mk('Agency', 224, 3900000, 39.00, [
                ['fa' => '۲۰ سایت وردپرسی', 'en' => '20 WordPress sites', 'tr' => '20 WordPress sitesi'],
                ['fa' => 'پاک‌سازی بدافزار نامحدود', 'en' => 'Unlimited malware cleanup', 'tr' => 'Sınırsız zararlı temizliği'],
                ['fa' => 'داشبورد وضعیت همه سایت‌ها', 'en' => 'All-sites status dashboard', 'tr' => 'Tüm siteler durum panosu'],
                ['fa' => '۲۰ ساعت توسعه در ماه', 'en' => '20 dev-hours / mo', 'tr' => 'Ayda 20 geliştirme saati'],
            ]),
        ],
        'features' => ['backup', 'support', 'uptime',
            ['icon' => 'restore',
                'fa' => ['t' => 'آپدیت با تور ایمنی', 'd' => 'هر آپدیت اول روی استیجینگ تست می‌شود؛ اگر چیزی شکست، به نسخه سالم برمی‌گردیم — سایت اصلی هرگز ریسک نمی‌کند.'],
                'en' => ['t' => 'Updates With a Safety Net', 'd' => 'Every update is tested on staging first; if anything breaks, we roll back — your live site never takes the risk.'],
                'tr' => ['t' => 'Güvenlik Ağıyla Güncelleme', 'd' => 'Her güncelleme önce staging\'de test edilir; bozulursa geri döneriz.']],
            ['icon' => 'shield',
                'fa' => ['t' => 'پاک‌سازی بدافزار تضمینی', 'd' => 'سایت هک‌شده را کامل پاک، مقاوم‌سازی و از بلک‌لیست گوگل خارج می‌کنیم — با گزارش نفوذ.'],
                'en' => ['t' => 'Guaranteed Malware Cleanup', 'd' => 'We fully clean hacked sites, harden them and get you off Google\'s blacklist — with a breach report.'],
                'tr' => ['t' => 'Garantili Zararlı Temizliği', 'd' => 'Hacklenmiş siteyi temizler, sağlamlaştırır ve Google kara listesinden çıkarırız.']],
            ['icon' => 'zap',
                'fa' => ['t' => 'سرعت، هر فصل بهتر', 'd' => 'بهینه‌سازی دوره‌ای تصاویر، دیتابیس و کش — امتیاز PageSpeed شما را مکتوب گزارش می‌دهیم.'],
                'en' => ['t' => 'Faster Every Quarter', 'd' => 'Periodic image, database and cache optimization — with your PageSpeed score reported in writing.'],
                'tr' => ['t' => 'Her Çeyrek Daha Hızlı', 'd' => 'Periyodik görsel, veritabanı ve önbellek optimizasyonu.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'سایتم پیش شما هاست نیست؛ می‌شود؟', 'a' => 'بله — پشتیبانی وردپرس مستقل از محل هاست است. فقط دسترسی مدیریت وردپرس (و ترجیحاً هاست) را می‌گیریم و شروع می‌کنیم.'],
             'en' => ['q' => 'My site isn\'t hosted with you — is that OK?', 'a' => 'Yes — WordPress Care is host-independent. We just need WP admin (and ideally hosting) access to start.'],
             'tr' => ['q' => 'Sitem sizde barınmıyor — sorun olur mu?', 'a' => 'Hayır — WP Bakımı hosting\'den bağımsızdır.']],
            ['fa' => ['q' => 'سایت هک‌شده هم قبول می‌کنید؟', 'a' => 'بله — پاک‌سازی اضطراری حتی بدون اشتراک هم انجام می‌شود؛ با اشتراک Agency نامحدود و رایگان است.'],
             'en' => ['q' => 'Do you take already-hacked sites?', 'a' => 'Yes — emergency cleanup is available even without a subscription; on Agency it\'s unlimited and free.'],
             'tr' => ['q' => 'Hacklenmiş siteleri kabul ediyor musunuz?', 'a' => 'Evet — acil temizlik aboneliksiz de yapılır.']],
            'activation', 'refund',
        ],
    ],

    'ssl' => [
        'icon' => 'lock', 'group' => 'services', 'billing' => 'yearly',
        'fa' => ['t' => 'گواهینامه SSL', 'tag' => 'DV · OV · EV · Wildcard',
            'hero_t' => 'قفل سبز اعتماد،', 'hero_g' => 'در چند دقیقه.',
            'hero_d' => 'از SSL رایگان همراه هاست تا گواهی‌های سازمانی EV با نام شرکت شما — صدور سریع، نصب رایگان توسط تیم ما و گارانتی مالی معتبرترین مراجع صدور.'],
        'en' => ['t' => 'SSL Certificates', 'tag' => 'DV · OV · EV · Wildcard',
            'hero_t' => 'The green lock of trust,', 'hero_g' => 'in minutes.',
            'hero_d' => 'From free SSL with hosting to EV certificates carrying your company name — fast issuance, free installation by our team and warranties from the most trusted CAs.'],
        'tr' => ['t' => 'SSL Sertifikaları', 'tag' => 'DV · OV · EV · Wildcard',
            'hero_t' => 'Güvenin yeşil kilidi,', 'hero_g' => 'dakikalar içinde.',
            'hero_d' => 'Hosting ile ücretsiz SSL\'den şirket adınızı taşıyan EV sertifikalarına — hızlı basım ve ekibimizce ücretsiz kurulum.'],
        'chips' => ['Sectigo / DigiCert', '5-min Issuance (DV)', 'Free Installation', 'Up to $1.75M Warranty', 'SHA-256 / TLS 1.3'],
        'plans' => [
            $mk('DV Basic', 225, 490000, 4.90, [
                ['fa' => '۱ دامنه + www', 'en' => '1 domain + www', 'tr' => '1 alan adı + www'],
                ['fa' => 'صدور ۵ دقیقه‌ای', 'en' => '5-minute issuance', 'tr' => '5 dakikada basım'],
                ['fa' => 'نصب رایگان', 'en' => 'Free installation', 'tr' => 'Ücretsiz kurulum'],
                ['fa' => 'گارانتی ۱۰ هزار دلاری', 'en' => '$10k warranty', 'tr' => '10 bin $ garanti'],
            ]),
            $mk('DV Wildcard', 226, 2490000, 24.90, [
                ['fa' => 'دامنه + همه ساب‌دامنه‌ها', 'en' => 'Domain + all subdomains', 'tr' => 'Alan adı + tüm alt alanlar'],
                ['fa' => 'صدور همان روز', 'en' => 'Same-day issuance', 'tr' => 'Aynı gün basım'],
                ['fa' => 'نصب رایگان', 'en' => 'Free installation', 'tr' => 'Ücretsiz kurulum'],
                ['fa' => 'گارانتی ۵۰ هزار دلاری', 'en' => '$50k warranty', 'tr' => '50 bin $ garanti'],
            ], true),
            $mk('OV Business', 227, 3900000, 39.00, [
                ['fa' => 'احراز هویت سازمان', 'en' => 'Organization validation', 'tr' => 'Kuruluş doğrulaması'],
                ['fa' => 'نام شرکت در گواهی', 'en' => 'Company name in certificate', 'tr' => 'Sertifikada şirket adı'],
                ['fa' => 'مناسب درگاه و بانک', 'en' => 'Gateway & banking grade', 'tr' => 'Ödeme ve banka sınıfı'],
                ['fa' => 'گارانتی ۱ میلیون دلاری', 'en' => '$1M warranty', 'tr' => '1 milyon $ garanti'],
            ]),
            $mk('EV Enterprise', 228, 7900000, 79.00, [
                ['fa' => 'بالاترین سطح اعتماد', 'en' => 'Highest trust level', 'tr' => 'En yüksek güven seviyesi'],
                ['fa' => 'احراز کامل حقوقی شرکت', 'en' => 'Full legal vetting', 'tr' => 'Tam hukuki doğrulama'],
                ['fa' => 'مناسب بانک و بیمه و بورس', 'en' => 'Bank / insurance / exchange grade', 'tr' => 'Banka / sigorta sınıfı'],
                ['fa' => 'گارانتی ۱.۷۵ میلیون دلاری', 'en' => '$1.75M warranty', 'tr' => '1,75 milyon $ garanti'],
            ]),
        ],
        'features' => ['instant', 'support',
            ['icon' => 'wrench',
                'fa' => ['t' => 'نصب و تمدید با ما', 'd' => 'گواهی را روی هر سروری — حتی خارج از سرورنت — رایگان نصب می‌کنیم و قبل از انقضا خودمان پیگیر تمدیدیم.'],
                'en' => ['t' => 'We Install & Renew', 'd' => 'We install your certificate on any server — even outside ServerNet — for free, and chase the renewal before expiry.'],
                'tr' => ['t' => 'Kurulum ve Yenileme Bizde', 'd' => 'Sertifikayı her sunucuya ücretsiz kurarız, yenilemeyi biz takip ederiz.']],
            ['icon' => 'trend',
                'fa' => ['t' => 'سئو و نرخ تبدیل بالاتر', 'd' => 'HTTPS فاکتور رسمی رتبه گوگل است و قفل سبز؛ اعتماد و خرید کاربر را قابل اندازه‌گیری افزایش می‌دهد.'],
                'en' => ['t' => 'Better SEO & Conversion', 'd' => 'HTTPS is an official Google ranking factor, and the padlock measurably increases user trust and purchases.'],
                'tr' => ['t' => 'Daha İyi SEO ve Dönüşüm', 'd' => 'HTTPS resmi Google sıralama faktörüdür.']],
            ['icon' => 'shield',
                'fa' => ['t' => 'گارانتی مالی واقعی', 'd' => 'اگر رمزنگاری گواهی شکسته شود، مرجع صدور تا سقف گارانتی (تا ۱.۷۵ میلیون دلار) خسارت کاربران را می‌پردازد.'],
                'en' => ['t' => 'Real Financial Warranty', 'd' => 'If the certificate\'s encryption is ever broken, the CA compensates affected users up to the warranty cap ($1.75M).'],
                'tr' => ['t' => 'Gerçek Mali Garanti', 'd' => 'Şifreleme kırılırsa CA, garanti tavanına kadar tazmin eder.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'SSL رایگان دارم؛ چرا گواهی بخرم؟', 'a' => 'SSL رایگان (Let\'s Encrypt) برای وبلاگ و سایت شخصی عالی است. اما فروشگاه‌ها و سازمان‌ها OV/EV می‌خرند چون نام حقوقی شرکت احراز و در گواهی درج می‌شود، گارانتی مالی دارد و اعتماد مشتریان پرداختی را جلب می‌کند.'],
             'en' => ['q' => 'I have free SSL — why buy one?', 'a' => 'Free SSL (Let\'s Encrypt) is great for blogs. Stores and organizations buy OV/EV because the company\'s legal identity is vetted and shown in the certificate, it carries a financial warranty, and it earns paying customers\' trust.'],
             'tr' => ['q' => 'Ücretsiz SSL\'im var — neden satın alayım?', 'a' => 'Ücretsiz SSL bloglar için harikadır. Mağazalar OV/EV alır çünkü şirket kimliği doğrulanır, mali garanti taşır.']],
            ['fa' => ['q' => 'صدور OV/EV چقدر طول می‌کشد؟', 'a' => 'DV چند دقیقه؛ OV معمولاً ۱-۳ روز کاری و EV ۳-۷ روز — مدارک ثبتی شرکت لازم است و ما کل فرایند احراز را پیش می‌بریم.'],
             'en' => ['q' => 'How long do OV/EV take?', 'a' => 'DV takes minutes; OV usually 1–3 business days, EV 3–7 — company registry documents are needed and we drive the whole vetting process.'],
             'tr' => ['q' => 'OV/EV ne kadar sürer?', 'a' => 'DV dakikalar; OV 1–3, EV 3–7 iş günü — süreci biz yürütürüz.']],
            'activation',
        ],
    ],

    /*
    | 🔴 متن این بخش عمداً محافظه‌کار است: به گفته‌ی صریح کارفرما این لایسنس‌ها
    | **اشتراکی**‌اند و تحویل **دستی** است (پس از پرداخت، با اعلام در تیکت/پنل).
    | نسخه‌ی قبلی «اورجینال، نه اشتراکی، پارتنرشیپ رسمی، فعال‌سازی آنی» ادعا
    | می‌کرد — ادعای اثبات‌نشدنی روی صفحه‌ای که مشتری برای اعتماد می‌خواند از
    | نبودش بدتر است (همان قاعده‌ی /status). کلیدهای pool «instant» و
    | «activation» هم برای همین از این بخش حذف شده‌اند؛ برنگردانشان.
    */
    'licenses' => [
        'icon' => 'key', 'group' => 'services',
        // نوار «در همه‌ی پلن‌ها» شامل «تحویل آنی» است — تحویل لایسنس دستی است
        'inc_strip' => false,
        'fa' => ['t' => 'لایسنس‌ها', 'tag' => 'پرداخت ریالی · تمدید خودکار',
            'hero_t' => 'لایسنس نرم‌افزار،', 'hero_g' => 'بدون دردسر پرداخت ارزی.',
            'hero_d' => 'cPanel، DirectAdmin، Plesk و LiteSpeed — سفارش آنلاین با پرداخت ریالی، فعال‌سازی روی IP سرور شما پس از پرداخت، و تمدید خودکار ماهانه تا پنل‌تان هیچ‌وقت قفل نشود.'],
        'en' => ['t' => 'Licenses', 'tag' => 'Local billing · Auto-renew',
            'hero_t' => 'Software licenses', 'hero_g' => 'without the forex hassle.',
            'hero_d' => 'cPanel, DirectAdmin, Plesk and LiteSpeed — order online with local payment, activation on your server IP after payment, and automatic monthly renewal so your panel never locks.'],
        'tr' => ['t' => 'Lisanslar', 'tag' => 'Yerel ödeme · Otomatik yenileme',
            'hero_t' => 'Yazılım lisansları,', 'hero_g' => 'döviz derdi olmadan.',
            'hero_d' => 'cPanel, DirectAdmin, Plesk ve LiteSpeed — yerel ödemeyle çevrimiçi sipariş, ödemeden sonra sunucu IP\'nizde etkinleştirme ve otomatik aylık yenileme.'],
        'chips' => ['Monthly Licenses', 'Local Billing', 'Auto-Renew', 'Any Server IP'],
        'plans' => [
            /*
            | ═══ چرا هر لایسنس **دو** رده دارد (مجازی / اختصاصی) ═══
            |
            | خودِ cPanel و Plesk قیمتشان را بر همین محور می‌بندند و بازارِ
            | ایران هم همین‌طور می‌فروشد (بررسیِ toshan.net، مرداد ۱۴۰۵:
            | cPanel مجازی ۳۷۰k در برابرِ اختصاصی ۷۴۰k).
            |
            | قیمتِ تخت یعنی مشتریِ VPS — که اکثریتِ خریدارند — عددی ببیند که
            | برای سرورِ اختصاصی بسته شده و از رقیب دو برابر گران به‌نظر برسد.
            | تا پیش از این، cPanel ما ۹۹۰k بود در برابرِ ۳۷۰k بازار.
            |
            | ⚠️ چهار اسلاگِ اصلی (`license-directadmin`, `-cpanel`, `-plesk`,
            | `-litespeed`) عمداً **دست‌نخورده** ماندند و ردهٔ «مجازی» شدند:
            | این‌ها روی پروداکشن از قبل ساخته شده‌اند و عوض‌کردنِ اسلاگ یعنی
            | محصولِ یتیم در دیتابیس و دکمهٔ خریدی که پکیجش را پیدا نمی‌کند.
            |
            | ⚠️ قیمت‌ها بر مبنای بازار است، نه بهای تمام‌شدهٔ ما (که در کد
            | نیست). پیش از فروش با هزینهٔ واقعی تطبیق دهید.
            */
            /*
            | ⚠️ نامِ پلن‌ها لاتین/خنثی است (مثل Personal/Business بالای همین
            | فایل): این رشته در هر سه زبان خام چاپ می‌شود و «سرور مجازی» روی
            | /en و /tr فارسی نشت می‌کرد (بررسی سراسری زبان، مرداد ۱۴۰۵).
            | ردهٔ مجازی/اختصاصی در اولین سطرِ مشخصاتِ سه‌زبانه گفته می‌شود.
            | نامِ فارسیِ محصولِ دیتابیس (فاکتور) در LicenseProductSeeder جداست.
            */
            $mk('DirectAdmin — VPS', 229, 350000, 3.50, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'مخصوص VPS', 'en' => 'For VPS', 'tr' => 'VPS için'],
                ['fa' => 'اکانت نامحدود', 'en' => 'Unlimited accounts', 'tr' => 'Sınırsız hesap'],
                ['fa' => 'فعال‌سازی روی IP شما', 'en' => 'Activated on your IP', 'tr' => 'IP\'nizde etkinleştirme'],
            ], true) + ['product' => 'license-directadmin'],
            $mk('DirectAdmin — Dedicated', 233, 590000, 5.90, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'مخصوص سرور اختصاصی', 'en' => 'For dedicated servers', 'tr' => 'Dedicated için'],
                ['fa' => 'اکانت نامحدود', 'en' => 'Unlimited accounts', 'tr' => 'Sınırsız hesap'],
                ['fa' => 'فعال‌سازی روی IP شما', 'en' => 'Activated on your IP', 'tr' => 'IP\'nizde etkinleştirme'],
            ]) + ['product' => 'license-directadmin-ded'],
            $mk('cPanel/WHM — VPS', 230, 390000, 3.90, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'مخصوص VPS', 'en' => 'For VPS', 'tr' => 'VPS için'],
                ['fa' => 'روی سرور داخل و خارج ایران', 'en' => 'Works inside and outside Iran', 'tr' => 'İran içinde ve dışında'],
                ['fa' => 'فعال‌سازی روی IP شما', 'en' => 'Activated on your IP', 'tr' => 'IP\'nizde etkinleştirme'],
            ]) + ['product' => 'license-cpanel'],
            $mk('cPanel/WHM — Dedicated', 234, 740000, 7.40, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'مخصوص سرور اختصاصی', 'en' => 'For dedicated servers', 'tr' => 'Dedicated için'],
                ['fa' => 'روی سرور داخل و خارج ایران', 'en' => 'Works inside and outside Iran', 'tr' => 'İran içinde ve dışında'],
                ['fa' => 'فعال‌سازی روی IP شما', 'en' => 'Activated on your IP', 'tr' => 'IP\'nizde etkinleştirme'],
            ]) + ['product' => 'license-cpanel-ded'],
            $mk('Plesk — VPS', 231, 450000, 4.50, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'دامنه نامحدود', 'en' => 'Unlimited domains', 'tr' => 'Sınırsız alan adı'],
                ['fa' => 'لینوکس و ویندوز', 'en' => 'Linux & Windows', 'tr' => 'Linux ve Windows'],
                ['fa' => 'همه اکستنشن‌های پایه', 'en' => 'All core extensions', 'tr' => 'Tüm temel eklentiler'],
            ]) + ['product' => 'license-plesk'],
            $mk('Plesk — Dedicated', 235, 690000, 6.90, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'مخصوص سرور اختصاصی', 'en' => 'For dedicated servers', 'tr' => 'Dedicated için'],
                ['fa' => 'دامنه نامحدود', 'en' => 'Unlimited domains', 'tr' => 'Sınırsız alan adı'],
                ['fa' => 'لینوکس و ویندوز', 'en' => 'Linux & Windows', 'tr' => 'Linux ve Windows'],
            ]) + ['product' => 'license-plesk-ded'],
            $mk('LiteSpeed Enterprise', 232, 390000, 3.90, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'تا ۸ هسته CPU', 'en' => 'Up to 8 CPU cores', 'tr' => '8 CPU çekirdeğine kadar'],
                ['fa' => 'LSCache همه CMSها', 'en' => 'LSCache for every CMS', 'tr' => 'Tüm CMS\'ler için LSCache'],
                ['fa' => 'جایگزین مستقیم Apache', 'en' => 'Drop-in Apache replacement', 'tr' => 'Apache\'nin birebir yedeği'],
            ]) + ['product' => 'license-litespeed'],
            // CloudLinux عمداً اضافه شد: خریدارش دقیقاً همان نماینده‌ای است که
            // پکیجِ نمایندگی می‌خرد — بی‌LVE، «اکانت زیاد» فروختن ریسکِ نود است.
            $mk('CloudLinux', 236, 390000, 3.90, [
                ['fa' => 'لایسنس ماهانه', 'en' => 'Monthly license', 'tr' => 'Aylık lisans'],
                ['fa' => 'ایزولاسیون منابع با LVE', 'en' => 'LVE resource isolation', 'tr' => 'LVE kaynak izolasyonu'],
                ['fa' => 'PHP Selector چندنسخه‌ای', 'en' => 'Multi-version PHP Selector', 'tr' => 'Çok sürümlü PHP Selector'],
                ['fa' => 'لازمهٔ نمایندگی پایدار', 'en' => 'Essential for stable reselling', 'tr' => 'Kararlı bayilik için gerekli'],
            ]) + ['product' => 'license-cloudlinux'],
        ],
        // کلید pool «instant» عمداً نیست: تحویل لایسنس دستی است
        'features' => ['support',
            ['icon' => 'key',
                'fa' => ['t' => 'فعال‌سازی روی IP شما', 'd' => 'لایسنس روی IP سروری که اعلام می‌کنید فعال می‌شود — سرور می‌تواند نزد هر ارائه‌دهنده‌ای باشد و تغییر IP بعداً با یک تیکت انجام می‌شود.'],
                'en' => ['t' => 'Activated on Your IP', 'd' => 'The license activates on the server IP you provide — hosted anywhere, with later IP changes handled via a support ticket.'],
                'tr' => ['t' => 'IP\'nizde Etkinleştirme', 'd' => 'Lisans bildirdiğiniz sunucu IP\'sinde etkinleşir; IP değişikliği destek talebiyle yapılır.']],
            ['icon' => 'coins',
                'fa' => ['t' => 'پرداخت ریالی، تمدید خودکار', 'd' => 'بدون کارت ارزی و دردسر تحریم — تمدید خودکار پیش از انقضا تا پنل هیچ‌وقت قفل نشود.'],
                'en' => ['t' => 'Local Billing, Auto-Renew', 'd' => 'No foreign cards or sanction workarounds — auto-renewal before expiry so your panel never locks.'],
                'tr' => ['t' => 'Yerel Ödeme, Otomatik Yenileme', 'd' => 'Yabancı kart derdi yok — panel asla kilitlenmez.']],
            ['icon' => 'box',
                'fa' => ['t' => 'لایسنس دیگری لازم دارید؟', 'd' => 'CloudLinux، Imunify360، JetBackup، Softaculous و… — با یک تیکت بپرسید؛ اگر تأمین‌شدنی باشد قیمت می‌دهیم.'],
                'en' => ['t' => 'Need Another License?', 'd' => 'CloudLinux, Imunify360, JetBackup, Softaculous and more — open a ticket and we will quote it if we can source it.'],
                'tr' => ['t' => 'Başka Lisans mı Lazım?', 'd' => 'CloudLinux, Imunify360, JetBackup ve dahası — destek talebiyle sorun, temin edebilirsek fiyat verelim.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'لایسنس روی سرورِ هر شرکتی فعال می‌شود؟', 'a' => 'بله — فقط IP سرور را می‌گیریم؛ سرور می‌تواند هرجای دنیا و نزد هر ارائه‌دهنده‌ای باشد. تغییر IP هم ماهی یک‌بار رایگان است.'],
             'en' => ['q' => 'Can I activate on a server from any provider?', 'a' => 'Yes — we just need the server IP; it can live anywhere with any provider. One free IP change per month included.'],
             'tr' => ['q' => 'Herhangi bir sağlayıcının sunucusunda etkinleştirebilir miyim?', 'a' => 'Evet — sadece sunucu IP\'si gerekir; ayda bir ücretsiz IP değişikliği dahildir.']],
            'upgrade', 'refund',
        ],
    ],

    'more' => [
        'icon' => 'box', 'group' => 'services', 'billing' => 'yearly',
        'unit' => ['fa' => '/ پروژه', 'en' => '/ project', 'tr' => '/ proje'],
        'fa' => ['t' => 'خدمات بیشتر', 'tag' => 'هر کار فنی که فکرش را کنید',
            'hero_t' => 'کار فنی خاصی دارید؟', 'hero_g' => 'برای همین اینجاییم.',
            'hero_d' => 'مهاجرت سرور و سایت، بهینه‌سازی سرعت، راه‌اندازی ایمیل سازمانی، کانفیگ‌های خاص — اگر در فهرست خدمات ما نبود، همین‌جا سفارشش بدهید.'],
        'en' => ['t' => 'More Services', 'tag' => 'Any technical job you can think of',
            'hero_t' => 'Got an unusual technical task?', 'hero_g' => 'That\'s exactly why we\'re here.',
            'hero_d' => 'Server and site migrations, speed optimization, business email setup, exotic configurations — if it\'s not in our service list, order it right here.'],
        'tr' => ['t' => 'Diğer Hizmetler', 'tag' => 'Aklınıza gelen her teknik iş',
            'hero_t' => 'Sıra dışı bir teknik işiniz mi var?', 'hero_g' => 'Tam da bunun için buradayız.',
            'hero_d' => 'Sunucu ve site taşıma, hız optimizasyonu, kurumsal e-posta kurulumu — listemizde yoksa buradan sipariş edin.'],
        'chips' => ['Fixed-Price Quotes', 'NDA On Request', 'Senior Engineers', 'Post-Job Support', 'Any Stack'],
        'plans' => [
            $mk('Migration', 233, 1900000, 19.00, [
                ['fa' => 'مهاجرت کامل سرور/سایت', 'en' => 'Full server/site migration', 'tr' => 'Tam sunucu/site taşıma'],
                ['fa' => 'بدون قطعی، با تست نهایی', 'en' => 'Zero downtime, final testing', 'tr' => 'Kesintisiz, son testli'],
                ['fa' => 'هر تعداد سایت و دیتابیس', 'en' => 'Any number of sites & DBs', 'tr' => 'İstenilen sayıda site ve DB'],
                ['fa' => '۷ روز پشتیبانی بعد از تحویل', 'en' => '7-day post-delivery support', 'tr' => 'Teslim sonrası 7 gün destek'],
            ]),
            $mk('Speed Boost', 234, 2900000, 29.00, [
                ['fa' => 'ممیزی کامل سرعت سایت', 'en' => 'Full site speed audit', 'tr' => 'Tam site hız denetimi'],
                ['fa' => 'بهینه‌سازی کش/تصویر/دیتابیس', 'en' => 'Cache/image/DB optimization', 'tr' => 'Önbellek/görsel/DB optimizasyonu'],
                ['fa' => 'گزارش قبل/بعد PageSpeed', 'en' => 'Before/after PageSpeed report', 'tr' => 'Öncesi/sonrası PageSpeed raporu'],
                ['fa' => 'تضمین بهبود امتیاز', 'en' => 'Score improvement guarantee', 'tr' => 'Skor iyileştirme garantisi'],
            ], true),
            array_merge($mk('Custom Job', 235, 0, 0, [
                ['fa' => 'شرح کار را شما بگویید', 'en' => 'You describe the job', 'tr' => 'İşi siz tanımlayın'],
                ['fa' => 'برآورد رایگان و قیمت مقطوع', 'en' => 'Free estimate, fixed price', 'tr' => 'Ücretsiz keşif, sabit fiyat'],
                ['fa' => 'قرارداد و NDA در صورت نیاز', 'en' => 'Contract & NDA if needed', 'tr' => 'Gerekirse sözleşme ve NDA'],
                ['fa' => 'تحویل مرحله‌ای شفاف', 'en' => 'Transparent milestones', 'tr' => 'Şeffaf aşamalı teslim'],
            ]), ['contact' => true]),
        ],
        'features' => ['support', 'instant',
            ['icon' => 'wrench',
                'fa' => ['t' => 'قیمت مقطوع، بدون سورپرایز', 'd' => 'قبل از شروع، شرح کار و قیمت نهایی مکتوب می‌شود — ساعت‌شمار مخفی و هزینه اضافه در کار نیست.'],
                'en' => ['t' => 'Fixed Price, No Surprises', 'd' => 'Scope and final price are written down before we start — no hidden hour-counters, no extra fees.'],
                'tr' => ['t' => 'Sabit Fiyat, Sürpriz Yok', 'd' => 'Kapsam ve fiyat baştan yazılır — gizli ücret yok.']],
            ['icon' => 'headset',
                'fa' => ['t' => 'بعد از تحویل هم هستیم', 'd' => 'هر پروژه ۷ روز پشتیبانی رایگان دارد — اگر چیزی به کار ما مربوط بود، بدون بحث درستش می‌کنیم.'],
                'en' => ['t' => 'We Stay After Delivery', 'd' => 'Every job includes 7 days of free support — if it\'s related to our work, we fix it, no debate.'],
                'tr' => ['t' => 'Teslimden Sonra da Buradayız', 'd' => 'Her iş 7 gün ücretsiz destek içerir.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'چطور سفارش بدهم؟', 'a' => 'از دکمه مشاوره رایگان تماس بگیرید یا از صفحه تماس پیام بدهید؛ شرح کار را می‌شنویم و ظرف یک روز کاری برآورد مکتوب می‌فرستیم.'],
             'en' => ['q' => 'How do I order?', 'a' => 'Call via the free-consultation button or message us from the contact page; we\'ll hear the scope and send a written estimate within one business day.'],
             'tr' => ['q' => 'Nasıl sipariş veririm?', 'a' => 'Ücretsiz danışma butonundan arayın veya iletişim sayfasından yazın; bir iş günü içinde yazılı teklif göndeririz.']],
            'refund', 'activation',
        ],
    ],
];
