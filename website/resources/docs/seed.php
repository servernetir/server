<?php

/*
|--------------------------------------------------------------------------
| مستندات پایه‌ی سرورنت — محتوای اصیل و اختصاصی
|--------------------------------------------------------------------------
| این محتوا مخصوص سرویس‌های سرورنت نوشته شده است. برای افزودن مستند جدید
| یک آیتم به این آرایه اضافه کنید و `php artisan docs:seed` را اجرا کنید،
| یا مستقیماً از پنل مدیریت (پایگاه دانش) مقاله بسازید.
*/

return [

    /* ==================== شروع کار ==================== */
    [
        'slug' => 'ordering-your-first-service', 'section' => 'getting-started',
        'icon' => 'rocket', 'cover' => 'a', 'reading' => 4,
        'fa' => [
            'title' => 'ثبت اولین سفارش در سرورنت',
            'excerpt' => 'از انتخاب سرویس تا تحویل — مسیر کامل ثبت سفارش، پرداخت و فعال‌سازی در سرورنت.',
            'tags' => ['شروع کار', 'سفارش', 'ناحیه کاربری'],
            'content' => <<<'HTML'
<p>این راهنما مسیر کامل ثبت اولین سفارش در سرورنت را توضیح می‌دهد؛ از انتخاب سرویس مناسب تا لحظه‌ای که سرویس تحویل داده می‌شود.</p>

<h2>۱. انتخاب سرویس مناسب</h2>
<p>پیش از سفارش، مشخص کنید کدام سرویس با نیاز شما می‌خواند:</p>
<ul>
<li><strong>هاست وب</strong> — برای سایت‌های شرکتی، فروشگاهی و وردپرسی که نیازی به دسترسی ریشه ندارند. ساده‌ترین گزینه؛ مدیریت سرور با ماست.</li>
<li><strong>سرور مجازی (VPS)</strong> — وقتی به دسترسی root، نصب نرم‌افزار دلخواه یا منابع تضمین‌شده نیاز دارید.</li>
<li><strong>سرور اختصاصی</strong> — برای بار سنگین، دیتابیس‌های بزرگ یا الزامات ایزولاسیون کامل.</li>
<li><strong>سرویس‌های ابری</strong> — وقتی مقیاس‌پذیری خودکار، فضای ذخیره‌سازی آبجکت یا کوبرنتیز لازم دارید.</li>
</ul>
<p>اگر مطمئن نیستید، پیش از خرید با کارشناسان ما تماس بگیرید؛ مشاوره رایگان است و از خرید اشتباه جلوگیری می‌کند.</p>

<h2>۲. انتخاب لوکیشن</h2>
<p>لوکیشن سرور مستقیماً روی سرعت سایت برای بازدیدکنندگان اثر می‌گذارد:</p>
<ul>
<li><strong>ایران</strong> — اگر بیشتر مخاطبان داخل کشورند. کمترین پینگ و بهترین تجربه برای کاربر ایرانی.</li>
<li><strong>آلمان، فنلاند، فرانسه</strong> — برای مخاطب اروپایی یا زمانی که به پهنای باند بین‌المللی نیاز دارید.</li>
<li><strong>آمریکا</strong> — برای مخاطب آمریکای شمالی.</li>
</ul>

<h2>۳. ثبت سفارش</h2>
<ol>
<li>در صفحه‌ی سرویس، پلن مورد نظر را انتخاب و روی دکمه‌ی سفارش کلیک کنید.</li>
<li>دوره‌ی پرداخت را انتخاب کنید. پرداخت سالانه معمولاً حدود ۲۰٪ ارزان‌تر از ماهانه تمام می‌شود.</li>
<li>اگر دامنه ندارید، در همین مرحله می‌توانید دامنه ثبت کنید یا دامنه‌ی موجود خود را وارد کنید.</li>
<li>اطلاعات حساب کاربری را تکمیل کنید. <strong>ایمیل را دقیق وارد کنید</strong> — اطلاعات تحویل سرویس و هشدارهای تمدید به همین آدرس ارسال می‌شود.</li>
</ol>

<h2>۴. پرداخت و فعال‌سازی</h2>
<p>پس از پرداخت موفق:</p>
<ul>
<li><strong>هاست و دامنه</strong> معمولاً بلافاصله و به‌صورت خودکار تحویل می‌شوند.</li>
<li><strong>سرور مجازی</strong> در چند دقیقه آماده می‌شود.</li>
<li><strong>سرور اختصاصی</strong> بسته به پیکربندی ممکن است چند ساعت زمان ببرد.</li>
</ul>
<p>اطلاعات ورود (آدرس کنترل‌پنل، نام کاربری و رمز عبور) به ایمیل شما ارسال و در ناحیه کاربری هم ثبت می‌شود.</p>

<h2>مرحله‌ی بعد</h2>
<p>پس از تحویل سرویس، به ناحیه کاربری وارد شوید و اطلاعات دسترسی را بردارید. اگر هاست گرفته‌اید، ادامه‌ی کار را از راهنمای اولین قدم‌ها در کنترل‌پنل دنبال کنید.</p>

<h2>مشکلی پیش آمد؟</h2>
<p>اگر پرداخت انجام شد ولی سرویس تحویل نشد، ابتدا پوشه‌ی اسپم ایمیل خود را بررسی کنید. اگر همچنان مشکل داشتید، از ناحیه کاربری تیکت بزنید؛ تیم پشتیبانی ۲۴ ساعته پاسخگوست.</p>
HTML,
        ],
        'en' => [
            'title' => 'Placing your first order with ServerNet',
            'excerpt' => 'From picking a service to delivery — the full path through ordering, payment and activation.',
            'tags' => ['getting started', 'ordering', 'client area'],
            'content' => <<<'HTML'
<p>This guide walks through placing your first order with ServerNet, from choosing the right service to the moment it is delivered.</p>

<h2>1. Choosing the right service</h2>
<p>Before ordering, work out which service matches your needs:</p>
<ul>
<li><strong>Web hosting</strong> — for company sites, shops and WordPress installs that do not need root access. The simplest option; we manage the server for you.</li>
<li><strong>VPS</strong> — when you need root access, custom software, or guaranteed resources.</li>
<li><strong>Dedicated server</strong> — for heavy workloads, large databases, or full isolation requirements.</li>
<li><strong>Cloud services</strong> — when you need autoscaling, object storage or Kubernetes.</li>
</ul>
<p>If you are unsure, talk to our specialists before buying — consultation is free and prevents an expensive mistake.</p>

<h2>2. Choosing a location</h2>
<p>Server location directly affects how fast your site feels to visitors:</p>
<ul>
<li><strong>Iran</strong> — if most of your audience is inside the country. Lowest latency for Iranian users.</li>
<li><strong>Germany, Finland, France</strong> — for European audiences or when you need international bandwidth.</li>
<li><strong>USA</strong> — for North American audiences.</li>
</ul>

<h2>3. Placing the order</h2>
<ol>
<li>On the service page, pick your plan and click the order button.</li>
<li>Choose a billing cycle. Annual billing is typically around 20% cheaper than monthly.</li>
<li>If you do not own a domain yet, you can register one at this step, or enter a domain you already have.</li>
<li>Complete your account details. <strong>Enter your email carefully</strong> — service credentials and renewal notices go to that address.</li>
</ol>

<h2>4. Payment and activation</h2>
<p>After a successful payment:</p>
<ul>
<li><strong>Hosting and domains</strong> are usually delivered instantly and automatically.</li>
<li><strong>A VPS</strong> is ready within a few minutes.</li>
<li><strong>A dedicated server</strong> may take a few hours depending on the configuration.</li>
</ul>
<p>Login details (control panel address, username and password) are emailed to you and also recorded in your client area.</p>

<h2>Next step</h2>
<p>Once the service is delivered, sign in to the client area and collect your access details. If you ordered hosting, continue with the control panel first-steps guide.</p>

<h2>Something went wrong?</h2>
<p>If payment succeeded but the service was not delivered, check your spam folder first. If the problem persists, open a ticket from the client area — support is available 24/7.</p>
HTML,
        ],
        'tr' => [
            'title' => "ServerNet'te ilk siparişinizi verme",
            'excerpt' => 'Hizmet seçiminden teslimata — sipariş, ödeme ve aktivasyonun tam yolu.',
            'tags' => ['başlarken', 'sipariş', 'müşteri paneli'],
            'content' => <<<'HTML'
<p>Bu kılavuz, doğru hizmeti seçmekten teslim edildiği ana kadar ServerNet'te ilk siparişinizi vermeyi anlatır.</p>

<h2>1. Doğru hizmeti seçmek</h2>
<p>Sipariş vermeden önce hangi hizmetin ihtiyacınıza uyduğunu belirleyin:</p>
<ul>
<li><strong>Web hosting</strong> — root erişimi gerektirmeyen kurumsal siteler, mağazalar ve WordPress kurulumları için. En basit seçenek; sunucuyu biz yönetiriz.</li>
<li><strong>VPS</strong> — root erişimi, özel yazılım veya garantili kaynaklara ihtiyacınız olduğunda.</li>
<li><strong>Fiziksel sunucu</strong> — ağır iş yükleri, büyük veritabanları veya tam izolasyon gereksinimleri için.</li>
<li><strong>Bulut hizmetleri</strong> — otomatik ölçekleme, nesne depolama veya Kubernetes gerektiğinde.</li>
</ul>
<p>Emin değilseniz satın almadan önce uzmanlarımızla görüşün — danışmanlık ücretsizdir ve pahalı bir hatayı önler.</p>

<h2>2. Lokasyon seçimi</h2>
<p>Sunucu lokasyonu, sitenizin ziyaretçilere ne kadar hızlı hissettirdiğini doğrudan etkiler:</p>
<ul>
<li><strong>İran</strong> — kitlenizin çoğu ülke içindeyse. İranlı kullanıcılar için en düşük gecikme.</li>
<li><strong>Almanya, Finlandiya, Fransa</strong> — Avrupa kitlesi veya uluslararası bant genişliği için.</li>
<li><strong>ABD</strong> — Kuzey Amerika kitlesi için.</li>
</ul>

<h2>3. Siparişi verme</h2>
<ol>
<li>Hizmet sayfasında paketinizi seçin ve sipariş düğmesine tıklayın.</li>
<li>Faturalama döngüsünü seçin. Yıllık ödeme genellikle aylıktan yaklaşık %20 daha ucuzdur.</li>
<li>Henüz alan adınız yoksa bu adımda kaydedebilir veya mevcut alan adınızı girebilirsiniz.</li>
<li>Hesap bilgilerinizi tamamlayın. <strong>E-postanızı dikkatle girin</strong> — hizmet bilgileri ve yenileme bildirimleri bu adrese gider.</li>
</ol>

<h2>4. Ödeme ve aktivasyon</h2>
<p>Başarılı ödemeden sonra:</p>
<ul>
<li><strong>Hosting ve alan adları</strong> genellikle anında ve otomatik teslim edilir.</li>
<li><strong>VPS</strong> birkaç dakika içinde hazır olur.</li>
<li><strong>Fiziksel sunucu</strong> yapılandırmaya bağlı olarak birkaç saat sürebilir.</li>
</ul>
<p>Giriş bilgileri (kontrol paneli adresi, kullanıcı adı ve parola) size e-postayla gönderilir ve müşteri panelinize de kaydedilir.</p>

<h2>Sonraki adım</h2>
<p>Hizmet teslim edildikten sonra müşteri paneline giriş yapın ve erişim bilgilerinizi alın. Hosting sipariş ettiyseniz kontrol paneli ilk adımlar kılavuzuyla devam edin.</p>

<h2>Bir sorun mu çıktı?</h2>
<p>Ödeme başarılı olduysa ancak hizmet teslim edilmediyse önce spam klasörünüzü kontrol edin. Sorun devam ederse müşteri panelinden destek talebi açın — destek 7/24 hizmetinizdedir.</p>
HTML,
        ],
    ],

    /* ==================== هاست وب ==================== */
    [
        'slug' => 'uploading-your-website', 'section' => 'hosting',
        'icon' => 'server', 'cover' => 'b', 'reading' => 5,
        'fa' => [
            'title' => 'آپلود فایل‌های سایت روی هاست',
            'excerpt' => 'سه روش آپلود سایت — فایل‌منیجر، FTP و SFTP — به‌همراه اشتباه‌های رایجی که سایت را از کار می‌اندازد.',
            'tags' => ['هاست', 'FTP', 'کنترل‌پنل'],
            'content' => <<<'HTML'
<p>بعد از تحویل هاست، اولین کار انتقال فایل‌های سایت است. سه روش رایج وجود دارد؛ انتخاب بین آن‌ها به حجم فایل‌ها و ترجیح شما بستگی دارد.</p>

<h2>مسیر درست آپلود کجاست؟</h2>
<p>فایل‌های سایت باید داخل پوشه‌ی <code>public_html</code> قرار بگیرند. این پوشه ریشه‌ی وب‌سایت شماست؛ یعنی فایل <code>public_html/index.php</code> با آدرس <code>example.com/index.php</code> باز می‌شود.</p>
<p><strong>اشتباه بسیار رایج:</strong> آپلود کل پوشه‌ی پروژه داخل <code>public_html</code>. در این حالت سایت با آدرس <code>example.com/myproject/</code> باز می‌شود نه آدرس اصلی. محتویات پوشه را آپلود کنید، نه خود پوشه را.</p>

<h2>روش ۱: فایل‌منیجر کنترل‌پنل</h2>
<p>ساده‌ترین روش و مناسب برای سایت‌های کوچک:</p>
<ol>
<li>وارد کنترل‌پنل شوید و File Manager را باز کنید.</li>
<li>وارد پوشه‌ی <code>public_html</code> شوید.</li>
<li>فایل‌های سایت را در یک فایل ZIP فشرده کنید و آن را آپلود کنید.</li>
<li>روی فایل ZIP راست‌کلیک و گزینه‌ی Extract را بزنید.</li>
<li>پس از استخراج، فایل ZIP را حذف کنید.</li>
</ol>
<p>آپلود یک فایل ZIP و استخراج آن روی سرور، به‌مراتب سریع‌تر از آپلود صدها فایل جداگانه است.</p>

<h2>روش ۲: FTP</h2>
<p>برای حجم بالا یا به‌روزرسانی‌های مکرر مناسب‌تر است. با نرم‌افزارهایی مثل FileZilla:</p>
<ul>
<li><strong>Host</strong> — نام دامنه یا آی‌پی سرور</li>
<li><strong>Username / Password</strong> — همان اطلاعات کنترل‌پنل یا یک اکانت FTP اختصاصی</li>
<li><strong>Port</strong> — ۲۱ برای FTP</li>
</ul>

<h2>روش ۳: SFTP (توصیه‌شده)</h2>
<p>SFTP همان کار FTP را انجام می‌دهد اما ارتباط رمزنگاری‌شده است. اگر سرویس شما SSH دارد، به‌جای FTP از SFTP استفاده کنید:</p>
<ul>
<li><strong>Protocol</strong> — SFTP</li>
<li><strong>Port</strong> — ۲۲</li>
</ul>
<p>در FTP ساده، نام کاربری و رمز عبور بدون رمزنگاری روی شبکه منتقل می‌شوند. روی شبکه‌های عمومی این یک ریسک واقعی است.</p>

<h2>بعد از آپلود چه چیزهایی را بررسی کنیم</h2>
<ul>
<li><strong>سطح دسترسی فایل‌ها</strong> — پوشه‌ها معمولاً ۷۵۵ و فایل‌ها ۶۴۴. هرگز ۷۷۷ ندهید؛ یک ریسک امنیتی جدی است.</li>
<li><strong>فایل‌های مخفی</strong> — فایل‌هایی مثل <code>.htaccess</code> در بعضی نرم‌افزارها به‌صورت پیش‌فرض نمایش داده نمی‌شوند. نمایش فایل‌های مخفی را فعال کنید تا جا نمانند.</li>
<li><strong>تنظیمات اتصال دیتابیس</strong> — اگر سایت دیتابیس دارد، اطلاعات اتصال را با مقادیر هاست جدید به‌روز کنید.</li>
</ul>

<h2>سایت بالا نمی‌آید؟</h2>
<p>اگر بعد از آپلود صفحه‌ی سفید یا خطا دیدید، ابتدا بررسی کنید فایل <code>index.php</code> یا <code>index.html</code> مستقیماً داخل <code>public_html</code> باشد. سپس لاگ خطا را از کنترل‌پنل ببینید؛ معمولاً علت دقیق آنجاست.</p>
HTML,
        ],
        'en' => [
            'title' => 'Uploading your website files',
            'excerpt' => 'Three ways to upload a site — File Manager, FTP and SFTP — plus the common mistakes that break it.',
            'tags' => ['hosting', 'FTP', 'control panel'],
            'content' => <<<'HTML'
<p>Once your hosting is delivered, the first job is moving your site files across. There are three common methods; which you pick depends on file size and preference.</p>

<h2>Where do files go?</h2>
<p>Your site files belong inside the <code>public_html</code> folder. This is your web root — the file <code>public_html/index.php</code> is served at <code>example.com/index.php</code>.</p>
<p><strong>A very common mistake:</strong> uploading the whole project folder into <code>public_html</code>. That makes your site load at <code>example.com/myproject/</code> instead of the root. Upload the folder's <em>contents</em>, not the folder itself.</p>

<h2>Method 1: control panel File Manager</h2>
<p>The simplest approach, good for small sites:</p>
<ol>
<li>Sign in to your control panel and open File Manager.</li>
<li>Enter the <code>public_html</code> folder.</li>
<li>Compress your site files into a single ZIP and upload it.</li>
<li>Right-click the ZIP and choose Extract.</li>
<li>Delete the ZIP once extraction finishes.</li>
</ol>
<p>Uploading one ZIP and extracting it server-side is far faster than uploading hundreds of individual files.</p>

<h2>Method 2: FTP</h2>
<p>Better for large volumes or frequent updates. Using a client such as FileZilla:</p>
<ul>
<li><strong>Host</strong> — your domain name or server IP</li>
<li><strong>Username / Password</strong> — your control panel credentials, or a dedicated FTP account</li>
<li><strong>Port</strong> — 21 for FTP</li>
</ul>

<h2>Method 3: SFTP (recommended)</h2>
<p>SFTP does the same job as FTP but over an encrypted connection. If your service includes SSH, use SFTP instead of plain FTP:</p>
<ul>
<li><strong>Protocol</strong> — SFTP</li>
<li><strong>Port</strong> — 22</li>
</ul>
<p>With plain FTP, your username and password travel the network unencrypted. On public networks that is a genuine risk.</p>

<h2>What to check after uploading</h2>
<ul>
<li><strong>File permissions</strong> — folders are normally 755 and files 644. Never use 777; it is a serious security risk.</li>
<li><strong>Hidden files</strong> — files such as <code>.htaccess</code> are hidden by default in some clients. Enable "show hidden files" so they are not left behind.</li>
<li><strong>Database settings</strong> — if your site uses a database, update the connection details to match the new host.</li>
</ul>

<h2>Site not loading?</h2>
<p>If you see a blank page or an error after uploading, first confirm that <code>index.php</code> or <code>index.html</code> sits directly inside <code>public_html</code>. Then open the error log from your control panel — the specific cause is usually there.</p>
HTML,
        ],
        'tr' => [
            'title' => 'Web sitesi dosyalarınızı yükleme',
            'excerpt' => 'Site yüklemenin üç yolu — Dosya Yöneticisi, FTP ve SFTP — ve siteyi bozan yaygın hatalar.',
            'tags' => ['hosting', 'FTP', 'kontrol paneli'],
            'content' => <<<'HTML'
<p>Hosting hizmetiniz teslim edildikten sonraki ilk iş, site dosyalarınızı taşımaktır. Üç yaygın yöntem vardır; seçiminiz dosya boyutuna ve tercihinize bağlıdır.</p>

<h2>Dosyalar nereye gider?</h2>
<p>Site dosyalarınız <code>public_html</code> klasörünün içine gelir. Burası web kök dizininizdir — <code>public_html/index.php</code> dosyası <code>example.com/index.php</code> adresinde sunulur.</p>
<p><strong>Çok yaygın bir hata:</strong> tüm proje klasörünü <code>public_html</code> içine yüklemek. Bu, sitenizin kök yerine <code>example.com/projem/</code> adresinde açılmasına neden olur. Klasörün <em>içeriğini</em> yükleyin, klasörün kendisini değil.</p>

<h2>Yöntem 1: kontrol paneli Dosya Yöneticisi</h2>
<p>En basit yaklaşım, küçük siteler için uygun:</p>
<ol>
<li>Kontrol panelinize giriş yapın ve Dosya Yöneticisi'ni açın.</li>
<li><code>public_html</code> klasörüne girin.</li>
<li>Site dosyalarınızı tek bir ZIP'te sıkıştırın ve yükleyin.</li>
<li>ZIP'e sağ tıklayın ve Ayıkla'yı seçin.</li>
<li>Ayıklama bittikten sonra ZIP dosyasını silin.</li>
</ol>
<p>Tek bir ZIP yükleyip sunucu tarafında ayıklamak, yüzlerce dosyayı tek tek yüklemekten çok daha hızlıdır.</p>

<h2>Yöntem 2: FTP</h2>
<p>Büyük hacimler veya sık güncellemeler için daha uygun. FileZilla gibi bir istemciyle:</p>
<ul>
<li><strong>Host</strong> — alan adınız veya sunucu IP'si</li>
<li><strong>Kullanıcı adı / Parola</strong> — kontrol paneli bilgileriniz veya özel bir FTP hesabı</li>
<li><strong>Port</strong> — FTP için 21</li>
</ul>

<h2>Yöntem 3: SFTP (önerilir)</h2>
<p>SFTP, FTP ile aynı işi şifreli bir bağlantı üzerinden yapar. Hizmetinizde SSH varsa düz FTP yerine SFTP kullanın:</p>
<ul>
<li><strong>Protokol</strong> — SFTP</li>
<li><strong>Port</strong> — 22</li>
</ul>
<p>Düz FTP'de kullanıcı adınız ve parolanız ağ üzerinde şifrelenmeden iletilir. Halka açık ağlarda bu gerçek bir risktir.</p>

<h2>Yükledikten sonra kontrol edilecekler</h2>
<ul>
<li><strong>Dosya izinleri</strong> — klasörler normalde 755, dosyalar 644. Asla 777 kullanmayın; ciddi bir güvenlik riskidir.</li>
<li><strong>Gizli dosyalar</strong> — <code>.htaccess</code> gibi dosyalar bazı istemcilerde varsayılan olarak gizlidir. Geride kalmamaları için "gizli dosyaları göster" seçeneğini açın.</li>
<li><strong>Veritabanı ayarları</strong> — siteniz veritabanı kullanıyorsa bağlantı bilgilerini yeni sunucuya göre güncelleyin.</li>
</ul>

<h2>Site açılmıyor mu?</h2>
<p>Yükleme sonrası boş sayfa veya hata görüyorsanız önce <code>index.php</code> veya <code>index.html</code> dosyasının doğrudan <code>public_html</code> içinde olduğunu doğrulayın. Ardından kontrol panelinden hata günlüğünü açın — kesin neden genellikle oradadır.</p>
HTML,
        ],
    ],

    /* ==================== سرور ==================== */
    [
        'slug' => 'connecting-to-linux-server-ssh', 'section' => 'servers',
        'icon' => 'cpu', 'cover' => 'c', 'reading' => 5,
        'fa' => [
            'title' => 'اتصال به سرور لینوکس با SSH',
            'excerpt' => 'اتصال به سرور مجازی یا اختصاصی لینوکس از ویندوز، مک و لینوکس — به‌همراه ورود با کلید به‌جای رمز عبور.',
            'tags' => ['سرور مجازی', 'لینوکس', 'SSH'],
            'content' => <<<'HTML'
<p>پس از تحویل سرور لینوکس، مدیریت آن از طریق SSH انجام می‌شود. این راهنما اتصال از هر سه سیستم‌عامل و سپس روش امن‌تر ورود با کلید را توضیح می‌دهد.</p>

<h2>چه چیزی لازم دارید</h2>
<p>در ایمیل تحویل سرویس این موارد آمده است:</p>
<ul>
<li>آی‌پی سرور</li>
<li>نام کاربری — معمولاً <code>root</code> در لینوکس</li>
<li>رمز عبور</li>
<li>پورت SSH — به‌صورت پیش‌فرض ۲۲</li>
</ul>

<h2>اتصال از ویندوز</h2>
<p>ویندوز ۱۰ به بعد کلاینت SSH داخلی دارد. کافی است PowerShell یا Command Prompt را باز کنید:</p>
<pre><code>ssh root@YOUR_SERVER_IP</code></pre>
<p>اگر پورت SSH تغییر کرده است:</p>
<pre><code>ssh root@YOUR_SERVER_IP -p 2222</code></pre>
<p>در اولین اتصال پیامی درباره‌ی اصالت میزبان می‌بینید؛ با تایپ <code>yes</code> تأیید کنید. اگر ترجیح می‌دهید رابط گرافیکی داشته باشید، PuTTY هم گزینه‌ی خوبی است.</p>

<h2>اتصال از مک یا لینوکس</h2>
<p>ترمینال را باز کنید و همان دستور را اجرا کنید:</p>
<pre><code>ssh root@YOUR_SERVER_IP</code></pre>

<h2>ورود با کلید به‌جای رمز عبور</h2>
<p>ورود با رمز عبور در برابر حملات جستجوی فراگیر آسیب‌پذیر است. سرورهای عمومی دائماً هدف این حملات‌اند. ورود با کلید هم امن‌تر است هم راحت‌تر.</p>

<h3>۱. ساخت جفت کلید</h3>
<p>روی کامپیوتر خودتان (نه روی سرور):</p>
<pre><code>ssh-keygen -t ed25519 -C "my-laptop"</code></pre>
<p>هنگام پرسش برای passphrase، یک عبارت عبور وارد کنید؛ این لایه‌ی محافظت دوم است اگر لپ‌تاپتان به دست کسی بیفتد.</p>

<h3>۲. انتقال کلید عمومی به سرور</h3>
<pre><code>ssh-copy-id root@YOUR_SERVER_IP</code></pre>
<p>اگر <code>ssh-copy-id</code> در دسترس نبود، محتوای فایل <code>~/.ssh/id_ed25519.pub</code> را در فایل <code>~/.ssh/authorized_keys</code> روی سرور اضافه کنید.</p>

<h3>۳. آزمایش و سپس غیرفعال‌کردن ورود با رمز</h3>
<p><strong>ابتدا مطمئن شوید ورود با کلید کار می‌کند</strong> و اتصال فعلی را نبندید. سپس در فایل <code>/etc/ssh/sshd_config</code>:</p>
<pre><code>PasswordAuthentication no
PermitRootLogin prohibit-password</code></pre>
<p>و سرویس را ری‌استارت کنید:</p>
<pre><code>systemctl restart sshd</code></pre>
<p><strong>هشدار:</strong> پیش از بستن ترمینال فعلی، در یک پنجره‌ی جدید اتصال را آزمایش کنید. اگر تنظیمات اشتباه باشد و ترمینال را ببندید، دسترسی خود را از دست می‌دهید و برای بازیابی باید از کنسول اضطراری استفاده کنید.</p>

<h2>خطاهای رایج</h2>
<ul>
<li><strong>Connection timed out</strong> — معمولاً فایروال پورت را بسته یا آی‌پی اشتباه است.</li>
<li><strong>Permission denied</strong> — نام کاربری یا رمز اشتباه است، یا ورود با رمز غیرفعال شده است.</li>
<li><strong>Host key verification failed</strong> — سرور بازنصب شده و کلید میزبان عوض شده است. ورودی قدیمی را از فایل <code>~/.ssh/known_hosts</code> حذف کنید.</li>
</ul>
HTML,
        ],
        'en' => [
            'title' => 'Connecting to a Linux server over SSH',
            'excerpt' => 'Reach your Linux VPS or dedicated server from Windows, macOS and Linux — plus key-based login instead of passwords.',
            'tags' => ['VPS', 'Linux', 'SSH'],
            'content' => <<<'HTML'
<p>Once your Linux server is delivered, you manage it over SSH. This guide covers connecting from all three operating systems, then the safer key-based login.</p>

<h2>What you need</h2>
<p>Your service delivery email contains:</p>
<ul>
<li>Server IP address</li>
<li>Username — usually <code>root</code> on Linux</li>
<li>Password</li>
<li>SSH port — 22 by default</li>
</ul>

<h2>Connecting from Windows</h2>
<p>Windows 10 and later ship with a built-in SSH client. Open PowerShell or Command Prompt:</p>
<pre><code>ssh root@YOUR_SERVER_IP</code></pre>
<p>If the SSH port has been changed:</p>
<pre><code>ssh root@YOUR_SERVER_IP -p 2222</code></pre>
<p>On first connection you will see a host authenticity prompt; type <code>yes</code> to accept. If you prefer a graphical client, PuTTY is a solid choice.</p>

<h2>Connecting from macOS or Linux</h2>
<p>Open Terminal and run the same command:</p>
<pre><code>ssh root@YOUR_SERVER_IP</code></pre>

<h2>Key-based login instead of passwords</h2>
<p>Password login is vulnerable to brute-force attacks, and public servers are targeted constantly. Key-based login is both safer and more convenient.</p>

<h3>1. Generate a key pair</h3>
<p>On your own computer (not on the server):</p>
<pre><code>ssh-keygen -t ed25519 -C "my-laptop"</code></pre>
<p>When prompted for a passphrase, set one — it is your second layer of protection if your laptop is ever lost.</p>

<h3>2. Copy the public key to the server</h3>
<pre><code>ssh-copy-id root@YOUR_SERVER_IP</code></pre>
<p>If <code>ssh-copy-id</code> is unavailable, append the contents of <code>~/.ssh/id_ed25519.pub</code> to <code>~/.ssh/authorized_keys</code> on the server.</p>

<h3>3. Test, then disable password login</h3>
<p><strong>Confirm key login works first</strong> and keep your current session open. Then in <code>/etc/ssh/sshd_config</code>:</p>
<pre><code>PasswordAuthentication no
PermitRootLogin prohibit-password</code></pre>
<p>And restart the service:</p>
<pre><code>systemctl restart sshd</code></pre>
<p><strong>Warning:</strong> test the connection in a new window before closing your current terminal. If the configuration is wrong and you close it, you lock yourself out and will need emergency console access to recover.</p>

<h2>Common errors</h2>
<ul>
<li><strong>Connection timed out</strong> — usually a firewall blocking the port, or the wrong IP.</li>
<li><strong>Permission denied</strong> — wrong username or password, or password login is disabled.</li>
<li><strong>Host key verification failed</strong> — the server was rebuilt and its host key changed. Remove the old entry from <code>~/.ssh/known_hosts</code>.</li>
</ul>
HTML,
        ],
        'tr' => [
            'title' => 'SSH ile Linux sunucuya bağlanma',
            'excerpt' => "Linux VPS veya fiziksel sunucunuza Windows, macOS ve Linux'tan erişin — ayrıca parola yerine anahtarla giriş.",
            'tags' => ['VPS', 'Linux', 'SSH'],
            'content' => <<<'HTML'
<p>Linux sunucunuz teslim edildikten sonra yönetimi SSH üzerinden yaparsınız. Bu kılavuz üç işletim sisteminden bağlanmayı ve ardından daha güvenli anahtar tabanlı girişi anlatır.</p>

<h2>Neye ihtiyacınız var</h2>
<p>Hizmet teslim e-postanızda şunlar bulunur:</p>
<ul>
<li>Sunucu IP adresi</li>
<li>Kullanıcı adı — Linux'ta genellikle <code>root</code></li>
<li>Parola</li>
<li>SSH portu — varsayılan 22</li>
</ul>

<h2>Windows'tan bağlanma</h2>
<p>Windows 10 ve sonrası yerleşik SSH istemcisiyle gelir. PowerShell veya Komut İstemi'ni açın:</p>
<pre><code>ssh root@SUNUCU_IP</code></pre>
<p>SSH portu değiştirildiyse:</p>
<pre><code>ssh root@SUNUCU_IP -p 2222</code></pre>
<p>İlk bağlantıda bir host doğrulama sorusu görürsünüz; kabul etmek için <code>yes</code> yazın. Grafik arayüz tercih ediyorsanız PuTTY iyi bir seçenektir.</p>

<h2>macOS veya Linux'tan bağlanma</h2>
<p>Terminal'i açın ve aynı komutu çalıştırın:</p>
<pre><code>ssh root@SUNUCU_IP</code></pre>

<h2>Parola yerine anahtarla giriş</h2>
<p>Parola girişi kaba kuvvet saldırılarına açıktır ve genel sunucular sürekli hedeftir. Anahtar tabanlı giriş hem daha güvenli hem daha pratiktir.</p>

<h3>1. Anahtar çifti oluşturun</h3>
<p>Kendi bilgisayarınızda (sunucuda değil):</p>
<pre><code>ssh-keygen -t ed25519 -C "dizustu"</code></pre>
<p>Parola cümlesi sorulduğunda bir tane belirleyin — dizüstünüz kaybolursa ikinci koruma katmanınızdır.</p>

<h3>2. Genel anahtarı sunucuya kopyalayın</h3>
<pre><code>ssh-copy-id root@SUNUCU_IP</code></pre>
<p><code>ssh-copy-id</code> yoksa <code>~/.ssh/id_ed25519.pub</code> içeriğini sunucudaki <code>~/.ssh/authorized_keys</code> dosyasına ekleyin.</p>

<h3>3. Test edin, sonra parola girişini kapatın</h3>
<p><strong>Önce anahtar girişinin çalıştığını doğrulayın</strong> ve mevcut oturumunuzu açık tutun. Ardından <code>/etc/ssh/sshd_config</code> içinde:</p>
<pre><code>PasswordAuthentication no
PermitRootLogin prohibit-password</code></pre>
<p>Ve servisi yeniden başlatın:</p>
<pre><code>systemctl restart sshd</code></pre>
<p><strong>Uyarı:</strong> mevcut terminalinizi kapatmadan önce bağlantıyı yeni bir pencerede test edin. Yapılandırma hatalıysa ve kapatırsanız erişiminizi kaybedersiniz ve kurtarmak için acil konsol erişimi gerekir.</p>

<h2>Yaygın hatalar</h2>
<ul>
<li><strong>Connection timed out</strong> — genellikle portu engelleyen bir güvenlik duvarı veya yanlış IP.</li>
<li><strong>Permission denied</strong> — yanlış kullanıcı adı/parola veya parola girişi kapalı.</li>
<li><strong>Host key verification failed</strong> — sunucu yeniden kuruldu ve host anahtarı değişti. Eski kaydı <code>~/.ssh/known_hosts</code> dosyasından silin.</li>
</ul>
HTML,
        ],
    ],

    /* ==================== دامنه و DNS ==================== */
    [
        'slug' => 'understanding-dns-records', 'section' => 'domains',
        'icon' => 'globe', 'cover' => 'd', 'reading' => 6,
        'fa' => [
            'title' => 'رکوردهای DNS و کاربرد هرکدام',
            'excerpt' => 'A، AAAA، CNAME، MX، TXT و NS — هر رکورد چه می‌کند، کِی از آن استفاده کنیم و TTL چه معنایی دارد.',
            'tags' => ['دامنه', 'DNS', 'رکورد'],
            'content' => <<<'HTML'
<p>DNS دفترچه‌ی تلفن اینترنت است: نام دامنه را به آدرس سرور ترجمه می‌کند. شناخت رکوردها برای هر کسی که سایت یا ایمیل مدیریت می‌کند ضروری است.</p>

<h2>رکورد A</h2>
<p>نام دامنه را به یک آدرس IPv4 وصل می‌کند. پرکاربردترین رکورد.</p>
<pre><code>example.com.    A    192.0.2.10</code></pre>
<p>برای زیردامنه هم همین‌طور است:</p>
<pre><code>blog.example.com.    A    192.0.2.10</code></pre>

<h2>رکورد AAAA</h2>
<p>مثل A است اما برای آدرس IPv6. اگر سرور شما IPv6 دارد، این رکورد را هم بسازید.</p>

<h2>رکورد CNAME</h2>
<p>یک نام را به نام دیگری ارجاع می‌دهد (نه به آی‌پی):</p>
<pre><code>www.example.com.    CNAME    example.com.</code></pre>
<p>مزیتش این است که اگر آی‌پی عوض شود، فقط رکورد A اصلی را تغییر می‌دهید و همه‌ی CNAMEها خودکار به‌روز می‌شوند.</p>
<p><strong>محدودیت مهم:</strong> روی دامنه‌ی اصلی (ریشه) نمی‌توان CNAME گذاشت اگر رکورد دیگری مثل MX هم دارد. برای ریشه از رکورد A استفاده کنید.</p>

<h2>رکورد MX</h2>
<p>مشخص می‌کند ایمیل‌های دامنه به کدام سرور تحویل داده شوند. عدد ابتدای آن اولویت است — <strong>عدد کمتر یعنی اولویت بالاتر</strong>:</p>
<pre><code>example.com.    MX    10 mail.example.com.
example.com.    MX    20 backup.example.com.</code></pre>
<p>در این مثال ابتدا سرور اول امتحان می‌شود و اگر در دسترس نبود، سرور دوم.</p>

<h2>رکورد TXT</h2>
<p>متن آزاد نگه می‌دارد و بیشتر برای تأیید مالکیت و احراز هویت ایمیل به‌کار می‌رود:</p>
<ul>
<li><strong>SPF</strong> — تعیین می‌کند کدام سرورها مجازند از طرف دامنه‌ی شما ایمیل بفرستند</li>
<li><strong>DKIM</strong> — امضای رمزنگاری‌شده‌ی ایمیل‌ها</li>
<li><strong>DMARC</strong> — سیاست برخورد با ایمیل‌های جعلی</li>
<li>تأیید مالکیت دامنه برای سرویس‌هایی مثل گوگل</li>
</ul>

<h2>رکورد NS</h2>
<p>مشخص می‌کند کدام نیم‌سرورها مسئول پاسخ‌دهی به کوئری‌های دامنه‌ی شما هستند. این رکورد را در پنل ثبت‌کننده‌ی دامنه تنظیم می‌کنید، نه در پنل DNS.</p>

<h2>TTL چیست؟</h2>
<p>TTL یعنی «زمان زنده‌ماندن» و تعیین می‌کند سرورهای DNS چند ثانیه پاسخ را کش کنند.</p>
<ul>
<li><strong>TTL بالا (مثلاً ۸۶۴۰۰ = یک روز)</strong> — کش بهتر، بار کمتر، اما تغییرات دیرتر اعمال می‌شوند.</li>
<li><strong>TTL پایین (مثلاً ۳۰۰ = پنج دقیقه)</strong> — تغییرات سریع‌تر منتشر می‌شوند.</li>
</ul>
<p><strong>نکته‌ی عملی:</strong> اگر قصد مهاجرت سرور دارید، <em>چند ساعت قبل</em> TTL را روی عدد پایین بگذارید. آن‌وقت هنگام تغییر واقعی، انتشار خیلی سریع انجام می‌شود. بعد از پایان مهاجرت دوباره آن را بالا ببرید.</p>

<h2>چرا تغییرات فوری اعمال نمی‌شود؟</h2>
<p>به این تأخیر «انتشار DNS» می‌گویند و علتش کش است: سرورهای DNS در سراسر دنیا پاسخ قبلی را تا پایان TTL نگه می‌دارند. معمولاً بین چند دقیقه تا ۲۴ ساعت طول می‌کشد.</p>
<p>برای بررسی وضعیت فعلی رکوردها می‌توانید از <strong>ابزار بررسی کامل DNS سرورنت</strong> استفاده کنید که همه‌ی رکوردهای یک دامنه را در یک گزارش نشان می‌دهد.</p>
HTML,
        ],
        'en' => [
            'title' => 'DNS records and what each one does',
            'excerpt' => 'A, AAAA, CNAME, MX, TXT and NS — what each record does, when to use it, and what TTL really means.',
            'tags' => ['domains', 'DNS', 'records'],
            'content' => <<<'HTML'
<p>DNS is the phone book of the internet: it translates domain names into server addresses. Understanding the record types is essential for anyone running a website or email.</p>

<h2>A record</h2>
<p>Points a domain name at an IPv4 address. The most commonly used record.</p>
<pre><code>example.com.    A    192.0.2.10</code></pre>
<p>Subdomains work the same way:</p>
<pre><code>blog.example.com.    A    192.0.2.10</code></pre>

<h2>AAAA record</h2>
<p>Like A, but for IPv6 addresses. If your server has IPv6, create this record too.</p>

<h2>CNAME record</h2>
<p>Points one name at another name (not at an IP):</p>
<pre><code>www.example.com.    CNAME    example.com.</code></pre>
<p>The benefit: if the IP changes, you update only the target A record and every CNAME follows automatically.</p>
<p><strong>Important limitation:</strong> you cannot put a CNAME on the root domain if it also has other records such as MX. Use an A record for the root.</p>

<h2>MX record</h2>
<p>Defines which server receives email for the domain. The leading number is priority — <strong>lower means higher priority</strong>:</p>
<pre><code>example.com.    MX    10 mail.example.com.
example.com.    MX    20 backup.example.com.</code></pre>
<p>Here the first server is tried first; if it is unreachable, delivery falls back to the second.</p>

<h2>TXT record</h2>
<p>Holds free-form text, mostly used for ownership verification and email authentication:</p>
<ul>
<li><strong>SPF</strong> — declares which servers may send mail on your domain's behalf</li>
<li><strong>DKIM</strong> — cryptographic signatures for your outgoing mail</li>
<li><strong>DMARC</strong> — policy for handling forged mail</li>
<li>Domain ownership verification for services such as Google</li>
</ul>

<h2>NS record</h2>
<p>Declares which nameservers are authoritative for your domain. You set this at your domain registrar, not in the DNS panel.</p>

<h2>What is TTL?</h2>
<p>TTL means "time to live" and controls how many seconds DNS resolvers cache an answer.</p>
<ul>
<li><strong>High TTL (e.g. 86400 = one day)</strong> — better caching, less load, but changes take longer to appear.</li>
<li><strong>Low TTL (e.g. 300 = five minutes)</strong> — changes propagate much faster.</li>
</ul>
<p><strong>Practical tip:</strong> if you are planning a server migration, lower the TTL <em>several hours beforehand</em>. Then when you make the real change, propagation is nearly immediate. Raise it again once the migration is done.</p>

<h2>Why don't changes apply instantly?</h2>
<p>This delay is called DNS propagation, and caching is the cause: resolvers around the world hold the previous answer until the TTL expires. It typically takes anywhere from a few minutes to 24 hours.</p>
<p>To inspect a domain's current records, use <strong>ServerNet's full DNS lookup tool</strong>, which reports every record for a domain in one place.</p>
HTML,
        ],
        'tr' => [
            'title' => 'DNS kayıtları ve her birinin işlevi',
            'excerpt' => 'A, AAAA, CNAME, MX, TXT ve NS — her kayıt ne yapar, ne zaman kullanılır ve TTL gerçekte ne anlama gelir.',
            'tags' => ['alan adı', 'DNS', 'kayıtlar'],
            'content' => <<<'HTML'
<p>DNS, internetin telefon rehberidir: alan adlarını sunucu adreslerine çevirir. Kayıt türlerini anlamak, web sitesi veya e-posta yöneten herkes için gereklidir.</p>

<h2>A kaydı</h2>
<p>Bir alan adını IPv4 adresine yönlendirir. En çok kullanılan kayıt.</p>
<pre><code>example.com.    A    192.0.2.10</code></pre>
<p>Alt alan adları da aynı şekilde çalışır:</p>
<pre><code>blog.example.com.    A    192.0.2.10</code></pre>

<h2>AAAA kaydı</h2>
<p>A gibi, ancak IPv6 adresleri için. Sunucunuzda IPv6 varsa bu kaydı da oluşturun.</p>

<h2>CNAME kaydı</h2>
<p>Bir adı başka bir ada yönlendirir (IP'ye değil):</p>
<pre><code>www.example.com.    CNAME    example.com.</code></pre>
<p>Avantajı: IP değişirse yalnızca hedef A kaydını günceller, tüm CNAME'ler otomatik takip eder.</p>
<p><strong>Önemli kısıt:</strong> kök alan adında MX gibi başka kayıtlar da varsa CNAME kullanamazsınız. Kök için A kaydı kullanın.</p>

<h2>MX kaydı</h2>
<p>Alan adının e-postalarını hangi sunucunun alacağını belirler. Baştaki sayı önceliktir — <strong>düşük sayı daha yüksek öncelik</strong>:</p>
<pre><code>example.com.    MX    10 mail.example.com.
example.com.    MX    20 backup.example.com.</code></pre>
<p>Burada önce ilk sunucu denenir; erişilemezse teslimat ikinciye düşer.</p>

<h2>TXT kaydı</h2>
<p>Serbest metin tutar; çoğunlukla sahiplik doğrulama ve e-posta kimlik doğrulaması için kullanılır:</p>
<ul>
<li><strong>SPF</strong> — alan adınız adına hangi sunucuların posta gönderebileceğini bildirir</li>
<li><strong>DKIM</strong> — giden postalarınız için kriptografik imzalar</li>
<li><strong>DMARC</strong> — sahte postaların nasıl işleneceğine dair politika</li>
<li>Google gibi hizmetler için alan adı sahiplik doğrulaması</li>
</ul>

<h2>NS kaydı</h2>
<p>Alan adınız için hangi nameserver'ların yetkili olduğunu bildirir. Bunu DNS panelinde değil, alan adı kayıt firmanızda ayarlarsınız.</p>

<h2>TTL nedir?</h2>
<p>TTL "yaşam süresi" demektir ve DNS çözücülerin bir yanıtı kaç saniye önbellekte tutacağını belirler.</p>
<ul>
<li><strong>Yüksek TTL (örn. 86400 = bir gün)</strong> — daha iyi önbellek, daha az yük, ancak değişiklikler geç görünür.</li>
<li><strong>Düşük TTL (örn. 300 = beş dakika)</strong> — değişiklikler çok daha hızlı yayılır.</li>
</ul>
<p><strong>Pratik ipucu:</strong> sunucu taşıma planlıyorsanız TTL'yi <em>birkaç saat önceden</em> düşürün. Gerçek değişikliği yaptığınızda yayılım neredeyse anında olur. Taşıma bitince tekrar yükseltin.</p>

<h2>Değişiklikler neden anında uygulanmıyor?</h2>
<p>Bu gecikmeye DNS yayılımı denir ve nedeni önbelleklemedir: dünyadaki çözücüler TTL dolana kadar önceki yanıtı tutar. Genellikle birkaç dakika ile 24 saat arasında sürer.</p>
<p>Bir alan adının mevcut kayıtlarını incelemek için <strong>ServerNet tam DNS sorgu aracını</strong> kullanabilirsiniz; bir alan adının tüm kayıtlarını tek raporda gösterir.</p>
HTML,
        ],
    ],

    /* ==================== ایمیل ==================== */
    [
        'slug' => 'spf-dkim-dmarc-setup', 'section' => 'email',
        'icon' => 'mail', 'cover' => 'a', 'reading' => 6,
        'fa' => [
            'title' => 'SPF، DKIM و DMARC — جلوگیری از رفتن ایمیل به اسپم',
            'excerpt' => 'سه رکوردی که ثابت می‌کنند ایمیل واقعاً از طرف شماست — و بدون آن‌ها نامه‌هایتان به پوشه‌ی اسپم می‌رود.',
            'tags' => ['ایمیل', 'SPF', 'DKIM', 'DMARC'],
            'content' => <<<'HTML'
<p>اگر ایمیل‌های سازمانی شما به پوشه‌ی اسپم می‌روند، تقریباً همیشه علتش نبودِ همین سه رکورد است. این‌ها به گیرنده ثابت می‌کنند نامه واقعاً از طرف دامنه‌ی شماست.</p>

<h2>چرا لازم است؟</h2>
<p>پروتکل اصلی ایمیل هیچ مکانیزم احراز هویتی ندارد؛ یعنی هرکسی می‌تواند ایمیلی بفرستد که ظاهراً از آدرس شماست. SPF، DKIM و DMARC لایه‌هایی هستند که این حفره را می‌بندند. سرویس‌هایی مثل Gmail و Outlook بدون این رکوردها به ایمیل شما بی‌اعتماد می‌شوند.</p>

<h2>SPF — چه کسی مجاز است از طرف من ایمیل بفرستد</h2>
<p>یک رکورد TXT روی دامنه‌ی اصلی که سرورهای مجاز را فهرست می‌کند:</p>
<pre><code>example.com.    TXT    "v=spf1 include:_spf.servernet.cloud ~all"</code></pre>
<p>اجزای مهم:</p>
<ul>
<li><code>include:</code> — سرورهای یک ارائه‌دهنده را مجاز می‌کند</li>
<li><code>ip4:</code> — یک آی‌پی مشخص را مجاز می‌کند</li>
<li><code>~all</code> — بقیه «مشکوک» تلقی می‌شوند (softfail)</li>
<li><code>-all</code> — بقیه صراحتاً رد می‌شوند (سخت‌گیرانه‌تر)</li>
</ul>
<p><strong>دو خطای رایج:</strong> اول اینکه فقط <em>یک</em> رکورد SPF مجاز است؛ اگر دو تا بسازید هر دو بی‌اعتبار می‌شوند. دوم اینکه همه‌ی سرویس‌های ارسال‌کننده (مثلاً سرویس خبرنامه یا CRM) باید در همان یک رکورد گنجانده شوند.</p>

<h2>DKIM — امضای دیجیتال ایمیل</h2>
<p>DKIM هر ایمیل خروجی را با یک کلید خصوصی امضا می‌کند و کلید عمومی در DNS منتشر می‌شود. گیرنده امضا را بررسی می‌کند و مطمئن می‌شود محتوا در مسیر دستکاری نشده است.</p>
<pre><code>selector._domainkey.example.com.    TXT    "v=DKIM1; k=rsa; p=MIGfMA0..."</code></pre>
<p>مقدار کلید را سرویس ایمیل شما تولید می‌کند؛ کافی است رکورد را در DNS ثبت کنید.</p>

<h2>DMARC — تکلیف ایمیل‌های مشکوک چیست</h2>
<p>DMARC می‌گوید اگر SPF یا DKIM شکست خورد، گیرنده چه کند — و گزارش بفرستد:</p>
<pre><code>_dmarc.example.com.    TXT    "v=DMARC1; p=none; rua=mailto:dmarc@example.com"</code></pre>
<p>مقدار <code>p</code> سیاست شماست:</p>
<ul>
<li><code>p=none</code> — فقط گزارش بده، کاری نکن</li>
<li><code>p=quarantine</code> — به پوشه‌ی اسپم بفرست</li>
<li><code>p=reject</code> — کلاً تحویل نده</li>
</ul>

<h2>ترتیب درست راه‌اندازی</h2>
<p>این ترتیب مهم است؛ اگر مستقیم سراغ سخت‌گیرانه‌ترین حالت بروید ممکن است ایمیل‌های واقعی خودتان را مسدود کنید:</p>
<ol>
<li>ابتدا SPF را بسازید و چند روز صبر کنید.</li>
<li>DKIM را فعال و تست کنید.</li>
<li>DMARC را با <code>p=none</code> اضافه کنید و گزارش‌ها را بخوانید.</li>
<li>وقتی گزارش‌ها نشان داد همه‌ی ارسال‌کننده‌های واقعی شما درست احراز می‌شوند، به <code>quarantine</code> و بعد <code>reject</code> ارتقا دهید.</li>
</ol>

<h2>بررسی نتیجه</h2>
<p>پس از ثبت رکوردها، یک ایمیل آزمایشی به یک آدرس Gmail بفرستید و گزینه‌ی «نمایش اصل پیام» را باز کنید. باید در بخش احراز هویت عبارت <code>PASS</code> را برای SPF و DKIM ببینید.</p>
<p>برای دیدن رکوردهای فعلی دامنه می‌توانید از ابزار بررسی DNS سرورنت استفاده کنید.</p>
HTML,
        ],
        'en' => [
            'title' => 'SPF, DKIM and DMARC — keeping mail out of spam',
            'excerpt' => "The three records that prove your mail really is yours — without them, your messages land in spam.",
            'tags' => ['email', 'SPF', 'DKIM', 'DMARC'],
            'content' => <<<'HTML'
<p>If your business email keeps landing in spam, missing these three records is almost always the reason. They prove to the recipient that a message genuinely came from your domain.</p>

<h2>Why they matter</h2>
<p>The core email protocol has no authentication built in, which means anyone can send a message that appears to come from your address. SPF, DKIM and DMARC are the layers that close that hole. Without them, providers like Gmail and Outlook simply do not trust your mail.</p>

<h2>SPF — who may send on my behalf</h2>
<p>A TXT record on your root domain listing the authorised servers:</p>
<pre><code>example.com.    TXT    "v=spf1 include:_spf.servernet.cloud ~all"</code></pre>
<p>The key parts:</p>
<ul>
<li><code>include:</code> — authorises a provider's servers</li>
<li><code>ip4:</code> — authorises a specific IP</li>
<li><code>~all</code> — everything else is treated as suspicious (softfail)</li>
<li><code>-all</code> — everything else is explicitly rejected (stricter)</li>
</ul>
<p><strong>Two common mistakes:</strong> first, only <em>one</em> SPF record is allowed — create two and both become invalid. Second, every sending service you use (newsletter platform, CRM) must be included in that single record.</p>

<h2>DKIM — a digital signature on your mail</h2>
<p>DKIM signs each outgoing message with a private key and publishes the public key in DNS. The recipient verifies the signature and confirms the content was not altered in transit.</p>
<pre><code>selector._domainkey.example.com.    TXT    "v=DKIM1; k=rsa; p=MIGfMA0..."</code></pre>
<p>Your mail service generates the key value; you simply publish the record in DNS.</p>

<h2>DMARC — what to do with suspicious mail</h2>
<p>DMARC tells recipients what to do when SPF or DKIM fails, and asks them to send you reports:</p>
<pre><code>_dmarc.example.com.    TXT    "v=DMARC1; p=none; rua=mailto:dmarc@example.com"</code></pre>
<p>The <code>p</code> value is your policy:</p>
<ul>
<li><code>p=none</code> — report only, take no action</li>
<li><code>p=quarantine</code> — deliver to the spam folder</li>
<li><code>p=reject</code> — do not deliver at all</li>
</ul>

<h2>The right rollout order</h2>
<p>Order matters here — jumping straight to the strictest policy can block your own legitimate mail:</p>
<ol>
<li>Publish SPF first and leave it a few days.</li>
<li>Enable and test DKIM.</li>
<li>Add DMARC with <code>p=none</code> and read the reports.</li>
<li>Once the reports confirm all your genuine senders authenticate correctly, move up to <code>quarantine</code>, then <code>reject</code>.</li>
</ol>

<h2>Verifying it worked</h2>
<p>After publishing, send a test message to a Gmail address and open "show original". You should see <code>PASS</code> for both SPF and DKIM in the authentication section.</p>
<p>To review your domain's current records, use ServerNet's DNS lookup tool.</p>
HTML,
        ],
        'tr' => [
            'title' => 'SPF, DKIM ve DMARC — postaları spam\'den uzak tutmak',
            'excerpt' => 'Postanızın gerçekten size ait olduğunu kanıtlayan üç kayıt — bunlar olmadan mesajlarınız spam\'e düşer.',
            'tags' => ['e-posta', 'SPF', 'DKIM', 'DMARC'],
            'content' => <<<'HTML'
<p>Kurumsal e-postalarınız sürekli spam'e düşüyorsa, neredeyse her zaman sebep bu üç kaydın eksikliğidir. Bunlar alıcıya, mesajın gerçekten sizin alan adınızdan geldiğini kanıtlar.</p>

<h2>Neden önemliler</h2>
<p>Temel e-posta protokolünde kimlik doğrulama yoktur; yani herkes sizin adresinizden geliyormuş gibi görünen bir mesaj gönderebilir. SPF, DKIM ve DMARC bu açığı kapatan katmanlardır. Bunlar olmadan Gmail ve Outlook gibi sağlayıcılar postanıza güvenmez.</p>

<h2>SPF — kim benim adıma gönderebilir</h2>
<p>Kök alan adınızda, yetkili sunucuları listeleyen bir TXT kaydı:</p>
<pre><code>example.com.    TXT    "v=spf1 include:_spf.servernet.cloud ~all"</code></pre>
<p>Önemli bölümler:</p>
<ul>
<li><code>include:</code> — bir sağlayıcının sunucularını yetkilendirir</li>
<li><code>ip4:</code> — belirli bir IP'yi yetkilendirir</li>
<li><code>~all</code> — diğer her şey şüpheli sayılır (softfail)</li>
<li><code>-all</code> — diğer her şey açıkça reddedilir (daha katı)</li>
</ul>
<p><strong>İki yaygın hata:</strong> birincisi, yalnızca <em>bir</em> SPF kaydına izin verilir — iki tane oluşturursanız ikisi de geçersiz olur. İkincisi, kullandığınız her gönderim servisi (bülten platformu, CRM) o tek kayda dahil edilmelidir.</p>

<h2>DKIM — postanızda dijital imza</h2>
<p>DKIM her giden mesajı özel bir anahtarla imzalar ve genel anahtarı DNS'te yayınlar. Alıcı imzayı doğrular ve içeriğin yolda değiştirilmediğini teyit eder.</p>
<pre><code>selector._domainkey.example.com.    TXT    "v=DKIM1; k=rsa; p=MIGfMA0..."</code></pre>
<p>Anahtar değerini e-posta servisiniz üretir; siz yalnızca kaydı DNS'te yayınlarsınız.</p>

<h2>DMARC — şüpheli postalar ne olacak</h2>
<p>DMARC, SPF veya DKIM başarısız olduğunda alıcıların ne yapacağını söyler ve rapor göndermelerini ister:</p>
<pre><code>_dmarc.example.com.    TXT    "v=DMARC1; p=none; rua=mailto:dmarc@example.com"</code></pre>
<p><code>p</code> değeri politikanızdır:</p>
<ul>
<li><code>p=none</code> — yalnızca raporla, işlem yapma</li>
<li><code>p=quarantine</code> — spam klasörüne teslim et</li>
<li><code>p=reject</code> — hiç teslim etme</li>
</ul>

<h2>Doğru kurulum sırası</h2>
<p>Sıra önemlidir — doğrudan en katı politikaya geçmek kendi meşru postalarınızı engelleyebilir:</p>
<ol>
<li>Önce SPF'yi yayınlayın ve birkaç gün bekleyin.</li>
<li>DKIM'i etkinleştirin ve test edin.</li>
<li>DMARC'ı <code>p=none</code> ile ekleyin ve raporları okuyun.</li>
<li>Raporlar tüm gerçek göndericilerinizin doğru doğrulandığını gösterince <code>quarantine</code>'e, sonra <code>reject</code>'e geçin.</li>
</ol>

<h2>Çalıştığını doğrulama</h2>
<p>Yayınladıktan sonra bir Gmail adresine test mesajı gönderin ve "orijinali göster" seçeneğini açın. Kimlik doğrulama bölümünde hem SPF hem DKIM için <code>PASS</code> görmelisiniz.</p>
<p>Alan adınızın mevcut kayıtlarını incelemek için ServerNet DNS sorgu aracını kullanın.</p>
HTML,
        ],
    ],

    /* ==================== امنیت ==================== */
    [
        'slug' => 'installing-ssl-certificate', 'section' => 'security',
        'icon' => 'lock', 'cover' => 'b', 'reading' => 5,
        'fa' => [
            'title' => 'نصب گواهینامه SSL و انتقال سایت به HTTPS',
            'excerpt' => 'نصب SSL روی هاست، هدایت خودکار به HTTPS و رفع مشکل رایج «محتوای ناامن».',
            'tags' => ['SSL', 'HTTPS', 'امنیت'],
            'content' => <<<'HTML'
<p>SSL ارتباط بین مرورگر کاربر و سرور شما را رمزنگاری می‌کند. امروز دیگر اختیاری نیست: مرورگرها سایت‌های بدون HTTPS را «ناامن» علامت می‌زنند و گوگل هم HTTPS را یک عامل رتبه‌بندی می‌داند.</p>

<h2>نصب روی هاست</h2>
<p>روی هاست‌های سرورنت گواهینامه‌ی رایگان به‌صورت خودکار صادر و تمدید می‌شود. برای بررسی وضعیت:</p>
<ol>
<li>وارد کنترل‌پنل شوید.</li>
<li>بخش SSL/TLS را باز کنید.</li>
<li>مطمئن شوید دامنه‌ی شما در فهرست گواهینامه‌های فعال هست.</li>
</ol>
<p><strong>پیش‌نیاز مهم:</strong> دامنه باید از قبل به سرور اشاره کند. صدور گواهینامه با تأیید مالکیت از طریق DNS یا فایل انجام می‌شود؛ اگر رکورد A هنوز به سرور قبلی اشاره می‌کند، صدور شکست می‌خورد.</p>

<h2>هدایت خودکار به HTTPS</h2>
<p>نصب گواهینامه به‌تنهایی کافی نیست؛ باید ترافیک HTTP را هم به HTTPS هدایت کنید. در فایل <code>.htaccess</code> در ریشه‌ی سایت:</p>
<pre><code>RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]</code></pre>
<p>کد <code>301</code> یعنی انتقال دائمی؛ این برای سئو مهم است چون اعتبار صفحه‌ی قدیمی به آدرس جدید منتقل می‌شود.</p>

<h2>مشکل «محتوای ترکیبی»</h2>
<p>شایع‌ترین مشکل بعد از فعال‌سازی SSL این است: قفل سبز نمایش داده نمی‌شود با اینکه گواهینامه درست نصب شده. علتش این است که صفحه‌ی HTTPS شما هنوز فایل‌هایی (عکس، CSS، جاوااسکریپت) را با آدرس <code>http://</code> بارگذاری می‌کند.</p>
<p>راه‌حل: همه‌ی آدرس‌های داخلی را به <code>https://</code> یا به آدرس نسبی تغییر دهید. برای پیدا کردن موارد مشکل‌دار، در مرورگر کنسول توسعه‌دهنده را باز کنید؛ هشدارهای mixed content آنجا فهرست می‌شوند.</p>
<p>در وردپرس، علاوه بر این باید آدرس سایت را در تنظیمات به <code>https://</code> به‌روز کنید و آدرس‌های قدیمی داخل دیتابیس را هم اصلاح کنید.</p>

<h2>پس از فعال‌سازی چه کنیم</h2>
<ul>
<li>در Google Search Console نسخه‌ی HTTPS را به‌عنوان دارایی جدید ثبت کنید.</li>
<li>آدرس سایت را در نقشه‌ی سایت و فایل robots به‌روز کنید.</li>
<li>لینک‌های داخلی سایت را بررسی کنید تا مستقیماً به HTTPS اشاره کنند (نه از طریق ریدایرکت).</li>
</ul>

<h2>بررسی صحت نصب</h2>
<p>با <strong>ابزار بررسی SSL سرورنت</strong> می‌توانید اعتبار گواهینامه، تاریخ انقضا و کامل بودن زنجیره‌ی گواهی را بررسی کنید. زنجیره‌ی ناقص باعث می‌شود سایت در بعضی مرورگرها یا اپلیکیشن‌های موبایل خطا بدهد در حالی که در مرورگر شما درست کار می‌کند.</p>
HTML,
        ],
        'en' => [
            'title' => 'Installing an SSL certificate and moving to HTTPS',
            'excerpt' => 'Install SSL on your hosting, force HTTPS, and fix the common "mixed content" problem.',
            'tags' => ['SSL', 'HTTPS', 'security'],
            'content' => <<<'HTML'
<p>SSL encrypts the connection between a visitor's browser and your server. It is no longer optional: browsers flag sites without HTTPS as "not secure", and Google treats HTTPS as a ranking signal.</p>

<h2>Installing on hosting</h2>
<p>On ServerNet hosting, a free certificate is issued and renewed automatically. To check its status:</p>
<ol>
<li>Sign in to your control panel.</li>
<li>Open the SSL/TLS section.</li>
<li>Confirm your domain appears in the list of active certificates.</li>
</ol>
<p><strong>Important prerequisite:</strong> the domain must already point at the server. Issuance works by verifying ownership over DNS or a file, so if your A record still points at your old host, issuance will fail.</p>

<h2>Forcing HTTPS</h2>
<p>Installing the certificate alone is not enough — you also need to redirect HTTP traffic. In the <code>.htaccess</code> file at your site root:</p>
<pre><code>RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]</code></pre>
<p>The <code>301</code> means a permanent redirect, which matters for SEO because it passes the old URL's authority to the new one.</p>

<h2>The "mixed content" problem</h2>
<p>This is the most common issue after enabling SSL: the padlock does not appear even though the certificate installed correctly. The cause is that your HTTPS page still loads some assets (images, CSS, JavaScript) over <code>http://</code>.</p>
<p>The fix: change all internal URLs to <code>https://</code> or to relative paths. To find the offenders, open your browser's developer console — mixed content warnings are listed there.</p>
<p>On WordPress you must additionally update the site address in settings to <code>https://</code> and correct old URLs stored in the database.</p>

<h2>After enabling HTTPS</h2>
<ul>
<li>Add the HTTPS version as a new property in Google Search Console.</li>
<li>Update your sitemap and robots file with the new URL.</li>
<li>Check internal links so they point straight to HTTPS rather than going through a redirect.</li>
</ul>

<h2>Verifying the installation</h2>
<p>Use <strong>ServerNet's SSL checker</strong> to confirm the certificate is valid, check its expiry date, and verify the certificate chain is complete. An incomplete chain causes errors in some browsers and mobile apps even when it works fine in yours.</p>
HTML,
        ],
        'tr' => [
            'title' => "SSL sertifikası kurma ve HTTPS'e geçiş",
            'excerpt' => 'Hosting\'inize SSL kurun, HTTPS\'i zorunlu kılın ve yaygın "karışık içerik" sorununu çözün.',
            'tags' => ['SSL', 'HTTPS', 'güvenlik'],
            'content' => <<<'HTML'
<p>SSL, ziyaretçinin tarayıcısı ile sunucunuz arasındaki bağlantıyı şifreler. Artık isteğe bağlı değil: tarayıcılar HTTPS'siz siteleri "güvenli değil" olarak işaretler ve Google HTTPS'i bir sıralama sinyali sayar.</p>

<h2>Hosting üzerine kurulum</h2>
<p>ServerNet hosting'de ücretsiz sertifika otomatik olarak verilir ve yenilenir. Durumunu kontrol etmek için:</p>
<ol>
<li>Kontrol panelinize giriş yapın.</li>
<li>SSL/TLS bölümünü açın.</li>
<li>Alan adınızın aktif sertifikalar listesinde göründüğünü doğrulayın.</li>
</ol>
<p><strong>Önemli ön koşul:</strong> alan adı zaten sunucuyu göstermelidir. Sertifika verme işlemi DNS veya dosya üzerinden sahiplik doğrulamasıyla çalışır; A kaydınız hâlâ eski sunucuyu gösteriyorsa işlem başarısız olur.</p>

<h2>HTTPS'i zorunlu kılma</h2>
<p>Sertifikayı kurmak tek başına yeterli değildir — HTTP trafiğini de yönlendirmelisiniz. Site kökünüzdeki <code>.htaccess</code> dosyasında:</p>
<pre><code>RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]</code></pre>
<p><code>301</code> kalıcı yönlendirme demektir; SEO açısından önemlidir çünkü eski adresin otoritesini yenisine aktarır.</p>

<h2>"Karışık içerik" sorunu</h2>
<p>SSL etkinleştirdikten sonraki en yaygın sorun budur: sertifika doğru kurulmuş olmasına rağmen kilit simgesi görünmez. Nedeni, HTTPS sayfanızın hâlâ bazı dosyaları (görsel, CSS, JavaScript) <code>http://</code> üzerinden yüklemesidir.</p>
<p>Çözüm: tüm iç adresleri <code>https://</code> veya göreli yollara çevirin. Sorunlu olanları bulmak için tarayıcınızın geliştirici konsolunu açın — karışık içerik uyarıları orada listelenir.</p>
<p>WordPress'te ayrıca ayarlardaki site adresini <code>https://</code> olarak güncellemeli ve veritabanındaki eski adresleri de düzeltmelisiniz.</p>

<h2>HTTPS'i etkinleştirdikten sonra</h2>
<ul>
<li>Google Search Console'a HTTPS sürümünü yeni bir mülk olarak ekleyin.</li>
<li>Site haritanızı ve robots dosyanızı yeni adresle güncelleyin.</li>
<li>İç bağlantıları kontrol edin; yönlendirme üzerinden değil doğrudan HTTPS'e işaret etmeliler.</li>
</ul>

<h2>Kurulumu doğrulama</h2>
<p><strong>ServerNet SSL denetleyicisini</strong> kullanarak sertifikanın geçerliliğini, son kullanma tarihini ve sertifika zincirinin eksiksiz olduğunu doğrulayın. Eksik zincir, sizin tarayıcınızda sorunsuz çalışsa bile bazı tarayıcı ve mobil uygulamalarda hataya yol açar.</p>
HTML,
        ],
    ],

    /* ==================== ابزارها ==================== */
    [
        'slug' => 'using-dns-lookup-tool', 'section' => 'tools',
        'icon' => 'search', 'cover' => 'c', 'reading' => 4,
        'fa' => [
            'title' => 'کار با ابزار بررسی کامل DNS سرورنت',
            'excerpt' => 'گرفتن گزارش کامل رکوردهای یک دامنه در چند ثانیه — و تفسیر نتایج برای عیب‌یابی.',
            'tags' => ['ابزار', 'DNS', 'عیب‌یابی'],
            'content' => <<<'HTML'
<p>ابزار بررسی کامل DNS سرورنت همه‌ی رکوردهای یک دامنه را در یک گزارش جمع می‌کند. رایگان است و نیازی به ثبت‌نام ندارد.</p>

<h2>چطور استفاده کنیم</h2>
<ol>
<li>به بخش ابزارهای تخصصی سایت بروید و «بررسی کامل DNS» را باز کنید.</li>
<li>نام دامنه را بدون <code>http://</code> و بدون <code>www</code> وارد کنید.</li>
<li>گزارش را دریافت کنید.</li>
</ol>
<p>ابزار از طریق DNS-over-HTTPS کوئری می‌زند، یعنی نتیجه مستقل از تنظیمات DNS کامپیوتر شماست.</p>

<h2>تفسیر گزارش</h2>
<h3>رکورد A و AAAA</h3>
<p>آی‌پی سروری که دامنه به آن اشاره می‌کند. اگر تازه سرور را عوض کرده‌اید و اینجا هنوز آی‌پی قدیمی را می‌بینید، یعنی انتشار DNS کامل نشده است.</p>

<h3>رکورد NS</h3>
<p>نیم‌سرورهای فعال دامنه. اگر با آنچه در پنل ثبت‌کننده تنظیم کرده‌اید فرق دارد، تغییر نیم‌سرور هنوز اعمال نشده است.</p>

<h3>رکورد MX</h3>
<p>سرور دریافت ایمیل. اگر ایمیل‌های دامنه‌تان نمی‌رسد، اولین جایی که باید نگاه کنید همین‌جاست — نبودِ رکورد MX یعنی هیچ ایمیلی تحویل نمی‌شود.</p>

<h3>رکورد TXT</h3>
<p>اینجا می‌توانید SPF، DKIM و DMARC خود را ببینید. اگر مشکل رفتن ایمیل به اسپم دارید، بررسی کنید که <em>فقط یک</em> رکورد SPF داشته باشید.</p>

<h2>سناریوهای رایج عیب‌یابی</h2>
<ul>
<li><strong>سایت باز نمی‌شود</strong> — رکورد A را ببینید؛ باید آی‌پی سرور فعلی شما باشد.</li>
<li><strong>ایمیل دریافت نمی‌شود</strong> — رکورد MX را بررسی کنید.</li>
<li><strong>ایمیل ارسالی به اسپم می‌رود</strong> — رکوردهای TXT مربوط به SPF و DMARC را بررسی کنید.</li>
<li><strong>بعد از تغییر نیم‌سرور هیچ‌چیز عوض نشد</strong> — رکورد NS را ببینید؛ اگر هنوز قدیمی است باید صبر کنید.</li>
</ul>

<h2>ابزارهای مرتبط</h2>
<p>در کنار این ابزار، <strong>بررسی شبکه و امنیت</strong> سرورنت هم موجود است که وضعیت گواهینامه SSL، باز بودن پورت‌ها، پینگ و DNSSEC را بررسی می‌کند. برای عیب‌یابی کامل یک دامنه معمولاً هر دو ابزار لازم می‌شود.</p>
HTML,
        ],
        'en' => [
            'title' => "Using ServerNet's full DNS lookup tool",
            'excerpt' => "Get a complete record report for any domain in seconds — and read the results for troubleshooting.",
            'tags' => ['tools', 'DNS', 'troubleshooting'],
            'content' => <<<'HTML'
<p>ServerNet's full DNS lookup tool collects every record for a domain into one report. It is free and requires no signup.</p>

<h2>How to use it</h2>
<ol>
<li>Open the Toolbox section of the site and choose "Full DNS Lookup".</li>
<li>Enter the domain name without <code>http://</code> and without <code>www</code>.</li>
<li>Read the report.</li>
</ol>
<p>The tool queries over DNS-over-HTTPS, so results are independent of your own computer's DNS settings.</p>

<h2>Reading the report</h2>
<h3>A and AAAA records</h3>
<p>The server IP the domain points at. If you recently changed servers and still see the old IP here, DNS propagation has not finished.</p>

<h3>NS records</h3>
<p>The domain's active nameservers. If these differ from what you set at your registrar, the nameserver change has not taken effect yet.</p>

<h3>MX records</h3>
<p>Your mail server. If mail for your domain is not arriving, this is the first place to look — no MX record means no mail is delivered at all.</p>

<h3>TXT records</h3>
<p>Here you can see your SPF, DKIM and DMARC entries. If mail is landing in spam, check that you have exactly <em>one</em> SPF record.</p>

<h2>Common troubleshooting scenarios</h2>
<ul>
<li><strong>Site will not load</strong> — check the A record; it should be your current server IP.</li>
<li><strong>No incoming mail</strong> — check the MX record.</li>
<li><strong>Outgoing mail goes to spam</strong> — check the TXT records for SPF and DMARC.</li>
<li><strong>Nothing changed after switching nameservers</strong> — check the NS record; if it is still the old one, you need to wait.</li>
</ul>

<h2>Related tools</h2>
<p>Alongside this, ServerNet offers a <strong>Network &amp; Security check</strong> that inspects SSL certificate status, open ports, ping and DNSSEC. Fully diagnosing a domain usually takes both tools.</p>
HTML,
        ],
        'tr' => [
            'title' => 'ServerNet tam DNS sorgu aracını kullanma',
            'excerpt' => 'Herhangi bir alan adı için saniyeler içinde eksiksiz kayıt raporu alın — ve sonuçları sorun gidermek için okuyun.',
            'tags' => ['araçlar', 'DNS', 'sorun giderme'],
            'content' => <<<'HTML'
<p>ServerNet tam DNS sorgu aracı, bir alan adının tüm kayıtlarını tek raporda toplar. Ücretsizdir ve kayıt gerektirmez.</p>

<h2>Nasıl kullanılır</h2>
<ol>
<li>Sitenin Araç Kutusu bölümünü açın ve "Tam DNS Sorgu"yu seçin.</li>
<li>Alan adını <code>http://</code> ve <code>www</code> olmadan girin.</li>
<li>Raporu okuyun.</li>
</ol>
<p>Araç DNS-over-HTTPS üzerinden sorgular, bu yüzden sonuçlar kendi bilgisayarınızın DNS ayarlarından bağımsızdır.</p>

<h2>Raporu okumak</h2>
<h3>A ve AAAA kayıtları</h3>
<p>Alan adının işaret ettiği sunucu IP'si. Yakın zamanda sunucu değiştirdiyseniz ve burada hâlâ eski IP görünüyorsa DNS yayılımı tamamlanmamıştır.</p>

<h3>NS kayıtları</h3>
<p>Alan adının aktif nameserver'ları. Bunlar kayıt firmanızda ayarladıklarınızdan farklıysa nameserver değişikliği henüz etkin değildir.</p>

<h3>MX kayıtları</h3>
<p>Posta sunucunuz. Alan adınıza posta ulaşmıyorsa ilk bakılacak yer burasıdır — MX kaydı yoksa hiç posta teslim edilmez.</p>

<h3>TXT kayıtları</h3>
<p>SPF, DKIM ve DMARC girdilerinizi burada görebilirsiniz. Postalar spam'e düşüyorsa tam olarak <em>bir</em> SPF kaydınız olduğunu kontrol edin.</p>

<h2>Yaygın sorun giderme senaryoları</h2>
<ul>
<li><strong>Site açılmıyor</strong> — A kaydını kontrol edin; mevcut sunucu IP'niz olmalı.</li>
<li><strong>Gelen posta yok</strong> — MX kaydını kontrol edin.</li>
<li><strong>Giden posta spam'e düşüyor</strong> — SPF ve DMARC için TXT kayıtlarını kontrol edin.</li>
<li><strong>Nameserver değiştikten sonra hiçbir şey değişmedi</strong> — NS kaydını kontrol edin; hâlâ eskiyse beklemeniz gerekir.</li>
</ul>

<h2>İlgili araçlar</h2>
<p>Bunun yanında ServerNet, SSL sertifika durumu, açık portlar, ping ve DNSSEC'i inceleyen bir <strong>Ağ &amp; Güvenlik kontrolü</strong> de sunar. Bir alan adını tam teşhis etmek genellikle her iki aracı gerektirir.</p>
HTML,
        ],
    ],

    /* ==================== حساب و مالی ==================== */
    [
        'slug' => 'renewing-and-upgrading-services', 'section' => 'billing',
        'icon' => 'coins', 'cover' => 'd', 'reading' => 4,
        'fa' => [
            'title' => 'تمدید، ارتقا و مدیریت سرویس‌ها',
            'excerpt' => 'تمدید به‌موقع، ارتقای پلن بدون قطعی، و اینکه اگر سرویس منقضی شود چه اتفاقی می‌افتد.',
            'tags' => ['مالی', 'تمدید', 'ارتقا'],
            'content' => <<<'HTML'
<p>مدیریت سرویس‌ها از ناحیه کاربری انجام می‌شود. این راهنما مهم‌ترین کارهای مالی و نکته‌های زمان‌بندی را توضیح می‌دهد.</p>

<h2>تمدید سرویس</h2>
<p>پیش از انقضا برای شما یادآوری ایمیل می‌شود. برای تمدید:</p>
<ol>
<li>وارد ناحیه کاربری شوید.</li>
<li>به بخش سرویس‌ها بروید و سرویس مورد نظر را انتخاب کنید.</li>
<li>گزینه‌ی تمدید را بزنید و دوره را انتخاب کنید.</li>
<li>فاکتور صادرشده را پرداخت کنید.</li>
</ol>
<p><strong>توصیه:</strong> چند روز زودتر تمدید کنید. تمدید زودهنگام باعث از دست رفتن روزهای باقی‌مانده نمی‌شود — دوره‌ی جدید از تاریخ انقضای فعلی ادامه پیدا می‌کند.</p>

<h2>اگر سرویس منقضی شود چه می‌شود؟</h2>
<p>انقضا در چند مرحله اتفاق می‌افتد:</p>
<ul>
<li><strong>مرحله‌ی مهلت</strong> — سرویس موقتاً معلق می‌شود اما داده‌ها دست‌نخورده باقی می‌مانند. با پرداخت فاکتور، سرویس بلافاصله برمی‌گردد.</li>
<li><strong>مرحله‌ی حذف</strong> — پس از پایان مهلت، داده‌ها حذف می‌شوند و بازگردانی ممکن نیست.</li>
</ul>
<p><strong>درباره‌ی دامنه دقت کنید:</strong> قوانین انقضای دامنه را ثبت‌کننده‌ی بین‌المللی تعیین می‌کند نه ما. بعد از انقضا دامنه وارد دوره‌ی بازیابی می‌شود که هزینه‌ی آن به‌مراتب بیشتر از تمدید عادی است، و پس از آن دامنه آزاد می‌شود و هرکسی می‌تواند ثبتش کند. دامنه را هرگز نگذارید منقضی شود.</p>

<h2>ارتقای پلن</h2>
<p>اگر منابع فعلی کافی نیست، می‌توانید بدون از دست دادن داده‌ها ارتقا دهید:</p>
<ul>
<li><strong>هاست</strong> — ارتقا معمولاً بدون قطعی انجام می‌شود.</li>
<li><strong>سرور مجازی</strong> — افزایش منابع معمولاً یک ری‌استارت کوتاه لازم دارد.</li>
</ul>
<p>هزینه به‌صورت تناسبی محاسبه می‌شود؛ یعنی فقط مابه‌التفاوت باقی‌مانده‌ی دوره را می‌پردازید.</p>
<p>پیش از ارتقا، اگر مطمئن نیستید کدام پلن مناسب است، تیکت بزنید. کارشناسان ما می‌توانند بر اساس مصرف واقعی سرویس‌تان پلن مناسب را پیشنهاد دهند — گاهی مشکل کندی از منابع نیست و ارتقا کمکی نمی‌کند.</p>

<h2>ثبت تیکت پشتیبانی</h2>
<p>برای مسائل فنی، از ناحیه کاربری تیکت ثبت کنید. برای گرفتن سریع‌ترین پاسخ:</p>
<ul>
<li>دپارتمان درست را انتخاب کنید (فنی، مالی یا فروش).</li>
<li>نام سرویس یا دامنه‌ی مربوطه را بنویسید.</li>
<li>متن دقیق خطا را کپی کنید و اسکرین‌شات بگذارید.</li>
<li>بنویسید چه کاری انجام دادید که مشکل شروع شد.</li>
</ul>
<p><strong>هشدار امنیتی:</strong> رمز عبور خود را هرگز در تیکت ننویسید. تیم پشتیبانی سرورنت هیچ‌وقت رمز عبور شما را نمی‌پرسد؛ اگر کسی چنین درخواستی کرد، به آن پاسخ ندهید و موضوع را به ما گزارش دهید.</p>
HTML,
        ],
        'en' => [
            'title' => 'Renewing, upgrading and managing services',
            'excerpt' => 'Renew on time, upgrade without downtime, and understand exactly what happens if a service expires.',
            'tags' => ['billing', 'renewal', 'upgrade'],
            'content' => <<<'HTML'
<p>Services are managed from your client area. This guide covers the main billing tasks and the timing details that matter.</p>

<h2>Renewing a service</h2>
<p>You receive email reminders before expiry. To renew:</p>
<ol>
<li>Sign in to your client area.</li>
<li>Go to Services and select the service.</li>
<li>Choose the renew option and pick a term.</li>
<li>Pay the generated invoice.</li>
</ol>
<p><strong>Recommendation:</strong> renew a few days early. Renewing early does not waste your remaining days — the new term continues from the current expiry date.</p>

<h2>What happens if a service expires?</h2>
<p>Expiry happens in stages:</p>
<ul>
<li><strong>Grace period</strong> — the service is suspended but your data is untouched. Paying the invoice restores it immediately.</li>
<li><strong>Termination</strong> — after the grace period, data is deleted and cannot be recovered.</li>
</ul>
<p><strong>Note on domains:</strong> domain expiry rules are set by the international registry, not by us. After expiry a domain enters a redemption period that costs considerably more than a normal renewal, and after that it is released for anyone to register. Never let a domain lapse.</p>

<h2>Upgrading a plan</h2>
<p>If your current resources are no longer enough, you can upgrade without losing data:</p>
<ul>
<li><strong>Hosting</strong> — upgrades are usually applied with no downtime.</li>
<li><strong>VPS</strong> — increasing resources normally requires a brief restart.</li>
</ul>
<p>Pricing is prorated, so you only pay the difference for the remainder of your term.</p>
<p>If you are not sure which plan fits, open a ticket before upgrading. Our specialists can suggest the right plan based on your actual usage — sometimes slowness is not a resource problem and an upgrade will not help.</p>

<h2>Opening a support ticket</h2>
<p>For technical issues, open a ticket from the client area. To get the fastest answer:</p>
<ul>
<li>Pick the correct department (technical, billing or sales).</li>
<li>State the service or domain involved.</li>
<li>Copy the exact error text and attach a screenshot.</li>
<li>Describe what you did when the problem started.</li>
</ul>
<p><strong>Security note:</strong> never put your password in a ticket. ServerNet support will never ask for your password; if anyone does, do not reply and report it to us.</p>
HTML,
        ],
        'tr' => [
            'title' => 'Hizmetleri yenileme, yükseltme ve yönetme',
            'excerpt' => 'Zamanında yenileyin, kesintisiz yükseltin ve bir hizmet sona ererse tam olarak ne olacağını öğrenin.',
            'tags' => ['faturalama', 'yenileme', 'yükseltme'],
            'content' => <<<'HTML'
<p>Hizmetler müşteri panelinizden yönetilir. Bu kılavuz temel faturalama işlemlerini ve önemli zamanlama ayrıntılarını anlatır.</p>

<h2>Hizmet yenileme</h2>
<p>Süre dolmadan önce e-posta hatırlatmaları alırsınız. Yenilemek için:</p>
<ol>
<li>Müşteri panelinize giriş yapın.</li>
<li>Hizmetler bölümüne gidin ve hizmeti seçin.</li>
<li>Yenileme seçeneğini seçin ve bir süre belirleyin.</li>
<li>Oluşturulan faturayı ödeyin.</li>
</ol>
<p><strong>Öneri:</strong> birkaç gün erken yenileyin. Erken yenileme kalan günlerinizi boşa harcamaz — yeni dönem mevcut bitiş tarihinden devam eder.</p>

<h2>Hizmet sona ererse ne olur?</h2>
<p>Sona erme aşamalı gerçekleşir:</p>
<ul>
<li><strong>Ek süre</strong> — hizmet askıya alınır ancak verileriniz korunur. Faturayı ödemek hizmeti hemen geri getirir.</li>
<li><strong>Sonlandırma</strong> — ek süre bittikten sonra veriler silinir ve kurtarılamaz.</li>
</ul>
<p><strong>Alan adları hakkında:</strong> alan adı sona erme kuralları bizim değil, uluslararası kayıt kuruluşunun belirlediği kurallardır. Süre dolduktan sonra alan adı, normal yenilemeden çok daha pahalı bir kurtarma dönemine girer; ardından herkesin kaydedebileceği şekilde serbest bırakılır. Alan adınızın süresinin dolmasına asla izin vermeyin.</p>

<h2>Paket yükseltme</h2>
<p>Mevcut kaynaklarınız yetmiyorsa veri kaybetmeden yükseltebilirsiniz:</p>
<ul>
<li><strong>Hosting</strong> — yükseltmeler genellikle kesintisiz uygulanır.</li>
<li><strong>VPS</strong> — kaynak artırımı normalde kısa bir yeniden başlatma gerektirir.</li>
</ul>
<p>Fiyatlandırma orantılıdır, yani yalnızca döneminizin kalanı için farkı ödersiniz.</p>
<p>Hangi paketin uygun olduğundan emin değilseniz yükseltmeden önce destek talebi açın. Uzmanlarımız gerçek kullanımınıza göre doğru paketi önerebilir — bazen yavaşlık bir kaynak sorunu değildir ve yükseltme işe yaramaz.</p>

<h2>Destek talebi açma</h2>
<p>Teknik sorunlar için müşteri panelinden talep açın. En hızlı yanıtı almak için:</p>
<ul>
<li>Doğru departmanı seçin (teknik, faturalama veya satış).</li>
<li>İlgili hizmeti veya alan adını belirtin.</li>
<li>Tam hata metnini kopyalayın ve ekran görüntüsü ekleyin.</li>
<li>Sorun başladığında ne yaptığınızı açıklayın.</li>
</ul>
<p><strong>Güvenlik notu:</strong> parolanızı asla bir destek talebine yazmayın. ServerNet desteği parolanızı asla istemez; biri isterse yanıt vermeyin ve durumu bize bildirin.</p>
HTML,
        ],
    ],

];
