{{--
  صورت‌حسابِ ساعتی و پایانِ اعتبار — صفحهٔ مدیریتِ سرور در پنل مشتری.

  🔴 چرا: پرتکرارترین تماسِ پشتیبانیِ ساعتی «چرا سرورم خاموش شد؟ / چرا حذف
     شد؟ / خاموش کردم چرا کسر شد؟» بود (کارفرما، شهریور ۱۴۰۵). همهٔ پاسخ‌ها
     این‌جا، با عددهای **همین** سرور، پیش از آن‌که پرسیده شوند.

  ⚠️ عددها از همان منبعی می‌آیند که متر با آن کار می‌کند: «در دسترس» از
     Wallet (منهایِ ذخیرهٔ نگهداری و رزروِ AI)، مهلت از `HourlyHold`، آستانهٔ
     هشدار از `CloudMeterHourly`.

  ورودی: $service (Service ساعتی)
--}}
@php
  $hbFa = app()->getLocale() === 'fa';
  $hbN = fn ($v) => $hbFa ? fa_num((string) $v) : (string) $v;
  $hbRate = (int) $service->hourly_rate_irt;
  $hbAvail = max(0, app(\App\Services\Finance\Wallet::class)->availableOf($service->customer_id));
  $hbHours = $hbRate > 0 ? intdiv($hbAvail, $hbRate) : 0;
  $hbHoldOn = \App\Services\Cloud\HourlyHold::enabled();
  $hbHoldRate = $hbHoldOn ? (int) $service->hold_rate_irt : 0;
  $hbReserve = $hbHoldOn ? (int) $service->hold_reserve_irt : 0;
  $hbPolicy = (string) ($service->on_credit_out ?: 'suspend');
  $hbGrace = $hbN(\App\Services\Cloud\HourlyHold::GRACE_HOURS);
  $hbWarn = $hbN(\App\Console\Commands\CloudMeterHourly::LOW_CREDIT_HOURS);
  $hbInterruptible = (bool) $service->cloudPlan?->is_interruptible;
  $hbSuspended = $service->status === 'suspended' && $service->suspended_at !== null;
  $hbDeletesAt = \App\Services\Cloud\HourlyHold::deletesAt($service);
  $hbLeft = $hbDeletesAt ? max(0, (int) ceil(now()->diffInMinutes($hbDeletesAt, false) / 60)) : 0;
  $hbNeed = $hbSuspended
      ? max(0, $hbRate + \App\Services\Cloud\HourlyHold::fullReserve($hbHoldRate, $hbPolicy) - ($hbAvail + $hbReserve))
      : 0;
  $hbTime = fn ($c) => sdate($c, true);
  $hbStepEnd = match ($hbPolicy) {
      'terminate' => __('ui.hb_step_terminate'),
      'convert' => __('ui.hb_step_convert', ['grace' => $hbGrace, 'monthly' => cloud_price((int) $service->price)]),
      default => __('ui.hb_step_suspend', ['grace' => $hbGrace]),
  };
@endphp

<section class="pnl-sec hb-sec" id="hourly-billing" @if($hbSuspended) style="border-color:var(--danger-line)" @endif>
  <div class="pnl-sec-h"><h2>{{ __('ui.hb_h') }}</h2></div>
  <div class="pnl-sec-b">

    @if($hbSuspended)
      <div class="hb-alert">
        <b>{{ __('ui.hb_susp_h') }}</b>
        <p>{{ __('ui.hb_susp_p', ['at' => $hbDeletesAt ? $hbTime($hbDeletesAt) : '—', 'left' => $hbN($hbLeft)]) }}</p>
        @if($hbNeed > 0)<p>{{ __('ui.hb_susp_need', ['need' => cloud_price($hbNeed)]) }}</p>@endif
        <a class="pnl-btn primary" href="{{ lroute('account.topup') }}">{{ __('ui.hb_topup') }}</a>
      </div>
    @endif

    <div class="hb-facts">
      <div class="hb-fact"><small>{{ __('ui.hb_rate') }}</small><b>{{ cloud_hourly_price($hbRate) }}{{ __('ui.cvb_hourly_per') }}</b></div>
      <div class="hb-fact {{ ! $hbSuspended && $hbHours < 24 ? 'is-warn' : '' }}">
        <small>{{ __('ui.hb_spendable') }}</small>
        <b>{{ cloud_price($hbAvail) }}</b>
        @unless($hbSuspended)
          <em>{{ __('ui.hb_hours_left', ['hours' => $hbN($hbHours)]) }} · {{ __('ui.hb_until', ['time' => $hbTime(now()->addHours($hbHours))]) }}</em>
        @endunless
      </div>
      <div class="hb-fact">
        <small>{{ __('ui.hb_reserve', ['grace' => $hbGrace]) }}</small>
        @if($hbPolicy === 'terminate' && ! $hbSuspended)
          <b>{{ __('ui.hb_reserve_none') }}</b>
        @elseif($hbHoldRate > 0)
          <b>{{ cloud_price($hbReserve) }}</b>
          <em>{{ __('ui.hb_reserve_d') }}</em>
        @else
          <b>{{ __('ui.hb_reserve_free') }}</b>
          <em>{{ __('ui.hb_reserve_free_d', ['grace' => $hbGrace]) }}</em>
        @endif
      </div>
    </div>

    <h3 class="hb-t">{{ __('ui.hb_steps_t') }}</h3>
    <ol class="hb-steps">
      <li>{{ __('ui.hb_step_warn', ['warn' => $hbWarn]) }}</li>
      <li>{{ $hbStepEnd }}</li>
      @if($hbPolicy !== 'terminate')
        <li>{{ __('ui.hb_step_topup', ['grace' => $hbGrace]) }}</li>
        <li class="hb-danger">{{ __('ui.hb_step_delete', ['grace' => $hbGrace]) }}</li>
      @endif
      <li>{{ $hbInterruptible ? __('ui.hb_step_off_free') : __('ui.hb_step_off_billed') }}</li>
    </ol>

    <form class="hb-form" method="post" action="{{ lroute('account.cloud.credit-policy', $service) }}">
      @csrf
      <label>
        <span>{{ __('ui.hb_policy') }}</span>
        <select name="on_credit_out" @disabled($hbSuspended)>
          <option value="suspend" @selected($hbPolicy === 'suspend')>{{ __('ui.cvb_hourly_end_suspend', ['grace' => $hbGrace]) }}</option>
          <option value="convert" @selected($hbPolicy === 'convert')>{{ __('ui.cvb_hourly_end_convert', ['grace' => $hbGrace]) }}</option>
          <option value="terminate" @selected($hbPolicy === 'terminate')>{{ __('ui.cvb_hourly_end_terminate') }}</option>
        </select>
      </label>
      <button class="pnl-btn" @disabled($hbSuspended)>{{ __('ui.hb_policy_save') }}</button>
      @if($hbSuspended)<small>{{ __('ui.hb_policy_locked') }}</small>@endif
    </form>
  </div>
</section>
