{{--
  صفحهٔ «نشان سرورنت» — /badge (fa / en / tr).

  نقشِ لینک‌سازی: هر مشتریِ هاست/وب‌دیزاین که این نشان را در فوترش بگذارد، یک
  لینکِ واقعیِ dofollow به سرورنت می‌دهد — همان الگویی که mahroodecor و niyosha
  خودجوش انجام دادند، حالا به‌صورتِ محصول. (ممیزی بک‌لینکِ ۲۵ اوت: لینکِ واقعیِ
  کسب‌شده تقریباً صفر بود؛ این صفحه موتورِ تدریجیِ ساختنش است.)

  ⚠️ نشان عمداً **بدونِ تصویر و بدونِ اسکریپتِ خارجی** است: چند خطِ HTML با
     استایلِ درون‌خطی که در هر قالبی (وردپرس، جوملا، دست‌ساز) بی‌دردسر کار
     می‌کند و هیچ باری روی سرورِ ما نمی‌گذارد.
  ⚠️ کد داخلِ <textarea readonly> نمایش داده می‌شود، نه <pre>: هم escape لازم
     ندارد، هم انتخاب/کپی راحت است. جاوااسکریپتِ کپی مقدارش را از همان
     textarea می‌خوانَد — یک منبعِ حقیقت.
  ⚠️ نخستین بستهٔ در-جریان `.section` است تا جبرانِ هدر از #main بیاید
     (FixedHeaderOffsetTest). استایل درجا با پیشوندِ bdg-.
--}}
@extends('layouts.site')

@php
  $bdgIsFa = app()->getLocale() === 'fa';
  $bdgHosted = __('ui.bdg_label_hosted');
  $bdgBrand = __('ui.bdg_label_brand');

  /*
  | سه نسخهٔ کدِ نشان. با نقطه‌گذاریِ رشته‌ای ساخته می‌شوند (نه heredoc) تا هیچ
  | `@` یا `{{`ی واردِ متنِ Blade نشود. لینک عمداً بدونِ UTM است: مقصدِ لینک
  | باید همان صفحه‌ای باشد که اعتبارش را می‌خواهیم — خانهٔ سایت.
  */
  $bdgUrl = 'https://servernet.cloud/';
  $bdgTitle = $bdgIsFa ? 'سرورنت — هاستینگ، سرور مجازی و ابری' : 'ServerNet — hosting, VPS and cloud';

  $bdgDark = '<a href="'.$bdgUrl.'" title="'.$bdgTitle.'" style="display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:10px;background:#0A0E17;border:1px solid #263041;color:#e6edf3;font-family:Tahoma,Arial,sans-serif;font-size:13px;line-height:1;text-decoration:none">'
      .'<span style="width:8px;height:8px;border-radius:50%;background:#22d3ee;display:inline-block"></span>'
      .$bdgHosted.' <b style="color:#22d3ee;font-weight:700">'.$bdgBrand.'</b></a>';

  $bdgLight = '<a href="'.$bdgUrl.'" title="'.$bdgTitle.'" style="display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:10px;background:#f6f8fa;border:1px solid #d0d7de;color:#1f2328;font-family:Tahoma,Arial,sans-serif;font-size:13px;line-height:1;text-decoration:none">'
      .'<span style="width:8px;height:8px;border-radius:50%;background:#0891b2;display:inline-block"></span>'
      .$bdgHosted.' <b style="color:#0891b2;font-weight:700">'.$bdgBrand.'</b></a>';

  $bdgText = '<a href="'.$bdgUrl.'" title="'.$bdgTitle.'">'.$bdgHosted.' '.$bdgBrand.'</a>';

  $bdgBlocks = [
      ['t' => __('ui.bdg_dark_t'),  'code' => $bdgDark],
      ['t' => __('ui.bdg_light_t'), 'code' => $bdgLight],
      ['t' => __('ui.bdg_text_t'),  'code' => $bdgText],
  ];

  $bdgFaq = [
      ['q' => __('ui.bdg_faq1_q'), 'a' => __('ui.bdg_faq1_a')],
      ['q' => __('ui.bdg_faq2_q'), 'a' => __('ui.bdg_faq2_a')],
  ];
  $bdgFaqLd = [];
  foreach ($bdgFaq as $bdgQ) {
      $bdgFaqLd[] = ['@type' => 'Question', 'name' => $bdgQ['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $bdgQ['a']]];
  }
  $bdgCrumbs = ['itemListElement' => [
      ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => $homeUrl],
      ['@type' => 'ListItem', 'position' => 2, 'name' => __('ui.bdg_badge'), 'item' => url()->current()],
  ]];
@endphp

@section('title', __('ui.bdg_meta_t'))
@section('description', __('ui.bdg_meta_d'))

@section('content')

<script type="application/ld+json">{!! schema_ld(['mainEntity' => $bdgFaqLd], 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld($bdgCrumbs, 'BreadcrumbList') !!}</script>

<section class="section bdg-top">
  <div class="container">
    <div class="bdg-head">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.bdg_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.bdg_h1') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.bdg_lead') }}</p>
    </div>
  </div>
</section>

{{-- ═══════════ چرا ═══════════ --}}
<section class="section bdg-sec">
  <div class="container">
    <div class="bdg-why">
      <h2>{{ __('ui.bdg_why_t') }}</h2>
      <ul>
        <li>{{ __('ui.bdg_why_1') }}</li>
        <li>{{ __('ui.bdg_why_2') }}</li>
        <li>{{ __('ui.bdg_why_3') }}</li>
      </ul>
    </div>
  </div>
</section>

{{-- ═══════════ سه طرح ═══════════ --}}
<section class="section bdg-sec" id="codes">
  <div class="container">
    <div class="bdg-grid">
      @foreach($bdgBlocks as $i => $b)
        <div class="bdg-card reveal" style="transition-delay:{{ $i * 60 }}ms">
          <h3>{{ $b['t'] }}</h3>
          <div class="bdg-preview">
            <span class="bdg-preview-l">{{ __('ui.bdg_preview') }}</span>
            <span class="bdg-preview-b">{!! $b['code'] !!}</span>
          </div>
          <textarea class="bdg-code" readonly rows="4" spellcheck="false" dir="ltr">{{ $b['code'] }}</textarea>
          <button type="button" class="btn btn-primary bdg-copy" data-copied="{{ __('ui.bdg_copied') }}">{{ __('ui.bdg_copy') }}</button>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══════════ کجا بگذارم ═══════════ --}}
<section class="section bdg-sec">
  <div class="container">
    <div class="bdg-why">
      <h2>{{ __('ui.bdg_how_t') }}</h2>
      <p>{{ __('ui.bdg_how_d') }}</p>
    </div>
  </div>
</section>

{{-- ═══════════ پرسش‌ها + CTA ═══════════ --}}
<section class="section bdg-sec" id="faq">
  <div class="container">
    <div class="bdg-faq">
      @foreach($bdgFaq as $i => $row)
        <details @if($i === 0) open @endif>
          <summary>{{ $row['q'] }}</summary>
          <div>{{ $row['a'] }}</div>
        </details>
      @endforeach
    </div>
    <p class="bdg-cta"><a href="{{ lroute('hosting', 'linux') }}">{{ __('ui.bdg_cta_hosting') }}</a></p>
  </div>
</section>

<script>
(function(){
  var btns = document.querySelectorAll('.bdg-copy');
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function(){
      var ta = this.parentNode.querySelector('.bdg-code');
      if (!ta) { return; }
      ta.select();
      var ok = false;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value);
        ok = true;
      } else {
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      }
      if (ok) {
        var b = this, old = b.textContent;
        b.textContent = b.getAttribute('data-copied');
        setTimeout(function(){ b.textContent = old; }, 1600);
      }
    });
  }
})();
</script>

<style>
/* صفحهٔ نشان — استایل درجا با پیشوند bdg- */
.bdg-top{ padding-bottom:26px }
.bdg-sec{ padding:34px 0 }
.bdg-head{ max-width:820px }
.bdg-head h1{ font-family:var(--font-disp); font-size:clamp(25px,4vw,38px); font-weight:700;
  letter-spacing:-.6px; line-height:1.3; margin:14px 0 14px; text-wrap:balance }
.bdg-head .lead{ color:var(--muted); font-size:14.8px; line-height:2.1 }
.bdg-why{ max-width:820px }
.bdg-why h2{ font-family:var(--font-disp); font-size:clamp(19px,2.6vw,25px); font-weight:700; margin-bottom:12px }
.bdg-why p{ color:var(--muted); font-size:14px; line-height:2.05 }
.bdg-why ul{ list-style:none; display:flex; flex-direction:column; gap:9px }
.bdg-why li{ position:relative; padding-inline-start:20px; color:var(--muted); font-size:13.8px; line-height:2 }
.bdg-why li::before{ content:''; position:absolute; inset-inline-start:0; top:11px; width:8px; height:8px;
  border-radius:50%; background:var(--grad) }
.bdg-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(290px,1fr)); gap:16px }
.bdg-card{ border:1px solid var(--line); border-radius:18px; background:var(--surface); padding:20px;
  display:flex; flex-direction:column; gap:12px }
.bdg-card h3{ font-size:15px; font-weight:700 }
.bdg-preview{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:14px;
  border:1px dashed var(--line-2); border-radius:12px }
.bdg-preview-l{ font-size:12px; color:var(--dim) }
.bdg-code{ width:100%; resize:vertical; min-height:86px; font-family:Consolas,Menlo,monospace; font-size:11.5px;
  line-height:1.7; color:var(--muted); background:var(--surface-2); border:1px solid var(--line);
  border-radius:12px; padding:10px 12px; white-space:pre-wrap; word-break:break-all }
.bdg-copy{ align-self:flex-start }
.bdg-faq{ display:flex; flex-direction:column; gap:10px; max-width:820px }
.bdg-faq details{ border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:14px 18px }
.bdg-faq summary{ font-size:14px; font-weight:600; list-style:none; cursor:pointer }
.bdg-faq summary::-webkit-details-marker{ display:none }
.bdg-faq details[open] summary{ color:var(--cyan) }
.bdg-faq details div{ margin-top:10px; color:var(--muted); font-size:13.2px; line-height:2 }
.bdg-cta{ margin-top:22px; font-size:13.5px }
.bdg-cta a{ color:var(--cyan); text-decoration:underline; text-underline-offset:4px }
</style>
@endsection
