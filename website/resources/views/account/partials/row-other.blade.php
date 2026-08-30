{{--
  خدمات — عمداً کوتاه‌ترین شکل.

  روی این ردیف‌ها هیچ دادهٔ فنی‌ای وجود ندارد: نام، توضیح، دوره، مبلغ، سررسید و
  وضعیت — همین. ریختنشان در یک جدولِ شش‌ستونه یعنی پنج ستونِ خالی، و همان
  ستون‌های خالی بزرگ‌ترین سهم را در «همه چی توهم» داشتند.

  ورودی: $s (Service)
--}}
@php
  $recurring = $s->isRecurring();
@endphp

<li class="svc-row">
  <div class="svc-row-main">
    <span class="pnl-svc-ic"><svg class="icon"><use href="#i-wrench"/></svg></span>

    <span class="svc-row-t">
      <b>{{ $s->name }}</b>
      @if($s->description)<small>{{ \Illuminate\Support\Str::limit($s->description, 90) }}</small>@endif
    </span>

    <span class="svc-row-m">
      {{-- ⚠️ سرویسِ «یک‌بار» سررسیدِ بعدی **ندارد** (`nextDueFrom()` عمداً برای
           `monthsIn()===0` نال می‌دهد)، پس ردیفِ قبلی یک خط‌تیرهٔ همیشگی نشان
           می‌داد. حالا حقیقت را می‌گوید. --}}
      @if($recurring)
        <small>{{ __('ui.oth_renews') }} · {{ sdate($s->next_due_at) }}</small>
      @else
        <small>{{ __('ui.oth_once') }}</small>
      @endif
      <b class="pnl-num">{{ invoice_money($s->total(), $s->currency_code) }}</b>
    </span>

    {{-- قرصِ وضعیت فقط وقتی چیزی برای گفتن هست: یک خدمتِ فعالِ سالم نشان لازم ندارد --}}
    @if($s->status !== 'active' || $s->provision_status === 'failed')
      @include('account.partials.status-pill', ['s' => $s])
    @endif
  </div>

  <div class="svc-row-a">
    @include('account.partials.svc-actions', ['s' => $s, 'kind' => 'other'])
  </div>
</li>
