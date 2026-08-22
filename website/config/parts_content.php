<?php

/*
|--------------------------------------------------------------------------
| محتوای اختصاصیِ هر دستهٔ فروشگاهِ قطعات
|--------------------------------------------------------------------------
|
| 🔴 چرا این فایل وجود دارد:
|
| نسخهٔ اولِ صفحاتِ دسته، همگی یک پاراگرافِ معرفیِ یکسان داشتند — ۹ دسته × ۳
| زبان = **۲۷ صفحه با متنِ کاملاً یکسان**. گوگل این را محتوای تکراری می‌بیند و
| در بهترین حالت یکی را نگه می‌دارد و بقیه را کنار می‌گذارد. یعنی هشت دسته از
| نُه دسته عملاً از نتایج حذف می‌شدند، بی‌آنکه چیزی خطا بدهد.
|
| ⚠️ هر دسته باید متنِ **خودش** را داشته باشد؛ نه صرفاً برای سئو، بلکه چون
| چیزی که خریدارِ پردازنده باید بداند با چیزی که خریدارِ منبعِ تغذیه باید بداند
| اصلاً یکی نیست. متنِ عمومی به هیچ‌کدام کمک نمی‌کند.
|
| ═══ ساختار ═══
|
| meta   → توضیحِ متای یکتا (۱۲۰ تا ۱۶۰ کاراکتر)
| intro  → دو-سه جملهٔ بالای صفحه
| guide  → بخش‌های راهنمای خرید: [h => عنوان، p => متن]
| faq    → پرسش‌های واقعیِ خریدار: [q, a] — با schema.org FAQPage منتشر می‌شود
|
| ⚠️ FAQ فقط پرسشی را بیاورد که پاسخش در همین صفحه **واقعاً** هست. پرسشِ
| تزئینی برای schema، هم کاربر را گمراه می‌کند هم ریسکِ جریمهٔ structured data
| دارد.
*/

return [

    // ═══════════════════════════════ CPU ═══════════════════════════════
    'cpu' => [
        'meta' => [
            'fa' => 'خرید پردازندهٔ سرور HPE ProLiant نسل ۸ تا ۱۲ — Xeon E5-2600 v2/v3/v4، Xeon Scalable و Xeon 6 با مشخصات کامل، سازگاری اعلام‌شده و مقایسهٔ آنلاین.',
            'en' => 'Buy HPE ProLiant Gen8–Gen12 server processors: Xeon E5-2600 v2/v3/v4, Xeon Scalable and Xeon 6, with full specifications, stated compatibility and side-by-side comparison.',
            'tr' => 'HPE ProLiant Gen8–Gen12 sunucu işlemcileri: Xeon E5-2600 v2/v3/v4, Xeon Scalable ve Xeon 6 — tam özellikler, uyumluluk bilgisi ve karşılaştırma.',
        ],
        'intro' => [
            'fa' => 'پردازندهٔ سرور با پردازندهٔ دسکتاپ فرق دارد: کانال‌های حافظهٔ بیشتر، پشتیبانی از ECC و امکان کارکردن دو پردازنده روی یک مادربرد. این‌جا همهٔ Xeonهایی که روی نسل‌های ۸ تا ۱۲ ProLiant می‌نشینند فهرست شده‌اند، هرکدام با سوکت، هسته، فرکانس پایه و توربو، کش و توان مصرفی.',
            'en' => 'A server processor is not a desktop one: more memory channels, ECC support, and the ability to run two sockets on one board. Listed here is every Xeon that fits ProLiant Gen8 through Gen12, each with its socket, core count, base and turbo clocks, cache and power draw.',
            'tr' => 'Sunucu işlemcisi masaüstü işlemcisinden farklıdır: daha fazla bellek kanalı, ECC desteği ve tek anakartta iki soket çalıştırabilme. Burada ProLiant Gen8–Gen12 ile uyumlu her Xeon listelenir; soketi, çekirdek sayısı, temel ve turbo hızı, önbelleği ve güç tüketimiyle.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'اول سوکت، بعد هسته', 'en' => 'Socket first, cores second', 'tr' => 'Önce soket, sonra çekirdek'],
                'p' => [
                    'fa' => 'پرتکرارترین اشتباه خرید این است که خریدار پردازنده‌ای با هستهٔ بیشتر انتخاب می‌کند بدون آنکه سوکت را چک کند. Gen8 سوکت LGA2011 دارد، Gen9 سوکت LGA2011-3، Gen10 سوکت LGA3647 و Gen11 سوکت LGA4677. این چهار سوکت هیچ سازگاری‌ای با هم ندارند — نه اینکه کند کار کنند؛ اصلاً روی هم نمی‌نشینند. فهرست سازگاری روی هر محصول همین را می‌گوید.',
                    'en' => 'The most common buying mistake is picking a processor with more cores without checking the socket. Gen8 uses LGA2011, Gen9 LGA2011-3, Gen10 LGA3647 and Gen11 LGA4677. These four are not cross-compatible in any degree — it is not that they run slowly; they do not physically fit. The compatibility list on each product states exactly this.',
                    'tr' => 'En sık yapılan satın alma hatası, soketi kontrol etmeden daha çok çekirdekli bir işlemci seçmektir. Gen8 LGA2011, Gen9 LGA2011-3, Gen10 LGA3647 ve Gen11 LGA4677 kullanır. Bu dördü hiçbir şekilde uyumlu değildir — yavaş çalışmazlar; fiziksel olarak oturmazlar. Her üründeki uyumluluk listesi tam olarak bunu söyler.',
                ],
            ],
            [
                'h' => ['fa' => 'هستهٔ زیاد یا فرکانس بالا؟', 'en' => 'Many cores or high clock?', 'tr' => 'Çok çekirdek mi, yüksek hız mı?'],
                'p' => [
                    'fa' => 'این تصمیم را بار کاری می‌گیرد، نه قیمت. مجازی‌سازی، کانتینر و میزبانی اشتراکی از هستهٔ بیشتر سود می‌برند چون کارها موازی‌اند. در مقابل، پایگاه دادهٔ تراکنشی، سرور بازی و نرم‌افزارهایی که لایسنسشان per-core حساب می‌شود، فرکانس بالا می‌خواهند؛ آن‌جا E5-2667 v4 با ۸ هستهٔ ۳٫۲ گیگاهرتزی از E5-2699 v4 با ۲۲ هستهٔ ۲٫۲ گیگاهرتزی بهتر جواب می‌دهد و ارزان‌تر هم هست.',
                    'en' => 'Workload decides this, not price. Virtualisation, containers and shared hosting benefit from more cores because the work is parallel. Transactional databases, game servers and anything licensed per core want clock speed instead: there an 8-core E5-2667 v4 at 3.2 GHz beats a 22-core E5-2699 v4 at 2.2 GHz, and costs less too.',
                    'tr' => 'Bu kararı fiyat değil iş yükü verir. Sanallaştırma, konteynerler ve paylaşımlı barındırma paralel çalıştığı için çok çekirdekten fayda görür. İşlem yoğun veritabanları, oyun sunucuları ve çekirdek başına lisanslanan yazılımlar ise hız ister: orada 3,2 GHz’lik 8 çekirdekli E5-2667 v4, 2,2 GHz’lik 22 çekirdekli E5-2699 v4’ü geçer ve daha ucuzdur.',
                ],
            ],
            [
                'h' => ['fa' => 'توان مصرفی را نادیده نگیرید', 'en' => 'Do not ignore TDP', 'tr' => 'TDP’yi göz ardı etmeyin'],
                'p' => [
                    'fa' => 'هر پردازندهٔ بالای ۱۳۵ وات به هیت‌سینک پرکارایی و کیت فن نیاز دارد. اگر با هیت‌سینک استاندارد نصبش کنید، سرور بالا می‌آید و کار می‌کند و هیچ خطایی نمی‌دهد — فقط زیر بار سنگین فرکانسش را پایین می‌آورد. یعنی برای کارایی‌ای پول داده‌اید که هرگز نمی‌گیرید. در پیکربندی دو پردازنده‌ای هم توان مصرفی دو برابر می‌شود و ممکن است منبع تغذیهٔ بزرگ‌تری لازم شود.',
                    'en' => 'Any processor above 135 W needs the high-performance heatsink and fan kit. Fit it with the standard heatsink and the server boots, runs and reports no error — it simply drops its clock under sustained load. You paid for performance you never receive. In a dual-socket build the draw doubles, and a larger power supply may become necessary.',
                    'tr' => '135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister. Standart soğutucuyla takarsanız sunucu açılır, çalışır ve hata vermez — yalnızca sürekli yük altında hızını düşürür. Hiç almadığınız bir performans için ödeme yaptınız demektir. Çift soketli yapılandırmada tüketim ikiye katlanır ve daha büyük bir güç kaynağı gerekebilir.',
                ],
            ],
            [
                'h' => ['fa' => 'دو پردازنده یعنی دو مدل یکسان', 'en' => 'Two sockets means two identical parts', 'tr' => 'İki soket, iki özdeş parça demektir'],
                'p' => [
                    'fa' => 'در پیکربندی دو سوکتی، هر دو پردازنده باید دقیقاً یک مدل باشند. سروری که یک E5-2650 v4 و یک E5-2680 v4 داشته باشد اصلاً بوت نمی‌شود. همچنین با پردازندهٔ دوم، نیمی از اسلات‌های حافظه فعال می‌شوند؛ اگر فقط یک پردازنده نصب باشد، ماژول‌های نشسته در بانک دوم اصلاً دیده نمی‌شوند.',
                    'en' => 'In a dual-socket build both processors must be exactly the same model. A server holding one E5-2650 v4 and one E5-2680 v4 will not post at all. The second processor also activates half the memory slots: with only one fitted, DIMMs seated in the second bank are simply not seen.',
                    'tr' => 'Çift soketli yapılandırmada her iki işlemci de tam olarak aynı model olmalıdır. Bir E5-2650 v4 ile bir E5-2680 v4 taşıyan sunucu hiç açılmaz. İkinci işlemci ayrıca bellek yuvalarının yarısını etkinleştirir: yalnızca biri takılıysa ikinci banktaki modüller hiç görülmez.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'آیا پردازندهٔ Gen9 روی سرور Gen10 کار می‌کند؟', 'en' => 'Will a Gen9 processor work in a Gen10 server?', 'tr' => 'Gen9 işlemci Gen10 sunucuda çalışır mı?'],
                'a' => [
                    'fa' => 'نه. Gen9 از خانوادهٔ Xeon E5-2600 v3/v4 با سوکت LGA2011-3 استفاده می‌کند و Gen10 از Xeon Scalable با سوکت LGA3647. این دو نه از نظر فیزیکی روی هم می‌نشینند نه از نظر برقی سازگارند. برای ارتقا از Gen9 به Gen10 باید مادربرد، پردازنده و حافظه هر سه عوض شوند — که در عمل یعنی خرید سرور دیگر.',
                    'en' => 'No. Gen9 uses the Xeon E5-2600 v3/v4 family on socket LGA2011-3; Gen10 uses Xeon Scalable on LGA3647. They are neither physically nor electrically compatible. Moving from Gen9 to Gen10 means replacing the board, the processor and the memory — in practice, buying a different server.',
                    'tr' => 'Hayır. Gen9, LGA2011-3 soketinde Xeon E5-2600 v3/v4 ailesini; Gen10 ise LGA3647’de Xeon Scalable kullanır. Ne fiziksel ne de elektriksel olarak uyumludurlar. Gen9’dan Gen10’a geçmek anakart, işlemci ve belleğin üçünü birden değiştirmek demektir — pratikte başka bir sunucu almak.',
                ],
            ],
            [
                'q' => ['fa' => 'پردازندهٔ کارکرده چقدر عمر می‌کند؟', 'en' => 'How long does a used server processor last?', 'tr' => 'Kullanılmış sunucu işlemcisi ne kadar dayanır?'],
                'a' => [
                    'fa' => 'پردازنده جزو کم‌خرابی‌ترین قطعات سرور است؛ برخلاف دیسک و منبع تغذیه، قطعهٔ متحرک یا خازن فرسوده‌شونده ندارد. آمار خرابی میدانی پردازنده‌های سرور در سال حدود دو دهم درصد است. آن‌چه واقعاً پیر می‌شود، معماری است نه سیلیکون: پردازندهٔ ده‌ساله سالم کار می‌کند ولی برق بیشتری برای همان کار مصرف می‌کند.',
                    'en' => 'Processors are among the least failure-prone parts in a server; unlike drives and power supplies they have no moving parts and no ageing capacitors. Field failure rates for server CPUs sit at roughly 0.2% per year. What ages is the architecture, not the silicon: a ten-year-old part still works, it just burns more power for the same work.',
                    'tr' => 'İşlemciler bir sunucudaki en az arızalanan parçalar arasındadır; disk ve güç kaynağının aksine hareketli parçaları ve yaşlanan kondansatörleri yoktur. Sunucu işlemcileri için saha arıza oranı yılda yaklaşık %0,2’dir. Yaşlanan silikon değil mimaridir: on yıllık bir parça hâlâ çalışır, yalnızca aynı iş için daha çok elektrik harcar.',
                ],
            ],
            [
                'q' => ['fa' => 'برای مجازی‌سازی چند هسته لازم دارم؟', 'en' => 'How many cores do I need for virtualisation?', 'tr' => 'Sanallaştırma için kaç çekirdek gerekir?'],
                'a' => [
                    'fa' => 'قاعدهٔ عملی: برای ماشین‌های مجازی معمولی می‌توانید تا نسبت ۴ به ۱ (چهار vCPU روی هر هستهٔ فیزیکی) پیش بروید بی‌آنکه کاربر افت را حس کند. برای بار سنگین‌تر مثل پایگاه داده، نسبت را ۲ به ۱ نگه دارید. یعنی یک سرور دو پردازنده‌ای با مجموع ۲۸ هستهٔ فیزیکی، حدود ۱۱۰ vCPU سبک یا ۵۶ vCPU سنگین را جواب می‌دهد.',
                    'en' => 'A practical rule: for ordinary virtual machines you can go to about 4:1 (four vCPUs per physical core) before users notice. For heavier work such as databases, keep it at 2:1. So a dual-socket server with 28 physical cores in total serves roughly 110 light vCPUs, or 56 demanding ones.',
                    'tr' => 'Pratik kural: sıradan sanal makineler için kullanıcılar fark etmeden yaklaşık 4:1’e (fiziksel çekirdek başına dört vCPU) kadar çıkabilirsiniz. Veritabanı gibi ağır işler için 2:1’de kalın. Yani toplam 28 fiziksel çekirdekli çift soketli bir sunucu, kabaca 110 hafif veya 56 ağır vCPU’ya hizmet eder.',
                ],
            ],
        ],
    ],

    // ═══════════════════════════════ RAM ═══════════════════════════════
    'ram' => [
        'meta' => [
            'fa' => 'خرید رم سرور ECC — ماژول DDR3، DDR4 و DDR5 نوع RDIMM و LRDIMM برای HPE ProLiant نسل ۸ تا ۱۲، با کد رسمی قطعه و راهنمای چیدمان کانال.',
            'en' => 'Buy ECC server memory: DDR3, DDR4 and DDR5 RDIMM and LRDIMM modules for HPE ProLiant Gen8–Gen12, with official part codes and a channel-population guide.',
            'tr' => 'ECC sunucu belleği: HPE ProLiant Gen8–Gen12 için DDR3, DDR4 ve DDR5 RDIMM ve LRDIMM modülleri — resmi parça kodları ve kanal doldurma kılavuzuyla.',
        ],
        'intro' => [
            'fa' => 'حافظهٔ سرور همیشه ECC است: خطای تک‌بیتی را پیش از رسیدن به برنامه اصلاح می‌کند. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بدون ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.',
            'en' => 'Server memory is always ECC: it corrects a single-bit error before it ever reaches your application. On a workstation that is a nice extra; on a server running for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.',
            'tr' => 'Sunucu belleği daima ECC’dir: tek bitlik bir hatayı uygulamanıza ulaşmadan düzeltir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'RDIMM یا LRDIMM؟', 'en' => 'RDIMM or LRDIMM?', 'tr' => 'RDIMM mi LRDIMM mi?'],
                'p' => [
                    'fa' => 'RDIMM انتخاب پیش‌فرض است: تأخیر کمتر و قیمت پایین‌تر. LRDIMM وقتی معنا پیدا می‌کند که بخواهید همهٔ ۲۴ اسلات را با ماژول‌های پرظرفیت پر کنید؛ بافر اضافه‌اش اجازه می‌دهد ظرفیت کل بالاتر برود، به قیمت کمی تأخیر بیشتر. برای اکثر سرورها که نصف اسلات‌ها را پر می‌کنند، RDIMM هم ارزان‌تر است هم سریع‌تر.',
                    'en' => 'RDIMM is the default: lower latency, lower price. LRDIMM starts to matter when you want to fill all 24 slots with high-capacity modules; its extra buffer lets total capacity go higher at the cost of slightly more latency. For most servers, which populate half the slots, RDIMM is both cheaper and faster.',
                    'tr' => 'RDIMM varsayılan tercihtir: daha düşük gecikme, daha düşük fiyat. LRDIMM, 24 yuvanın tamamını yüksek kapasiteli modüllerle doldurmak istediğinizde anlam kazanır; ek tamponu toplam kapasitenin yükselmesine izin verir, karşılığında biraz daha gecikme. Yuvaların yarısını dolduran çoğu sunucu için RDIMM hem daha ucuz hem daha hızlıdır.',
                ],
            ],
            [
                'h' => ['fa' => 'کانال‌به‌کانال پر کنید، نه پشت سر هم', 'en' => 'Populate by channel, not by slot order', 'tr' => 'Yuva sırasıyla değil, kanal kanal doldurun'],
                'p' => [
                    'fa' => 'این نکته‌ای است که بیش از همه از قلم می‌افتد و بیشترین کارایی را هدر می‌دهد. پردازندهٔ Xeon چهار تا شش کانال حافظه دارد و هر کانال پهنای باند مستقل خودش را می‌آورد. شش ماژول پخش‌شده روی شش کانال، تا نزدیک دو برابرِ همان شش ماژول روی یک کانال پهنای باند می‌دهد. ترتیب درست در راهنمای چیدمان همان مدل سرور آمده و روی خود مادربرد هم چاپ شده است.',
                    'en' => 'This is the detail most often missed, and the one that wastes the most performance. A Xeon has four to six memory channels, each contributing its own independent bandwidth. Six modules spread across six channels deliver close to twice the bandwidth of the same six modules on one channel. The correct order is in that server model’s memory-population guide and is also printed on the board itself.',
                    'tr' => 'Bu, en sık atlanan ve en çok performans israf eden ayrıntıdır. Bir Xeon’un dört ila altı bellek kanalı vardır ve her kanal kendi bağımsız bant genişliğini katar. Altı kanala dağılmış altı modül, aynı altı modülün tek kanaldaki hâlinin neredeyse iki katı bant genişliği verir. Doğru sıra, o sunucu modelinin bellek doldurma kılavuzunda ve anakartın üzerinde yazılıdır.',
                ],
            ],
            [
                'h' => ['fa' => 'سرعت با کندترین ماژول تنظیم می‌شود', 'en' => 'The slowest module sets the speed', 'tr' => 'Hızı en yavaş modül belirler'],
                'p' => [
                    'fa' => 'اگر ماژول ۲۴۰۰ و ۲۶۶۶ را با هم نصب کنید، همه روی ۲۴۰۰ کار می‌کنند. هیچ خطایی هم نمی‌بینید؛ فقط سرعتی که برایش پول داده‌اید از دست می‌رود. ضمناً سرعت واقعی به پردازنده هم بستگی دارد: Gen9 حداکثر تا ۲۴۰۰ می‌رود، حتی اگر ماژول ۲۶۶۶ باشد.',
                    'en' => 'Mix a 2400 and a 2666 module and everything runs at 2400. No error is shown; you simply lose the speed you paid for. Actual speed also depends on the processor: Gen9 tops out at 2400 even when the module is rated 2666.',
                    'tr' => 'Bir 2400 ile bir 2666 modülü karıştırırsanız hepsi 2400’de çalışır. Hiçbir hata görünmez; yalnızca ödediğiniz hızı kaybedersiniz. Gerçek hız işlemciye de bağlıdır: modül 2666 olsa bile Gen9 en fazla 2400’e çıkar.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'آیا رم معمولی کامپیوتر روی سرور کار می‌کند؟', 'en' => 'Can I use ordinary desktop memory in a server?', 'tr' => 'Sunucuda sıradan masaüstü belleği kullanabilir miyim?'],
                'a' => [
                    'fa' => 'نه. سرورهای ProLiant فقط ماژول ثبات‌دار (Registered/RDIMM یا LRDIMM) با ECC را می‌پذیرند. رم UDIMM دسکتاپ حتی اگر از نظر فیزیکی در اسلات جا برود، سرور با آن بوت نمی‌شود و iLO خطای حافظهٔ ناسازگار می‌دهد.',
                    'en' => 'No. ProLiant servers accept only registered modules (RDIMM or LRDIMM) with ECC. Desktop UDIMM will not boot the server even if it physically fits the slot; iLO reports unsupported memory.',
                    'tr' => 'Hayır. ProLiant sunucular yalnızca ECC’li kayıtlı modülleri (RDIMM veya LRDIMM) kabul eder. Masaüstü UDIMM, yuvaya fiziksel olarak girse bile sunucuyu açmaz; iLO desteklenmeyen bellek hatası verir.',
                ],
            ],
            [
                'q' => ['fa' => 'چقدر رم برای سرور مجازی‌سازی لازم است؟', 'en' => 'How much memory does a virtualisation host need?', 'tr' => 'Sanallaştırma sunucusuna ne kadar bellek gerekir?'],
                'a' => [
                    'fa' => 'در عمل حافظه زودتر از پردازنده تمام می‌شود، نه برعکس. برای هر ماشین مجازی معمولی ۴ تا ۸ گیگابایت در نظر بگیرید و حدود ۱۶ گیگابایت هم برای خود هایپروایزر کنار بگذارید. یک سرور با ۲۵۶ گیگابایت راحت ۳۰ تا ۴۰ ماشین مجازی متوسط را نگه می‌دارد — و همان سرور با ۶۴ گیگابایت، هرچقدر هم هسته داشته باشد، نمی‌تواند.',
                    'en' => 'In practice memory runs out before CPU does, not the other way round. Budget 4 to 8 GB per ordinary virtual machine and reserve about 16 GB for the hypervisor itself. A server with 256 GB comfortably holds 30 to 40 mid-sized VMs — and the same server with 64 GB cannot, however many cores it has.',
                    'tr' => 'Pratikte bellek işlemciden önce tükenir, tersi değil. Sıradan bir sanal makine başına 4–8 GB ayırın ve hipervizörün kendisi için yaklaşık 16 GB bırakın. 256 GB’lık bir sunucu rahatlıkla 30–40 orta boy sanal makine taşır — aynı sunucu 64 GB ile, kaç çekirdeği olursa olsun, taşıyamaz.',
                ],
            ],
            [
                'q' => ['fa' => 'ماژول‌های برندهای مختلف را می‌شود با هم استفاده کرد؟', 'en' => 'Can I mix modules from different brands?', 'tr' => 'Farklı markaların modüllerini karıştırabilir miyim?'],
                'a' => [
                    'fa' => 'از نظر فنی بله، به شرط آنکه نوع (RDIMM/LRDIMM)، رنک و سرعت یکسان باشند؛ خودِ برند تراشه مهم نیست. ولی توصیه می‌شود دست‌کم ماژول‌های داخل یک کانال هم‌جنس باشند، چون عیب‌یابی حافظهٔ مخلوط وقتی خطای ECC ظاهر شود به‌مراتب سخت‌تر است.',
                    'en' => 'Technically yes, provided type (RDIMM/LRDIMM), rank and speed match; the chip brand itself does not matter. It is still worth keeping modules within a single channel identical, because diagnosing mixed memory once ECC errors appear is considerably harder.',
                    'tr' => 'Teknik olarak evet — tip (RDIMM/LRDIMM), rank ve hız aynı olduğu sürece; yonga markası önemli değildir. Yine de tek bir kanaldaki modülleri özdeş tutmakta fayda var, çünkü ECC hataları çıktığında karışık belleği teşhis etmek belirgin şekilde zordur.',
                ],
            ],
        ],
    ],

    // ═══════════════════════════════ DISK ══════════════════════════════
    'disk' => [
        'meta' => [
            'fa' => 'خرید هارد و SSD سرور — دیسک SAS و SATA، اس‌اس‌دی SAS و NVMe برای HPE ProLiant، با IOPS تخمینی، کدی متناسب هر نسل و راهنمای انتخاب RAID.',
            'en' => 'Buy server drives and SSDs: SAS and SATA disks, SAS and NVMe solid-state drives for HPE ProLiant, with estimated IOPS, the correct caddy per generation and RAID guidance.',
            'tr' => 'Sunucu diskleri ve SSD’leri: HPE ProLiant için SAS ve SATA diskler, SAS ve NVMe SSD’ler — tahmini IOPS, her nesle uygun kızak ve RAID rehberliğiyle.',
        ],
        'intro' => [
            'fa' => 'دیسک تنها قطعه‌ای در سرور است که خرابی‌اش قابل پیش‌بینی و برنامه‌ریزی است — به شرط آنکه از اول درست انتخاب شده باشد. این‌جا دیسک مکانیکی SAS و SATA، اس‌اس‌دی SATA و SAS و درایو NVMe را با IOPS تخمینی و نسل سازگار می‌بینید.',
            'en' => 'The drive is the one component in a server whose failure is predictable and can be planned for — provided it was chosen correctly to begin with. Listed here are SAS and SATA mechanical disks, SATA and SAS solid-state drives and NVMe drives, each with estimated IOPS and its compatible generation.',
            'tr' => 'Disk, bir sunucuda arızası öngörülebilen ve planlanabilen tek bileşendir — baştan doğru seçilmiş olması şartıyla. Burada SAS ve SATA mekanik diskler, SATA ve SAS SSD’ler ve NVMe sürücüler; tahmini IOPS ve uyumlu nesilleriyle listelenir.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'IOPS مهم‌تر از ظرفیت است', 'en' => 'IOPS matters more than capacity', 'tr' => 'IOPS kapasiteden önemlidir'],
                'p' => [
                    'fa' => 'دیسک مکانیکی ۱۰ هزار دور حدود ۱۸۰ عملیات در ثانیه می‌دهد؛ یک SSD معمولی سرور بالای ۷۵ هزار. این اختلاف دو تا سه رقم است، نه چند درصد. برای دیسک سیستم‌عامل، پایگاه داده و ماشین مجازی، همین عدد تعیین‌کننده است نه گیگابایت. دیسک مکانیکی جای خودش را دارد: بایگانی، پشتیبان و فایل‌های بزرگ، جایی که ارزان‌ترین گیگابایت برنده است.',
                    'en' => 'A 10,000 rpm mechanical drive delivers roughly 180 operations per second; an ordinary server SSD exceeds 75,000. That gap is two to three orders of magnitude, not a few percent. For the OS volume, databases and virtual machines this number decides everything, not the gigabytes. Mechanical drives still have their place: archives, backups and large files, where the cheapest gigabyte wins.',
                    'tr' => '10.000 devirli mekanik bir disk saniyede yaklaşık 180 işlem verir; sıradan bir sunucu SSD’si 75.000’i aşar. Bu fark yüzde birkaç değil, iki-üç kat büyüklük mertebesindedir. İşletim sistemi birimi, veritabanları ve sanal makineler için her şeyi bu sayı belirler, gigabaytlar değil. Mekanik diskin de yeri vardır: arşiv, yedek ve büyük dosyalar — en ucuz gigabaytın kazandığı yer.',
                ],
            ],
            [
                'h' => ['fa' => 'کدی بخشی از دیسک است', 'en' => 'The caddy is part of the drive', 'tr' => 'Kızak diskin bir parçasıdır'],
                'p' => [
                    'fa' => 'دیسک بدون کدی در جایگاه هات‌پلاگ نمی‌نشیند. کدی Smart Carrier نسل‌های ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جای هم نمی‌نشیند و ظاهرشان شبیه است. این پرتکرارترین اشتباه سفارش قطعهٔ سرور است: بسته می‌رسد و هیچ‌کدام از دیسک‌ها جا نمی‌روند. پیش از سفارش نسل سرور را چک کنید، نه مدل دیسک را.',
                    'en' => 'A bare drive will not seat in a hot-plug bay. The Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and they look similar. This is the single most common server-parts ordering mistake: the box arrives and none of the drives go in. Check the server generation before ordering, not the drive model.',
                    'tr' => 'Kızaksız disk hot-plug yuvasına oturmaz. Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve görünüşleri benzerdir. Bu, sunucu parçası siparişinde en sık yapılan hatadır: kutu gelir ve disklerin hiçbiri girmez. Sipariş öncesi disk modelini değil, sunucu neslini kontrol edin.',
                ],
            ],
            [
                'h' => ['fa' => 'در یک آرایه، همه هم‌اندازه', 'en' => 'In an array, everything matches', 'tr' => 'Bir dizide her şey eşleşmeli'],
                'p' => [
                    'fa' => 'اعضای یک آرایهٔ RAID باید ظرفیت و سرعت یکسان داشته باشند. اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند و باقی ظرفیت هدر می‌رود. برای بازسازی سریع‌تر پس از خرابی هم بهتر است یک دیسک یدک (hot spare) در آرایه باشد؛ بازسازی آرایهٔ بزرگ می‌تواند ساعت‌ها طول بکشد و در آن مدت افزونگی ندارید.',
                    'en' => 'Members of a RAID array should share capacity and speed. If one is smaller, the controller treats every disk as that size and the rest of the capacity is wasted. It is also worth keeping a hot spare in the array: rebuilding a large array can take hours, and for that whole period you have no redundancy.',
                    'tr' => 'Bir RAID dizisinin üyeleri aynı kapasite ve hızda olmalıdır. Biri küçükse denetleyici tüm diskleri o boyutta görür ve kalan kapasite boşa gider. Dizide bir yedek disk (hot spare) bulundurmakta da fayda var: büyük bir diziyi yeniden oluşturmak saatler sürebilir ve bu süre boyunca yedekliliğiniz olmaz.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'دیسک SATA معمولی روی سرور کار می‌کند؟', 'en' => 'Will an ordinary SATA desktop drive work in a server?', 'tr' => 'Sıradan bir SATA masaüstü diski sunucuda çalışır mı?'],
                'a' => [
                    'fa' => 'از نظر برقی بله، ولی توصیه نمی‌شود. دیسک دسکتاپ برای کار ۸ ساعته طراحی شده نه ۲۴ ساعته، و مدیریت خطایش با کنترلر RAID سازگار نیست: وقتی به سکتور خراب می‌رسد ده‌ها ثانیه تلاش می‌کند، و کنترلر در این مدت آن را «مرده» اعلام و از آرایه خارج می‌کند. دیسک سرور همین رفتار را با محدودیت زمانی (TLER) کنترل می‌کند.',
                    'en' => 'Electrically yes, but it is not advisable. A desktop drive is designed for eight-hour duty, not continuous operation, and its error handling conflicts with a RAID controller: on a bad sector it retries for tens of seconds, and the controller declares it dead and drops it from the array meanwhile. Server drives bound that behaviour with a time limit (TLER).',
                    'tr' => 'Elektriksel olarak evet, ama tavsiye edilmez. Masaüstü diski sürekli çalışma için değil sekiz saatlik kullanım için tasarlanmıştır ve hata yönetimi RAID denetleyicisiyle çakışır: bozuk bir sektörde onlarca saniye yeniden dener, bu sırada denetleyici onu ölü ilan edip diziden çıkarır. Sunucu diskleri bu davranışı bir zaman sınırıyla (TLER) sınırlar.',
                ],
            ],
            [
                'q' => ['fa' => 'ساعت کارکرد دیسک استوک چقدر اهمیت دارد؟', 'en' => 'How much do power-on hours matter on a used drive?', 'tr' => 'Kullanılmış bir diskte çalışma saati ne kadar önemli?'],
                'a' => [
                    'fa' => 'مهم است ولی تنها عدد تعیین‌کننده نیست. دیسک سرور برای صدها هزار ساعت طراحی شده، پس ۲۰ هزار ساعت هنوز جوان است. مهم‌تر از ساعت، شمارندهٔ سکتورهای جابه‌جاشده و خطاهای خواندن در SMART است. ما ساعت کارکرد هر دیسک را اعلام می‌کنیم و پیش از ارسال، تست سطح کامل می‌گیریم.',
                    'en' => 'It matters, but it is not the deciding number. A server drive is rated for hundreds of thousands of hours, so 20,000 hours is still young. More telling than hours are the reallocated-sector count and read-error rate in SMART. We disclose power-on hours for every drive and run a full surface test before shipping.',
                    'tr' => 'Önemlidir ama belirleyici tek sayı değildir. Sunucu diski yüz binlerce saat için tasarlanır, dolayısıyla 20.000 saat hâlâ gençtir. Saatten daha anlamlı olan, SMART’taki yeniden atanmış sektör sayısı ve okuma hatası oranıdır. Her disk için çalışma saatini bildirir ve sevkiyat öncesi tam yüzey testi yaparız.',
                ],
            ],
            [
                'q' => ['fa' => 'NVMe روی سرور Gen9 نصب می‌شود؟', 'en' => 'Can I fit NVMe in a Gen9 server?', 'tr' => 'Gen9 sunucuya NVMe takabilir miyim?'],
                'a' => [
                    'fa' => 'Gen9 بک‌پلین NVMe ندارد. تنها راه، کارت افزودنی PCIe است که یک یا دو درایو NVMe را از طریق اسلات توسعه می‌گیرد — کار می‌کند ولی هات‌پلاگ نیست و از اسلات PCIe شما استفاده می‌کند. اگر NVMe واقعاً لازم دارید، Gen10 اولین نسلی است که بک‌پلین آن را دارد.',
                    'en' => 'Gen9 has no NVMe backplane. The only route is a PCIe add-in card carrying one or two NVMe drives through an expansion slot — it works, but it is not hot-plug and it consumes a PCIe slot. If NVMe genuinely matters to you, Gen10 is the first generation with a backplane for it.',
                    'tr' => 'Gen9’un NVMe backplane’i yoktur. Tek yol, bir genişletme yuvası üzerinden bir veya iki NVMe sürücü taşıyan PCIe ek kartıdır — çalışır, ancak hot-plug değildir ve bir PCIe yuvası tüketir. NVMe gerçekten gerekiyorsa, backplane’e sahip ilk nesil Gen10’dur.',
                ],
            ],
        ],
    ],

    // ═══════════════════════════════ RAID ══════════════════════════════
    'raid' => [
        'meta' => [
            'fa' => 'خرید کنترلر RAID و HBA سرور — Smart Array P420i، P440ar، P408i و MR416i برای HPE ProLiant، با تفاوت RAID سخت‌افزاری و HBA و راهنمای انتخاب سطح آرایه.',
            'en' => 'Buy server RAID controllers and HBAs: Smart Array P420i, P440ar, P408i and MR416i for HPE ProLiant, with the hardware-RAID versus HBA distinction and array-level guidance.',
            'tr' => 'Sunucu RAID denetleyicileri ve HBA’lar: HPE ProLiant için Smart Array P420i, P440ar, P408i ve MR416i — donanımsal RAID ile HBA farkı ve dizi seviyesi rehberliğiyle.',
        ],
        'intro' => [
            'fa' => 'کنترلر همان قطعه‌ای است که تصمیم می‌گیرد دیسک‌های شما یک آرایهٔ افزونه باشند یا چند دیسک مستقل. انتخاب اشتباهش را معمولاً وقتی می‌فهمید که دیسکی خراب شده و راه برگشتی نیست.',
            'en' => 'The controller is the part that decides whether your disks form a redundant array or stay independent volumes. You usually discover the wrong choice at the moment a disk fails and there is no way back.',
            'tr' => 'Denetleyici, disklerinizin yedekli bir dizi mi yoksa bağımsız birimler mi olacağına karar veren parçadır. Yanlış seçimi genellikle bir disk arızalandığında ve geri dönüş kalmadığında fark edersiniz.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'RAID سخت‌افزاری یا HBA؟', 'en' => 'Hardware RAID or HBA?', 'tr' => 'Donanımsal RAID mi HBA mı?'],
                'p' => [
                    'fa' => 'اگر سیستم‌عامل معمولی نصب می‌کنید (ویندوز سرور، لینوکس با ext4 یا XFS)، کنترلر RAID سخت‌افزاری با حافظهٔ کش و باتری پشتیبان انتخاب درست است: نوشتن‌های در راه هنگام قطع برق از دست نمی‌روند. اگر ZFS یا Ceph یا Storage Spaces استفاده می‌کنید، دقیقاً برعکس است: آن‌ها می‌خواهند دیسک را خام ببینند و لایهٔ RAID سخت‌افزاری فقط جلوی کارشان را می‌گیرد. آن‌جا HBA لازم دارید.',
                    'en' => 'If you run a conventional operating system (Windows Server, Linux on ext4 or XFS), a hardware RAID controller with cache and battery backup is the right answer: in-flight writes survive a power cut. If you run ZFS, Ceph or Storage Spaces, the opposite holds — they want to see raw disks, and a hardware RAID layer only obstructs them. There you need an HBA.',
                    'tr' => 'Geleneksel bir işletim sistemi çalıştırıyorsanız (Windows Server, ext4 veya XFS üzerinde Linux), önbellekli ve pil destekli donanımsal RAID denetleyicisi doğru cevaptır: yolda olan yazmalar elektrik kesintisinden sağ çıkar. ZFS, Ceph veya Storage Spaces kullanıyorsanız tam tersi geçerlidir — bunlar diski ham görmek ister ve donanımsal RAID katmanı yalnızca engel olur. Orada HBA gerekir.',
                ],
            ],
            [
                'h' => ['fa' => 'AROC و PCIe جای هم نمی‌نشینند', 'en' => 'AROC and PCIe do not interchange', 'tr' => 'AROC ve PCIe yer değiştiremez'],
                'p' => [
                    'fa' => 'کارت AROC روی اسلات اختصاصی مادربرد می‌نشیند و اسلات‌های PCIe را آزاد نگه می‌دارد؛ کارت PCIe در اسلات توسعهٔ معمولی. این دو جای هم نمی‌روند و هر نسل اسلات AROC مخصوص خودش را دارد: P440ar فقط روی Gen9 و P408i-a فقط روی Gen10. پیش از سفارش، نسل سرور را با فهرست سازگاری روی همان محصول تطبیق دهید.',
                    'en' => 'An AROC card mounts in the board’s dedicated slot and leaves your PCIe slots free; a PCIe card goes in an ordinary expansion slot. The two are not interchangeable, and each generation has its own AROC slot: the P440ar is Gen9 only, the P408i-a Gen10 only. Match your server generation against the compatibility list on the product before ordering.',
                    'tr' => 'AROC kart anakartın özel yuvasına takılır ve PCIe yuvalarınızı boş bırakır; PCIe kart sıradan bir genişletme yuvasına girer. İkisi birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır: P440ar yalnızca Gen9, P408i-a yalnızca Gen10. Sipariş öncesi sunucu neslinizi üründeki uyumluluk listesiyle karşılaştırın.',
                ],
            ],
            [
                'h' => ['fa' => 'باتری کش را فراموش نکنید', 'en' => 'Do not forget the cache battery', 'tr' => 'Önbellek pilini unutmayın'],
                'p' => [
                    'fa' => 'کنترلر RAID بدون باتری یا خازن پشتیبان، حالت نوشتن با کش را خودش غیرفعال می‌کند و به نوشتن مستقیم برمی‌گردد. نتیجه، افت چشمگیر سرعت نوشتن است بی‌آنکه هیچ خطایی ببینید. باتری‌های قدیمی هم بعد از چند سال ظرفیتشان می‌افتد و همین اتفاق تکرار می‌شود؛ وضعیتش در iLO زیر نام Cache Module قابل دیدن است.',
                    'en' => 'Without a battery or capacitor backup, a RAID controller disables write-back caching itself and falls back to write-through. The result is a marked drop in write speed with no error shown anywhere. Old batteries also lose capacity after a few years and cause the same thing; iLO reports the state under Cache Module.',
                    'tr' => 'Pil veya kondansatör desteği olmadan RAID denetleyicisi write-back önbelleklemeyi kendisi devre dışı bırakır ve write-through’a döner. Sonuç, hiçbir yerde hata görünmeden yazma hızında belirgin bir düşüştür. Eski piller de birkaç yıl sonra kapasite kaybeder ve aynı şey tekrarlanır; iLO durumu Cache Module altında bildirir.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'آیا RAID جای پشتیبان‌گیری را می‌گیرد؟', 'en' => 'Does RAID replace backups?', 'tr' => 'RAID yedeklemenin yerini tutar mı?'],
                'a' => [
                    'fa' => 'نه، و این خطرناک‌ترین سوءتفاهم در نگهداری سرور است. RAID فقط در برابر خرابی سخت‌افزاری دیسک محافظت می‌کند. حذف اشتباهی فایل، باج‌افزار، خرابی پایگاه داده، آتش‌سوزی و سرقت — هیچ‌کدام را پوشش نمی‌دهد، چون هرکدام همان لحظه روی همهٔ دیسک‌ها تکرار می‌شود. RAID دسترس‌پذیری می‌دهد، نه بازیابی.',
                    'en' => 'No, and this is the most dangerous misconception in server operations. RAID protects only against a disk failing as hardware. Accidental deletion, ransomware, database corruption, fire and theft are none of them covered, because each replicates across every disk the instant it happens. RAID buys availability, not recovery.',
                    'tr' => 'Hayır ve bu, sunucu işletiminde en tehlikeli yanlış anlamadır. RAID yalnızca diskin donanım olarak arızalanmasına karşı korur. Yanlışlıkla silme, fidye yazılımı, veritabanı bozulması, yangın ve hırsızlık — hiçbiri kapsanmaz, çünkü her biri olduğu anda tüm disklere yansır. RAID erişilebilirlik sağlar, kurtarma değil.',
                ],
            ],
            [
                'q' => ['fa' => 'کدام سطح RAID را انتخاب کنم؟', 'en' => 'Which RAID level should I pick?', 'tr' => 'Hangi RAID seviyesini seçmeliyim?'],
                'a' => [
                    'fa' => 'برای دیسک سیستم‌عامل، RAID 1 (دو دیسک آینه‌ای) ساده و مطمئن است. برای ذخیره‌سازی عمومی، RAID 10 بهترین تعادل سرعت و امنیت را می‌دهد ولی نصف ظرفیت را می‌گیرد. RAID 5 ظرفیت بیشتری می‌دهد اما با دیسک‌های بزرگ امروزی بازسازی‌اش ساعت‌ها طول می‌کشد و یک خرابی دیگر در همان بازه یعنی از دست رفتن کل آرایه؛ برای دیسک بالای ۴ ترابایت، RAID 6 انتخاب امن‌تری است.',
                    'en' => 'For the OS volume, RAID 1 (two mirrored disks) is simple and dependable. For general storage, RAID 10 gives the best balance of speed and safety but costs half the capacity. RAID 5 yields more usable space, but with today’s large disks a rebuild takes hours, and a second failure in that window loses the whole array; above 4 TB per disk, RAID 6 is the safer choice.',
                    'tr' => 'İşletim sistemi birimi için RAID 1 (iki aynalı disk) basit ve güvenilirdir. Genel depolama için RAID 10 hız ve güvenlik dengesini en iyi kurar ama kapasitenin yarısına mal olur. RAID 5 daha çok kullanılabilir alan verir; ancak bugünün büyük diskleriyle yeniden oluşturma saatler sürer ve bu pencerede ikinci bir arıza tüm diziyi kaybettirir; disk başına 4 TB üzerinde RAID 6 daha güvenlidir.',
                ],
            ],
        ],
    ],

    // ═══════════════════════════════ NIC ═══════════════════════════════
    'nic' => [
        'meta' => [
            'fa' => 'خرید کارت شبکهٔ سرور — کارت ۱، ۱۰، ۲۵ و ۴۰ گیگابیت FlexibleLOM و PCIe برای HPE ProLiant، با تفاوت RJ45 و SFP+ و راهنمای انتخاب ماژول.',
            'en' => 'Buy server network adapters: 1, 10, 25 and 40 Gb FlexibleLOM and PCIe cards for HPE ProLiant, with the RJ45 versus SFP+ distinction and module guidance.',
            'tr' => 'Sunucu ağ kartları: HPE ProLiant için 1, 10, 25 ve 40 Gb FlexibleLOM ve PCIe kartlar — RJ45 ile SFP+ farkı ve modül rehberliğiyle.',
        ],
        'intro' => [
            'fa' => 'اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و ارزان‌ترین ارتقایی که می‌توانید انجام دهید.',
            'en' => 'If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and the cheapest upgrade available to you.',
            'tr' => 'Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genellikle çarptığınız ilk darboğazdır — ve yapabileceğiniz en ucuz yükseltmedir.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'RJ45 یا SFP+؟', 'en' => 'RJ45 or SFP+?', 'tr' => 'RJ45 mi SFP+ mı?'],
                'p' => [
                    'fa' => 'RJ45 با کابل مسی استاندارد کار می‌کند و ماژول جدا نمی‌خواهد؛ ساده‌ترین راه رسیدن به ده گیگابیت اگر فاصله کوتاه باشد. SFP+ ماژول جداگانه لازم دارد ولی برق کمتری مصرف می‌کند، گرمای کمتری تولید می‌کند و برای فاصله‌های بلند و فیبر تنها گزینه است. یک نکتهٔ مهم: بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند، پس پیش از خرید ماژول این را چک کنید.',
                    'en' => 'RJ45 runs on ordinary copper with no separate module — the simplest route to ten gigabit over short distances. SFP+ needs a module, but draws less power, runs cooler, and is the only option for long runs and fibre. One important caveat: some switches accept only their own branded modules, so check before buying one.',
                    'tr' => 'RJ45 sıradan bakır kabloyla, ayrı modül olmadan çalışır — kısa mesafede on gigabite en basit yol. SFP+ modül ister ama daha az güç çeker, daha az ısınır ve uzun mesafeler ile fiber için tek seçenektir. Önemli bir uyarı: bazı anahtarlar yalnızca kendi markalı modüllerini kabul eder, almadan önce kontrol edin.',
                ],
            ],
            [
                'h' => ['fa' => 'FlexibleLOM اسلات PCIe را آزاد نگه می‌دارد', 'en' => 'FlexibleLOM keeps your PCIe slots free', 'tr' => 'FlexibleLOM PCIe yuvalarınızı boş bırakır'],
                'p' => [
                    'fa' => 'کارت FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشیند، یعنی اسلات‌های PCIe برای کارت گرافیک یا کنترلر ذخیره‌سازی باقی می‌مانند. کارت PCIe در اسلات FlexibleLOM جا نمی‌رود و برعکس. در سرور ۱U که فقط دو یا سه اسلات PCIe دارد، همین تفاوت پیکربندی نهایی را تعیین می‌کند.',
                    'en' => 'A FlexibleLOM card sits in the board’s dedicated slot, which leaves your PCIe slots available for a GPU or a storage controller. A PCIe card does not fit a FlexibleLOM slot, or the other way round. In a 1U server with only two or three PCIe slots, that distinction decides the build.',
                    'tr' => 'FlexibleLOM kart anakartın özel yuvasına oturur; böylece PCIe yuvaları GPU veya depolama denetleyicisi için kalır. PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına girmez. Yalnızca iki-üç PCIe yuvası olan 1U sunucuda bu ayrım yapılandırmayı belirler.',
                ],
            ],
            [
                'h' => ['fa' => 'دو پورت یعنی افزونگی، نه لزوماً دو برابر سرعت', 'en' => 'Two ports means redundancy, not automatically double speed', 'tr' => 'İki port yedeklilik demektir, otomatik olarak iki kat hız değil'],
                'p' => [
                    'fa' => 'کارت دو پورته را هم می‌شود برای افزونگی بست (یکی خراب شد، دیگری کار می‌کند) و هم برای پهنای باند بیشتر با LACP. ولی حالت دوم به پیکربندی سمت سوییچ هم نیاز دارد و بدون آن، ترافیک فقط روی یک پورت می‌رود. ضمناً حتی با LACP، یک اتصال TCP منفرد همچنان محدود به یک پورت است؛ سود واقعی وقتی است که چند اتصال هم‌زمان دارید.',
                    'en' => 'A dual-port card can be configured for redundancy (one fails, the other carries on) or for more bandwidth with LACP. The second mode also requires configuration on the switch side; without it, traffic uses a single port only. And even with LACP a single TCP connection is still bound to one port — the real gain appears when you have many concurrent connections.',
                    'tr' => 'Çift portlu kart yedeklilik için (biri arızalanır, diğeri devam eder) veya LACP ile daha fazla bant genişliği için yapılandırılabilir. İkinci mod anahtar tarafında da yapılandırma ister; onsuz trafik yalnızca tek portu kullanır. LACP ile bile tek bir TCP bağlantısı hâlâ tek porta bağlıdır — gerçek kazanç çok sayıda eşzamanlı bağlantıda ortaya çıkar.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'برای ۱۰ گیگابیت چه چیزهایی لازم دارم؟', 'en' => 'What do I need for 10 gigabit?', 'tr' => '10 gigabit için neye ihtiyacım var?'],
                'a' => [
                    'fa' => 'سه چیز: کارت شبکهٔ ۱۰ گیگابیتی روی سرور، سوییچ با پورت ۱۰ گیگابیتی، و رسانهٔ مناسب — کابل Cat6a برای RJ45 یا ماژول و فیبر (یا کابل DAC) برای SFP+. اگر هرکدام نباشد، اتصال روی سرعت پایین‌تر مذاکره می‌شود بی‌آنکه خطایی ببینید.',
                    'en' => 'Three things: a 10 Gb adapter in the server, a switch with 10 Gb ports, and the right medium — Cat6a cable for RJ45, or a module plus fibre (or a DAC cable) for SFP+. If any one is missing the link simply negotiates down to a lower speed, with no error shown.',
                    'tr' => 'Üç şey: sunucuda 10 Gb kart, 10 Gb portlu bir anahtar ve doğru ortam — RJ45 için Cat6a kablo veya SFP+ için modül ve fiber (ya da DAC kablo). Biri eksikse bağlantı hata göstermeden daha düşük hızda anlaşır.',
                ],
            ],
            [
                'q' => ['fa' => 'کارت ۲۵ گیگابیتی روی سوییچ ۱۰ گیگابیتی کار می‌کند؟', 'en' => 'Will a 25 Gb card work with a 10 Gb switch?', 'tr' => '25 Gb kart 10 Gb anahtarla çalışır mı?'],
                'a' => [
                    'fa' => 'بله. کارت SFP28 با ماژول SFP+ ده گیگابیتی روی سوییچ ده گیگابیتی کار می‌کند و روی همان سرعت مذاکره می‌شود. یعنی می‌توانید امروز کارت ۲۵ گیگابیتی بخرید و بعداً که سوییچ را ارتقا دادید بدون تعویض کارت به ۲۵ برسید — و قیمت کارت ۲۵ گیگابیتی استوک اغلب از ۱۰ گیگابیتی بالاتر نیست.',
                    'en' => 'Yes. An SFP28 card with a 10 Gb SFP+ module works against a 10 Gb switch and negotiates at that speed. Which means you can buy a 25 Gb card today and reach 25 later by upgrading only the switch — and used 25 Gb cards often cost no more than 10 Gb ones.',
                    'tr' => 'Evet. SFP28 kart, 10 Gb SFP+ modülle 10 Gb anahtara karşı çalışır ve o hızda anlaşır. Yani bugün 25 Gb kart alıp yalnızca anahtarı yükselterek sonradan 25’e çıkabilirsiniz — ve ikinci el 25 Gb kartlar çoğu zaman 10 Gb olanlardan pahalı değildir.',
                ],
            ],
        ],
    ],

    // ═══════════════════════════════ PSU ═══════════════════════════════
    'psu' => [
        'meta' => [
            'fa' => 'خرید منبع تغذیهٔ سرور HPE — پاور هات‌پلاگ ۴۶۰ تا ۱۶۰۰ وات با گواهی Platinum برای ProLiant نسل ۸ تا ۱۱، با تفاوت Common Slot و Flex Slot.',
            'en' => 'Buy HPE server power supplies: 460 W to 1600 W hot-plug Platinum units for ProLiant Gen8–Gen11, with the Common Slot versus Flex Slot distinction explained.',
            'tr' => 'HPE sunucu güç kaynakları: ProLiant Gen8–Gen11 için 460 W–1600 W hot-plug Platinum üniteler — Common Slot ile Flex Slot farkı açıklanmış.',
        ],
        'intro' => [
            'fa' => 'منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد. با دو منبع، سوختن یکی یعنی یک چراغ قرمز؛ با یکی، یعنی قطعی. همیشه سرور را با دو منبع ببندید، حتی اگر یکی برای بارتان کافی باشد.',
            'en' => 'The power supply is the component that fails most often in a server. With two fitted, a failure is a red light; with one, it is an outage. Always build with two, even when a single unit covers your load.',
            'tr' => 'Güç kaynağı bir sunucuda en sık arızalanan bileşendir. İki tane takılıysa arıza kırmızı bir ışıktır; tek taneyle kesintidir. Yükünüz için tek ünite yetse bile daima iki tane ile kurun.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'Common Slot و Flex Slot جای هم نمی‌نشینند', 'en' => 'Common Slot and Flex Slot do not interchange', 'tr' => 'Common Slot ve Flex Slot yer değiştiremez'],
                'p' => [
                    'fa' => 'سرورهای نسل ۶ تا ۸ از منبع Common Slot استفاده می‌کنند و نسل ۹ به بعد از Flex Slot. ابعاد و کانکتورشان متفاوت است و اشتباه‌گرفتنشان یعنی قطعه‌ای که در جایگاه نمی‌رود. روی برچسب هر منبع، نسل‌های سازگار نوشته شده و ما هم روی هر محصول همان را اعلام می‌کنیم.',
                    'en' => 'Gen6 to Gen8 servers use Common Slot supplies; Gen9 onwards use Flex Slot. Their dimensions and connectors differ, and confusing them means a part that will not go in the bay. Compatible generations are printed on every supply’s label, and we state the same on each product.',
                    'tr' => 'Gen6–Gen8 sunucular Common Slot, Gen9 ve sonrası Flex Slot güç kaynağı kullanır. Boyutları ve konnektörleri farklıdır; karıştırmak yuvaya girmeyen bir parça demektir. Uyumlu nesiller her ünitenin etiketinde yazılıdır ve biz de her üründe aynısını belirtiriz.',
                ],
            ],
            [
                'h' => ['fa' => 'چند وات کافی است؟', 'en' => 'How many watts is enough?', 'tr' => 'Kaç watt yeterli?'],
                'p' => [
                    'fa' => 'یک سرور دو پردازنده‌ای معمولی با ۸ تا ۱۲ دیسک زیر بار کامل حدود ۴۰۰ تا ۶۰۰ وات مصرف می‌کند؛ ۸۰۰ وات برای اکثر پیکربندی‌ها با حاشیهٔ امن کافی است. اگر پردازندهٔ بالای ۲۰۰ وات یا کارت گرافیک دارید، به ۱۴۰۰ وات یا بالاتر بروید. منبع بزرگ‌تر از نیاز، برق اضافه مصرف نمی‌کند — فقط در نقطهٔ بهینهٔ بازده خود کار نمی‌کند.',
                    'en' => 'A typical dual-socket server with 8 to 12 drives draws roughly 400 to 600 W at full load; 800 W covers most configurations with safe headroom. With a processor above 200 W or a GPU, move to 1400 W or higher. An oversized supply does not consume extra power — it simply runs away from its efficiency sweet spot.',
                    'tr' => '8–12 diskli tipik bir çift soketli sunucu tam yükte kabaca 400–600 W çeker; 800 W çoğu yapılandırmayı güvenli bir payla karşılar. 200 W üzeri işlemci veya GPU varsa 1400 W ve üzerine çıkın. Gereğinden büyük bir ünite fazladan güç tüketmez — yalnızca verimlilik optimumunun dışında çalışır.',
                ],
            ],
            [
                'h' => ['fa' => 'دو منبع باید هم‌توان باشند', 'en' => 'Both supplies should be the same wattage', 'tr' => 'İki ünite aynı güçte olmalı'],
                'p' => [
                    'fa' => 'اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور در iLO هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند — چون اگر منبع بزرگ‌تر بسوزد، کوچک‌تر ممکن است نتواند کل بار را بردارد. برای همین اگر یک منبع سوخت، جایگزینش باید هم‌توان باشد نه صرفاً «یک پاور دیگر».',
                    'en' => 'Fit two supplies of different wattage and the server warns in iLO and does not treat the redundancy as guaranteed — because if the larger one fails, the smaller may not carry the full load. So when one dies, its replacement must match the wattage, not merely be “another PSU”.',
                    'tr' => 'Farklı güçte iki ünite takarsanız sunucu iLO’da uyarır ve yedekliliği garantili saymaz — çünkü büyük olan arızalanırsa küçük olan tüm yükü taşıyamayabilir. Bu yüzden biri bozulduğunda yerine geleni yalnızca “başka bir güç kaynağı” değil, aynı güçte olmalıdır.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'منبع تغذیه را می‌شود با سرور روشن عوض کرد؟', 'en' => 'Can a power supply be swapped with the server running?', 'tr' => 'Güç kaynağı sunucu çalışırken değiştirilebilir mi?'],
                'a' => [
                    'fa' => 'بله، به شرط آنکه دو منبع نصب باشد و منبع سالم بتواند بار را تنهایی بردارد. منابع ProLiant هات‌پلاگ‌اند: اهرم را فشار می‌دهید، منبع را بیرون می‌کشید و جدید را جا می‌زنید. اگر فقط یک منبع دارید، بیرون کشیدنش یعنی خاموش شدن سرور.',
                    'en' => 'Yes, provided two are fitted and the healthy one can carry the load alone. ProLiant supplies are hot-plug: press the latch, slide the unit out and seat the new one. With only one supply, pulling it means the server shuts down.',
                    'tr' => 'Evet — iki ünite takılıysa ve sağlam olan yükü tek başına taşıyabiliyorsa. ProLiant üniteleri hot-plug’dır: mandala basın, üniteyi çekin ve yenisini yerleştirin. Tek üniteniz varsa onu çekmek sunucunun kapanması demektir.',
                ],
            ],
            [
                'q' => ['fa' => 'گواهی Platinum چه فرقی می‌کند؟', 'en' => 'What difference does a Platinum rating make?', 'tr' => 'Platinum sertifikası ne fark eder?'],
                'a' => [
                    'fa' => 'بازده Platinum یعنی حدود ۹۴ درصد برق ورودی به سرور می‌رسد و بقیه به گرما تبدیل می‌شود. در برابر یک منبع ۸۵ درصدی، روی سرور ۵۰۰ واتی سالانه حدود ۴۵۰ کیلووات‌ساعت صرفه‌جویی است — و مهم‌تر از هزینهٔ برق، گرمای کمتری که سیستم سرمایش رک باید بیرون ببرد.',
                    'en' => 'A Platinum rating means roughly 94% of the input power reaches the server and the rest becomes heat. Against an 85% unit, on a 500 W server that is about 450 kWh saved per year — and more important than the electricity bill is the heat your rack cooling no longer has to remove.',
                    'tr' => 'Platinum sertifikası, giriş gücünün kabaca %94’ünün sunucuya ulaştığı, kalanın ısıya dönüştüğü anlamına gelir. %85’lik bir üniteye karşı, 500 W’lık bir sunucuda yılda yaklaşık 450 kWh tasarruf demektir — ve elektrik faturasından önemlisi, raf soğutmanızın artık atmak zorunda olmadığı ısıdır.',
                ],
            ],
        ],
    ],

    // ═════════════════════════════ CHASSIS ═════════════════════════════
    'chassis' => [
        'meta' => [
            'fa' => 'خرید شاسی بربون سرور HPE ProLiant — DL380 و DL360 نسل ۸ تا ۱۰ بدون پردازنده و حافظه، برای ساخت سرور با پیکربندی دقیقاً دلخواه خودتان.',
            'en' => 'Buy HPE ProLiant barebone chassis: DL380 and DL360 Gen8–Gen10 without processor or memory, to build a server to exactly your own configuration.',
            'tr' => 'HPE ProLiant barebone kasalar: işlemci ve bellek olmadan DL380 ve DL360 Gen8–Gen10 — tam olarak kendi yapılandırmanızla sunucu kurmak için.',
        ],
        'intro' => [
            'fa' => 'بربون یعنی مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر. وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.',
            'en' => 'A barebone is motherboard, backplane, fans and enclosure — no processor, memory, drives or controller. It makes sense when you already hold the parts, or want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.',
            'tr' => 'Barebone; anakart, backplane, fanlar ve muhafaza demektir — işlemci, bellek, disk ve denetleyici hariç. Parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Çalışmaya hazır ikinci el bir sunucu istiyorsanız komple sunucu listemiz daha basit bir seçenektir.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'SFF یا LFF — این تصمیم برگشت‌پذیر نیست', 'en' => 'SFF or LFF — this choice does not reverse', 'tr' => 'SFF mi LFF mi — bu seçim geri alınmaz'],
                'p' => [
                    'fa' => 'شاسی SFF جایگاه دیسک ۲٫۵ اینچی دارد (معمولاً ۸ تا ۲۴ عدد) و برای IOPS بالا و اس‌اس‌دی ساخته شده. شاسی LFF جایگاه ۳٫۵ اینچی دارد (۱۲ تا ۱۴ عدد) و برای ذخیره‌سازی حجیم. بک‌پلین این دو متفاوت است و تبدیل یکی به دیگری عملاً یعنی خرید شاسی دیگر. پیش از سفارش تصمیم بگیرید سرور را برای سرعت می‌خواهید یا برای حجم.',
                    'en' => 'An SFF chassis takes 2.5-inch bays (typically 8 to 24) and is built for high IOPS and SSDs. An LFF chassis takes 3.5-inch bays (12 to 14) and is built for bulk capacity. Their backplanes differ, and converting one to the other means buying a different chassis in practice. Decide before ordering whether you want the server for speed or for volume.',
                    'tr' => 'SFF kasa 2,5 inç yuvalar alır (tipik olarak 8–24) ve yüksek IOPS ile SSD’ler için tasarlanmıştır. LFF kasa 3,5 inç yuvalar alır (12–14) ve toplu kapasite içindir. Backplane’leri farklıdır ve birini diğerine çevirmek pratikte başka bir kasa almak demektir. Sipariş öncesi sunucuyu hız için mi hacim için mi istediğinize karar verin.',
                ],
            ],
            [
                'h' => ['fa' => 'برای راه‌اندازی چه چیزهایی لازم است', 'en' => 'What you need to bring it up', 'tr' => 'Çalıştırmak için gerekenler'],
                'p' => [
                    'fa' => 'دست‌کم: یک پردازندهٔ سازگار با همان نسل، حافظهٔ ECC مناسب، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه. اگر پردازندهٔ بالای ۱۳۵ وات انتخاب کرده‌اید، هیت‌سینک پرکارایی و کیت فن هم اضافه کنید. صفحهٔ هر نسل در همین فروشگاه، قطعات سازگار با آن نسل را یک‌جا فهرست می‌کند.',
                    'en' => 'At minimum: a processor compatible with that generation, suitable ECC memory, a storage controller, drives in correct-generation caddies, and two power supplies. If you picked a processor above 135 W, add the high-performance heatsink and fan kit. Each generation page in this shop lists everything compatible with that generation in one place.',
                    'tr' => 'En az: o nesille uyumlu bir işlemci, uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı. 135 W üzeri bir işlemci seçtiyseniz yüksek performanslı soğutucu ve fan kitini de ekleyin. Bu mağazadaki her nesil sayfası, o nesille uyumlu her şeyi tek yerde listeler.',
                ],
            ],
            [
                'h' => ['fa' => '۱U یا ۲U؟', 'en' => '1U or 2U?', 'tr' => '1U mu 2U mu?'],
                'p' => [
                    'fa' => 'شاسی ۱U مثل DL360 جای کمتری در رک می‌گیرد ولی محدودیت‌های واقعی دارد: اسلات PCIe کمتر، جایگاه دیسک کمتر، فن‌های کوچک‌تر و پرصداتر، و سقف توان حرارتی پایین‌تر. شاسی ۲U مثل DL380 دو برابر جا می‌گیرد ولی تقریباً همه‌چیز در آن راحت‌تر است. اگر هزینهٔ رک برایتان تعیین‌کننده نیست، ۲U انتخاب عملی‌تری است.',
                    'en' => 'A 1U chassis such as a DL360 takes less rack space but carries real limits: fewer PCIe slots, fewer drive bays, smaller and louder fans, and a lower thermal ceiling. A 2U chassis such as a DL380 takes twice the space but makes nearly everything easier. Unless rack cost dominates your decision, 2U is the more practical choice.',
                    'tr' => 'DL360 gibi bir 1U kasa rafta daha az yer kaplar ama gerçek sınırları vardır: daha az PCIe yuvası, daha az disk yuvası, daha küçük ve gürültülü fanlar ve daha düşük termal tavan. DL380 gibi bir 2U kasa iki katı yer kaplar ama neredeyse her şeyi kolaylaştırır. Raf maliyeti kararınızı belirlemiyorsa 2U daha pratik seçimdir.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'بربون بخرم یا سرور کامل؟', 'en' => 'Should I buy a barebone or a complete server?', 'tr' => 'Barebone mu komple sunucu mu almalıyım?'],
                'a' => [
                    'fa' => 'اگر قطعات را از قبل دارید یا پیکربندی خاصی می‌خواهید که آماده پیدا نمی‌شود، بربون ارزان‌تر تمام می‌شود. اگر می‌خواهید سرور همان روز راه بیفتد و تست‌شده تحویل بگیرید، سرور کامل کم‌دردسرتر است — چون سازگاری همهٔ قطعات از پیش تأیید شده و کل دستگاه یک‌جا گارانتی می‌شود.',
                    'en' => 'If you already hold the parts, or want a specific configuration that is not available ready-built, a barebone works out cheaper. If you want the server running the same day and delivered tested, a complete machine is less trouble — compatibility across every part is already proven and the whole unit carries one warranty.',
                    'tr' => 'Parçalara zaten sahipseniz veya hazır bulunmayan özel bir yapılandırma istiyorsanız barebone daha ucuza gelir. Sunucunun aynı gün çalışmasını ve test edilmiş teslim edilmesini istiyorsanız komple makine daha az zahmetlidir — tüm parçaların uyumu önceden kanıtlanmıştır ve ünitenin tamamı tek garanti taşır.',
                ],
            ],
        ],
    ],

    // ═══════════════════════════════ GPU ═══════════════════════════════
    'gpu' => [
        'meta' => [
            'fa' => 'خرید کارت گرافیک سرور — NVIDIA Tesla P4، T4 و A2 با خنک‌کاری غیرفعال برای HPE ProLiant، مناسب استنتاج هوش مصنوعی، ترنسکد ویدئو و دسکتاپ مجازی.',
            'en' => 'Buy server GPUs: passively cooled NVIDIA Tesla P4, T4 and A2 for HPE ProLiant — suited to AI inference, video transcoding and virtual desktops.',
            'tr' => 'Sunucu GPU’ları: HPE ProLiant için pasif soğutmalı NVIDIA Tesla P4, T4 ve A2 — yapay zekâ çıkarımı, video kod dönüştürme ve sanal masaüstü için.',
        ],
        'intro' => [
            'fa' => 'کارت گرافیک سرور فن ندارد: خنک‌کاری را جریان هوای خود شاسی انجام می‌دهد. همین یعنی نمی‌توانید کارت گرافیک دسکتاپ را جایگزینش کنید و انتظار داشته باشید دوام بیاورد.',
            'en' => 'A server GPU has no fan of its own: chassis airflow cools it. That is also why you cannot drop in a desktop graphics card and expect it to survive.',
            'tr' => 'Sunucu GPU’sunun kendi fanı yoktur: onu kasa hava akışı soğutur. Bir masaüstü ekran kartını takıp dayanmasını bekleyememenizin nedeni de budur.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'استنتاج یا آموزش؟', 'en' => 'Inference or training?', 'tr' => 'Çıkarım mı eğitim mi?'],
                'p' => [
                    'fa' => 'کارت‌های این دسته برای استنتاج ساخته شده‌اند: اجرای مدل آموزش‌دیده روی درخواست‌های ورودی. برای آموزش مدل‌های بزرگ، حافظهٔ ۸ تا ۱۶ گیگابایتی این کارت‌ها کافی نیست و به رده‌های بالاتر نیاز دارید. اگر کاربردتان ترنسکد ویدئو یا دسکتاپ مجازی است، همین کارت‌ها دقیقاً همان چیزی‌اند که لازم دارید.',
                    'en' => 'The cards in this category are built for inference: running a trained model against incoming requests. For training large models their 8 to 16 GB of memory is not enough and you need a higher tier. If your use case is video transcoding or virtual desktops, these cards are exactly what you want.',
                    'tr' => 'Bu kategorideki kartlar çıkarım için tasarlanmıştır: eğitilmiş bir modeli gelen isteklere karşı çalıştırmak. Büyük modelleri eğitmek için 8–16 GB bellekleri yetmez, daha üst sınıf gerekir. Kullanım amacınız video kod dönüştürme veya sanal masaüstü ise bu kartlar tam olarak aradığınız şeydir.',
                ],
            ],
            [
                'h' => ['fa' => 'شاسی باید آماده باشد', 'en' => 'The chassis has to be ready for it', 'tr' => 'Kasa buna hazır olmalı'],
                'p' => [
                    'fa' => 'پیش از سفارش تأیید کنید که شاسی شما کیت جریان هوای GPU دارد و منبع تغذیه‌اش توان کافی. بعضی پیکربندی‌ها به منبع بزرگ‌تر و کیت فن پرکارایی نیاز پیدا می‌کنند، و بدون آن‌ها یا کارت شناسایی نمی‌شود یا زیر بار داغ می‌کند و خودش را عقب می‌کشد.',
                    'en' => 'Before ordering, confirm your chassis has the GPU airflow kit and that the power supply has headroom. Some configurations need a larger supply and the high-performance fan kit; without them the card is either not detected or overheats under load and throttles itself.',
                    'tr' => 'Sipariş öncesi kasanızda GPU hava akış kiti olduğunu ve güç kaynağında pay bulunduğunu doğrulayın. Bazı yapılandırmalar daha büyük ünite ve yüksek performanslı fan kiti ister; bunlar olmadan kart ya algılanmaz ya da yük altında ısınıp kendini kısar.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'کارت گرافیک بازی روی سرور نصب می‌شود؟', 'en' => 'Can I fit a gaming graphics card in a server?', 'tr' => 'Sunucuya oyun ekran kartı takabilir miyim?'],
                'a' => [
                    'fa' => 'از نظر فیزیکی گاهی بله، ولی دو مشکل جدی دارد. اول اینکه کارت دسکتاپ فن خودش را دارد که با جریان هوای جلو-به-عقب شاسی سرور می‌جنگد و نتیجه گرم شدن هر دو است. دوم اینکه درایور سرور و پشتیبانی مجازی‌سازی (vGPU) روی کارت‌های بازی وجود ندارد. برای بار تولیدی، کارت سرور بخرید.',
                    'en' => 'Physically sometimes yes, but with two serious problems. First, a desktop card has its own fan, which fights the front-to-back airflow of a server chassis and leaves both hotter. Second, server drivers and virtualisation support (vGPU) do not exist for gaming cards. For production work, buy a server card.',
                    'tr' => 'Fiziksel olarak bazen evet, ancak iki ciddi sorunla. Birincisi, masaüstü kartının kendi fanı vardır ve sunucu kasasının önden arkaya hava akışıyla çatışır; ikisi de daha sıcak kalır. İkincisi, oyun kartları için sunucu sürücüleri ve sanallaştırma desteği (vGPU) yoktur. Üretim işi için sunucu kartı alın.',
                ],
            ],
        ],
    ],

    // ══════════════════════════════ OTHER ══════════════════════════════
    'other' => [
        'meta' => [
            'fa' => 'خرید متعلقات سرور HPE — کدی دیسک، ریل رک، هیت‌سینک، کیت فن و لایسنس iLO Advanced، همان قطعات کوچکی که نبودشان کل نصب را متوقف می‌کند.',
            'en' => 'Buy HPE server accessories: drive caddies, rack rails, heatsinks, fan kits and the iLO Advanced licence — the small parts whose absence stops an installation dead.',
            'tr' => 'HPE sunucu aksesuarları: disk kızakları, raf rayları, soğutucular, fan kitleri ve iLO Advanced lisansı — yokluğu bir kurulumu durduran küçük parçalar.',
        ],
        'intro' => [
            'fa' => 'این‌ها قطعاتی هستند که کسی از اول به فکرشان نیست و بعد کل نصب را متوقف می‌کنند: دیسکی که بدون کدی جا نمی‌رود، سروری که بدون ریل نمی‌شود سرویسش کرد، پردازنده‌ای که بدون هیت‌سینک درست خودش را عقب می‌کشد.',
            'en' => 'These are the parts nobody plans for and which then stop an installation dead: a drive that will not seat without a caddy, a server that cannot be serviced without rails, a processor that throttles itself without the right heatsink.',
            'tr' => 'Bunlar kimsenin baştan düşünmediği ve sonra bir kurulumu durduran parçalardır: kızak olmadan oturmayan disk, ray olmadan servis edilemeyen sunucu, doğru soğutucu olmadan kendini kısan işlemci.',
        ],
        'guide' => [
            [
                'h' => ['fa' => 'کدی را با نسل سرور سفارش دهید، نه با مدل دیسک', 'en' => 'Order caddies by server generation, not drive model', 'tr' => 'Kızakları disk modeline değil sunucu nesline göre sipariş edin'],
                'p' => [
                    'fa' => 'کدی Smart Carrier نسل ۸ تا ۱۰ و کدی Basic Carrier نسل ۱۰ به بعد ظاهر مشابهی دارند و جای هم نمی‌نشینند. تنها معیار درست، نسل خود سرور است. اگر مطمئن نیستید، شمارهٔ سریال سرور را برای ما بفرستید تا مدل دقیق را اعلام کنیم.',
                    'en' => 'The Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier look alike and do not interchange. The only correct criterion is the server generation itself. If you are unsure, send us the server serial and we will confirm the exact model.',
                    'tr' => 'Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirine benzer ve yer değiştiremez. Tek doğru ölçüt sunucunun neslidir. Emin değilseniz sunucu seri numarasını gönderin, tam modeli teyit edelim.',
                ],
            ],
            [
                'h' => ['fa' => 'لایسنس iLO به سریال سرور بسته می‌شود', 'en' => 'The iLO licence binds to the server serial', 'tr' => 'iLO lisansı sunucu seri numarasına bağlanır'],
                'p' => [
                    'fa' => 'لایسنس iLO Advanced قابل انتقال به سرور دیگر نیست؛ برای هر دستگاه یکی لازم است. در عوض چیزی که باز می‌کند واقعاً کار اپراتور را عوض می‌کند: کنسول گرافیکی از راه دور، مانت مجازی رسانه و ضبط لحظهٔ کرش — یعنی نصب سیستم‌عامل و رفع خطای بوت بی‌آنکه کسی جلوی رک باشد.',
                    'en' => 'An iLO Advanced licence cannot be moved to another server; you need one per machine. What it unlocks genuinely changes an operator’s day, though: remote graphical console, virtual media mount and crash capture — installing an OS and fixing a boot failure with nobody at the rack.',
                    'tr' => 'iLO Advanced lisansı başka bir sunucuya taşınamaz; makine başına bir tane gerekir. Buna karşılık açtığı şeyler bir operatörün gününü gerçekten değiştirir: uzak grafik konsol, sanal medya bağlama ve çökme kaydı — rafın başında kimse olmadan işletim sistemi kurmak ve açılış hatasını gidermek.',
                ],
            ],
        ],
        'faq' => [
            [
                'q' => ['fa' => 'ریل رک واقعاً لازم است؟', 'en' => 'Are rack rails really necessary?', 'tr' => 'Raf rayları gerçekten gerekli mi?'],
                'a' => [
                    'fa' => 'اگر قرار است هیچ‌وقت به سرور دست نزنید، نه. ولی با ریل کشویی می‌توانید سرور روشن را نیمه بیرون بکشید و دیسک یا فن یا رم را در حال کار عوض کنید. بدون ریل، هر سرویسی یعنی خاموش‌کردن، بازکردن همهٔ کابل‌ها و بیرون‌آوردن کامل دستگاه — و همان‌جاست که به سرور دیگری در رک هم دست می‌زنید.',
                    'en' => 'If you never intend to touch the server, no. But sliding rails let you pull a running server halfway out and swap a drive, a fan or a DIMM while it works. Without them, every service job means powering down, unplugging everything and lifting the whole machine out — which is exactly when you disturb another server in the rack.',
                    'tr' => 'Sunucuya hiç dokunmayacaksanız hayır. Ama kayar raylar, çalışan bir sunucuyu yarıya kadar çekip çalışırken disk, fan veya bellek değiştirmenizi sağlar. Onlar olmadan her servis işi kapatmak, her şeyi sökmek ve makineyi tamamen çıkarmak demektir — raftaki başka bir sunucuyu tam da o sırada rahatsız edersiniz.',
                ],
            ],
        ],
    ],

];
