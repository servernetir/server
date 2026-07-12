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
            ['fa' => ['t' => 'ضمانت بازگشت وجه', 'b' => 'برای سرویس‌های واجد شرایط، ضمانت بازگشت وجه ۱۴ روزه ارائه می‌شود. دامنه‌ها، لایسنس‌ها و هزینه‌های راه‌اندازی سفارشی مشمول این ضمانت نیستند.'],
             'en' => ['t' => 'Money-back guarantee', 'b' => 'Eligible services include a 14-day money-back guarantee. Domains, licenses and custom setup fees are excluded.'],
             'tr' => ['t' => 'Para iade garantisi', 'b' => 'Uygun hizmetler 14 gün para iade garantisi içerir. Alan adı ve lisanslar hariçtir.']],
            ['fa' => ['t' => 'آپتایم و مسئولیت', 'b' => 'ما آپتایم ۹۹.۹٪ را طبق توافق‌نامه سطح خدمات (SLA) تضمین می‌کنیم. مسئولیت ما محدود به جبران طبق SLA است و شامل خسارات غیرمستقیم یا از دست رفتن سود نمی‌شود. مشتری مسئول تهیه بکاپ مستقل از داده‌های حیاتی خود است.'],
             'en' => ['t' => 'Uptime & liability', 'b' => 'We guarantee 99.9% uptime under our SLA. Our liability is limited to SLA compensation and excludes indirect damages or lost profit. Customers are responsible for keeping independent backups of critical data.'],
             'tr' => ['t' => 'Çalışma süresi ve sorumluluk', 'b' => 'SLA kapsamında %99,9 çalışma süresi garanti ederiz. Sorumluluğumuz SLA tazminatıyla sınırlıdır.']],
            ['fa' => ['t' => 'تغییرات', 'b' => 'ممکن است این شرایط را به‌روزرسانی کنیم. تغییرات مهم از طریق ایمیل یا اعلان در ناحیه کاربری اطلاع‌رسانی می‌شود. ادامه استفاده پس از تغییر، به‌منزله پذیرش شرایط جدید است.'],
             'en' => ['t' => 'Changes', 'b' => 'We may update these terms. Material changes are announced by email or a notice in the client area. Continued use after a change means acceptance of the new terms.'],
             'tr' => ['t' => 'Değişiklikler', 'b' => 'Bu şartları güncelleyebiliriz. Önemli değişiklikler e-posta ile bildirilir.']],
        ],
    ],
];
