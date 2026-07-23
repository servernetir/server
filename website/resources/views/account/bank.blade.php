@extends('panel.layout')
@section('title', 'حساب بانکی — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>حساب بانکی</h1>
    <p>برای تسویه و بازگشت وجه</p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px">{{ session('ok') }}</div>
  </div>
@endif

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

{{-- ==== حساب‌های ثبت‌شده ==== --}}
@if($accounts->isNotEmpty())
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>حساب‌های شما</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead>
          <tr><th>بانک</th><th>کارت</th><th>شبا</th><th>وضعیت</th></tr>
        </thead>
        <tbody>
          @foreach($accounts as $a)
          <tr>
            <td>{{ $a->bank_name ?: '—' }}{!! $a->is_default ? ' <span class="pnl-pill info" style="font-size:10px">پیش‌فرض</span>' : '' !!}</td>
            <td dir="ltr">{{ $a->card_bin }}••••••{{ $a->card_last4 }}</td>
            <td dir="ltr" style="font-size:12px">{{ $a->iban ?: '—' }}</td>
            <td>
              @if($a->status === 'verified')
                <span class="pnl-pill ok">تأیید شده</span>
              @elseif($a->status === 'rejected')
                <span class="pnl-pill danger">رد شده</span>
              @else
                <span class="pnl-pill warn">در بررسی</span>
              @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>
@endif

{{-- ==== افزودن ==== --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>افزودن حساب بانکی</h2></div>
  <div class="pnl-sec-b">

    @if($identity?->status !== 'verified')
      <p style="font-size:13.5px;color:var(--warn);line-height:2;margin:0">
        اول باید احراز هویت را کامل کنید. بدون نام رسمی، تطابق صاحب کارت ممکن نیست.
      </p>

    @else
      <div style="border:1px solid var(--line);border-inline-start:3px solid var(--info);
                  border-radius:10px;padding:12px 14px;margin-bottom:18px;
                  font-size:12.5px;color:var(--muted);line-height:2">
        فقط کارتی که به نام <b style="color:var(--text)">{{ trim($identity->first_name.' '.$identity->last_name) }}</b>
        باشد پذیرفته می‌شود.<br>
        شمارهٔ کارت شما <b style="color:var(--text)">ذخیره نمی‌شود</b> — بعد از تأیید،
        فقط شبا، شماره حساب و شش رقم اول و چهار رقم آخر کارت نگه داشته می‌شود.
        @if($nameLocked)
          <br>چون حساب بانکی تأییدشده دارید، نام شما دیگر قابل تغییر نیست.
        @endif
      </div>

      <form method="POST" action="{{ lroute('account.bank.store') }}" style="display:flex;flex-direction:column;gap:14px;max-width:420px">
        @csrf
        <div>
          <label for="card" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:7px">شمارهٔ کارت (۱۶ رقم)</label>
          <input type="text" id="card" name="card" dir="ltr" inputmode="numeric" maxlength="19"
                 placeholder="6037 9912 3456 7893" required
                 style="width:100%;box-sizing:border-box;background:var(--surface-2);
                        border:1px solid var(--line);border-radius:12px;padding:12px 14px;
                        font:inherit;font-size:15px;color:var(--text);letter-spacing:.08em;
                        font-variant-numeric:tabular-nums">
        </div>
        <button type="submit" class="pnl-btn primary" id="bgo" style="justify-content:center">
          <svg class="icon"><use href="#i-check"/></svg>استعلام و ثبت
        </button>
      </form>

      <script>
      (function () {
        var c = document.getElementById('card');
        // گروه‌بندی چهارتایی: خواندن ۱۶ رقم پیوسته برای چشم سخت است
        c.addEventListener('input', function () {
          var d = this.value.replace(/[^0-9]/g, '').slice(0, 16);
          this.value = (d.match(/.{1,4}/g) || []).join(' ');
        });
        c.form.addEventListener('submit', function () {
          var b = document.getElementById('bgo');
          b.disabled = true;
          b.textContent = 'در حال استعلام…';
        });
      })();
      </script>
    @endif

  </div>
</section>

@endsection
