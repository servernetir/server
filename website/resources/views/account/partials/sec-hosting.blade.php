{{-- بخشِ هاست — کارت‌های مترِ مصرف. ورودی: $items، $room (bool) --}}
@php $room = $room ?? false; @endphp

<section class="pnl-sec" id="sec-hosting">
  <div class="pnl-sec-h">
    <h2><svg class="icon svc-sec-ic"><use href="#i-hdd"/></svg>{{ __('ui.sec_hosting') }}</h2>
    @if(! $room && $items->isNotEmpty())
      <a class="pnl-more" href="{{ lroute('account.hosting') }}">{{ __('ui.sec_view_all') }}</a>
    @endif
  </div>
  <div class="pnl-sec-b">
    @if($items->isEmpty())
      @include('account.partials.sec-empty', [
        'full'  => $room,
        'icon'  => 'hdd',
        'h'     => __('ui.sec_empty_hosting_h'),
        'p'     => __('ui.sec_empty_hosting_p'),
        'short' => __('ui.sec_none_hosting'),
        'cta'   => __('ui.sec_empty_hosting_cta'),
        'url'   => lroute('account.store'),
      ])
    @else
      <div class="svc-grid">
        @foreach($items as $s)
          @include('account.partials.card-hosting', ['s' => $s])
        @endforeach
      </div>
    @endif
  </div>
</section>
