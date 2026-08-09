@extends('panel.layout')
@section('title', __('ui.dsr_my_title'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.dsr_my_title') }}</h1>
    <p>{{ __('ui.dsr_my_lead') }}</p>
  </div>
</div>

@include('account.partials.lens', ['secCounts' => $secCounts, 'secLens' => 'domains'])

@if(session('ok'))<div class="dm-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="dm-note danger">{{ $errors->first() }}</div>@endif

{{-- ثبتِ دامنهٔ تازه — همین‌جا، نه در صفحهٔ دیگر.
     استعلام سمتِ سرور گرفته می‌شود تا قیمتی که مشتری روی دکمه می‌بیند همانی
     باشد که پرداخت می‌کند (استعلام ۱۵ دقیقه اعتبار دارد). --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.dsr_panel_new') }}</h2></div>
  <div class="pnl-sec-b">
    <form method="get" action="{{ lroute('account.domains') }}" class="dm-search">
      <input name="register" dir="ltr" autocomplete="off" value="{{ $query }}"
             placeholder="{{ __('ui.dsr_input_ph') }}">
      <button class="pnl-btn" type="submit">
        <svg class="icon"><use href="#i-search"/></svg>{{ __('ui.dsr_search_btn') }}
      </button>
    </form>

    @if($query !== '')
      {{--
        🔴 بنرِ سطحِ صفحه دیگر از روی ردیف‌ها **حدس زده نمی‌شود**.

        نسخهٔ قبلی «همهٔ ردیف‌ها unknown شدند» را با «استعلام شکست خورد» یکی
        می‌گرفت. آن حدس هم کم‌گو بود (پاسخِ ناقص هیچ بنری نمی‌ساخت) و هم پرگو
        (جستجوی تک‌پسوندیِ ‎.ir بی‌آنکه چیزی خراب باشد بنرِ خرابی می‌داد).
        حالا خودِ سرویس می‌گوید پاسخ گرفته یا نه.

        ⚠️ و مهم‌تر از منطق، **واژه**: متنِ قبلی «استعلام دامنه در این لحظه در
        دسترس نیست» بود. در زبانِ دامنه «در دسترس نیست» یعنی «این نام گرفته
        شده» — یعنی یک قطعیِ رجیسترار عیناً شبیهِ یک جوابِ محصولی خوانده
        می‌شد. همین جمله بود که کارفرما دید و گزارش کرد.
      --}}
      @if($searchFailed)
        <p class="dm-note danger">{{ __('ui.dsr_search_failed') }}</p>
      @elseif(! $lookupOk)
        <p class="dm-note danger">{{ __('ui.dsr_lookup_failed') }}</p>
      @endif

      @forelse($results as $r)
        @php
          // 🔴 وضعیت سمتِ سرور حساب شده (`DomainSearch::stateOf`). این‌جا
          //    دوباره از روی available/orderable نتیجه نمی‌گیریم — همان کارِ
          //    موازی بود که پنل و صفحهٔ عمومی را دو حرفِ متفاوت زدن انداخت.
          $st = $r['state'] ?? \App\Services\Domain\DomainSearch::stateOf($r);
          $ok = $st === 'free' || $st === 'premium';

          /*
          | ⚠️ توضیحِ هر وضعیت با استایلِ درون‌خطی می‌آید و **نه** در
          | `.dm-res-p`: آن کلاس `white-space:nowrap` و رنگِ فیروزه‌ایِ قیمت را
          | دارد، پس یک جملهٔ کامل داخلش نمی‌شکند و از کارت بیرون می‌زند.
          | قاعدهٔ درست یک کلاسِ `.dm-res-note` در `panel.css` است؛ آن فایل
          | همین حالا دستِ عاملِ دیگری است، پس در `blocked_edits` گزارش شد.
          */
          $noteStyle = 'flex:1 1 100%;font-size:12.2px;color:var(--dim);line-height:1.9';
        @endphp
        <div class="dm-res {{ $ok ? 'ok' : 'no' }}" data-state="{{ $st }}">
          <span class="dm-res-n" dir="ltr">{{ $r['domain'] }}</span>

          @if($st === 'taken')
            <span class="pnl-pill mute">{{ __('ui.dsr_taken_pill') }}</span>
          @elseif($st === 'unchecked')
            <span class="pnl-pill warn">{{ __('ui.dsr_unchecked_pill') }}</span>
            <span style="{{ $noteStyle }}">{{ __('ui.dsr_unchecked_note') }}</span>
          @elseif($st === 'unsupported')
            <span class="pnl-pill mute">{{ __('ui.dsr_unsupported_pill') }}</span>
            <span style="{{ $noteStyle }}">{{ __('ui.dsr_unsupported_note') }}</span>
          @elseif($st === 'no_price')
            <span class="pnl-pill warn">{{ __('ui.dsr_no_price_pill') }}</span>
            <span style="{{ $noteStyle }}">{{ ($r['reason'] ?? '') === 'fx_unavailable' ? __('ui.dsr_fx_unavailable') : __('ui.dsr_no_price') }}</span>
          @else
            {{-- ⚠️ پرمیوم **جدا** برچسب می‌خورد. تا امروز پنل شاخهٔ پرمیوم
                 نداشت و یک دامنهٔ ۳۱۲ میلیون تومانی را با همان کلمهٔ «آزاد» و
                 همان دکمهٔ خرید نشان می‌داد که یک دامنهٔ ۱٫۲ میلیونی. --}}
            <span class="pnl-pill {{ $st === 'premium' ? 'warn' : 'ok' }}">
              {{ $st === 'premium' ? __('ui.dsr_premium_pill') : __('ui.dsr_free_pill') }}
            </span>
            <span class="dm-res-p">{{ cloud_price($r['price_toman']) }} <small>{{ __('ui.dsr_year_suffix') }}</small></span>
            @if($st === 'premium')
              <span style="{{ $noteStyle }}">{{ __('ui.dsr_premium_note') }}</span>
            @endif
            <form method="post" action="{{ lroute('account.domains.order') }}">
              @csrf
              <input type="hidden" name="quote_id" value="{{ $r['quote_id'] }}">
              <input type="hidden" name="years" value="1">
              <button class="pnl-btn" type="submit">{{ __('ui.dsr_order_btn') }}</button>
            </form>
          @endif
        </div>
      @empty
        @unless($searchFailed)
          <p class="dm-note">{{ __('ui.dsr_no_results') }}</p>
        @endunless
      @endforelse
    @endif
  </div>
</section>

{{-- دفترِ دامنه‌ها — همان جدولی که نمای «همه» هم نشان می‌دهد، از یک قالبِ
     مشترک. دو تغییرِ ماهوی نسبت به نسخهٔ قبلِ همین جدول:

     ۱) 🔴 قرصِ «فعال» **دروغ می‌گفت**. `Domain::isActive()` فقط
        `status === 'active'` را می‌سنجد و چرخهٔ عمر، دامنه را در کلِ ۳۰ روزِ
        مهلتِ بازیابی روی همان `active` نگه می‌دارد — پس دامنه‌ای که دیروز
        منقضی شده، دقیقاً همان سبزِ دامنهٔ سالم را می‌گرفت.
     ۲) ⚠️ سرستون‌ها و مقدارها از `ui.*` می‌آیند؛ این جدول تا امروز فارسیِ
        سخت‌کد بود روی سایتی که سه‌زبانگی قرارداد اولش است. --}}
@include('account.partials.sec-domains', ['domains' => $domains, 'room' => true])

@endsection
