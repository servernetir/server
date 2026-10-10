{{-- Useful route choices, rendered without JavaScript in every locale. --}}
@php
  $seoChoice = lc([
    'fa' => ['t' => 'برای کار کوتاه‌مدت، کدام سرور مناسب است؟', 'cpu' => 'سرور مجازی ساعتی', 'cpu_d' => 'برای اجرای برنامه، آزمایش نرم‌افزار و کارهای موقت؛ نرخ‌ها، مکان‌های موجود و شرایط صورتحساب را مقایسه کنید.', 'gpu' => 'اجاره سرور گرافیکی ساعتی', 'gpu_d' => 'برای کارهای هوش مصنوعی و پردازش گرافیکی؛ پیش از سفارش، حافظهٔ کارت، برنامه‌های آماده و محدودیت‌های سرویس را بررسی کنید.'],
    'en' => ['t' => 'Which server fits a short project?', 'cpu' => 'Hourly cloud VPS', 'cpu_d' => 'Run apps, test software or complete temporary jobs. Compare current rates, available locations and billing conditions.', 'gpu' => 'Hourly GPU server rental', 'gpu_d' => 'For AI and graphics workloads. Check GPU memory, ready-to-use apps and service limitations before ordering.'],
    'tr' => ['t' => 'Kısa süreli işler için hangi sunucu uygun?', 'cpu' => 'Saatlik bulut VPS', 'cpu_d' => 'Uygulama çalıştırın, yazılım test edin veya geçici işlerinizi tamamlayın. Güncel ücretleri, mevcut konumları ve faturalandırma koşullarını karşılaştırın.', 'gpu' => 'Saatlik GPU sunucu kiralama', 'gpu_d' => 'Yapay zekâ ve grafik iş yükleri için. Siparişten önce GPU belleğini, hazır uygulamaları ve hizmet sınırlarını inceleyin.'],
  ]);
@endphp
<section class="section seo-choice" aria-labelledby="seo-choice-title">
  <div class="container">
    <div class="section-head"><h2 id="seo-choice-title">{{ $seoChoice['t'] }}</h2></div>
    <div class="seo-choice-grid">
      <a class="seo-choice-card" href="{{ lroute('vps.hourly') }}"><h3>{{ $seoChoice['cpu'] }}</h3><p>{{ $seoChoice['cpu_d'] }}</p></a>
      <a class="seo-choice-card" href="{{ lroute('gpu') }}"><h3>{{ $seoChoice['gpu'] }}</h3><p>{{ $seoChoice['gpu_d'] }}</p></a>
    </div>
  </div>
</section>
