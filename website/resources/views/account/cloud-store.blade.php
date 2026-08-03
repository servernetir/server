@extends('panel.layout')
@section('title', __('ui.cvb_title'))

{{-- سرورساز: مکان ← پلن ← سیستم‌عامل/نرم‌افزار ← دوره ← نام ← پیش‌فاکتور.

     سه نکتهٔ این فایل:
     ۱) هیچ نام یا شناسهٔ زیرساختی این‌جا نیست — فقط نام عمومی پلن و کد مکان خودمان.
     ۲) مبلغ‌های نمایشی فقط نمایشی‌اند؛ مبلغ نهایی سمت سرور از دیتابیس خوانده می‌شود.
     ۳) کلاس تازه‌ای به panel.css اضافه نشده؛ استایل همین‌جاست (همان الگوی
        account/checkout.blade.php) تا کلاس بی‌استایل رندر نشود. --}}

@section('panel')

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ lroute('account.home') }}">{{ __('ui.cvb_crumb_panel') }}</a><span>/</span>
      <span>{{ __('ui.cvb_crumb') }}</span>
    </nav>
    <h1 class="dash-h">{{ __('ui.cvb_h1') }}</h1>
    <p>{{ __('ui.cvb_intro') }}</p>
  </div>
  <span class="pnl-pill info" style="font-size:12.5px;padding:7px 15px">{{ __('ui.cvb_pill') }}</span>
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

@if(count($groups) === 0)
  {{-- کاتالوگ خالی است. سکوت بدترین حالت است: مشتری فکر می‌کند پنل خراب شده. --}}
  <section class="pnl-sec">
    <div class="pnl-sec-b" style="text-align:center;padding:34px 20px">
      <div style="font-size:34px;margin-bottom:10px">☁️</div>
      <h2 style="font-size:17px;margin:0 0 8px">{{ __('ui.cvb_empty_h') }}</h2>
      <p style="color:var(--muted);font-size:13.5px;line-height:2;margin:0">
        {{ __('ui.cvb_empty_p1') }}<a href="{{ lroute('account.tickets') }}">{{ __('ui.cvb_empty_support') }}</a>{{ __('ui.cvb_empty_p2') }}
      </p>
    </div>
  </section>
@else

@php
  // دادهٔ امن برای جاوااسکریپت — همه از پیش ساخته می‌شوند، چون json با آرایهٔ
  // درون‌خطی پارسر Blade را می‌شکند. هیچ ستون زیرساختی این‌جا نیست.
  $jsPrices = $priceMap;
  $jsImages = $imageMap;
  $jsCycles = $cycleLabels;
  $jsPlans  = collect($planCards)->mapWithKeys(fn ($p) => [$p['slug'] => $p['name']])->all();
  $jsImgLbl = $osCatalog->concat($appCatalog)->mapWithKeys(fn ($i) => [$i->key => $i->icon().' '.$i->label])->all();

  // انتخاب‌های اولیه: با old() تا بازگشت خطا انتخاب کاربر را دور نریزد
  $curSlug  = (string) old('plan', $selectedSlug);
  if (! isset($imageMap[$curSlug])) { $curSlug = $selectedSlug; }

  $okOs  = (array) ($imageMap[$curSlug]['os'] ?? []);
  $okApp = (array) ($imageMap[$curSlug]['app'] ?? []);

  // پیش‌فرض سیستم‌عامل: اوبونتو اگر بود، وگرنه اولین گزینهٔ ممکن
  $defImage = collect($okOs)->first(fn ($k) => str_starts_with($k, 'ubuntu')) ?? ($okOs[0] ?? ($okApp[0] ?? ''));
  $curImage = (string) old('image', $defImage);
  if (! in_array($curImage, array_merge($okOs, $okApp), true)) { $curImage = (string) $defImage; }

  $curCycle = (string) old('cycle', $defCycle);
  if (! in_array($curCycle, $cycles, true)) { $curCycle = $defCycle; }

  $initial = $priceMap[$curSlug][$curCycle] ?? ['cycle' => 0, 'per' => 0, 'first' => 0, 'save' => 0];
@endphp

<form method="POST" action="{{ lroute('account.cloud.store.place') }}" id="cvb-form" class="cvb-wrap">
  @csrf
  <input type="hidden" name="location" value="{{ $locCode }}">

  <div class="cvb-main">

    {{-- ═══ گام ۱: کشور و مکان ═══ --}}
    <section class="pnl-sec">
      <div class="pnl-sec-h">
        <h2><span class="cvb-step">۱</span> {{ __('ui.cvb_s1') }}</h2>
        <span class="cvb-hint">{{ fa_num(count($groups)) }} {{ __('ui.cvb_countries_unit') }}</span>
      </div>
      <div class="pnl-sec-b">
        @foreach($groups as $g)
          <div class="cvb-cgroup">
            <div class="cvb-chead"><span class="cvb-flag">{{ $g['flag'] }}</span><b>{{ $g['label'] }}</b></div>
            <div class="cvb-cities">
              @foreach($g['locations'] as $l)
                {{-- لینک ساده و نه رادیو: با عوض شدن مکان، پلن‌ها هم عوض می‌شوند و
                     سرور باید فهرست تازه را بدهد. بی‌جاوااسکریپت هم کار می‌کند. --}}
                <a class="cvb-city @if((string) $l->code === (string) $locCode) on @endif"
                   href="{{ lroute('account.cloud.store') }}?location={{ urlencode($l->code) }}">
                  {{ $l->cityLabel() !== '' ? $l->cityLabel() : $l->countryLabel() }}
                </a>
              @endforeach
            </div>
          </div>
        @endforeach
        <p class="cvb-note">
          <svg class="icon"><use href="#i-pin"/></svg>
          {{ __('ui.cvb_loc_note') }}
        </p>
      </div>
    </section>

    {{-- ═══ گام ۲: پلن ═══ --}}
    <section class="pnl-sec">
      <div class="pnl-sec-h">
        <h2><span class="cvb-step">۲</span> {{ __('ui.cvb_s2') }}</h2>
        <span class="cvb-hint">{{ $location?->label() ?? '' }}</span>
      </div>
      <div class="pnl-sec-b">
        @if(count($planCards) === 0)
          <p class="cvb-warn">{{ __('ui.cvb_no_plans') }}</p>
        @else
          <div class="cvb-plans">
            @foreach($planCards as $p)
              <label class="cvb-plan @if($p['slug'] === $curSlug) on @endif" data-slug="{{ $p['slug'] }}">
                <input type="radio" name="plan" value="{{ $p['slug'] }}" @checked($p['slug'] === $curSlug)>
                <span class="cvb-pn">{{ $p['name'] }}</span>
                <span class="cvb-specs">
                  <span><svg class="icon"><use href="#i-cpu"/></svg>{{ fa_num($p['vcpu']) }} {{ __('ui.cvb_cores') }}</span>
                  <span><svg class="icon"><use href="#i-server"/></svg>{{ fa_num($p['ram']) }} {{ __('ui.cvb_ram') }}</span>
                  <span><svg class="icon"><use href="#i-hdd"/></svg>{{ fa_num($p['disk']) }}</span>
                  <span><svg class="icon"><use href="#i-globe"/></svg>{{ fa_num($p['traffic']) }} {{ __('ui.cvb_traffic') }}</span>
                </span>
                <span class="cvb-cpukind">{{ $p['cpu'] }}</span>
                <span class="cvb-pp" data-pp>{{ cloud_price($priceMap[$p['slug']][$curCycle]['cycle'] ?? 0) }}</span>
              </label>
            @endforeach
          </div>
        @endif
      </div>
    </section>

    {{-- ═══ گام ۳: سیستم‌عامل یا نرم‌افزار آماده ═══ --}}
    <section class="pnl-sec">
      <div class="pnl-sec-h">
        <h2><span class="cvb-step">۳</span> {{ __('ui.cvb_s3') }}</h2>
        <div class="cvb-tabs">
          <button type="button" class="cvb-tab on" data-tab="os">{{ __('ui.cvb_os') }}</button>
          <button type="button" class="cvb-tab" data-tab="app">{{ __('ui.cvb_app') }}</button>
        </div>
      </div>
      <div class="pnl-sec-b">
        {{-- گزینه‌های ناسازگار با پلن انتخابی پنهان می‌شوند (سمت سرور محاسبه شده،
             جاوااسکریپت فقط با عوض شدن پلن به‌روزش می‌کند). گزینه‌ای که تحویلش
             نشدنی است هرگز نباید دیده شود. --}}
        <div class="cvb-imgs" data-pane="os">
          @php $osByFam = $osCatalog->groupBy(fn ($i) => (string) $i->family); @endphp
          @forelse($osByFam as $fam => $rows)
            <div class="cvb-fam" data-fam="{{ $fam }}">
              <div class="cvb-famh"><img class="cvb-logo sm" src="{{ $rows->first()->logo() }}" alt="" loading="lazy" width="18" height="18">{{ $fam !== '' ? ucfirst($fam) : __('ui.cvb_other') }}</div>
              <div class="cvb-opts">
                @foreach($rows as $img)
                  <label class="cvb-img @if($img->key === $curImage) on @endif"
                         data-key="{{ $img->key }}" @if(! in_array($img->key, $okOs, true)) hidden @endif>
                    <input type="radio" name="image" value="{{ $img->key }}" @checked($img->key === $curImage)>
                    <img class="cvb-logo" src="{{ $img->logo() }}" alt="" loading="lazy" width="20" height="20">
                    <b>{{ $img->label }}</b>
                  </label>
                @endforeach
              </div>
            </div>
          @empty
            <p class="cvb-warn">{{ __('ui.cvb_os_na') }}</p>
          @endforelse
          <p class="cvb-empty" data-empty="os" hidden>{{ __('ui.cvb_os_empty') }}</p>
        </div>

        <div class="cvb-imgs" data-pane="app" hidden>
          @php $appByFam = $appCatalog->groupBy(fn ($i) => (string) $i->family); @endphp
          @forelse($appByFam as $fam => $rows)
            <div class="cvb-fam" data-fam="{{ $fam }}">
              <div class="cvb-famh"><img class="cvb-logo sm" src="{{ $rows->first()->logo() }}" alt="" loading="lazy" width="18" height="18">{{ $fam !== '' ? ucfirst($fam) : __('ui.cvb_other') }}</div>
              <div class="cvb-opts">
                @foreach($rows as $img)
                  <label class="cvb-img @if($img->key === $curImage) on @endif"
                         data-key="{{ $img->key }}" @if(! in_array($img->key, $okApp, true)) hidden @endif>
                    <input type="radio" name="image" value="{{ $img->key }}" @checked($img->key === $curImage)>
                    <img class="cvb-logo" src="{{ $img->logo() }}" alt="" loading="lazy" width="20" height="20">
                    <b>{{ $img->label }}</b>
                  </label>
                @endforeach
              </div>
            </div>
          @empty
            <p class="cvb-warn">{{ __('ui.cvb_app_na') }}</p>
          @endforelse
          <p class="cvb-empty" data-empty="app" hidden>{{ __('ui.cvb_app_empty') }}</p>
        </div>

        <p class="cvb-note">
          <svg class="icon"><use href="#i-key"/></svg>
          {{ __('ui.cvb_os_note') }}
        </p>
      </div>
    </section>

    {{-- ═══ گام ۴: دورهٔ پرداخت ═══ --}}
    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2><span class="cvb-step">۴</span> {{ __('ui.cvb_s4') }}</h2></div>
      <div class="pnl-sec-b">
        <div class="cvb-cycles">
          @foreach($cycles as $cy)
            @php $row = $priceMap[$curSlug][$cy] ?? ['cycle' => 0, 'per' => 0, 'save' => 0]; @endphp
            <label class="cvb-cyc @if($cy === $curCycle) on @endif" data-cyc="{{ $cy }}">
              <input type="radio" name="cycle" value="{{ $cy }}" @checked($cy === $curCycle)>
              <span class="cvb-cyc-t">{{ $cycleLabels[$cy] ?? $cy }}</span>
              <span class="cvb-cyc-p" data-p>{{ cloud_price($row['cycle']) }}</span>
              <span class="cvb-cyc-m" data-m>{{ __('ui.cvb_per_before') }}{{ cloud_price($row['per']) }}{{ __('ui.cvb_per_after') }}</span>
              @if(($row['save'] ?? 0) > 0)
                <span class="cvb-cyc-s">{{ fa_num($row['save']) }}{{ __('ui.cvb_save_suf') }}</span>
              @endif
            </label>
          @endforeach
        </div>

        {{-- ═══ پرداختِ ساعتی ═══
             چک‌باکسِ ساده (نه رادیو) تا **بی‌جاوااسکریپت هم** کار کند: تیک‌خورده
             یعنی billing_mode=hourly، تیک‌نخورده یعنی هیچ‌چیز ارسال نمی‌شود و
             سرور همان چرخهٔ عادی را می‌گیرد. --}}
        @php
          $hRate = (int) ($hourlyMap[$curSlug]['rate'] ?? 0);
          $hMin  = (int) ($hourlyMap[$curSlug]['min'] ?? 0);
          $hOn   = old('billing_mode') === 'hourly';
        @endphp
        @if($hRate > 0)
        <div class="cvb-hourly @if($hOn) on @endif" id="cvb-hourly-box">
          <label class="cvb-hourly-head">
            <input type="checkbox" name="billing_mode" value="hourly" id="cvb-hourly" @checked($hOn)>
            <span class="cvb-hourly-t">
              <b>{{ __('ui.cvb_hourly_t') }}</b>
              <small>{{ __('ui.cvb_hourly_d') }}</small>
            </span>
            <span class="cvb-hourly-p"><span id="cvb-h-rate">{{ cloud_price($hRate) }}</span>{{ __('ui.cvb_hourly_per') }}</span>
          </label>

          <div class="cvb-hourly-body" id="cvb-hourly-body" @if(! $hOn) hidden @endif>
            <p class="cvb-note" style="margin-top:2px">
              <svg class="icon"><use href="#i-info"/></svg>
              {{ __('ui.cvb_hourly_min_pre') }}<b id="cvb-h-min">{{ cloud_price($hMin) }}</b>{{ __('ui.cvb_hourly_min_suf') }}
              — {{ __('ui.cvb_hourly_credit') }}<b>{{ cloud_price($creditIrt) }}</b>
            </p>
            <p class="cvb-warn" id="cvb-h-low" @if($creditIrt >= $hMin) hidden @endif>{{ __('ui.cvb_hourly_low') }}</p>
            <label class="cvb-field" style="margin-top:8px">
              <span>{{ __('ui.cvb_hourly_end') }}</span>
              <select name="on_credit_out">
                <option value="suspend" @selected(old('on_credit_out', 'suspend') === 'suspend')>{{ __('ui.cvb_hourly_end_suspend') }}</option>
                <option value="convert" @selected(old('on_credit_out') === 'convert')>{{ __('ui.cvb_hourly_end_convert') }}</option>
                <option value="terminate" @selected(old('on_credit_out') === 'terminate')>{{ __('ui.cvb_hourly_end_terminate') }}</option>
              </select>
            </label>
            <p class="cvb-note">
              <svg class="icon"><use href="#i-clock"/></svg>
              {{ __('ui.cvb_hourly_note') }}
            </p>
          </div>
        </div>
        @endif
      </div>
    </section>

    {{-- ═══ گام ۵: افزودنی‌ها ═══
         کلیدِ SSH رایگان است و IP اضافه پولی. کارتِ IP فقط وقتی نشان داده
         می‌شود که این مکان واقعاً بتواند تحویلش دهد — گزینه‌ای که سرِ ثبتِ
         سفارش رد شود، بدترین نوعِ رابطِ کاربری است. --}}
    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2><span class="cvb-step">۵</span> {{ __('ui.cvb_s5') }} <small style="font-weight:400;color:var(--dim);font-size:12px">{{ __('ui.cvb_optional') }}</small></h2></div>
      <div class="pnl-sec-b">

        {{-- ورود با کلید SSH --}}
        <label class="cvb-field">
          <span>{{ __('ui.cvb_ssh') }} <b style="color:var(--ok)">{{ __('ui.cvb_free') }}</b></span>
          <select name="ssh_key_id" id="cvb-ssh-pick">
            <option value="">{{ __('ui.cvb_ssh_pw') }}</option>
            @foreach($sshKeys as $k)
              <option value="{{ $k->id }}" @selected((string) old('ssh_key_id') === (string) $k->id)>{{ $k->label() }}</option>
            @endforeach
            <option value="__new" @selected(old('ssh_key_new') !== null)>{{ __('ui.cvb_ssh_add') }}</option>
          </select>
        </label>

        <div id="cvb-ssh-new" style="display:none">
          <label class="cvb-field">
            <span>{{ __('ui.cvb_ssh_name') }}</span>
            <input type="text" name="ssh_key_name" value="{{ old('ssh_key_name') }}"
                   placeholder="{{ __('ui.cvb_ssh_name_ph') }}" maxlength="60" autocomplete="off">
          </label>
          <label class="cvb-field">
            <span>{{ __('ui.cvb_ssh_pub') }}</span>
            <textarea name="ssh_key_new" dir="ltr" rows="3" maxlength="6000"
                      placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... you@laptop"
                      autocapitalize="off" autocomplete="off" spellcheck="false"
                      style="font-family:var(--mono,monospace);font-size:12px">{{ old('ssh_key_new') }}</textarea>
          </label>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-shield"/></svg>
            {!! __('ui.cvb_ssh_note1') !!}
          </p>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-info"/></svg>
            {{ __('ui.cvb_ssh_note2') }}
          </p>
        </div>

        @if($addonOk)
          <label class="cvb-field" style="margin-top:14px">
            <span>{{ __('ui.cvb_ip_pre') }}{{ cloud_price($extraIpPrice) }}{{ __('ui.cvb_ip_suf') }}</span>
            <select name="extra_ipv4" id="cvb-extra-ip">
              @for($i = 0; $i <= $maxExtraIp; $i++)
                <option value="{{ $i }}" @selected((int) old('extra_ipv4', 0) === $i)>
                  {{ $i === 0 ? __('ui.cvb_ip_none') : fa_num($i).__('ui.cvb_ip_opt_mid').cloud_price($i * $extraIpPrice).__('ui.cvb_ip_opt_suf') }}
                </option>
              @endfor
            </select>
          </label>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-globe"/></svg>
            {{ __('ui.cvb_ip_note') }}
          </p>
        @endif
      </div>
    </section>

    {{-- ═══ گام ۶: نام سرور ═══ --}}
    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2><span class="cvb-step">۶</span> {{ __('ui.cvb_s6') }}</h2></div>
      <div class="pnl-sec-b">
        <label class="cvb-field">
          <span>{{ __('ui.cvb_label') }}</span>
          <input type="text" name="label" dir="ltr" value="{{ old('label') }}"
                 placeholder="{{ $autoLabel }}" maxlength="64"
                 autocapitalize="off" autocomplete="off" spellcheck="false">
        </label>
        <p class="cvb-note">
          <svg class="icon"><use href="#i-info"/></svg>
          {{ __('ui.cvb_label_note') }}
        </p>
      </div>
    </section>
  </div>

  {{-- ═══ خلاصه و پرداخت ═══ --}}
  <aside class="cvb-side">
    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2>{{ __('ui.cvb_sum') }}</h2></div>
      <div class="pnl-sec-b">
        <div class="cvb-row"><span>{{ __('ui.cvb_loc') }}</span><b>{{ $location ? $location->flagEmoji().' '.$location->label() : '—' }}</b></div>
        <div class="cvb-row"><span>{{ __('ui.cvb_plan') }}</span><b id="cvb-s-plan">{{ $jsPlans[$curSlug] ?? '—' }}</b></div>
        <div class="cvb-row"><span>{{ __('ui.cvb_os') }}</span><b id="cvb-s-img">{{ $jsImgLbl[$curImage] ?? '—' }}</b></div>
        <div class="cvb-row"><span>{{ __('ui.cvb_cycle') }}</span><b id="cvb-s-cyc">{{ $cycleLabels[$curCycle] ?? '—' }}</b></div>
        <div class="cvb-row" id="cvb-s-ip-row" style="display:none"><span>{{ __('ui.cvb_ip') }}</span><b class="pnl-num" id="cvb-s-ip">—</b></div>
        <div class="cvb-row"><span>{{ __('ui.cvb_amount') }}</span><b class="pnl-num" id="cvb-s-price">{{ cloud_price($initial['cycle']) }}</b></div>
        <div class="cvb-row"><span>{{ __('ui.cvb_monthly') }}</span><b class="pnl-num" id="cvb-s-per">{{ cloud_price($initial['per']) }}</b></div>
        <div class="cvb-row"><span>{{ __('ui.cvb_tax') }}</span><b class="pnl-num">{{ fa_num($taxPct) }}٪</b></div>
        <div class="cvb-row cvb-total"><span>{{ __('ui.cvb_pay_now') }}</span><b class="pnl-num" id="cvb-s-first">{{ cloud_price($initial['first']) }}</b></div>

        <button type="submit" class="pnl-btn primary cvb-go" @disabled(count($planCards) === 0)>
          <svg class="icon"><use href="#i-rocket"/></svg>
          {{ __('ui.cvb_submit') }} — <span id="cvb-s-btn">{{ cloud_price($initial['first']) }}</span>
        </button>
        <p class="cvb-note" style="text-align:center">
          {{ __('ui.cvb_submit_note') }}
        </p>
      </div>
    </section>
  </aside>
</form>

<style>
.cvb-wrap{ display:grid; grid-template-columns:1fr 330px; gap:16px; align-items:start; }
.cvb-main{ display:flex; flex-direction:column; gap:16px; min-width:0; }
.cvb-side .pnl-sec{ position:sticky; top:16px; }
@media(max-width:900px){ .cvb-wrap{ grid-template-columns:1fr; } .cvb-side .pnl-sec{ position:static; } }

.cvb-step{ display:inline-grid; place-items:center; width:21px; height:21px; border-radius:50%;
  background:var(--info-bg); color:var(--info); border:1px solid var(--info-line);
  font-size:11.5px; font-weight:700; margin-inline-end:7px; }
.cvb-hint{ font-size:12px; color:var(--muted); }
.cvb-note{ display:flex; align-items:flex-start; gap:7px; font-size:12px; color:var(--muted); line-height:1.9; margin:12px 0 0; }
.cvb-note .icon{ width:14px; height:14px; flex:0 0 auto; margin-top:3px; color:var(--info); }
.cvb-warn{ font-size:13px; color:var(--warn); line-height:2; margin:0; }
.cvb-empty{ font-size:12.5px; color:var(--warn); margin:8px 0 0; }

/* مکان‌ها، گروه‌بندی‌شده بر اساس کشور */
.cvb-cgroup{ padding:9px 0; border-top:1px solid var(--line); }
.cvb-cgroup:first-child{ border-top:0; padding-top:0; }
.cvb-chead{ display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text); margin-bottom:8px; }
.cvb-flag{ font-size:19px; line-height:1; }
.cvb-cities{ display:flex; flex-wrap:wrap; gap:7px; }
.cvb-city{ font-size:12.5px; color:var(--muted); border:1.5px solid var(--line); border-radius:10px;
  padding:6px 12px; transition:.16s; }
.cvb-city:hover{ border-color:var(--info); color:var(--info); }
.cvb-city.on{ border-color:var(--info); background:var(--info-bg); color:var(--info); font-weight:700; }

/* پلن‌ها */
.cvb-plans{ display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; }
.cvb-plan{ position:relative; display:flex; flex-direction:column; gap:7px; cursor:pointer;
  border:1.5px solid var(--line); border-radius:13px; padding:13px; transition:border-color .16s, background .16s; }
.cvb-plan.on{ border-color:var(--info); background:var(--info-bg); }
/* رادیو «پنهان دیداری» است نه display:none — وگرنه از ترتیب Tab بیرون می‌افتد
   و کاربر صفحه‌کلید نمی‌تواند انتخاب کند. */
.cvb-plan input, .cvb-cyc input, .cvb-img input{ position:absolute; width:1px; height:1px; opacity:0; margin:0; pointer-events:none; }
.cvb-plan:has(input:focus-visible), .cvb-cyc:has(input:focus-visible), .cvb-img:has(input:focus-visible){
  outline:2px solid var(--info); outline-offset:2px; }
.cvb-pn{ font-size:14px; font-weight:700; color:var(--text); }
.cvb-specs{ display:flex; flex-direction:column; gap:4px; }
.cvb-specs span{ display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); }
.cvb-specs .icon{ width:13px; height:13px; color:var(--dim); }
.cvb-cpukind{ font-size:11px; color:var(--dim); }
.cvb-pp{ font-size:13.5px; font-weight:700; color:var(--text); font-variant-numeric:tabular-nums;
  border-top:1px solid var(--line); padding-top:7px; }
.cvb-plan.on .cvb-pp{ color:var(--info); }

/* سیستم‌عامل و نرم‌افزار */
.cvb-tabs{ display:flex; gap:6px; }
.cvb-tab{ background:none; border:1.5px solid var(--line); border-radius:9px; padding:5px 11px;
  font:inherit; font-size:12px; color:var(--muted); cursor:pointer; }
.cvb-tab.on{ border-color:var(--info); background:var(--info-bg); color:var(--info); font-weight:700; }
.cvb-fam{ padding:9px 0; border-top:1px solid var(--line); }
.cvb-fam:first-child{ border-top:0; padding-top:0; }
.cvb-famh{ display:flex; align-items:center; gap:7px; font-size:12.5px; color:var(--muted); margin-bottom:7px; }
.cvb-opts{ display:flex; flex-wrap:wrap; gap:7px; }
.cvb-img{ display:inline-flex; align-items:center; gap:8px; cursor:pointer; border:1.5px solid var(--line); border-radius:10px; padding:6px 11px; transition:.16s; }
.cvb-img b{ font-size:12.5px; font-weight:400; color:var(--muted); }
/* لوگوی سیستم‌عامل/نرم‌افزار — همه **دقیقاً هم‌اندازه** (خواستهٔ کارفرما). SVGِ
   خودمیزبان است، پس خط‌تیز و بی‌درخواستِ بیرونی؛ object-fit تا اگر نسبتِ یکی
   فرق داشت، کادر نشکند. */
.cvb-logo{ width:20px; height:20px; flex:none; border-radius:5px; object-fit:contain; }
.cvb-logo.sm{ width:18px; height:18px; }
.cvb-img:hover{ border-color:var(--info); }
.cvb-img.on{ border-color:var(--info); background:var(--info-bg); }
.cvb-img.on b{ color:var(--info); font-weight:700; }

/* دوره‌ها */
.cvb-cycles{ display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; }
.cvb-cyc{ position:relative; display:flex; flex-direction:column; gap:2px; cursor:pointer;
  border:1.5px solid var(--line); border-radius:13px; padding:12px 13px; transition:border-color .16s, background .16s; }
.cvb-cyc.on{ border-color:var(--info); background:var(--info-bg); }
.cvb-cyc-t{ font-size:13px; font-weight:700; color:var(--text); }
.cvb-cyc-p{ font-size:15px; font-weight:700; color:var(--text); font-variant-numeric:tabular-nums; }
.cvb-cyc-m{ font-size:11px; color:var(--muted); font-variant-numeric:tabular-nums; }
.cvb-cyc-s{ position:absolute; top:-9px; inset-inline-end:10px; font-size:10.5px; font-weight:700;
  color:#04121a; background:linear-gradient(135deg,#34D399,#22D3EE); padding:2px 8px; border-radius:20px; }
.cvb-cyc.on .cvb-cyc-p{ color:var(--info); }

/* نام سرور */
.cvb-field{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted); }
.cvb-field input{ background:var(--surface); border:1px solid var(--line); border-radius:11px;
  padding:11px 13px; font:inherit; font-size:14px; color:var(--text); text-align:left; }
.cvb-field input:focus-visible{ outline:2px solid var(--info); outline-offset:-2px; }

/* خلاصه */
.cvb-row{ display:flex; justify-content:space-between; align-items:center; gap:10px;
  padding:9px 0; font-size:12.5px; color:var(--muted); border-top:1px solid var(--line); }
.cvb-row:first-child{ border-top:0; padding-top:0; }
.cvb-row b{ color:var(--text); font-size:13px; text-align:left; }
.cvb-total{ border-top:2px solid var(--line-2); margin-top:4px; }
.cvb-total span{ color:var(--text); font-weight:600; }
.cvb-total b{ font-size:16px; color:var(--info); }
.cvb-go{ justify-content:center; width:100%; margin-top:12px; }

/* پرداختِ ساعتی */
.cvb-hourly{ margin-top:14px; border:1px solid var(--line); border-radius:12px; padding:10px 12px; background:var(--bg2); }
.cvb-hourly.on{ border-color:var(--info); background:var(--info-bg); }
.cvb-hourly-head{ display:flex; align-items:center; gap:10px; cursor:pointer; }
.cvb-hourly-head input{ width:16px; height:16px; margin:0; flex:none; accent-color:var(--info); }
.cvb-hourly-t{ display:flex; flex-direction:column; gap:1px; flex:1; min-width:0; }
.cvb-hourly-t b{ font-size:13.5px; color:var(--text); }
.cvb-hourly-t small{ font-size:11.5px; color:var(--muted); }
.cvb-hourly-p{ font-size:13px; font-weight:700; color:var(--info); white-space:nowrap; font-variant-numeric:tabular-nums; }
.cvb-hourly-body{ margin-top:8px; padding-top:8px; border-top:1px solid var(--line); }
</style>

@php
  $jsData = [
    'prices' => $jsPrices,
    'images' => $jsImages,
    'cycles' => $jsCycles,
    'plans'  => $jsPlans,
    'imgLbl' => $jsImgLbl,
    // ارز: فارسی تومان، بقیه یورو با نرخِ زندهٔ همان کلاسی که قیمت‌ها را ساخته.
    'fa'   => app()->getLocale() === 'fa',
    'rate' => cloud_eur_rate(),
    'perB' => __('ui.cvb_per_before'),
    'perA' => __('ui.cvb_per_after'),
    // فروشِ ساعتی
    'hourly' => $hourlyMap ?? [],
    'credit' => $creditIrt ?? 0,
    'hPer'   => __('ui.cvb_hourly_per'),
    'hLbl'   => __('ui.cvb_hourly_t'),
  ];
@endphp
<script>
(function(){
  var D = @json($jsData);

  var faN = function(x){ return String(x).replace(/[0-9]/g, function(g){ return '۰۱۲۳۴۵۶۷۸۹'[g]; }); };
  var comma = function(n){ return Math.round(n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
  // money() خودش واحد را می‌چسبانَد؛ هیچ‌جای دیگر « تومان» دستی اضافه نشود.
  var money = function(n){
    if (D.fa) { return faN(comma(n)) + ' تومان'; }
    if (D.rate > 0) { return '€' + ((n || 0) / D.rate).toFixed(2); }
    return comma(n);
  };
  var set = function(id, txt){ var el = document.getElementById(id); if (el) el.textContent = txt; };
  var val = function(name){ var el = document.querySelector('input[name="' + name + '"]:checked'); return el ? el.value : ''; };

  var mark = function(sel, node){
    document.querySelectorAll(sel).forEach(function(o){ o.classList.remove('on'); });
    if (node) node.classList.add('on');
  };

  // ── گزینه‌های سیستم‌عامل/نرم‌افزار را با پلن انتخابی هم‌تراز کن ──
  // گزینه‌ای که روی این پلن تحویل نمی‌شود پنهان می‌شود؛ اگر همان انتخاب شده
  // بود، اولین گزینهٔ ممکن جایش را می‌گیرد تا فرم هرگز با انتخاب نشدنی ارسال نشود.
  var syncImages = function(){
    var slug = val('plan');
    var allow = D.images[slug] || { os: [], app: [] };
    var chosen = val('image');
    var first = null, stillOk = false;

    ['os', 'app'].forEach(function(kind){
      var ok = allow[kind] || [];
      var pane = document.querySelector('[data-pane="' + kind + '"]');
      if (!pane) return;
      var shown = 0;

      pane.querySelectorAll('.cvb-img').forEach(function(lab){
        var can = ok.indexOf(lab.getAttribute('data-key')) !== -1;
        lab.hidden = !can;
        if (can) {
          shown++;
          if (!first) first = lab;
          if (lab.getAttribute('data-key') === chosen) stillOk = true;
        }
      });

      pane.querySelectorAll('.cvb-fam').forEach(function(fam){
        fam.hidden = fam.querySelectorAll('.cvb-img:not([hidden])').length === 0;
      });

      var empty = pane.querySelector('[data-empty]');
      if (empty) empty.hidden = shown > 0;
    });

    if (!stillOk && first) {
      var r = first.querySelector('input');
      if (r) { r.checked = true; }
      mark('.cvb-img', first);
    }
  };

  var render = function(){
    var slug = val('plan'), cyc = val('cycle');
    var bucket = D.prices[slug] || {};

    // قیمت روی کارت هر پلن = همان دورهٔ انتخابی
    document.querySelectorAll('.cvb-plan').forEach(function(card){
      var row = (D.prices[card.getAttribute('data-slug')] || {})[cyc];
      var el = card.querySelector('[data-pp]');
      if (row && el) el.textContent = money(row.cycle);
    });

    // قیمت روی کارت هر دوره = همان پلن انتخابی
    document.querySelectorAll('.cvb-cyc').forEach(function(card){
      var row = bucket[card.getAttribute('data-cyc')];
      if (!row) return;
      var p = card.querySelector('[data-p]'), m = card.querySelector('[data-m]');
      if (p) p.textContent = money(row.cycle);
      if (m) m.textContent = D.perB + money(row.per) + D.perA;
    });

    set('cvb-s-plan', D.plans[slug] || '—');
    set('cvb-s-img', D.imgLbl[val('image')] || '—');

    // ── حالتِ ساعتی: خلاصه نرخِ ساعتی را نشان می‌دهد، نه مبلغِ دوره ──
    var hBox = document.getElementById('cvb-hourly');
    var h = (D.hourly || {})[slug] || { rate: 0, min: 0 };

    if (hBox) {
      set('cvb-h-rate', money(h.rate));
      set('cvb-h-min', money(h.min));
      var low = document.getElementById('cvb-h-low');
      if (low) low.hidden = (D.credit >= h.min);
    }

    if (hBox && hBox.checked) {
      set('cvb-s-cyc', D.hLbl);
      set('cvb-s-price', money(h.rate) + D.hPer);
      set('cvb-s-per', money(h.rate * 720));
      set('cvb-s-first', money(h.rate));       // فقط ساعتِ اول پرداخت می‌شود
      set('cvb-s-btn', money(h.rate));

      return;
    }

    set('cvb-s-cyc', D.cycles[cyc] || '—');

    var row = bucket[cyc];
    if (!row) return;
    set('cvb-s-price', money(row.cycle));
    set('cvb-s-per', money(row.per));
    set('cvb-s-first', money(row.first));
    set('cvb-s-btn', money(row.first));
  };

  document.querySelectorAll('.cvb-plan input').forEach(function(r){
    r.addEventListener('change', function(){
      mark('.cvb-plan', r.closest('.cvb-plan'));
      syncImages();
      render();
    });
  });

  document.querySelectorAll('.cvb-cyc input').forEach(function(r){
    r.addEventListener('change', function(){ mark('.cvb-cyc', r.closest('.cvb-cyc')); render(); });
  });

  // تیکِ «پرداختِ ساعتی» — کارت‌های دوره کم‌رنگ می‌شوند (ولی حذف نه، تا برگشت آسان باشد)
  var hChk = document.getElementById('cvb-hourly');
  if (hChk) {
    var hSync = function(){
      var box = document.getElementById('cvb-hourly-box');
      var body = document.getElementById('cvb-hourly-body');
      var cycles = document.querySelector('.cvb-cycles');
      if (box) box.classList.toggle('on', hChk.checked);
      if (body) body.hidden = !hChk.checked;
      if (cycles) { cycles.style.opacity = hChk.checked ? '.45' : ''; }
      render();
    };
    hChk.addEventListener('change', hSync);
    hSync();
  }

  document.querySelectorAll('.cvb-img input').forEach(function(r){
    r.addEventListener('change', function(){ mark('.cvb-img', r.closest('.cvb-img')); render(); });
  });

  document.querySelectorAll('.cvb-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      var kind = btn.getAttribute('data-tab');
      document.querySelectorAll('.cvb-tab').forEach(function(b){ b.classList.toggle('on', b === btn); });
      document.querySelectorAll('[data-pane]').forEach(function(p){
        p.hidden = p.getAttribute('data-pane') !== kind;
      });
    });
  });

  syncImages();
  render();
})();
</script>
@endif

{{-- انتخابگرِ کلیدِ SSH: «افزودن کلید تازه» کادرِ چسباندن را باز می‌کند.
     ⚠️ وقتی کادر بسته است، فیلدهایش `disabled` می‌شوند نه فقط پنهان — وگرنه
     متنِ نیمه‌تمامِ یک تلاشِ قبلی همراهِ فرم می‌رفت و اعتبارسنجی رد می‌کرد. --}}
<script>
(function(){
  'use strict';

  var pick = document.getElementById('cvb-ssh-pick');
  var box  = document.getElementById('cvb-ssh-new');

  if (!pick || !box) { return; }

  function sync(){
    var isNew = pick.value === '__new';
    box.style.display = isNew ? '' : 'none';

    box.querySelectorAll('input, textarea').forEach(function(el){
      el.disabled = !isNew;
    });

    // مقدارِ '__new' نباید به سرور برود؛ سرور یا شناسهٔ عددی می‌خواهد یا خالی
    pick.querySelectorAll('option').forEach(function(o){
      if (o.value === '__new') { o.disabled = false; }
    });
  }

  pick.addEventListener('change', sync);
  sync();

  // سرِ ارسال، مقدارِ نشانه‌ایِ '__new' را خالی کن تا اعتبارسنجیِ عددیِ
  // ssh_key_id نشکند.
  var form = document.getElementById('cvb-form');

  if (form) {
    form.addEventListener('submit', function(){
      if (pick.value === '__new') { pick.value = ''; }
    });
  }
})();
</script>

@endsection
