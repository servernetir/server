@extends('admin.layout')

@section('title', 'بررسی سایت و ارسال گزارش')
@section('nav_seo', 'on')

@section('content')

<div class="ad-toolbar">
  <h1 style="font-size:19px">بررسی سایت و ارسال گزارش</h1>
</div>
<p class="ad-note">گزارشِ سئو/سلامت را برای مشتری بفرست، یا یک فهرست از سایت‌ها را بررسی کن و به صاحبانشان اطلاع بده.</p>

@unless($ready)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">
    جدول‌های این بخش روی این سرور هنوز ساخته نشده‌اند. پس از اجرای مهاجرت، این صفحه فعال می‌شود.
  </p></div>
@else

{{-- ═══════════ ۱) ارسال به یک نفر ═══════════ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>ارسال گزارش به یک نفر</h2></div>
  <p class="ad-note">سایت بررسی می‌شود و لینکِ گزارشِ زنده به این نشانی ایمیل می‌شود. گیرنده روی سایت ما وضعیتِ سایتِ خودش را می‌بیند.</p>

  <div class="sx-form">
    <div class="ad-field"><label>آدرس سایت</label>
      <input class="ad-input" type="text" id="sx-url" dir="ltr" placeholder="example.com" autocomplete="off"></div>
    <div class="ad-field"><label>ایمیل گیرنده</label>
      <input class="ad-input" type="email" id="sx-email" dir="ltr" placeholder="owner@example.com" autocomplete="off"></div>
    <div class="ad-field"><label>یادداشت (اختیاری — بالای دکمهٔ گزارش می‌آید)</label>
      <textarea class="ad-input" id="sx-note" rows="2" placeholder="سلام، طبق صحبتمان سایت را بررسی کردیم…"></textarea></div>
    <div>
      <button class="btn btn-primary" type="button" id="sx-send-one">بررسی و ارسال</button>
      <span class="sx-status" id="sx-one-status"></span>
    </div>
  </div>
</div>

{{-- ═══════════ ۲) کمپین ═══════════ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>کمپین بررسی سایت‌ها</h2></div>

  <p class="ad-note">
    هر خط یک ردیف: <code dir="ltr">دامنه، ایمیل</code> — مثلاً <code dir="ltr">example.com, info@example.com</code>.
    سقف هر بار {{ \App\Http\Controllers\Admin\SeoOutreachController::MAX_LIST }} ردیف.
    خطی که ایمیل نداشته باشد رد می‌شود؛ ایمیل حدس زده نمی‌شود.
  </p>

  {{-- 🔴 این هشدار عمداً روی صفحه است و در کامنتِ کد پنهان نشده: کسی که دکمه را
       می‌زند باید بداند دارد به آدمی که درخواستش نکرده ایمیل می‌فرستد. --}}
  <div class="sx-warn">
    <b>پیش از ارسال بخوانید:</b> این پیام به کسی می‌رود که آن را نخواسته است.
    هر ایمیل، هویتِ ما و دلیلِ دریافت را می‌گوید و لینکِ «دیگر نفرست» دارد؛ هرکس
    آن را بزند، برای همیشه از همهٔ کمپین‌ها کنار می‌رود. برای گیرندهٔ اروپایی این
    حداقلِ قانونی است — کمتر از این، اسپم حساب می‌شود.
  </div>

  <div class="sx-form">
    <div class="ad-field"><label>فهرست</label>
      <textarea class="ad-input" id="sx-list" rows="6" dir="ltr" placeholder="example.com, info@example.com&#10;another.ir, hello@another.ir"></textarea></div>
    <div>
      <button class="btn btn-ghost" type="button" id="sx-import">افزودن به فهرست</button>
      <span class="sx-status" id="sx-import-status"></span>
    </div>
  </div>

  <div class="sx-actions">
    <button class="btn btn-ghost" type="button" id="sx-scan">
      بررسی سایت‌های بررسی‌نشده (<span id="sx-toscan">{{ fa_num($stats['toScan']) }}</span>)
    </button>
    <button class="btn btn-primary" type="button" id="sx-send">ارسال به انتخاب‌شده‌ها</button>
    <span class="sx-status" id="sx-bulk-status"></span>
  </div>

  <div class="sx-stats">
    <span>آمادهٔ ارسال: <b id="sx-ready">{{ fa_num($stats['pending']) }}</b></span>
    <span>ارسال‌شده: <b>{{ fa_num($stats['sent']) }}</b></span>
    <span>ناموفق: <b>{{ fa_num($stats['failed']) }}</b></span>
    <span>لغو اشتراک: <b>{{ fa_num($stats['unsub']) }}</b></span>
  </div>
</div>

{{-- ═══════════ جدول ═══════════ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>فهرست</h2></div>

  @if($contacts->isEmpty())
    <p style="padding:20px;color:var(--muted)">هنوز ردیفی اضافه نشده.</p>
  @else
    <table class="ad-table">
      <thead>
        <tr>
          <th style="width:34px"><input type="checkbox" id="sx-all"></th>
          <th>دامنه</th><th>ایمیل</th><th>نمره</th><th>ایراد</th><th>وضعیت</th><th>گزارش</th><th>زمان</th>
        </tr>
      </thead>
      <tbody>
        @foreach($contacts as $c)
          @php
            $st = [
              'pending' => ['در انتظار', 'var(--muted)'],
              'sent'    => ['ارسال شد', '#34d399'],
              'failed'  => ['ناموفق', '#ff6b6b'],
              'skipped' => ['رد شد', 'var(--dim)'],
            ][$c->status] ?? [$c->status, 'var(--muted)'];
          @endphp
          <tr data-id="{{ $c->id }}">
            <td>
              <input type="checkbox" class="sx-pick" value="{{ $c->id }}"
                     @disabled($c->status !== 'pending' || ! $c->audit_report_id || $c->unsubscribed_at)>
            </td>
            <td dir="ltr">{{ $c->host }}</td>
            <td dir="ltr" style="color:var(--muted)">{{ $c->email }}</td>
            <td>@if($c->report)<b>{{ fa_num($c->report->score) }}</b>@else <span style="color:var(--dim)">—</span> @endif</td>
            <td>@if($c->report){{ fa_num($c->report->issueCount()) }}@else <span style="color:var(--dim)">—</span> @endif</td>
            <td>
              <span class="ad-badge" style="background:{{ $st[1] }}22;color:{{ $st[1] }}">{{ $st[0] }}</span>
              @if($c->unsubscribed_at)<span class="ad-badge" style="background:rgba(255,107,107,.12);color:#ff6b6b">لغو اشتراک</span>@endif
              @if($c->error)<div class="sx-err" dir="ltr">{{ \Illuminate\Support\Str::limit($c->error, 90) }}</div>@endif
            </td>
            <td>@if($c->report)<a class="sx-link" href="{{ $c->report->url() }}" target="_blank" rel="noopener">دیدن</a>@else <span style="color:var(--dim)">—</span> @endif</td>
            <td dir="ltr" style="color:var(--muted)">{{ $c->sent_at ? sdate($c->sent_at) : sdate($c->created_at) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

<script>
window.SX = {
  urls: {
    one:  '/admin/seo/send-one',
    list: '/admin/seo/list',
    scan: '/admin/seo/scan-next',
    send: '/admin/seo/send-next',
  },
  csrf: '{{ csrf_token() }}',
};
</script>
<script src="{{ asset_ver('assets/js/admin-seo.js') }}" defer></script>
@endunless
@endsection
