@extends('layouts.site')

@section('title', __('ui.ct_title').' — '.__('ui.brand'))
@section('description', __('ui.ct_sub'))

@section('content')
@php $loc = app()->getLocale(); @endphp

{{-- ============ HERO ============ --}}
<section class="hero hero-sub" style="padding-bottom:50px">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.topbar_support') }} · {{ __('ui.topbar_status') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.ct_h1a') }} <span class="grad">{{ __('ui.ct_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.ct_sub') }}</p>
    </div>
  </div>
</section>

{{-- ============ CHANNELS ============ --}}
<section class="section" style="padding-top:10px;padding-bottom:40px">
  <div class="container">
    <div class="ct-channels">
      <a class="ct-card reveal" href="tel:{{ $contact['phone_link'] }}">
        <span class="dc-icon"><svg class="icon"><use href="#i-phone"/></svg></span>
        <b>{{ __('ui.ct_phone') }}</b>
        <span dir="ltr">{{ $contact['phone'] }}</span>
        <small>{{ __('ui.ct_phone_d') }}</small>
      </a>
      <a class="ct-card reveal" style="transition-delay:.06s" href="mailto:{{ $contact['email'] }}">
        <span class="dc-icon tool"><svg class="icon"><use href="#i-mail"/></svg></span>
        <b>{{ __('ui.ct_email') }}</b>
        <span dir="ltr">{{ $contact['email'] }}</span>
        <small>{{ __('ui.ct_email_d') }}</small>
      </a>
      <a class="ct-card reveal" style="transition-delay:.12s" href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener">
        <span class="dc-icon know"><svg class="icon"><use href="#i-message"/></svg></span>
        <b>WhatsApp</b>
        <span dir="ltr">{{ $contact['whatsapp'] }}</span>
        <small>{{ __('ui.ct_wa_d') }}</small>
      </a>
      <button class="ct-card reveal" style="transition-delay:.18s" type="button" id="ct-open-chat">
        <span class="dc-icon"><svg class="icon"><use href="#i-bot"/></svg></span>
        <b>{{ __('ui.chat_title') }}</b>
        <span>{{ __('ui.chat_online') }}</span>
        <small>{{ __('ui.ct_chat_d') }}</small>
      </button>
    </div>
  </div>
</section>

{{-- ============ FORM + INFO ============ --}}
<section class="section" style="padding-top:20px" id="form">
  <div class="container">
    <div class="ct-grid">
      <div class="ct-form-wrap reveal">
        <h2>{{ __('ui.ct_form_title') }}</h2>
        <p>{{ __('ui.ct_form_sub') }}</p>
        <form id="ct-form" data-endpoint="{{ route($routePrefix.'chat') }}" novalidate>
          <div class="ct-row">
            <label>{{ __('ui.ct_f_name') }}<input type="text" name="name" required maxlength="80"></label>
            <label>{{ __('ui.ct_f_phone') }}<input type="tel" name="phone" required maxlength="20" dir="ltr" placeholder="0912…"></label>
          </div>
          <label>{{ __('ui.ct_f_email') }}<input type="email" name="email" maxlength="120" dir="ltr" placeholder="you@example.com"></label>
          <label>{{ __('ui.ct_f_msg') }}<textarea name="message" rows="5" required maxlength="800"></textarea></label>
          <button class="btn btn-primary" type="submit"><span>{{ __('ui.ct_f_send') }}</span><svg class="icon dir" style="width:16px;height:16px"><use href="#i-send"/></svg></button>
        </form>
        <div class="ct-result" id="ct-result" hidden></div>
      </div>
      <aside class="ct-info reveal" style="transition-delay:.1s">
        <div class="ct-info-card">
          <h4><svg class="icon"><use href="#i-clock"/></svg>{{ __('ui.ct_hours') }}</h4>
          <p>{{ __('ui.ct_hours_d') }}</p>
        </div>
        <div class="ct-info-card">
          <h4><svg class="icon"><use href="#i-pin"/></svg>{{ __('ui.ct_office') }}</h4>
          <p>{{ __('ui.ct_office_d') }}</p>
        </div>
        <div class="ct-info-card">
          <h4><svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.ct_sales') }}</h4>
          <p dir="ltr" style="text-align:end">{{ $contact['sales_email'] }}</p>
        </div>
        <div class="ct-info-card ct-social-card">
          <h4><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.ct_social') }}</h4>
          <div class="f-social">
            <a href="{{ $social['linkedin'] }}" target="_blank" rel="noopener" aria-label="LinkedIn"><svg class="icon"><use href="#i-linkedin"/></svg></a>
            <a href="{{ $social['instagram'] }}" target="_blank" rel="noopener" aria-label="Instagram"><svg class="icon"><use href="#i-instagram"/></svg></a>
          </div>
        </div>
      </aside>
    </div>
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
@endsection
