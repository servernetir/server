@extends('layouts.site')
@include('partials.atlas-assets')
@include('partials.atlas-seo', ['atlasContactPage' => true])
@section('title', __('ui.ct_title').' — '.__('ui.brand'))
@section('description', __('ui.ct_sub'))
@section('content')
@php $loc = app()->getLocale(); @endphp
<div class="sn-atlas sn-contact-page">
<section class="sn-contact-section" id="form">
  <div class="container">
    <div class="sn-contact-header"><span class="sn-eyebrow">{{ __('atlas.contact_eyebrow') }}</span><h1>{{ __('ui.ct_h1a') }} <span class="grad">{{ __('ui.ct_h1b') }}</span></h1><p>{{ __('ui.ct_sub') }}</p></div>
    <div class="sn-contact-grid">
      <div class="sn-contact-world">
        <div class="sn-display-top" aria-hidden="true"><span>CONNECTIONS START HERE</span><span>SERVERNET / CONTACT</span></div>
        @include('partials.atlas-globe', ['wireframe' => true])
        <p class="sn-contact-caption">{{ __('atlas.contact_caption') }}</p>
        <nav class="sn-contact-channels" aria-label="{{ __('atlas.contact_channels') }}">
          <a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg><span><small>{{ __('ui.ct_phone') }}</small><b dir="ltr">{{ $contact['phone'] }}</b></span><svg class="icon dir"><use href="#i-arrow"/></svg></a>
          <a href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg><span><small>{{ __('ui.ct_email') }}</small><b dir="ltr">{{ $contact['email'] }}</b></span><svg class="icon dir"><use href="#i-arrow"/></svg></a>
          <a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-message"/></svg><span><small>{{ __('ui.ct_wa_d') }}</small><b>WhatsApp</b></span><svg class="icon dir"><use href="#i-arrow"/></svg></a>
          <button type="button" id="ct-open-chat"><svg class="icon"><use href="#i-bot"/></svg><span><small>{{ __('ui.ct_chat_d') }}</small><b>{{ __('ui.chat_title') }}</b></span><svg class="icon dir"><use href="#i-arrow"/></svg></button>
        </nav>
      </div>
      <div class="ct-form-wrap reveal">
        <h2>{{ __('atlas.contact_form') }}</h2>
        <p>{{ __('ui.ct_form_sub') }}</p>
        <form id="ct-form" data-endpoint="{{ route($routePrefix.'chat') }}" novalidate>
          <div class="ct-row">
            <label>{{ __('ui.ct_f_name') }}<input type="text" name="name" required maxlength="80"></label>
            <label>{{ __('ui.ct_f_phone') }}<input type="tel" name="phone" required maxlength="20" dir="ltr" placeholder="{{ $loc === 'fa' ? '0912…' : '+…' }}"></label>
          </div>
          <label>{{ __('ui.ct_f_email') }}<input type="email" name="email" maxlength="120" dir="ltr" placeholder="you@example.com"></label>
          <label>{{ __('ui.ct_f_msg') }}<textarea name="message" rows="5" required maxlength="800"></textarea></label>
          <button class="btn btn-primary" type="submit"><span>{{ __('ui.ct_f_send') }}</span><svg class="icon dir" style="width:16px;height:16px"><use href="#i-send"/></svg></button>
        </form>
        <div class="ct-result" id="ct-result" hidden></div>
      </div>
    </div>
    <div class="sn-contact-info">
      <aside class="ct-info reveal" style="transition-delay:.1s">
        <div class="ct-info-card">
          <h3><svg class="icon"><use href="#i-clock"/></svg>{{ __('ui.ct_hours') }}</h3>
          <p>{{ __('ui.ct_hours_d') }}</p>
        </div>
        <div class="ct-info-card">
          <h3><svg class="icon"><use href="#i-pin"/></svg>{{ __('ui.ct_office') }}</h3>
          <p>{{ __('ui.ct_office_d') }}</p>
        </div>
        <div class="ct-info-card">
          <h3><svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.ct_sales') }}</h3>
          <p dir="ltr" style="text-align:end">{{ $contact['sales_email'] }}</p>
        </div>
        <div class="ct-info-card ct-social-card">
          <h3><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.ct_social') }}</h3>
          <div class="f-social">
            <a href="{{ $social['linkedin'] }}" target="_blank" rel="noopener" aria-label="LinkedIn"><svg class="icon"><use href="#i-linkedin"/></svg></a>
            <a href="{{ $social['instagram'] }}" target="_blank" rel="noopener" aria-label="Instagram"><svg class="icon"><use href="#i-instagram"/></svg></a>
          </div>
        </div>
      </aside>    </div>
  </div>
</section>
{{-- ============ FAQ ============ --}}
<section class="section" id="faq" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.faq_badge') }}</span>
      <h2>{{ __('ui.faq_title') }}</h2>
    </div>
    <div class="faq-list reveal">
      @foreach($faqs as $f)
      <details class="faq">
        <summary>{{ lc($f)['q'] }}<svg class="icon"><use href="#i-plus"/></svg></summary>
        <div class="body">{{ lc($f)['a'] }}</div>
      </details>
      @endforeach
    </div>
  </div>
</section>

{{--
  ══ هویتِ حقوقی ══

  🔴 کلِ بخش فقط وقتی می‌آید که **چیزی برای گفتن** باشد.

  خریدارِ ایرانی پیش از پرداخت دنبالِ همین است: نامِ ثبتی، شمارهٔ ثبت، نشانیِ
  فیزیکی. ولی جای‌نگهدار («شماره ثبت: —») از نبودنِ بخش بدتر است، چون صریح
  می‌گوید نداریم. `company_identity()` خالی‌ها را اصلاً برنمی‌گرداند، پس این
  بخش تا روزی که `.env` پر شود اصلاً وجود ندارد.
--}}
@php($ident = company_identity())
@php($addr = company_address())
@if($ident || $addr)
<section class="section" id="legal" style="padding-top:0;padding-bottom:60px">
  <div class="container" style="max-width:820px">
    <div class="section-head reveal" style="margin-bottom:22px">
      <h2 style="font-size:24px">{{ __('ui.trust_title') }}</h2>
    </div>
    <div class="ct-legal reveal">
      @foreach($ident as $row)
        <div><b>{{ __($row['label']) }}</b><span>{{ app()->getLocale() === 'fa' ? fa_num($row['value']) : $row['value'] }}</span></div>
      @endforeach
      @if($addr)
        <div><b>{{ __('ui.trust_address') }}</b><span>{{ app()->getLocale() === 'fa' ? fa_num($addr) : $addr }}</span></div>
      @endif
    </div>
  </div>
</section>
@endif

<script>
document.addEventListener('DOMContentLoaded', () => {
  // باز کردن ویجت چت از کارت «دستیار هوشمند»
  const openChat = document.getElementById('ct-open-chat');
  if (openChat) openChat.addEventListener('click', () => document.querySelector('.chat-fab')?.click());

  // فرم تماس → همان خط لوله دستیار هوشمند و سیستم سرنخ فروش
  const form = document.getElementById('ct-form');
  const result = document.getElementById('ct-result');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(form);
    if (!f.get('name') || !f.get('phone') || !f.get('message')) { form.reportValidity(); return; }
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true; btn.style.opacity = .6;
    const composed = @json(__('ui.ct_composed')) + '\n' +
      @json(__('ui.ct_f_name')) + ': ' + f.get('name') + '\n' +
      @json(__('ui.ct_f_phone')) + ': ' + f.get('phone') + '\n' +
      (f.get('email') ? @json(__('ui.ct_f_email')) + ': ' + f.get('email') + '\n' : '') +
      @json(__('ui.ct_f_msg')) + ': ' + f.get('message');
    try {
      const res = await fetch(form.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ message: composed, session: 'contact-' + Date.now().toString(36) }),
      });
      const data = await res.json();

      /*
       * 🔴 «ارسال شد» فقط وقتی واقعاً رفت.
       *
       * قبلاً کلاسِ `ok` بی‌قیدوشرط ست می‌شد. اگر سرور خطا می‌داد یا وب‌هوک
       * نبود، مشتری باز هم پیامِ سبز می‌دید و منتظرِ تماس می‌مانْد — در حالی
       * که نام و شماره‌اش هیچ‌جا ذخیره نشده بود و هیچ‌کس آن را نمی‌دید.
       * سرنخِ فروشِ ازدست‌رفته‌ای که هیچ ردی از خودش نمی‌گذاشت.
       */
      if (!res.ok || (data && data.ok === false)) {
        result.hidden = false;
        result.className = 'ct-result err';
        result.textContent = (data && data.message) || @json(__('ui.chat_error'));
        btn.disabled = false; btn.style.opacity = 1;
        return;
      }

      result.hidden = false;
      result.className = 'ct-result ok';
      result.innerHTML = '<b>' + @json(__('ui.ct_sent')) + '</b><p>' + (data.reply ? data.reply.replace(/</g, '&lt;') : @json(__('ui.ct_sent_d'))) + '</p>';
      form.reset();
    } catch {
      result.hidden = false;
      result.className = 'ct-result err';
      result.textContent = @json(__('ui.chat_error'));
    }
    btn.disabled = false; btn.style.opacity = 1;
    result.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
});
</script>
</div>
@endsection
