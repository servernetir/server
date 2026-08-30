@extends('panel.layout')
@section('title', __('ui.svc_page_title'))

{{--
  «همه» — نمای کاملِ دارایی‌های مشتری.

  ═══ چرا این صفحه هنوز **همه‌چیز** را رندر می‌کند ═══

  وسوسه‌اش هست که این نشانی به یک صفحهٔ چهار-کارتیِ فهرست‌وار تبدیل شود. نشد،
  و عمداً:

   • `ProvisioningService` لینکِ همین نشانی را داخلِ اعلانِ بازگشتِ وجه می‌گذارد
     — پیامی که واقعاً برای مشتری فرستاده می‌شود. اگر این صفحه فقط منو باشد،
     مشتری روی آن کلیک می‌کند و **سفارشِ شکست‌خورده‌اش را نمی‌بیند**.
   • هدرِ سایت نشانیِ مطلقِ کنسول را به همین‌جا می‌دهد، داشبورد هم.
   • پنج سوئیتِ تست محتوای ردیف را روی همین URL می‌سنجند (خطِ SSH، IPv4/IPv6،
     «مدیریت سرور»، فرمِ حذف). یک صفحهٔ منو، هر پنج‌تا را می‌کشت — و بدتر،
     خاصیتی را که آن‌ها برای قفل‌کردنش نوشته شده بودند بی‌صدا حذف می‌کرد.

  پس همان چهار بخش، ولی این‌بار روی هم چیده — نه چهار محصولِ بی‌هم‌پوشانی که
  به زور در یک شکلِ ردیف ریخته شده‌اند. همان چیزی که کارفرما «همه چی توهم»
  نامیدش.
--}}

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.svc_heading') }}</h1>
    <p>{{ __('ui.svc_subtitle') }}</p>
  </div>
</div>

@include('account.partials.lens', ['secCounts' => $secCounts, 'secLens' => 'all'])

@php
  $nothingAtAll = $services->isEmpty() && $secDomains->isEmpty();
@endphp

@if($nothingAtAll)
  {{-- چهار جعبهٔ خالیِ روی‌هم برای مشتریِ تازه یک دیوارِ هیچ است؛ یک پیامِ
       روشن و دو در. --}}
  <section class="pnl-sec">
    <div class="pnl-sec-b">
      <div class="pnl-empty">
        <svg class="icon"><use href="#i-box"/></svg>
        <b>{{ __('ui.sec_empty_all_h') }}</b>
        <p>{{ __('ui.sec_empty_all_p') }}</p>
        <span class="pnl-acts svc-empty-acts">
          <a class="pnl-btn primary" href="{{ lroute('account.store') }}">{{ __('ui.sec_empty_hosting_cta') }}</a>
          <a class="pnl-btn" href="{{ lroute('account.cloud.store') }}">{{ __('ui.sec_empty_servers_cta') }}</a>
        </span>
      </div>
    </div>
  </section>
@else
  @include('account.partials.sec-hosting', ['items' => $secBuckets['hosting'], 'room' => false])
  @include('account.partials.sec-servers', ['items' => $secBuckets['server'], 'room' => false])
  @include('account.partials.sec-domains', ['domains' => $secDomains, 'room' => false])
  @include('account.partials.sec-other',   ['items' => $secBuckets['other'], 'room' => false])
@endif

@include('account.partials.svc-js')
@endsection
