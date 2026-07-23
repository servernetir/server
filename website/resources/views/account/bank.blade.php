@extends('panel.layout')
@section('title', __('ui.pnl_bank_title').' — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.pnl_bank_title') }}</h1>
    <p>{{ __('ui.pnl_bank_why') }}</p>
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

{{-- ══ حساب‌های ثبت‌شده ══
     کارت به‌جای جدول: یک حساب بانکی چند مقدار بلند دارد (شبا ۲۶ نویسه) که
     در جدول ستون را می‌شکند. کارت هر حساب را مستقل و خوانا نگه می‌دارد. --}}
@foreach($accounts as $a)
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <span class="bank-head">
      @include('panel.bank-mark', ['bank' => $a->bank()])
      <h2>{{ $a->bankLabel() }}</h2>
    </span>
    <span style="display:flex;gap:6px;align-items:center">
      @if($a->is_default)<span class="pnl-pill info" style="font-size:10px">{{ __('ui.pnl_default') }}</span>@endif
      @if($a->status === 'verified')
        <span class="pnl-pill ok">{{ __('ui.pnl_verified') }}</span>
      @elseif($a->status === 'rejected')
        <span class="pnl-pill danger">{{ __('ui.pnl_rejected') }}</span>
      @else
        <span class="pnl-pill warn">{{ __('ui.pnl_reviewing') }}</span>
      @endif
    </span>
  </div>
  <div class="pnl-sec-b">
    <dl class="spec">
      <div>
        <dt>{{ __('ui.pnl_card') }}</dt>
        <dd dir="ltr" class="num">{{ fa_num($a->card_bin) }}••••••{{ fa_num($a->card_last4) }}</dd>
      </div>
      <div>
        <dt>{{ __('ui.pnl_account_no') }}</dt>
        <dd dir="ltr" class="num">{{ $a->account_number ? fa_num($a->account_number) : '—' }}</dd>
      </div>
      <div>
        <dt>{{ __('ui.pnl_iban') }}</dt>
        <dd dir="ltr" class="num">{{ $a->iban ? fa_num($a->iban) : '—' }}</dd>
      </div>
      @if($a->verified_at)
        <div>
          <dt>{{ __('ui.pnl_verified_at') }}</dt>
          <dd class="num">{{ blog_date((string) $a->verified_at) }}</dd>
        </div>
      @endif
    </dl>
  </div>
</section>
@endforeach

{{-- ══ افزودن ══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.pnl_bank_add') }}</h2></div>
  <div class="pnl-sec-b">

    @if($identity?->status !== 'verified')
      <p style="font-size:13.5px;color:var(--warn);line-height:2;margin:0">
        {{ __('ui.pnl_bank_needs_identity') }}
      </p>

    @else
      <div class="bank-note">
        {!! __('ui.pnl_bank_only_yours', ['name' => '<b>'.e(trim($identity->first_name.' '.$identity->last_name)).'</b>']) !!}<br>
        {!! __('ui.pnl_bank_no_pan') !!}
        @if($nameLocked)<br>{{ __('ui.pnl_name_locked') }}@endif
      </div>

      <form method="POST" action="{{ lroute('account.bank.store') }}" class="bank-form">
        @csrf
        <div>
          <label for="card">{{ __('ui.pnl_card_16') }}</label>
          <div class="card-row">
            @include('panel.bank-mark', ['bank' => null])
            <input type="text" id="card" name="card" dir="ltr" inputmode="numeric" maxlength="19"
                   placeholder="6037 9912 3456 7893" required
                   aria-describedby="bank-guess">
          </div>
          {{-- تشخیص بانک همان لحظه که شش رقم اول کامل شد — رایگان، محلی،
               و قبل از هر استعلام پولی. اگر کاربر کارت اشتباه بزند، همان‌جا
               می‌بیند که بانکش آن نیست. --}}
          <small id="bank-guess" aria-live="polite"></small>
        </div>
        <button type="submit" class="pnl-btn primary" id="bgo" style="justify-content:center">
          <svg class="icon"><use href="#i-check"/></svg><span class="txt">{{ __('ui.pnl_bank_submit') }}</span>
        </button>
      </form>

      @php
        // فقط شش‌رقمی‌ها لازم‌اند و نه کل جدول — سبک‌تر و بدون افشای
        // چیزی که به کار مرورگر نمی‌آید
        $binMap = [];
        foreach ((array) config('banks.bins') as $bin => $slug) {
            $b = \App\Support\IranianBank::bySlug($slug);
            if ($b) { $binMap[$bin] = ['n' => $b['name'], 's' => $b['short'], 'c' => $b['color']]; }
        }
      @endphp

      <script>
      (function () {
        var c = document.getElementById('card'),
            guess = document.getElementById('bank-guess'),
            mark = document.querySelector('.card-row .bank-mark'),
            BINS = @json($binMap),
            WORKING = @json(__('ui.auth_kyc_working'));

        function showBank(d) {
          var b = d.length >= 6 ? BINS[d.slice(0, 6)] : null;

          if (b) {
            mark.style.setProperty('--bk', b.c);
            mark.innerHTML = '<i>' + b.s + '</i>';
            mark.title = b.n;
            guess.textContent = b.n;
            guess.style.color = 'var(--ok)';
          } else {
            mark.style.removeProperty('--bk');
            mark.innerHTML = '<svg class="icon"><use href="#i-db"/></svg>';
            mark.title = '';
            guess.textContent = '';
          }
        }

        // گروه‌بندی چهارتایی: خواندن ۱۶ رقم پیوسته برای چشم سخت است.
        // ارقام فارسی هم پذیرفته می‌شوند چون کاربر با کیبورد فارسی تایپ می‌کند.
        c.addEventListener('input', function () {
          var d = this.value
            .replace(/[۰-۹]/g, function (x) { return x.charCodeAt(0) - 1776; })
            .replace(/[٠-٩]/g, function (x) { return x.charCodeAt(0) - 1632; })
            .replace(/[^0-9]/g, '').slice(0, 16);
          this.value = (d.match(/.{1,4}/g) || []).join(' ');
          showBank(d);
        });

        c.form.addEventListener('submit', function () {
          var b = document.getElementById('bgo');
          b.disabled = true;
          b.querySelector('.txt').textContent = WORKING;
        });
      })();
      </script>
    @endif

  </div>
</section>

<style>
.bank-note{
  border:1px solid var(--line); border-inline-start:3px solid var(--info);
  border-radius:10px; padding:13px 15px; margin-bottom:20px;
  background:var(--surface-2);
  font-size:12.5px; color:var(--muted); line-height:2;
}
.bank-note b{ color:var(--text); }

/* نشان بانک کنار فیلد کارت — همان اندازهٔ فیلد تا خط پایه یکی بماند */
.card-row{ display:flex; align-items:center; gap:10px; }
.card-row .bank-mark{ width:48px; height:48px; border-radius:12px; }
.card-row input{ flex:1; min-width:0; }

.bank-form{ display:flex; flex-direction:column; gap:16px; max-width:460px; }
.bank-form label{ display:block; font-size:12.5px; font-weight:600; margin-bottom:8px; }
.bank-form input{
  width:100%; box-sizing:border-box; min-height:48px;
  background:var(--surface-2); border:1px solid var(--line); border-radius:12px;
  padding:12px 15px; font:inherit; font-size:16px; color:var(--text);
  letter-spacing:.1em; font-variant-numeric:tabular-nums;
  transition:border-color .18s var(--ease), box-shadow .18s var(--ease);
}
.bank-form input:focus{
  outline:none; border-color:var(--line-2);
  box-shadow:0 0 0 3px rgba(34,211,238,.16);
}
</style>

@endsection
