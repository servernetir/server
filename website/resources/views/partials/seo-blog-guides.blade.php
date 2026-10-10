@php
    // index() contains published, translated metadata and is already cached.
    // Never link a missing post or a Persian fallback on EN/TR.
    $guidePosts = collect(app(\App\Services\BlogRepository::class)->index())->keyBy('slug');
    $guideGroups = [
        [
            'title' => ['fa' => 'سرعت و اندازه‌گیری', 'en' => 'Performance and measurement', 'tr' => 'Performans ve ölçüm'],
            'slugs' => ['diagnosing-slow-website', 'browser-caching-headers', 'image-optimization-webp-avif', 'measuring-real-user-performance'],
        ],
        [
            'title' => ['fa' => 'راه‌اندازی و نگهداری سرور', 'en' => 'Server setup and operations', 'tr' => 'Sunucu kurulumu ve işletimi'],
            'slugs' => ['configuring-dns-for-new-site', 'connection-timed-out-diagnosis', 'docker-basics-for-web-developers', 'firewall-rules-basics', 'autoscaling-concepts', 'two-factor-authentication-guide'],
        ],
    ];
@endphp
@if($guidePosts->isNotEmpty())
<section class="seo-blog-guides" aria-labelledby="seo-guides-title">
  <h2 id="seo-guides-title">{{ lc(['fa' => 'راهنماهای کاربردی برای سایت و سرور شما', 'en' => 'Practical guides for your website and server', 'tr' => 'Web siteniz ve sunucunuz için pratik rehberler']) }}</h2>
  <p>{{ lc(['fa' => 'اگر برای راه‌اندازی سرور، رفع خطا یا افزایش سرعت سایت به بلاگ آمده‌اید، از راهنمای مرتبط شروع کنید. این مسیرها به مطالب موجود وصل‌اند و جای فهرست تازه‌ترین نوشته‌ها را نمی‌گیرند.', 'en' => 'Start with the guide that matches your task: configure a server, troubleshoot an error, or improve website performance. These reading paths complement the latest articles above.', 'tr' => 'Görevinize uygun rehberle başlayın: sunucu kurmak, bir hatayı gidermek veya web sitesi performansını artırmak. Bu okuma yolları, yukarıdaki en yeni yazıları tamamlar.']) }}</p>
  <div class="seo-guide-groups">
  @foreach($guideGroups as $group)
    @php $availableGuides = collect($group['slugs'])->filter(fn($slug) => isset($guidePosts[$slug]) && empty($guidePosts[$slug]['untranslated'])); @endphp
    @if($availableGuides->isNotEmpty())
    <div>
      <h3>{{ lc($group['title']) }}</h3>
      <ul>
      @foreach($availableGuides as $slug)
        <li><a href="{{ lroute('blog', $slug) }}">{{ $guidePosts[$slug]['title'] }}</a></li>
      @endforeach
      </ul>
    </div>
    @endif
  @endforeach
  </div>
</section>
@endif
