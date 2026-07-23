@extends('panel.layout')
@section('title', 'افزایش اعتبار — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>افزایش اعتبار</h1>
    <p>موجودی فعلی: <b class="pnl-num">{{ fa_num(number_format($balance)) }}</b> تومان</p>
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
  <div class="pnl-sec-h"><h2>مبلغ را انتخاب کنید</h2></div>
  <div class="pnl-sec-b">

    @if(count($gateways) === 0)
      <p style="font-size:13.5px;color:var(--warn);line-height:2;margin:0">
        هیچ درگاه پرداختی هنوز فعال نیست.
      </p>

    @else
      <form method="POST" action="{{ lroute('account.topup.start') }}"
            style="display:flex;flex-direction:column;gap:18px;max-width:460px">
        @csrf

        <div>
          <label for="amount" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:8px">
            مبلغ (تومان)
          </label>
          <input type="text" id="amount-view" dir="ltr" inputmode="numeric" required
                 placeholder="۵۰۰٬۰۰۰"
                 style="width:100%;box-sizing:border-box;background:var(--surface-2);
                        border:1px solid var(--line);border-radius:12px;padding:13px 15px;
                        font:inherit;font-size:17px;color:var(--text);
                        font-variant-numeric:tabular-nums;letter-spacing:.03em">
          <input type="hidden" name="amount" id="amount" value="{{ old('amount') }}">
          <small style="display:block;margin-top:8px;font-size:11.5px;color:var(--dim);line-height:1.85">
            کمترین مبلغ ۱٬۰۰۰ تومان.
          </small>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
          @foreach([200000, 500000, 1000000, 2000000] as $q)
            <button type="button" class="pnl-btn quick" data-v="{{ $q }}">
              {{ fa_num(number_format($q)) }}
            </button>
          @endforeach
        </div>

        <div>
          <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:8px">روش پرداخت</label>
          <div style="display:flex;flex-direction:column;gap:8px">
            @foreach($gateways as $key => $g)
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                            border:1px solid var(--line);border-radius:12px;padding:13px 15px;
                            background:var(--surface-2)">
                <input type="radio" name="gateway" value="{{ $key }}" {{ $loop->first ? 'checked' : '' }}
                       style="accent-color:#22D3EE">
                <b style="font-size:13px">{{ $key === 'zarinpal' ? 'زرین‌پال — کارت بانکی' : $key }}</b>
              </label>
            @endforeach
          </div>
        </div>

        <button type="submit" class="pnl-btn primary" style="justify-content:center">
          <svg class="icon"><use href="#i-coins"/></svg>انتقال به درگاه
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
    @endif

  </div>
</section>

@endsection
