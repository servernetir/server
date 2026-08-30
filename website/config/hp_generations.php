<?php

/*
|--------------------------------------------------------------------------
| نسل‌های سرورِ HPE ProLiant — دادهٔ مرجع
|--------------------------------------------------------------------------
|
| 🔴 چرا config و نه دیتابیس:
|
| اینها **واقعیتِ مهندسیِ ثابت**اند، نه موجودیِ فروشگاه. «Gen9 پردازندهٔ
| E5-2600 v3/v4 می‌خورد» تا ابد درست است و نباید در فرمِ ادمین قابلِ تغییر
| باشد — یک ویرایشِ اشتباه یعنی پیشنهادِ قطعه‌ای که روی برد نمی‌نشیند.
|
| موجودی، قیمت و خودِ محصولات در جدولِ `server_parts` هستند و از پنل مدیریت
| می‌شوند.
|
| ═══ منابع ═══
|
| ⚠️ عددهای این فایل از QuickSpecsِ رسمیِ HPE و اعلامِ محصول درآمده‌اند، نه
| از حافظه. هر ادعای این‌جا قابلِ راستی‌آزمایی است — و همین چیزی است که
| خریدارِ فنی را نگه می‌دارد. یک عددِ غلط در جدولِ مشخصات، کلِ فروشگاه را
| بی‌اعتبار می‌کند.
|
|   Gen9  — HPE QuickSpecs c04346247 / c04375627
|   Gen10 — HPE QuickSpecs a00008180enw
|   Gen11 — HPE ProLiant DL380 Gen11 QuickSpecs
|   Gen12 — HPE ProLiant Compute DL380 Gen12 (اعلامِ ۲۰۲۵)
|
| ⚠️ نسلِ ۱۱ با نسلِ ۱۰ **سازگار نیست**: نه پردازنده، نه رم، نه کنترلرِ رید.
| شیارِ DDR5 با DDR4 فرق دارد و فیزیکاً جا نمی‌رود. این را صریح نوشته‌ایم چون
| رایج‌ترین اشتباهِ خریدارِ ارتقادهنده همین است.
*/

return [

    'gen8' => [
        'gen' => 8,
        'years' => '2012–2014',
        'cpu_family' => 'Intel Xeon E5-2600 / E5-2600 v2',
        'cpu_max_cores' => 12,
        'ram_type' => 'DDR3 ECC',
        'ram_speed' => '1333–1866 MT/s',
        'ram_slots' => 24,
        'ram_max_gb' => 768,
        'nvme' => false,
        'ilo' => 'iLO 4',
        'status' => 'legacy',
        'name' => ['fa' => 'نسل ۸ (Gen8)', 'en' => 'Generation 8 (Gen8)', 'tr' => '8. Nesil (Gen8)'],
        'headline' => ['fa' => 'ارزان‌ترین راهِ داشتنِ سرورِ واقعی', 'en' => 'The cheapest way into a real server', 'tr' => 'Gerçek bir sunucuya en ucuz giriş'],
        'summary' => ['fa' => 'نسلِ ۲۰۱۲ با پردازندهٔ Xeon E5-2600 و رمِ DDR3. امروز ارزان‌ترین سرورِ کارکردهٔ قابلِ اتکاست و برای آزمایشگاه، بکاپ و بارِ سبک هنوز کافی است.', 'en' => 'The 2012 generation with Xeon E5-2600 and DDR3. Today it is the cheapest dependable used server and still perfectly adequate for labs, backup and light workloads.', 'tr' => 'Xeon E5-2600 ve DDR3 ile 2012 nesli. Bugün en ucuz güvenilir ikinci el sunucu.'],
        'good_for' => [
            'fa' => ['آزمایشگاه و محیطِ تست', 'سرورِ بکاپ', 'فایل‌سرور داخلی', 'یادگیری و آموزش'],
            'en' => ['Lab and test environments', 'Backup server', 'Internal file server', 'Learning and training'],
            'tr' => ['Laboratuvar ve test ortamı', 'Yedekleme sunucusu', 'Dahili dosya sunucusu', 'Öğrenme ve eğitim'],
        ],
        'watch_out' => ['fa' => 'رمِ DDR3 گران‌تر از DDR4 شده چون دیگر تولید نمی‌شود. اگر رمِ زیاد می‌خواهید، Gen9 در مجموع ارزان‌تر تمام می‌شود.', 'en' => 'DDR3 now costs more than DDR4 because it is out of production. If you need lots of RAM, Gen9 works out cheaper overall.', 'tr' => 'DDR3 üretimden kalktığı için DDR4 ten pahalı. Çok RAM gerekiyorsa Gen9 daha ucuz.'],
    ],

    'gen9' => [
        'gen' => 9,
        'years' => '2014–2017',
        'cpu_family' => 'Intel Xeon E5-2600 v3 / v4',
        'cpu_max_cores' => 22,
        'ram_type' => 'DDR4 ECC (RDIMM / LRDIMM)',
        'ram_speed' => '1866–2400 MT/s',
        'ram_slots' => 24,
        'ram_max_gb' => 3072,
        'nvme' => false,
        'ilo' => 'iLO 4',
        'status' => 'sweet-spot',
        'name' => ['fa' => 'نسل ۹ (Gen9)', 'en' => 'Generation 9 (Gen9)', 'tr' => '9. Nesil (Gen9)'],
        'headline' => ['fa' => 'بهترین نسبتِ کارایی به قیمت در بازارِ کارکرده', 'en' => 'The best performance-per-toman on the used market', 'tr' => 'İkinci el pazarında en iyi fiyat/performans'],
        'summary' => ['fa' => 'انتخابِ اولِ اکثرِ خریدارها. رمِ DDR4 با ۲۴ اسلات و سقفِ ۳ ترابایت، تا ۲۲ هسته در هر سوکت، و فراوانیِ قطعه در بازار. برای مجازی‌سازی و هاستینگ هنوز استانداردِ عملی است.', 'en' => 'Most buyers land here. DDR4 with 24 slots and a 3 TB ceiling, up to 22 cores per socket, and parts are everywhere. Still the practical standard for virtualisation and hosting.', 'tr' => 'Çoğu alıcının tercihi. 24 yuvalı DDR4, 3 TB tavan, soket başına 22 çekirdek.'],
        'good_for' => [
            'fa' => ['مجازی‌سازی و VPS', 'هاستینگ اشتراکی', 'ذخیره‌سازی و بکاپ', 'دیتابیسِ متوسط'],
            'en' => ['Virtualisation and VPS', 'Shared hosting', 'Storage and backup', 'Mid-size databases'],
            'tr' => ['Sanallaştırma ve VPS', 'Paylaşımlı hosting', 'Depolama ve yedekleme', 'Orta ölçekli veritabanı'],
        ],
        'watch_out' => ['fa' => 'NVMe ندارد. اگر بارِ کاری‌تان دیتابیسِ پرنوشتن است، Gen10 تفاوتِ واقعی می‌سازد نه تفاوتِ کاغذی.', 'en' => 'No NVMe. If your workload is a write-heavy database, Gen10 makes a real difference, not a paper one.', 'tr' => 'NVMe yok. Yoğun yazma yapan veritabanı için Gen10 gerçek fark yaratır.'],
    ],

    'gen10' => [
        'gen' => 10,
        'years' => '2017–2021',
        'cpu_family' => 'Intel Xeon Scalable Gen1 / Gen2 (Bronze · Silver · Gold · Platinum)',
        'cpu_max_cores' => 28,
        'ram_type' => 'DDR4 ECC (RDIMM / LRDIMM)',
        'ram_speed' => '2666 MT/s (Gen1) · 2933 MT/s (Gen2)',
        'ram_slots' => 24,
        'ram_max_gb' => 3072,
        'nvme' => true,
        'ilo' => 'iLO 5',
        'status' => 'modern',
        'name' => ['fa' => 'نسل ۱۰ (Gen10)', 'en' => 'Generation 10 (Gen10)', 'tr' => '10. Nesil (Gen10)'],
        'headline' => ['fa' => 'اولین نسلی که NVMe را جدی گرفت', 'en' => 'The first generation that took NVMe seriously', 'tr' => 'NVMe yi ciddiye alan ilk nesil'],
        'summary' => ['fa' => 'گذر از E5 به Xeon Scalable. تا ۲۸ هسته در هر سوکت و پشتیبانی از ۲۰ درایوِ NVMe. اگر تأخیرِ دیسک گلوگاهِ شماست، این نسل جایی است که تفاوت دیده می‌شود.', 'en' => 'The jump from E5 to Xeon Scalable. Up to 28 cores per socket and support for 20 NVMe drives. If disk latency is your bottleneck, this is where it changes.', 'tr' => 'E5 ten Xeon Scalable a geçiş. Soket başına 28 çekirdek ve 20 NVMe sürücü desteği.'],
        'good_for' => [
            'fa' => ['دیتابیسِ پرتراکنش', 'مجازی‌سازیِ متراکم', 'زیرساختِ ابری', 'بارِ کاریِ حساس به تأخیر'],
            'en' => ['High-transaction databases', 'Dense virtualisation', 'Cloud infrastructure', 'Latency-sensitive workloads'],
            'tr' => ['Yüksek işlemli veritabanı', 'Yoğun sanallaştırma', 'Bulut altyapısı', 'Gecikmeye duyarlı iş yükleri'],
        ],
        'watch_out' => ['fa' => 'رمِ DDR4-2933 فقط با پردازندهٔ نسلِ دومِ Scalable کار می‌کند؛ با نسلِ اول به ۲۶۶۶ می‌افتد. خریدِ رمِ گران‌تر بی‌عوضِ پردازنده، پولِ دورریخته است.', 'en' => 'DDR4-2933 only runs at full speed with 2nd-gen Scalable CPUs; with 1st-gen it drops to 2666. Buying faster RAM without the matching CPU is wasted money.', 'tr' => 'DDR4-2933 yalnızca 2. nesil Scalable ile tam hızda çalışır; 1. nesilde 2666 ya düşer.'],
    ],

    'gen11' => [
        'gen' => 11,
        'years' => '2023–2025',
        'cpu_family' => 'Intel Xeon Scalable Gen4 / Gen5',
        'cpu_max_cores' => 64,
        'ram_type' => 'DDR5 ECC RDIMM',
        'ram_speed' => '4400–5600 MT/s',
        'ram_slots' => 32,
        'ram_max_gb' => 8192,
        'nvme' => true,
        'ilo' => 'iLO 6',
        'status' => 'current',
        'name' => ['fa' => 'نسل ۱۱ (Gen11)', 'en' => 'Generation 11 (Gen11)', 'tr' => '11. Nesil (Gen11)'],
        'headline' => ['fa' => 'جهش به DDR5 — و شکستنِ سازگاری با گذشته', 'en' => 'The jump to DDR5 — and a clean break with the past', 'tr' => 'DDR5 e geçiş — ve geçmişle tam kopuş'],
        'summary' => ['fa' => 'تا ۶۴ هسته و رمِ DDR5 با سرعتِ ۵۶۰۰. برای بارِ کاریِ سنگین و متراکم ساخته شده، ولی قیمتش هنوز در ردهٔ نو است نه کارکرده.', 'en' => 'Up to 64 cores and DDR5 at 5600 MT/s. Built for heavy, dense workloads — but priced as new hardware, not used.', 'tr' => '64 çekirdeğe kadar ve 5600 MT/s DDR5. Ağır iş yükleri için.'],
        'good_for' => [
            'fa' => ['بارِ کاریِ هوش مصنوعی', 'مجازی‌سازیِ بسیار متراکم', 'دیتابیسِ درون‌حافظه‌ای', 'زیرساختِ نسلِ بعد'],
            'en' => ['AI workloads', 'Very dense virtualisation', 'In-memory databases', 'Next-generation infrastructure'],
            'tr' => ['Yapay zekâ iş yükleri', 'Çok yoğun sanallaştırma', 'Bellek içi veritabanı', 'Yeni nesil altyapı'],
        ],
        'watch_out' => ['fa' => '🔴 با Gen10 **هیچ سازگاری ندارد** — نه پردازنده، نه رم، نه کنترلرِ رید. شیارِ DDR5 با DDR4 فرق دارد و فیزیکاً جا نمی‌رود. اگر از Gen10 ارتقا می‌دهید، هزینهٔ همهٔ قطعات را از نو حساب کنید.', 'en' => '🔴 **Nothing carries over from Gen10** — not CPUs, not memory, not RAID controllers. The DDR5 key differs from DDR4 and will not physically fit. Budget for a full set of new parts.', 'tr' => '🔴 Gen10 ile **hiçbir uyumluluk yok** — CPU, bellek ve RAID denetleyici dahil.'],
    ],

    'gen12' => [
        'gen' => 12,
        'years' => '2025–',
        'cpu_family' => 'Intel Xeon 6',
        'cpu_max_cores' => 144,
        'ram_type' => 'DDR5 ECC',
        'ram_speed' => '5200–6400 MT/s',
        'ram_slots' => 32,
        'ram_max_gb' => 8192,
        'nvme' => true,
        'ilo' => 'iLO 7',
        'status' => 'newest',
        'name' => ['fa' => 'نسل ۱۲ (Gen12)', 'en' => 'Generation 12 (Gen12)', 'tr' => '12. Nesil (Gen12)'],
        'headline' => ['fa' => 'تازه‌ترین نسل — تا ۱۴۴ هسته در یک سرور', 'en' => 'The newest generation — up to 144 cores in one server', 'tr' => 'En yeni nesil — tek sunucuda 144 çekirdeğe kadar'],
        'summary' => ['fa' => 'معرفی‌شده در ۲۰۲۵ با پردازندهٔ Xeon 6، تا ۱۴۴ هسته، ۸ ترابایت رمِ DDR5 با ۶۴۰۰، و تا ۳۶ درایوِ EDSFF E3.S. مدیریت با iLO 7 و امکانِ خنک‌کاریِ مایع.', 'en' => 'Announced in 2025 with Xeon 6, up to 144 cores, 8 TB of DDR5 at 6400 MT/s, and up to 36 EDSFF E3.S drives. Managed by iLO 7, with optional direct liquid cooling.', 'tr' => '2025 te Xeon 6 ile tanıtıldı: 144 çekirdeğe kadar, 8 TB DDR5 ve 36 EDSFF E3.S sürücü.'],
        'good_for' => [
            'fa' => ['آموزش و استنتاجِ مدل', 'ابرِ خصوصیِ بزرگ', 'HPC', 'تراکمِ حداکثری در رک'],
            'en' => ['Model training and inference', 'Large private cloud', 'HPC', 'Maximum rack density'],
            'tr' => ['Model eğitimi ve çıkarım', 'Büyük özel bulut', 'HPC', 'Maksimum raf yoğunluğu'],
        ],
        'watch_out' => ['fa' => 'تازه است و در بازارِ ایران کمیاب. پیش از سفارش حتماً موجودی و مهلتِ تحویل را استعلام کنید.', 'en' => 'Brand new and scarce in the Iranian market. Confirm stock and lead time before ordering.', 'tr' => 'Çok yeni ve İran pazarında nadir. Sipariş öncesi stok ve teslim süresini teyit edin.'],
    ],

];
