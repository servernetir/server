{{-- بخشِ خدمات — فهرستِ ساده. ورودی: $items، $room (bool) --}}
@php $room = $room ?? false; @endphp

<section class="pnl-sec" id="sec-other">
  <div class="pnl-sec-h">
    <h2><svg class="icon svc-sec-ic"><use href="#i-wrench"/></svg>{{ __('ui.sec_other') }}</h2>
    @if(! $room && $items->isNotEmpty())
      <a class="pnl-more" href="{{ lroute('account.other') }}">{{ __('ui.sec_view_all') }}</a>
    @endif
  </div>
  <div class="pnl-sec-b">
    @if($items->isEmpty())
      {{-- ⚠️ تنها بخشی که دکمه‌اش فروشگاه **نیست**: این خدمات را مشتری خودش
           نمی‌خرد؛ مدیر برایش ثبت می‌کند. لینک به فروشگاه یعنی فرستادنِ او به
           صفحه‌ای که چیزی برای این بخش ندارد. --}}
      @include('account.partials.sec-empty', [
        'full'  => $room,
        'icon'  => 'wrench',
        'h'     => __('ui.sec_empty_other_h'),
        'p'     => __('ui.sec_empty_other_p'),
        'short' => __('ui.sec_none_other'),
        'cta'   => __('ui.sec_empty_other_cta'),
        'url'   => lroute('account.tickets'),
      ])
    @else
      <ul class="svc-list">
        @foreach($items as $s)
          @include('account.partials.row-other', ['s' => $s])
        @endforeach
      </ul>
    @endif
  </div>
</section>
