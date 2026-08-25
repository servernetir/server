{{--
  لینک‌های متنیِ همهٔ مکان‌های ابری — ممیزی ۶ (سئو/رشد):
  «۳۰ صفحهٔ /cloud/* اولویتِ مطلق‌اند — صفحهٔ فروش با قصدِ خریدِ بالا، یتیم.»
  هر مکان یک انکرِ توصیفی می‌گیرد («سرور ابری فرانکفورت — آلمان») نه «اینجا».

  ورودی: $clHeading (عنوان بخش) · $clExcept (کدِ مکانِ جاری، اختیاری)
  ⚠️ پشتِ hasTable تا روی نصبِ مهاجرت‌نخورده چیزی نشکند؛ خالی ⇒ هیچ رندر نمی‌شود.
--}}
@php
  $clLocs = collect();
  try {
      if (\Illuminate\Support\Facades\Schema::hasTable('cloud_locations')) {
          $clLocs = \App\Models\CloudLocation::where('is_active', true)->orderBy('sort')->get()
              ->reject(fn ($l) => isset($clExcept) && (string) $l->code === (string) $clExcept);
      }
  } catch (\Throwable) {
      $clLocs = collect();
  }
@endphp
@if($clLocs->isNotEmpty())
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:18px">
      <h2 style="font-size:22px">{{ $clHeading ?? __('ui.cl_all_locations') }}</h2>
    </div>
    <div class="loc-strip reveal">
      @foreach($clLocs as $cl)
      <a class="loc" href="{{ lroute('cloud.location', (string) $cl->code) }}">
        @include('partials.flag', ['flagSrc' => $cl->flagSvg(), 'flagEmoji' => $cl->flagEmoji(), 'flagSize' => 16])
        {{ __('ui.cl_anchor', ['city' => $cl->cityLabel(), 'country' => $cl->countryLabel()]) }}
      </a>
      @endforeach
    </div>
  </div>
</section>
@endif
