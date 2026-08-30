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
            'price_from' => ['contact' => true],
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
            'price_from' => ['contact' => true],
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

        // ═══════════ HPE ProLiant نسل ۹ (Gen9) — بازسازی‌شده ═══════════

        'hpe-proliant-dl380-gen9' => [
            'brand' => 'hp', 'condition' => 'refurb', 'popular' => true,
            'fa' => ['name' => 'HPE ProLiant DL380 Gen9', 'tag' => 'رَک ۲U · دو سوکته · پرفروش‌ترین سرورِ بازسازی‌شده',
                'hero_d' => 'محبوب‌ترین انتخابِ بازارِ کارکرده — پردازندهٔ Xeon E5 v3/v4، تا ۴۴ هسته و ۳ ترابایت رمِ DDR4، با گارانتیِ ما و قیمتی به‌صرفه.',
                'desc' => 'DL380 Gen9 نقطهٔ طلاییِ «قدرت به قیمت» است؛ همان معماریِ اثبات‌شدهٔ DL380 با پردازنده‌های DDR4 که هنوز برای مجازی‌سازی، دیتابیس و بارِ کاریِ تولیدی بیش از کافی است.'],
            'en' => ['name' => 'HPE ProLiant DL380 Gen9', 'tag' => '2U rack · dual-socket · the best-selling refurbished server',
                'hero_d' => 'The market\'s favourite used server — Xeon E5 v3/v4, up to 44 cores and 3 TB of DDR4 RAM, with our warranty at a friendly price.',
                'desc' => 'The DL380 Gen9 is the sweet spot of power-for-price; the same proven DL380 architecture with DDR4 CPUs that is still more than enough for virtualization, databases and production workloads.'],
            'tr' => ['name' => 'HPE ProLiant DL380 Gen9', 'tag' => '2U raf · çift soket · en çok satan yenilenmiş sunucu',
                'hero_d' => 'Pazarın favori ikinci el sunucusu — Xeon E5 v3/v4, 44 çekirdeğe ve 3 TB DDR4 RAM\'e kadar, garantimizle uygun fiyata.',
                'desc' => 'DL380 Gen9, güç/fiyat dengesinin altın noktasıdır; sanallaştırma, veritabanı ve üretim iş yükleri için hâlâ fazlasıyla yeterli olan DDR4 işlemcili kanıtlanmış DL380 mimarisi.'],
            'price_from' => ['contact' => true],
            'body' => [
                'fa' => "اگر دنبالِ یک سرورِ کاری و قابلِ اعتماد با کمترین هزینه هستید، DL380 Gen9 دقیقاً همان چیزی است که باید ببینید. این نسل با پردازنده‌های Intel Xeon E5-2600 نسل سوم و چهارم (v3/v4) کار می‌کند؛ یعنی تا ۲۲ هسته در هر سوکت و مجموعاً تا ۴۴ هسته و ۸۸ رشتهٔ پردازشی. حافظهٔ DDR4 روی ۲۴ اسلات تا ۳ ترابایت گسترش می‌یابد و همین، دستگاه را برای اجرای ده‌ها ماشینِ مجازیِ هم‌زمان آماده می‌کند.\n\nکاربردِ اصلی‌اش مجازی‌سازی (VMware / Proxmox / Hyper-V)، سرورِ دیتابیس، فایل‌سرور و میزبانیِ اپلیکیشن‌های سازمانی است. کنترلرِ Smart Array P440ar با کشِ باتری‌دار از داده‌ها در برابرِ قطعِ برق محافظت می‌کند و مدیریتِ کاملِ از راه دور با iLO 4 اجازه می‌دهد بدونِ حضورِ فیزیکی، سرور را روشن/خاموش، نصب و عیب‌یابی کنید. هر دستگاه پیش از تحویل کاملاً تست و با گارانتیِ سرورنت ارائه می‌شود.",
                'en' => "If you want a dependable workhorse at the lowest cost, the DL380 Gen9 is exactly what to look at. This generation runs Intel Xeon E5-2600 v3/v4 CPUs — up to 22 cores per socket, 44 cores and 88 threads in total. DDR4 memory scales to 3 TB across 24 slots, making it ready to run dozens of virtual machines at once.\n\nIts sweet spot is virtualization (VMware / Proxmox / Hyper-V), database servers, file servers and enterprise application hosting. The Smart Array P440ar controller with battery-backed cache protects your data against power loss, and full out-of-band management via iLO 4 lets you power, install and troubleshoot the server remotely. Every unit is fully tested and shipped with a ServerNet warranty.",
                'tr' => "En düşük maliyetle güvenilir bir beygir istiyorsanız, DL380 Gen9 tam da bakmanız gereken şeydir. Bu nesil Intel Xeon E5-2600 v3/v4 işlemcilerle çalışır — soket başına 22 çekirdeğe, toplamda 44 çekirdek ve 88 iş parçacığına kadar. DDR4 bellek 24 yuvada 3 TB'a kadar ölçeklenir ve aynı anda onlarca sanal makine çalıştırmaya hazırdır.\n\nİdeal kullanımı sanallaştırma (VMware / Proxmox / Hyper-V), veritabanı sunucuları, dosya sunucuları ve kurumsal uygulama barındırmadır. Pilli önbelleğe sahip Smart Array P440ar denetleyicisi verilerinizi elektrik kesintisine karşı korur; iLO 4 ile tam uzaktan yönetim, sunucuyu uzaktan açıp kapatmanıza, kurmanıza ve sorun gidermenize olanak tanır. Her birim tamamen test edilir ve ServerNet garantisiyle gönderilir."],
            'strengths' => [
                'fa' => ['بهترین نسبتِ قدرت به قیمت در بازارِ بازسازی‌شده', 'تا ۴۴ هسته و ۳ ترابایت رمِ DDR4 برای مجازی‌سازیِ سنگین', 'کنترلرِ RAID با کشِ باتری‌دار و دیسک‌های Hot-plug', 'مدیریتِ کاملِ از راه دور با iLO 4', 'قطعاتِ فراوان و ارزان — نگهداری و ارتقای آسان'],
                'en' => ['Best power-to-price ratio on the refurbished market', 'Up to 44 cores and 3 TB DDR4 for heavy virtualization', 'RAID controller with battery-backed cache and hot-plug drives', 'Full remote management with iLO 4', 'Abundant, cheap parts — easy to maintain and upgrade'],
                'tr' => ['Yenilenmiş pazarda en iyi güç/fiyat oranı', 'Yoğun sanallaştırma için 44 çekirdeğe ve 3 TB DDR4\'e kadar', 'Pilli önbellekli RAID denetleyici ve hot-plug diskler', 'iLO 4 ile tam uzaktan yönetim', 'Bol ve ucuz parçalar — kolay bakım ve yükseltme']],
            'weaknesses' => [
                'fa' => ['مصرفِ برق و صدای بیشتر نسبت به نسل‌های جدیدتر', 'پردازندهٔ E5 v3/v4 از جدیدترین دستورالعمل‌های AI / AVX-512 پشتیبانی نمی‌کند', 'بدونِ پشتیبانیِ رسمیِ HPE (گارانتی با ماست)'],
                'en' => ['Higher power draw and noise than newer generations', 'E5 v3/v4 CPUs lack the newest AI / AVX-512 instructions', 'No official HPE support (the warranty is with us)'],
                'tr' => ['Yeni nesillere göre daha yüksek güç tüketimi ve gürültü', 'E5 v3/v4 işlemciler en yeni AI / AVX-512 komutlarını desteklemez', 'Resmi HPE desteği yok (garanti bizde)']],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon E5-2600 v3/v4 (تا ۴۴ هسته)', 'Up to 2× Intel Xeon E5-2600 v3/v4 (up to 44 cores)', '2× Intel Xeon E5-2600 v3/v4\'e kadar (44 çekirdeğe kadar)'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۲۴× SFF یا ۱۲× LFF + NVMe', 'Up to 24× SFF or 12× LFF + NVMe', '24× SFF veya 12× LFF + NVMe\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array P440ar با کشِ باتری‌دار', 'HPE Smart Array P440ar, battery-backed cache', 'HPE Smart Array P440ar, pilli önbellek'),
                $row($L['net'], '۴× 1GbE داخلی + کارت‌های 10/25GbE اختیاری', '4× 1GbE onboard + optional 10/25GbE', '4× 1GbE + isteğe bağlı 10/25GbE'),
                $row($L['mgmt'], 'iLO 4 (کنسولِ کامل از راه دور)', 'iLO 4 (full remote console)', 'iLO 4 (tam uzaktan konsol)'),
                $row($L['psu'], 'دوگانهٔ افزونه (Hot-plug)', 'Dual redundant (hot-plug)', 'Çift yedekli (hot-plug)'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'hpe-proliant-dl360-gen9' => [
            'brand' => 'hp', 'condition' => 'refurb', 'popular' => false,
            'fa' => ['name' => 'HPE ProLiant DL360 Gen9', 'tag' => 'رَک ۱U · تراکمِ بالا · بازسازی‌شده',
                'hero_d' => 'همان قدرتِ DL380 Gen9 در ارتفاعِ ۱U — انتخابِ اقتصادیِ دیتاسنترهایی که هر واحدِ رَک برایشان طلاست.',
                'desc' => 'DL360 Gen9 نسخهٔ نصف‌ارتفاعِ DL380 است؛ دو سوکته، DDR4 و iLO 4، برای مجازی‌سازیِ متراکم و کولوکیشنِ کم‌فضا با کمترین هزینه.'],
            'en' => ['name' => 'HPE ProLiant DL360 Gen9', 'tag' => '1U rack · high density · refurbished',
                'hero_d' => 'The same DL380 Gen9 power in 1U — the budget pick for datacenters where every rack unit is gold.',
                'desc' => 'The DL360 Gen9 is the half-height DL380; dual-socket, DDR4 and iLO 4, for dense virtualization and space-tight colocation at the lowest cost.'],
            'tr' => ['name' => 'HPE ProLiant DL360 Gen9', 'tag' => '1U raf · yüksek yoğunluk · yenilenmiş',
                'hero_d' => '1U\'da aynı DL380 Gen9 gücü — her raf biriminin altın değerinde olduğu veri merkezleri için ekonomik seçim.',
                'desc' => 'DL360 Gen9, yarı yükseklikteki DL380\'dir; çift soket, DDR4 ve iLO 4 ile yoğun sanallaştırma ve dar alanlı barındırma için.'],
            'price_from' => ['contact' => true],
            'body' => [
                'fa' => "وقتی فضای رَک محدود است ولی به قدرتِ دو سوکته نیاز دارید، DL360 Gen9 پاسخِ منطقی است. تمامِ توانِ پردازشیِ برادرِ بزرگ‌ترش (Xeon E5-2600 v3/v4، تا ۴۴ هسته و ۳ ترابایت DDR4) را در نیمی از ارتفاع جای داده است؛ پس در یک رَکِ استاندارد می‌توانید دو برابر سرور بگذارید.\n\nاین مدل برای ارائه‌دهنده‌های هاست، نودهای مجازی‌سازی و محیط‌هایی که برقِ ورودی و فضای فیزیکی محدود دارند ایده‌آل است. طراحیِ حرارتیِ ۱U یعنی فن‌ها پرسرعت‌ترند و صدا کمی بیشتر است — بهایِ کوچکی برای تراکمِ بالا. مثلِ همهٔ سرورهای ما، کاملاً تست‌شده و گارانتی‌دار تحویل می‌شود.",
                'en' => "When rack space is tight but you still need dual-socket power, the DL360 Gen9 is the logical answer. It packs all of its bigger sibling's compute (Xeon E5-2600 v3/v4, up to 44 cores and 3 TB DDR4) into half the height — so you can fit twice as many servers in a standard rack.\n\nIt is ideal for hosting providers, virtualization nodes and environments with limited inbound power and floor space. The 1U thermal design means faster fans and a little more noise — a small price for high density. Like all our servers, it ships fully tested and warrantied.",
                'tr' => "Raf alanı dar ama yine de çift soket güce ihtiyacınız varsa, DL360 Gen9 mantıklı cevaptır. Büyük kardeşinin tüm işlem gücünü (Xeon E5-2600 v3/v4, 44 çekirdeğe ve 3 TB DDR4'e kadar) yarı yükseklikte toplar — böylece standart bir rafa iki kat sunucu sığdırabilirsiniz.\n\nBarındırma sağlayıcıları, sanallaştırma düğümleri ve giriş gücü ile zemin alanı sınırlı ortamlar için idealdir. 1U termal tasarım daha hızlı fanlar ve biraz daha fazla gürültü demektir — yüksek yoğunluk için küçük bir bedel. Tüm sunucularımız gibi tamamen test edilmiş ve garantili gönderilir."],
            'strengths' => [
                'fa' => ['قدرتِ دو سوکته در نصفِ فضای رَک', 'تا ۴۴ هسته و ۳ ترابایت DDR4', 'ایده‌آل برای نودهای مجازی‌سازی و ارائه‌دهنده‌های هاست', 'iLO 4 و کنترلرِ RAID با کشِ باتری‌دار', 'صرفه‌جوییِ چشمگیر در فضا و هزینهٔ کولوکیشن'],
                'en' => ['Dual-socket power in half the rack space', 'Up to 44 cores and 3 TB DDR4', 'Ideal for virtualization nodes and hosting providers', 'iLO 4 and RAID controller with battery-backed cache', 'Big savings on rack space and colocation cost'],
                'tr' => ['Yarı raf alanında çift soket güç', '44 çekirdeğe ve 3 TB DDR4\'e kadar', 'Sanallaştırma düğümleri ve barındırma sağlayıcıları için ideal', 'iLO 4 ve pilli önbellekli RAID denetleyici', 'Raf alanı ve barındırma maliyetinde büyük tasarruf']],
            'weaknesses' => [
                'fa' => ['به دلیلِ ارتفاعِ ۱U، صدا و سرعتِ فن‌ها بیشتر است', 'ظرفیتِ دیسکِ داخلی کمتر از مدلِ ۲U', 'برای شتاب‌دهندهٔ GPU مناسب نیست (فضای کارت محدود)'],
                'en' => ['Louder and faster fans due to the 1U height', 'Fewer internal drive bays than the 2U model', 'Not suited to GPU accelerators (limited card space)'],
                'tr' => ['1U yükseklik nedeniyle daha gürültülü ve hızlı fanlar', '2U modele göre daha az dahili disk yuvası', 'GPU hızlandırıcılar için uygun değil (sınırlı kart alanı)']],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon E5-2600 v3/v4 (تا ۴۴ هسته)', 'Up to 2× Intel Xeon E5-2600 v3/v4 (up to 44 cores)', '2× Intel Xeon E5-2600 v3/v4\'e kadar'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۱۰× SFF + NVMe', 'Up to 10× SFF + NVMe', '10× SFF + NVMe\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array P440ar با کشِ باتری‌دار', 'HPE Smart Array P440ar, battery-backed cache', 'HPE Smart Array P440ar, pilli önbellek'),
                $row($L['net'], '۴× 1GbE + کارت‌های اختیاریِ 10/25GbE', '4× 1GbE + optional 10/25GbE', '4× 1GbE + isteğe bağlı 10/25GbE'),
                $row($L['mgmt'], 'iLO 4', 'iLO 4', 'iLO 4'),
                $row($L['form'], 'رَک ۱U', '1U rack', '1U raf'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'hpe-proliant-dl180-gen9' => [
            'brand' => 'hp', 'condition' => 'refurb', 'popular' => false,
            'fa' => ['name' => 'HPE ProLiant DL180 Gen9', 'tag' => 'رَک ۲U · ذخیره‌سازیِ حجیم · اقتصادی',
                'hero_d' => 'سرورِ ذخیره‌سازیِ مقرون‌به‌صرفه — تا ۱۲ دیسکِ بزرگِ LFF، مناسبِ بکاپ، آرشیو، NAS و فایل‌سرور.',
                'desc' => 'DL180 Gen9 برای جایی ساخته شده که «فضا» مهم‌تر از «هستهٔ پردازشی» است؛ ظرفیتِ چند ده ترابایتی با قیمتی به‌صرفه برای بکاپ و آرشیوِ سازمانی.'],
            'en' => ['name' => 'HPE ProLiant DL180 Gen9', 'tag' => '2U rack · high-capacity storage · budget',
                'hero_d' => 'A cost-effective storage server — up to 12 large LFF drives, great for backup, archiving, NAS and file serving.',
                'desc' => 'The DL180 Gen9 is built where capacity matters more than cores; tens of terabytes at a budget price for backup and enterprise archiving.'],
            'tr' => ['name' => 'HPE ProLiant DL180 Gen9', 'tag' => '2U raf · yüksek kapasiteli depolama · ekonomik',
                'hero_d' => 'Uygun maliyetli depolama sunucusu — 12 büyük LFF diske kadar, yedekleme, arşivleme, NAS ve dosya sunumu için ideal.',
                'desc' => 'DL180 Gen9, çekirdekten çok kapasitenin önemli olduğu yerler için üretilmiştir; yedekleme ve kurumsal arşivleme için uygun fiyata onlarca terabayt.'],
            'price_from' => ['contact' => true],
            'body' => [
                'fa' => "هر سازمانی به جایی برای نگهداریِ حجمِ زیادِ داده نیاز دارد: بکاپ‌ها، آرشیوِ ویدیو، فایل‌سرورِ مشترک یا ذخیره‌سازیِ دوربین‌های مداربسته. DL180 Gen9 دقیقاً برای همین ساخته شده؛ به‌جای تمرکز بر هستهٔ پردازشیِ زیاد، شاسیِ ۲U را پر از دیسکِ بزرگِ LFF می‌کند تا با کمترین هزینه به ازای هر ترابایت، فضای فراوان بدهد.\n\nهمچنان دو سوکتهٔ Xeon E5 v3/v4 و DDR4 دارد، پس برای اجرای نرم‌افزارِ بکاپ، NAS نرم‌افزاری (TrueNAS / Unraid) یا فایل‌سرورِ سازمانی به‌قدرِ کافی توان دارد. مدیریتِ iLO 4 و کنترلرِ RAID هم سرِ جایشان‌اند. اگر هدفتان «ذخیره‌سازیِ ارزان و مطمئن» است، این مدل بهترین شروع است.",
                'en' => "Every organization needs somewhere to keep large volumes of data: backups, video archives, a shared file server or CCTV storage. The DL180 Gen9 is built exactly for that; instead of chasing high core counts, it fills a 2U chassis with big LFF drives to deliver abundant space at the lowest cost per terabyte.\n\nIt still carries dual Xeon E5 v3/v4 sockets and DDR4, so it has plenty of horsepower to run backup software, a software NAS (TrueNAS / Unraid) or an enterprise file server. iLO 4 management and a RAID controller are in place too. If your goal is cheap, reliable storage, this model is the best place to start.",
                'tr' => "Her kuruluşun büyük hacimli verileri saklamak için bir yere ihtiyacı vardır: yedekler, video arşivleri, paylaşımlı dosya sunucusu veya güvenlik kamerası depolaması. DL180 Gen9 tam bunun için üretilmiştir; yüksek çekirdek sayısı yerine, 2U kasayı büyük LFF disklerle doldurarak terabayt başına en düşük maliyetle bol alan sunar.\n\nHâlâ çift Xeon E5 v3/v4 soketi ve DDR4 taşır, bu yüzden yedekleme yazılımı, yazılımsal NAS (TrueNAS / Unraid) veya kurumsal dosya sunucusu çalıştırmak için bolca güce sahiptir. iLO 4 yönetimi ve RAID denetleyicisi de yerindedir. Amacınız ucuz ve güvenilir depolama ise, bu model başlamak için en iyi yerdir."],
            'strengths' => [
                'fa' => ['کمترین هزینه به ازای هر ترابایتِ ذخیره‌سازی', 'تا ۱۲ دیسکِ بزرگِ LFF در شاسیِ ۲U', 'مناسبِ بکاپ، آرشیو، NAS و فایل‌سرور', 'همچنان دو سوکته و DDR4 برای اجرای نرم‌افزار', 'iLO 4 و کنترلرِ RAID سازمانی'],
                'en' => ['Lowest cost per terabyte of storage', 'Up to 12 large LFF drives in a 2U chassis', 'Great for backup, archiving, NAS and file serving', 'Still dual-socket with DDR4 to run the software', 'iLO 4 and an enterprise RAID controller'],
                'tr' => ['Terabayt başına en düşük depolama maliyeti', '2U kasada 12 büyük LFF diske kadar', 'Yedekleme, arşivleme, NAS ve dosya sunumu için ideal', 'Yazılımı çalıştırmak için hâlâ çift soket ve DDR4', 'iLO 4 ve kurumsal RAID denetleyici']],
            'weaknesses' => [
                'fa' => ['برای بارِ کاریِ سنگینِ پردازشی یا مجازی‌سازیِ بزرگ بهینه نیست', 'تعدادِ اسلاتِ رم کمتر از DL380', 'دیسکِ SFF/NVMe کمتری نسبت به مدل‌های محاسباتی دارد'],
                'en' => ['Not optimized for heavy compute or large-scale virtualization', 'Fewer memory slots than the DL380', 'Fewer SFF/NVMe bays than the compute models'],
                'tr' => ['Ağır işlem veya büyük ölçekli sanallaştırma için optimize değil', 'DL380\'e göre daha az bellek yuvası', 'İşlem modellerine göre daha az SFF/NVMe yuvası']],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon E5-2600 v3/v4', 'Up to 2× Intel Xeon E5-2600 v3/v4', '2× Intel Xeon E5-2600 v3/v4\'e kadar'),
                $row($L['ram'], 'تا ۱.۵ ترابایت DDR4', 'Up to 1.5 TB DDR4', '1.5 TB DDR4\'e kadar'),
                $row($L['storage'], 'تا ۱۲× LFF (دیسکِ بزرگ) + ۲× SFF داخلی', 'Up to 12× LFF + 2× internal SFF', '12× LFF + 2× dahili SFF\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array (H240/P440)', 'HPE Smart Array (H240/P440)', 'HPE Smart Array (H240/P440)'),
                $row($L['net'], '۲× 1GbE داخلی + کارت‌های اختیاری', '2× 1GbE onboard + optional cards', '2× 1GbE + isteğe bağlı kartlar'),
                $row($L['mgmt'], 'iLO 4', 'iLO 4', 'iLO 4'),
                $row($L['form'], 'رَک ۲U', '2U rack', '2U raf'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        // ═══════════ HPE ProLiant نسل ۸ (Gen8) — اقتصادی‌ترین ═══════════

        'hpe-proliant-dl380p-gen8' => [
            'brand' => 'hp', 'condition' => 'refurb', 'popular' => false,
            'fa' => ['name' => 'HPE ProLiant DL380p Gen8', 'tag' => 'رَک ۲U · ارزان‌ترین ورودیِ سرورِ سازمانی',
                'hero_d' => 'کم‌هزینه‌ترین راهِ داشتنِ یک سرورِ واقعیِ سازمانی — دو سوکته، iLO 4 و RAID، برای آزمایشگاه، توسعه و بارِ کاریِ سبک.',
                'desc' => 'DL380p Gen8 ارزان‌ترین نقطهٔ ورود به دنیای سرورهای رَکیِ HPE است؛ برای لبِ راه‌اندازی، محیطِ تست، توسعه و سرویس‌های سبک، ارزشی بی‌رقیب دارد.'],
            'en' => ['name' => 'HPE ProLiant DL380p Gen8', 'tag' => '2U rack · the cheapest way into enterprise servers',
                'hero_d' => 'The lowest-cost way to own a real enterprise server — dual-socket, iLO 4 and RAID, for labs, dev and light workloads.',
                'desc' => 'The DL380p Gen8 is the cheapest entry into HPE rack servers; unbeatable value for a starter rig, test environment, development or light services.'],
            'tr' => ['name' => 'HPE ProLiant DL380p Gen8', 'tag' => '2U raf · kurumsal sunucuya en ucuz giriş',
                'hero_d' => 'Gerçek bir kurumsal sunucuya sahip olmanın en düşük maliyetli yolu — çift soket, iLO 4 ve RAID; laboratuvar, geliştirme ve hafif iş yükleri için.',
                'desc' => 'DL380p Gen8, HPE raf sunucularına en ucuz giriştir; başlangıç sistemi, test ortamı, geliştirme veya hafif hizmetler için rakipsiz değer.'],
            'price_from' => ['contact' => true],
            'body' => [
                'fa' => "همه از یک جایی شروع می‌کنند. اگر تازه می‌خواهید وارد دنیای سرورهای واقعی شوید، یک آزمایشگاهِ خانگی (Home Lab) بسازید، یا محیطِ تست و توسعه راه بیندازید، DL380p Gen8 کم‌هزینه‌ترین بلیطِ ورود است. با پردازنده‌های Intel Xeon E5-2600 نسلِ اول و دوم (v1/v2) تا ۲۴ هسته و حافظهٔ DDR3 تا ۷۶۸ گیگابایت، برای اجرای چند ماشینِ مجازی، کنترلرِ دامنه، یا سرویس‌های داخلیِ سبک کاملاً کافی است.\n\nنکتهٔ مهم این است که با وجودِ قیمتِ پایین، هنوز یک سرورِ «واقعیِ» سازمانی است: کنترلرِ Smart Array P420i، دیسک‌های Hot-plug، منبعِ تغذیهٔ افزونه و مدیریتِ کاملِ iLO 4. یعنی همان تجربهٔ حرفه‌ای را با کسری از هزینه تمرین می‌کنید. تنها باید بدانید که DDR3 و نسلِ قدیمی‌ترِ پردازنده، مصرفِ برقِ بیشتری نسبت به Gen9 دارد.",
                'en' => "Everyone starts somewhere. If you are stepping into real servers for the first time, building a home lab, or standing up a test and dev environment, the DL380p Gen8 is the cheapest ticket in. With Intel Xeon E5-2600 v1/v2 CPUs up to 24 cores and DDR3 memory up to 768 GB, it is plenty for a handful of VMs, a domain controller or light internal services.\n\nThe key point is that despite the low price it is still a real enterprise server: a Smart Array P420i controller, hot-plug drives, redundant power and full iLO 4 management. You practice the same professional experience at a fraction of the cost. Just know that DDR3 and the older CPU generation draw more power than a Gen9.",
                'tr' => "Herkes bir yerden başlar. Gerçek sunucular dünyasına ilk kez adım atıyor, bir ev laboratuvarı kuruyor veya test ve geliştirme ortamı oluşturuyorsanız, DL380p Gen8 en ucuz giriş biletidir. 24 çekirdeğe kadar Intel Xeon E5-2600 v1/v2 işlemciler ve 768 GB'a kadar DDR3 bellek ile birkaç sanal makine, bir etki alanı denetleyicisi veya hafif iç hizmetler için fazlasıyla yeterlidir.\n\nÖnemli nokta, düşük fiyatına rağmen hâlâ gerçek bir kurumsal sunucu olmasıdır: Smart Array P420i denetleyici, hot-plug diskler, yedekli güç ve tam iLO 4 yönetimi. Aynı profesyonel deneyimi maliyetin çok altında pratik edersiniz. Yalnızca DDR3 ve eski işlemci neslinin Gen9'a göre daha fazla güç çektiğini bilin."],
            'strengths' => [
                'fa' => ['ارزان‌ترین راهِ ورود به سرورِ سازمانیِ واقعی', 'عالی برای Home Lab، تست، توسعه و یادگیری', 'دو سوکته تا ۲۴ هسته و DDR3 تا ۷۶۸ گیگابایت', 'iLO 4، RAID و منبعِ تغذیهٔ افزونه — تجربهٔ کامل', 'فراوان و بسیار ارزان در بازار'],
                'en' => ['The cheapest way into a real enterprise server', 'Great for home labs, testing, dev and learning', 'Dual-socket up to 24 cores and DDR3 up to 768 GB', 'iLO 4, RAID and redundant power — the full experience', 'Abundant and very cheap on the market'],
                'tr' => ['Gerçek kurumsal sunucuya en ucuz giriş', 'Ev laboratuvarı, test, geliştirme ve öğrenme için ideal', '24 çekirdeğe kadar çift soket ve 768 GB\'a kadar DDR3', 'iLO 4, RAID ve yedekli güç — tam deneyim', 'Pazarda bol ve çok ucuz']],
            'weaknesses' => [
                'fa' => ['حافظهٔ DDR3 و پردازندهٔ نسلِ قدیمی‌تر (v1/v2)', 'مصرفِ برقِ بیشتر به ازای هر واحدِ کارایی', 'برای بارِ کاریِ تولیدیِ سنگین توصیه نمی‌شود'],
                'en' => ['DDR3 memory and older-generation CPUs (v1/v2)', 'Higher power draw per unit of performance', 'Not recommended for heavy production workloads'],
                'tr' => ['DDR3 bellek ve eski nesil işlemciler (v1/v2)', 'Performans birimi başına daha yüksek güç tüketimi', 'Ağır üretim iş yükleri için önerilmez']],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon E5-2600 v1/v2 (تا ۲۴ هسته)', 'Up to 2× Intel Xeon E5-2600 v1/v2 (up to 24 cores)', '2× Intel Xeon E5-2600 v1/v2\'ye kadar'),
                $row($L['ram'], 'تا ۷۶۸ گیگابایت DDR3 (۲۴ اسلات)', 'Up to 768 GB DDR3 (24 slots)', '768 GB DDR3\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۲۵× SFF یا ۱۲× LFF', 'Up to 25× SFF or 12× LFF', '25× SFF veya 12× LFF\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array P420i با کش', 'HPE Smart Array P420i with cache', 'HPE Smart Array P420i, önbellekli'),
                $row($L['net'], '۴× 1GbE داخلی', '4× 1GbE onboard', '4× 1GbE'),
                $row($L['mgmt'], 'iLO 4', 'iLO 4', 'iLO 4'),
                $row($L['psu'], 'دوگانهٔ افزونه (Hot-plug)', 'Dual redundant (hot-plug)', 'Çift yedekli (hot-plug)'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'hpe-proliant-dl360p-gen8' => [
            'brand' => 'hp', 'condition' => 'refurb', 'popular' => false,
            'fa' => ['name' => 'HPE ProLiant DL360p Gen8', 'tag' => 'رَک ۱U · اقتصادی و کم‌فضا',
                'hero_d' => 'برادرِ ۱U مدلِ Gen8 — کمترین هزینه و کمترین فضا برای سرویس‌های سبک، فایروال، یا نودِ آزمایشی.',
                'desc' => 'DL360p Gen8 همان ارزشِ اقتصادیِ DL380p را در ارتفاعِ ۱U می‌دهد؛ ایده‌آل برای سرویس‌های شبکه‌ای، فایروال و محیط‌های کم‌فضا با بودجهٔ محدود.'],
            'en' => ['name' => 'HPE ProLiant DL360p Gen8', 'tag' => '1U rack · budget & space-saving',
                'hero_d' => 'The 1U sibling of the Gen8 — lowest cost and footprint for light services, a firewall, or a test node.',
                'desc' => 'The DL360p Gen8 delivers the DL380p\'s budget value in 1U; ideal for network services, firewalls and space-tight environments on a limited budget.'],
            'tr' => ['name' => 'HPE ProLiant DL360p Gen8', 'tag' => '1U raf · ekonomik ve yer tasarruflu',
                'hero_d' => 'Gen8\'in 1U kardeşi — hafif hizmetler, güvenlik duvarı veya test düğümü için en düşük maliyet ve alan.',
                'desc' => 'DL360p Gen8, DL380p\'nin ekonomik değerini 1U\'da sunar; sınırlı bütçeyle ağ hizmetleri, güvenlik duvarları ve dar alanlı ortamlar için idealdir.'],
            'price_from' => ['contact' => true],
            'body' => [
                'fa' => "گاهی به یک سرورِ کوچک، کم‌هزینه و کم‌جا نیاز دارید که فقط یک یا دو کارِ مشخص را قابلِ اعتماد انجام دهد: یک فایروال یا روترِ نرم‌افزاری، یک سرورِ DNS/DHCP، یک نودِ مانیتورینگ یا محیطِ آزمایشی. DL360p Gen8 دقیقاً همین است؛ دو سوکتهٔ Xeon E5 v1/v2 و DDR3 در شاسیِ باریکِ ۱U، با کمترین قیمتِ ممکن.\n\nبا وجودِ اندازهٔ کوچک، هنوز مدیریتِ کاملِ iLO 4 و کنترلرِ RAID دارد؛ پس می‌توانید از راه دور کاملاً کنترلش کنید. برای کسانی که تازه وارد دنیای سرور می‌شوند یا بودجهٔ بسیار محدودی دارند، این ارزان‌ترین راهِ داشتنِ یک ماشینِ ۱U سازمانیِ واقعی است.",
                'en' => "Sometimes you need a small, cheap, space-saving server that just does one or two specific jobs reliably: a software firewall or router, a DNS/DHCP server, a monitoring node or a test environment. The DL360p Gen8 is exactly that; dual Xeon E5 v1/v2 sockets and DDR3 in a slim 1U chassis at the lowest possible price.\n\nDespite the small size, it still has full iLO 4 management and a RAID controller, so you can run it entirely remotely. For anyone stepping into servers for the first time or on a very tight budget, this is the cheapest way to own a real 1U enterprise machine.",
                'tr' => "Bazen yalnızca bir veya iki belirli işi güvenilir şekilde yapan küçük, ucuz, yer tasarruflu bir sunucuya ihtiyacınız olur: bir yazılım güvenlik duvarı veya yönlendirici, bir DNS/DHCP sunucusu, bir izleme düğümü veya test ortamı. DL360p Gen8 tam olarak budur; ince 1U kasada çift Xeon E5 v1/v2 soketi ve DDR3, mümkün olan en düşük fiyata.\n\nKüçük boyutuna rağmen hâlâ tam iLO 4 yönetimi ve RAID denetleyicisi vardır, böylece tamamen uzaktan çalıştırabilirsiniz. Sunuculara ilk kez adım atan veya çok kısıtlı bütçesi olanlar için, gerçek bir 1U kurumsal makineye sahip olmanın en ucuz yoludur."],
            'strengths' => [
                'fa' => ['کمترین قیمت و کمترین فضای رَک', 'مناسبِ فایروال، DNS/DHCP، مانیتورینگ و تست', 'مدیریتِ کاملِ از راه دور با iLO 4', 'دو سوکته با کاراییِ کافی برای سرویس‌های سبک', 'کم‌مصرف‌تر از مدل‌های ۲U در حالتِ سبک'],
                'en' => ['Lowest price and smallest rack footprint', 'Great for firewall, DNS/DHCP, monitoring and testing', 'Full remote management with iLO 4', 'Dual-socket with enough power for light services', 'Lower consumption than 2U models under light load'],
                'tr' => ['En düşük fiyat ve en küçük raf alanı', 'Güvenlik duvarı, DNS/DHCP, izleme ve test için ideal', 'iLO 4 ile tam uzaktan yönetim', 'Hafif hizmetler için yeterli güce sahip çift soket', 'Hafif yükte 2U modellerden daha düşük tüketim']],
            'weaknesses' => [
                'fa' => ['DDR3 و نسلِ قدیمی‌ترِ پردازنده', 'تعدادِ دیسکِ داخلی و اسلاتِ توسعه محدود', 'فن‌های ۱U در بارِ بالا پرسروصدا می‌شوند'],
                'en' => ['DDR3 and older-generation CPUs', 'Limited internal drives and expansion slots', '1U fans get noisy under high load'],
                'tr' => ['DDR3 ve eski nesil işlemciler', 'Sınırlı dahili disk ve genişleme yuvaları', '1U fanlar yüksek yükte gürültülü olur']],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon E5-2600 v1/v2 (تا ۲۴ هسته)', 'Up to 2× Intel Xeon E5-2600 v1/v2 (up to 24 cores)', '2× Intel Xeon E5-2600 v1/v2\'ye kadar'),
                $row($L['ram'], 'تا ۷۶۸ گیگابایت DDR3 (۲۴ اسلات)', 'Up to 768 GB DDR3 (24 slots)', '768 GB DDR3\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۱۰× SFF', 'Up to 10× SFF', '10× SFF\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array P420i', 'HPE Smart Array P420i', 'HPE Smart Array P420i'),
                $row($L['net'], '۴× 1GbE داخلی', '4× 1GbE onboard', '4× 1GbE'),
                $row($L['mgmt'], 'iLO 4', 'iLO 4', 'iLO 4'),
                $row($L['form'], 'رَک ۱U', '1U rack', '1U raf'),
                $row($L['warranty'], '۱۲ ماه گارانتیِ سرورنت', '12-month ServerNet warranty', '12 ay ServerNet garantisi'),
            ],
            'gallery' => [],
        ],

        'hpe-proliant-ml350-gen9' => [
            'brand' => 'hp', 'condition' => 'refurb', 'popular' => false,
            'fa' => ['name' => 'HPE ProLiant ML350 Gen9', 'tag' => 'ایستاده (Tower) · کم‌صدا · مناسبِ دفتر',
                'hero_d' => 'سرورِ ایستادهٔ قدرتمند برای دفتر و کسب‌وکارِ کوچک — بی‌نیاز از رَک، کم‌صدا، با امکانِ نصبِ GPU و ذخیره‌سازیِ زیاد.',
                'desc' => 'ML350 Gen9 قدرتِ دو سوکتهٔ نسلِ Gen9 را در قالبِ ایستاده و کم‌صدا می‌دهد؛ انتخابِ ایده‌آلِ دفتر، شرکتِ کوچک و محیط‌های بدونِ رَکِ استاندارد.'],
            'en' => ['name' => 'HPE ProLiant ML350 Gen9', 'tag' => 'tower · quiet · office-friendly',
                'hero_d' => 'A powerful tower server for the office and small business — no rack needed, quiet, with room for GPUs and lots of storage.',
                'desc' => 'The ML350 Gen9 brings Gen9 dual-socket power in a quiet tower form; the ideal pick for an office, small company or environments without a standard rack.'],
            'tr' => ['name' => 'HPE ProLiant ML350 Gen9', 'tag' => 'kule · sessiz · ofis dostu',
                'hero_d' => 'Ofis ve küçük işletme için güçlü bir kule sunucu — rafa gerek yok, sessiz, GPU ve bol depolama için yer var.',
                'desc' => 'ML350 Gen9, Gen9 çift soket gücünü sessiz bir kule formunda sunar; ofis, küçük şirket veya standart rafı olmayan ortamlar için ideal seçim.'],
            'price_from' => ['contact' => true],
            'body' => [
                'fa' => "همهٔ کسب‌وکارها اتاقِ سرورِ استاندارد با رَک ندارند. اگر می‌خواهید یک سرورِ قدرتمند را کنارِ میزِ کار، در دفتر یا انبار بگذارید، ML350 Gen9 برای همین ساخته شده؛ شاسیِ ایستاده (Tower) که بی‌نیاز از رَک است، فن‌های بزرگ و کم‌صدا دارد و می‌تواند در محیطِ اداریِ معمولی کار کند.\n\nاز نظرِ قدرت چیزی کم ندارد: دو سوکتهٔ Xeon E5 v3/v4، تا ۳ ترابایت DDR4 و فضای فراوان برای دیسک. جای کافی برای نصبِ کارتِ گرافیک (GPU) هم دارد، پس برای رندرینگ، شبیه‌سازی یا هوش مصنوعیِ سبک هم گزینهٔ خوبی است. برای شرکت‌های کوچک که یک سرورِ همه‌کاره و کم‌دردسر می‌خواهند، انتخابی عالی است.",
                'en' => "Not every business has a standard server room with a rack. If you want to place a powerful server next to a desk, in an office or a storeroom, the ML350 Gen9 is built for that; a tower chassis that needs no rack, with large quiet fans that work in a normal office.\n\nOn power it gives nothing away: dual Xeon E5 v3/v4 sockets, up to 3 TB DDR4 and plenty of room for drives. It also has space for a graphics card (GPU), so it is a good option for rendering, simulation or light AI. For small companies that want one versatile, low-hassle server, it is an excellent choice.",
                'tr' => "Her işletmenin rafı olan standart bir sunucu odası yoktur. Güçlü bir sunucuyu bir masanın yanına, ofise veya depoya yerleştirmek istiyorsanız, ML350 Gen9 bunun için üretilmiştir; rafa ihtiyaç duymayan, normal bir ofiste çalışan büyük ve sessiz fanlara sahip bir kule kasa.\n\nGüçten ödün vermez: çift Xeon E5 v3/v4 soketi, 3 TB'a kadar DDR4 ve diskler için bol yer. Ayrıca bir ekran kartı (GPU) için de yer vardır, bu yüzden render, simülasyon veya hafif yapay zeka için iyi bir seçenektir. Çok yönlü ve az uğraştıran tek bir sunucu isteyen küçük şirketler için mükemmel bir seçimdir."],
            'strengths' => [
                'fa' => ['بی‌نیاز از رَک — مناسبِ دفتر و شرکتِ کوچک', 'کم‌صدا با فن‌های بزرگ', 'دو سوکته تا ۴۴ هسته و ۳ ترابایت DDR4', 'جای کافی برای GPU و دیسکِ فراوان', 'همه‌کاره: از فایل‌سرور تا مجازی‌سازی و رندر'],
                'en' => ['No rack needed — office and small-business friendly', 'Quiet, with large fans', 'Dual-socket up to 44 cores and 3 TB DDR4', 'Room for a GPU and plenty of drives', 'Versatile: from file server to virtualization and rendering'],
                'tr' => ['Rafa gerek yok — ofis ve küçük işletme dostu', 'Büyük fanlarla sessiz', '44 çekirdeğe ve 3 TB DDR4\'e kadar çift soket', 'GPU ve bol disk için yer', 'Çok yönlü: dosya sunucusundan sanallaştırma ve rendera']],
            'weaknesses' => [
                'fa' => ['اشغالِ فضای فیزیکیِ بیشتر نسبت به مدلِ رَکی', 'برای دیتاسنترِ متراکم مناسب نیست', 'مصرفِ برقِ نسلِ Gen9 (بیشتر از نسل‌های جدید)'],
                'en' => ['Takes more physical space than a rack model', 'Not suited to a dense datacenter', 'Gen9-era power draw (more than newer generations)'],
                'tr' => ['Raf modeline göre daha fazla fiziksel alan kaplar', 'Yoğun bir veri merkezi için uygun değil', 'Gen9 dönemi güç tüketimi (yeni nesillerden daha fazla)']],
            'specs' => [
                $row($L['cpu'], 'تا ۲× Intel Xeon E5-2600 v3/v4 (تا ۴۴ هسته)', 'Up to 2× Intel Xeon E5-2600 v3/v4 (up to 44 cores)', '2× Intel Xeon E5-2600 v3/v4\'e kadar'),
                $row($L['ram'], 'تا ۳ ترابایت DDR4 (۲۴ اسلات)', 'Up to 3 TB DDR4 (24 slots)', '3 TB DDR4\'e kadar (24 yuva)'),
                $row($L['storage'], 'تا ۲۴× SFF یا ۱۲× LFF + NVMe', 'Up to 24× SFF or 12× LFF + NVMe', '24× SFF veya 12× LFF + NVMe\'ye kadar'),
                $row($L['raid'], 'HPE Smart Array P440 با کش', 'HPE Smart Array P440 with cache', 'HPE Smart Array P440, önbellekli'),
                $row($L['mgmt'], 'iLO 4 (کنسولِ کامل از راه دور)', 'iLO 4 (full remote console)', 'iLO 4 (tam uzaktan konsol)'),
                $row($L['net'], '۴× 1GbE + کارت‌های اختیاریِ 10GbE', '4× 1GbE + optional 10GbE', '4× 1GbE + isteğe bağlı 10GbE'),
                $row($L['form'], 'ایستاده (Tower) · قابلِ تبدیل به ۵U رَک', 'Tower · convertible to 5U rack', 'Kule · 5U rafa dönüştürülebilir'),
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
            'price_from' => ['contact' => true],
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
            'price_from' => ['contact' => true],
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
            'price_from' => ['contact' => true],
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
