@extends('panel.layout')
@section('title', $domain->domain)

@section('panel')

<div class="pnl-head">
  <div>
    {{-- ⚠️ همهٔ لینک‌ها و همهٔ action‌های این صفحه با lroute() ساخته می‌شوند.
         روت‌های account داخلِ closureِ $site‌اند، پس /en/account/… و
         /tr/account/… وجود دارند؛ route()ِ خام چهار فرمِ مدیریتیِ این صفحه
         (نام‌سرور، قفل، کد انتقال، تمدید خودکار) را به آدرسِ فارسی POST
         می‌کرد و زبانِ مشتری وسطِ کار عوض می‌شد. --}}
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ lroute('account.home') }}">{{ __('ui.crumb_panel') }}</a><span>/</span>
      <a href="{{ lroute('account.domains') }}">{{ __('ui.crumb_domains') }}</a><span>/</span>
      <span dir="ltr">{{ $domain->domain }}</span>
    </nav>
    <h1 dir="ltr">{{ $domain->domain }}</h1>
  </div>
  @if($domain->isActive())
    <span class="pnl-pill ok">{{ __('ui.dpg_active') }}</span>
  @elseif($domain->status === 'expired')
    <span class="pnl-pill danger">{{ __('ui.dpg_expired') }}</span>
  @elseif($domain->provision_status === 'manual')
    <span class="pnl-pill danger">{{ __('ui.dpg_manual') }}</span>
  @else
    <span class="pnl-pill info">{{ __('ui.dpg_pending') }}</span>
  @endif
</div>

@if(session('ok'))<div class="dm-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="dm-note danger">{{ $errors->first() }}</div>@endif

{{-- 🔴 کدِ انتقال فقط **یک بار** و فقط از session نشان داده می‌شود: ذخیره‌اش
     نمی‌کنیم چون کلیدِ مالکیت است و هرکس داشته باشد دامنه را می‌برد. --}}
@if(session('authCode'))
  <div class="dm-note warn">
    <b>{{ __('ui.dpg_epp_label') }}</b>
    <code dir="ltr" style="user-select:all;font-size:14px">{{ session('authCode') }}</code>
    <br><small>{{ __('ui.dpg_epp_note') }}</small>
  </div>
@endif

{{-- 🔴 سفارشِ انتقال پیامِ خودش را دارد. تا ممیزیِ شهریور ۱۴۰۵ این صفحه به
     ردیفِ انتقال هم می‌گفت «در صف ثبت است» — و بدتر: پیامِ بعد از پرداخت
     می‌گفت «کد انتقال را در همین صفحه وارد کنید» ولی فرمی وجود نداشت.
     مشتری پول داده بود و به بن‌بست می‌خورد. --}}
@if($domain->isTransfer() && ! $domain->isActive() && $domain->status !== 'expired')
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('ui.dpg_transfer_h') }}</h2></div>
    <div class="pnl-sec-b">
      @if($domain->transfer_status === 'pending' && $transferUnpaid !== null)
        <p style="margin-top:0">{{ __('ui.dpg_transfer_pay') }}</p>
        <a class="pnl-btn" href="{{ lroute('account.invoice', $transferUnpaid) }}">{{ __('ui.dpg_transfer_pay_btn') }}</a>
      @elseif($domain->transfer_status === 'pending')
        <p style="margin-top:0">
          {{ __('ui.dpg_transfer_enter') }}
        </p>
        <form method="post" action="{{ lroute('account.domain.transfer.submit', $domain) }}"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          @csrf
          <input name="auth_code" dir="ltr" class="pnl-input" style="max-width:280px"
                 autocomplete="off" placeholder="EPP / Auth Code" required minlength="4">
          <button class="pnl-btn" type="submit">{{ __('ui.dpg_transfer_start_btn') }}</button>
        </form>
        <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
          {{ __('ui.dpg_epp_not_stored') }}
        </p>
      @elseif($domain->transfer_status === 'submitted')
        <p style="margin:0">
          {{ __('ui.dpg_transfer_wait') }}
        </p>
      @elseif($domain->transfer_status === 'failed')
        <p style="margin:0">
          {{ __('ui.dpg_transfer_fail') }}
        </p>
      @endif
    </div>
  </section>
@elseif($domain->status === 'expired')
  {{-- 🔴 مسیرِ نجات (redemption) — تا شهریور ۱۴۰۵ دامنهٔ منقضی از پنل غیب
       می‌شد و تنها راهش «تماس با پشتیبانی» بود، دقیقاً در پنجره‌ای که هنوز
       می‌شد نجاتش داد. --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('ui.dpg_restore_h') }}</h2></div>
    <div class="pnl-sec-b">
      @php $dmRestoreFee = (int) \App\Models\Setting::get('domain_restore_fee_toman'); @endphp
      @if($domain->provision_status === 'pending' || $domain->provision_status === 'running')
        <p style="margin:0">{{ __('ui.dpg_restore_paid') }}</p>
      @elseif($domain->provision_status === 'manual')
        <p style="margin:0">{{ __('ui.dpg_restore_manual') }}</p>
      @elseif($dmRestoreFee > 0 && $domain->op_id)
        <p style="margin-top:0">
          {{ __('ui.dpg_restore_offer', ['fee' => cloud_price($dmRestoreFee)]) }}
          <b>{{ __('ui.dpg_restore_urgent') }}</b>
        </p>
        <form method="post" action="{{ lroute('account.domain.restore', $domain) }}">
          @csrf
          <button class="pnl-btn" type="submit">{{ __('ui.dpg_restore_btn') }}</button>
        </form>
        <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
          {{ __('ui.dpg_restore_refund') }}
        </p>
      @else
        <p style="margin:0">{{ __('ui.dpg_restore_contact') }}</p>
      @endif
    </div>
  </section>
@elseif(! $domain->isActive())
  <section class="pnl-sec">
    <div class="pnl-sec-b">
      @if($domain->provision_status === 'manual')
        <p>{{ __('ui.dpg_reg_manual') }}</p>
      @else
        <p>{{ __('ui.dpg_reg_queue') }}</p>
      @endif
    </div>
  </section>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.dpg_specs_h') }}</h2></div>
  <div class="pnl-sec-b">
    <div class="pnl-tw">
      <table class="pnl-table">
        <tbody>
          <tr><th>{{ __('ui.dpg_reg_date') }}</th><td>{{ $domain->registered_at ? sdate($domain->registered_at) : '—' }}</td></tr>
          <tr><th>{{ __('ui.dpg_exp_date') }}</th><td>{{ $domain->expires_at ? sdate($domain->expires_at) : '—' }}</td></tr>
          <tr><th>{{ __('ui.dpg_period') }}</th><td>{{ fa_num($domain->period_years) }} {{ __('ui.lbl_year') }}</td></tr>
          {{-- قیمتِ مؤثرِ روز (ذخیره + استعلامِ تازه + کفِ ارزی) — همان عددی
               که فاکتورِ تمدید می‌گیرد، نه عددِ فریزشدهٔ روزِ خرید. --}}
          <tr><th>{{ __('ui.dpg_renew_cost') }}</th><td>{{ cloud_price(($renewUnit ?? 0) > 0 ? $renewUnit : $domain->renew_toman) }} / {{ __('ui.lbl_year') }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

@if($domain->isActive())
{{-- 🔴 تمدیدِ دستی — تا مرداد ۱۴۰۵ اصلاً وجود نداشت: تنها مسیرِ تمدید،
     فاکتورِ خودکارِ کرون در ۲۱ روزِ آخر بود و مشتریِ نگران هیچ دکمه‌ای
     نداشت. مدتِ تازه به پایانِ دورهٔ فعلی اضافه می‌شود؛ تمدیدِ زودتر
     یعنی هیچ روزی از دست نمی‌رود. --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.dpg_renew_h') }}</h2></div>
  <div class="pnl-sec-b">
    {{-- قیمتِ مؤثرِ تمدید از کنترلر می‌آید (renew_toman یا کفِ ارزی، هرکدام بالاتر)
         تا عددِ روی فرم همانی باشد که فاکتور می‌گیرد. --}}
    @php $dmRenewUnit = (int) ($renewUnit ?? ($domain->renew_toman ?: $domain->price_toman)); @endphp
    @if($dmRenewUnit > 0)
      <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
        {{ __('ui.dpg_renew_note') }}
      </p>
      <form method="post" action="{{ lroute('account.domain.renew', $domain) }}"
            style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        @csrf
        <select name="years" class="pnl-input" style="max-width:260px">
          @foreach(range(1, 5) as $y)
            <option value="{{ $y }}">{{ fa_num($y) }} {{ __('ui.lbl_year') }} — {{ cloud_price($dmRenewUnit * $y) }}</option>
          @endforeach
        </select>
        <button class="pnl-btn" type="submit">{{ __('ui.dpg_renew_btn') }}</button>
      </form>
      <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
        {{ __('ui.dpg_tax_note') }}
      </p>
    @else
      <p style="margin:0">{{ __('ui.dpg_renew_noprice') }}</p>
    @endif
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.dpg_ns_h') }}</h2>
  </div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
      {{ __('ui.dpg_ns_note') }}
    </p>
    <form method="post" action="{{ lroute('account.domain.ns', $domain) }}">
      @csrf
      @foreach(range(0, 3) as $i)
        <p class="dm-ns-row">
          <label for="ns{{ $i }}" style="display:block;font-size:12.5px;margin-bottom:4px">
            {{ __('ui.dpg_ns_label') }} {{ fa_num($i + 1) }}@if($i < 2) <span style="color:var(--danger)">*</span>@endif
          </label>
          <input id="ns{{ $i }}" name="ns[]" dir="ltr" autocomplete="off"
                 value="{{ $domain->effectiveNameServers()[$i] ?? '' }}"
                 placeholder="{{ $defaultNs[$i] ?? 'ns.example.com' }}">
        </p>
      @endforeach
      <button class="pnl-btn" type="submit">{{ __('ui.dpg_ns_save') }}</button>
    </form>
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.dpg_sec_h') }}</h2></div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
      {{ __('ui.dpg_lock_note') }}
    </p>

    <form method="post" action="{{ lroute('account.domain.lock', $domain) }}" style="display:inline">
      @csrf
      <input type="hidden" name="lock" value="{{ $domain->is_locked ? 0 : 1 }}">
      <button class="pnl-btn" type="submit">
        {{ $domain->is_locked ? __('ui.dpg_lock_off_btn') : __('ui.dpg_lock_on_btn') }}
      </button>
    </form>

    @unless($domain->is_locked)
      <form method="post" action="{{ lroute('account.domain.authcode', $domain) }}" style="display:inline">
        @csrf
        <button class="pnl-btn" type="submit">{{ __('ui.dpg_get_epp_btn') }}</button>
      </form>
    @endunless

    <form method="post" action="{{ lroute('account.domain.autorenew', $domain) }}" style="display:inline">
      @csrf
      <input type="hidden" name="auto_renew" value="{{ $domain->auto_renew ? 0 : 1 }}">
      <button class="pnl-btn" type="submit">
        {{ $domain->auto_renew ? __('ui.dpg_ar_off_btn') : __('ui.dpg_ar_on_btn') }}
      </button>
    </form>

    <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
      {{ __('ui.dpg_cur_status') }} <b>{{ $domain->is_locked ? __('ui.lbl_on') : __('ui.lbl_off') }}</b> ·
      {{ __('ui.dpg_ar_label') }} <b>{{ $domain->auto_renew ? __('ui.lbl_on') : __('ui.lbl_off') }}</b>
    </p>
  </div>
</section>
@endif

@endsection
