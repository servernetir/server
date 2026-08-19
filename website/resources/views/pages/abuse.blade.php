@extends('layouts.site')

@section('title', __('ui.ab_title').' — '.__('ui.brand'))
@section('description', __('ui.ab_meta_d'))

{{-- فرم گزارش سوءاستفاده (ممیزی ۴ — امنیت). مارک‌آپ فرم = الگوی فرم نظرات
     بلاگ (blog-comment-form در site.css موجود است) تا کلاس تازه لازم نشود. --}}

@section('content')

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.ab_title') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.ab_h1') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.ab_lead') }}</p>
    </div>
  </div>
</section>

<section class="section" style="padding-top:10px">
  <div class="container" style="max-width:720px">

    @if(session('abuse_status') === 'ok')
    <div class="blog-note ok reveal">{{ __('ui.ab_ok') }}</div>
    @elseif(session('abuse_status') === 'busy')
    <div class="blog-note reveal">{{ __('ui.ab_busy') }}</div>
    @endif

    <form class="blog-comment-form reveal" method="post" action="{{ lroute('abuse.report') }}">
      @csrf
      <h3>{{ __('ui.ab_form_t') }}</h3>
      @if($errors->any())<div class="blog-note err">{{ __('ui.ab_err') }}</div>@endif

      <div class="bcf-row">
        <input type="text" name="target" placeholder="{{ __('ui.ab_target') }}" maxlength="200" required value="{{ old('target') }}" dir="ltr">
        <input type="email" name="email" placeholder="{{ __('ui.ab_email') }}" maxlength="120" value="{{ old('email') }}">
      </div>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <textarea name="body" rows="6" placeholder="{{ __('ui.ab_desc') }}" minlength="20" maxlength="4000" required>{{ old('body') }}</textarea>
      <button class="btn btn-primary" type="submit">{{ __('ui.ab_submit') }}<svg class="icon dir"><use href="#i-send"/></svg></button>
    </form>

    <p class="reveal" style="margin-top:18px;font-size:13.5px;color:var(--muted)">
      {{ __('ui.ab_sla') }}
      · <a href="{{ lroute('aup') }}">{{ __('ui.f_aup') }}</a>
      @php $abuseMail = 'abuse'.'@'.'servernet.cloud'; @endphp
      · <a href="mailto:{{ $abuseMail }}" dir="ltr">{{ $abuseMail }}</a>
    </p>

  </div>
</section>
@endsection
