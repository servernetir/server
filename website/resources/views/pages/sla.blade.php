@extends('layouts.site')

@section('title', __('ui.sla_title').' — '.__('ui.brand'))
@section('description', __('ui.sla_meta_d'))

@section('content')

{{-- سند SLA.

  چرا وجودش از محتوایش مهم‌تر است: سایت «آپتایم تضمینی» تبلیغ می‌کرد و هیچ سندی
  پشتش نبود — یعنی یک تعهدِ یک‌طرفه بدونِ سقف، بدونِ استثنا و بدونِ فرآیندِ
  مطالبه. بدترین حالتِ ممکن برای فروشنده: مشتریِ معترض هر عددی را می‌تواند
  ادعا کند و ما هیچ چارچوبی برای پاسخ نداریم.

  ⚠️ بندِ قوّهٔ قاهره عمداً «قطعی یا محدودسازیِ سراسریِ اینترنت» و «قطعِ دسترسیِ
  تأمین‌کنندهٔ خارجی» را نام می‌برد. برای یک میزبانِ ایرانی این دو، ریسکِ فرضی
  نیستند؛ سابقهٔ واقعی دارند. --}}

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.sla_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.sla_title') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.sla_lead') }}</p>
    </div>
  </div>
</section>

<section class="section" style="padding-top:20px">
  <div class="container" style="max-width:860px">

    <div class="sla-doc reveal">

      <h2>{{ __('ui.sla_s1_t') }}</h2>
      <p>{{ __('ui.sla_s1_d') }}</p>

      <h2>{{ __('ui.sla_s2_t') }}</h2>
      <p>{{ __('ui.sla_s2_d') }}</p>

      <div style="overflow-x:auto">
        <table class="sla-table">
          <thead><tr><th>{{ __('ui.sla_col_uptime') }}</th><th>{{ __('ui.sla_col_credit') }}</th></tr></thead>
          <tbody>
            @foreach(config('sla.credits') as $row)
            <tr>
              <td dir="ltr">{{ $isFa ? fa_num($row['range']) : $row['range'] }}</td>
              <td><b>{{ $isFa ? fa_num($row['credit']) : $row['credit'] }}٪</b></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <h2>{{ __('ui.sla_s3_t') }}</h2>
      <p>{{ __('ui.sla_s3_d') }}</p>

      <h2>{{ __('ui.sla_s4_t') }}</h2>
      <ul>
        @foreach(['sla_ex1', 'sla_ex2', 'sla_ex3'] as $k)
        <li>{{ __('ui.'.$k) }}</li>
        @endforeach
      </ul>

      <h2>{{ __('ui.sla_s5_t') }}</h2>
      <p>{{ __('ui.sla_s5_d') }}</p>
      <ul>
        {{-- sla_fm5 در ممیزی ۴ اضافه شد: قطع/محدودسازی/کاهش پهنای باند شبکهٔ
             کشور و مسیرهای بین‌الملل — صریح، با ارجاع به /status و بی‌خدشه به
             ضمانتِ ۱۴روزه. وعدهٔ «دسترس‌پذیری از شبکهٔ داخلی» عمداً نیامده:
             ممیزی گفت فقط با تأیید زیرساخت درج شود، و آن تأیید هنوز نیست. --}}
        @foreach(['sla_fm1', 'sla_fm2', 'sla_fm3', 'sla_fm4', 'sla_fm5'] as $k)
        <li>{{ __('ui.'.$k) }}</li>
        @endforeach
      </ul>
      <p>{{ __('ui.sla_s5_note') }}</p>

      <h2>{{ __('ui.sla_s6_t') }}</h2>
      <p>{{ __('ui.sla_s6_d') }}</p>

      <p class="sla-foot">
        {{ __('ui.sla_version', ['date' => sdate(config('sla.updated_at'))]) }} ·
        <a href="{{ lroute('status') }}">{{ __('ui.status_title') }}</a> ·
        {{-- ⚠️ کلید `f_terms` است نه `footer_terms`؛ کلیدِ نبود در هر سه زبان
             متنِ خام «ui.footer_terms» را روی صفحهٔ SLA چاپ می‌کرد. --}}
        <a href="{{ lroute('terms') }}">{{ __('ui.f_terms') }}</a> ·
        {{-- ارجاعِ متقابل به AUP — خواستهٔ صریح مدیر حقوقی در ممیزی ۴ --}}
        <a href="{{ lroute('aup') }}">{{ __('ui.f_aup') }}</a>
      </p>

    </div>
  </div>
</section>
@endsection
