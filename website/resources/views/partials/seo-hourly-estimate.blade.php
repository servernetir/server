{{-- Estimate from the same currently available starting plan as the headline. --}}
@if(($fromHourlyRaw ?? 0) > 0 && $fromHourly && ($isFa || cloud_eur_rate() > 0))
@php
  $seoEstimate = lc([
    'fa' => ['t' => 'هزینهٔ سرور ساعتی برای یک کار کوتاه چقدر است؟', 'd' => 'نمونه‌های زیر با نرخ فعلیِ ارزان‌ترین پلن قابل سفارش محاسبه می‌شوند. مشخصات و مکان آن پلن را در فروشگاه بررسی کنید؛ نرخ پلن انتخابی شما ممکن است بیشتر باشد.', 'duration' => 'مدت استفاده', 'cost' => 'برآورد هزینه از', 'hour' => 'ساعت', 'note' => 'این جدول برآورد است و شامل افزونه‌ها نیست؛ اعتبار لازم برای شروع با هزینهٔ نهایی یکسان نیست. خاموش‌کردن VPS، رزرو منابع را پایان نمی‌دهد؛ برای پایان صورتحساب، سرور را حذف کنید. نرخ نهایی و شرایط جاری هنگام سفارش نمایش داده می‌شوند.', 'rates' => 'مقایسهٔ نرخ‌ها و مکان‌های موجود', 'gpu' => 'برای کارهای گرافیکی، سرور GPU ساعتی را بررسی کنید'],
    'en' => ['t' => 'How much does an hourly VPS cost for a short job?', 'd' => 'These examples use the current rate of the cheapest orderable plan. Check its specifications and location in the store; your chosen plan may cost more.', 'duration' => 'Usage duration', 'cost' => 'Estimated cost from', 'hour' => 'hours', 'note' => 'This estimate excludes add-ons. Starting credit is different from the final cost. Turning a VPS off does not release its reserved resources; delete the server to end billing. The final rate and current conditions are shown when ordering.', 'rates' => 'Compare current rates and available locations', 'gpu' => 'For graphics workloads, explore hourly GPU servers'],
    'tr' => ['t' => 'Kısa bir iş için saatlik VPS ne kadar tutar?', 'd' => 'Bu örnekler sipariş edilebilir en ucuz planın güncel ücretiyle hesaplanır. Planın özelliklerini ve konumunu mağazada kontrol edin; seçtiğiniz plan daha pahalı olabilir.', 'duration' => 'Kullanım süresi', 'cost' => 'Tahmini başlangıç maliyeti', 'hour' => 'saat', 'note' => 'Bu tahmin ek hizmetleri içermez. Başlangıç bakiyesi ile nihai maliyet farklıdır. VPS’yi kapatmak ayrılan kaynakları serbest bırakmaz; faturalandırmayı bitirmek için sunucuyu silin. Nihai ücret ve güncel koşullar sipariş sırasında gösterilir.', 'rates' => 'Güncel ücretleri ve mevcut konumları karşılaştırın', 'gpu' => 'Grafik işleri için saatlik GPU sunucularını inceleyin'],
  ]);
@endphp
<section class="section hv-sec" id="cost-examples">
  <div class="container">
    <div class="hv-sec-h"><h2>{{ $seoEstimate['t'] }}</h2><p>{{ $seoEstimate['d'] }}</p></div>
    <div class="hv-table-wrap">
      <table class="hv-table">
        <thead><tr><th scope="col">{{ $seoEstimate['duration'] }}</th><th scope="col">{{ $seoEstimate['cost'] }}</th></tr></thead>
        <tbody>
        @foreach([6, 24, 72] as $seoHours)
          <tr><th scope="row">{{ $isFa ? fa_num($seoHours) : $seoHours }} {{ $seoEstimate['hour'] }}</th><td>{{ cloud_price($fromHourlyRaw * $seoHours) }}</td></tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <p class="seo-cost-note">{{ $seoEstimate['note'] }}</p>
    <div class="hv-cross"><a href="#rates">{{ $seoEstimate['rates'] }}</a><a href="{{ lroute('gpu') }}">{{ $seoEstimate['gpu'] }}</a></div>
  </div>
</section>
@endif
