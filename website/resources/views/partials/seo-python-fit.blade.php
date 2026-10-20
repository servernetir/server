@php
  $pythonFit = lc([
    'fa' => [
      'title' => 'هاست پایتون یا سرور مجازی؟ بر اساس نیاز اجرا انتخاب کنید',
      'intro' => 'پیش از انتخاب پلن، نوع برنامه و فرایندهای موردنیازش را بررسی کنید. تفاوت اصلی فقط مقدار RAM نیست؛ روش اجرای برنامه و میزان کنترل شما هم مهم است.',
      'hosting' => 'برای وب‌اپ معمولی پایتون',
      'hosting_d' => 'Django و Flask مبتنی بر WSGI روی هاست پایتون اجرا می‌شوند. FastAPI و دیگر برنامه‌های ASGI به پل نیاز دارند. برای نصب بسته‌ها از SSH، pip و محیط مجازی استفاده کنید؛ دیتابیس ارائه‌شده MySQL/MariaDB است.',
      'hosting_link' => 'پلن‌های هاست پایتون را ببینید',
      'vps' => 'برای کنترل بیشتر روی اجرای برنامه',
      'vps_d' => 'اگر WebSocket، پاسخ SSE، کارگر دائمی مانند Celery یا دیتابیس دیگری لازم دارید، به‌جای هاست اشتراکی سرور مجازی انتخاب کنید و پشتهٔ نرم‌افزاری را خودتان تنظیم کنید.',
      'vps_link' => 'سرور مجازی ساعتی را مقایسه کنید',
      'gpu' => 'برای پردازش مدل‌های هوش مصنوعی',
      'gpu_d' => 'اگر بار اصلی پروژه آموزش یا اجرای مدل روی کارت گرافیک است، حافظهٔ GPU و برنامه‌های آمادهٔ سرویس گرافیکی را جدا از هاست وب بررسی کنید.',
      'gpu_link' => 'گزینه‌های اجارهٔ سرور GPU را ببینید',
      'domain' => 'برای انتشار روی نام دلخواه، وضعیت و پسوند دامنه را هم بررسی کنید.',
      'domain_link' => 'جستجوی دامنه',
    ],
    'en' => [
      'title' => 'Python hosting or a VPS? Choose by runtime needs',
      'intro' => 'Check your application and its background processes before choosing a plan. Memory is only part of the decision; the runtime and level of control matter too.',
      'hosting' => 'For a conventional Python web app',
      'hosting_d' => 'Django and Flask WSGI apps run on Python hosting. FastAPI and other ASGI apps need a bridge. Use SSH, pip and virtual environments for packages; the offered database is MySQL/MariaDB.',
      'hosting_link' => 'Compare Python hosting plans',
      'vps' => 'For more control over the runtime',
      'vps_d' => 'If you need WebSockets, SSE responses, persistent workers such as Celery or a different database, choose a VPS instead of shared hosting and configure your own software stack.',
      'vps_link' => 'Compare hourly VPS options',
      'gpu' => 'For AI model workloads',
      'gpu_d' => 'If training or running a model on a graphics card is the main job, assess GPU memory and ready-to-use apps separately from web hosting.',
      'gpu_link' => 'Explore GPU server rental',
      'domain' => 'To publish on your own name, check the domain and available extensions as well.',
      'domain_link' => 'Search domains',
    ],
    'tr' => [
      'title' => 'Python hosting mi VPS mi? Çalışma biçimine göre seçin',
      'intro' => 'Paket seçmeden önce uygulamanızın ve arka plan işlemlerinin ihtiyaçlarını kontrol edin. Karar yalnızca RAM miktarına değil, çalışma ortamına ve istediğiniz kontrole de bağlıdır.',
      'hosting' => 'Standart Python web uygulamaları için',
      'hosting_d' => 'Django ve Flask gibi WSGI uygulamaları Python hostingde çalışır. FastAPI ve diğer ASGI uygulamaları köprü gerektirir. Paketler için SSH, pip ve sanal ortam kullanın; sunulan veritabanı MySQL/MariaDB’dir.',
      'hosting_link' => 'Python hosting paketlerini karşılaştırın',
      'vps' => 'Çalışma ortamı üzerinde daha fazla kontrol için',
      'vps_d' => 'WebSocket, SSE yanıtları, Celery gibi sürekli çalışan worker’lar veya farklı bir veritabanı gerekiyorsa paylaşımlı hosting yerine VPS seçip yazılım ortamınızı kendiniz yapılandırın.',
      'vps_link' => 'Saatlik VPS seçeneklerini karşılaştırın',
      'gpu' => 'Yapay zekâ modelleri için',
      'gpu_d' => 'Asıl işiniz modeli ekran kartında eğitmek veya çalıştırmaksa GPU belleğini ve hazır uygulamaları web hostingden ayrı değerlendirin.',
      'gpu_link' => 'GPU sunucu kiralama seçenekleri',
      'domain' => 'Uygulamanızı kendi adınızla yayınlamak için alan adı ve uzantı durumunu da kontrol edin.',
      'domain_link' => 'Alan adı arayın',
    ],
  ]);
@endphp
<section class="section python-fit" id="python-fit" aria-labelledby="python-fit-title" style="padding-top:30px">
  <div class="container">
    <div class="section-head"><h2 id="python-fit-title">{{ $pythonFit['title'] }}</h2><p>{{ $pythonFit['intro'] }}</p></div>
    <div class="why-grid">
      <article class="witem"><div class="wicon"><svg class="icon"><use href="#i-code"/></svg></div><div><h3>{{ $pythonFit['hosting'] }}</h3><p>{{ $pythonFit['hosting_d'] }}</p><a href="#pricing">{{ $pythonFit['hosting_link'] }}</a></div></article>
      <article class="witem"><div class="wicon"><svg class="icon"><use href="#i-cloud"/></svg></div><div><h3>{{ $pythonFit['vps'] }}</h3><p>{{ $pythonFit['vps_d'] }}</p><a href="{{ lroute('vps.hourly') }}">{{ $pythonFit['vps_link'] }}</a></div></article>
      <article class="witem"><div class="wicon"><svg class="icon"><use href="#i-cpu"/></svg></div><div><h3>{{ $pythonFit['gpu'] }}</h3><p>{{ $pythonFit['gpu_d'] }}</p><a href="{{ lroute('gpu') }}">{{ $pythonFit['gpu_link'] }}</a></div></article>
    </div>
    <p style="margin-top:20px">{{ $pythonFit['domain'] }} <a href="{{ lroute('domain.search') }}">{{ $pythonFit['domain_link'] }}</a></p>
  </div>
</section>
<style>
.python-fit .witem h3{font-size:15.5px;font-weight:600;margin:0 0 7px}
.python-fit .witem a{display:inline-block;margin-top:12px;color:var(--green,#34d399);font-weight:600;text-decoration:underline;text-underline-offset:3px}
.python-fit .witem a:focus-visible,.python-fit>div>p a:focus-visible{outline:2px solid currentColor;outline-offset:3px}
</style>
