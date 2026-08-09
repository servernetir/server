{{-- بخشِ سرور — کارت‌های هویتِ شبکه. ورودی: $items، $room (bool)، $secBalance --}}
@php $room = $room ?? false; @endphp

<section class="pnl-sec" id="sec-servers">
  <div class="pnl-sec-h">
    <h2><svg class="icon svc-sec-ic"><use href="#i-cloud"/></svg>{{ __('ui.sec_servers') }}</h2>
    @if(! $room && $items->isNotEmpty())
      <a class="pnl-more" href="{{ lroute('account.servers') }}">{{ __('ui.sec_view_all') }}</a>
    @endif
  </div>
  <div class="pnl-sec-b">
    @if($items->isEmpty())
      @include('account.partials.sec-empty', [
        'full'  => $room,
        'icon'  => 'cloud',
        'h'     => __('ui.sec_empty_servers_h'),
        'p'     => __('ui.sec_empty_servers_p'),
        'short' => __('ui.sec_none_servers'),
        'cta'   => __('ui.sec_empty_servers_cta'),
        'url'   => lroute('account.cloud.store'),
      ])
    @else
      <div class="svc-grid">
        @foreach($items as $s)
          @include('account.partials.card-server', ['s' => $s])
        @endforeach
      </div>
    @endif
  </div>
</section>
