@php
  // Describe the order flow without turning catalog families or driver capabilities
  // into promises for every plan. Keep the actual SSH endpoint in the service panel.
  $hvLinux = lc([
      'fa' => [
          'title' => 'سرور لینوکس ساعتی؛ از انتخاب پلن تا اتصال SSH',
          'intro' => 'برای تمرین لینوکس، آزمودن نسخهٔ تازهٔ برنامه یا اجرای یک اسکریپت کوتاه، مدت رزرو و نیاز نرم‌افزار را از ابتدا مشخص کنید. این چهار گام کمک می‌کنند پلن مناسب را انتخاب کنید، به سرور متصل شوید و پس از پایان کار هزینهٔ ادامه‌دار نداشته باشید.',
          'use_title' => 'منابع را با کاری که می‌خواهید انجام دهید تطبیق دهید',
          'use_text' => 'برای تست یک API، نصب بسته‌ها یا اجرای کانتینر، حداقل CPU و RAM موردنیاز همان نرم‌افزار را بررسی کنید؛ برای دیتابیس، لاگ و خروجی پردازش نیز فضای دیسک در نظر بگیرید. هزینه را برای تمام ساعت‌های رزرو برآورد کنید، حتی ساعت‌هایی که ترمینال بسته است. برای سرویس دائمی، نرخ ماهانهٔ همان منابع را هم مقایسه کنید.',
          'hosting_text' => 'اگر فقط یک سایت Django یا Flask با WSGI دارید، پیش از پذیرفتن مدیریت سیستم‌عامل، شرایط',
          'hosting_link' => 'هاست پایتون',
          'hosting_after' => 'را بررسی کنید؛ پردازش دائمی، worker و تنظیمات سیستمی نیاز جداگانه‌اند.',
          'os_title' => 'توزیع و نسخهٔ لینوکس را در همان پلن بررسی کنید',
          'os_text' => 'ابتدا مکان و منابع را در فروشگاه انتخاب کنید، سپس سیستم‌عامل‌های قابل انتخاب آن پلن را ببینید. اگر Ubuntu یا Debian ارائه می‌شود، نسخه‌ای را انتخاب کنید که نرم‌افزارتان پشتیبانی می‌کند و معماری آن با برنامه سازگار است. وجود نام یک توزیع در راهنما به معنی موجودبودن همهٔ نسخه‌ها در همهٔ مکان‌ها نیست.',
          'os_note' => 'کنترل‌پنل، دسکتاپ گرافیکی و مجوز نرم‌افزار را جزو پیش‌فرض سرور لینوکس فرض نکنید؛ جزئیات گزینهٔ انتخابی را پیش از سفارش بخوانید.',
          'os_link' => 'راهنمای انتخاب سرور مجازی لینوکس',
          'ssh_title' => 'فرمان اتصال را از صفحهٔ سرویس بردارید',
          'ssh_text' => 'پس از تحویل سرور لینوکس، فرمان SSH، نشانی و اطلاعات ورود همان سرویس را در پنل ببینید. از پورت و نام کاربری همان فرمان استفاده کنید؛ اطلاعات اتصال SSH ممکن است با نشانی وب‌سایت متفاوت باشد. قالب زیر فقط شکل فرمان است و PORT، USER و HOST باید با اطلاعات سرویس جایگزین شوند.',
          'ssh_auth' => 'ورود با رمز یا کلید به امکانات پلن و روش انتخاب‌شده در سفارش بستگی دارد. اگر نصب کلید عمومی پشتیبانی می‌شود و آن را انتخاب کرده‌اید، کلید خصوصی متناظر را روی دستگاه خود نگه دارید. کلید خصوصی را در فرم سفارش یا تیکت نفرستید.',
          'ssh_link' => 'آموزش اتصال SSH در ویندوز، macOS و لینوکس',
          'end_title' => 'خروجی و بکاپ را بردارید، سپس سرویس را حذف کنید',
          'end_text' => 'فایل‌ها، خروجی دیتابیس و تنظیمات لازم برای اجرای دوباره را خارج از سرور ذخیره کنید. پیش از حذف، بازشدن فایل‌ها و امکان بازیابی بکاپ را بررسی کنید؛ نسخه‌ای که فقط روی دیسک همان VPS مانده، پس از حذف قابل اتکا نیست. سپس فرایند تأیید حذف سرویس را در پنل کامل کنید.',
          'end_note' => 'بستن SSH یا خاموش‌کردن عادی VPS صورتحساب را پایان نمی‌دهد. حذف سرویس برای پایان هزینه است و دادهٔ سرور را از بین می‌برد؛ آن را فقط پس از گرفتن نسخهٔ لازم انجام دهید.',
          'order' => 'بررسی پلن‌ها و سیستم‌عامل‌های قابل سفارش ساعتی',
      ],
      'en' => [
          'title' => 'Hourly Linux VPS: choosing a plan and connecting over SSH',
          'intro' => 'For Linux practice, a release test or a short script, define the software requirements and reservation time first. These four steps help you choose a suitable plan, connect to it and end further charges after saving your work.',
          'use_title' => 'Match the resources to the job',
          'use_text' => 'For an API test, package installation or a container, check the software’s CPU and RAM requirements. Allow disk space for databases, logs and output files. Estimate all reserved hours, including time when your terminal is closed. For a service that runs continuously, compare the monthly price for the same resources.',
          'hosting_text' => 'For a Django or Flask website using WSGI, review',
          'hosting_link' => 'Python hosting',
          'hosting_after' => 'before taking on operating system administration. Persistent processes, workers and system configuration have separate requirements.',
          'os_title' => 'Check the Linux distribution and version for your plan',
          'os_text' => 'Choose the location and resources in the store, then review the operating systems available for that plan. If Ubuntu or Debian is offered, choose a version supported by your software and a compatible architecture. A distribution mentioned in a guide does not mean every version is available in every location.',
          'os_note' => 'Do not assume a control panel, graphical desktop or software licence is included with Linux. Review the details of your selected option before ordering.',
          'os_link' => 'Linux VPS selection guide',
          'ssh_title' => 'Use the connection command from your service page',
          'ssh_text' => 'Once your Linux server is delivered, find its SSH command, address and login details in the panel. Use the username and port in that command; the SSH connection details may differ from the website address. This example shows the format only: replace PORT, USER and HOST with your service details.',
          'ssh_auth' => 'Password or key login depends on the plan’s capabilities and the method selected when ordering. If public-key installation is supported and you selected it, keep the matching private key on your device. Never send the private key in an order form or support ticket.',
          'ssh_link' => 'Connect over SSH from Windows, macOS or Linux',
          'end_title' => 'Export your results and backup, then delete the service',
          'end_text' => 'Save files, a database export and the configuration needed to run the app again outside the server. Before deletion, check that the files open and the backup can be restored. A copy kept only on the same VPS disk cannot protect your data after deletion. Then complete the service deletion confirmation in the panel.',
          'end_note' => 'Closing SSH or powering off a regular VPS does not end billing. Deleting the service ends charges and removes the server’s data, so do this only after saving what you need.',
          'order' => 'Review hourly plans and available operating systems',
      ],
      'tr' => [
          'title' => 'Saatlik Linux VPS: plan seçiminden SSH bağlantısına',
          'intro' => 'Linux öğrenmek, yeni bir sürümü test etmek veya kısa bir betik çalıştırmak için önce yazılım gereksinimlerini ve ayıracağınız süreyi belirleyin. Bu dört adım uygun planı seçmenize, sunucuya bağlanmanıza ve iş bitince ücretlendirmeyi sonlandırmanıza yardımcı olur.',
          'use_title' => 'Kaynakları yapacağınız işe göre seçin',
          'use_text' => 'API testi, paket kurulumu veya konteyner çalıştırma için yazılımın CPU ve RAM gereksinimlerini kontrol edin. Veritabanı, günlükler ve çıktı dosyaları için disk alanı ayırın. Terminaliniz kapalıyken geçen süre dahil, kaynakların ayrıldığı tüm saatleri hesaba katın. Sürekli çalışan bir hizmet için aynı kaynakların aylık fiyatını da karşılaştırın.',
          'hosting_text' => 'WSGI kullanan bir Django veya Flask sitesi için işletim sistemi yönetimini üstlenmeden önce',
          'hosting_link' => 'Python hosting',
          'hosting_after' => 'koşullarını inceleyin. Sürekli işlemler, worker süreçleri ve sistem yapılandırması ayrı gereksinimlerdir.',
          'os_title' => 'Linux dağıtımını ve sürümünü seçtiğiniz planda kontrol edin',
          'os_text' => 'Mağazada konumu ve kaynakları seçtikten sonra o planda kullanılabilen işletim sistemlerini inceleyin. Ubuntu veya Debian sunuluyorsa yazılımınızın desteklediği bir sürümü ve uyumlu mimariyi seçin. Rehberde bir dağıtımın adının geçmesi, her sürümün her konumda mevcut olduğu anlamına gelmez.',
          'os_note' => 'Kontrol paneli, grafik masaüstü veya yazılım lisansının Linux sunucuya dahil olduğunu varsaymayın. Sipariş vermeden önce seçtiğiniz seçeneğin ayrıntılarını okuyun.',
          'os_link' => 'Linux VPS seçim rehberi',
          'ssh_title' => 'Hizmet sayfasındaki bağlantı komutunu kullanın',
          'ssh_text' => 'Linux sunucunuz teslim edildikten sonra SSH komutunu, adresi ve giriş bilgilerini panelde bulun. Komuttaki kullanıcı adını ve portu kullanın; SSH bağlantı bilgileri web sitesi adresinden farklı olabilir. Aşağıdaki örnek yalnızca komut biçimidir: PORT, USER ve HOST yerine hizmet bilgilerinizi yazın.',
          'ssh_auth' => 'Parola veya anahtar ile giriş, planın özelliklerine ve siparişte seçilen yönteme bağlıdır. Açık anahtar kurulumu destekleniyorsa ve bunu seçtiyseniz, eşleşen özel anahtarı kendi cihazınızda tutun. Özel anahtarı sipariş formu veya destek talebiyle göndermeyin.',
          'ssh_link' => 'Windows, macOS ve Linux üzerinden SSH bağlantısı',
          'end_title' => 'Çıktıları ve yedeği dışarı aktarın, ardından hizmeti silin',
          'end_text' => 'Dosyaları, veritabanı dışa aktarımını ve uygulamayı yeniden çalıştırmak için gereken yapılandırmayı sunucu dışında saklayın. Silmeden önce dosyaların açıldığını ve yedeğin geri yüklenebildiğini kontrol edin. Yalnızca aynı VPS diskinde kalan kopya, silme sonrasında veriyi koruyamaz. Ardından paneldeki hizmet silme onayını tamamlayın.',
          'end_note' => 'SSH oturumunu kapatmak veya normal VPS sunucuyu kapatmak ücretlendirmeyi durdurmaz. Hizmeti silmek ücretlendirmeyi sonlandırır ve sunucudaki verileri kaldırır; önce gerekli kopyaları alın.',
          'order' => 'Saatlik planları ve mevcut işletim sistemlerini inceleyin',
      ],
  ]);
@endphp

<section class="section hv-sec" id="linux-hourly-guide" aria-labelledby="hourly-linux-title">
  <div class="container">
    <div class="hv-sec-h">
      <h2 id="hourly-linux-title">{{ $hvLinux['title'] }}</h2>
      <p>{{ $hvLinux['intro'] }}</p>
    </div>
    <div class="hv-guide-grid">
      <article>
        <span class="hv-guide-n">{{ $isFa ? fa_num(1) : 1 }}</span>
        <h3>{{ $hvLinux['use_title'] }}</h3>
        <p>{{ $hvLinux['use_text'] }}</p>
        <p>{{ $hvLinux['hosting_text'] }} <a class="hv-link" href="{{ lroute('hosting', 'python') }}">{{ $hvLinux['hosting_link'] }}</a> {{ $hvLinux['hosting_after'] }}</p>
      </article>
      <article>
        <span class="hv-guide-n">{{ $isFa ? fa_num(2) : 2 }}</span>
        <h3>{{ $hvLinux['os_title'] }}</h3>
        <p>{{ $hvLinux['os_text'] }}</p>
        <p>{{ $hvLinux['os_note'] }}</p>
        <p><a class="hv-link" href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'linux']) }}">{{ $hvLinux['os_link'] }}</a></p>
      </article>
      <article>
        <span class="hv-guide-n">{{ $isFa ? fa_num(3) : 3 }}</span>
        <h3>{{ $hvLinux['ssh_title'] }}</h3>
        <p>{{ $hvLinux['ssh_text'] }}</p>
        <p dir="ltr"><code>ssh -p PORT USER@HOST</code></p>
        <p>{{ $hvLinux['ssh_auth'] }}</p>
        <p><a class="hv-link" href="{{ lroute('docs', 'connecting-to-linux-server-ssh') }}">{{ $hvLinux['ssh_link'] }}</a></p>
      </article>
      <article>
        <span class="hv-guide-n">{{ $isFa ? fa_num(4) : 4 }}</span>
        <h3>{{ $hvLinux['end_title'] }}</h3>
        <p>{{ $hvLinux['end_text'] }}</p>
        <p><strong>{{ $hvLinux['end_note'] }}</strong></p>
      </article>
    </div>
    <div class="hero-ctas">
      <a class="btn btn-glass" href="{{ $storeUrl }}" rel="nofollow">{{ $hvLinux['order'] }}</a>
    </div>
  </div>
</section>
