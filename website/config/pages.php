<?php

/*
|--------------------------------------------------------------------------
| صفحات محتوایی — درباره ما، حریم خصوصی، قوانین (fa / en / tr)
|--------------------------------------------------------------------------
| متن حقوقی عمومی و استاندارد شرکت هاستینگ؛ در صورت نیاز با مشاور حقوقی نهایی شود.
*/

return [

    'about' => [
        'icon' => 'server',
        'fa' => ['tag' => 'درباره سرورنت', 'h1a' => 'از ۱۳۸۸،', 'h1b' => 'زیرساخت رشد شما.',
            'lead' => 'سرورنت با بیش از یک دهه تجربه، از یک تیم کوچک هاستینگ به یک ارائه‌دهنده کامل زیرساخت ابری و راهکارهای سازمانی تبدیل شده — با تمرکز بر پایداری، سرعت و پشتیبانی واقعی.'],
        'en' => ['tag' => 'About ServerNet', 'h1a' => 'Since 2009,', 'h1b' => 'the infrastructure behind your growth.',
            'lead' => 'With over a decade of experience, ServerNet has grown from a small hosting team into a full cloud-infrastructure and enterprise-solutions provider — built on reliability, speed and real support.'],
        'tr' => ['tag' => 'ServerNet Hakkında', 'h1a' => "2009'dan beri,", 'h1b' => 'büyümenizin arkasındaki altyapı.',
            'lead' => 'On yılı aşkın deneyimiyle ServerNet, küçük bir hosting ekibinden tam bir bulut altyapısı ve kurumsal çözüm sağlayıcısına dönüştü.'],
        'stats' => [
            ['n' => '15+', 'fa' => 'سال تجربه', 'en' => 'Years of experience', 'tr' => 'Yıllık deneyim'],
            ['n' => '11+', 'fa' => 'دیتاسنتر', 'en' => 'Datacenters', 'tr' => 'Veri merkezi'],
            ['n' => '10K+', 'fa' => 'کسب‌وکار', 'en' => 'Businesses', 'tr' => 'İşletme'],
            ['n' => '99.9%', 'fa' => 'آپتایم', 'en' => 'Uptime', 'tr' => 'Çalışma süresi'],
        ],
        'story' => [
            ['fa' => ['t' => 'داستان ما', 'b' => 'سرورنت از دل یک نیاز ساده متولد شد: هاستینگی که واقعاً پاسخگو باشد. در سال ۱۳۸۸ با چند سرور و یک تعهد شروع کردیم — اینکه سرویس هر مشتری را مثل سرویس خودمان جدی بگیریم. امروز آن تعهد، پایه‌ی زیرساختی است که هزاران کسب‌وکار روی آن ساخته شده‌اند.'],
             'en' => ['t' => 'Our story', 'b' => 'ServerNet was born from a simple need: hosting that actually responds. In 2009 we started with a handful of servers and one promise — to treat every customer\'s service as if it were our own. Today that promise is the foundation thousands of businesses are built on.'],
             'tr' => ['t' => 'Hikayemiz', 'b' => 'ServerNet basit bir ihtiyaçtan doğdu: gerçekten yanıt veren hosting. 2009\'da birkaç sunucu ve tek bir sözle başladık — her müşterinin hizmetini kendimizinki gibi görmek.']],
            ['fa' => ['t' => 'ماموریت ما', 'b' => 'ما زیرساخت فناوری را برای کسب‌وکارها ساده می‌کنیم؛ از هاست یک سایت شخصی تا دیتاسنتر یک کارخانه. هدف این است که مشتریانمان روی کسب‌وکارشان تمرکز کنند و نگرانی سرور، امنیت و آپتایم را به ما بسپارند.'],
             'en' => ['t' => 'Our mission', 'b' => 'We make technology infrastructure simple for businesses — from a personal site\'s hosting to a factory\'s datacenter. The goal: let our customers focus on their business while we handle servers, security and uptime.'],
             'tr' => ['t' => 'Misyonumuz', 'b' => 'Teknoloji altyapısını işletmeler için basitleştiriyoruz — kişisel bir siteden fabrika veri merkezine kadar.']],
        ],
        'values' => [
            ['icon' => 'headset', 'fa' => ['t' => 'پشتیبانی واقعی', 'd' => 'مهندس واقعی، نه ربات — ۲۴ ساعته.'], 'en' => ['t' => 'Real support', 'd' => 'Real engineers, not bots — 24/7.'], 'tr' => ['t' => 'Gerçek destek', 'd' => 'Bot değil, gerçek mühendisler.']],
            ['icon' => 'shield', 'fa' => ['t' => 'امنیت اول', 'd' => 'داده‌های شما با جدی‌ترین استانداردها محافظت می‌شوند.'], 'en' => ['t' => 'Security first', 'd' => 'Your data protected to the strictest standards.'], 'tr' => ['t' => 'Önce güvenlik', 'd' => 'Verileriniz en katı standartlarla korunur.']],
            ['icon' => 'trend', 'fa' => ['t' => 'رشد مشترک', 'd' => 'وقتی کسب‌وکار شما بزرگ می‌شود، زیرساخت ما همراهش رشد می‌کند.'], 'en' => ['t' => 'Shared growth', 'd' => 'As your business scales, our infrastructure scales with it.'], 'tr' => ['t' => 'Ortak büyüme', 'd' => 'İşiniz büyüdükçe altyapımız da büyür.']],
            ['icon' => 'coins', 'fa' => ['t' => 'شفافیت', 'd' => 'قیمت‌گذاری شفاف، بدون هزینه پنهان.'], 'en' => ['t' => 'Transparency', 'd' => 'Transparent pricing, no hidden fees.'], 'tr' => ['t' => 'Şeffaflık', 'd' => 'Şeffaf fiyatlandırma, gizli ücret yok.']],
        ],
    ],

    'privacy' => [
        'icon' => 'lock',
        'fa' => ['tag' => 'حریم خصوصی', 'h1a' => 'حریم خصوصی', 'h1b' => 'شما، تعهد ما.',
            'lead' => 'این سیاست توضیح می‌دهد چه اطلاعاتی جمع‌آوری می‌کنیم، چرا، و چگونه از آن محافظت می‌کنیم. آخرین به‌روزرسانی: تیر ۱۴۰۵.'],
        'en' => ['tag' => 'Privacy Policy', 'h1a' => 'Your privacy,', 'h1b' => 'our commitment.',
            'lead' => 'This policy explains what data we collect, why, and how we protect it. Last updated: July 2026.'],
        'tr' => ['tag' => 'Gizlilik Politikası', 'h1a' => 'Gizliliğiniz,', 'h1b' => 'bizim taahhüdümüz.',
            'lead' => 'Bu politika hangi verileri topladığımızı, neden ve nasıl koruduğumuzu açıklar. Son güncelleme: Temmuz 2026.'],
        'sections' => [
            ['fa' => ['t' => 'اطلاعاتی که جمع‌آوری می‌کنیم', 'b' => 'برای ارائه سرویس، اطلاعات حساب (نام، ایمیل، شماره تماس)، اطلاعات صورتحساب و داده‌های فنی لازم برای راه‌اندازی سرویس را جمع‌آوری می‌کنیم. اطلاعات پرداخت مستقیماً توسط درگاه‌های بانکی معتبر پردازش می‌شود و ما اطلاعات کارت شما را ذخیره نمی‌کنیم.'],
             'en' => ['t' => 'Information we collect', 'b' => 'To provide our services we collect account information (name, email, phone), billing details and technical data needed to provision your service. Payment information is processed directly by trusted payment gateways; we never store your card details.'],
             'tr' => ['t' => 'Topladığımız bilgiler', 'b' => 'Hizmet sunmak için hesap bilgileri (ad, e-posta, telefon), fatura detayları ve teknik veriler toplarız. Ödeme bilgileri güvenilir ağ geçitlerince işlenir; kart bilgilerinizi asla saklamayız.']],
            ['fa' => ['t' => 'چگونه از اطلاعات استفاده می‌کنیم', 'b' => 'اطلاعات شما فقط برای ارائه و بهبود سرویس، پشتیبانی، صورتحساب و اطلاع‌رسانی‌های مهم درباره سرویس استفاده می‌شود. ما اطلاعات شخصی شما را نمی‌فروشیم و بدون رضایت شما در اختیار اشخاص ثالث قرار نمی‌دهیم.'],
             'en' => ['t' => 'How we use it', 'b' => 'Your information is used only to deliver and improve our services, provide support, handle billing and send important service notices. We do not sell your personal data or share it with third parties without your consent.'],
             'tr' => ['t' => 'Nasıl kullanırız', 'b' => 'Bilgileriniz yalnızca hizmet sunumu, destek, faturalama ve önemli bildirimler için kullanılır. Kişisel verilerinizi satmayız.']],
            ['fa' => ['t' => 'امنیت داده‌ها', 'b' => 'از رمزنگاری، فایروال، کنترل دسترسی و بکاپ منظم برای محافظت از اطلاعات شما استفاده می‌کنیم. با این حال هیچ سیستمی صددرصد امن نیست و ما متعهد به اطلاع‌رسانی سریع در صورت هرگونه رخداد امنیتی هستیم.'],
             'en' => ['t' => 'Data security', 'b' => 'We use encryption, firewalls, access controls and regular backups to protect your data. No system is 100% secure, and we commit to notifying you promptly of any security incident.'],
             'tr' => ['t' => 'Veri güvenliği', 'b' => 'Verilerinizi korumak için şifreleme, güvenlik duvarı ve düzenli yedekleme kullanırız.']],
            ['fa' => ['t' => 'کوکی‌ها', 'b' => 'سایت ما از کوکی‌های ضروری برای عملکرد (مثل نگهداری زبان و تم انتخابی) استفاده می‌کند. کوکی‌های تحلیلی فقط برای بهبود تجربه کاربری و به‌صورت تجمیعی استفاده می‌شوند.'],
             'en' => ['t' => 'Cookies', 'b' => 'Our site uses essential cookies for functionality (such as remembering your language and theme). Analytics cookies are used only in aggregate to improve the experience.'],
             'tr' => ['t' => 'Çerezler', 'b' => 'Sitemiz işlevsellik için temel çerezler kullanır (dil ve tema tercihi gibi).']],
            ['fa' => ['t' => 'حقوق شما', 'b' => 'شما حق دارید به اطلاعات خود دسترسی داشته باشید، آن‌ها را اصلاح کنید یا حذف حساب خود را درخواست دهید. برای هر درخواست مرتبط با حریم خصوصی با support@servernet.cloud تماس بگیرید.'],
             'en' => ['t' => 'Your rights', 'b' => 'You have the right to access, correct or request deletion of your data. For any privacy-related request, contact support@servernet.cloud.'],
             'tr' => ['t' => 'Haklarınız', 'b' => 'Verilerinize erişme, düzeltme veya silinmesini isteme hakkınız vardır. support@servernet.cloud ile iletişime geçin.']],
            /*
            | ممیزی ۴ (حقوقی/امنیت): AUP «تعهد نگهداری سوابق» را پذیرفته بود ولی
            | حریم خصوصی هم‌زمان به‌روز نشده بود — «دو سند منتشرشده دربارهٔ دادهٔ
            | مشتری حرف‌های ناهماهنگ می‌زدند». این بخش دو سند را هم‌راستا می‌کند
            | و مدت نگهداری را — که ممیزی گفت اعلام نشده — اعلام می‌کند.
            */
            ['fa' => ['t' => 'سوابق قانونی و مدت نگهداری', 'b' => 'مطابق سیاست استفاده‌ی پذیرفته (AUP)، سوابق هویت مشتری، نوع سرویس و تخصیص نشانی‌های IP برای پاسخ‌گویی به مراجع ذی‌صلاح نگهداری می‌شوند؛ این سوابق فقط بسته می‌شوند و در طول دوره‌ی الزام قانونی حذف نمی‌شوند. لاگ‌های فنی شبکه و دسترسی حداکثر ۹۰ روز نگهداری و سپس حذف یا ناشناس می‌شوند، مگر آنکه الزام قانونی یا رسیدگی به یک رخداد مشخص، نگهداری طولانی‌تر همان مورد را ایجاب کند. (نسخه‌ی پیش‌نویس؛ متن نهایی پس از تأیید مشاور حقوقی.)'],
             'en' => ['t' => 'Legal records & retention', 'b' => 'In line with our Acceptable Use Policy (AUP), customer identity records, service type and IP-allocation history are retained to answer competent authorities; such records are closed, not deleted, for the legally required period. Technical network and access logs are kept for at most 90 days and then deleted or anonymised, unless a legal obligation or a specific incident requires keeping that particular record longer. (Working draft; final text after legal review.)'],
             'tr' => ['t' => 'Yasal kayıtlar ve saklama', 'b' => 'Kabul Edilebilir Kullanım Politikası (AUP) uyarınca müşteri kimlik kayıtları, hizmet türü ve IP tahsis geçmişi yetkili makamlara yanıt için saklanır; bu kayıtlar yasal süre boyunca silinmez, yalnızca kapatılır. Teknik ağ ve erişim logları en fazla 90 gün tutulur, sonra silinir veya anonimleştirilir. (Çalışma taslağı; nihai metin hukuki incelemeden sonra.)']],
        ],
    ],

    'terms' => [
        'icon' => 'book',
        'fa' => ['tag' => 'قوانین و مقررات', 'h1a' => 'قوانین', 'h1b' => 'استفاده از خدمات.',
            'lead' => 'با استفاده از خدمات سرورنت، این شرایط را می‌پذیرید. لطفاً پیش از خرید مطالعه کنید. آخرین به‌روزرسانی: تیر ۱۴۰۵.'],
        'en' => ['tag' => 'Terms of Service', 'h1a' => 'Terms of', 'h1b' => 'service.',
            'lead' => 'By using ServerNet\'s services you accept these terms. Please read them before ordering. Last updated: July 2026.'],
        'tr' => ['tag' => 'Hizmet Şartları', 'h1a' => 'Hizmet', 'h1b' => 'şartları.',
            'lead' => 'ServerNet hizmetlerini kullanarak bu şartları kabul edersiniz. Sipariş öncesi okuyun. Son güncelleme: Temmuz 2026.'],
        'sections' => [
            ['fa' => ['t' => 'پذیرش شرایط', 'b' => 'استفاده از هر یک از سرویس‌های سرورنت به‌منزله پذیرش کامل این قوانین است. اگر با بخشی از این شرایط موافق نیستید، لطفاً از خدمات استفاده نکنید.'],
             'en' => ['t' => 'Acceptance of terms', 'b' => 'Using any ServerNet service constitutes full acceptance of these terms. If you disagree with any part, please do not use the services.'],
             'tr' => ['t' => 'Şartların kabulü', 'b' => 'Herhangi bir ServerNet hizmetini kullanmak bu şartların tam kabulü anlamına gelir.']],
            ['fa' => ['t' => 'استفاده مجاز', 'b' => 'استفاده از سرویس‌ها برای فعالیت‌های غیرقانونی، ارسال اسپم، میزبانی بدافزار، نقض حق نشر یا هرگونه فعالیتی که به دیگران یا زیرساخت ما آسیب بزند، ممنوع است و منجر به تعلیق سرویس می‌شود.'],
             'en' => ['t' => 'Acceptable use', 'b' => 'Using the services for illegal activity, sending spam, hosting malware, copyright infringement, or anything that harms others or our infrastructure is prohibited and will result in suspension.'],
             'tr' => ['t' => 'Kabul edilebilir kullanım', 'b' => 'Hizmetlerin yasa dışı faaliyet, spam, zararlı yazılım barındırma veya telif ihlali için kullanımı yasaktır.']],
            ['fa' => ['t' => 'پرداخت و تمدید', 'b' => 'سرویس‌ها به‌صورت پیش‌پرداخت ارائه می‌شوند. عدم تمدید پیش از سررسید ممکن است به تعلیق و سپس حذف سرویس منجر شود. مسئولیت تمدید به‌موقع بر عهده مشتری است، هرچند ما یادآوری ارسال می‌کنیم.'],
             'en' => ['t' => 'Payment & renewal', 'b' => 'Services are prepaid. Failure to renew before the due date may lead to suspension and then termination. Timely renewal is the customer\'s responsibility, though we do send reminders.'],
             'tr' => ['t' => 'Ödeme ve yenileme', 'b' => 'Hizmetler ön ödemelidir. Zamanında yenilememe askıya alma ve sonlandırmaya yol açabilir.']],
            ['fa' => ['t' => 'ضمانت بازگشت وجه', 'b' => 'برای سرویس‌های مشمول، ضمانت بازگشت وجه ۱۴ روزه ارائه می‌شود. شمول، مستثنیات و رویه‌ی دقیق آن در بند «ضمانت ۱۴ روزه‌ی بازگشت وجه — شمول و استثنا» همین صفحه آمده و همان بند ملاک است.'],
             'en' => ['t' => 'Money-back guarantee', 'b' => 'Eligible services include a 14-day money-back guarantee. The exact scope, exclusions and procedure are set out in the "14-day money-back guarantee — scope and exclusions" clause on this page, which prevails.'],
             'tr' => ['t' => 'Para iade garantisi', 'b' => 'Kapsam dahilindeki hizmetler 14 gün para iade garantisi içerir. Kapsam, istisnalar ve usul bu sayfadaki "14 gün para iade garantisi — kapsam ve istisnalar" maddesinde yer alır ve o madde esastır.']],
            ['fa' => ['t' => 'آپتایم و مسئولیت', 'b' => 'ما آپتایم ۹۹.۹٪ را طبق توافق‌نامه سطح خدمات (SLA) تضمین می‌کنیم. مسئولیت ما محدود به جبران طبق SLA است و شامل خسارات غیرمستقیم یا از دست رفتن سود نمی‌شود. مشتری مسئول تهیه بکاپ مستقل از داده‌های حیاتی خود است.'],
             'en' => ['t' => 'Uptime & liability', 'b' => 'We guarantee 99.9% uptime under our SLA. Our liability is limited to SLA compensation and excludes indirect damages or lost profit. Customers are responsible for keeping independent backups of critical data.'],
             'tr' => ['t' => 'Çalışma süresi ve sorumluluk', 'b' => 'SLA kapsamında %99,9 çalışma süresi garanti ederiz. Sorumluluğumuz SLA tazminatıyla sınırlıdır.']],
            ['fa' => ['t' => 'حساب کاربری و امنیت', 'b' => 'حفظ محرمانگی اطلاعات ورود به حساب و ناحیه کاربری بر عهده مشتری است. مسئولیت همه فعالیت‌هایی که از طریق حساب شما انجام می‌شود با شماست. در صورت مشاهده هرگونه دسترسی غیرمجاز، بلافاصله ما را مطلع کنید.'],
             'en' => ['t' => 'Account & security', 'b' => 'Keeping your account and client-area credentials confidential is the customer\'s responsibility. You are responsible for all activity performed through your account. Notify us immediately of any unauthorized access.'],
             'tr' => ['t' => 'Hesap ve güvenlik', 'b' => 'Hesap ve müşteri paneli bilgilerinizin gizliliği müşterinin sorumluluğundadır. Hesabınız üzerinden yapılan tüm faaliyetlerden siz sorumlusunuz.']],
            ['fa' => ['t' => 'حفاظت از داده و بکاپ', 'b' => 'ما با استانداردهای امنیتی و بکاپ منظم از زیرساخت محافظت می‌کنیم، اما مسئولیت نهایی تهیه نسخه پشتیبان مستقل از داده‌های حیاتی بر عهده مشتری است. داده‌های شما تنها برای ارائه سرویس پردازش می‌شوند و به شکل مطابق با سیاست حریم خصوصی ما نگهداری می‌شوند.'],
             'en' => ['t' => 'Data protection & backups', 'b' => 'We protect the infrastructure with security standards and regular backups, but ultimate responsibility for keeping independent backups of critical data rests with the customer. Your data is processed only to provide the service and handled per our Privacy Policy.'],
             'tr' => ['t' => 'Veri koruma ve yedekleme', 'b' => 'Altyapıyı güvenlik standartları ve düzenli yedeklerle koruruz, ancak kritik verilerin bağımsız yedeğini tutma nihai sorumluluğu müşteridedir.']],
            ['fa' => ['t' => 'تعلیق و فسخ سرویس', 'b' => 'در صورت نقض این قوانین، عدم پرداخت، یا سوءاستفاده‌ای که به زیرساخت یا سایر مشتریان آسیب بزند، حق داریم سرویس را تعلیق یا فسخ کنیم. در موارد نقض شدید (مانند حملات یا فعالیت مجرمانه) تعلیق می‌تواند بدون اطلاع قبلی و فوری باشد.'],
             'en' => ['t' => 'Suspension & termination', 'b' => 'We may suspend or terminate a service for breach of these terms, non-payment, or abuse that harms our infrastructure or other customers. For severe violations (e.g. attacks or criminal activity) suspension may be immediate and without prior notice.'],
             'tr' => ['t' => 'Askıya alma ve sonlandırma', 'b' => 'Bu şartların ihlali, ödememe veya altyapıya zarar veren kötüye kullanım durumunda hizmeti askıya alabilir veya sonlandırabiliriz. Ağır ihlallerde askıya alma önceden bildirimsiz olabilir.']],
            ['fa' => ['t' => 'مالکیت محتوا', 'b' => 'محتوایی که روی سرویس‌ها بارگذاری می‌کنید متعلق به خودتان است و ما ادعایی نسبت به آن نداریم. در مقابل، مسئولیت قانونی محتوا، مجوزها و رعایت حق نشر نیز کاملاً بر عهده مشتری است. برند، پنل‌ها و ابزارهای سرورنت متعلق به ما هستند.'],
             'en' => ['t' => 'Content ownership', 'b' => 'Content you upload to the services remains yours; we claim no ownership of it. In turn, legal responsibility for that content, its licenses and copyright compliance rests entirely with the customer. The ServerNet brand, panels and tools remain ours.'],
             'tr' => ['t' => 'İçerik sahipliği', 'b' => 'Hizmetlere yüklediğiniz içerik size aittir; üzerinde hak iddia etmeyiz. Buna karşılık içeriğin yasal sorumluluğu tamamen müşteridedir. ServerNet markası, panelleri ve araçları bize aittir.']],
            ['fa' => ['t' => 'رسیدگی به تخلف و شکایت', 'b' => 'گزارش‌های تخلف (اسپم، بدافزار، نقض حق نشر) را جدی می‌گیریم و بررسی می‌کنیم. برای گزارش موارد نقض یا محتوای متخلف می‌توانید با تیم ما تماس بگیرید؛ ما طبق قوانین و در چارچوب معقول به آن‌ها رسیدگی می‌کنیم.'],
             'en' => ['t' => 'Abuse & complaints', 'b' => 'We take abuse reports (spam, malware, copyright infringement) seriously and investigate them. To report violations or infringing content, contact our team; we handle reports lawfully and within a reasonable timeframe.'],
             'tr' => ['t' => 'Kötüye kullanım ve şikayetler', 'b' => 'Kötüye kullanım bildirimlerini (spam, zararlı yazılım, telif ihlali) ciddiye alır ve inceleriz. İhlalleri bildirmek için ekibimizle iletişime geçin.']],
            ['fa' => ['t' => 'فورس ماژور', 'b' => 'سرورنت در قبال قطعی یا تأخیر ناشی از رویدادهای خارج از کنترل معقول (بلایای طبیعی، قطع سراسری برق یا اینترنت، تصمیمات حاکمیتی، حملات گسترده) مسئولیتی ندارد، هرچند تمام تلاش خود را برای بازگردانی سریع سرویس به کار می‌گیرد.'],
             'en' => ['t' => 'Force majeure', 'b' => 'ServerNet is not liable for outages or delays caused by events beyond our reasonable control (natural disasters, nationwide power or internet outages, government actions, large-scale attacks), though we make every effort to restore service quickly.'],
             'tr' => ['t' => 'Mücbir sebep', 'b' => 'ServerNet, makul kontrolü dışındaki olaylardan (doğal afetler, ülke çapında kesintiler, devlet kararları, büyük ölçekli saldırılar) kaynaklanan kesintilerden sorumlu değildir.']],
            ['fa' => ['t' => 'قانون حاکم', 'b' => 'این شرایط تابع قوانین جاری کشور محل ثبت شرکت است و هرگونه اختلاف ابتدا از طریق مذاکره و در صورت لزوم مراجع صالح قانونی حل‌وفصل می‌شود.'],
             'en' => ['t' => 'Governing law', 'b' => 'These terms are governed by the applicable laws of the company\'s jurisdiction. Any dispute is resolved first through negotiation and, if necessary, the competent legal authorities.'],
             'tr' => ['t' => 'Geçerli hukuk', 'b' => 'Bu şartlar şirketin bulunduğu yargı bölgesinin geçerli yasalarına tabidir. Anlaşmazlıklar önce müzakere yoluyla çözülür.']],
            ['fa' => ['t' => 'تغییرات', 'b' => 'ممکن است این شرایط را به‌روزرسانی کنیم. تغییرات مهم از طریق ایمیل یا اعلان در ناحیه کاربری اطلاع‌رسانی می‌شود. ادامه استفاده پس از تغییر، به‌منزله پذیرش شرایط جدید است.'],
             'en' => ['t' => 'Changes', 'b' => 'We may update these terms. Material changes are announced by email or a notice in the client area. Continued use after a change means acceptance of the new terms.'],
             'tr' => ['t' => 'Değişiklikler', 'b' => 'Bu şartları güncelleyebiliriz. Önemli değişiklikler e-posta ile bildirilir.']],
            // ارجاعِ متقابل به AUP — ممیزی ۴ (حقوقی): AUP باید از شرایط استفاده
            // و SLA ارجاع بگیرد تا مبنای قراردادیِ تعلیق باشد، نه سندی جزیره‌ای.
            ['fa' => ['t' => 'سیاست استفاده‌ی پذیرفته (AUP)', 'b' => 'سیاست استفاده‌ی پذیرفته — از جمله منع ارائه و بازفروش سرویس عبور (VPN/پروکسی) روی زیرساخت داخل ایران — جزء جدایی‌ناپذیر همین شرایط است و در نشانی servernet.cloud/aup منتشر شده. نقض آن سیاست، نقض همین قرارداد محسوب می‌شود.'],
             'en' => ['t' => 'Acceptable Use Policy (AUP)', 'b' => 'The Acceptable Use Policy — including the ban on providing or reselling transit services (VPN/proxy) on infrastructure located in Iran — is an integral part of these terms and is published at servernet.cloud/aup. Violating that policy is a violation of this agreement.'],
             'tr' => ['t' => 'Kabul Edilebilir Kullanım Politikası (AUP)', 'b' => 'AUP — İran\'daki altyapıda geçiş hizmeti (VPN/proxy) sunma ve satma yasağı dahil — bu şartların ayrılmaz parçasıdır ve servernet.cloud/aup adresinde yayımlanmıştır. Politikanın ihlali bu sözleşmenin ihlalidir.']],
            /*
            | بندِ بازگشتِ وجه — ممیزی ۶ (حقوقی): ضمانتِ ۱۴روزه روی ۶۴ صفحهٔ عمومی
            | اعلام می‌شد ولی فقط یک جملهٔ کلی در ToS داشت؛ یعنی همان وعده به
            | دامنه/SSL/لایسنس/سرورِ اختصاصی هم تعمیم یافته بود که عملاً
            | غیرقابلِ بازگشت‌اند. شمول و مستثنیات حالا صریح است و صفحهٔ سفارش
            | فقط روی دسته‌های مشمول ضمانت را نشان می‌دهد (Product::isRefundable).
            | پیش‌نویسِ کاری — پیش از استنادِ قراردادی وکیل ببیند.
            */
            /*
            | بندِ قیمت/مالیات/لوکیشن — ممیزی ۷ (حقوقی): سه مواجهه‌ی پوشش‌نداده
            | برای صفحاتِ لوکیشنِ خارجی: (۱) بازفروشِ زیرساختِ اپراتورِ ثالث و
            | انتقالِ AUP بالادستی به مشتری، (۲) انتقالِ داده به خارج، (۳) قیمتِ
            | نمایشی/فاکتورِ رسمی. پیش‌نویسِ کاری — پیش از استناد، وکیل ببیند.
            */
            ['fa' => ['t' => 'قیمت، مالیات و لوکیشن خدمات', 'b' => 'قیمت‌های نمایش‌داده‌شده در صفحات سایت جنبه‌ی اطلاع‌رسانی دارد و مبلغ قطعی هر سفارش، همراه مالیات و عوارض قانونی مطابق نرخ مصوب روز، در صفحه‌ی پرداخت محاسبه و در فاکتور رسمی درج می‌شود. خدمات ارائه‌شده در لوکیشن‌های خارج از ایران از طریق اپراتورهای زیرساخت ثالث تأمین می‌شود؛ مشتری می‌پذیرد که شرایط استفاده و سیاست مقابله با سوءاستفاده‌ی آن اپراتور و قوانین کشور محل استقرار داده بر سرویس او نیز حاکم است و نقض آن‌ها، نقض همین قرارداد محسوب می‌شود. مشتری آگاه است که با انتخاب لوکیشن خارجی، داده‌های او خارج از ایران نگهداری می‌شود و رعایت الزامات قانونی مرتبط با نوع داده و فعالیت خودش (از جمله داده‌های مشتریان او) بر عهده‌ی اوست. (پیش‌نویس؛ متن نهایی پس از تأیید مشاور حقوقی.)'],
             'en' => ['t' => 'Pricing, tax and service locations', 'b' => 'Prices shown on the website are informational; the final amount of each order, including applicable taxes and statutory charges at the current official rate, is calculated at checkout and stated on the official invoice. Services in locations outside Iran are provided through third-party infrastructure operators; the customer accepts that the operator\'s terms of use and abuse policy and the laws of the country where the data resides also govern the service, and violating them is a violation of this agreement. By choosing a foreign location the customer acknowledges that their data is stored outside Iran and that complying with the legal requirements applicable to their own data and activity (including their customers\' data) is their responsibility. (Working draft; final text after legal review.)'],
             'tr' => ['t' => 'Fiyat, vergi ve hizmet konumları', 'b' => 'Sitede gösterilen fiyatlar bilgilendirme amaçlıdır; her siparişin kesin tutarı, geçerli resmi orana göre vergi ve yasal kesintilerle birlikte ödeme sayfasında hesaplanır ve resmi faturada yer alır. İran dışındaki konumlarda hizmetler üçüncü taraf altyapı operatörleri üzerinden sağlanır; müşteri, operatörün kullanım şartlarının ve kötüye kullanım politikasının ve verinin bulunduğu ülkenin yasalarının da hizmeti için geçerli olduğunu kabul eder. Yabancı konum seçen müşteri, verilerinin İran dışında tutulduğunu ve kendi verileri ve faaliyetiyle ilgili yasal yükümlülüklere uymanın kendi sorumluluğunda olduğunu kabul eder. (Taslak; nihai metin hukuki incelemeden sonra.)']],
            ['fa' => ['t' => 'ضمانت ۱۴ روزه‌ی بازگشت وجه — شمول و استثنا', 'b' => 'شمول: هاست لینوکس و وردپرس، نمایندگی هاست، سرور مجازی و ابری و پنل‌های میزبانی — تا ۱۴ روز از لحظه‌ی تحویل و فقط برای دوره‌ی نخست خرید (نه تمدید). مستثنیات صریح: ثبت، تمدید و انتقال دامنه؛ گواهی SSL پس از صدور؛ لایسنس نرم‌افزار پس از تحویل کلید؛ سرور اختصاصی و سخت‌افزار سفارشی؛ IP اضافه؛ هزینه‌ی راه‌اندازی؛ خدمات پروژه‌ای پس از شروع کار. در سرویس‌های ساعتی و ابری، بهای منابع تخصیص‌یافته و ترافیک مصرف‌شده کسر می‌شود. تعلیق یا فسخ به دلیل نقض AUP، حق بازگشت وجه را ساقط می‌کند. حداکثر یک بار برای هر مشتری و هر نوع سرویس. رویه: ثبت درخواست از پنل یا تیکت؛ رسیدگی حداکثر ۷ روز کاری؛ واریز به همان روش پرداخت حداکثر ۱۴ روز کاری. این ضمانت مانع اعمال حق انصراف قانونی موضوع قانون تجارت الکترونیکی نیست. (پیش‌نویس؛ متن نهایی پس از تأیید مشاور حقوقی.)'],
             'en' => ['t' => '14-day money-back guarantee — scope and exclusions', 'b' => 'Covered: Linux and WordPress hosting, reseller hosting, virtual/cloud servers and hosting panels — within 14 days of delivery and only for the first billing period (not renewals). Explicitly excluded: domain registration, renewal and transfer; SSL certificates once issued; software licences once the key is delivered; dedicated servers and custom hardware; additional IPs; setup fees; project services once work has started. For hourly/cloud services, the cost of allocated resources and consumed traffic is deducted. Suspension or termination for AUP violation voids the guarantee. At most once per customer per service type. Procedure: request via the panel or a ticket; handled within 7 business days; refund to the original payment method within 14 business days. This guarantee does not limit any statutory right of withdrawal. (Working draft; final text after legal review.)'],
             'tr' => ['t' => '14 gün para iade garantisi — kapsam ve istisnalar', 'b' => 'Kapsam: Linux ve WordPress hosting, bayi hosting, sanal/bulut sunucular ve hosting panelleri — teslimattan itibaren 14 gün ve yalnızca ilk dönem için (yenilemeler hariç). Açık istisnalar: alan adı kaydı/yenileme/transfer; düzenlenmiş SSL; anahtarı teslim edilmiş lisanslar; fiziksel sunucu ve özel donanım; ek IP; kurulum ücreti; başlamış proje hizmetleri. Saatlik/bulut hizmetlerde tahsis edilen kaynak ve tüketilen trafik düşülür. AUP ihlali nedeniyle askıya alma garantiyi düşürür. Müşteri ve hizmet türü başına en fazla bir kez. Süreç: panel veya bilet; 7 iş günü içinde inceleme; 14 iş günü içinde aynı yöntemle iade. (Taslak; nihai metin hukuki incelemeden sonra.)']],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | AUP — سیاست استفادهٔ پذیرفته (ممیزی ۳، بند ۷ پیشنهادی مدیر حقوقی)
    |----------------------------------------------------------------------
    | 🔴 چرا فوری بود: رژیم ردیابی هویتِ خریدارانِ سرور، اتصال بین‌الملل را
    | مشروط به انطباق کرده و صریحاً سرورهای مجریِ VPN را هدف گرفته است. بدون
    | سند عمومیِ ضدبازفروشِ VPN، در یک بررسی نظارتی بار اثبات روی شرکت است و
    | دستش خالی — به‌خصوص با کانال ۱۰۶هزارنفریِ هم‌نامِ توزیع‌کنندهٔ کانفیگ.
    |
    | ⚠️ پیش‌نویس کاری است؛ باید پیش از استناد قراردادی توسط وکیل نهایی شود
    | (خودِ صفحه هم همین را صریح می‌گوید). برای تبدیل به بند قرارداد، همین متن
    | باید به legal_documents پنل مشتری هم اضافه شود.
    */
    'aup' => [
        'icon' => 'shield',
        'fa' => ['tag' => 'سیاست استفادهٔ پذیرفته', 'h1a' => 'قواعد استفاده از', 'h1b' => 'زیرساخت سرورنت.',
            'lead' => 'این سند مشخص می‌کند چه استفاده‌هایی روی زیرساخت سرورنت مجاز نیست — از جمله ممنوعیت صریح ارائه و بازفروش سرویس عبور (VPN/پروکسی) روی زیرساخت داخل ایران. نسخهٔ پیش‌نویس، مرداد ۱۴۰۵؛ متن نهایی پس از تأیید مشاور حقوقی جایگزین می‌شود.'],
        'en' => ['tag' => 'Acceptable Use Policy', 'h1a' => 'Rules for using', 'h1b' => 'ServerNet infrastructure.',
            'lead' => 'This document defines what is not permitted on ServerNet infrastructure — including an explicit ban on providing or reselling transit services (VPN/proxy) on infrastructure located in Iran. Working draft, August 2026; the final text will replace this after legal review.'],
        'tr' => ['tag' => 'Kabul Edilebilir Kullanım Politikası', 'h1a' => 'ServerNet altyapısını', 'h1b' => 'kullanma kuralları.',
            'lead' => 'Bu belge ServerNet altyapısında nelere izin verilmediğini tanımlar — İran\'daki altyapı üzerinde geçiş hizmeti (VPN/proxy) sunma ve yeniden satmanın açık yasağı dahil. Çalışma taslağı, Ağustos 2026; hukuki incelemeden sonra nihai metin yayınlanacaktır.'],
        'sections' => [
            ['fa' => ['t' => 'تعاریف', 'b' => '«زیرساخت داخل ایران» یعنی هر سرور اختصاصی، سرور مجازی، منبع ابری یا نشانی آی‌پی که سرورنت در دیتاسنترهای مستقر در ایران در اختیار مشتری قرار می‌دهد. «سرویس عبور» یعنی هر سامانهٔ VPN، پروکسی، تانل یا شبکهٔ خصوصی مجازی که برای دسترسی اشخاص ثالث به شبکهٔ بین‌الملل به کار رود.'],
             'en' => ['t' => 'Definitions', 'b' => '"Infrastructure in Iran" means any dedicated server, virtual server, cloud resource or IP address that ServerNet provides to the customer in data centres located in Iran. "Transit service" means any VPN, proxy, tunnel or virtual private network used to give third parties access to the international network.'],
             'tr' => ['t' => 'Tanımlar', 'b' => '"İran\'daki altyapı", ServerNet\'in İran\'daki veri merkezlerinde müşteriye sağladığı her türlü fiziksel sunucu, sanal sunucu, bulut kaynağı veya IP adresi anlamına gelir. "Geçiş hizmeti", üçüncü kişilere uluslararası ağa erişim sağlamak için kullanılan her türlü VPN, proxy veya tünel anlamına gelir.']],
            ['fa' => ['t' => 'ممنوعیت ارائه و بازفروش سرویس عبور', 'b' => 'مشتری مجاز نیست روی زیرساخت داخل ایران، سرویس عبور را به اشخاص ثالث ارائه، اجاره، بازفروش، اشتراک‌گذاری یا توزیع کند؛ اعم از فروش اشتراک، توزیع کانفیگ، عرضهٔ پنل کاربری یا واگذاری دسترسی — چه با دریافت وجه و چه رایگان.'],
             'en' => ['t' => 'Ban on providing or reselling transit services', 'b' => 'The customer may not provide, rent, resell, share or distribute transit services to third parties on infrastructure in Iran — whether by selling subscriptions, distributing configs, offering a user panel or handing over access, for payment or free of charge.'],
             'tr' => ['t' => 'Geçiş hizmeti sunma ve yeniden satma yasağı', 'b' => 'Müşteri, İran\'daki altyapı üzerinde üçüncü kişilere geçiş hizmeti sunamaz, kiralayamaz, yeniden satamaz, paylaşamaz veya dağıtamaz — abonelik satışı, config dağıtımı, kullanıcı paneli sunumu veya erişim devri dahil, ücretli ya da ücretsiz.']],
            ['fa' => ['t' => 'استثنای اتصال سازمانی', 'b' => 'استفاده از VPN صرفاً برای اتصال امن کارکنان، شعب یا سامانه‌های خودِ مشتری به منابع خودش مجاز است، مشروط به اعلام کتبی پیشین و ثبت در پروندهٔ مشتری.'],
             'en' => ['t' => 'Corporate connectivity exception', 'b' => 'Using a VPN solely to securely connect the customer\'s own staff, branches or systems to the customer\'s own resources is permitted, subject to prior written notice recorded in the customer file.'],
             'tr' => ['t' => 'Kurumsal bağlantı istisnası', 'b' => 'VPN\'in yalnızca müşterinin kendi personelini, şubelerini veya sistemlerini kendi kaynaklarına güvenle bağlamak için kullanılması, önceden yazılı bildirim ve müşteri dosyasına kayıt şartıyla serbesttir.']],
            ['fa' => ['t' => 'تعهد اطلاعاتی و سوابق', 'b' => 'مشتری متعهد است اطلاعات هویتی خود را صحیح و به‌روز نگه دارد. سرورنت مجاز است سوابق هویت، نوع سرویس و تخصیص آی‌پی را مطابق الزامات مراجع ذی‌صلاح نگهداری و در صورت درخواست قانونی ارائه کند. این سوابق فقط بسته می‌شوند و هرگز حذف نمی‌شوند.'],
             'en' => ['t' => 'Information obligations & records', 'b' => 'The customer must keep their identity information accurate and up to date. ServerNet may retain records of identity, service type and IP allocation as required by the competent authorities, and provide them upon lawful request. Such records are closed, never deleted.'],
             'tr' => ['t' => 'Bilgi yükümlülüğü ve kayıtlar', 'b' => 'Müşteri kimlik bilgilerini doğru ve güncel tutmakla yükümlüdür. ServerNet; kimlik, hizmet türü ve IP tahsis kayıtlarını yetkili makamların gerektirdiği şekilde saklayabilir ve yasal talep üzerine sunabilir.']],
            ['fa' => ['t' => 'ضمانت اجرا', 'b' => 'در صورت تخلف، سرورنت مجاز است سرویس را بدون اطلاع قبلی تعلیق کند. تکرار تخلف موجب فسخ بدون بازگشت وجه است و مشتری مسئول جبران خسارات وارده به سرورنت — از جمله محدودیت اتصال بین‌الملل مجموعه — خواهد بود.'],
             'en' => ['t' => 'Enforcement', 'b' => 'In case of violation, ServerNet may suspend the service without prior notice. Repeated violation leads to termination without refund, and the customer is liable for damages caused to ServerNet — including any restriction of the company\'s international connectivity.'],
             'tr' => ['t' => 'Yaptırım', 'b' => 'İhlal durumunda ServerNet hizmeti önceden bildirimde bulunmadan askıya alabilir. Tekrarlanan ihlal, iade olmaksızın fesihle sonuçlanır ve müşteri ServerNet\'e verilen zararlardan sorumludur.']],
            /*
            | ممیزی ۴ (امنیت): «سیاستی که راهِ گزارش ندارد اجرا نمی‌شود.» کانالِ
            | اختصاصی + تعهدِ زمانِ پاسخِ اعلام‌شده جایگزینِ support@ شد؛ فرمِ
            | /abuse هم بدونِ نیاز به ایمیل کار می‌کند.
            */
            ['fa' => ['t' => 'گزارش تخلف', 'b' => 'اگر از استفادهٔ متخلفانه از زیرساخت سرورنت مطلع شدید (توزیع کانفیگ، فروش اشتراک عبور، اسپم یا بدافزار)، از فرم servernet.cloud/abuse یا نشانی abuse@servernet.cloud گزارش دهید. گزارش‌ها محرمانه بررسی می‌شوند؛ تأیید دریافت خودکار است و رسیدگی حداکثر تا ۲ روز کاری آغاز می‌شود. برای فیشینگ و بدافزار فعال، هدف ما شروع رسیدگی در ۴ ساعت کاری است.'],
             'en' => ['t' => 'Reporting violations', 'b' => 'If you become aware of abusive use of ServerNet infrastructure (config distribution, selling transit subscriptions, spam or malware), report it via servernet.cloud/abuse or abuse@servernet.cloud. Reports are handled confidentially; acknowledgement is automatic and handling starts within 2 business days. For active phishing or malware our target is to start within 4 business hours.'],
             'tr' => ['t' => 'İhlal bildirimi', 'b' => 'ServerNet altyapısının kötüye kullanımını fark ederseniz (config dağıtımı, geçiş aboneliği satışı, spam veya zararlı yazılım) servernet.cloud/abuse formundan veya abuse@servernet.cloud adresinden bildirin. Bildirimler gizli tutulur; alındı bilgisi otomatiktir ve inceleme en geç 2 iş günü içinde başlar. Aktif phishing veya zararlı yazılım için hedefimiz 4 iş saati içinde başlamaktır.']],
            ['fa' => ['t' => 'وضعیت این سند', 'b' => 'این متن پیش‌نویس کاری است و پیش از استناد قراردادی توسط مشاور حقوقی بازبینی و نهایی می‌شود. نسخهٔ نهایی به‌عنوان پیوست قرارداد سرویس در پنل مشتری نیز ارائه خواهد شد.'],
             'en' => ['t' => 'Status of this document', 'b' => 'This text is a working draft and will be reviewed and finalised by legal counsel before contractual reliance. The final version will also be provided as a service-contract annex in the customer panel.'],
             'tr' => ['t' => 'Bu belgenin durumu', 'b' => 'Bu metin bir çalışma taslağıdır ve sözleşmesel dayanak olmadan önce hukuk danışmanı tarafından incelenip kesinleştirilecektir.']],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | /speed — گزارش سرعت با متدولوژی (ممیزی ۴، مارکتینگ)
    |----------------------------------------------------------------------
    | «یک متدولوژی، نه یک عدد — تنها نوع ادعایی که رقیب بدونِ انجامِ کار
    | نمی‌تواند کپی کند. صادقانه منتشر کردنِ عددِ بد، عددِ خوب را باورپذیر
    | می‌کند.» بدترین عدد (۵۰۹ms) عمداً منتشر می‌شود. پیش‌شرطِ مارکتینگ هم
    | رعایت شده: ادعای ۱۲٬۴۰۰ req/sِ بی‌روش هم‌زمان از سایت حذف شد.
    |
    | ⚠️ با هر دورِ اندازه‌گیریِ تازه، بخشِ «نتایج» همین‌جا به‌روز و تاریخ در
    | lead عوض می‌شود — صفحهٔ کهنه‌مانده اعتبارِ کلِ روش را می‌سوزاند.
    */
    'speed' => [
        'icon' => 'gauge',
        'fa' => ['tag' => 'گزارش سرعت', 'h1a' => 'سرعتی که اندازه گرفته‌ایم،', 'h1b' => 'با روشی که می‌توانید تکرارش کنید.',
            'lead' => 'به‌جای عدد تبلیغاتی، اندازه‌گیری واقعی روی سایت زنده منتشر می‌کنیم — با روش کامل، همه‌ی اعداد از جمله بدترینشان، و تعهد به تکرار. آخرین اندازه‌گیری: ۲۷ مرداد ۱۴۰۵.'],
        'en' => ['tag' => 'Speed report', 'h1a' => 'Speed we measured,', 'h1b' => 'with a method you can reproduce.',
            'lead' => 'Instead of a marketing number we publish real measurements of the live site — full method, every number including the worst one, and a commitment to repeat. Last measured: 18 August 2026.'],
        'tr' => ['tag' => 'Hız raporu', 'h1a' => 'Ölçtüğümüz hız,', 'h1b' => 'tekrarlayabileceğiniz bir yöntemle.',
            'lead' => 'Pazarlama rakamı yerine canlı sitenin gerçek ölçümlerini yayımlıyoruz — tam yöntem, en kötüsü dahil tüm sayılar ve tekrar taahhüdü. Son ölçüm: 18 Ağustos 2026.'],
        'sections' => [
            ['fa' => ['t' => 'چه چیزی اندازه می‌گیریم', 'b' => 'زمان تا اولین بایت (TTFB) صفحات عمومی سایت از دید یک بازدیدکننده‌ی ناشناس — یعنی همان چیزی که کاربر واقعی و خزنده‌ی گوگل تجربه می‌کنند. TTFB را اندازه می‌گیریم چون برخلاف «درخواست در ثانیه»، از بیرون و توسط هر کسی قابل راستی‌آزمایی است.'],
             'en' => ['t' => 'What we measure', 'b' => 'Time-to-first-byte (TTFB) of the public pages as an anonymous visitor sees them — the same thing a real user and Google\'s crawler experience. We measure TTFB because, unlike "requests per second", anyone can verify it from outside.'],
             'tr' => ['t' => 'Neyi ölçüyoruz', 'b' => 'Herkese açık sayfaların anonim ziyaretçi gözünden ilk bayta kadar süresi (TTFB) — gerçek kullanıcının ve Google tarayıcısının yaşadığı şey. TTFB\'yi ölçüyoruz çünkü dışarıdan herkes doğrulayabilir.']],
            ['fa' => ['t' => 'روش دقیق — تکرارش کنید', 'b' => 'ابزار: curl از یک کلاینت خارج از شبکه‌ی سرور. هر صفحه دو حالت: (۱) بدون کوکی و بدون رشته‌ی کوئری، که ممکن است از کش پاسخ بگیرد (هدر X-Cache: HIT)؛ (۲) با یک پارامتر کوئری یکتا (cache-buster) که کش را دور می‌زند (X-Cache: BYPASS) و هزینه‌ی کامل رندر را نشان می‌دهد. هدر X-Cache روی همه‌ی پاسخ‌های صفحات عمومی سرورنت همیشه حاضر است تا هر کسی بتواند همین تفکیک را ببیند. دستور نمونه: curl -s -o /dev/null -w "%{time_starttransfer}" آدرس‌صفحه'],
             'en' => ['t' => 'Exact method — reproduce it', 'b' => 'Tool: curl from a client outside the server\'s network. Each page in two modes: (1) no cookies and no query string, which may be served from cache (X-Cache: HIT header); (2) with a unique query parameter (cache-buster) that bypasses the cache (X-Cache: BYPASS) and shows the full render cost. The X-Cache header is always present on ServerNet\'s public pages so anyone can see the same split. Sample: curl -s -o /dev/null -w "%{time_starttransfer}" PAGE-URL'],
             'tr' => ['t' => 'Tam yöntem — tekrarlayın', 'b' => 'Araç: sunucu ağının dışından curl. Her sayfa iki modda: (1) çerezsiz ve sorgusuz — önbellekten dönebilir (X-Cache: HIT); (2) benzersiz bir sorgu parametresiyle (cache-buster) önbelleği atlar (X-Cache: BYPASS) ve tam render maliyetini gösterir. X-Cache başlığı tüm genel sayfalarda her zaman vardır.']],
            ['fa' => ['t' => 'نتایج — ۲۷ مرداد ۱۴۰۵ (همه‌ی اعداد، از جمله بدترین)', 'b' => 'صفحه‌ی کش‌شده (HIT): ۱۶۰ تا ۱۹۵ میلی‌ثانیه در سه اندازه‌گیری پیاپی. رندر کامل بدون کش (BYPASS): صفحه‌ی اصلی ۲۲۶ms، هاست وردپرس ۲۳۴ms، درباره‌ی ما ۲۱۸ms — و بدترین صفحه، سرور مجازی ایران، ۵۰۹ms که در حال بهینه‌سازی است. برای مقایسه، همین صفحات یک ماه قبل بدون کش ۴۱۴ تا ۷۵۹ms بودند.'],
             'en' => ['t' => 'Results — 18 Aug 2026 (every number, including the worst)', 'b' => 'Cached page (HIT): 160–195ms across three consecutive measurements. Full render without cache (BYPASS): homepage 226ms, WordPress hosting 234ms, About 218ms — and the worst page, Iran VPS, 509ms, which is being optimised. For comparison, a month earlier the same pages rendered in 414–759ms without cache.'],
             'tr' => ['t' => 'Sonuçlar — 18 Ağu 2026 (en kötüsü dahil)', 'b' => 'Önbellekli sayfa (HIT): art arda üç ölçümde 160–195ms. Önbelleksiz tam render (BYPASS): ana sayfa 226ms, WordPress hosting 234ms, Hakkımızda 218ms — ve en kötü sayfa İran VPS 509ms (optimizasyon sürüyor). Bir ay önce aynı sayfalar önbelleksiz 414–759ms idi.']],
            ['fa' => ['t' => 'تعهد به تکرار', 'b' => 'این اندازه‌گیری با هر دور ممیزی داخلی (حداقل فصلی) تکرار می‌شود و نتایج قبلی همین‌جا آرشیو می‌ماند. اگر عددی بدتر شد، همان را منتشر می‌کنیم — اعتبار این صفحه به کامل بودنش است، نه به خوب بودن اعداد.'],
             'en' => ['t' => 'Commitment to repeat', 'b' => 'This measurement is repeated with every internal audit round (at least quarterly) and previous results stay archived here. If a number gets worse, we publish it — this page\'s credibility comes from being complete, not from the numbers being good.'],
             'tr' => ['t' => 'Tekrar taahhüdü', 'b' => 'Bu ölçüm her iç denetim turunda (en az üç ayda bir) tekrarlanır ve önceki sonuçlar burada arşivlenir. Bir sayı kötüleşirse onu da yayımlarız.']],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | /official-channels — کانال‌های رسمی (ممیزی ۶، مارکتینگ + حقوقی)
    |----------------------------------------------------------------------
    | یک کانالِ تلگرامیِ هم‌نام با ۱۰۶ هزار عضو کانفیگِ VPN پخش می‌کند. پاسخِ
    | درست: فهرستِ دقیقِ کانال‌های رسمی + جملهٔ «هیچ کانالِ دیگری متعلق به
    | سرورنت نیست» + لینک از فوتر — و **هرگز** اشارهٔ عمومی به آن کانال
    | (تبلیغِ رایگان است). نشانی‌ها از همان config/servernet می‌آیند که فوتر.
    */
    'official-channels' => [
        'icon' => 'shield',
        'fa' => ['tag' => 'کانال‌های رسمی', 'h1a' => 'فقط این‌ها', 'h1b' => 'کانال‌های رسمی سرورنت هستند.',
            'lead' => 'هر پیام، کانال، پشتیبانی یا پیشنهادی که از نشانی‌های این فهرست نیامده باشد، از طرف سرورنت نیست. اگر مورد مشکوکی دیدید، از صفحه‌ی گزارش سوءاستفاده به ما خبر دهید.'],
        'en' => ['tag' => 'Official channels', 'h1a' => 'Only these', 'h1b' => 'are ServerNet\'s official channels.',
            'lead' => 'Any message, channel, support contact or offer that does not come from an address on this list is not from ServerNet. If you see something suspicious, tell us via the abuse report page.'],
        'tr' => ['tag' => 'Resmi kanallar', 'h1a' => 'Yalnızca bunlar', 'h1b' => 'ServerNet\'in resmi kanallarıdır.',
            'lead' => 'Bu listedeki adreslerden gelmeyen hiçbir mesaj, kanal, destek veya teklif ServerNet\'e ait değildir. Şüpheli bir durum görürseniz kötüye kullanım bildirimi sayfasından bize bildirin.'],
        'sections' => [
            ['fa' => ['t' => 'وب‌سایت و پنل', 'b' => 'سایت اصلی: servernet.cloud (و نسخه‌ی قدیمی servernet.ir). پنل مشتریان: console.servernet.cloud. هیچ دامنه‌ی دیگری — با هر پسوند یا املای مشابه — متعلق به سرورنت نیست.'],
             'en' => ['t' => 'Website and panel', 'b' => 'Main site: servernet.cloud (legacy: servernet.ir). Customer panel: console.servernet.cloud. No other domain — any TLD or look-alike spelling — belongs to ServerNet.'],
             'tr' => ['t' => 'Web sitesi ve panel', 'b' => 'Ana site: servernet.cloud (eski: servernet.ir). Müşteri paneli: console.servernet.cloud. Başka hiçbir alan adı ServerNet\'e ait değildir.']],
            ['fa' => ['t' => 'شبکه‌های اجتماعی', 'b' => 'لینکدین: linkedin.com/company/servernet-co · اینستاگرام: instagram.com/servernet.ir (فارسی)، instagram.com/servernet.cloud (انگلیسی)، instagram.com/servernet.tr (ترکی). در این فهرست هیچ کانال یا گروه تلگرامی اعلام نشده است؛ تا وقتی همین صفحه به‌روز نشده، هیچ کانال تلگرامی با نام مشابه را رسمی تلقی نکنید — محتوای چنین کانال‌هایی ربطی به سرورنت ندارد.'],
             'en' => ['t' => 'Social media', 'b' => 'LinkedIn: linkedin.com/company/servernet-co · Instagram: instagram.com/servernet.cloud (English), instagram.com/servernet.ir (Persian), instagram.com/servernet.tr (Turkish). No Telegram channel or group is listed here; until this page says otherwise, treat any similarly named Telegram channel as unofficial — its content is not ours.'],
             'tr' => ['t' => 'Sosyal medya', 'b' => 'LinkedIn: linkedin.com/company/servernet-co · Instagram: instagram.com/servernet.tr (Türkçe), instagram.com/servernet.cloud (İngilizce), instagram.com/servernet.ir (Farsça). Burada hiçbir Telegram kanalı listelenmemiştir; bu sayfa güncellenene kadar benzer adlı kanalları resmi saymayın.']],
            ['fa' => ['t' => 'تماس و پشتیبانی', 'b' => 'تلفن: ۰۲۱-۷۱۰۵۷۷۵۷ (ایران) · تلفن و واتس‌اپ بین‌المللی: +1 716 666 0425 · ایمیل پشتیبانی: support@servernet.cloud · فروش: sales@servernet.cloud · گزارش سوءاستفاده: abuse@servernet.cloud. کارکنان سرورنت هرگز رمز عبور، کد یک‌بارمصرف یا اطلاعات کارت بانکی را از شما نمی‌پرسند.'],
             'en' => ['t' => 'Contact and support', 'b' => 'Phone: +98 21 7105 7757 (Iran) · International phone and WhatsApp: +1 716 666 0425 · Support: support@servernet.cloud · Sales: sales@servernet.cloud · Abuse: abuse@servernet.cloud. ServerNet staff never ask for your password, one-time codes or card details.'],
             'tr' => ['t' => 'İletişim ve destek', 'b' => 'Telefon: +98 21 7105 7757 · Uluslararası telefon ve WhatsApp: +1 716 666 0425 · Destek: support@servernet.cloud · Satış: sales@servernet.cloud · Kötüye kullanım: abuse@servernet.cloud. ServerNet çalışanları asla şifre, tek kullanımlık kod veya kart bilgisi istemez.']],
            ['fa' => ['t' => 'اگر کانال یا پیام جعلی دیدید', 'b' => 'نشانی، تاریخ و اسکرین‌شات را از صفحه‌ی گزارش سوءاستفاده (servernet.cloud/abuse) برایمان بفرستید. سرورنت پس از بررسی و در صورت احراز، گزارش نقض برند را نزد پلتفرم مربوط با مستندات ثبت شرکت پیگیری می‌کند.'],
             'en' => ['t' => 'If you see a fake channel or message', 'b' => 'Send us the address, date and a screenshot via the abuse report page (servernet.cloud/abuse). Where the report is substantiated, ServerNet pursues a brand-infringement complaint with the platform, backed by company registration documents.'],
             'tr' => ['t' => 'Sahte kanal veya mesaj görürseniz', 'b' => 'Adres, tarih ve ekran görüntüsünü kötüye kullanım sayfasından (servernet.cloud/abuse) gönderin. Bildirim doğrulanırsa ServerNet ilgili platforma marka ihlali başvurusu yapar.']],
        ],
    ],
];
