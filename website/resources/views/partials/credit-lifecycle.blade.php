{{--
  «وقتی اعتبار تمام شود دقیقاً چه می‌شود؟» — صفحهٔ فروشِ ساعتی و GPU.

  🔴 پرتکرارترین تماسِ پشتیبانیِ ساعتی همین پرسش بود (کارفرما، شهریور ۱۴۰۵).
     عددها از همان ثابت‌هایی می‌آیند که متر با آن‌ها کار می‌کند
     (`HourlyHold::GRACE_HOURS`، `CloudMeterHourly::LOW_CREDIT_HOURS`) — متنی که
     عددش جدا از کد نوشته شود، روزی دروغ می‌گوید.

  ورودی: $clMode = 'vps' | 'gpu'
  ⚠️ استایل درجا با پیشوندِ `crl-` (`cl-` مالِ admin/cloud است): کلاسِ نبود بی‌خطا بی‌استایل رندر می‌شود.
--}}
@php
  $clGpu = ($clMode ?? 'vps') === 'gpu';
  $clFa = app()->getLocale() === 'fa';
  $clGrace = $clFa ? fa_num((string) \App\Services\Cloud\HourlyHold::GRACE_HOURS) : (string) \App\Services\Cloud\HourlyHold::GRACE_HOURS;
  $clWarn = $clFa ? fa_num((string) \App\Console\Commands\CloudMeterHourly::LOW_CREDIT_HOURS) : (string) \App\Console\Commands\CloudMeterHourly::LOW_CREDIT_HOURS;
  $clRep = ['grace' => $clGrace, 'warn' => $clWarn];
  $clSteps = [
    ['t' => __('ui.cl_1_t'), 'd' => __('ui.cl_1_d', $clRep), 'k' => 'warn'],
    ['t' => __('ui.cl_2_t'), 'd' => __($clGpu ? 'ui.cl_2_d_gpu' : 'ui.cl_2_d', $clRep), 'k' => 'off'],
    ['t' => __('ui.cl_3_t'), 'd' => __('ui.cl_3_d', $clRep), 'k' => 'on'],
    ['t' => __('ui.cl_4_t', $clRep), 'd' => __('ui.cl_4_d', $clRep), 'k' => 'del'],
  ];
@endphp
<section class="section crl-sec" id="credit-runs-out">
  <div class="container">
    <div class="crl-head">
      <h2>{{ __('ui.cl_t') }}</h2>
      <p>{{ __('ui.cl_d') }}</p>
    </div>

    <ol class="crl-steps">
      @foreach($clSteps as $i => $s)
        <li class="crl-step crl-{{ $s['k'] }}">
          <span class="crl-n">{{ $clFa ? fa_num((string) ($i + 1)) : $i + 1 }}</span>
          <h3>{{ $s['t'] }}</h3>
          <p>{{ $s['d'] }}</p>
        </li>
      @endforeach
    </ol>

    <div class="crl-notes">
      <div class="crl-note">
        <b>{{ __('ui.cl_hold_t', $clRep) }}</b>
        <p>{{ __($clGpu ? 'ui.cl_hold_d_gpu' : 'ui.cl_hold_d', $clRep) }}</p>
      </div>
      <div class="crl-note">
        <b>{{ __('ui.cl_off_t') }}</b>
        <p>{{ __($clGpu ? 'ui.cl_off_d_gpu' : 'ui.cl_off_d') }}</p>
      </div>
      <div class="crl-note">
        <b>{{ __('ui.cl_choice_t') }}</b>
        <p>{{ __('ui.cl_choice_d') }}</p>
      </div>
    </div>
  </div>
</section>

<style>
.crl-sec{ padding:44px 0 }
.crl-head{ max-width:860px; margin-bottom:22px }
.crl-head h2{ font-family:var(--font-disp); font-size:clamp(20px,3vw,27px); font-weight:700; letter-spacing:-.5px; margin-bottom:10px }
.crl-head p{ color:var(--muted); font-size:14.2px; line-height:2 }
.crl-steps{ list-style:none; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin:0; padding:0 }
.crl-step{ position:relative; border:1px solid var(--line); border-radius:18px; background:var(--surface); padding:20px }
.crl-n{ display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%;
  background:var(--surface-2); border:1px solid var(--line-2); color:var(--text); font-weight:700; font-size:14px; margin-bottom:12px }
.crl-warn .crl-n{ border-color:rgba(251,191,36,.5); color:#fbbf24 }
.crl-off .crl-n{ border-color:rgba(34,211,238,.45); color:var(--cyan) }
.crl-on .crl-n{ border-color:rgba(52,211,153,.5); color:#34d399 }
.crl-del{ border-color:rgba(248,113,113,.35) }
.crl-del .crl-n{ border-color:rgba(248,113,113,.55); color:#f87171 }
.crl-step h3{ font-size:15px; font-weight:700; margin-bottom:8px }
.crl-step p{ color:var(--muted); font-size:13.2px; line-height:1.95; margin:0 }
.crl-notes{ display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:14px; margin-top:14px }
.crl-note{ border:1px dashed var(--line-2); border-radius:16px; padding:16px 18px }
.crl-note b{ display:block; font-size:14px; margin-bottom:6px }
.crl-note p{ color:var(--muted); font-size:13px; line-height:1.95; margin:0 }
@media(max-width:640px){ .crl-sec{ padding:32px 0 } }
</style>
