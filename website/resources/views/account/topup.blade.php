@extends('panel.layout')
@section('title', __('ui.top_page_title'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.top_heading') }}</h1>
    <p>{{ __('ui.top_current_balance') }} <b class="pnl-num">{{ cloud_price($balance) }}</b></p>
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.top_choose_amount') }}</h2></div>
  <div class="pnl-sec-b">

      <form method="POST" action="{{ lroute('account.topup.start') }}"
            style="display:flex;flex-direction:column;gap:18px;max-width:460px">
        @csrf

        <div>
          <label for="amount" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:8px">
            {{ __('ui.top_amount_label') }}
          </label>
          <input type="text" id="amount-view" dir="ltr" inputmode="numeric" required
                 placeholder="{{ __('ui.top_amount_placeholder') }}"
                 style="width:100%;box-sizing:border-box;background:var(--surface-2);
                        border:1px solid var(--line);border-radius:12px;padding:13px 15px;
                        font:inherit;font-size:17px;color:var(--text);
                        font-variant-numeric:tabular-nums;letter-spacing:.03em">
          <input type="hidden" name="amount" id="amount" value="{{ old('amount') }}">
          <small style="display:block;margin-top:8px;font-size:11.5px;color:var(--dim);line-height:1.85">
            {{ __('ui.top_min_note') }}
          </small>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
          @foreach([200000, 500000, 1000000, 2000000] as $q)
            <button type="button" class="pnl-btn quick" data-v="{{ $q }}">
              {{ cloud_price($q) }}
            </button>
          @endforeach
        </div>

        <button type="submit" class="pnl-btn primary" style="justify-content:center">
          <svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.top_continue_btn') }}
        </button>
      </form>

      <script>
      (function () {
        var view = document.getElementById('amount-view'), real = document.getElementById('amount');
        var faDigits = function (s) { return s.replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
        var toEn = function (s) {
          return s.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
                  .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
        };
        // نمایش با جداکنندهٔ فارسی، ارسال با رقم لاتین — سرور عدد خام می‌خواهد
        function render(n) {
          real.value = n || '';
          view.value = n ? faDigits(Number(n).toLocaleString('en-US')) : '';
        }
        view.addEventListener('input', function () {
          render(toEn(this.value).replace(/[^0-9]/g, '').slice(0, 12));
        });
        document.querySelectorAll('.quick').forEach(function (b) {
          b.addEventListener('click', function () { render(this.dataset.v); view.focus(); });
        });
        if (real.value) render(real.value);
      })();
      </script>

  </div>
</section>

@endsection
