<?php

/*
|--------------------------------------------------------------------------
| کاتالوگ خدمات ابری — ۱۱ صفحه (fa / en / tr)
|--------------------------------------------------------------------------
*/

$traffic = ['fa' => 'ترافیک نامحدود', 'en' => 'Unlimited traffic', 'tr' => 'Sınırsız trafik'];
$api = ['fa' => 'API و CLI کامل', 'en' => 'Full API & CLI', 'tr' => 'Tam API ve CLI'];

$mk = fn (string $name, int $pid, int $irt, float $eur, array $specs, bool $pop = false) =>
    array_filter(['name' => $name, 'pid' => $pid, 'irt' => $irt, 'eur' => $eur, 'specs' => $specs, 'popular' => $pop]);

return [

    'iaas' => [
        'icon' => 'cloud', 'group' => 'compute',
        'fa' => ['t' => 'زیرساخت ابری (IaaS)', 'tag' => 'پرداخت ساعتی · API کامل',
            'hero_t' => 'زیرساخت ابری،', 'hero_g' => 'در چند ثانیه، با یک API.',
            'hero_d' => 'سرور، شبکه خصوصی، فایروال و استوریج — همه با API و پرداخت ساعتی. زیرساختی که با کد ساخته می‌شود و با رشد شما مقیاس می‌گیرد.'],
        'en' => ['t' => 'Cloud Infrastructure (IaaS)', 'tag' => 'Hourly billing · Full API',
            'hero_t' => 'Cloud infrastructure', 'hero_g' => 'in seconds, via one API.',
            'hero_d' => 'Servers, private networks, firewalls and storage — all API-driven and billed hourly. Infrastructure as code that scales with you.'],
        'tr' => ['t' => 'Bulut Altyapı (IaaS)', 'tag' => 'Saatlik ödeme · Tam API',
            'hero_t' => 'Saniyeler içinde, tek API ile', 'hero_g' => 'bulut altyapısı.',
            'hero_d' => 'Sunucular, özel ağlar, güvenlik duvarları ve depolama — hepsi API ile, saatlik faturalandırma.'],
        'chips' => ['Hourly Billing', 'REST API + CLI', 'Terraform Provider', 'Private Networking', 'NVMe RAID-10'],
        'signature' => ['type' => 'term',
            'fa' => ['t' => 'زیرساخت با یک خط دستور', 'd' => 'CLI سرورنت — سرور بسازید، اسنپ‌شات بگیرید، مقیاس بدهید'],
            'en' => ['t' => 'Infrastructure in one command', 'd' => 'The ServerNet CLI — create servers, snapshot, scale'],
            'tr' => ['t' => 'Tek komutla altyapı', 'd' => 'ServerNet CLI — sunucu oluşturun, snapshot alın, ölçekleyin'],
            'lines' => [
                ['c', '# Create a server with the ServerNet CLI'],
                ['p', '$ snet server create --plan c-2 --image ubuntu-24.04 --region ir-thr'],
                ['ok', '✔ server created in 38s → ip 185.xx.xx.42'],
                ['p', '$ snet snapshot create srv-8f2a --label pre-deploy'],
                ['ok', '✔ snapshot saved (12 GB, incremental)'],
                ['p', '$ snet server resize srv-8f2a --plan c-4'],
                ['ok', '✔ resized live — no reboot required'],
            ]],
        'plans' => [
            $mk('C-1', 170, 590000, 5.90, ['1 vCPU / 2 GB RAM', '25 GB NVMe', ['fa' => '~۸۲۰ تومان/ساعت', 'en' => '~€0.009/hour', 'tr' => '~€0,009/saat'], $traffic, $api]),
            $mk('C-2', 171, 1090000, 10.90, ['2 vCPU / 4 GB RAM', '60 GB NVMe', ['fa' => '~۱,۵۲۰ تومان/ساعت', 'en' => '~€0.016/hour', 'tr' => '~€0,016/saat'], $traffic, $api], true),
            $mk('C-4', 172, 1990000, 19.90, ['4 vCPU / 8 GB RAM', '120 GB NVMe', ['fa' => '~۲,۷۷۰ تومان/ساعت', 'en' => '~€0.028/hour', 'tr' => '~€0,028/saat'], $traffic, $api]),
            $mk('C-8', 173, 3790000, 37.90, ['8 vCPU / 16 GB RAM', '240 GB NVMe', ['fa' => '~۵,۲۷۰ تومان/ساعت', 'en' => '~€0.053/hour', 'tr' => '~€0,053/saat'], $traffic, $api]),
        ],
        'features' => ['hourly', 'scale', 'instant', 'net10', 'backup', 'support'],
        'faqs' => ['activation',
            ['fa' => ['q' => 'Terraform و Ansible پشتیبانی می‌شود؟', 'a' => 'بله — Provider رسمی Terraform و کالکشن Ansible داریم؛ به‌علاوه REST API کامل با مستندات OpenAPI و SDK پایتون/Node.'],
             'en' => ['q' => 'Do you support Terraform and Ansible?', 'a' => 'Yes — we ship an official Terraform provider and Ansible collection, plus a full REST API with OpenAPI docs and Python/Node SDKs.'],
             'tr' => ['q' => 'Terraform ve Ansible destekleniyor mu?', 'a' => 'Evet — resmi Terraform provider ve Ansible koleksiyonumuz var; ayrıca OpenAPI dokümanlı tam REST API.']],
            'os', 'refund',
        ],
    ],


    'bmaas' => [
        'icon' => 'server', 'group' => 'compute',
        'fa' => ['t' => 'زیرساخت اختصاصی (BMaaS)', 'tag' => 'Bare-Metal · تحویل ۲ ساعته',
            'hero_t' => 'فلز خالص،', 'hero_g' => 'با تجربه‌ی ابری.',
            'hero_d' => 'سرور فیزیکی کامل بدون لایه مجازی‌سازی — با همان API، پرداخت ساعتی و تحویل سریع ابر. برای دیتابیس‌ها و بارهای کاری حساس به کارایی.'],
        'en' => ['t' => 'Bare-Metal Cloud (BMaaS)', 'tag' => 'Bare-metal · 2-hour delivery',
            'hero_t' => 'Pure metal,', 'hero_g' => 'with a cloud experience.',
            'hero_d' => 'Full physical servers with zero virtualization — same API, hourly billing and fast delivery as cloud. For databases and performance-critical loads.'],
        'tr' => ['t' => 'Bare-Metal Bulut (BMaaS)', 'tag' => 'Bare-metal · 2 saatte teslim',
            'hero_t' => 'Saf metal,', 'hero_g' => 'bulut deneyimiyle.',
            'hero_d' => 'Sanallaştırmasız tam fiziksel sunucular — bulutla aynı API, saatlik ödeme ve hızlı teslim.'],
        'chips' => ['No Hypervisor', 'Hourly Billing', 'API Provisioning', 'NVMe Gen4', '2h Delivery'],
        'plans' => [
            $mk('BM-1', 177, 6900000, 69.00, ['Ryzen 7 7700 · 8c/16t', '64 GB DDR5 ECC', '2 × 512 GB NVMe Gen4', ['fa' => 'پرداخت ساعتی', 'en' => 'Hourly billing', 'tr' => 'Saatlik ödeme'], $api]),
            $mk('BM-2', 178, 9900000, 99.00, ['Ryzen 9 7950X · 16c/32t', '128 GB DDR5 ECC', '2 × 1 TB NVMe Gen4', ['fa' => 'پرداخت ساعتی', 'en' => 'Hourly billing', 'tr' => 'Saatlik ödeme'], $api], true),
            array_merge($mk('BM-XL', 179, 0, 0, ['EPYC · up to 96 cores', 'Up to 1 TB ECC RAM', 'Custom NVMe array', ['fa' => 'پرداخت ساعتی', 'en' => 'Hourly billing', 'tr' => 'Saatlik ödeme'], $api]), ['contact' => true]),
        ],
        'features' => ['hourly', 'root', 'instant', 'net10', 'scale', 'support'],
        'faqs' => ['activation', 'os',
            ['fa' => ['q' => 'فرق BMaaS با سرور اختصاصی معمولی چیست؟', 'a' => 'سخت‌افزار همان است؛ تجربه فرق دارد: تحویل ۲ ساعته به‌جای ۲۴ ساعت، پرداخت ساعتی به‌جای ماهانه و ساخت/حذف با API — انعطاف ابر با قدرت فلز.'],
             'en' => ['q' => 'How is BMaaS different from regular dedicated?', 'a' => 'Same hardware, different experience: 2-hour delivery instead of 24, hourly instead of monthly billing, and API create/destroy — cloud flexibility with metal power.'],
             'tr' => ['q' => 'BMaaS normal dedicated\'dan nasıl farklı?', 'a' => 'Donanım aynı, deneyim farklı: 24 saat yerine 2 saatte teslim, aylık yerine saatlik ödeme ve API ile oluştur/sil.']],
            'refund',
        ],
    ],

    'kubernetes' => [
        'icon' => 'flow', 'group' => 'compute',
        'fa' => ['t' => 'کلاستر کوبرنتیز (KaaS)', 'tag' => 'Managed K8s · کنترل‌پلین رایگان',
            'hero_t' => 'کوبرنتیز مدیریت‌شده،', 'hero_g' => 'بدون دردسر کوبرنتیز.',
            'hero_d' => 'کلاستر آماده با کنترل‌پلین رایگان و HA — آپگرید، پچ و مانیتورینگ با ما؛ شما فقط deploy کنید. سازگار کامل با اکوسیستم CNCF.'],
        'en' => ['t' => 'Kubernetes (KaaS)', 'tag' => 'Managed K8s · Free control plane',
            'hero_t' => 'Managed Kubernetes,', 'hero_g' => 'minus the Kubernetes pain.',
            'hero_d' => 'Ready clusters with a free, HA control plane — we handle upgrades, patches and monitoring; you just deploy. Fully CNCF-conformant.'],
        'tr' => ['t' => 'Kubernetes (KaaS)', 'tag' => 'Yönetilen K8s · Ücretsiz control plane',
            'hero_t' => 'Yönetilen Kubernetes,', 'hero_g' => 'Kubernetes derdi olmadan.',
            'hero_d' => 'Ücretsiz ve HA control plane\'li hazır kümeler — yükseltme ve izleme bizde; siz sadece deploy edin.'],
        'chips' => ['CNCF Conformant', 'Free Control Plane', 'Auto-Scaling', 'LB + Storage CSI', 'kubectl Ready'],
        'signature' => ['type' => 'term',
            'fa' => ['t' => 'کلاستر واقعی، در سه دقیقه', 'd' => 'از ساخت کلاستر تا اولین deploy — بدون نصب حتی یک باینری روی سرور'],
            'en' => ['t' => 'A real cluster in three minutes', 'd' => 'From cluster creation to first deploy — without installing a single binary on a server'],
            'tr' => ['t' => 'Üç dakikada gerçek küme', 'd' => 'Küme oluşturmadan ilk deploy\'a'],
            'lines' => [
                ['p', '$ snet k8s create --name prod --nodes 3 --plan k-4'],
                ['ok', '✔ cluster ready in 2m41s (v1.31, HA control plane)'],
                ['p', '$ snet k8s kubeconfig prod > ~/.kube/config'],
                ['p', '$ kubectl get nodes'],
                ['w', 'prod-node-1   Ready   45s    prod-node-2   Ready   43s'],
                ['p', '$ kubectl apply -f app.yaml'],
                ['ok', '✔ deployment.apps/shop created — LB: 185.xx.xx.10'],
            ]],
        'plans' => [
            $mk('K-DEV', 180, 1990000, 19.90, [['fa' => '۲ نود کارگر ۲v/4G', 'en' => '2 worker nodes 2v/4G', 'tr' => '2 worker düğümü 2v/4G'], ['fa' => 'کنترل‌پلین مشترک رایگان', 'en' => 'Free shared control plane', 'tr' => 'Ücretsiz paylaşımlı control plane'], '80 GB NVMe CSI', ['fa' => 'لودبالانسر ۱ عدد', 'en' => '1 load balancer', 'tr' => '1 yük dengeleyici'], $api]),
            $mk('K-PROD', 181, 5900000, 59.00, [['fa' => '۳ نود کارگر ۴v/8G', 'en' => '3 worker nodes 4v/8G', 'tr' => '3 worker düğümü 4v/8G'], ['fa' => 'کنترل‌پلین HA اختصاصی', 'en' => 'Dedicated HA control plane', 'tr' => 'Özel HA control plane'], '360 GB NVMe CSI', ['fa' => 'اتواسکیل + ۲ لودبالانسر', 'en' => 'Autoscaling + 2 LBs', 'tr' => 'Otomatik ölçek + 2 LB'], $api], true),
            array_merge($mk('K-ENT', 182, 0, 0, [['fa' => 'نودپول سفارشی + GPU', 'en' => 'Custom node pools + GPU', 'tr' => 'Özel node pool + GPU'], ['fa' => 'چند کلاستر + GitOps', 'en' => 'Multi-cluster + GitOps', 'tr' => 'Çoklu küme + GitOps'], ['fa' => 'SLA اختصاصی', 'en' => 'Custom SLA', 'tr' => 'Özel SLA'], ['fa' => 'مهندس پلتفرم اختصاصی', 'en' => 'Dedicated platform engineer', 'tr' => 'Özel platform mühendisi'], $api]), ['contact' => true]),
        ],
        'features' => ['scale', 'uptime', 'backup', 'support', 'net10',
            ['icon' => 'flow',
                'fa' => ['t' => 'آپگرید بدون قطعی', 'd' => 'نسخه‌های جدید کوبرنتیز را ما با استراتژی rolling و بدون حتی یک ثانیه قطعی سرویس اعمال می‌کنیم.'],
                'en' => ['t' => 'Zero-Downtime Upgrades', 'd' => 'We roll out new Kubernetes versions with rolling strategies and zero service interruption.'],
                'tr' => ['t' => 'Kesintisiz Yükseltmeler', 'd' => 'Yeni Kubernetes sürümlerini kesintisiz rolling stratejiyle uygularız.']],
        ],
        'faqs' => ['activation',
            ['fa' => ['q' => 'کنترل‌پلین واقعاً رایگان است؟', 'a' => 'بله — شما فقط هزینه نودهای کارگر، استوریج و لودبالانسر را می‌دهید. کنترل‌پلین (در پلن Prod به‌صورت HA سه‌تایی) کاملاً رایگان است.'],
             'en' => ['q' => 'Is the control plane really free?', 'a' => 'Yes — you only pay for worker nodes, storage and load balancers. The control plane (triple-HA on Prod) is completely free.'],
             'tr' => ['q' => 'Control plane gerçekten ücretsiz mi?', 'a' => 'Evet — yalnızca worker, depolama ve LB için ödersiniz. Control plane tamamen ücretsizdir.']],
            'manage', 'refund',
        ],
    ],

    'cdn' => [
        'icon' => 'globe', 'group' => 'network',
        'fa' => ['t' => 'شبکه توزیع محتوا (CDN)', 'tag' => 'PoPهای ایران + جهان',
            'hero_t' => 'محتوای شما،', 'hero_g' => 'همه‌جا در چند میلی‌ثانیه.',
            'hero_d' => 'CDN با نقاط حضور داخل ایران و شبکه جهانی — کش هوشمند، WAF یکپارچه و SSL رایگان. سایتتان را بدون تغییر کد، چند برابر سریع‌تر کنید.'],
        'en' => ['t' => 'Content Delivery (CDN)', 'tag' => 'Iran + global PoPs',
            'hero_t' => 'Your content,', 'hero_g' => 'everywhere in milliseconds.',
            'hero_d' => 'A CDN with PoPs inside Iran plus a global network — smart caching, integrated WAF and free SSL. Make your site several times faster with zero code changes.'],
        'tr' => ['t' => 'İçerik Dağıtım Ağı (CDN)', 'tag' => 'İran + küresel PoP\'lar',
            'hero_t' => 'İçeriğiniz,', 'hero_g' => 'her yerde milisaniyeler içinde.',
            'hero_d' => 'İran içi PoP\'lar ve küresel ağa sahip CDN — akıllı önbellek, entegre WAF ve ücretsiz SSL.'],
        'chips' => ['Iran PoPs', 'HTTP/3', 'Integrated WAF', 'Free SSL', 'Real-Time Analytics'],
        'signature' => ['type' => 'bars',
            'fa' => ['t' => 'زمان پاسخ اول (TTFB) از تهران', 'd' => 'یک سایت با سرور خارج — قبل و بعد از فعال‌سازی CDN سرورنت'],
            'en' => ['t' => 'TTFB from Tehran', 'd' => 'A site hosted abroad — before and after enabling ServerNet CDN'],
            'tr' => ['t' => 'Tahran\'dan TTFB', 'd' => 'Yurt dışında barındırılan bir site — ServerNet CDN öncesi ve sonrası'],
            'items' => [
                ['fa' => 'با CDN سرورنت (کش تهران)', 'en' => 'With ServerNet CDN (Tehran cache)', 'tr' => 'ServerNet CDN ile', 'val' => '28 ms', 'w' => '100%', 'hl' => true],
                ['fa' => 'بدون CDN — مستقیم از آلمان', 'en' => 'No CDN — direct from Germany', 'tr' => 'CDN\'siz — Almanya\'dan', 'val' => '190 ms', 'w' => '15%'],
                ['fa' => 'بدون CDN — ساعت پیک', 'en' => 'No CDN — peak hours', 'tr' => 'CDN\'siz — yoğun saat', 'val' => '450 ms', 'w' => '6%'],
            ]],
        'plans' => [
            $mk('CDN-START', 183, 290000, 2.90, ['500 GB Traffic / mo', ['fa' => 'PoP تهران + فرانکفورت', 'en' => 'Tehran + Frankfurt PoPs', 'tr' => 'Tahran + Frankfurt PoP'], ['fa' => 'SSL رایگان', 'en' => 'Free SSL', 'tr' => 'Ücretsiz SSL'], ['fa' => 'آمار لحظه‌ای', 'en' => 'Real-time analytics', 'tr' => 'Canlı analitik'], 'HTTP/3 + Brotli']),
            $mk('CDN-BIZ', 184, 990000, 9.90, ['5 TB Traffic / mo', ['fa' => 'همه PoPها + WAF', 'en' => 'All PoPs + WAF', 'tr' => 'Tüm PoP\'lar + WAF'], ['fa' => 'قوانین کش پیشرفته', 'en' => 'Advanced cache rules', 'tr' => 'Gelişmiş önbellek kuralları'], ['fa' => 'Image Optimizer', 'en' => 'Image optimizer', 'tr' => 'Görsel iyileştirici'], 'HTTP/3 + Brotli'], true),
            array_merge($mk('CDN-ENT', 185, 0, 0, [['fa' => 'ترافیک نامحدود سفارشی', 'en' => 'Custom unlimited traffic', 'tr' => 'Özel sınırsız trafik'], ['fa' => 'PoP اختصاصی سازمانی', 'en' => 'Private enterprise PoP', 'tr' => 'Özel kurumsal PoP'], ['fa' => 'SLA اختصاصی', 'en' => 'Custom SLA', 'tr' => 'Özel SLA'], ['fa' => 'مهندس اختصاصی', 'en' => 'Dedicated engineer', 'tr' => 'Özel mühendis']]), ['contact' => true]),
        ],
        'features' => ['ddos', 'ssl', 'uptime', 'support', 'instant',
            ['icon' => 'gauge',
                'fa' => ['t' => 'کش هوشمند داخل ایران', 'd' => 'محتوای استاتیک از نزدیک‌ترین PoP داخلی سرو می‌شود — حتی وقتی سرور اصلی شما خارج از کشور است.'],
                'en' => ['t' => 'Smart In-Country Caching', 'd' => 'Static content is served from the nearest domestic PoP — even when your origin is abroad.'],
                'tr' => ['t' => 'Ülke İçi Akıllı Önbellek', 'd' => 'Statik içerik en yakın yerel PoP\'tan sunulur.']],
        ],
        'faqs' => ['activation',
            ['fa' => ['q' => 'راه‌اندازی CDN چقدر کار می‌برد؟', 'a' => 'فقط تغییر NS یا یک رکورد CNAME — بدون تغییر در کد سایت. برای وردپرس افزونه اتصال یک‌کلیکی هم داریم.'],
             'en' => ['q' => 'How much work is CDN setup?', 'a' => 'Just an NS change or a CNAME record — zero code changes. For WordPress we ship a one-click connector plugin.'],
             'tr' => ['q' => 'CDN kurulumu ne kadar iş?', 'a' => 'Sadece NS değişikliği veya bir CNAME kaydı — kodda sıfır değişiklik.']],
            'upgrade', 'refund',
        ],
    ],

    'ddos-protection' => [
        'icon' => 'shield', 'group' => 'network',
        'fa' => ['t' => 'محافظت از حملات DDoS', 'tag' => 'L3 / L4 / L7 · همیشه فعال',
            'hero_t' => 'حمله می‌آید،', 'hero_g' => 'سایت شما نمی‌افتد.',
            'hero_d' => 'سپر چندلایه در برابر حملات حجمی و اپلیکیشنی — تشخیص خودکار زیر ۳ ثانیه، ظرفیت پالایش چند صد گیگابیتی و گزارش شفاف هر حمله.'],
        'en' => ['t' => 'DDoS Protection', 'tag' => 'L3 / L4 / L7 · Always on',
            'hero_t' => 'Attacks will come —', 'hero_g' => 'your site won\'t go down.',
            'hero_d' => 'Multi-layer defense against volumetric and application attacks — sub-3-second automatic detection, hundreds of Gbit scrubbing capacity, transparent per-attack reports.'],
        'tr' => ['t' => 'DDoS Koruması', 'tag' => 'L3 / L4 / L7 · Daima aktif',
            'hero_t' => 'Saldırılar gelecek —', 'hero_g' => 'siteniz düşmeyecek.',
            'hero_d' => 'Hacimsel ve uygulama saldırılarına karşı çok katmanlı savunma — 3 saniyenin altında otomatik tespit.'],
        'chips' => ['Always-On', '< 3s Detection', 'L7 WAF', 'BGP / GRE', 'Attack Reports'],
        'plans' => [
            $mk('SHIELD-ADV', 187, 990000, 9.90, [['fa' => 'پالایش تا ۱۰۰ گیگابیت', 'en' => 'Up to 100 Gbit scrubbing', 'tr' => '100 Gbit\'e kadar temizleme'], 'L3 / L4 / L7', ['fa' => 'WAF با قوانین سفارشی', 'en' => 'WAF with custom rules', 'tr' => 'Özel kurallı WAF'], ['fa' => 'گزارش هر حمله', 'en' => 'Per-attack reports', 'tr' => 'Saldırı başına rapor'], ['fa' => '۱ دامنه / سرویس', 'en' => '1 domain / service', 'tr' => '1 alan adı / hizmet']]),
            $mk('SHIELD-PRO', 188, 2490000, 24.90, [['fa' => 'پالایش تا ۵۰۰ گیگابیت', 'en' => 'Up to 500 Gbit scrubbing', 'tr' => '500 Gbit\'e kadar temizleme'], 'L3 / L4 / L7 + Bot Management', ['fa' => 'WAF + Rate-Limit پیشرفته', 'en' => 'WAF + advanced rate-limiting', 'tr' => 'WAF + gelişmiş hız sınırı'], ['fa' => 'پشتیبانی حین حمله', 'en' => 'Live under-attack support', 'tr' => 'Saldırı anında canlı destek'], ['fa' => '۵ دامنه / سرویس', 'en' => '5 domains / services', 'tr' => '5 alan adı / hizmet']], true),
            array_merge($mk('SHIELD-ENT', 189, 0, 0, [['fa' => 'ظرفیت پالایش سفارشی', 'en' => 'Custom scrubbing capacity', 'tr' => 'Özel temizleme kapasitesi'], 'BGP / GRE Tunnel', ['fa' => 'SOC اختصاصی ۲۴/۷', 'en' => 'Dedicated 24/7 SOC', 'tr' => 'Özel 7/24 SOC'], ['fa' => 'SLA اختصاصی', 'en' => 'Custom SLA', 'tr' => 'Özel SLA'], ['fa' => 'دامنه نامحدود', 'en' => 'Unlimited domains', 'tr' => 'Sınırsız alan adı']]), ['contact' => true]),
        ],
        'features' => ['ddos', 'uptime', 'support', 'instant',
            ['icon' => 'gauge',
                'fa' => ['t' => 'تشخیص زیر ۳ ثانیه', 'd' => 'الگوریتم‌های ما ترافیک را لحظه‌ای تحلیل می‌کنند و حمله را قبل از رسیدن به سرور شما خنثی می‌کنند.'],
                'en' => ['t' => 'Sub-3-Second Detection', 'd' => 'Our algorithms analyze traffic in real time and neutralize attacks before they reach your server.'],
                'tr' => ['t' => '3 Saniyenin Altında Tespit', 'd' => 'Algoritmalarımız trafiği anlık analiz eder ve saldırıyı sunucunuza ulaşmadan etkisizleştirir.']],
            ['icon' => 'book',
                'fa' => ['t' => 'گزارش شفاف هر حمله', 'd' => 'نوع، حجم، مبدأ و مدت هر حمله را در پنل ببینید — بدانید دقیقاً از چه چیزی محافظت شدید.'],
                'en' => ['t' => 'Transparent Attack Reports', 'd' => 'See the type, volume, origin and duration of every attack in your panel — know exactly what you were protected from.'],
                'tr' => ['t' => 'Şeffaf Saldırı Raporları', 'd' => 'Her saldırının türünü, hacmini ve süresini panelde görün.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'آیا محافظت پایه رایگان دارید؟', 'a' => 'بله — همه سرویس‌های سرورنت (هاست، VPS، اختصاصی) محافظت L3/L4 پایه را رایگان دارند. این پلن‌ها برای حملات بزرگ‌تر و محافظت لایه ۷ (اپلیکیشن) هستند.'],
             'en' => ['q' => 'Is there free basic protection?', 'a' => 'Yes — every ServerNet service (hosting, VPS, dedicated) includes free basic L3/L4 protection. These plans cover larger attacks and Layer-7 (application) defense.'],
             'tr' => ['q' => 'Ücretsiz temel koruma var mı?', 'a' => 'Evet — tüm ServerNet hizmetleri ücretsiz temel L3/L4 koruması içerir. Bu planlar daha büyük saldırılar ve L7 içindir.']],
            ['fa' => ['q' => 'در حین حمله چه اتفاقی می‌افتد؟', 'a' => 'ترافیک به‌صورت خودکار از مراکز پالایش عبور می‌کند؛ کاربران واقعی هیچ چیزی حس نمی‌کنند. در پلن Pro تیم ما حین حمله به‌صورت زنده همراه شماست.'],
             'en' => ['q' => 'What happens during an attack?', 'a' => 'Traffic is automatically routed through scrubbing centers; real users notice nothing. On Pro, our team is live with you throughout the attack.'],
             'tr' => ['q' => 'Saldırı sırasında ne olur?', 'a' => 'Trafik otomatik olarak temizleme merkezlerinden geçer; gerçek kullanıcılar hiçbir şey fark etmez.']],
            'activation', 'refund',
        ],
    ],

    'block-storage' => [
        'icon' => 'hdd', 'group' => 'storage',
        'fa' => ['t' => 'بلاک‌استوریج', 'tag' => 'NVMe · اتصال آنی',
            'hero_t' => 'دیسک اضافه،', 'hero_g' => 'هر وقت که کم آوردید.',
            'hero_d' => 'والیوم‌های NVMe که در چند ثانیه به هر سرور ابری وصل می‌شوند — بزرگ کنید، اسنپ‌شات بگیرید، جابه‌جا کنید؛ بدون ری‌استارت.'],
        'en' => ['t' => 'Block Storage', 'tag' => 'NVMe · Instant attach',
            'hero_t' => 'Extra disks,', 'hero_g' => 'whenever you run out.',
            'hero_d' => 'NVMe volumes that attach to any cloud server in seconds — grow them, snapshot them, move them; no restarts.'],
        'tr' => ['t' => 'Block Storage', 'tag' => 'NVMe · Anında bağlama',
            'hero_t' => 'Yer bittiğinde', 'hero_g' => 'ekstra diskler.',
            'hero_d' => 'Saniyeler içinde her bulut sunucusuna bağlanan NVMe birimler — büyütün, snapshot alın, taşıyın.'],
        'chips' => ['NVMe-Backed', 'Attach in Seconds', 'Live Resize', 'Snapshots', '3× Replication'],
        'plans' => [
            $mk('VOL-100', 190, 190000, 1.90, ['100 GB NVMe Volume', ['fa' => 'اتصال آنی به هر سرور', 'en' => 'Instant attach to any server', 'tr' => 'Her sunucuya anında bağlama'], ['fa' => 'رشد آنلاین بدون ری‌استارت', 'en' => 'Live resize, no restart', 'tr' => 'Canlı boyutlandırma'], ['fa' => 'اسنپ‌شات دستی و زمان‌بندی', 'en' => 'Manual & scheduled snapshots', 'tr' => 'Manuel ve zamanlanmış snapshot'], ['fa' => 'تکرار ۳ نسخه‌ای', 'en' => '3× replication', 'tr' => '3× çoğaltma']]),
            $mk('VOL-500', 191, 790000, 7.90, ['500 GB NVMe Volume', ['fa' => 'اتصال آنی به هر سرور', 'en' => 'Instant attach to any server', 'tr' => 'Her sunucuya anında bağlama'], ['fa' => 'رشد آنلاین بدون ری‌استارت', 'en' => 'Live resize, no restart', 'tr' => 'Canlı boyutlandırma'], ['fa' => 'اسنپ‌شات دستی و زمان‌بندی', 'en' => 'Manual & scheduled snapshots', 'tr' => 'Manuel ve zamanlanmış snapshot'], ['fa' => 'تکرار ۳ نسخه‌ای', 'en' => '3× replication', 'tr' => '3× çoğaltma']], true),
            $mk('VOL-1T', 192, 1390000, 13.90, ['1 TB NVMe Volume', ['fa' => 'اتصال آنی به هر سرور', 'en' => 'Instant attach to any server', 'tr' => 'Her sunucuya anında bağlama'], ['fa' => 'رشد آنلاین بدون ری‌استارت', 'en' => 'Live resize, no restart', 'tr' => 'Canlı boyutlandırma'], ['fa' => 'اسنپ‌شات دستی و زمان‌بندی', 'en' => 'Manual & scheduled snapshots', 'tr' => 'Manuel ve zamanlanmış snapshot'], ['fa' => 'IOPS تضمینی', 'en' => 'Guaranteed IOPS', 'tr' => 'Garantili IOPS']]),
        ],
        'features' => ['scale', 'instant', 'backup', 'uptime', 'support'],
        'faqs' => ['activation', 'upgrade',
            ['fa' => ['q' => 'اگر سرورم حذف شود دیسک چه می‌شود؟', 'a' => 'والیوم مستقل از سرور است — سرور را حذف کنید، دیسک و داده‌ها می‌مانند و می‌توانید به سرور جدید وصلشان کنید.'],
             'en' => ['q' => 'What happens to the volume if I delete my server?', 'a' => 'Volumes live independently — delete the server and the disk with all data remains, ready to attach to a new server.'],
             'tr' => ['q' => 'Sunucumu silersem birim ne olur?', 'a' => 'Birimler bağımsızdır — sunucuyu silin, disk ve veriler kalır, yeni sunucuya bağlanabilir.']],
            'refund',
        ],
    ],

    'object-storage' => [
        'icon' => 'db', 'group' => 'storage',
        'fa' => ['t' => 'آبجکت‌استوریج', 'tag' => 'S3-Compatible · CDN آماده',
            'hero_t' => 'فضای S3،', 'hero_g' => 'بدون تحریم و بدون مرز.',
            'hero_d' => 'استوریج سازگار با S3 برای فایل، بکاپ و رسانه — با همان SDKها و ابزارهای آمازون کار می‌کند؛ داخل ایران یا اروپا، انتخاب با شماست.'],
        'en' => ['t' => 'Object Storage', 'tag' => 'S3-compatible · CDN-ready',
            'hero_t' => 'S3 storage,', 'hero_g' => 'without borders.',
            'hero_d' => 'S3-compatible storage for files, backups and media — works with every AWS SDK and tool; hosted in Iran or Europe, your choice.'],
        'tr' => ['t' => 'Object Storage', 'tag' => 'S3 uyumlu · CDN hazır',
            'hero_t' => 'Sınırsız', 'hero_g' => 'S3 depolama.',
            'hero_d' => 'Dosya, yedek ve medya için S3 uyumlu depolama — tüm AWS SDK\'larıyla çalışır.'],
        'chips' => ['S3 API', 'Presigned URLs', 'Versioning', 'Lifecycle Rules', 'CDN Integration'],
        'plans' => [
            $mk('OBJ-100', 193, 149000, 1.49, ['100 GB Storage', ['fa' => 'ترافیک خروجی ۲۰۰ گیگ', 'en' => '200 GB egress included', 'tr' => '200 GB çıkış dahil'], 'S3 API + Versioning', ['fa' => 'باکت نامحدود', 'en' => 'Unlimited buckets', 'tr' => 'Sınırsız bucket'], ['fa' => 'ایران یا اروپا', 'en' => 'Iran or Europe', 'tr' => 'İran veya Avrupa']]),
            $mk('OBJ-1T', 194, 990000, 9.90, ['1 TB Storage', ['fa' => 'ترافیک خروجی ۲ ترابایت', 'en' => '2 TB egress included', 'tr' => '2 TB çıkış dahil'], 'S3 API + Lifecycle', ['fa' => 'باکت نامحدود', 'en' => 'Unlimited buckets', 'tr' => 'Sınırsız bucket'], ['fa' => 'اتصال مستقیم CDN', 'en' => 'Direct CDN integration', 'tr' => 'Doğrudan CDN entegrasyonu']], true),
            $mk('OBJ-10T', 195, 8900000, 89.00, ['10 TB Storage', ['fa' => 'ترافیک خروجی ۲۰ ترابایت', 'en' => '20 TB egress included', 'tr' => '20 TB çıkış dahil'], 'S3 API + Replication', ['fa' => 'تکرار بین دو لوکیشن', 'en' => 'Cross-region replication', 'tr' => 'Bölgeler arası çoğaltma'], ['fa' => 'پشتیبانی اولویت‌دار', 'en' => 'Priority support', 'tr' => 'Öncelikli destek']]),
        ],
        'features' => ['scale', 'uptime', 'instant', 'support', 'net10'],
        'faqs' => ['activation',
            ['fa' => ['q' => 'با کدام ابزارها سازگار است؟', 'a' => 'هر ابزاری که S3 بفهمد: AWS CLI و SDKها، rclone، s3cmd، MinIO Client، افزونه‌های بکاپ وردپرس و لاراول Flysystem — فقط endpoint را عوض کنید.'],
             'en' => ['q' => 'Which tools does it work with?', 'a' => 'Anything that speaks S3: AWS CLI and SDKs, rclone, s3cmd, MinIO Client, WordPress backup plugins and Laravel Flysystem — just change the endpoint.'],
             'tr' => ['q' => 'Hangi araçlarla çalışır?', 'a' => 'S3 konuşan her şeyle: AWS CLI ve SDK\'lar, rclone, s3cmd, MinIO Client — sadece endpoint\'i değiştirin.']],
            'upgrade', 'refund',
        ],
    ],


    'ai-infrastructure' => [
        'icon' => 'bot', 'group' => 'ai',
        'fa' => ['t' => 'زیرساخت هوش مصنوعی', 'tag' => 'Private LLM · سازمانی',
            'hero_t' => 'هوش مصنوعی سازمانی،', 'hero_g' => 'روی زیرساخت خودتان.',
            'hero_d' => 'LLM خصوصی، RAG روی اسناد سازمان و ایجنت‌های سفارشی — داده‌ها هرگز از زیرساخت شما خارج نمی‌شوند. طراحی، پیاده‌سازی و نگهداری با سرورنت.'],
        'en' => ['t' => 'AI Infrastructure', 'tag' => 'Private LLM · Enterprise',
            'hero_t' => 'Enterprise AI,', 'hero_g' => 'on your own infrastructure.',
            'hero_d' => 'Private LLMs, RAG over your documents and custom agents — data never leaves your infrastructure. Designed, built and maintained by ServerNet.'],
        'tr' => ['t' => 'AI Altyapısı', 'tag' => 'Özel LLM · Kurumsal',
            'hero_t' => 'Kurumsal yapay zeka,', 'hero_g' => 'kendi altyapınızda.',
            'hero_d' => 'Özel LLM\'ler, belgeleriniz üzerinde RAG ve özel ajanlar — veriler altyapınızdan asla çıkmaz.'],
        'chips' => ['On-Prem / Private Cloud', 'RAG Pipelines', 'Custom Agents', 'Data Sovereignty', 'SLA-Backed'],
        'plans' => [
            $mk('AI-START', 199, 19900000, 199.00, [['fa' => 'LLM خصوصی تک‌سرور (GPU)', 'en' => 'Single-server private LLM (GPU)', 'tr' => 'Tek sunucu özel LLM (GPU)'], ['fa' => 'RAG تا ۱۰۰ هزار سند', 'en' => 'RAG up to 100k documents', 'tr' => '100 bin belgeye kadar RAG'], ['fa' => 'چت‌بات داخلی سازمان', 'en' => 'Internal company chatbot', 'tr' => 'Kurum içi chatbot'], ['fa' => 'آموزش تیم شما', 'en' => 'Team training included', 'tr' => 'Ekip eğitimi dahil'], ['fa' => 'نگهداری ماهانه', 'en' => 'Monthly maintenance', 'tr' => 'Aylık bakım']]),
            $mk('AI-SCALE', 200, 49900000, 499.00, [['fa' => 'کلاستر GPU با HA', 'en' => 'HA GPU cluster', 'tr' => 'HA GPU kümesi'], ['fa' => 'RAG نامحدود + چند ایجنت', 'en' => 'Unlimited RAG + multiple agents', 'tr' => 'Sınırsız RAG + çoklu ajan'], ['fa' => 'اتصال به ERP و اتوماسیون', 'en' => 'ERP & automation integration', 'tr' => 'ERP ve otomasyon entegrasyonu'], ['fa' => 'داشبورد مدیریتی', 'en' => 'Management dashboard', 'tr' => 'Yönetim panosu'], ['fa' => 'SLA + مهندس اختصاصی', 'en' => 'SLA + dedicated engineer', 'tr' => 'SLA + özel mühendis']], true),
            array_merge($mk('AI-ENT', 201, 0, 0, [['fa' => 'طراحی کاملاً سفارشی', 'en' => 'Fully custom design', 'tr' => 'Tamamen özel tasarım'], ['fa' => 'On-Prem در دیتاسنتر شما', 'en' => 'On-prem in your datacenter', 'tr' => 'Kendi veri merkezinizde'], ['fa' => 'فاین‌تیون مدل اختصاصی', 'en' => 'Custom model fine-tuning', 'tr' => 'Özel model fine-tuning'], ['fa' => 'تیم پروژه اختصاصی', 'en' => 'Dedicated project team', 'tr' => 'Özel proje ekibi']]), ['contact' => true]),
        ],
        'features' => ['support', 'uptime', 'ddos',
            ['icon' => 'lock',
                'fa' => ['t' => 'حاکمیت کامل داده', 'd' => 'مدل و داده روی سرورهای شما (یا ابر خصوصی ما در ایران) اجرا می‌شود — هیچ بایتی به سرویس‌های خارجی نمی‌رود.'],
                'en' => ['t' => 'Full Data Sovereignty', 'd' => 'Models and data run on your servers (or our private cloud in Iran) — not a single byte goes to foreign services.'],
                'tr' => ['t' => 'Tam Veri Egemenliği', 'd' => 'Model ve veri sizin sunucularınızda çalışır — tek bayt bile dış servislere gitmez.']],
            ['icon' => 'flow',
                'fa' => ['t' => 'از BPMN تا ایجنت', 'd' => 'فرایندهای سازمان را تحلیل می‌کنیم و همان‌ها را به ایجنت‌های هوشمند تبدیل می‌کنیم — تجربه ما در ERP و BPMN پشتوانه پروژه شماست.'],
                'en' => ['t' => 'From BPMN to Agents', 'd' => 'We analyze your business processes and turn them into intelligent agents — backed by our ERP and BPMN practice.'],
                'tr' => ['t' => 'BPMN\'den Ajanlara', 'd' => 'İş süreçlerinizi analiz eder ve akıllı ajanlara dönüştürürüz.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'پروژه از کجا شروع می‌شود؟', 'a' => 'با یک جلسه مشاوره رایگان: نیاز و داده‌های شما را بررسی می‌کنیم، PoC دو هفته‌ای تعریف می‌کنیم و بعد از تایید شما وارد پیاده‌سازی کامل می‌شویم.'],
             'en' => ['q' => 'Where does the project start?', 'a' => 'With a free consultation: we review your needs and data, define a two-week PoC, and move to full implementation after your sign-off.'],
             'tr' => ['q' => 'Proje nereden başlıyor?', 'a' => 'Ücretsiz bir danışmanlıkla: ihtiyaç ve verilerinizi inceler, iki haftalık PoC tanımlar, onayınızla tam uygulamaya geçeriz.']],
            ['fa' => ['q' => 'کدام مدل‌ها را پشتیبانی می‌کنید؟', 'a' => 'مدل‌های متن‌باز (Llama، Qwen، Mistral و مدل‌های فارسی) روی زیرساخت خصوصی؛ در صورت نیاز اتصال امن به APIهای تجاری هم طراحی می‌شود.'],
             'en' => ['q' => 'Which models do you support?', 'a' => 'Open-source models (Llama, Qwen, Mistral and Persian models) on private infrastructure; secure connections to commercial APIs can be designed when needed.'],
             'tr' => ['q' => 'Hangi modelleri destekliyorsunuz?', 'a' => 'Açık kaynak modeller (Llama, Qwen, Mistral) özel altyapıda; gerekirse ticari API\'lere güvenli bağlantı da tasarlanır.']],
            'manage',
        ],
    ],

    'n8n' => [
        'icon' => 'flow',
        'group' => 'apps',
        'fa' => [
            't' => 'هاست و راه‌اندازی n8n',
            'seo_t' => 'هاست n8n و راه‌اندازی اتوماسیون | منابع و بکاپ',
            'seo_d' => 'مقایسه گزینه‌های میزبانی n8n، منابع، دامنه وب‌هوک و مسئولیت بکاپ و مدیریت؛ دامنه خدمات را پیش از سفارش با سرورنت مشخص کنید.',
            'tag' => 'n8n · میزبانی و اتوماسیون',
            'hero_t' => 'راه‌اندازی n8n،',
            'hero_g' => 'با دامنه خدمات روشن.',
            'hero_d' => 'برای اجرای ورک‌فلوها، منابع، دامنه HTTPS و دسترسی لازم را انتخاب کنید. مدیریت نسخه، بکاپ، بازیابی و انتقال را پیش از شروع در سفارش مشخص کنید؛ اگر کنترل کامل سیستم‌عامل می‌خواهید، نصب مستقل روی VPS را هم مقایسه کنید.'
        ],
        'en' => [
            't' => 'n8n Hosting and Setup',
            'seo_t' => 'n8n Hosting | Resources, Backups and Setup',
            'seo_d' => 'Compare n8n hosting resources, webhook domains and maintenance responsibilities. Confirm the management, backup and restore scope before ordering.',
            'tag' => 'n8n · Hosting and automation',
            'hero_t' => 'Set up n8n',
            'hero_g' => 'with a clear service scope.',
            'hero_d' => 'Choose resources, an HTTPS domain and the access your workflows need. Define upgrades, backups, restores and migration before starting. Compare a self-managed VPS if you need full operating-system control.'
        ],
        'tr' => [
            't' => 'n8n Hosting ve Kurulum',
            'seo_t' => 'n8n Hosting | Kaynaklar, Yedekleme ve Kurulum',
            'seo_d' => 'n8n hosting kaynaklarını, webhook alan adını ve bakım sorumluluklarını karşılaştırın; yönetim ve geri yükleme kapsamını siparişten önce netleştirin.',
            'tag' => 'n8n · Hosting ve otomasyon',
            'hero_t' => 'n8n kurulumu,',
            'hero_g' => 'açık hizmet kapsamıyla.',
            'hero_d' => 'İş akışınıza uygun kaynak, HTTPS alan adı ve erişimleri seçin. Güncelleme, yedekleme, geri yükleme ve taşıma kapsamını başlamadan belirleyin. Tam sistem kontrolü için kendi VPS kurulumunuzu da karşılaştırın.'
        ],
        'chips' => ['n8n', 'Workflows', 'HTTPS Webhooks', 'Backup Planning'],
        'inc_strip' => false,
        'show_infra' => false,
        'offer_schema' => false,
        'plan_note' => [
            'fa' => 'محدودیت اجرا، منابع، نسخه، دسترسی worker، بکاپ و افزونه‌های پولی هر پلن را در پیشنهاد نهایی بررسی کنید؛ زمان تحویل و انتقال پس از بررسی محیط مشخص می‌شود.',
            'en' => 'Confirm execution limits, resources, version, worker access, backups and paid extensions in the final quote. Delivery and migration timing require an environment review.',
            'tr' => 'Çalıştırma sınırı, kaynak, sürüm, worker erişimi, yedekleme ve ücretli eklentileri son teklifte doğrulayın. Teslimat ve taşıma süresi ortam incelemesinden sonra belirlenir.'
        ]
    ,
        'plans' => [
            $mk('N8N-START', 202, 490000, 4.90, [
                ['fa' => 'سقف اجرا طبق پیشنهاد نهایی', 'en' => 'Execution limits agreed in the quote', 'tr' => 'Çalıştırma sınırı son teklife göre'],
                '1 vCPU / 2 GB RAM',
                ['fa' => 'دامنه و HTTPS طبق سفارش', 'en' => 'Domain and HTTPS scope agreed in the order', 'tr' => 'Alan adı ve HTTPS kapsamı siparişe göre'],
                ['fa' => 'برنامه بکاپ و بازیابی طبق سفارش', 'en' => 'Backup and restore schedule agreed in the order', 'tr' => 'Yedekleme ve geri yükleme planı siparişe göre'],
                ['fa' => 'روش به‌روزرسانی طبق سفارش', 'en' => 'Upgrade process agreed in the order', 'tr' => 'Güncelleme süreci siparişe göre'],
            ]),
            $mk('N8N-PRO', 203, 990000, 9.90, [
                ['fa' => 'سقف اجرا طبق پیشنهاد نهایی', 'en' => 'Execution limits agreed in the quote', 'tr' => 'Çalıştırma sınırı son teklife göre'],
                '2 vCPU / 4 GB RAM',
                ['fa' => 'دامنه و HTTPS طبق سفارش', 'en' => 'Domain and HTTPS scope agreed in the order', 'tr' => 'Alan adı ve HTTPS kapsamı siparişe göre'],
                ['fa' => 'فاصله و نگهداری بکاپ طبق سفارش', 'en' => 'Backup frequency and retention agreed in the order', 'tr' => 'Yedekleme sıklığı ve saklama süresi siparişe göre'],
                ['fa' => 'کیو و worker پس از بررسی نیاز', 'en' => 'Queue and worker access reviewed for your workload', 'tr' => 'Kuyruk ve worker erişimi iş yüküne göre değerlendirilir'],
            ], true),
            $mk('N8N-BIZ', 204, 1990000, 19.90, [
                ['fa' => 'سقف اجرا طبق پیشنهاد نهایی', 'en' => 'Execution limits agreed in the quote', 'tr' => 'Çalıştırma sınırı son teklife göre'],
                '4 vCPU / 8 GB RAM',
                ['fa' => 'منابع worker طبق پیشنهاد نهایی', 'en' => 'Worker resources agreed in the quote', 'tr' => 'Worker kaynakları son teklife göre'],
                ['fa' => 'SSO پس از بررسی نسخه و لایسنس', 'en' => 'SSO subject to edition and license review', 'tr' => 'SSO için sürüm ve lisans değerlendirmesi gerekir'],
                ['fa' => 'دامنه پشتیبانی ورک‌فلو طبق سفارش', 'en' => 'Workflow support scope agreed in the order', 'tr' => 'İş akışı desteğinin kapsamı siparişe göre'],
            ]),
        ],
        'features' => [[
                'icon' => 'server',
                'fa' => [
                    't' => 'منابع متناسب با ورک‌فلو',
                    'd' => 'تعداد اجرای هم‌زمان، حجم داده و نیاز worker را مشخص کنید تا منابع و محدودیت مناسب در پیشنهاد ثبت شوند.'
                ],
                'en' => [
                    't' => 'Size for your workflows',
                    'd' => 'Define concurrency, data size and worker needs so the quote can state the relevant resources and limits.'
                ],
                'tr' => [
                    't' => 'Workflow için kaynak seçimi',
                    'd' => 'Eşzamanlılık, veri ve worker ihtiyacını belirleyin; kaynak ve sınırlar teklifte yazılsın.'
                ]
            ], [
                'icon' => 'shield',
                'fa' => [
                    't' => 'دامنه و اتصال امن',
                    'd' => 'مسیر HTTPS، دسترسی به پنل و شیوه نگهداری اعتبارنامه‌های سرویس‌های متصل را پیش از اتصال واقعی بررسی کنید.'
                ],
                'en' => [
                    't' => 'HTTPS and access scope',
                    'd' => 'Confirm the HTTPS route, panel access and connected-service credential handling before connecting live workflows.'
                ],
                'tr' => [
                    't' => 'HTTPS ve erişim kapsamı',
                    'd' => 'Canlı iş akışlarından önce HTTPS, panel erişimi ve kimlik bilgisi yönetimini doğrulayın.'
                ]
            ], [
                'icon' => 'database',
                'fa' => [
                    't' => 'بکاپ قابل بازیابی',
                    'd' => 'دوره، محل، مدت نگهداری و مسئول بازیابی باید مشخص باشند؛ فایل workflow به‌تنهایی بکاپ کامل نیست.'
                ],
                'en' => [
                    't' => 'A restorable backup',
                    'd' => 'Agree on backup schedule, location, retention and restore ownership. Workflow files alone are not a full backup.'
                ],
                'tr' => [
                    't' => 'Geri yüklenebilir yedek',
                    'd' => 'Sıklık, konum, saklama ve geri yükleme sorumlusunu belirleyin. Workflow dosyası tek başına tam yedek değildir.'
                ]
            ]],
        'faqs' => [[
                'fa' => [
                    'q' => 'چه چیزی با نصب مستقل روی VPS فرق دارد؟',
                    'a' => 'روی VPS نگهداری سیستم‌عامل و برنامه با مدیر سرور است. در گزینه مدیریت‌شده، دقیقاً مشخص کنید کدام کارهای نسخه، امنیت، بکاپ و بازیابی در قرارداد سرورنت قرار می‌گیرد.'
                ],
                'en' => [
                    'q' => 'How does this differ from installing n8n on a VPS?',
                    'a' => 'On a VPS, its administrator maintains the OS and application. For a managed option, specify which upgrades, security, backup and restore tasks are included in the ServerNet order.'
                ],
                'tr' => [
                    'q' => 'VPS kurulumundan farkı nedir?',
                    'a' => 'VPS’de sistem ve uygulamayı sunucu yöneticisi yönetir. Yönetilen seçenekte güncelleme, güvenlik, yedekleme ve geri yükleme kapsamını siparişte belirleyin.'
                ]
            ], [
                'fa' => [
                    'q' => 'انتقال از n8n فعلی چگونه است؟',
                    'a' => 'ابتدا نسخه، دیتابیس، ورک‌فلوها، اعتبارنامه‌ها و کلید رمزنگاری بررسی می‌شوند. برنامه انتقال، توقف احتمالی و بازگشت باید مشخص شود؛ بدون بررسی، انتقال بدون قطعی را تضمین نمی‌کنیم.'
                ],
                'en' => [
                    'q' => 'Can an existing n8n instance be migrated?',
                    'a' => 'Review the version, database, workflows, credentials and encryption key first. Define the migration window, possible downtime and rollback rather than assuming a zero-downtime move.'
                ],
                'tr' => [
                    'q' => 'Mevcut n8n taşınabilir mi?',
                    'a' => 'Önce sürüm, veritabanı, workflow, kimlik bilgileri ve anahtar incelenir. Taşıma zamanı, olası kesinti ve geri dönüş planlanmalıdır.'
                ]
            ], [
                'fa' => [
                    'q' => 'بکاپ چه چیزهایی را پوشش می‌دهد؟',
                    'a' => 'محتوای بکاپ و مدت نگهداری باید در سفارش همان سرویس مشخص شود. برای بازیابی کامل، فقط خروجی workflow کافی نیست و داده و کلید لازم باید به‌صورت امن نگهداری شوند.'
                ],
                'en' => [
                    'q' => 'What does the backup include?',
                    'a' => 'The service order must define contents and retention. Workflow exports alone do not provide a full restore; the required data and key must be secured.'
                ],
                'tr' => [
                    'q' => 'Yedek neleri kapsar?',
                    'a' => 'İçerik ve saklama süresi hizmet siparişinde belirtilmelidir. Workflow çıktısı tek başına tam geri yükleme sağlamaz; gerekli veri ve anahtar güvenli saklanmalıdır.'
                ]
            ]],
    ],
];
