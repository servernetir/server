{{--
  پیش‌نمایشِ قیمتِ فروشِ یک مدل — خروجیِ مستقیمِ `AiPricing`، همان کلاسی که شارژِ
  هر تماس را می‌سازد. پس عددِ این جدول همان عددی است که از کیفِ پولِ مشتری کم
  می‌شود؛ هیچ محاسبهٔ جداگانه‌ای در این قالب نیست، فقط قالب‌بندی.
--}}
@php
  $reasonLabels = [
    'provider_missing' => 'ارائه‌دهندهٔ مدل پیدا نشد',
    'fee_unset' => 'سربارِ ارزِ ارائه‌دهنده ثبت نشده (خالی = فروختنی نیست)',
    'fee_invalid' => 'سربارِ ارزِ ارائه‌دهنده بیرون از بازهٔ ۰ تا ۲۵٪ است',
    'margin_unset' => 'حاشیهٔ سودِ AI در تنظیماتِ قیمت‌گذاری خالی است',
    'margin_invalid' => 'حاشیهٔ اختصاصیِ این مدل نامعتبر است',
    'currency_unsupported' => 'ارزِ صورت‌حسابِ ارائه‌دهنده USD یا EUR نیست',
    'no_input_price' => 'قیمتِ فعالِ «توکن ورودی» در سطحِ همین مدل ثبت نشده',
    'no_output_price' => 'قیمتِ فعالِ «توکن خروجی» در سطحِ همین مدل ثبت نشده',
    'price_basis' => 'یکای یکی از قیمت‌ها «به ازای ۱M توکن» نیست',
    'currency_mismatch' => 'ارزِ یکی از قیمت‌ها با ارزِ صورت‌حسابِ ارائه‌دهنده یکی نیست',
    'rate_out_of_range' => 'یکی از قیمت‌ها بیرون از سقفِ ۱۰۰۰ واحدِ ارز به ازای ۱M است',
    'cached_above_input' => 'قیمتِ ورودیِ کش‌شده از قیمتِ ورودی بیشتر است',
    'fx_usd' => 'نرخِ دلارِ قابلِ اتکا نداریم (نه نرخِ دستیِ معتبر، نه نرخِ بازارِ کمتر از ۲۴ ساعت)',
    'fx_eur' => 'نرخِ یوروی قابلِ اتکا نداریم (نه نرخِ دستیِ معتبر، نه نرخِ بازارِ کمتر از ۲۴ ساعت)',
  ];
  $gateLabels = [
    'model_inactive' => 'مدل فعال نیست',
    'model_not_chat' => 'مدل از دستهٔ چت نیست (/v1/chat/completions فقط چت می‌فروشد)',
    'provider_missing' => 'ارائه‌دهنده ندارد',
    'provider_disabled' => 'ارائه‌دهنده فعالِ فنی نیست',
    'provider_not_live' => 'تماسِ زندهٔ ارائه‌دهنده خاموش است',
    'provider_not_commercial' => 'فروشِ ارائه‌دهنده خاموش است',
    'resale_not_allowed' => 'اجازهٔ فروشِ دوباره ثبت نشده',
    'agreement_unsigned' => 'توافق با ارائه‌دهنده امضاشده نیست',
    'sales_closed' => 'درِ فروشِ عمومی بسته است (فقط مشتریانِ آزمایشی)',
  ];
  $fxSource = ['scraped' => 'نرخِ بازار', 'override' => 'نرخِ دستی', 'ratchet' => 'ضامنِ افت (۳٪ در روز)', 'scraped+stale' => 'نرخِ بازارِ کهنه + ۲٪'];
  $toman = fn (int $n) => fa_num(number_format($n)).' تومان';
  $micro = fn (int $m) => rtrim(rtrim(sprintf('%d.%06d', intdiv($m, 1_000_000), $m % 1_000_000), '0'), '.');
  $eur = fn (?int $t) => $t === null ? null : sprintf('€%d.%04d', intdiv($t, 10_000), $t % 10_000);
  $pct = fn (int $bp) => fa_num(\App\Services\Ai\AiPricing::bpToPercent($bp)).'٪';
@endphp

<div style="margin:6px 18px 4px;padding:14px 16px;border:1px solid var(--line, rgba(148,163,184,.2));border-radius:12px;background:var(--bg2)">
  <h3 style="font-size:14px;margin:0 0 8px">پیش‌نمایشِ قیمتِ فروش — <span dir="ltr">{{ $model->slug }}</span></h3>

  @if($preview['error'])
    <div style="color:#f87171;font-size:13px;line-height:1.9">
      <b>این مدل الان فروختنی نیست</b>
      <span dir="ltr" style="color:var(--dim)">({{ $preview['error'] }})</span>
      <ul style="margin:4px 18px 0">
        @foreach($preview['reasons'] as $r)
          <li>{{ $reasonLabels[$r] ?? $r }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if($preview['gates'])
    <div style="color:#fbbf24;font-size:12.5px;line-height:1.9;margin-top:6px">
      سدهای فروش (پرچم‌ها — از مرحلهٔ بعد در مسیرِ /v1 اجباری می‌شوند):
      <ul style="margin:2px 18px 0">
        @foreach($preview['gates'] as $g)
          <li>{{ $gateLabels[$g] ?? $g }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if($q = $preview['quote'])
    <table class="ad-table" style="margin-top:10px">
      <tbody>
        <tr><th style="width:220px">نرخِ {{ $q->currency }}</th>
          <td>{{ $toman($q->fx->rate) }} · {{ $fxSource[$q->fx->source] ?? $q->fx->source }}
            @if($q->fx->at)<span style="color:var(--dim)" dir="ltr"> · {{ \Illuminate\Support\Carbon::parse($q->fx->at)->diffForHumans() }}</span>@endif
            @if($q->fx->highWater)<span style="color:var(--dim)"> · بالاترین نرخِ ۲۴ ساعت: {{ fa_num(number_format($q->fx->highWater)) }}</span>@endif
          </td></tr>
        <tr><th>سربارِ ارز · حاشیه</th>
          <td>{{ $pct($q->feeBp) }} · {{ $pct($q->marginBp) }} <span style="color:var(--dim)">({{ $q->marginSource === 'model' ? 'اختصاصیِ مدل' : 'سراسری' }})</span></td></tr>
      </tbody>
    </table>

    <table class="ad-table" style="margin-top:10px">
      <thead><tr><th>به ازای ۱M توکن</th><th>بهای ارائه‌دهنده</th><th>قیمتِ فروش (بی‌مالیات)</th><th>≈ یورو (نمایش)</th></tr></thead>
      <tbody>
        @foreach([['ورودی', $q->rIn, $q->pIn], ['ورودیِ کش‌شده'.($q->cachedPriceId ? '' : ' (بی‌قیمتِ جدا = قیمتِ کامل)'), $q->rCached, $q->pCached], ['خروجی', $q->rOut, $q->pOut]] as [$label, $r, $p])
          <tr><td>{{ $label }}</td>
            <td dir="ltr">{{ $micro($r) }} {{ $q->currency }}</td>
            <td><b>{{ $toman($p) }}</b></td>
            <td dir="ltr">{{ $preview['eurRate'] ? $eur(\App\Services\Ai\AiPricing::eurTenThousandths($p, $preview['eurRate'])) : '—' }}</td></tr>
        @endforeach
      </tbody>
    </table>

    @php($s = $preview['sample'])
    @php($c = $s['charge'])
    <table class="ad-table" style="margin-top:10px">
      <thead><tr><th colspan="2">نمونه: {{ fa_num(number_format($s['prompt'])) }} توکن ورودی + {{ fa_num(number_format($s['completion'])) }} توکن خروجی</th></tr></thead>
      <tbody>
        <tr><th style="width:220px">قیمتِ فروش (درآمد)</th><td>{{ $toman($c['sell']) }}</td></tr>
        <tr><th>مالیات بر ارزش افزوده ({{ $pct($preview['vatBp']) }})</th><td>{{ $toman($c['tax']) }}</td></tr>
        <tr><th>کسر از کیفِ پولِ مشتریِ ایرانی</th><td><b>{{ $toman($c['charged']) }}</b></td></tr>
        <tr><th>کسر از مشتریِ خارجی (کشورِ غیرِ IR ثبت‌شده)</th><td>{{ $toman($s['charge_foreign']['charged']) }}</td></tr>
        <tr><th>بهای تمام‌شده (با سربارِ ارز)</th><td>{{ $toman($c['cost_irt']) }} <span style="color:var(--dim)" dir="ltr">({{ $micro($c['cost_micro']) }} {{ $q->currency }})</span></td></tr>
        <tr><th>سودِ ناخالص</th>
          <td style="color:{{ $c['sell'] >= $c['cost_irt'] ? '#34d399' : '#f87171' }}">
            {{ $toman($c['sell'] - $c['cost_irt']) }}
            @if($c['sell'] < $c['cost_irt']) — 🔴 زیرِ بها؛ این نباید هرگز رخ دهد @endif
          </td></tr>
        <tr><th>رزروِ همین درخواست بی max_tokens</th>
          <td>{{ $toman($s['hold']['total']) }} <span style="color:var(--dim)">(خروجی تا {{ fa_num(number_format($s['hold_output'])) }} توکن؛ مازاد پس از تسویه آزاد می‌شود)</span></td></tr>
      </tbody>
    </table>
  @endif
</div>
