@extends('admin.layout')
@section('title', 'رشد و دیده‌شدن')
@section('nav_marketing', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')

<div class="mk">

  <div class="mk-head">
    <div>
      <h2>رشد و دیده‌شدن</h2>
      <p>این‌جا دربارهٔ سایتِ <b>خودمان</b> است، نه سرنخ‌ها: چه چیزی ما را در گوگل و در جوابِ هوش‌های مصنوعی بالا می‌آورد.</p>
    </div>
    <a class="mk-btn" href="/admin/marketing">بازگشت به قیف</a>
  </div>

  <div class="mk-note info">
    <svg class="icon"><use href="#i-info"/></svg>
    <div>
      <b>چرا کامنت‌گذاریِ خودکار برای بک‌لینک اینجا نیست:</b>
      گوگل در سیاستِ رسمی‌اش «کامنت با لینکِ بهینه‌شده» و «ساختِ لینک با برنامهٔ خودکار» را صریحاً
      لینک‌اسپم نامیده، و نتیجه‌اش «رتبهٔ پایین‌تر یا حذف از نتایج» است — یعنی همان دارایی‌ای که
      داریم می‌سازیم را می‌سوزاند. راهِ زیر کندتر است ولی پاک است و جریمه نمی‌شود.
    </div>
  </div>

  {{-- ══ سئوی سایتِ خودمان ══ --}}
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h">
      <h2>سایتِ خودمان</h2>
      <small style="color:var(--dim)" dir="ltr">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</small>
    </div>

    @if($audit)
      <div class="mk-tiles" style="padding:16px 18px 0;margin:0">
        <div class="mk-tile {{ ($audit['overall'] ?? 0) < 75 ? 'is-warn' : 'is-good' }}">
          <div class="mk-tile-k"><svg class="icon"><use href="#i-gauge"/></svg>امتیازِ کل</div>
          <div class="mk-tile-v">{{ $audit['overall'] ?? '—' }}<small>از ۱۰۰</small></div>
          <div class="mk-tile-s">ممیزیِ خودمان روی خودمان</div>
        </div>
        @foreach(($audit['scores'] ?? []) as $cat => $score)
          <div class="mk-tile">
            <div class="mk-tile-k">{{ ['seo'=>'سئو','performance'=>'سرعت','security'=>'امنیت','mobile'=>'موبایل','best'=>'بهترین‌روش'][$cat] ?? $cat }}</div>
            <div class="mk-tile-v">{{ $score }}</div>
          </div>
        @endforeach
      </div>

      @php
        $issues = collect($audit['checks'] ?? [])->flatMap(fn ($checks, $cat) =>
            collect($checks)->filter(fn ($c) => ($c['status'] ?? 'pass') !== 'pass')->map(fn ($c) => $c + ['cat' => $cat]))
          ->sortBy(fn ($i) => ($i['status'] ?? '') === 'fail' ? 0 : 1)->values();
      @endphp

      @if($issues->isNotEmpty())
        <div class="mk-rows" style="padding:16px 18px 18px">
          @foreach($issues->take(25) as $issue)
            <div class="mk-row {{ ($issue['status'] ?? '') === 'fail' ? 'is-wait' : '' }}">
              <div>
                <b>{{ $issue['label'] ?? $issue['title'] ?? '—' }}</b>
                <small>{{ ['seo'=>'سئو','performance'=>'سرعت','security'=>'امنیت','mobile'=>'موبایل','best'=>'بهترین‌روش'][$issue['cat']] ?? $issue['cat'] }}</small>
              </div>
              <div><span class="mk-tag {{ ($issue['status'] ?? '') === 'fail' ? 'r' : 'a' }}">{{ ($issue['status'] ?? '') === 'fail' ? 'مهم' : 'بهتر است' }}</span></div>
            </div>
          @endforeach
        </div>
      @else
        <div style="padding:18px"><div class="mk-empty" style="padding:30px 20px">
          <svg class="icon"><use href="#i-check"/></svg>
          <b>هیچ ایرادی پیدا نشد</b>
        </div></div>
      @endif
    @else
      <div style="padding:18px">
        <div class="mk-empty" style="padding:34px 20px">
          <svg class="icon"><use href="#i-globe"/></svg>
          <b>ممیزی انجام نشد</b>
          <p>سایت از روی این سرور باز نشد. اگر پشتِ فایروال یا در محیطِ محلی هستی طبیعی است؛ روی سرورِ اصلی کار می‌کند.</p>
        </div>
      </div>
    @endif
  </div>

  {{-- ══ جاهایی که باید در آن‌ها باشیم ══ --}}
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>ثبت در دایرکتوری و مقایسه‌گرها</h2></div>
    <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.95">
      این‌ها بک‌لینکِ واقعی و ماندگار می‌دهند و برخلافِ کامنت، هیچ‌وقت پاک یا جریمه نمی‌شوند.
      هرکدام یک‌بار ثبت می‌شود و سال‌ها کار می‌کند.
    </p>
    <div class="mk-rows" style="padding:14px 18px 18px">
      @foreach($directories as $d)
        <div class="mk-row">
          <div>
            <b>{{ $d['name'] }}</b>
            <small>{{ $d['note'] }}</small>
          </div>
          <div><a class="mk-btn" href="{{ $d['url'] }}" target="_blank" rel="noopener">باز کن</a></div>
        </div>
      @endforeach
    </div>
  </div>

</div>
@endsection
