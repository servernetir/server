<?php

/*
|--------------------------------------------------------------------------
| فروشگاهِ سرورِ فیزیکی — HP / Dell / Lenovo / Supermicro
|--------------------------------------------------------------------------
| هر مدل یک صفحهٔ اختصاصیِ سه‌زبانه با گالری، جدولِ مشخصات و دکمهٔ استعلام دارد.
|
| ⚠️ تحویلِ سرورِ فیزیکی خودکار نیست (سخت‌افزار باید آماده/کانفیگ شود)، پس
|    قیمت‌ها «از» است و دکمه به استعلام/سفارش می‌رود، نه سبدِ خودکار.
|
| ⚠️ عکس‌ها: در `public/assets/servers/{slug}/` بگذارید و در `gallery` فهرست
|    کنید. تا وقتی عکسی نباشد، تصویرِ جای‌گزینِ برند نشان داده می‌شود
|    (placeholder.svg) — صفحه هیچ‌وقت خالی نیست.
*/

$brands = [
    'hp'         => ['label' => 'HPE ProLiant', 'color' => '#01A982'],
    'dell'       => ['label' => 'Dell PowerEdge', 'color' => '#0076CE'],
    'lenovo'     => ['label' => 'Lenovo ThinkSystem', 'color' => '#E1140A'],
    'supermicro' => ['label' => 'Supermicro', 'color' => '#1E8B3B'],
];

// برچسبِ ردیف‌های مشخصات — سه‌زبانه، یک‌بار تعریف تا در همهٔ مدل‌ها یکسان باشد.
$L = [
    'cpu'     => ['fa' => 'پردازنده', 'en' => 'Processor', 'tr' => 'İşlemci'],
    'ram'     => ['fa' => 'حافظه (RAM)', 'en' => 'Memory (RAM)', 'tr' => 'Bellek (RAM)'],
    'storage' => ['fa' => 'ذخیره‌سازی', 'en' => 'Storage', 'tr' => 'Depolama'],
    'raid'    => ['fa' => 'کنترلر RAID', 'en' => 'RAID controller', 'tr' => 'RAID denetleyici'],
    'net'     => ['fa' => 'شبکه', 'en' => 'Network', 'tr' => 'Ağ'],
    'psu'     => ['fa' => 'منبع تغذیه', 'en' => 'Power supply', 'tr' => 'Güç kaynağı'],
    'form'    => ['fa' => 'فرم‌فاکتور', 'en' => 'Form factor', 'tr' => 'Form faktörü'],
    'mgmt'    => ['fa' => 'مدیریت از راه دور', 'en' => 'Out-of-band mgmt', 'tr' => 'Uzaktan yönetim'],
    'warranty' => ['fa' => 'گارانتی', 'en' => 'Warranty', 'tr' => 'Garanti'],
];

$row = fn (array $lbl, string $fa, string $en, string $tr) => [
    'label' => $lbl, 'fa' => $fa, 'en' => $en, 'tr' => $tr,
];

return [

    'brands' => $brands,

    'models' => [

        'hpe-proliant-dl380-gen10' => [
            'brand' => 'hp', 'condition' => 'new', 'popular' => true,
            'fa' => ['name' => 'HPE ProLiant DL380 Gen10', 'tag' => 'رَک ۲U · دو سوکته · پرفروش‌ترین سرورِ دنیا',
                'hero_d' => 'اسبِ کاریِ دیتاسنتر — انعطاف بالا برای مجازی‌سازی، دیتابیس و بارِ کاریِ سنگین. تا ۲ پردازندهٔ Xeon نسل دوم و ۳ ترابایت رم.',
                'desc' => 'DL380 Gen10 محبوب‌ترین سرورِ رَکیِ جهان است؛ ترکیبِ کارایی، قابلیتِ اطمینان و توسعه‌پذیری برای هر بارِ کاری، از مجازی‌سازی تا پایگاه‌دادهٔ حجیم.'],
            'en' => ['name' => 'HPE ProLiant DL380 Gen10', 'tag' => '2U rack · dual-socket · the world\'s best-seller',
                'hero_d' => 'The datacenter workhorse — flexible for virtualization, databases and heavy workloads. Up to 2 Xeon Scalable CPUs and 3 TB of RAM.',
                'desc' => 'The DL380 Gen10 is the world\'s most popular rack server — performance, reliability and expandability for any workload, from virtualization to large databases.'],
            'tr' => ['name' => 'HPE ProLiant DL380 Gen10', 'tag' => '2U raf · çift soket · dünyanın en çok satanı',
                'hero_d' => 'Veri merkezinin beygiri — sanallaştırma, veritabanı ve ağır iş yükleri için esnek. 2 Xeon Scalable CPU ve 3 TB RAM\'e kadar.',
                'desc' => 'DL380 Gen10, dünyanın en popüler raf sunucusudur — sanallaştırmadan büyük veritabanlarına her iş yükü için performans ve güvenilirlik.'],
            'price_from' => ['irt' => 185000000, 'eur' => 1850],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon Scalable نسل ۱/۲ (تا ۵۶ هسته)', 'Up to 2× Intel Xeon Scalable Gen 1/2 (up to 56 cores)', '2× Intel Xeon Scalable\'e kadar (56 çekirdeğe kadar)'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۲۴× SFF یا ۱۲× LFF + NVMe', 'Up to 24× SFF or 12× LFF + NVMe', '24× SFF veya 12× LFF + NVMe\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array P408i با کش باتری‌دار', 'HPE Smart Array P408i, battery-backed cache', 'HPE Smart Array P408i, pilli önbellek'),
                $row($L['net'], '۴× 1GbE داخلی + کارت‌های 10/25GbE اختیاری', '4× 1GbE onboard + optional 10/25GbE', '4× 1GbE + isteğe bağlı 10/25GbE'),
                $row($L['mgmt'], 'iLO 5 (کنسولِ کامل از راه دور)', 'iLO 5 (full remote console)', 'iLO 5 (tam uzaktan konsol)'),
                $row($L['psu'], 'دوگانهٔ افزونه (Hot-plug)', 'Dual redundant (hot-plug)', 'Çift yedekli (hot-plug)'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'hpe-proliant-dl360-gen10' => [
            'brand' => 'hp', 'condition' => 'new', 'popular' => false,
            'fa' => ['name' => 'HPE ProLiant DL360 Gen10', 'tag' => 'رَک ۱U · تراکمِ بالا',
                'hero_d' => 'کاراییِ دو سوکته در ارتفاعِ ۱U — بهترین انتخاب برای دیتاسنترهایی که هر واحدِ رَک برایشان مهم است.',
                'desc' => 'DL360 Gen10 همان قدرتِ DL380 را در نصفِ ارتفاع می‌دهد؛ ایده‌آل برای مجازی‌سازیِ متراکم و محیط‌های با محدودیتِ فضای رَک.'],
            'en' => ['name' => 'HPE ProLiant DL360 Gen10', 'tag' => '1U rack · high density',
                'hero_d' => 'Dual-socket performance in 1U — the best pick for datacenters where every rack unit counts.',
                'desc' => 'The DL360 Gen10 delivers DL380-class power in half the height; ideal for dense virtualization and rack-constrained environments.'],
            'tr' => ['name' => 'HPE ProLiant DL360 Gen10', 'tag' => '1U raf · yüksek yoğunluk',
                'hero_d' => '1U\'da çift soket performansı — her raf biriminin önemli olduğu veri merkezleri için en iyi seçim.',
                'desc' => 'DL360 Gen10, DL380 gücünü yarı yükseklikte sunar; yoğun sanallaştırma için idealdir.'],
            'price_from' => ['irt' => 168000000, 'eur' => 1680],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon Scalable نسل ۱/۲', 'Up to 2× Intel Xeon Scalable Gen 1/2', '2× Intel Xeon Scalable\'e kadar'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۱۰× SFF + NVMe', 'Up to 10× SFF + NVMe', '10× SFF + NVMe\'ye kadar'),
                $row($L['net'], '۴× 1GbE + کارت‌های اختیاریِ 10/25GbE', '4× 1GbE + optional 10/25GbE', '4× 1GbE + isteğe bağlı 10/25GbE'),
                $row($L['mgmt'], 'iLO 5', 'iLO 5', 'iLO 5'),
                $row($L['form'], 'رَک ۱U', '1U rack', '1U raf'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'dell-poweredge-r740' => [
            'brand' => 'dell', 'condition' => 'new', 'popular' => true,
            'fa' => ['name' => 'Dell PowerEdge R740', 'tag' => 'رَک ۲U · مناسبِ GPU و مجازی‌سازی',
                'hero_d' => 'پلتفرمِ همه‌کارهٔ Dell برای مجازی‌سازی، VDI و شتاب‌دهندهٔ GPU — تا ۳ کارتِ گرافیکِ دولاین.',
                'desc' => 'R740 با معماریِ متعادلِ Dell و پشتیبانی از GPU، انتخابِ محبوبِ محیط‌های مجازی‌سازی و هوش مصنوعیِ سبک است.'],
            'en' => ['name' => 'Dell PowerEdge R740', 'tag' => '2U rack · great for GPU & virtualization',
                'hero_d' => 'Dell\'s versatile platform for virtualization, VDI and GPU acceleration — up to 3 double-width GPUs.',
                'desc' => 'The R740 with Dell\'s balanced architecture and GPU support is a popular choice for virtualization and light AI workloads.'],
            'tr' => ['name' => 'Dell PowerEdge R740', 'tag' => '2U raf · GPU ve sanallaştırma için ideal',
                'hero_d' => 'Sanallaştırma, VDI ve GPU hızlandırma için Dell\'in çok yönlü platformu — 3 çift genişlikli GPU\'ya kadar.',
                'desc' => 'Dengeli mimarisi ve GPU desteğiyle R740, sanallaştırma ortamları için popüler bir seçimdir.'],
            'price_from' => ['irt' => 179000000, 'eur' => 1790],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon Scalable نسل ۱/۲ (تا ۵۶ هسته)', 'Up to 2× Intel Xeon Scalable Gen 1/2', '2× Intel Xeon Scalable\'e kadar'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar'),
                $row($L['storage'], 'تا ۱۶× SFF یا ۸× LFF + NVMe', 'Up to 16× SFF or 8× LFF + NVMe', '16× SFF veya 8× LFF + NVMe\'ye kadar'),
                $row($L['raid'], 'Dell PERC H730P/H740P', 'Dell PERC H730P/H740P', 'Dell PERC H730P/H740P'),
                $row($L['net'], 'کارت‌های شبکهٔ 1/10/25GbE قابلِ انتخاب', '1/10/25GbE selectable NICs', '1/10/25GbE seçilebilir NIC'),
                $row($L['mgmt'], 'iDRAC 9 (کنسولِ کاملِ از راه دور)', 'iDRAC 9 (full remote console)', 'iDRAC 9 (tam uzaktan konsol)'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'dell-poweredge-r640' => [
            'brand' => 'dell', 'condition' => 'refurb', 'popular' => false,
            'fa' => ['name' => 'Dell PowerEdge R640', 'tag' => 'رَک ۱U · اقتصادیِ بازسازی‌شده',
                'hero_d' => 'گزینهٔ به‌صرفهٔ ۱U با گارانتیِ ما — کاراییِ نسلِ Scalable برای بودجه‌های محدود، تست‌شده و آماده.',
                'desc' => 'R640 بازسازی‌شده، همان قابلیتِ اطمینانِ Dell را با قیمتی اقتصادی‌تر می‌دهد؛ هر دستگاه پیش از تحویل کامل تست و گارانتی می‌شود.'],
            'en' => ['name' => 'Dell PowerEdge R640', 'tag' => '1U rack · budget refurbished',
                'hero_d' => 'A cost-effective 1U with our warranty — Scalable-gen performance for tight budgets, tested and ready.',
                'desc' => 'The refurbished R640 delivers Dell reliability at a friendlier price; every unit is fully tested and warrantied before delivery.'],
            'tr' => ['name' => 'Dell PowerEdge R640', 'tag' => '1U raf · ekonomik yenilenmiş',
                'hero_d' => 'Garantimizle uygun maliyetli 1U — kısıtlı bütçeler için Scalable nesil performans, test edilmiş ve hazır.',
                'desc' => 'Yenilenmiş R640, Dell güvenilirliğini daha uygun fiyata sunar; her birim teslimden önce test edilir ve garantilenir.'],
            'price_from' => ['irt' => 98000000, 'eur' => 980],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon Scalable نسل ۱/۲', 'Up to 2× Intel Xeon Scalable Gen 1/2', '2× Intel Xeon Scalable\'e kadar'),
                $row($L['ram'], 'تا ۱.۵ ترابایت DDR4', 'Up to 1.5 TB DDR4', '1.5 TB DDR4\'e kadar'),
                $row($L['storage'], 'تا ۱۰× SFF + NVMe', 'Up to 10× SFF + NVMe', '10× SFF + NVMe\'ye kadar'),
                $row($L['mgmt'], 'iDRAC 9', 'iDRAC 9', 'iDRAC 9'),
                $row($L['form'], 'رَک ۱U · بازسازی‌شدهٔ گواهی‌دار', '1U rack · certified refurbished', '1U raf · sertifikalı yenilenmiş'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'lenovo-thinksystem-sr650' => [
            'brand' => 'lenovo', 'condition' => 'new', 'popular' => false,
            'fa' => ['name' => 'Lenovo ThinkSystem SR650', 'tag' => 'رَک ۲U · رکورددارِ کارایی',
                'hero_d' => 'سرورِ ۲U لنوو با ده‌ها رکوردِ جهانیِ بنچمارک — تعادلِ عالیِ کارایی، ذخیره‌سازی و مصرفِ انرژی.',
                'desc' => 'SR650 با طراحیِ حرارتیِ پیشرفتهٔ لنوو، کاراییِ پایدار و مصرفِ بهینه را برای مجازی‌سازی و تحلیلِ داده فراهم می‌کند.'],
            'en' => ['name' => 'Lenovo ThinkSystem SR650', 'tag' => '2U rack · benchmark record-holder',
                'hero_d' => 'Lenovo\'s 2U server holding dozens of world benchmark records — an excellent balance of performance, storage and efficiency.',
                'desc' => 'With Lenovo\'s advanced thermal design, the SR650 delivers steady performance and efficiency for virtualization and analytics.'],
            'tr' => ['name' => 'Lenovo ThinkSystem SR650', 'tag' => '2U raf · benchmark rekortmeni',
                'hero_d' => 'Onlarca dünya benchmark rekoruna sahip Lenovo\'nun 2U sunucusu — performans, depolama ve verimlilik dengesi.',
                'desc' => 'Lenovo\'nun gelişmiş termal tasarımıyla SR650, sanallaştırma ve analiz için istikrarlı performans sunar.'],
            'price_from' => ['irt' => 172000000, 'eur' => 1720],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon Scalable نسل ۱/۲', 'Up to 2× Intel Xeon Scalable Gen 1/2', '2× Intel Xeon Scalable\'e kadar'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar'),
                $row($L['storage'], 'تا ۲۴× SFF یا ۱۴× LFF + NVMe', 'Up to 24× SFF or 14× LFF + NVMe', '24× SFF veya 14× LFF + NVMe\'ye kadar'),
                $row($L['mgmt'], 'XClarity Controller (XCC)', 'XClarity Controller (XCC)', 'XClarity Controller (XCC)'),
                $row($L['net'], 'کارت‌های 1/10/25GbE قابلِ انتخاب', '1/10/25GbE selectable NICs', '1/10/25GbE seçilebilir NIC'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'supermicro-superserver' => [
            'brand' => 'supermicro', 'condition' => 'new', 'popular' => false,
            'fa' => ['name' => 'Supermicro SuperServer (سفارشی)', 'tag' => 'کانفیگِ کاملاً سفارشی',
                'hero_d' => 'سرورِ ساخته‌شده بر اساسِ نیازِ دقیقِ شما — از ذخیره‌سازیِ حجیم تا خوشهٔ GPU. هر قطعه را با کارشناسِ ما انتخاب کنید.',
                'desc' => 'اگر کانفیگِ آماده جوابگو نیست، Supermicro امکانِ ساختِ سرورِ کاملاً سفارشی می‌دهد؛ از فایل‌سرورِ ده‌ها ترابایتی تا نودِ آموزشِ هوش مصنوعی.'],
            'en' => ['name' => 'Supermicro SuperServer (custom)', 'tag' => 'fully custom build',
                'hero_d' => 'A server built to your exact needs — from massive storage to GPU clusters. Pick every part with our specialist.',
                'desc' => 'When stock configs don\'t fit, Supermicro enables fully custom builds; from multi-hundred-TB file servers to AI training nodes.'],
            'tr' => ['name' => 'Supermicro SuperServer (özel)', 'tag' => 'tamamen özel yapılandırma',
                'hero_d' => 'İhtiyacınıza göre üretilen sunucu — büyük depolamadan GPU kümelerine. Her parçayı uzmanımızla seçin.',
                'desc' => 'Hazır yapılandırmalar yetmezse, Supermicro tamamen özel yapılandırmalara olanak tanır; büyük dosya sunucularından AI eğitim düğümlerine.'],
            'price_from' => ['contact' => true],
            'specs' => [
                $row($L['cpu'], 'Intel Xeon یا AMD EPYC (تک/دوسوکته)', 'Intel Xeon or AMD EPYC (single/dual)', 'Intel Xeon veya AMD EPYC'),
                $row($L['ram'], 'تا ۴ ترابایت DDR4/DDR5', 'Up to 4 TB DDR4/DDR5', '4 TB DDR4/DDR5\'e kadar'),
                $row($L['storage'], 'تا ده‌ها ترابایت — SATA/SAS/NVMe', 'Up to dozens of TB — SATA/SAS/NVMe', 'Onlarca TB\'a kadar — SATA/SAS/NVMe'),
                $row($L['net'], 'تا 100GbE', 'Up to 100GbE', '100GbE\'ye kadar'),
                $row($L['mgmt'], 'IPMI 2.0 + Redfish', 'IPMI 2.0 + Redfish', 'IPMI 2.0 + Redfish'),
                $row($L['form'], 'رَک ۱U/۲U/۴U یا Tower', '1U/2U/4U rack or Tower', '1U/2U/4U raf veya Tower'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

    ],
];
