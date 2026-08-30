{{-- بخشِ سرور — کارت‌های هویتِ شبکه. ورودی: $items، $room (bool)، $secBalance --}}
@php $room = $room ?? false; @endphp

<section class="pnl-sec" id="sec-servers">
  <div class="pnl-sec-h">
    <h2><svg class="icon svc-sec-ic"><use href="#i-cloud"/></svg>{{ __('ui.sec_servers') }}</h2>
    {{-- «افزودن سرور» تا امروز فقط در حالتِ خالی بود، یعنی مشتری‌ای که یک سرور
         دارد هیچ راهِ دیدنی‌ای برای خریدِ دومی نداشت و باید از منو دنبالش
         می‌گشت. حالا هر جا فهرست ناخالی است، همان‌جا بالای بخش می‌آید — هم در
         اتاقِ سرور (`/account/servers`) و هم در بخشِ سرورِ `/account/services`. --}}
    @if($items->isNotEmpty())
      <span class="pnl-sec-hx">
        @if(! $room)
          <a class="pnl-more" href="{{ lroute('account.servers') }}">{{ __('ui.sec_view_all') }}</a>
        @endif
        <a class="pnl-btn pnl-add" href="{{ lroute('account.cloud.store') }}">
          <svg class="icon"><use href="#i-plus"/></svg>{{ __('ui.sec_add_server') }}
        </a>
      </span>
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
